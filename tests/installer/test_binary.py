"""The installed FHIR helper must remain executable after permission normalization."""
from pathlib import Path
import os
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class BinaryPermissions(unittest.TestCase):
    def test_installed_helper_can_be_executed(self):
        source = (ROOT / 'installer/install.sh').read_text()
        start = source.index('find "$APP_DIR" -type d')
        block = source[start:source.index('\ninstall -d -o root -g "$SERVICE_USER"', start)]
        with tempfile.TemporaryDirectory(prefix='kf-binary-mode-') as temp:
            root = Path(temp); (root/'libexec').mkdir()
            for name in ['fhir-importer','fhir-importer-linux-amd64','fhir-importer-linux-arm64']:
                (root/'libexec'/name).write_text('#!/bin/sh\nprintf "helper-ran"\n')
            (root/'config.json').write_text('{}')
            result = subprocess.run(['sh','-c','set -e\n'+block],env={**os.environ,'APP_DIR':str(root)},capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)
            result = subprocess.run([str(root/'libexec/fhir-importer')],capture_output=True,text=True)
            self.assertEqual(result.stdout,'helper-ran')
            self.assertEqual((root/'config.json').stat().st_mode&0o777,0o644)


if __name__=='__main__': unittest.main()
