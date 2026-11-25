"""Explicit Fon handoffs must fail closed without logging their contents."""
from pathlib import Path
import os
import subprocess
import tempfile
import tomllib
import unittest

ROOT = Path(__file__).resolve().parents[2]
INSTALL = ['bash', str(ROOT/'installer/install.sh')]
FIXTURE = (ROOT/'tests/installer/fixtures/fon-connection.toml').read_text()
SECRET = tomllib.loads(FIXTURE)['ami']['secret']


class AmiConnectionTests(unittest.TestCase):
    def rejected(self, args, diagnostic, env=None):
        result = subprocess.run(INSTALL + args, text=True, capture_output=True, env=env)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(diagnostic, result.stderr)
        self.assertNotIn(SECRET, result.stdout + result.stderr)

    def test_explicit_invalid_import_before_live_install(self):
        errors = [
            ('schema_version = 1', 'schema_version = true', 'schema_version'),
            ('schema_version = 1', 'schema_version = 2', 'schema_version'),
            ('enabled = true', 'enabled = false', 'enabled'),
            ('[ami]', '[other]', '[ami]'),
            ('192.0.2.10', '0.0.0.0', 'ami.host'),
            ('192.0.2.10', 'host:port', 'ami.host'),
            ('port = 5039', 'port = "5039"', 'ami.port'),
            ('port = 5039', 'port = 65536', 'ami.port'),
            ('"tls"', '"https"', 'ami.transport'),
            ('"fon-test"', '"general"', 'ami.username'),
            (f'secret = "{SECRET}"', 'secret = ""', 'ami.secret'),
            (f'secret = "{SECRET}"', 'secret = "test"', 'ami.secret'),
            (f'secret = "{SECRET}"', f'secret = "{SECRET}\\r\\n"', 'ami.secret'),
            ('events = "on"', 'events = "off"', 'ami.events'),
            ('["201", "299"]', '["201", "desk"]', 'click_to_dial.extensions'),
            ('extensions = ["201"]', 'extensions = 201', 'transfer.extensions'),
            ('events = "on"', f'secret = "{SECRET}"', 'TOML'),
        ]
        with tempfile.TemporaryDirectory() as temp:
            file = Path(temp)/'handoff.toml'
            file.touch(mode=0o600)
            # Even a regression cannot pass the live root check on a Linux CI runner.
            fake_id = Path(temp)/'id'
            fake_id.write_text('#!/bin/sh\necho 65534\n')
            fake_id.chmod(0o755)
            env = dict(os.environ, PATH=temp + os.pathsep + os.environ['PATH'])
            for old, new, error in errors:
                with self.subTest(error=error):
                    file.write_text(FIXTURE.replace(old, new))
                    self.rejected(['--ami-connection-file', str(file)], error, env)
            self.rejected(['--dry-run', '--ami-connection-file', str(file)], 'TOML')

    def test_import_path_permissions_and_conflicting_options(self):
        with tempfile.TemporaryDirectory() as temp:
            file = Path(temp)/'private.toml'
            self.rejected(['--dry-run', '--ami-connection-file', str(file)], 'nicht lesbar')
            file.write_text(FIXTURE)
            file.chmod(0o644)
            self.rejected(['--dry-run', '--ami-connection-file', str(file)], 'geschützte Datei')
            self.rejected(['--ami-connection-file', str(file), '--keep-config'], 'nicht kombiniert')
            self.rejected(['--dry-run', '--ami-connection-file'], 'Dateipfad')
            self.rejected(['--ami-connection-file', '--dry-run'], 'Dateipfad')
            file.chmod(0o400)
            link = Path(temp)/'link.toml'
            link.symlink_to(file)
            self.rejected(['--dry-run', '--ami-connection-file', str(link)], 'nicht lesbar')

    def test_ipv6_and_future_fields(self):
        with tempfile.TemporaryDirectory() as temp:
            file = Path(temp)/'private.toml'
            file.write_text(FIXTURE.replace('192.0.2.10', '2001:db8::10') + '\n[future]\nextra = true\n')
            file.chmod(0o400)
            result = subprocess.run(INSTALL + ['--dry-run', '--ami-connection-file', str(file)],
                                    check=True, capture_output=True, text=True)
            self.assertIn('AMI-Übergabe geprüft', result.stdout)
            self.assertNotIn(SECRET, result.stdout + result.stderr)
