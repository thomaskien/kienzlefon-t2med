"""Exercise the installed Fon/T2med handoff in private directories using synthetic settings."""
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import tomllib
import unittest
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[2]
FON = Path(os.environ.get("KIENZLEFON_SOURCE", ROOT.parent / "kienzlefon"))


class AppCounterCheck(unittest.TestCase):
    def test_freshness_day_and_partial_zero(self):
        now = dt.datetime.now(dt.timezone.utc)
        today = dict(status="partial", reason_code="initial_partial_day", as_of=now.isoformat(), incoming_calls=0,
                     timezone="Europe/Berlin", date=now.astimezone(ZoneInfo("Europe/Berlin")).date().isoformat())
        live = dict(status="current", waiting=0, ivr=0, ai=0, age_ms=0, as_of=now.isoformat())
        with tempfile.TemporaryDirectory() as directory:
            reply = Path(directory) / "reply.json"
            for field, changes, error in [
                ("today", {}, None),
                ("today", {"date": "2000-01-01"}, "Kalendertag"),
                ("today", {"as_of": None}, "AMI-Erfassung"),
                ("today", {"status": "unavailable", "reason_code": "storage_unavailable"}, "storage_unavailable"),
                ("live", {"ai": None}, "Zählerstand"),
                ("live", {"as_of": (now - dt.timedelta(seconds=20)).isoformat()}, "stale"),
            ]:
                with self.subTest(changes=changes):
                    reply.write_text(json.dumps(dict(schema_version=1, source="live", extensions=[{"id":"201"}],
                        live_counters=live | (changes if field == "live" else {}),
                        call_statistics={"today": today | (changes if field == "today" else {})})))
                    result = subprocess.run(["php", ROOT/"installer/check-app-counters.php", reply],
                                            capture_output=True, text=True)
                    self.assertEqual(result.returncode, 0 if error is None else 1)
                    self.assertIn(error or "Teiltag", result.stderr if error else result.stdout)


@unittest.skipUnless((FON / "kienzlefon/t2med_counters.py").is_file(), "Fon-Quellbaum für Paketpaartest erforderlich")
class LocalCounters(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.mw = self.base / "middleware.toml"
        self.token = self.base / "control.token"
        self.fon = self.base / "fon.toml"
        self.identities = self.base / "identities.json"
        self.db = self.base / "stats.sqlite"
        self.mw.write_text((ROOT / "config.example.toml").read_text()
                           .replace("/etc/kienzlefon-t2med/control.token", str(self.token))
                           .replace("/var/lib/kienzlefon-t2med/call-statistics.sqlite", str(self.db)))
        self.env = dict(os.environ, PATH=str(self.base) + os.pathsep + os.environ["PATH"])
        asterisk = self.base / "asterisk"
        asterisk.write_text('''#!/bin/sh
case "$2" in
  'dialplan show kfx-phone-in'|'dialplan show kienzlefon-direct-'*) echo "    2. Gosub(kienzlefon-statistics-entry,s,1($PBX))  [pbx_config]" ;;
  'dialplan show kfx-phone-overflow-in') echo "There is no existence of 'kfx-phone-overflow-in' context" ;;
  'dialplan show kienzlefon-statistics-entry') echo '    2. Set(__KF_STATS_PBX_ID=${ARG1})'
    echo '    8. UserEvent(KienzlefonCallEntered,SchemaVersion: 1,PbxId: ${KF_STATS_PBX_ID},CallId: ${KF_STATS_CALL_ID})' ;;
  'dialplan show kienzlefon-statistics-route') echo '    2. UserEvent(KienzlefonCallRoute,SchemaVersion: 1,PbxId: ${KF_STATS_PBX_ID},Route: ${ARG1})' ;;
  *) exit 1 ;;
esac
''')
        asterisk.chmod(0o700)

    def fon_run(self, action, *args, check=True):
        return subprocess.run([sys.executable, "-m", "kienzlefon.t2med_counters", action,
                               "--config", self.fon, *args], cwd=FON, env=self.env,
                              capture_output=True, text=True, check=check)

    def provision_fon(self, pbx, stats):
        config = (FON / "config/kienzlefon.toml.example").read_text()
        before, after = config.split("[control_api]", 1)
        self.fon.write_text(before.replace('pbx_id = ""', f'pbx_id = "{stats}"') + "[control_api]" +
                            after.replace("enabled = false", "enabled = true", 1)
                            .replace('pbx_id = ""', f'pbx_id = "{pbx}"', 1)
                            .replace("/etc/kienzlefon/control-identities.json", str(self.identities)))
        self.env["PBX"] = pbx
        self.admin = dict(id="admin-lokal", allowed_clients=["anlagenadmin"], token_sha256="a" * 64)
        self.identities.write_text(json.dumps(dict(schema_version=1, identities=[self.admin])))
        self.identities.chmod(0o600)

    def prepare(self, name):
        work = self.base / name
        work.mkdir(mode=0o700)
        candidate = work / "config.toml"
        shutil.copyfile(self.mw, candidate)
        (work / "fon-info.json").write_text(self.fon_run("describe", "--middleware", candidate).stdout)
        subprocess.run(["php", ROOT / "installer/configure.php", "prepare-counters", candidate, work, self.token],
                       capture_output=True, check=True)
        return work

    def install(self, name):
        work = self.prepare(name)
        self.fon_run("plan", "--work", work)
        if (work / "routing.token").exists():
            shutil.copy2(work / "routing.token", self.base / "routing.token")
        if (work / "control.token").exists():
            self.assertFalse(self.token.exists())
            shutil.copy2(work / "control.token", self.token)
        self.fon_run("apply", "--work", work)
        shutil.copyfile(work / "config.toml", self.mw)
        return work

    def check_install(self, pbx):
        old = tomllib.loads(self.mw.read_text())
        old_fon = tomllib.loads(self.fon.read_text())
        self.install("first")
        new = tomllib.loads(self.mw.read_text())
        for section in ("call_statistics", "telephony_control"):
            old[section].update(enabled=True, pbx_id=pbx)
        info = json.loads((self.base / "first/fon-info.json").read_text())
        paired = info.get("routing_pairing_version") == 1
        routing = self.base / "routing.token"
        if paired:
            old["telephony_control"]["routing_token_file"] = str(routing)
        self.assertEqual(old, new)
        old_fon["anrufstatistik"]["pbx_id"] = pbx
        self.assertEqual(old_fon, tomllib.loads(self.fon.read_text()))
        identities = {v["id"]: v for v in json.loads(self.identities.read_text())["identities"]}
        admin, reader = identities["admin-lokal"], identities["t2med-counters"]
        if paired:
            writer = identities["t2med-routing"]
            self.assertIsNone(writer["allowed_clients"])
            self.assertNotEqual(self.token.read_bytes(), routing.read_bytes())
            self.assertEqual(writer["token_sha256"], hashlib.sha256(routing.read_bytes().strip()).hexdigest())
            self.assertEqual((self.base/"first/routing.sha256").stat().st_mode & 0o777, 0o600)
            self.assertEqual(routing.stat().st_mode & 0o777, 0o600)
        self.assertEqual(admin, self.admin)
        self.assertEqual(reader["allowed_clients"], [])
        self.assertEqual(reader["token_sha256"], hashlib.sha256(self.token.read_bytes().strip()).hexdigest())
        self.assertEqual(self.token.stat().st_mode & 0o777, 0o600)
        paths = (self.token, routing, self.identities, self.mw, self.fon, self.db)
        snapshot = [p.read_bytes() if p.exists() else None for p in paths]
        repeat = self.install("repeat")
        self.assertFalse((repeat / "control.token").exists())
        self.assertFalse((repeat / "routing.token").exists())
        self.assertFalse((repeat / "identities.new").exists())
        self.assertFalse((repeat / "statistics.new").exists())
        self.assertEqual(snapshot, [p.read_bytes() if p.exists() else None for p in paths])

    def test_fresh_uses_generated_id_and_repeat_preserves_keys(self):
        pbx = self.fon_run("pbx-id", "--middleware", self.mw).stdout.strip()
        self.assertRegex(pbx, r"^pbx-[0-9a-f]{32}$")
        self.provision_fon(pbx, pbx)
        # Execute the real full-mode Fon setup, starting with its Control listener disabled.
        before, after = self.fon.read_text().split("[control_api]", 1)
        self.fon.write_text(before + "[control_api]" + after.replace("enabled = true", "enabled = false", 1)
                            .replace(f'pbx_id = "{pbx}"', 'pbx_id = ""', 1))
        tools = self.base/"venv"/"bin"
        tools.mkdir(parents=True)
        (tools/"python").symlink_to(sys.executable)
        cli = tools/"kienzlefon-control-setup"
        cli.write_text("""#!/bin/sh
exec "$TEST_PYTHON" -c 'from kienzlefon.control_setup import main; main()' "$@"
""")
        cli.chmod(0o700)
        installer = (FON/"kienzlefon-installer.sh").read_text()
        def function(name):
            return installer.split(name + "(){", 1)[1].split("\n}", 1)[0]
        script = ("set -eu\nlog(){ :; }\ncollect_statistics_configuration(){" +
                  function("collect_statistics_configuration") + "\n}\nconfigure_local_routing(){" +
                  function("configure_local_routing") + "\n}\ncollect_statistics_configuration\nconfigure_local_routing")
        subprocess.run(["bash", "-c", script], cwd=FON, env=dict(self.env, VENV=str(tools.parent),
                       MIT_T2MED="y", FULL_PBX_ID=pbx, CONFIG_FILE=str(self.fon), TEST_PYTHON=sys.executable),
                       text=True, capture_output=True, check=True)
        self.assertTrue(tomllib.loads(self.fon.read_text())["control_api"]["enabled"])
        self.check_install(pbx)

    def test_repair_existing_install_and_repeat_preserve_data(self):
        self.provision_fon("praxis-test", "")
        with sqlite3.connect(self.db) as db:
            db.execute("CREATE TABLE calls(pbx, id)")
            db.execute("INSERT INTO calls VALUES ('praxis-test', 'synthetic')")
        self.check_install("praxis-test")

    def test_update_preserves_155_reader_and_explicit_app_restriction(self):
        self.provision_fon("praxis-test", "praxis-test")
        info = json.loads(self.fon_run("describe", "--middleware", self.mw).stdout)
        if info.pop("routing_pairing_version", None) != 1:
            self.skipTest("Fon mit Rufgruppenkopplung erforderlich")
        old = self.base/"reader-only"
        old.mkdir(mode=0o700)
        (old/"fon-info.json").write_text(json.dumps(info))
        subprocess.run(["php", ROOT/"installer/configure.php", "prepare-counters", self.mw, old, self.token], check=True)
        shutil.copy2(old/"control.token", self.token)
        self.fon_run("plan","--work",old)
        self.fon_run("apply","--work",old)
        self.mw.write_text(self.mw.read_text().replace("allowed_clients = []", 'allowed_clients = ["desk-a"]'))
        reader = self.token.read_bytes()
        previous = json.loads(self.identities.read_text())["identities"]
        self.install("upgrade")
        paired = json.loads(self.identities.read_text())["identities"]
        self.assertTrue(all(item in paired for item in previous))
        self.assertEqual(self.token.read_bytes(), reader)
        self.assertEqual(tomllib.loads(self.mw.read_text())["app_access"]["allowed_clients"], ["desk-a"])
        self.assertFalse(tomllib.loads(self.mw.read_text())["telephony_control"]["legacy_routing"])
        snapshot = [p.read_bytes() for p in (self.mw, self.identities, self.token, self.base/"routing.token")]
        self.install("repeat-upgrade")
        self.assertEqual(snapshot, [p.read_bytes() for p in (self.mw, self.identities, self.token, self.base/"routing.token")])

    def test_legacy_writer_reuses_identity_and_separates_reader(self):
        self.provision_fon("praxis-test", "praxis-test")
        if json.loads(self.fon_run("describe", "--middleware", self.mw).stdout).get("routing_pairing_version") != 1:
            self.skipTest("Fon mit Rufgruppenkopplung erforderlich")
        secret = b"b" * 64
        self.token.write_bytes(secret)
        self.token.chmod(0o600)
        registered = dict(id="existing-writer", allowed_clients=["desk-a"], token_sha256=hashlib.sha256(secret).hexdigest())
        self.identities.write_text(json.dumps(dict(schema_version=1, identities=[self.admin, registered])))
        prefix, ctrl = self.mw.read_text().split("[telephony_control]", 1)
        self.mw.write_text(prefix + "[telephony_control]" + ctrl.replace("enabled = false", "enabled = true", 1)
                           .replace('pbx_id = ""', 'pbx_id = "praxis-test"', 1))
        work = self.prepare("old-writer")
        shell = (ROOT/"installer/install.sh").read_text()
        plan = shell.split("    FON_WORK=$WORK_DIR",1)[1].split('\nfi\nGDT_ROOT=',1)[0]
        script = """set -eu
fon_counters() { "$PYTHON" -m kienzlefon.t2med_counters "$@" --config "$FON_CONFIG"; }
run_configure() { php "$CONFIGURE" "$@"; }
FON_WORK=$WORK_DIR
""" + plan + '\nfon_counters apply --work "$FON_WORK"'
        subprocess.run(["sh","-ec",script],cwd=FON,env=dict(self.env, WORK_DIR=str(work), CONFIG_DIR=str(self.base),
            PYTHON=sys.executable, FON_CONFIG=str(self.fon), PLANNED_CONFIG=str(work/"config.toml"),
            CONFIGURE=str(ROOT/"installer/configure.php")),capture_output=True,check=True)
        updated = tomllib.loads((work/"config.toml").read_text())
        control = updated["telephony_control"]
        self.assertTrue(control["legacy_routing"])
        self.assertEqual(control["routing_token_file"], str(self.token))
        self.assertEqual(control["token_file"], str(self.base/"counters.token"))
        identities = {v["id"]: v for v in json.loads(self.identities.read_text())["identities"]}
        self.assertEqual(identities["existing-writer"], registered)
        self.assertEqual(identities["t2med-counters"]["allowed_clients"], [])
        self.assertNotIn("t2med-routing", identities)
        shutil.copy2(work/"routing.token", self.base/"counters.token")
        shutil.copyfile(work/"config.toml", self.mw)
        self.install("repeat-legacy")
        self.assertEqual(self.token.read_bytes(), secret)
        self.assertEqual(tomllib.loads(self.mw.read_text()), updated)

    def test_older_describe_only_prepares_reader(self):
        self.provision_fon("praxis-test", "")
        work = self.base/"old-fon"
        work.mkdir(mode=0o700)
        info = json.loads(self.fon_run("describe","--middleware",self.mw).stdout)
        info.pop("routing_pairing_version", None)
        (work/"fon-info.json").write_text(json.dumps(info))
        subprocess.run(["php", ROOT/"installer/configure.php","prepare-counters",self.mw,work,self.token],check=True)
        self.assertFalse((work/"routing.sha256").exists())
        self.assertEqual(tomllib.loads(self.mw.read_text())["telephony_control"]["routing_token_file"], "")

    def test_conflicts_dialplan_and_rollback(self):
        self.provision_fon("praxis-test", "")
        before = self.fon.read_bytes(), self.identities.read_bytes()
        self.mw.write_text(self.mw.read_text().replace('pbx_id = ""', 'pbx_id = "other"', 1))
        refusal = self.fon_run("describe", "--middleware", self.mw, check=False)
        self.assertNotEqual(refusal.returncode, 0)
        self.assertIn("Abweichende Anlagenkennungen", refusal.stderr)
        self.mw.write_text(self.mw.read_text().replace('pbx_id = "other"', 'pbx_id = ""', 1))
        self.env["PBX"] = "wrong"
        work = self.prepare("staging")
        refusal = self.fon_run("plan", "--work", work, check=False)
        self.assertNotEqual(refusal.returncode, 0)
        self.assertIn("Wirksamer Dialplan", refusal.stderr)
        self.assertEqual(before, (self.fon.read_bytes(), self.identities.read_bytes()))
        self.env["PBX"] = "praxis-test"
        self.fon_run("plan", "--work", work)
        self.fon_run("apply", "--work", work)
        self.fon_run("rollback", "--work", work)
        self.assertEqual(before, (self.fon.read_bytes(), self.identities.read_bytes()))
