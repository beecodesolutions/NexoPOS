"""Package reviewed source files only; never read working-tree application files."""
import argparse
import hashlib
import json
import re
import subprocess
import zipfile
from pathlib import Path

BASE = 'v6.2.4'
FILES = (
    'app/Services/CashRegistersService.php',
    'lang/es.json',
    'resources/views/pages/dashboard/orders/templates/_product-name.blade.php',
    'resources/views/pages/dashboard/orders/templates/_receipt.blade.php',
)
MARKERS = ('composer.json', 'composer.lock', 'package.json', 'config/nexopos.php')
# Windows catalog verified byte-for-byte on 2026-10-26; JSON equals commit 3d9bb71e.
VERIFIED_SOURCE_HASHES = {
    'lang/es.json': {'26b17b2a5039a7095da54106f815ca317e74677cc925ac0963d7ef8f76218249'},
}


def git(*args):
    return subprocess.check_output(['git', *args])


def digest(data):
    return hashlib.sha256(data).hexdigest()


def blob(revision, path):
    return git('show', f'{revision}:{path}')


def package(revision, version, output):
    if not re.fullmatch(r'cm-v\d+\.\d+\.\d+', version):
        raise ValueError('Expected version cm-vMAJOR.MINOR.PATCH')
    commit = git('rev-parse', '--verify', f'{revision}^{{commit}}').decode().strip()
    subprocess.run(['git', 'merge-base', '--is-ancestor', BASE, commit], check=True)
    # Refuse releases needing dependency changes, migrations or other runtime files.
    changed = git('diff', '--name-only', BASE, commit).decode().splitlines()
    unsupported = [p for p in changed if p not in FILES and not p.startswith(('tests/', '.github/'))]
    if unsupported:
        raise ValueError(f'Unsupported runtime changes: {unsupported}')
    manifest = dict(schema=1, version=version, commit=commit, base=BASE, files=[], markers=[])
    for path in MARKERS:
        manifest['markers'].append(dict(path=path, sha256=digest(blob(BASE, path))))
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    archive = output / 'nexopos-update.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
        for path in FILES:
            data = blob(commit, path)
            revisions = [BASE, commit, *git('log', '--format=%H', f'{BASE}..{commit}', '--', path).decode().splitlines()]
            known = sorted({digest(blob(ref, path)) for ref in revisions} | VERIFIED_SOURCE_HASHES.get(path, set()))
            manifest['files'].append(dict(path=path, sha256=digest(data), accepted_sha256=known))
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
