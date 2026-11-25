"""Real Fon 2.6.11 HTTP service and PHP middleware, isolated queue inventory and SQLite."""
import datetime as dt
import hashlib
import json
import os
import secrets
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
import urllib.error
import urllib.request
import uuid
from dataclasses import replace
from pathlib import Path

from test_middleware import AMIServer, wait_for

ROOT = Path(__file__).resolve().parents[2]
FON = Path(os.environ.get("KIENZLEFON_SOURCE", ROOT.parent / "kienzlefon"))
SCOPE = "/api/v1/extension-routing/workplaces"
ROUTING = "/api/v1/extension-routing"


class QueuePlant:
    connected = True
    routing_generation = 0

    def __init__(self):
        self.members = {"normal": {}, "specialist": {}}

    def process_key(self):
        return "test-process"

    def ready(self, value):
        pass

    def inventory(self, _config):
        from kienzlefon.telephony_control import QueueInventory
        return QueueInventory({k: dict(v) for k, v in self.members.items()}, self.routing_generation)

    def change(self, _config, route, extension, penalty):
        if penalty is None:
            del self.members[route][extension]
        else:
            self.members[route][extension] = penalty
        self.routing_generation += 1


@unittest.skipUnless((FON/"kienzlefon/control_api.py").exists(), "Lokaler Fon-Quellbaum erforderlich")
class RoutingPairing(unittest.TestCase):
    def setUp(self):
        sys.path.insert(0, str(FON))
        self.addCleanup(sys.path.remove, str(FON))
        from kienzlefon.config import load_config
        from kienzlefon.telephony_control import Journal
        from kienzlefon.telephony_policy import Membership, RoutingPolicy

        if not hasattr(RoutingPolicy, "permitted_clients"):
            self.skipTest("Fon 2.6.11 erforderlich")
        from kienzlefon.control_api import ControlServer, ControlService, encode_credentials
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.pbx = "praxis-test"
        credentials = self.root/"identities.json"
        self.identities = {}
        self.secrets = {}
        for id_, rights in (("reader", set()), ("writer", None), ("app", None)):
            secret = secrets.token_hex(32)
            self.secrets[id_] = secret
            (self.root/id_).write_text(secret)
            (self.root/id_).chmod(0o600)
            if id_ != "app":
                self.identities[id_] = (hashlib.sha256(secret.encode()).hexdigest(), rights)

        def save_credentials():
            credentials.write_text(encode_credentials(self.identities))
            credentials.chmod(0o600)
        self.save_credentials = save_credentials
        save_credentials()
        template = load_config(FON/"config/kienzlefon.toml.example")
        allow = Membership(True, True, True, True)
        groups = {"201": {"normal": allow, "specialist": Membership()},
                  "202": {"normal": allow, "specialist": allow}}
        config = replace(template, phone_names={"201": "Empfang"}, routing_policy=RoutingPolicy(True, groups),
                         asterisk=replace(template.asterisk, first_queue_extension=201, queue_extension_count=2),
                         control_api=replace(template.control_api, enabled=True, port=0,
                                             pbx_id=self.pbx, credentials_file=credentials))
        self.plant = QueuePlant()
        self.fon = ControlService(config, Journal(self.root/"fon-journal"), self.plant)
        self.tick()
        server = ControlServer(self.fon)
        self.serve(server)
        ami = AMIServer()
        self.serve(ami)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            middleware_port = sock.getsockname()[1]
        self.url = f"http://127.0.0.1:{middleware_port}"
        self.cfg = json.loads((ROOT/"config.example.json").read_text())
        self.cfg["http"].update(listen=f"127.0.0.1:{middleware_port}", public_url=self.url)
        self.cfg["ami"].update(port=ami.server_address[1], secret="mock", username="mock")
        self.cfg["fhir"]["database"] = str(self.root/"patients.sqlite")
        self.cfg["gdt"]["root"] = str(self.root/"gdt")
        self.cfg["clients"] = {}
        self.cfg["telephony"]["extensions"] = {}
        (self.root/"gdt").mkdir()
        self.cfg["telephony_control"].update(enabled=True, pbx_id=self.pbx, token_file=str(self.root/"reader"),
            routing_token_file=str(self.root/"writer"), base_url=f"http://127.0.0.1:{server.server_port}",
            request_database=str(self.root/"mw-journal.sqlite"))
        self.cfg["app_access"].update(enabled=True, token_file=str(self.root/"app"), allowed_clients=[])
        self.log = open(self.root/"middleware.log","w+")
        self.addCleanup(self.log.close)
        self.process = None
        self.addCleanup(self.stop)
        self.start()

    def tick(self):
        self.fon.controller.refresh(self.fon.config)
        self.fon.epoch = self.fon.journal.load()["epoch"]

    def serve(self, server):
        threading.Thread(target=server.serve_forever,daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)

    def stop(self):
        if self.process and self.process.poll() is None:
            self.process.terminate()
            self.process.wait(timeout=5)

    def start(self):
        self.stop()
        path = self.root/"config.json"
        path.write_text(json.dumps(self.cfg))
        self.tick()
        self.process = subprocess.Popen(["php", ROOT/"bin/server.php", "--config="+str(path)],
                                        cwd=ROOT,stdout=self.log,stderr=self.log)
        def ready():
            try:
                return self.api("/api/v1/telephony/overview")[1]["routing_control"]["available"]
            except OSError:
                if self.process.poll() is not None:
                    self.log.seek(0)
                    self.fail(self.log.read())
                return False
        wait_for(ready)

    def api(self, path, body=None, seat=None, pbx=None, auth=True):
        headers = {"Content-Type":"application/json"}
        if auth:
            headers["Authorization"] = "Bearer "+self.secrets["app"]
        if seat:
            headers.update({"X-Kienzlefon-Workplace":seat, "X-Kienzlefon-Pbx-Id":pbx or self.pbx})
        req = urllib.request.Request(self.url+path, headers=headers, data=json.dumps(body).encode() if body else None)
        try:
            with urllib.request.urlopen(req,timeout=5) as resp:
                return resp.status, json.load(resp)
        except urllib.error.HTTPError as resp:
            with resp:
                return resp.code, json.load(resp)

    def command(self, seat, member, route="normal"):
        view = self.api("/api/v1/telephony/overview")[1]["routing_control"]
        return dict(request_id=str(uuid.uuid4()), client_id=seat, extension="201", route=route, member=member,
                    pbx_epoch=view["pbx_epoch"], expected_revision=view["revision"],
                    expires_at=(dt.datetime.now(dt.timezone.utc)+dt.timedelta(seconds=25)).isoformat())

    def test_all_workplaces_directions_and_result_ownership(self):
        a, b = "platz-a", "platz-b"
        self.assertFalse({a,b}.intersection(self.cfg["clients"]))
        self.assertEqual(self.api(SCOPE),(200,dict(schema_version=1,pbx_id=self.pbx,
                                                  remote_enabled=True,mode="all",client_ids=[])))
        self.assertEqual(self.api(SCOPE,auth=False)[0],401)
        ext = next(e for e in self.api("/api/v1/telephony/overview")[1]["extensions"] if e["id"]=="201")
        self.assertEqual(ext["label"],"Empfang")
        self.assertTrue(ext["routing"]["permissions"]["normal"]["can_disable"])
        self.assertFalse(ext["routing"]["permissions"]["specialist"]["can_enable"])
        for seat, member in ((a,False),(b,True),(b,False),(a,True)):
            self.tick()
            command = self.command(seat,member)
            self.assertEqual(self.api(ROUTING,command)[0],403)
            self.assertEqual(self.api(ROUTING,command,seat,"andere-praxis")[0],403)
            other = a if seat == b else b
            self.assertEqual(self.api(ROUTING,command,other)[0],403)
            self.assertEqual(self.api(ROUTING,command,seat)[0],202)
            result_path = ROUTING+"/requests/"+command["request_id"]
            def applied():
                self.tick()
                return self.api(result_path,seat=seat)[1]["request"]["state"] == "applied"
            wait_for(applied,timeout=9)
            self.assertEqual(self.api(result_path,seat=other)[0],403)
            self.assertEqual(self.api(result_path,seat=seat,pbx="andere-praxis")[0],403)
            self.assertIn("202",self.plant.members["normal"])
            self.assertEqual("201" in self.plant.members["normal"],member)
            def caught_up():
                self.tick()
                v=self.api("/api/v1/telephony/overview")[1]
                return next(e for e in v["extensions"] if e["id"]=="201")["routing"]["normal"] == member
            wait_for(caught_up)
        for seat in (a,b):
            for direction in (True,False):
                status, reply = self.api(ROUTING,self.command(seat,direction,"specialist"),seat)
                self.assertEqual(status,403)
                self.assertIn(reply["error"]["code"],("not_eligible","admin_locked"))
        self.fon.config=replace(self.fon.config,routing_policy=replace(self.fon.config.routing_policy,
                                 workplace_mode="selected",workplace_ids=(b,)))
        self.assertEqual(self.api(result_path,seat=a)[0],403)

    def test_selected_empty_revoked_readonly_and_legacy(self):
        a, b = "platz-a", "platz-b"
        self.cfg["app_access"]["allowed_clients"] = [a,b]
        self.start()
        self.assertEqual(self.api(SCOPE)[1]["client_ids"],[a,b])
        for ids in ((a,"fon-only"),()):
            self.fon.config=replace(self.fon.config,routing_policy=replace(self.fon.config.routing_policy,
                                    workplace_mode="selected",workplace_ids=ids))
            scope = self.api(SCOPE)[1]
            self.assertEqual((scope["mode"], scope["client_ids"]), ("selected", [a] if ids else []))
            self.assertEqual(self.api(ROUTING,self.command(b,False),b)[0],403)
            if ids:
                selected = self.command(a,False)
                self.assertEqual(self.api(ROUTING,selected,a)[0],202)
                result = ROUTING+"/requests/"+selected["request_id"]
                self.assertEqual(self.api(result,seat=a)[0],200)
            else:
                self.assertEqual(self.api(result,seat=a)[0],403)
        self.fon.config=replace(self.fon.config,routing_policy=replace(self.fon.config.routing_policy,
                                workplace_mode="all",remote_enabled=False))
        self.assertFalse(self.api(SCOPE)[1]["remote_enabled"])
        self.assertEqual(self.api(ROUTING,self.command(a,False),a)[1]["error"]["code"], "global_disabled")
        self.fon.config=replace(self.fon.config,routing_policy=replace(self.fon.config.routing_policy,remote_enabled=True))
        self.cfg["app_access"].update(workplace_mode="selected",allowed_clients=[])
        self.start()
        self.assertEqual(self.api(SCOPE)[1]["mode"],"selected")
        self.assertEqual(self.api(SCOPE)[1]["client_ids"],[])
        self.assertEqual(self.api(ROUTING,self.command(a,False),a)[0],403)
        self.cfg["app_access"]["workplace_mode"]="fon"
        self.start()
        self.identities["writer"] = self.identities["writer"][0], set()
        self.save_credentials()
        self.assertEqual(self.api(SCOPE)[1]["client_ids"],[])
        self.assertEqual(self.api(ROUTING,self.command(a,False),a)[0],403)
        self.cfg["telephony_control"]["routing_token_file"]=""
        self.start()
        self.assertEqual(self.api(SCOPE)[0],501)
        self.assertEqual(self.api(ROUTING,self.command(a,False),a)[1]["error"]["code"],"unknown_client")
