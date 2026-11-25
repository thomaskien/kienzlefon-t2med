"""Exercise the public pack/install commands with a Git-free working tree."""
import hashlib
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = ROOT / 'tests/installer/fixtures/fon-connection.toml'


class PackageTests(unittest.TestCase):
    def test_installer_from_release_and_modified_working_tree(self):
        with tempfile.TemporaryDirectory(prefix='t2med pack ') as temp:
            work = Path(temp)
            archive = work / 't2med.tar.gz'
            pack = ['bash', 'scripts/pack.sh', '--output', str(archive)]
            subprocess.run(pack, cwd=ROOT, check=True, capture_output=True)
            with tarfile.open(archive) as tar:
                for member in tar:
                    parts = Path(member.name).parts
                    self.assertEqual(parts[0], 't2med')
                    self.assertNotIn('..', parts)
                    self.assertTrue(member.isfile() or member.isdir())
                    self.assertFalse(any('xattr' in key for key in member.pax_headers))
                    self.assertFalse(any(part.startswith('.') for part in parts))
                tar.extractall(work, filter='data')
            tree = work / 't2med'
            required = ['composer.lock', 'resources/telephony-v1.schema.json',
                        'installer/kienzlefon-t2med.service', 'installer/check-app-counters.php', 'installer/samba.php',
                        'bin/check-telephony-control.php', 'installer/ami_connection.py']
            required += ['src/' + name + '.php' for name in
                         ('CallStatistics', 'ControlError', 'ControlTransport', 'RoutingJournal',
                          'TelephonyContract', 'TelephonyControl')]
            required += ['clients/' + name for name in
                         ('install-linux.sh', 'install-macos.sh', 'install-windows.ps1',
                          'kienzlefon_call.py', 'macos-bundle.sh')]
            for name in required:
                self.assertEqual((tree/name).read_bytes(), (ROOT/name).read_bytes(), name)
            sums = {}
            for line in (ROOT/'libexec/SHA256SUMS').read_text().splitlines():
                digest, filename = line.split()
                sums[filename] = digest
            for arch in ('amd64', 'arm64'):
                name = f'fhir-importer-linux-{arch}'
                binary = tree/'libexec'/name
                self.assertEqual(hashlib.sha256(binary.read_bytes()).hexdigest(), sums[name])
                self.assertTrue(binary.stat().st_mode & 0o111)
            dry_run = ['bash', 'installer/install.sh', '--dry-run']
            subprocess.run(dry_run, cwd=tree, check=True, capture_output=True)
            connection = work / 'private handoff.toml'
            shutil.copyfile(FIXTURE, connection)
            connection.chmod(0o600)
            result = subprocess.run(dry_run + ['--ami-connection-file', str(connection)],
                                    cwd=tree, check=True, capture_output=True, text=True)
            self.assertIn('AMI-Übergabe geprüft', result.stdout)

            # No Git metadata is present. Package the edited program and put
            # canaries both beside and inside the runtime directories.
            modified = tree/'bin/check-telephony-control.php'
            with modified.open('a') as f:
                f.write('\n// synthetic development change\n')
            private = ['config.toml', 'middleware-connection.toml', 'bin/aps-seed.php',
                       'resources/local-patients.json', 'public/report.gdt', 'src/config.local.php',
                       'libexec/patients.sqlite', 'clients/__pycache__/local.pyc', 'output/report.log']
            for name in private:
                path = tree/name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('private test marker')
            subprocess.run(pack, cwd=tree, check=True, capture_output=True)
            with tarfile.open(archive) as tar:
                self.assertEqual(tar.extractfile('t2med/bin/check-telephony-control.php').read(), modified.read_bytes())
                for name in private:
                    self.assertNotIn('t2med/' + name, tar.getnames())
            before = hashlib.sha256(archive.read_bytes()).digest()
            (tree/'composer.lock').unlink()
            rejected = subprocess.run(pack, cwd=tree, capture_output=True, text=True)
            self.assertNotEqual(rejected.returncode, 0)
            self.assertIn('composer.lock', rejected.stderr)
            self.assertEqual(hashlib.sha256(archive.read_bytes()).digest(), before)
