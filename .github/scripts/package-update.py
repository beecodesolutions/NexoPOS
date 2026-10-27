"""Package reviewed source files only; never read working-tree application files."""
import argparse
import hashlib
import json
import re
import subprocess
import zipfile
from pathlib import Path

BASE = 'v6.2.4'
MARKERS = ('composer.json', 'composer.lock', 'package.json', 'config/nexopos.php')
# Windows catalog verified byte-for-byte on 2026-10-26; JSON equals commit 3d9bb71e.
VERIFIED_SOURCE_HASHES = {
    'lang/es.json': {'26b17b2a5039a7095da54106f815ca317e74677cc925ac0963d7ef8f76218249'},
}


def git(*args):
    return subprocess.check_output(['git', *args])


def digest(data):
    return hashlib.sha256(data).hexdigest()


def source_path(path):
    if not re.fullmatch(r'(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*', path):
        return False
    for part in path.split('/'):
        if part.endswith('.') or re.fullmatch(r'(?i:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?', part):
            return False
    return path.startswith(('app/', 'lang/', 'resources/views/', 'routes/',
                            'public/build/', 'public/css/', 'public/js/', 'public/images/', 'public/fonts/'))


def blob(revision, path):
    entry = git('ls-tree', '-z', revision, '--', path)
    if not entry:
        return None
    if not entry.startswith((b'100644 ', b'100755 ')):
        raise ValueError(f'Not a regular source file: {path}')
    return git('show', f'{revision}:{path}')


def file_hash(data):
    return None if data is None else digest(data)


def package(revision, version, output):
    if not re.fullmatch(r'cm-v\d+\.\d+\.\d+', version):
        raise ValueError('Expected version cm-vMAJOR.MINOR.PATCH')
    commit = git('rev-parse', '--verify', f'{revision}^{{commit}}').decode().strip()
    subprocess.run(['git', 'merge-base', '--is-ancestor', BASE, commit], check=True)
    # Refuse releases needing dependency changes, migrations or other runtime files.
    changed = git('diff', '--name-only', '-z', BASE, commit).decode().split('\0')
    changed = [p for p in changed if p]
    unsupported = [p for p in changed if not source_path(p) and not p.startswith(('tests/', '.github/'))]
    if unsupported:
        raise ValueError(f'Unsupported runtime changes: {unsupported}')
    touched = git('log', '--format=', '--name-only', '--no-renames', '-z', f'{BASE}..{commit}').decode().split('\0')
    # Include reverted/deleted paths so clients can skip intermediate releases.
    files = sorted({p for p in [*changed, *touched] if source_path(p)})
    if not files or len(files) != len({p.casefold() for p in files}):
        raise ValueError('Empty update or Windows path collision')
    manifest = dict(schema=2, version=version, commit=commit, base=BASE, files=[], markers=[])
    for path in MARKERS:
        manifest['markers'].append(dict(path=path, sha256=digest(blob(BASE, path))))
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    archive = output / 'nexopos-update.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
        for path in files:
            data = blob(commit, path)
            revisions = [BASE, commit, *git('log', '--format=%H', f'{BASE}..{commit}', '--', path).decode().splitlines()]
            known = sorted({file_hash(blob(ref, path)) for ref in revisions} | VERIFIED_SOURCE_HASHES.get(path, set()), key=lambda h: h or '')
            manifest['files'].append(dict(path=path, sha256=file_hash(data), accepted_sha256=known))
            if data is not None:
                z.writestr('files/' + path, data)
        z.writestr('manifest.json', json.dumps(manifest, indent=2) + '\n')
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--version', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    package(args.revision, args.version, args.output)
