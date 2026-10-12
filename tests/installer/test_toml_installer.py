"""TOML migration and installer decision tests without host modifications."""
import json
import os
from pathlib import Path
import pty
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
INSTALLER = ROOT / 'installer' / 'install.sh'
CONFIGURE = ROOT / 'installer' / 'configure.php'


class TomlInstallerTests(unittest.TestCase):
    def run_config_plan(self, root, *, terminal=False, answers='', keep=False):
        """Run the installer's real selection/prompt block against temp files."""
        source = INSTALLER.read_text()
        select = source[source.index('select_existing_config() {'):source.index('\nstart_middleware() {')]
        block = source[source.index('PLANNED_CONFIG=$WORK_DIR/config.toml'):source.index('# A running managed instance')]
        work = root / 'stage'
        work.mkdir()
        script = '\n'.join([
            'set -eu', f'WORK_DIR={shlex_quote(str(work))}',
            f'CONFIG_FILE={shlex_quote(str(root / "config.toml"))}',
            f'LEGACY_CONFIG_FILE={shlex_quote(str(root / "config.json"))}',
            'RECONFIGURE=0', f'KEEP_CONFIG={int(keep)}',
            'say() { printf "%s\\n" "$*"; }',
            'fail() { printf "%s\\n" "$*" >&2; exit 1; }',
            f'run_configure() {{ {shlex_quote(shutil.which("php"))} {shlex_quote(str(CONFIGURE))} "$@"; }}',
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
            config['aps']['password'] = 'retained-aps'
            config['ami']['secret'] = 'retained-ami'
            existing.write_text(json.dumps(config))
            answers = [
                '', '', '', '', '', '', '', '', '', '', '', '', '',
                '', '',
                '', '', '211', '',
                'labor', 'Labor', '303', 'labor-gdt', '',
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
            self.assertEqual(updated['clients']['empfang-1']['extension'], '211')
            self.assertEqual(updated['clients']['empfang-1']['directory'], 'empfang-1')
            self.assertEqual(updated['clients']['labor']['directory'], 'labor-gdt')
            self.assertEqual(updated['aps']['password'], 'retained-aps')
            self.assertEqual(updated['ami']['secret'], 'retained-ami')

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
