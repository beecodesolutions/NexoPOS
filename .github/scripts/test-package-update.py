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
    def test_generic_add_replace_delete(self):
        def fake_git(*args):
            if args[0] == 'rev-parse':
                return b'a' * 40
            if args[0] == 'diff':
                return b'lang/es.json\0routes/new.php\0app/Legacy.php\0tests/Feature/Example.php\0'
            if args[0] == 'log':
                if args[1] == '--format=':
                    return b'app/Reverted.php\0'
                return b'b' * 40
            if args[0] == 'ls-tree':
                revision, path = args[2], args[4]
                if (path == 'routes/new.php' and revision != 'a' * 40) or (path == 'app/Legacy.php' and revision == 'a' * 40):
                    return b''
                return b'100644 blob hash\t' + path.encode() + b'\0'
            return (args[1] + '\n').encode()
        with tempfile.TemporaryDirectory() as tmp, patch.object(p, 'git', side_effect=fake_git), patch.object(p.subprocess, 'run'):
            manifest = p.package('HEAD', 'cm-v1.1.0', tmp)
            self.assertEqual(manifest['schema'], 2)
            files = {f['path']: f for f in manifest['files']}
            self.assertEqual(len(files), 4)
            self.assertIn('app/Reverted.php', files)
            self.assertIsNone(files['app/Legacy.php']['sha256'])
            self.assertIn(None, files['routes/new.php']['accepted_sha256'])
            verified = next(iter(p.VERIFIED_SOURCE_HASHES['lang/es.json']))
            for path, file in files.items():
                self.assertEqual(verified in file['accepted_sha256'], path == 'lang/es.json')
            with zipfile.ZipFile(Path(tmp) / 'nexopos-update.zip') as z:
                self.assertEqual(set(z.namelist()), {'manifest.json', 'files/lang/es.json', 'files/routes/new.php', 'files/app/Reverted.php'})
                self.assertEqual(z.read('files/lang/es.json'), (('a' * 40) + ':lang/es.json\n').encode())

    def test_reject_unsupported_changes_and_bad_version(self):
        for path in ('bootstrap/middleware.php', 'composer.lock', 'database/migrations/change.php', 'public/uploads/private.jpg', '.env'):
            with patch.object(p, 'git', side_effect=[b'a' * 40, path.encode() + b'\0']), patch.object(p.subprocess, 'run'):
                with self.assertRaisesRegex(ValueError, 'Unsupported'):
                    p.package('HEAD', 'cm-v1.1.0', '/unused')
        with self.assertRaises(ValueError):
            p.package('HEAD', '../bad', '/unused')

    def test_path_boundaries_and_symlinks(self):
        for path in ('app/../.env', 'app/CON.php', 'app/file.php:stream', 'app/folder./file.php', 'app/.hidden', 'public/uploads/a.jpg', 'storage/file', 'App/Service.php', 'app/x\\y.php'):
            self.assertFalse(p.source_path(path), path)
        for path in ('app/Services/New.php', 'lang/es.json', 'resources/views/new.blade.php', 'routes/web.php', 'public/build/assets/app.js'):
            self.assertTrue(p.source_path(path), path)
        with patch.object(p, 'git', return_value=b'120000 blob hash\tapp/link.php\0'):
            with self.assertRaisesRegex(ValueError, 'Not a regular'):
                p.blob('HEAD', 'app/link.php')


if __name__ == '__main__':
    unittest.main()
