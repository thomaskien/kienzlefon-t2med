import importlib.util
import json
import os
import tempfile
import unittest
from unittest import mock
import urllib.error
from pathlib import Path


MODULE_PATH = Path(__file__).parents[2] / "clients" / "kienzlefon_call.py"
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

    def test_http_errors_are_not_retried(self):
        error = urllib.error.HTTPError("https://phone.example/api/v1/bootstrap", 503, "down", {}, None)
        error.read = lambda _limit=4096: b"temporarily unavailable"
        opener = FakeOpener([error])
        with self.assertRaisesRegex(kc.ClientError, "HTTP 503"):
            kc.submit_call(self.config, "12345", opener=opener)
        self.assertEqual(len(opener.requests), 1)

    def test_non_202_post_is_rejected_without_retry(self):
        opener = FakeOpener([
            FakeResponse(200, {"token": "x", "clients": [{"id": "empfang-1", "extension": "201"}]}),
            FakeResponse(200, {"status": "ok"}),
        ])
        with self.assertRaisesRegex(kc.ClientError, "erwartet 202"):
            kc.submit_call(self.config, "12345", opener=opener)
        self.assertEqual(len(opener.requests), 2)


class PlatformConfigTests(unittest.TestCase):
    def test_macos_default_matches_installer(self):
        with mock.patch.object(kc.sys, "platform", "darwin"):
            self.assertEqual(kc.default_config_path(), Path.home() / "Library" / "Application Support" / "KienzlefonT2med" / "client.json")

    def test_missing_registration_fields_are_validation_errors(self):
        with self.assertRaises(kc.ClientError):
            kc.validate_registration("http://middleware.example:8765", None, None)

if __name__ == "__main__":
    unittest.main()
