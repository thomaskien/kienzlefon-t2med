"""Exercise installer startup failures without touching the host's services."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class StartupTests(unittest.TestCase):
    def run_startup(self, *, start=0, active=0, journal=0, was_active=0):
        source = (ROOT / 'installer/install.sh').read_text()
        function_start = source.index('start_middleware() {')
        function = source[function_start:source.index('\n}\n', function_start) + 3]
        script = f'''set -eu
SERVICE_WAS_ACTIVE={was_active}
fail() {{ printf 'FAILED: %s\\n' "$*" >&2; exit 1; }}
systemctl() {{
    printf 'systemctl %s\\n' "$*"
    case "$1" in
        start|restart) return {start} ;;
        is-active) return {active} ;;
        show) printf 'ExecMainStatus=1\\n'; return 0 ;;
    esac
}}
sleep() {{ :; }}
journalctl() {{ printf 'JOURNAL: startup error\\n'; return {journal}; }}
trap 'printf "ROLLBACK\\n"' EXIT
{function}
start_middleware
trap - EXIT
printf 'SUCCESS\\n'
'''
        return subprocess.run(['sh', '-c', script], stdout=subprocess.PIPE,
                              stderr=subprocess.STDOUT, text=True)

    def assert_diagnosed_failure(self, result):
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn('ExecMainStatus=1', result.stdout)
        self.assertIn('JOURNAL: startup error', result.stdout)
        self.assertLess(result.stdout.index('JOURNAL:'), result.stdout.index('ROLLBACK'))
        self.assertNotIn('SUCCESS', result.stdout)

    def test_start_command_failure_is_diagnosed_before_rollback(self):
        result = self.run_startup(start=1)
        self.assert_diagnosed_failure(result)
        self.assertNotIn('systemctl is-active', result.stdout)

    def test_process_exiting_after_start_is_diagnosed_before_rollback(self):
        self.assert_diagnosed_failure(self.run_startup(active=1))

    def test_unavailable_journal_does_not_prevent_rollback(self):
        self.assert_diagnosed_failure(self.run_startup(active=1, journal=1))

    def test_successful_update_restarts_without_failure_diagnostics(self):
        result = self.run_startup(was_active=1)
        self.assertEqual(result.returncode, 0, result.stdout)
        self.assertIn('systemctl restart kienzlefon-t2med.service', result.stdout)
        self.assertIn('SUCCESS', result.stdout)
        self.assertNotIn('JOURNAL:', result.stdout)
        self.assertNotIn('ROLLBACK', result.stdout)


if __name__ == '__main__':
    unittest.main()
