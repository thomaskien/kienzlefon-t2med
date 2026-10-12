import importlib.util
import io
import json
import os
import re
import subprocess
import tempfile
import unittest
from unittest import mock
import urllib.error
import urllib.parse
from pathlib import Path


MODULE_PATH = Path(__file__).parents[2] / "clients" / "kienzlefon_call.py"
REPOSITORY = MODULE_PATH.parents[1]
SPEC = importlib.util.spec_from_file_location("kienzlefon_call", MODULE_PATH)
kc = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(kc)


class FakeHeaders:
    def get_content_type(self):
        return "application/json"


class FakeResponse:
    def __init__(self, status, body):
        self.status = status
        self.body = json.dumps(body).encode()
        self.headers = FakeHeaders()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def getcode(self):
        return self.status

    def read(self, _limit):
        return self.body


class FakeOpener:
    def __init__(self, responses):
        self.responses = list(responses)
        self.requests = []

    def open(self, request, timeout):
        self.requests.append((request, timeout))
        response = self.responses.pop(0)
        if isinstance(response, Exception):
            raise response
        return response


class NumberTests(unittest.TestCase):
    def test_encoded_plus_and_spaces_are_normalized(self):
        self.assertEqual(kc.normalize_number("tel:%2B49%2030-123.45"), "+493012345")
        self.assertEqual(kc.normalize_number("callto: 201 555"), "201555")

    def test_injection_and_bad_lengths_are_rejected(self):
        for value in ["tel:123;ext=4", "tel:123?x=1", "tel:12&x", "tel:123%0dX", "12", "1" * 21, "https:123"]:
            with self.subTest(value=value), self.assertRaises(kc.ClientError):
                kc.normalize_number(value)

    def test_raw_plus_is_not_decoded_as_space(self):
        self.assertEqual(kc.normalize_number("tel:+49 (30) 123"), "+4930123")

    def test_encoded_uri_metacharacters_are_rejected(self):
        for value in ["tel:123%3Ffoo", "tel:123%23fragment", "tel:%2F%2Fevil", "callto:123%3Bext%3D9"]:
            with self.subTest(value=value), self.assertRaises(kc.ClientError):
                kc.normalize_number(value)


class ConfigTests(unittest.TestCase):
    def test_config_is_private_and_roundtrips(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "nested" / "client.json"
            kc.write_config(path, "https://phone.example:8765", "empfang-1", "201")
            self.assertEqual(kc.load_config(path)["client_id"], "empfang-1")
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)

    def test_registration_rejects_credentials_paths_and_bad_ids(self):
        bad = [
            ("https://user@example.test", "client", "201"),
            ("https://example.test/path", "client", "201"),
            ("file:///tmp/x", "client", "201"),
            ("https://example.test", "../client", "201"),
            ("https://example.test", "client", "20A"),
            ("https://example.test:99999", "client", "201"),
            ("https://example.test\n.evil", "client", "201"),
            ("[http://10.0.83.120:8766](http://10.0.83.120:8766)", "mac", "202"),
        ]
        for values in bad:
            with self.subTest(values=values), self.assertRaises(kc.ClientError):
                kc.validate_registration(*values)

    def test_noninteractive_confirmation_is_refused(self):
        original_platform = kc.sys.platform
        original_stdin = kc.sys.stdin
        try:
            kc.sys.platform = "linux"
            kc.sys.stdin = type("NonInteractive", (), {"isatty": lambda self: False})()
            with self.assertRaisesRegex(kc.ClientError, "Benutzeraktion"):
                kc.confirm_call("12345")
        finally:
            kc.sys.platform = original_platform
            kc.sys.stdin = original_stdin


class HttpTests(unittest.TestCase):
    def setUp(self):
        self.config = {"url": "https://phone.example", "client_id": "empfang-1", "extension": "201"}

    def test_bootstrap_mapping_is_checked_before_single_post(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "temporary", "clients": [{"id": "empfang-1", "label": "Empfang", "extension": "201"}]}),
            FakeResponse(202, {"request_id": "same-id", "status": "accepted"}),
        ])
        result = kc.submit_call(self.config, "+4930123", opener=opener, request_id="same-id")
        self.assertEqual(len(opener.requests), 2)
        post = opener.requests[1][0]
        self.assertEqual(post.get_method(), "POST")
        self.assertEqual(post.get_header("X-kienzlefon-token"), "temporary")
        self.assertEqual(json.loads(post.data)["request_id"], "same-id")
        self.assertEqual(result["response"]["status"], "accepted")

    def test_mismatched_extension_prevents_post(self):
        opener = FakeOpener([FakeResponse(200, {"token": "x", "clients": [{"id": "empfang-1", "extension": "999"}]})])
        with self.assertRaises(kc.ClientError):
            kc.submit_call(self.config, "12345", opener=opener)
        self.assertEqual(len(opener.requests), 1)

    def test_numeric_client_registration_and_bootstrap_mapping(self):
        for client_id in ['201', '0', '001', '1']:
            with self.subTest(client_id=client_id), tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / 'client.json'
                kc.write_config(path, self.config['url'], client_id, '202')
                config = kc.load_config(path)
                self.assertEqual(config['client_id'], client_id)
                opener = FakeOpener([
                    FakeResponse(200, {'token': 'temporary', 'clients': [{'id': client_id, 'extension': '202'}]}),
                    FakeResponse(202, {'status': 'accepted'}),
                ])
                kc.submit_call(config, '+4930123', opener=opener)
                payload = json.loads(opener.requests[1][0].data)
                self.assertEqual(payload['client_id'], client_id)
                self.assertEqual(payload['extension'], '202')

    def test_http_errors_are_not_retried(self):
        error = urllib.error.HTTPError("https://phone.example/api/v1/bootstrap", 503, "down", {}, None)
        error.read = lambda _limit=4096: b"temporarily unavailable"
        opener = FakeOpener([error])
        with self.assertRaisesRegex(kc.ClientError, "HTTP 503"):
            kc.submit_call(self.config, "12345", opener=opener)
        error.close()
        self.assertEqual(len(opener.requests), 1)

    def test_non_202_post_is_rejected_without_retry(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "x", "clients": [{"id": "empfang-1", "extension": "201"}]}),
            FakeResponse(200, {"status": "ok"}),
        ])
        with self.assertRaisesRegex(kc.ClientError, "erwartet 202"):
            kc.submit_call(self.config, "12345", opener=opener)
        self.assertEqual(len(opener.requests), 2)

    def test_browser_mode_uses_one_get_and_no_post(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "temporary", "clients": [{"id": "empfang-1", "extension": "201"}]}),
        ])
        browser_open = mock.Mock(return_value=True)
        target = kc.open_call_in_browser(self.config, "tel:+49 30 123", opener=opener, browser_open=browser_open)
        self.assertEqual(len(opener.requests), 1)
        self.assertEqual(opener.requests[0][0].get_method(), "GET")
        self.assertIsNone(opener.requests[0][0].data)
        browser_open.assert_called_once_with(target, new=2, autoraise=True)
        parsed = urllib.parse.urlsplit(target)
        self.assertEqual(parsed.path, "/waehlen")
        self.assertEqual(
            urllib.parse.parse_qs(parsed.query),
            {
                "client_id": ["empfang-1"],
                "telefon": ["+4930123"],
                "expected_extension": ["201"],
                "view": ["compact"],
            },
        )
        self.assertNotIn("sofort", target)

    def test_callto_uri_reaches_the_configured_client(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "temporary", "clients": [{"id": "empfang-1", "extension": "201"}]}),
        ])
        browser_open = mock.Mock(return_value=True)
        target = kc.open_call_in_browser(self.config, "callto:03012345", opener=opener, browser_open=browser_open)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(target).query)
        self.assertEqual(query["client_id"], ["empfang-1"])
        self.assertEqual(query["telefon"], ["03012345"])

    def test_browser_mode_mapping_error_does_not_open_browser(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "temporary", "clients": [{"id": "empfang-1", "extension": "999"}]}),
        ])
        browser_open = mock.Mock(return_value=True)
        with self.assertRaisesRegex(kc.ClientError, "Serverkonfiguration"):
            kc.open_call_in_browser(self.config, "03012345", opener=opener, browser_open=browser_open)
        browser_open.assert_not_called()
        self.assertEqual(len(opener.requests), 1)

    def test_monitor_mode_checks_mapping_then_opens_compact_view(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "temporary", "clients": [{"id": "empfang-1", "extension": 201}]}),
        ])
        browser_open = mock.Mock(return_value=True)
        target = kc.open_monitor(self.config, opener=opener, browser_open=browser_open)
        self.assertEqual(len(opener.requests), 1)
        self.assertEqual(target, "https://phone.example/?view=compact&client_id=empfang-1")
        browser_open.assert_called_once_with(target, new=2, autoraise=True)

    def test_browser_failure_is_reported(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "temporary", "clients": [{"id": "empfang-1", "extension": "201"}]}),
        ])
        with self.assertRaisesRegex(kc.ClientError, "Browser"):
            kc.open_call_in_browser(self.config, "03012345", opener=opener, browser_open=lambda *_args, **_kwargs: False)


class MainModeTests(unittest.TestCase):
    def test_uri_cannot_override_registered_options(self):
        args = kc.parse_args(['--open-browser', '--config', '/expected/client.json', '--', '--write-config'])
        self.assertFalse(args.write_config)
        self.assertEqual(args.config, Path('/expected/client.json'))
        with self.assertRaises(kc.ClientError):
            kc.normalize_number(args.uri)
        with mock.patch('sys.stderr', io.StringIO()), self.assertRaises(SystemExit):
            kc.parse_args(['--open-browser', '--', 'tel:03012345', '--config', '/other.json'])

    def write_config(self, directory):
        path = Path(directory) / "client.json"
        kc.write_config(path, "https://phone.example", "empfang-1", "201")
        return path

    def test_open_browser_dry_run_has_no_network_browser_call_or_confirmation(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.write_config(directory)
            output = io.StringIO()
            with (
                mock.patch.object(kc, "verify_bootstrap") as verify,
                mock.patch.object(kc.webbrowser, "open") as browser,
                mock.patch.object(kc, "submit_call") as submit,
                mock.patch.object(kc, "confirm_call") as confirm,
                mock.patch("sys.stdout", output),
            ):
                result = kc.main(["--config", str(path), "--open-browser", "--dry-run", "tel:+493012345"])
            self.assertEqual(result, 0)
            verify.assert_not_called()
            browser.assert_not_called()
            submit.assert_not_called()
            confirm.assert_not_called()
            self.assertIn("expected_extension=201", output.getvalue())
            self.assertNotIn("sofort=1", output.getvalue())

    def test_open_monitor_dry_run_has_no_external_io(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.write_config(directory)
            with (
                mock.patch.object(kc, "verify_bootstrap") as verify,
                mock.patch.object(kc.webbrowser, "open") as browser,
                mock.patch("sys.stdout", io.StringIO()),
            ):
                result = kc.main(["--config", str(path), "--open-monitor", "--dry-run"])
            self.assertEqual(result, 0)
            verify.assert_not_called()
            browser.assert_not_called()

    def test_desktop_errors_are_shown_for_invalid_uri(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.write_config(directory)
            with (
                mock.patch.object(kc, "show_desktop_error") as show_error,
                mock.patch("sys.stderr", io.StringIO()),
            ):
                result = kc.main(["--config", str(path), "--open-browser", "--desktop-errors", "tel:123?x=1"])
            self.assertEqual(result, 1)
            show_error.assert_called_once()

    def test_check_registration_is_get_only(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.write_config(directory)
            opener = FakeOpener([
                FakeResponse(200, {"token": "temporary", "clients": [{"id": "empfang-1", "extension": "201"}]}),
            ])
            with (
                mock.patch.object(kc.urllib.request, "build_opener", return_value=opener),
                mock.patch("sys.stdout", io.StringIO()),
            ):
                result = kc.main(["--config", str(path), "--check-registration"])
            self.assertEqual(result, 0)
            self.assertEqual(len(opener.requests), 1)
            self.assertEqual(opener.requests[0][0].get_method(), "GET")

    def test_nonpositive_and_nonfinite_timeouts_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = self.write_config(directory)
            for timeout in ["0", "-1", "nan", "inf"]:
                with self.subTest(timeout=timeout), mock.patch("sys.stderr", io.StringIO()):
                    self.assertEqual(kc.main(["--config", str(path), "--open-monitor", "--timeout", timeout]), 1)


class PlatformConfigTests(unittest.TestCase):
    def test_macos_default_matches_installer(self):
        with mock.patch.object(kc.sys, "platform", "darwin"):
            self.assertEqual(kc.default_config_path(), Path.home() / "Library" / "Application Support" / "KienzlefonT2med" / "client.json")

    def test_missing_registration_fields_are_validation_errors(self):
        with self.assertRaises(kc.ClientError):
            kc.validate_registration("http://middleware.example:8765", None, None)


class InstallerTests(unittest.TestCase):
    def test_shell_installers_have_valid_syntax(self):
        result = subprocess.run(
            ["/bin/sh", "-n", str(REPOSITORY / "clients" / "install-linux.sh"), str(REPOSITORY / "clients" / "install-macos.sh")],
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_linux_staged_install_generates_terminal_free_definitions(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mock_bin = root / "mock bin"
            mock_bin.mkdir()
            log = root / "xdg.log"
            commands = {
                "uname": "#!/bin/sh\nprintf 'Linux\\n'\n",
                "id": "#!/bin/sh\nprintf '501\\n'\n",
                "xdg-mime": "#!/bin/sh\nprintf '%s\\n' \"$*\" >>\"$K_TEST_LOG\"\n",
                "zenity": "#!/bin/sh\nexit 0\n",
            }
            for name, content in commands.items():
                command = mock_bin / name
                command.write_text(content, encoding="utf-8")
                command.chmod(0o755)
            xdg_data = root / 'data $`%" \\ space'
            xdg_config = root / "config space"
            environment = os.environ.copy()
            environment.update(
                {
                    "PATH": str(mock_bin) + os.pathsep + environment["PATH"],
                    "XDG_DATA_HOME": str(xdg_data),
                    "XDG_CONFIG_HOME": str(xdg_config),
                    "K_TEST_LOG": str(log),
                }
            )
            result = subprocess.run(
                [
                    "/bin/sh",
                    str(REPOSITORY / "clients" / "install-linux.sh"),
                    "--url",
                    "http://127.0.0.1:8765",
                    "--client-id",
                    "empfang-1",
                    "--extension",
                    "201",
                ],
                cwd=REPOSITORY,
                env=environment,
                capture_output=True,
                text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            launcher = (xdg_data / "applications" / "kienzlefon-t2med.desktop").read_text(encoding="utf-8")
            handler = (xdg_data / "applications" / "kienzlefon-t2med-url.desktop").read_text(encoding="utf-8")
            self.assertIn("--open-monitor --desktop-errors --config", launcher)
            self.assertIn("NoDisplay=false", launcher)
            self.assertIn("--open-browser --desktop-errors --config", handler)
            self.assertIn("Terminal=false", handler)
            self.assertNotIn("--confirmed", handler)
            # Decode both Desktop Entry string escapes and Exec argument quoting.
            exec_line = next(line[5:] for line in handler.splitlines() if line.startswith('Exec='))
            escapes = {'s': ' ', 'n': '\n', 't': '\t', 'r': '\r', '\\': '\\'}
            exec_line = re.sub(r'\\(.)', lambda m: escapes[m[1]], exec_line)
            args = [re.sub(r'\\(["`$\\])', r'\1', part[1:-1]) if part.startswith('"') else part
                    for part in re.findall(r'"(?:\\.|[^"\\])*"|\S+', exec_line)]
            args = [part.replace('%%', '%') for part in args]
            self.assertEqual(args[1], str(xdg_data / 'kienzlefon-t2med' / 'kienzlefon_call.py'))
            self.assertEqual(args[5], str(xdg_config / 'kienzlefon-t2med' / 'client.json'))
            self.assertEqual(args[-2:], ['--', '%u'])
            self.assertEqual((xdg_config / "kienzlefon-t2med" / "client.json").stat().st_mode & 0o777, 0o600)
            self.assertIn("kienzlefon-t2med-url.desktop x-scheme-handler/tel", log.read_text(encoding="utf-8"))
            update = subprocess.run(
                result.args,
                cwd=REPOSITORY,
                env=environment,
                capture_output=True,
                text=True,
            )
            self.assertEqual(update.returncode, 0, update.stderr)
            handler_path = xdg_data / "applications" / "kienzlefon-t2med-url.desktop"
            handler_path.write_text("foreign content\n", encoding="utf-8")
            refused = subprocess.run(
                result.args,
                cwd=REPOSITORY,
                env=environment,
                capture_output=True,
                text=True,
            )
            self.assertNotEqual(refused.returncode, 0)
            self.assertEqual(handler_path.read_text(encoding="utf-8"), "foreign content\n")

    def test_installers_encode_required_platform_behaviour(self):
        macos = (REPOSITORY / "clients" / "install-macos.sh").read_text(encoding="utf-8")
        windows = (REPOSITORY / "clients" / "install-windows.ps1").read_text(encoding="utf-8")
        self.assertIn("--open-browser", macos)
        self.assertIn("--open-monitor", macos)
        self.assertIn("quoted form of theURL", macos)
        self.assertNotIn("display dialog", macos)
        self.assertIn("pythonw.exe", windows)
        self.assertIn("--open-browser --desktop-errors --config", windows)
        self.assertIn("--open-monitor --desktop-errors --config", windows)
        self.assertNotIn("UserChoice", windows)
        self.assertIn("RegisteredApplications", windows)
        self.assertIn('-- "%1"', windows)

if __name__ == "__main__":
    unittest.main()
