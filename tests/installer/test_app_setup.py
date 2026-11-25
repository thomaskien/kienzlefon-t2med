import json
import os
import shutil
import subprocess
import tempfile
import tomllib
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class AppSetup(unittest.TestCase):
    def test_new_existing_and_external_access(self):
        with tempfile.TemporaryDirectory() as temp:
            config, proxy = Path(temp) / "config.toml", Path(temp) / "proxy.php"
            shutil.copy(ROOT / "config.example.toml", config)
            original = tomllib.loads(config.read_text())
            def prepare(address=""):
                return subprocess.check_output(["php", ROOT / "installer/configure.php", "prepare-app",
                                                config, proxy, address], text=True)
            self.assertEqual(prepare(), original["app_access"]["token_file"])
            configured = tomllib.loads(config.read_text())
            original["app_access"]["enabled"] = True
            self.assertEqual(configured, original)
            self.assertEqual(configured["app_access"]["allowed_clients"], [])
            self.assertIn("http://127.0.0.1:8766", proxy.read_text())
            first = config.read_bytes(), proxy.read_bytes()
            prepare()
            self.assertEqual(first, (config.read_bytes(), proxy.read_bytes()))
            prepare("192.0.2.15")
            prepare("192.0.2.15")
            allowed = tomllib.loads(config.read_text())["app_access"]["allowed_networks"]
            self.assertEqual(allowed, original["app_access"]["allowed_networks"] + ["192.0.2.15/32"])

    def test_repair_preserves_app_workplace_policy_and_checks_pbx(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            config, proxy, policy = root/'config.toml', root/'proxy.php', root/'policy.json'
            config.write_text((ROOT/'config.example.toml').read_text().replace('pbx_id = ""', 'pbx_id = "praxis-a"'))
            prepare = ["php", ROOT/"installer/configure.php", "prepare-app", config, proxy, "", "yes", policy]
            for clients in ([], [dict(id="desk", label="Empfang", extension="201")]):
                expected = dict(workplace_mode="selected", clients=clients, pbx_id="praxis-a")
                policy.write_text(json.dumps(expected))
                subprocess.run(prepare, check=True, capture_output=True)
                saved = json.loads(subprocess.check_output(["php", "-r", "echo json_encode(require $argv[1]);", proxy]))
                self.assertEqual({key: saved[key] for key in expected}, expected)
            policy.write_text(json.dumps(dict(pbx_id="andere-praxis")))
            refusal = subprocess.run(prepare, capture_output=True, text=True)
            self.assertNotEqual(refusal.returncode, 0)
            self.assertIn("Abweichende Anlagenkennungen", refusal.stderr)

    def test_service_key_exists_before_installer_checks_service_configuration(self):
        shell = (ROOT/'installer/install.sh').read_text()
        start = shell.index('publish_phone_file() {')
        stop = shell.index('if [ -f "$UNIT_FILE" ]; then UNIT_EXISTED', start)
        self.assertNotIn('--check-config', shell[:start])
        provision = shell[start:stop].replace('/usr/bin/php', 'php')
        self.assertIn('runuser -u "$SERVICE_USER" -- php "$APP_DIR/bin/server.php"', provision)
        # Run the actual provisioning and config checks as the test user. Only Linux
        # ownership changes and GNU mv's -T option need portable substitutes.
        script = '''set -eu
fail() { echo "$*" >&2; exit 1; }
install() {
    if [ "$1" = -d ]; then mkdir -p "${@: -1}"; return; fi
    local args=()
    while [ "$#" -gt 0 ]; do
        case "$1" in -o|-g) shift 2 ;; *) args+=("$1"); shift ;; esac
    done
    command install "${args[@]}"
}
mv() { if [ "$1" = -fT ]; then shift; fi; command mv -f "$@"; }
runuser() { test "$1" = -u && test "$3" = -- || exit 1; shift 3; "$@"; }
'''
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            confdir = base/'settings'
            confdir.mkdir()
            service = confdir/'app.token'
            candidate = base/'candidate.toml'
            candidate.write_text((ROOT/'config.example.toml').read_text().replace(
                '/etc/kienzlefon-t2med/app.token', str(service)))
            phone = base/'phone'
            env = dict(os.environ, APP_DIR=str(ROOT), CONFIG_DIR=str(confdir), SERVICE_USER='middleware',
                       PLANNED_CONFIG=str(candidate), CONFIG_FILE=str(confdir/'config.toml'),
                       SERVICE_TOKEN=str(service), PHONE_CONFIG_DIR=str(phone), PHONE_CONFIG=str(phone/'config.php'),
                       LINK_LOCAL_APP='yes')

            def run(attempt):
                work = base/attempt
                work.mkdir()
                subprocess.run(['php', ROOT/'installer/configure.php', 'prepare-app', candidate, work/'webconfig', ''],
                               capture_output=True, check=True)
                return subprocess.run(['bash', '-c', script + provision], env=dict(env, WORK_DIR=str(work)),
                                      text=True, capture_output=True)

            first = run('fresh')
            self.assertEqual(first.returncode, 0, first.stderr)
            self.assertIn('Konfiguration gültig', first.stdout)
            key = service.read_bytes().strip()
            self.assertTrue(len(key) == 64)
            web = phone/'t2med-token'
            for target in (service, web):
                self.assertEqual(target.stat().st_mode & 0o777, 0o600)
                self.assertTrue(target.read_bytes().strip() == key)
            update = run('update')
            self.assertEqual(update.returncode, 0, update.stderr)
            self.assertTrue(service.read_bytes().strip() == key)
            service.chmod(0o640)
            unsafe = run('unsafe')
            self.assertNotEqual(unsafe.returncode, 0)
            self.assertIn('0600', unsafe.stderr)
            for result in (first, update, unsafe):
                self.assertFalse(key.decode() in result.stdout + result.stderr)
