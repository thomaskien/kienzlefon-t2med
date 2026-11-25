"""Run the installer's Samba preflight and the real PHP planner against temporary files."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FAX_CONFIG = """[global]
    server role = auto
    map to guest = Bad User
[fax-eingang]
    path = /srv/kienzlefax/fax-eingang
"""
STANDALONE = 'Server role: ROLE_STANDALONE'
DOMAIN = 'Server role: ROLE_DOMAIN_MEMBER'


class SambaPreflightTests(unittest.TestCase):
    def run_preflight(self, report, expected_error=None, full_status=0):
        source = (ROOT / 'installer/install.sh').read_text()
        start = source.index('SAMBA_FRESH=0')
        block = source[start:source.index('\nMUTATING=1', start)].replace('/usr/bin/php ', 'php ')
        with tempfile.TemporaryDirectory(prefix='t2med-samba-', dir='/tmp') as temp:
            work = Path(temp)
            main = work / 'smb.conf'
            main.write_text(FAX_CONFIG)
            include = work / 'kienzlefon-t2med.conf'
            testparm = work / 'testparm'
            testparm.write_text('''#!/bin/sh
[ "$1" = -s ] || exit 2
if [ "$#" -eq 2 ] && [ "$2" = "$SMB_MAIN" ]; then
    cat "$SMB_MAIN"
    printf '%s\\n' "Load smb config files from $SMB_MAIN" "Loaded services file OK." "$REPORT" >&2
    exit "$FULL_STATUS"
fi
[ "$#" -eq 3 ] && [ "$3" = "$SMB_MAIN" ] || exit 2
case "$2" in
    '--parameter-name=server role') echo auto ;;
    '--parameter-name=map to guest') echo 'Bad User' ;;
    *) exit 2 ;;
esac
''')
            testparm.chmod(0o755)
            env = dict(os.environ, PATH=temp + os.pathsep + os.environ['PATH'], STAGE=str(ROOT),
                       WORK_DIR=temp, SMB_MAIN=str(main), SMB_INCLUDE=str(include), SKIP_SAMBA='0',
                       GDT_ROOT='/srv/kienzlefon-GDT', REPORT=report, FULL_STATUS=str(full_status))
            shell = 'set -eu\nfail() { echo "$*" >&2; exit 1; }\n' + block
            result = subprocess.run(['sh', '-c', shell], env=env, capture_output=True, text=True)
            self.assertEqual(main.read_text(), FAX_CONFIG)
            self.assertFalse(include.exists())
            planned = work / 'samba-plan'
            if expected_error:
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(expected_error, result.stderr)
                self.assertEqual(list(planned.iterdir()), [])
            else:
                self.assertEqual(result.returncode, 0, result.stderr)
                expected = FAX_CONFIG.replace('[fax-eingang]', f'    include = {include}\n[fax-eingang]')
                self.assertEqual((planned / 'smb.conf').read_text(), expected)
                self.assertIn('[kienzlefon-GDT]', (planned / include.name).read_text())

    def test_auto_uses_standalone_from_stderr(self):
        self.run_preflight(STANDALONE)
        self.run_preflight(f'{STANDALONE}\n{STANDALONE}')

    def test_auto_rejects_domain_missing_and_conflicting_reports(self):
        for report, error in [(DOMAIN, 'ROLE_DOMAIN_MEMBER'), ('', 'keine Rollenmeldung'),
                              ('Warning: expected ' + STANDALONE, 'keine Rollenmeldung'),
                              (f'{STANDALONE}\n{DOMAIN}', 'widersprüchlich'),
                              (f'{DOMAIN}\n{STANDALONE}', 'widersprüchlich')]:
            with self.subTest(report=report):
                self.run_preflight(report, error)

    def test_failed_dump_cannot_confirm_standalone(self):
        self.run_preflight(STANDALONE, 'Bestehende Samba-Konfiguration ist ungültig', full_status=1)
