"""Build real macOS applets without registering them or changing defaults."""
import plistlib
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


REPOSITORY = Path(__file__).resolve().parents[2]


@unittest.skipUnless(sys.platform == "darwin", "Requires native macOS applet tools")
class MacOSBundleTests(unittest.TestCase):
    def run_command(self, *args):
        result = subprocess.run(args, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def test_fresh_and_existing_bundle_metadata_are_signed_and_preserved(self):
        with tempfile.TemporaryDirectory(prefix="kienzlefon-bundle-") as directory:
            root = Path(directory)
            source = root / "main.applescript"
            source.write_text("on run\nreturn\nend run\n", encoding="utf-8")
            app = root / "Kienzlefon T2med.app"
            self.run_command("/usr/bin/osacompile", "-o", str(app), str(source))
            plist = app / "Contents" / "Info.plist"
            helper = REPOSITORY / "clients" / "kienzlefon_call.py"
            for existing_identifier in (None, "de.kienzlefon.previous"):
                with self.subTest(existing_identifier=existing_identifier):
                    metadata = plistlib.loads(plist.read_bytes())
                    if existing_identifier is None:
                        metadata.pop("CFBundleIdentifier", None)
                    else:
                        metadata["CFBundleIdentifier"] = existing_identifier
                        metadata["CFBundleURLTypes"] = [{"CFBundleURLSchemes": ["old"]}]
                    metadata["KienzlefonTestPreserve"] = "existing bundle metadata"
                    plist.write_bytes(plistlib.dumps(metadata))
                    self.run_command(
                        "/bin/sh", "-c", '. "$1"; prepare_macos_bundle "$2" "$3"',
                        "test-bundle", str(REPOSITORY / "clients" / "macos-bundle.sh"),
                        str(app), str(helper),
                    )
                    actual = plistlib.loads(plist.read_bytes())
                    self.assertEqual(actual["CFBundleIdentifier"], "de.kienzlefon.t2med")
                    self.assertEqual(actual["KienzlefonTestPreserve"], "existing bundle metadata")
                    self.assertEqual(actual["CFBundleExecutable"], metadata["CFBundleExecutable"])
                    self.assertEqual(actual["CFBundleURLTypes"], [{
                        "CFBundleURLName": "de.kienzlefon.t2med.call",
                        "CFBundleURLSchemes": ["tel", "callto"],
                    }])
                    copied_helper = app / "Contents" / "Resources" / "kienzlefon_call.py"
                    self.assertEqual(copied_helper.read_bytes(), helper.read_bytes())
                    self.run_command("/usr/bin/codesign", "--verify", "--strict", str(app))
                    signature = self.run_command("/usr/bin/codesign", "--display", "--verbose=2", str(app))
                    self.assertIn("Identifier=de.kienzlefon.t2med", signature.stderr)
                    # A subsequent changed resource must invalidate the checked signature.
                    copied_helper.write_text("# changed after signing\n", encoding="utf-8")
                    changed = subprocess.run(["/usr/bin/codesign", "--verify", "--strict", str(app)],
                                             capture_output=True, text=True, timeout=30)
                    self.assertNotEqual(changed.returncode, 0)


if __name__ == "__main__":
    unittest.main()
