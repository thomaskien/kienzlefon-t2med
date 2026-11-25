"""Execute the real directory and unit-render blocks using temporary paths."""
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[2]
class ControlDirectoryTests(unittest.TestCase):
    def test_enabled_and_disabled_journal_directory_reaches_systemd_unit(self):
        source=(ROOT/'installer/install.sh').read_text()
        start=source.index('\nCONTROL_DIR=$(run_configure control-directory')
        end=source.index('\nrun_configure paths "$PLANNED_CONFIG"',start)
        block=source[start:end]
        render=next(x for x in source.splitlines() if x.startswith('sed -e ') and '@@CONTROL_DIR@@' in x)
        for enabled in (False,True):
            with self.subTest(enabled=enabled), tempfile.TemporaryDirectory(prefix='kf-control-install-') as temp:
                root=Path(temp);c=json.loads((ROOT/'config.example.json').read_text());journal=root/'private'/'requests.sqlite'
                c['telephony_control'].update(enabled=enabled,pbx_id='practice-test',request_database=str(journal));config=root/'config.json';config.write_text(json.dumps(c))
                variables={'PLANNED_CONFIG':str(config),'WORK_DIR':temp,'SCRIPT_DIR':str(ROOT/'installer'),'SERVICE_USER':'test-user','GDT_ROOT':str(root/'gdt'),'STATISTICS_DIR':''}
                script='set -eu\n'+'\n'.join(k+'='+shlex.quote(v) for k,v in variables.items())+'\n'
                script+='fail() { echo "$*" >&2; exit 1; }\n'
                script+='run_configure() { '+shlex.quote(shutil.which('php'))+' '+shlex.quote(str(ROOT/'installer/configure.php'))+' "$@"; }\n'
                script+='install() { command mkdir -m 700 -p "${@: -1}"; }\nrunuser() { shift 3; "$@"; }\n'
                result=subprocess.run(['bash','-c',script+block+'\n'+render],text=True,capture_output=True)
                self.assertEqual(result.returncode,0,result.stderr)
                unit=(root/'service.rendered').read_text();self.assertNotIn('@@',unit)
                self.assertEqual(str(journal.parent) in unit,enabled);self.assertEqual(journal.parent.exists(),enabled)
                if enabled:self.assertEqual(os.stat(journal.parent).st_mode&0o777,0o700)
