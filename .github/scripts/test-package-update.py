import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('packager', Path(__file__).with_name('package-update.py'))
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class PackageTest(unittest.TestCase):
    def test_committed_bytes_and_allowlist(self):
        def fake_git(*args):
            if args[0] == 'rev-parse':
                return b'a' * 40
            if args[0] == 'diff':
                return b'lang/es.json\ntests/Feature/Example.php\n'
            if args[0] == 'log':
                return b'b' * 40
            return (args[1] + '\n').encode()
        with tempfile.TemporaryDirectory() as tmp, patch.object(p, 'git', side_effect=fake_git), patch.object(p.subprocess, 'run'):
            manifest = p.package('HEAD', 'cm-v1.0.0', tmp)
            self.assertEqual(len(manifest['files']), 4)
            with zipfile.ZipFile(Path(tmp) / 'nexopos-update.zip') as z:
                self.assertEqual(set(z.namelist()), {'manifest.json', *('files/' + path for path in p.FILES)})
                self.assertEqual(z.read('files/lang/es.json'), (('a' * 40) + ':lang/es.json\n').encode())
            self.assertEqual(len(manifest['files'][0]['accepted_sha256']), 3)
            verified_hash = '26b17b2a5039a7095da54106f815ca317e74677cc925ac0963d7ef8f76218249'
            for file in manifest['files']:
                self.assertEqual(verified_hash in file['accepted_sha256'], file['path'] == 'lang/es.json')

    def test_reject_unsupported_changes_and_bad_version(self):
        with patch.object(p, 'git', side_effect=[b'a' * 40, b'bootstrap/middleware.php\n']), patch.object(p.subprocess, 'run'):
            with self.assertRaisesRegex(ValueError, 'Unsupported'):
                p.package('HEAD', 'cm-v1.0.0', '/unused')
        with self.assertRaises(ValueError):
            p.package('HEAD', '../bad', '/unused')


if __name__ == '__main__':
    unittest.main()
