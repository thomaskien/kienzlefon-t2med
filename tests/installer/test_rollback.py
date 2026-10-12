"""Execute the real shell rollback body against isolated temporary files."""
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class RollbackTests(unittest.TestCase):
    def run_rollback(self, touched):
        with tempfile.TemporaryDirectory(prefix='kf-rollback-') as temp:
            root = Path(temp)
            work = root / 'work'
            work.mkdir()
            values = {'WORK_DIR': str(work), 'MUTATING': '1', 'SUCCESS': '0', 'SKIP_SAMBA': '0', 'SERVICE_WAS_ACTIVE': '0', 'SMB_WAS_ACTIVE': '0', 'SERVICE_TOUCHED': '0', 'SMB_SERVICE_TOUCHED': '0', 'SMB_WAS_ENABLED': '0'}
            app = root / 'app'
            app.mkdir()
            (app / 'payload').write_text('old-app')
            values.update(APP_DIR=str(app), APP_EXISTED='0', APP_TOUCHED='0', APP_BACKUP='')
            for name, filename in [('CONFIG', 'config'), ('UNIT', 'unit'), ('SMB_MAIN', 'smb-main'), ('SMB_INCLUDE', 'smb-include')]:
                target = root / filename
                target.write_text('old-' + filename)
                key = name + '_FILE' if name in ('CONFIG', 'UNIT') else name
                values.update({key: str(target), name + '_EXISTED': '0', name + '_BACKUP': ''})
            values.update(CONFIG_TOUCHED='0', UNIT_TOUCHED='0', SMB_TOUCHED='0')
            legacy = root / 'config.json'
            legacy.write_text('old-legacy-json')
            values['LEGACY_CONFIG_FILE'] = str(legacy)
            if touched:
                backup = work / 'app.backup'
                app.rename(backup)
                app.mkdir()
                (app / 'payload').write_text('new-app')
                values.update(APP_BACKUP=str(backup), APP_EXISTED='1', APP_TOUCHED='1')
            if touched == 'all':
                for name, filename in [('CONFIG', 'config'), ('UNIT', 'unit'), ('SMB_MAIN', 'smb-main'), ('SMB_INCLUDE', 'smb-include')]:
                    target = root / filename
                    backup = work / (filename + '.backup')
                    backup.write_text(target.read_text())
                    target.write_text('new-' + filename)
                    values.update({name + '_EXISTED': '1', name + '_BACKUP': str(backup)})
                values.update(CONFIG_TOUCHED='1', UNIT_TOUCHED='1', SMB_TOUCHED='1')
            if touched == 'migration':
                config = root / 'config'
                config.write_text('new-toml')
                unit = root / 'unit'
                unit_backup = work / 'unit.backup'
                unit_backup.write_text(unit.read_text())
                unit.write_text('new-toml-unit')
                values.update(CONFIG_EXISTED='0', CONFIG_TOUCHED='1',
                              UNIT_EXISTED='1', UNIT_TOUCHED='1', UNIT_BACKUP=str(unit_backup),
                              SERVICE_TOUCHED='1', SERVICE_WAS_ACTIVE='1')
            source = (ROOT / 'installer/install.sh').read_text()
            rollback = source[source.index('rollback() {'):source.index('trap rollback')]
            script = '\n'.join(k + '=' + shlex.quote(v) for k, v in values.items())
            script += '\nsystemctl() { return 0; }\nsmbcontrol() { return 0; }\n' + rollback + '\nfalse\nrollback\n'
            result = subprocess.run(['sh', '-c', script], capture_output=True, text=True)
            self.assertEqual(result.returncode, 1, result.stderr)
            self.assertEqual((app / 'payload').read_text(), 'old-app')
            for filename in ['config', 'unit', 'smb-main', 'smb-include']:
                if touched == 'migration' and filename == 'config':
                    self.assertFalse((root / filename).exists())
                else:
                    self.assertEqual((root / filename).read_text(), 'old-' + filename)
            self.assertEqual(legacy.read_text(), 'old-legacy-json')

    def test_failure_before_first_managed_write_preserves_existing_files(self):
        self.run_rollback(None)

    def test_failure_after_application_replace_preserves_untouched_config(self):
        self.run_rollback('app')

    def test_failure_after_all_changes_restores_exact_originals(self):
        self.run_rollback('all')

    def test_failed_first_json_migration_removes_toml_and_preserves_json(self):
        self.run_rollback('migration')


if __name__ == '__main__':
    unittest.main()
