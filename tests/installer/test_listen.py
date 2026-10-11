"""Check port conflicts against real loopback listeners, without changing services."""
import json
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ListenTests(unittest.TestCase):
    def check_listen(self, address):
        with tempfile.TemporaryDirectory(prefix='kf-listen-') as temp:
            path = Path(temp) / 'config.json'
            config = json.loads((ROOT / 'config.example.json').read_text())
            config['http']['listen'] = address
            config['ami']['secret'] = 'do-not-print-this-secret'
            path.write_text(json.dumps(config))
            result = subprocess.run([shutil.which('php'), str(ROOT / 'installer/configure.php'),
                                     'check-listen', str(path)], capture_output=True, text=True)
            self.assertNotIn(config['ami']['secret'], result.stdout + result.stderr)
            return result

    def test_free_port_is_released_after_successful_probe(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        result = self.check_listen(f'127.0.0.1:{port}')
        self.assertEqual(result.returncode, 0, result.stderr)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', port))
            listener.listen()

    def assert_foreign_listener_preserved(self, bind_host):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen()
            port = listener.getsockname()[1]
            result = self.check_listen(f'{bind_host}:{port}')
            self.assertEqual(result.returncode, 1, result.stdout)
            self.assertIn(f'{bind_host}:{port}', result.stderr)
            self.assertIn('Middleware publicURL', result.stderr)
            self.assertIn('--reconfigure', result.stderr)
            with socket.create_connection(('127.0.0.1', port), timeout=1):
                pass

    def test_foreign_listener_is_not_stopped(self):
        self.assert_foreign_listener_preserved('127.0.0.1')

    @unittest.skipUnless(sys.platform == 'linux', 'Linux wildcard binding semantics; installer targets Linux')
    def test_foreign_loopback_listener_blocks_wildcard_on_linux(self):
        self.assert_foreign_listener_preserved('0.0.0.0')

    def test_ipv6_listener_conflict(self):
        with socket.socket(socket.AF_INET6) as listener:
            try:
                listener.bind(('::1', 0))
            except OSError:
                self.skipTest('IPv6 loopback unavailable')
            listener.listen()
            port = listener.getsockname()[1]
            result = self.check_listen(f'[::1]:{port}')
            self.assertEqual(result.returncode, 1, result.stdout)
            self.assertIn(f'[::1]:{port}', result.stderr)


if __name__ == '__main__':
    unittest.main()
