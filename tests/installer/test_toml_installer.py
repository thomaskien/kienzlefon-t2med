"""TOML migration and installer decision tests without host modifications."""
import json
import os
from pathlib import Path
import pty
import shutil
import subprocess
import tempfile
import tomllib
import unittest

ROOT = Path(__file__).resolve().parents[2]
INSTALLER = ROOT / 'installer' / 'install.sh'
CONFIGURE = ROOT / 'installer' / 'configure.php'
FIXTURE = ROOT / 'tests/installer/fixtures/fon-connection.toml'


class TomlInstallerTests(unittest.TestCase):
    def run_config_plan(self, root, *, terminal=False, answers='', keep=False, connection=None, reconfigure=False, require_workplace=False):
        """Run the installer's real selection/prompt block against temp files."""
        source = INSTALLER.read_text()
        select = source[source.index('select_existing_config() {'):source.index('\nstart_middleware() {')]
        block = source[source.index('PLANNED_CONFIG=$WORK_DIR/config.toml'):source.index('# A running managed instance')]
        work = root / 'stage'
        work.mkdir()
        snapshot = ''
        if connection:
            snapshot = str(work / 'imported-ami.json')
            subprocess.run(['python3', str(ROOT / 'installer/ami_connection.py'), str(connection), snapshot], check=True)
            self.assertEqual(os.stat(snapshot).st_mode & 0o777, 0o600)
        script = '\n'.join([
            'set -eu', 'export T2MED_REQUIRE_WORKPLACE=' + ('fon' if require_workplace == 'fon' else str(int(require_workplace))), f'WORK_DIR={shlex_quote(str(work))}',
            f'CONFIG_FILE={shlex_quote(str(root / "config.toml"))}',
            f'LEGACY_CONFIG_FILE={shlex_quote(str(root / "config.json"))}',
            f'RECONFIGURE={int(reconfigure)}', f'KEEP_CONFIG={int(keep)}',
            f'AMI_SNAPSHOT={shlex_quote(snapshot)}',
            'say() { printf "%s\\n" "$*"; }',
            'fail() { printf "%s\\n" "$*" >&2; exit 1; }',
            f'run_configure() {{ {shlex_quote(shutil.which("php"))} {shlex_quote(str(CONFIGURE))} "$@"; }}',
            'set -x' if connection else '',  # Capture child argv for the secret-leak assertion.
            select, block,
        ])
        if terminal:
            master, slave = pty.openpty()
            try:
                process = subprocess.Popen(['bash', '-c', script], stdin=slave,
                                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                os.write(master, answers.encode())
                stdout, stderr = process.communicate(timeout=10)
                result = subprocess.CompletedProcess(process.args, process.returncode, stdout, stderr)
            finally:
                os.close(master)
                os.close(slave)
        else:
            result = subprocess.run(['bash', '-c', script], input='', capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result, work / 'config.toml'

    def test_fon_import_updates_only_exported_ami_fields(self):
        export = tomllib.loads(FIXTURE.read_text())['ami']
        for legacy, interactive in [(False, False), (True, False), (False, True)]:
            with self.subTest(legacy=legacy, interactive=interactive), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                connection = root / 'protected handoff.toml'
                shutil.copyfile(FIXTURE, connection)
                connection.chmod(0o600)
                before = json.loads((ROOT / 'config.example.json').read_text())
                before['ami']['ca_file'] = '/etc/ssl/private/local-ca.pem'
                before['telephony']['queues'] = ['team']
                before['telephony']['extensions']['201'] = 'Central phone'
                before['http']['allowed_hosts'] = ['localhost']
                before['aps'].update(url='https://aps.example', password='old-aps-marker')
                before['call_statistics'].update(enabled=True, pbx_id='test-office')
                before['telephony_control'].update(enabled=True, pbx_id='test-office')
                before['app_access'].update(enabled=True, allowed_clients=['empfang-1'])
                existing = root / 'config.json'
                existing.write_text(json.dumps(before))
                if not legacy:
                    subprocess.run(['php', str(CONFIGURE), 'migrate', str(existing), str(root/'config.toml')], check=True)
                    existing = root/'config.toml'
                original = existing.read_bytes()
                result, target = self.run_config_plan(root, connection=connection, reconfigure=interactive,
                                                      terminal=interactive, answers='\n' * 20)
                self.assertEqual(existing.read_bytes(), original)
                before['ami'].update(host=export['host'], port=export['port'], username=export['username'],
                                     secret=export['secret'], tls=True)
                if interactive:
                    before['telephony']['inactive_extensions'] = ['299']
                self.assertTrue(tomllib.loads(target.read_text()) == before, 'Unerwartete Änderung an Einstellungen')
                for private in ['old-aps-marker', export['secret']]:
                    self.assertNotIn(private, result.stdout + result.stderr)
                self.assertNotIn('AMI-Secret', result.stderr)

    def test_new_install_imports_local_tcp_without_credential_prompts(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            connection = root/'local.toml'
            connection.write_text(FIXTURE.read_text().replace('192.0.2.10', '127.0.0.1').replace('"tls"', '"tcp"'))
            connection.chmod(0o600)
            connection.write_text(connection.read_text().replace('["201", "299"]', '["201", "202", "299"]'))
            # Invalid input retries only the IP field. The menu toggles 201 twice, then enables 202.
            answers = ['', '', '', '', 'https://192.0.2.43/test', '192.0.2.43:16567', '192.0.2.43', '', '', '',
                       '1', '1', '99', '2', '0']
            result, target = self.run_config_plan(root, connection=connection, terminal=True,
                                                  answers='\n'.join(answers) + '\n')
            config = tomllib.loads(target.read_text())
            self.assertEqual(config['ami']['host'], '127.0.0.1')
            self.assertFalse(config['ami']['tls'])
            self.assertFalse(config['fhir']['tls_verify'])
            self.assertEqual(config['fhir']['base_url'], 'https://192.0.2.43:16567/aps/fhir/api/r4')
            self.assertEqual(result.stderr.count('gültige IPv4- oder IPv6-Adresse'), 3)
            self.assertEqual(config['clients'], {'202': dict(label='202', extension='202', directory='202')})
            self.assertEqual(config['telephony']['extensions'], {'202': '202'})
            self.assertEqual(config['telephony']['inactive_extensions'], ['201', '299'])
            self.assertIn('1) 201 [aktiv]', result.stderr)
            self.assertIn('2) 202 [inaktiv]', result.stderr)
            self.assertIn('2) 202 [aktiv]', result.stderr)
            self.assertIn('/telepraxis-app.php?arbeitsplatz=202&benutzer=202', result.stderr)
            self.assertNotIn('benutzer=201', result.stderr)
            self.assertNotIn('Name des Bearbeiters', result.stderr)
            self.assertEqual(config['app_access']['allowed_clients'], [])
            paths = subprocess.check_output(['php', str(CONFIGURE), 'paths', str(target)], text=True).splitlines()
            self.assertEqual(paths, ['/srv/kienzlefon-GDT', '202'])
            for hidden in ('AMI-Host', 'AMI-Port', 'AMI-Benutzer', 'AMI-Secret', 'AMI CA-Datei', 'FHIR CA-Datei', 'Client-ID:', config['ami']['secret']):
                self.assertNotIn(hidden, result.stdout + result.stderr)

    def test_app_requires_selection_and_preserves_leading_zeroes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            connection = root/'handoff.toml'
            connection.write_text(FIXTURE.read_text().replace('["201", "299"]', '["202", "002"]')
                                  .replace('["201"]', '["202"]'))
            connection.chmod(0o600)
            answers = ['', '', '', '2001:db8::42', '', '', '', '', '', '1 2', '']
            result, target = self.run_config_plan(root, connection=connection, terminal=True, require_workplace=True,
                                                  answers='\n'.join(answers) + '\n')
            config = tomllib.loads(target.read_text())
            self.assertEqual(config['fhir']['base_url'], 'https://[2001:db8::42]:16567/aps/fhir/api/r4')
            self.assertIn('mindestens eine Nebenstelle aktivieren', result.stderr)
            for number in ('002', '202'):
                self.assertEqual(config['clients'][number], dict(extension=number, directory=number, label=number))
            self.assertNotIn('Name des Bearbeiters (', result.stderr)
            self.assertEqual(set(config['telephony']['extensions']), {'002', '202'})

    def test_paired_fresh_accepts_fon_inventory_without_click_to_dial(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            connection = root/'handoff.toml'
            shutil.copyfile(FIXTURE, connection)
            connection.chmod(0o600)
            answers = ['', '', '', '192.0.2.43', '', '', '', '', '0']
            result, target = self.run_config_plan(root, connection=connection, terminal=True, require_workplace='fon',
                                                  answers='\n'.join(answers) + '\n')
            config = tomllib.loads(target.read_text())
            self.assertFalse(config['clients'])
            self.assertFalse(config['telephony']['inactive_extensions'])
            self.assertIn('Nebenstellenanzeige aus Fon', result.stderr)

    def test_reconfigure_toggles_saved_selection_without_export(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            config = json.loads((ROOT/'config.example.json').read_text())
            config['clients'] = {'201': dict(label='Anna', extension='201', directory='201')}
            config['telephony'].update(extensions={'201': 'Anna'}, inactive_extensions=['202'])
            config['app_access']['allowed_clients'] = ['201']
            (root/'config.json').write_text(json.dumps(config))
            answers = [''] * 12 + ['1 99', '1 2', '0']
            result, target = self.run_config_plan(root, reconfigure=True, terminal=True,
                                                  answers='\n'.join(answers) + '\n')
            saved = tomllib.loads(target.read_text())
            self.assertIn('1) 201 [aktiv]', result.stderr)
            self.assertIn('2) 202 [inaktiv]', result.stderr)
            self.assertEqual(saved['clients'], {'202': dict(label='202', extension='202', directory='202')})
            self.assertEqual(saved['telephony']['inactive_extensions'], ['201'])
            # Removing a GDT client must preserve an explicit App routing restriction.
            self.assertEqual(saved['app_access']['allowed_clients'], ['201'])
            self.assertNotIn('benutzer=201', result.stderr)

    def test_empty_export_allows_later_setup_and_explicit_fhir_ca(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            connection = root/'handoff.toml'
            connection.write_text(FIXTURE.read_text().split('[click_to_dial]')[0])
            connection.chmod(0o600)
            answers = ['', '', '', '192.0.2.42', 'j', '/etc/ssl/local-ca.pem', '', '', '', '']
            _, target = self.run_config_plan(root, connection=connection, terminal=True, answers='\n'.join(answers) + '\n')
            config = tomllib.loads(target.read_text())
            self.assertFalse(config['clients'])
            self.assertFalse(config['telephony']['extensions'])
            self.assertTrue(config['fhir']['tls_verify'])
            self.assertEqual(config['fhir']['ca_file'], '/etc/ssl/local-ca.pem')

    def test_existing_tls_defaults_survive_migration_and_reconfiguration(self):
        for verify in (True, False, None):
            with self.subTest(verify=verify), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                config = json.loads((ROOT/'config.example.json').read_text())
                for key in ('aps', 'fhir'):
                    if verify is None:
                        del config[key]['tls_verify']
                    else:
                        config[key]['tls_verify'] = verify
                    if verify is not False:
                        config[key]['ca_file'] = '/private/practice-ca.pem'
                (root/'config.json').write_text(json.dumps(config))
                _, target = self.run_config_plan(root, terminal=True, reconfigure=True, answers='\n' * 26)
                saved = tomllib.loads(target.read_text())
                for key in ('aps', 'fhir'):
                    self.assertEqual(saved[key]['tls_verify'], verify is not False)
                    self.assertEqual(saved[key]['ca_file'], config[key]['ca_file'])

    def test_new_aps_install_uses_https_without_certificate_check(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            connection = root/'handoff.toml'
            shutil.copyfile(FIXTURE, connection)
            connection.chmod(0o600)
            answers = ['', '', 'aps', '', '', 'aps-test', '', '', '', '', '']
            result, target = self.run_config_plan(root, connection=connection, terminal=True,
                                                  answers='\n'.join(answers) + '\n')
            aps = tomllib.loads(target.read_text())['aps']
            self.assertTrue(aps['url'].startswith('https://'))
            self.assertFalse(aps['tls_verify'])
            self.assertEqual(aps['ca_file'], '')
            self.assertNotIn('APS CA-Datei', result.stderr)

    def test_reconfigure_retains_legacy_fhir_url_or_uses_new_standard_ip(self):
        for ip, expected in [('', 'https://records.example:8443/legacy/r4/'),
                             ('192.0.2.22', 'https://192.0.2.22:16567/aps/fhir/api/r4')]:
            with self.subTest(ip=ip), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                config = json.loads((ROOT/'config.example.json').read_text())
                config['fhir']['base_url'] = 'https://records.example:8443/legacy/r4/'
                (root/'config.json').write_text(json.dumps(config))
                _, target = self.run_config_plan(root, terminal=True, reconfigure=True,
                                                  answers='\n' * 3 + ip + '\n' * 15)
                self.assertEqual(tomllib.loads(target.read_text())['fhir']['base_url'], expected)

    def test_update_no_preserves_toml_comments_and_ignores_legacy(self):
        with tempfile.TemporaryDirectory(prefix='kf-update-') as temp:
            root = Path(temp)
            before = b'# Individuelle Kommentare bleiben erhalten\n' + (ROOT / 'config.example.toml').read_bytes()
            (root / 'config.toml').write_bytes(before)
            (root / 'config.json').write_text('invalid legacy file')
            result, target = self.run_config_plan(root, terminal=True, answers='\n')
            self.assertIn('Vorhandene Einstellungen ändern', result.stderr)
            self.assertEqual(target.read_bytes(), before)
            self.assertEqual((root / 'config.toml').read_bytes(), before)

    def test_noninteractive_update_migrates_legacy_without_prompt(self):
        with tempfile.TemporaryDirectory(prefix='kf-update-') as temp:
            root = Path(temp)
            before = (ROOT / 'config.example.json').read_bytes()
            (root / 'config.json').write_bytes(before)
            result, target = self.run_config_plan(root)
            self.assertNotIn('Vorhandene Einstellungen ändern', result.stderr)
            self.assertTrue(target.is_file())
            self.assertEqual((root / 'config.json').read_bytes(), before)

    def test_keep_config_skips_terminal_prompt_and_keeps_toml(self):
        with tempfile.TemporaryDirectory(prefix='kf-update-') as temp:
            root = Path(temp)
            before = (ROOT / 'config.example.toml').read_bytes()
            (root / 'config.toml').write_bytes(before)
            result, target = self.run_config_plan(root, terminal=True, keep=True)
            self.assertNotIn('Vorhandene Einstellungen ändern', result.stderr)
            self.assertEqual(target.read_bytes(), before)

    def test_json_migration_is_lossless_and_keeps_original(self):
        with tempfile.TemporaryDirectory(prefix='kf-toml-') as temp:
            legacy = Path(temp) / 'config.json'
            target = Path(temp) / 'config.toml'
            config = json.loads((ROOT / 'config.example.json').read_text())
            config['patients']['provider'] = 'aps'
            config['ami']['secret'] = 'secret # "quoted" \\ Umlaut ä'
            config['aps']['password'] = ''
            config['telephony']['queues'] = []
            before = json.dumps(config, ensure_ascii=False, indent=2).encode()
            legacy.write_bytes(before)

            result = subprocess.run(
                [shutil.which('php'), str(CONFIGURE), 'migrate', str(legacy), str(target)],
                capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout, '')
            self.assertEqual(legacy.read_bytes(), before)
            compare = subprocess.run(
                [shutil.which('php'), '-r',
                 'require $argv[1]; $a=Kienzlefon\\Config::load($argv[2]); '
                 '$b=Kienzlefon\\Config::load($argv[3]); exit($a===$b ? 0 : 1);',
                 str(ROOT / 'vendor' / 'autoload.php'), str(legacy), str(target)],
                capture_output=True, text=True,
            )
            self.assertEqual(compare.returncode, 0, compare.stderr)
            self.assertNotIn(config['ami']['secret'], result.stdout + result.stderr)

    def test_toml_precedes_legacy_json_when_both_exist(self):
        source = INSTALLER.read_text()
        function = source[source.index('select_existing_config() {'):source.index('\nstart_middleware() {')]
        with tempfile.TemporaryDirectory(prefix='kf-select-') as temp:
            toml = Path(temp) / 'config.toml'
            legacy = Path(temp) / 'config.json'
            legacy.write_text('{}')
            toml.write_text('invalid = true')
            script = (f'CONFIG_FILE={shlex_quote(str(toml))}\n'
                      f'LEGACY_CONFIG_FILE={shlex_quote(str(legacy))}\n'
                      f'{function}\nselect_existing_config\n')
            selected = subprocess.run(['sh', '-c', script], capture_output=True, text=True)
            self.assertEqual(selected.returncode, 0, selected.stderr)
            self.assertEqual(selected.stdout.strip(), str(toml))
            validation = subprocess.run(
                [shutil.which('php'), str(CONFIGURE), 'validate', selected.stdout.strip()],
                capture_output=True, text=True,
            )
            self.assertNotEqual(validation.returncode, 0)

    def test_reconfigure_changes_and_adds_clients_while_retaining_secrets(self):
        with tempfile.TemporaryDirectory(prefix='kf-interactive-') as temp:
            existing = Path(temp) / 'existing.json'
            target = Path(temp) / 'configured.toml'
            config = json.loads((ROOT / 'config.example.json').read_text())
            config['patients']['provider'] = 'aps'
            config['aps']['password'] = 'retained-aps'
            config['ami']['secret'] = 'retained-ami'
            existing.write_text(json.dumps(config))
            answers = [
                *([''] * 14),
                '2', '303', '0',  # Add a phone beside the legacy workplace.
            ]
            result = subprocess.run(
                [shutil.which('php'), str(CONFIGURE), 'interactive', str(target), str(existing)],
                input='\n'.join(answers) + '\n', capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            decoded = subprocess.run(
                [shutil.which('php'), '-r',
                 'require $argv[1]; echo json_encode(Kienzlefon\\Config::load($argv[2]));',
                 str(ROOT / 'vendor' / 'autoload.php'), str(target)],
                capture_output=True, text=True,
            )
            self.assertEqual(decoded.returncode, 0, decoded.stderr)
            updated = json.loads(decoded.stdout)
            self.assertEqual(updated['clients']['empfang-1']['extension'], '201')
            self.assertEqual(updated['clients']['empfang-1']['directory'], 'empfang-1')
            self.assertEqual(updated['clients']['303'], dict(label='303', extension='303', directory='303'))
            self.assertEqual(updated['aps']['password'], 'retained-aps')
            self.assertEqual(updated['ami']['secret'], 'retained-ami')

    def test_interactive_numeric_client_creation_and_reconfiguration(self):
        with tempfile.TemporaryDirectory(prefix='kf-numeric-') as temp:
            existing = Path(temp) / 'existing.json'
            target = Path(temp) / 'configured.toml'
            config = json.loads((ROOT / 'config.example.json').read_text())
            config['patients']['provider'] = 'aps'
            config['clients'] = {}
            existing.write_text(json.dumps(config))
            for id_to_add, expected in [('201', ['201']), ('001', ['201', '001'])]:
                answers = [''] * 14  # Existing server settings and credentials.
                answers += [str(len(expected)), id_to_add, '0']
                result = subprocess.run(
                    [shutil.which('php'), str(CONFIGURE), 'interactive', str(target), str(existing)],
                    input='\n'.join(answers) + '\n', capture_output=True, text=True,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                decoded = subprocess.run(
                    [shutil.which('php'), '-r',
                     'require $argv[1]; echo json_encode(Kienzlefon\\Config::load($argv[2]));',
                     str(ROOT / 'vendor' / 'autoload.php'), str(target)],
                    capture_output=True, text=True, check=True,
                )
                updated = json.loads(decoded.stdout)
                self.assertEqual(list(updated['clients']), expected)
                for client_id in expected:
                    self.assertEqual(updated['clients'][client_id]['directory'], client_id)
                    self.assertEqual(updated['clients'][client_id]['extension'], client_id)
                existing = Path(temp) / 'existing.toml'
                existing.write_bytes(target.read_bytes())

    def test_dry_run_and_keep_config_are_noninteractive(self):
        dry = subprocess.run(['bash', str(INSTALLER), '--dry-run'], input='',
                             capture_output=True, text=True)
        self.assertEqual(dry.returncode, 0, dry.stderr)
        self.assertIn('ohne Terminal Werte übernehmen', dry.stdout)
        self.assertNotIn(')? [j/N]:', dry.stderr)
        keep = subprocess.run(['bash', str(INSTALLER), '--dry-run', '--keep-config'],
                              input='', capture_output=True, text=True)
        self.assertEqual(keep.returncode, 0, keep.stderr)
        self.assertIn('ohne Nachfrage', keep.stdout)

    def test_reconfigure_conflicts_with_keep_config(self):
        result = subprocess.run(
            ['bash', str(INSTALLER), '--dry-run', '--reconfigure', '--keep-config'],
            capture_output=True, text=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('können nicht kombiniert werden', result.stderr)

    def test_stage_parser_exists_before_config_and_samba_preflight(self):
        source = INSTALLER.read_text()
        composer = source.index('composer install --no-dev')
        config = source.index('run_configure validate "$EXISTING_CONFIG"')
        samba = source.index('SAMBA_PLAN=$WORK_DIR/samba-plan')
        self.assertLess(composer, config)
        self.assertLess(composer, samba)
        self.assertNotIn('$SCRIPT_DIR/configure.php', source)
        self.assertIn('cp -p -- "$CONFIG_FILE" "$PLANNED_CONFIG"', source)
        self.assertIn('Vorhandene Einstellungen ändern (z. B. Nebenstellen/Arbeitsplätze)? [j/N]', source)


def shlex_quote(value):
    """Quote a controlled temporary path for a tiny POSIX shell fixture."""
    return "'" + value.replace("'", "'\\''") + "'"


if __name__ == '__main__':
    unittest.main()
