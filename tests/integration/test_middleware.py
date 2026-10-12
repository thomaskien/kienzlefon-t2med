"""End-to-end contract tests. Uses only loopback mock AMI/APS and temporary GDT files."""
import base64
import http.server
import json
import os
from pathlib import Path
import shutil
import shlex
import socket
import socketserver
import ssl
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]


def wait_for(fn, timeout=6):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = fn()
        if value:
            return value
        time.sleep(0.03)
    raise AssertionError('Condition did not become true')


class APS(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        self.server.headers_seen.append(self.headers.get('Authorization'))
        payload = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        self.server.requests.append(payload)
        number = payload['suchmaske']
        if number.endswith('99'):
            time.sleep(1.4)
        rows = [{'nummer': 90001, 'vorname': 'Test', 'nachname': 'Patient', 'geburtsdatum': '19800101', 'adresse': 'Musterweg', 'ref': {'objectId': {'id': 'test-1'}, 'revision': 1}}]
        if number.endswith('22'):
            rows.append({**rows[0], 'nummer': 90002, 'ref': {'objectId': {'id': 'test-2'}, 'revision': 1}})
        if number.endswith('00'):
            rows = []
        # These fixtures model APS entries stored in only one spelling.
        if number.endswith('73') and not number.startswith('0176'):
            rows = []
        if number.endswith('74') and not number.startswith('49176'):
            rows = []
        body = json.dumps(rows).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


class AMIHandler(socketserver.BaseRequestHandler):
    def handle(self):
        self.server.connection = self.request
        self.server.send_raw(b'Asterisk Call Manager/5.0\r\n')
        buffer = b''
        try:
            while True:
                data = self.request.recv(8192)
                if not data:
                    break
                buffer += data
                while b'\r\n\r\n' in buffer:
                    raw, buffer = buffer.split(b'\r\n\r\n', 1)
                    action = {}
                    for line in raw.decode().split('\r\n'):
                        key, value = line.split(':', 1)
                        action[key] = value.strip()
                    self.server.actions.append(action)
                    aid = action['ActionID']
                    reply = {'Response': 'Success', 'ActionID': aid}
                    if action['Action'] == 'Getvar':
                        variable = action.get('Variable', '')
                        reply['Value'] = self.server.variables.get(variable, '1' if variable.startswith('DIALPLAN_EXISTS(') else '')
                    self.server.send(reply)
                    if action['Action'] == 'CoreShowChannels':
                        if self.server.snapshot_race:
                            self.server.send({'Event': 'Hangup', 'Uniqueid': 'gone', 'Channel': 'PJSIP/trunk-gone'})
                            self.server.send({'Event': 'CoreShowChannel', 'ActionID': aid, 'Uniqueid': 'gone', 'Linkedid': 'gone', 'Channel': 'PJSIP/trunk-gone', 'ChannelStateDesc': 'Ring', 'CallerIDNum': '03055503333'})
                        self.server.send({'Event': 'CoreShowChannelsComplete', 'ActionID': aid})
                    elif action['Action'] == 'QueueStatus':
                        self.server.send({'Event': 'QueueParams', 'ActionID': aid, 'Queue': 'test-praxis', 'Calls': '0'})
                        self.server.send({'Event': 'QueueStatusComplete', 'ActionID': aid})
        except (OSError, ValueError):
            pass


class AMIServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self):
        super().__init__(('127.0.0.1', 0), AMIHandler)
        self.actions = []
        self.lock = threading.Lock()
        self.connection = None
        self.snapshot_race = False
        self.variables = {'KF_TRANSFER_READY': '1', 'FEATUREMAP(atxfer)': '*2', 'FEATURE(atxferabort)': '*3', 'FEATURE(atxfercomplete)': '*4'}

    def send_raw(self, data):
        with self.lock:
            try:
                self.connection.sendall(data)
            except (OSError, AttributeError):
                pass

    def send(self, fields):
        self.send_raw((''.join(f'{k}: {v}\r\n' for k, v in fields.items()) + '\r\n').encode())


class MiddlewareIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='kf-integration-')
        root = Path(cls.temp.name)
        cls.share = root / 'gdt'
        (cls.share / 'empfang-1').mkdir(parents=True)
        (cls.share / 'empfang-2').mkdir()
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(root / 'key.pem'), '-out', str(root / 'cert.pem'), '-days', '1', '-subj', '/CN=localhost'], check=True, capture_output=True)
        cls.aps = http.server.ThreadingHTTPServer(('127.0.0.1', 0), APS)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(root / 'cert.pem', root / 'key.pem')
        cls.aps.socket = context.wrap_socket(cls.aps.socket, server_side=True)
        cls.aps.requests, cls.aps.headers_seen = [], []
        cls.ami = AMIServer()
        for server in (cls.aps, cls.ami):
            threading.Thread(target=server.serve_forever, daemon=True).start()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.url = f'http://127.0.0.1:{port}'
        config = json.loads((ROOT / 'config.example.json').read_text())
        config['http']['listen'] = f'127.0.0.1:{port}'
        config['http']['public_url'] = cls.url
        config['ami'].update(host='127.0.0.1', port=cls.ami.server_address[1], username='mock', secret='mock-secret')
        config['aps'].update(url=f'https://127.0.0.1:{cls.aps.server_port}', username='test', password='', tls_verify=False)
        config['gdt']['root'] = str(cls.share)
        config['clients']['empfang-2'] = {'label': 'Empfang 2', 'extension': '202', 'directory': 'empfang-2'}
        cls.numeric_clients = {'201': '301', '0': '302', '001': '303', '1': '304'}
        for client_id, extension in cls.numeric_clients.items():
            (cls.share / client_id).mkdir()
            config['clients'][client_id] = {'label': 'Arbeitsplatz ' + client_id, 'extension': extension, 'directory': client_id}
            config['telephony']['extensions'][extension] = 'Arbeitsplatz ' + client_id
        path = root / 'config.toml'
        encoded = subprocess.run(['php', '-r', 'require "vendor/autoload.php"; echo Kienzlefon\\Config::encodeToml(json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR));'], cwd=ROOT, input=json.dumps(config), text=True, capture_output=True, check=True)
        path.write_text(encoded.stdout)
        os.chmod(path, 0o600)
        cls.log = open(root / 'server.log', 'w+')
        # Exercise the installed PHP invocation, including its service-specific INI settings.
        unit = (ROOT / 'installer/kienzlefon-t2med.service').read_text()
        launch = shlex.split(next(line.removeprefix('ExecStart=') for line in unit.splitlines() if line.startswith('ExecStart=')))
        launch[0] = shutil.which('php')
        launch = [arg.replace('/opt/kienzlefon-t2med', str(ROOT)).replace('/etc/kienzlefon-t2med/config.toml', str(path)) for arg in launch]
        cls.process = subprocess.Popen(launch, cwd=ROOT, stdout=cls.log, stderr=cls.log)
        def ready():
            try:
                with urllib.request.urlopen(cls.url + '/api/v1/bootstrap', timeout=1) as response:
                    cls.token = json.load(response)['token']
                return True
            except (OSError, urllib.error.URLError):
                if cls.process.poll() is not None:
                    cls.log.seek(0)
                    raise AssertionError(cls.log.read())
                return False
        wait_for(ready)
        wait_for(lambda: not cls.request('/api/v1/telephony')[1]['stale'])

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.log.close()
        cls.ami.shutdown()
        cls.aps.shutdown()
        cls.ami.server_close()
        cls.aps.server_close()
        cls.temp.cleanup()

    @classmethod
    def request(cls, path, payload=None, headers=None):
        h = {'X-Kienzlefon-Token': cls.token, 'Content-Type': 'application/json'}
        h.update(headers or {})
        req = urllib.request.Request(cls.url + path, data=json.dumps(payload).encode() if payload is not None else None, headers=h)
        try:
            with urllib.request.urlopen(req, timeout=8) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            with error:
                return error.code, json.load(error)

    def lookup(self, number):
        status, result = self.request('/api/v1/lookup', {'number': number})
        self.assertEqual(status, 200)
        return result

    def test_12_numeric_client_ids_keep_workplace_mapping(self):
        status, bootstrap = self.request('/api/v1/bootstrap')
        self.assertEqual(status, 200)
        self.assertTrue(all(isinstance(c['id'], str) for c in bootstrap['clients']))
        clients = {c['id']: c['extension'] for c in bootstrap['clients']}
        lookup = self.lookup('03055501022')
        for client_id, extension in self.numeric_clients.items():
            with self.subTest(client_id=client_id):
                self.assertEqual(clients[client_id], extension)
                request = {'client_id': client_id, 'lookup_id': lookup['lookup_id'], 'patient_number': 90001, 'request_id': str(uuid.uuid4())}
                self.assertEqual(self.request('/api/v1/patient-open', request)[0], 200)
                target = self.share / client_id / 'AUFRUFi.gdt'
                self.assertEqual(list(self.share.rglob('*.gdt')), [target])
                self.assertIn(b'014300090001\r\n', target.read_bytes())
                target.unlink()
                call = {'client_id': client_id, 'extension': extension, 'number': '03055508888', 'request_id': str(uuid.uuid4())}
                self.assertEqual(self.request('/api/v1/calls', {**call, 'extension': '999'})[0], 400)
                status, result = self.request('/api/v1/calls', call)
                self.assertEqual(status, 202)
                self.assertEqual(result['client_id'], client_id)
                action = next(a for a in self.ami.actions if a.get('ChannelId') == 'kf-' + call['request_id'])
                self.assertEqual(action['Channel'], 'PJSIP/' + extension)
                self.ami.send({'Event': 'OriginateResponse', 'ActionID': action['ActionID'], 'Response': 'Failure'})
        for invalid_id in [201, 0, True, None, [], {}, '999', '../201']:
            self.assertEqual(self.request('/api/v1/patient-open', {**request, 'client_id': invalid_id, 'request_id': str(uuid.uuid4())})[0], 400)
            self.assertEqual(self.request('/api/v1/calls', {**call, 'client_id': invalid_id, 'request_id': str(uuid.uuid4())})[0], 400)
        self.assertEqual(list(self.share.rglob('*.gdt')), [])

    def test_01_aps_auth_empty_password_and_multiple_candidates(self):
        result = self.lookup('03055501022')
        self.assertEqual(result['status'], 'ambiguous')
        self.assertEqual([p['patient_number'] for p in result['patients']], [90001, 90002])
        self.assertTrue(all(h == 'Basic ' + base64.b64encode(b'test:').decode() for h in self.aps.headers_seen))
        self.assertTrue(all(p['ignoreSichtbarkeit'] is False for p in self.aps.requests))
        self.assertTrue(all('_ref' not in p for p in result['patients']))

    def test_02_gdt_selection_replacement_and_idempotency(self):
        lookup = self.lookup('03055501022')
        request = {'client_id': 'empfang-1', 'lookup_id': lookup['lookup_id'], 'patient_number': 90002, 'request_id': str(uuid.uuid4())}
        target = self.share / 'empfang-1' / 'AUFRUFi.gdt'
        status, result = self.request('/api/v1/patient-open', request)
        self.assertEqual(status, 200)
        self.assertEqual(result['status'], 'sent')
        data = target.read_bytes()
        self.assertIn(b'014300090002\r\n', data)
        self.assertNotIn(b'Patient', data)
        for line in data.splitlines(keepends=True):
            self.assertEqual(int(line[:3]), len(line))

        newer = {**request, 'patient_number': 90001, 'request_id': str(uuid.uuid4())}
        self.assertEqual(self.request('/api/v1/patient-open', newer)[0], 200)
        newer_data = target.read_bytes()
        self.assertIn(b'014300090001\r\n', newer_data)
        self.assertNotIn(b'014300090002\r\n', newer_data)

        self.assertEqual(self.request('/api/v1/patient-open', request), (status, result))
        self.assertEqual(target.read_bytes(), newer_data)
        target.unlink()  # Simulated consumption by T2med.
        after_consumption = {**request, 'request_id': str(uuid.uuid4())}
        self.assertEqual(self.request('/api/v1/patient-open', after_consumption)[0], 200)
        self.assertIn(b'014300090002\r\n', target.read_bytes())
        target.unlink()

        self.assertEqual(self.request('/api/v1/patient-open', {**request, 'client_id': 'empfang-2', 'request_id': str(uuid.uuid4())})[0], 200)
        (self.share / 'empfang-2' / 'AUFRUFi.gdt').unlink()
        self.assertEqual(self.request('/api/v1/patient-open', {**request, 'patient_number': 99999, 'request_id': str(uuid.uuid4())})[0], 400)
        self.assertEqual(self.request('/api/v1/patient-open', {**request, 'client_id': '../other'})[0], 400)

    def test_02_number_spellings_find_national_and_bare_international_aps_entries(self):
        for suffix in ['73', '74']:
            for initial in range(4):
                national = f'0176555{initial}0{suffix}'
                international = '49' + national[1:]
                variants = [national, '+' + international, '00' + international, international]
                start = len(self.aps.requests)
                first = self.lookup(variants[initial])
                self.assertEqual(first['status'], 'resolved')
                self.assertEqual([p['patient_number'] for p in first['patients']], [90001])
                self.assertEqual({q['suchmaske'] for q in self.aps.requests[start:]}, set(variants))
                after_first = len(self.aps.requests)
                for number in variants:
                    result = self.lookup(number)
                    self.assertEqual(result['lookup_id'], first['lookup_id'])
                    self.assertEqual(result['patients'], first['patients'])
                self.assertEqual(len(self.aps.requests), after_first)

    def test_02_version_is_available_without_patient_lookup(self):
        self.assertEqual(self.request('/api/v1/bootstrap')[1]['version'], '1.3.6')
        self.assertEqual(self.request('/api/v1/bootstrap')[1]['author'], 'Dr. Thomas Kienzle')
        self.assertEqual(self.request('/healthz')[1]['version'], '1.3.6')

    def test_03_http_controls_and_read_only_link(self):
        self.assertEqual(self.request('/api/v1/lookup', {'number': '03055501111'}, {'Origin': 'https://foreign.example'})[0], 403)
        self.assertEqual(self.request('/api/v1/calls', {}, {'X-Kienzlefon-Token': ''})[0], 403)
        self.assertEqual(self.request('/api/v1/bootstrap', headers={'Host': 'foreign.example'})[0], 403)
        with urllib.request.urlopen(self.url + '/patient-oeffnen?client_id=empfang-1&telefon=03055501111') as response:
            self.assertEqual(response.status, 200)
        self.assertEqual(list(self.share.rglob('*.gdt')), [])
        self.assertEqual(self.lookup('03055501000')['status'], 'not_found')

    def test_04_ami_lookup_commit_and_late_result(self):
        def emit(uid, number):
            self.ami.send({'Event': 'Newchannel', 'Uniqueid': uid, 'Linkedid': uid, 'Channel': f'PJSIP/trunk-{uid}', 'CallerIDNum': number, 'ChannelStateDesc': 'Ring'})
            self.ami.send({'Event': 'UserEvent', 'UserEvent': 'KienzlefonLookupRequest', 'SchemaVersion': '1', 'RequestId': 'lookup-' + uid, 'SourceChannel': f'PJSIP/trunk-{uid}', 'SourceUniqueid': uid, 'SourceLinkedid': uid, 'Number': number, 'BudgetMs': '1000'})
        emit('fast', '03055501011')
        actions = wait_for(lambda: [a for a in self.ami.actions if a.get('Channel') == 'PJSIP/trunk-fast' and a.get('Variable') == 'LOOKUP_DONE'])
        variables = [a for a in self.ami.actions if a.get('Channel') == 'PJSIP/trunk-fast' and a['Action'] == 'Setvar']
        self.assertEqual([a['Variable'] for a in variables], ['LOOKUP_RESULT_ID', 'LOOKUP_NAME', 'LOOKUP_STATUS', 'LOOKUP_DONE'])
        self.assertEqual(variables[1]['Value'], 'Test Patient')
        emit('ambiguous', '03055501022')
        wait_for(lambda: any(a.get('Channel') == 'PJSIP/trunk-ambiguous' and a.get('Variable') == 'LOOKUP_DONE' for a in self.ami.actions))
        self.assertEqual(next(a['Value'] for a in self.ami.actions if a.get('Channel') == 'PJSIP/trunk-ambiguous' and a.get('Variable') == 'LOOKUP_NAME'), '')
        emit('late', '03055501099')
        start = time.monotonic()
        self.assertEqual(self.request('/api/v1/telephony')[0], 200)
        self.assertLess(time.monotonic() - start, 0.5)
        time.sleep(1.8)
        self.assertFalse(any(a['Action'] == 'Setvar' and a.get('Channel') == 'PJSIP/trunk-late' for a in self.ami.actions))
        self.assertFalse(any(a.get('Variable', '').startswith('CALLERID') for a in self.ami.actions))
        for uid in ['fast', 'ambiguous', 'late']:
            self.ami.send({'Event': 'Hangup', 'Uniqueid': uid, 'Channel': 'PJSIP/trunk-' + uid})

    def test_05_originate_requires_second_leg_and_bridge(self):
        request = {'client_id': 'empfang-1', 'extension': '201', 'number': '03055508888', 'request_id': str(uuid.uuid4())}
        self.assertEqual(self.request('/api/v1/calls', {**request, 'extension': '202'})[0], 400)
        status, call = self.request('/api/v1/calls', request)
        self.assertEqual(status, 202)
        self.assertEqual(call['phase'], 'ringing_extension')
        self.assertEqual(self.request('/api/v1/calls', request)[1]['request_id'], request['request_id'])
        actions = [a for a in self.ami.actions if a['Action'] == 'Originate']
        self.assertEqual(len(actions), 1)
        self.assertEqual(actions[0]['Context'], 'kienzlefon-click-to-dial')
        self.assertEqual(actions[0]['CallerID'], '03055508888 <03055508888>')
        source = actions[0]['ChannelId']
        self.ami.send({'Event': 'OriginateResponse', 'ActionID': actions[0]['ActionID'], 'Response': 'Success', 'Uniqueid': source})
        def phase():
            return self.request('/api/v1/telephony')[1]['outgoing'][0]['phase']
        wait_for(lambda: phase() == 'dialing_target')
        self.ami.send({'Event': 'Newchannel', 'Uniqueid': source, 'Linkedid': source, 'Channel': 'PJSIP/201-0001', 'ChannelStateDesc': 'Up'})
        self.ami.send({'Event': 'DialBegin', 'Uniqueid': source, 'DestUniqueid': 'target', 'DestChannel': 'PJSIP/trunk-0002', 'Linkedid': source})
        wait_for(lambda: phase() == 'ringing_target')
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': source, 'BridgeUniqueid': 'callbridge', 'Channel': 'PJSIP/201-0001', 'ChannelStateDesc': 'Up'})
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': 'target', 'BridgeUniqueid': 'callbridge', 'Channel': 'PJSIP/trunk-0002', 'ChannelStateDesc': 'Ring'})
        time.sleep(0.1)
        self.assertNotEqual(phase(), 'connected')
        self.ami.send({'Event': 'Newstate', 'Uniqueid': 'target', 'Channel': 'PJSIP/trunk-0002', 'ChannelStateDesc': 'Up'})
        wait_for(lambda: phase() == 'connected')
        self.ami.send({'Event': 'Hangup', 'Uniqueid': 'target', 'Channel': 'PJSIP/trunk-0002'})
        wait_for(lambda: phase() == 'ended')
        self.ami.send({'Event': 'Hangup', 'Uniqueid': source, 'Channel': 'PJSIP/201-0001'})

    def test_06_disconnect_reconnect_snapshot_race(self):
        self.ami.snapshot_race = True
        self.ami.connection.shutdown(socket.SHUT_RDWR)
        wait_for(lambda: self.request('/api/v1/telephony')[1]['stale'])
        self.assertEqual(self.request('/api/v1/telephony')[1]['calls'], [])
        wait_for(lambda: not self.request('/api/v1/telephony')[1]['stale'])
        self.assertEqual(self.request('/api/v1/telephony')[1]['calls'], [])

    def test_07_originate_through_local_channels_tracks_actual_target(self):
        request = {'client_id': 'empfang-2', 'extension': '202', 'number': '03055507777', 'request_id': str(uuid.uuid4())}
        status, result = self.request('/api/v1/calls', request)
        self.assertEqual(status, 202)
        source = 'kf-' + request['request_id']
        self.ami.send({'Event': 'Newchannel', 'Uniqueid': source, 'Linkedid': source, 'Channel': 'PJSIP/202-0001', 'ChannelStateDesc': 'Up'})
        self.ami.send({'Event': 'DialBegin', 'Uniqueid': source, 'Linkedid': source, 'DestUniqueid': 'local1', 'DestChannel': 'Local/out@route-0001;1'})
        def phase():
            return next(c['phase'] for c in self.request('/api/v1/telephony')[1]['outgoing'] if c['request_id'] == request['request_id'])
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': source, 'BridgeUniqueid': 'local-b1', 'Channel': 'PJSIP/202-0001', 'ChannelStateDesc': 'Up'})
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': 'local1', 'BridgeUniqueid': 'local-b1', 'Channel': 'Local/out@route-0001;1', 'ChannelStateDesc': 'Up'})
        wait_for(lambda: phase() == 'ringing_target')
        self.assertNotEqual(phase(), 'connected')
        self.ami.send({'Event': 'Newchannel', 'Uniqueid': 'local2', 'Linkedid': source, 'Channel': 'Local/out@route-0001;2', 'ChannelStateDesc': 'Up'})
        self.ami.send({'Event': 'DialBegin', 'Uniqueid': 'local2', 'Linkedid': source, 'DestUniqueid': 'provider', 'DestChannel': 'PJSIP/trunk-0099'})
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': 'local2', 'BridgeUniqueid': 'local-b2', 'Channel': 'Local/out@route-0001;2', 'ChannelStateDesc': 'Up'})
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': 'provider', 'BridgeUniqueid': 'local-b2', 'Channel': 'PJSIP/trunk-0099', 'ChannelStateDesc': 'Ring'})
        time.sleep(0.1)
        self.assertNotEqual(phase(), 'connected')
        self.ami.send({'Event': 'Newstate', 'Uniqueid': 'provider', 'Channel': 'PJSIP/trunk-0099', 'ChannelStateDesc': 'Up'})
        wait_for(lambda: phase() == 'connected')
        self.ami.send({'Event': 'Hangup', 'Uniqueid': 'provider', 'Channel': 'PJSIP/trunk-0099'})
        wait_for(lambda: phase() == 'ended')
        for uid in [source, 'local1', 'local2']:
            self.ami.send({'Event': 'Hangup', 'Uniqueid': uid})

    def test_08_empty_queue_and_live_waiting_patient_candidates(self):
        def queue_view():
            view = self.request('/api/v1/telephony')[1]
            queue = next(q for q in view['queues'] if q['name'] == 'test-praxis')
            return view, queue
        self.assertEqual(queue_view()[1], {'name': 'test-praxis', 'waiting_count': 0, 'entries': []})
        self.ami.send({'Event': 'QueueCallerJoin', 'Queue': 'test-praxis', 'Uniqueid': 'queue-test', 'Linkedid': 'queue-test', 'Channel': 'PJSIP/trunk-queue-test', 'CallerIDNum': '03055501022', 'Position': '1'})
        wait_for(lambda: queue_view()[1]['waiting_count'] == 1)
        def candidates_ready():
            view, queue = queue_view()
            return next((c for c in view['calls'] if c['id'] == queue['entries'][0]['call_id'] and c['name_status'] == 'ambiguous'), None)
        call = wait_for(candidates_ready)
        self.assertEqual(call['id'], 'queue-test')
        self.assertEqual(call['state'], 'waiting')
        self.assertEqual([p['patient_number'] for p in call['patients']], [90001, 90002])
        self.ami.send({'Event': 'QueueCallerLeave', 'Queue': 'test-praxis', 'Uniqueid': 'queue-test', 'Channel': 'PJSIP/trunk-queue-test'})
        wait_for(lambda: queue_view()[1]['waiting_count'] == 0)
        self.assertEqual(queue_view()[1]['entries'], [])

    def transfer_pair(self, prefix):
        source, peer = prefix + '-staff', prefix + '-peer'
        for uid, channel in [(peer, 'PJSIP/trunk-' + prefix), (source, 'PJSIP/201-00ab')]:
            self.ami.send({'Event': 'Newchannel', 'Uniqueid': uid, 'Linkedid': peer, 'Channel': channel, 'ChannelStateDesc': 'Up', 'CallerIDNum': '03055508888'})
            self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': uid, 'BridgeUniqueid': prefix + '-original', 'Channel': channel, 'ChannelStateDesc': 'Up'})
        wait_for(lambda: any(c['id'] == peer and c['transfer_extensions'] == ['201'] for c in self.request('/api/v1/telephony')[1]['calls']))
        return source, peer

    def transfer_job(self, request_id):
        return next(j for j in self.request('/api/v1/telephony')[1]['transfers'] if j['request_id'] == request_id)

    def test_09_direct_transfer_is_bound_to_own_call_and_idempotent(self):
        source, peer = self.transfer_pair('blind')
        request = {'client_id': 'empfang-1', 'call_id': peer, 'target_type': 'internal', 'target': '202', 'mode': 'blind', 'request_id': str(uuid.uuid4())}
        self.assertEqual(self.request('/api/v1/transfers', {**request, 'client_id': 'empfang-2', 'target': '201'})[0], 503)
        self.assertEqual(self.request('/api/v1/transfers', request, {'Origin': 'https://foreign.example'})[0], 403)
        start = len(self.ami.actions)
        status, job = self.request('/api/v1/transfers', request)
        self.assertEqual(status, 202)
        self.assertEqual(job['phase'], 'transferred')
        actions = self.ami.actions[start:]
        native = [a for a in actions if a['Action'] == 'BlindTransfer']
        self.assertEqual(len(native), 1)
        self.assertEqual((native[0]['Channel'], native[0]['Context'], native[0]['Exten']), ('PJSIP/201-00ab', 'kienzlefon-transfer', '202'))
        metadata = [a for a in actions if a['Action'] == 'Setvar' and a['Variable'] == '__KF_TRANSFER_SOURCE_UID']
        self.assertEqual({a['Channel'] for a in metadata}, {'PJSIP/201-00ab', 'PJSIP/trunk-blind'})
        self.assertEqual({a['Value'] for a in metadata}, {source})
        self.assertFalse(any(k.startswith('_') for k in job))
        self.assertEqual(self.request('/api/v1/transfers', request)[1], job)
        self.assertEqual(len([a for a in self.ami.actions[start:] if a['Action'] == 'BlindTransfer']), 1)
        self.assertEqual(self.request('/api/v1/transfers', {**request, 'target': '201'})[0], 400)
        for uid in [source, peer]: self.ami.send({'Event': 'Hangup', 'Uniqueid': uid})

    def test_10_external_consultation_completion_waits_for_native_event(self):
        source, peer = self.transfer_pair('attended')
        request = {'client_id': 'empfang-1', 'call_id': peer, 'target_type': 'external', 'target': '+493055501111', 'mode': 'attended', 'request_id': str(uuid.uuid4())}
        status, job = self.request('/api/v1/transfers', request)
        self.assertEqual((status, job['phase']), (202, 'requested'))
        action = next(a for a in reversed(self.ami.actions) if a['Action'] == 'Atxfer')
        self.assertEqual(action['Exten'], '00493055501111')
        control = {'client_id': 'empfang-1', 'request_id': str(uuid.uuid4())}
        endpoint = '/api/v1/transfers/' + request['request_id'] + '/complete'
        self.assertEqual(self.request(endpoint, control)[0], 503)
        self.ami.send({'Event': 'UserEvent', 'UserEvent': 'KienzlefonTransferProgress', 'SchemaVersion': '1', 'RequestId': request['request_id'], 'TransfererUniqueid': source, 'SourceUniqueid': 'attended-route', 'Phase': 'dialing_target'})
        self.ami.send({'Event': 'BridgeLeave', 'Uniqueid': source})
        self.ami.send({'Event': 'DialBegin', 'Uniqueid': 'attended-route', 'DestUniqueid': 'attended-target', 'DestChannel': 'PJSIP/transfer-provider-00bb', 'Linkedid': peer})
        wait_for(lambda: self.transfer_job(request['request_id'])['can_cancel'])
        self.assertFalse(self.transfer_job(request['request_id'])['can_complete'])
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': source, 'BridgeUniqueid': 'attended-consult', 'ChannelStateDesc': 'Up'})
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': 'attended-target', 'BridgeUniqueid': 'attended-consult', 'ChannelStateDesc': 'Ringing'})
        self.assertFalse(self.transfer_job(request['request_id'])['can_complete'])
        self.ami.send({'Event': 'Newstate', 'Uniqueid': 'attended-target', 'ChannelStateDesc': 'Up'})
        wait_for(lambda: self.transfer_job(request['request_id'])['can_complete'])
        start = len(self.ami.actions)
        self.assertEqual(self.request(endpoint, control)[1]['phase'], 'completing')
        self.request(endpoint, control)
        digits = [a for a in self.ami.actions[start:] if a['Action'] == 'PlayDTMF']
        self.assertEqual([a['Digit'] for a in digits], ['*', '4'])
        self.assertTrue(all(a['Receive'] == 'true' and a['Channel'] == 'PJSIP/201-00ab' for a in digits))
        self.assertFalse(any(a['Action'] == 'Hangup' for a in self.ami.actions[start:]))
        self.ami.send({'Event': 'AttendedTransfer', 'Result': 'Success', 'OrigTransfererUniqueid': source, 'TransfereeUniqueid': peer, 'DestType': 'Bridge'})
        wait_for(lambda: self.transfer_job(request['request_id'])['phase'] == 'transferred')
        for uid in [source, peer, 'attended-target']: self.ami.send({'Event': 'Hangup', 'Uniqueid': uid})

    def test_11_consultation_cancel_requires_returned_original_bridge(self):
        source, peer = self.transfer_pair('cancel')
        request = {'client_id': 'empfang-1', 'call_id': peer, 'target_type': 'internal', 'target': '202', 'mode': 'attended', 'request_id': str(uuid.uuid4())}
        self.request('/api/v1/transfers', request)
        self.ami.send({'Event': 'UserEvent', 'UserEvent': 'KienzlefonTransferProgress', 'SchemaVersion': '1', 'RequestId': request['request_id'], 'TransfererUniqueid': source, 'SourceUniqueid': 'cancel-route', 'Phase': 'dialing_target'})
        self.ami.send({'Event': 'BridgeLeave', 'Uniqueid': source})
        wait_for(lambda: self.transfer_job(request['request_id'])['can_cancel'])
        endpoint = '/api/v1/transfers/' + request['request_id'] + '/cancel'
        control = {'client_id': 'empfang-1', 'request_id': str(uuid.uuid4())}
        start = len(self.ami.actions)
        self.assertEqual(self.request(endpoint, control)[1]['phase'], 'cancelling')
        self.request(endpoint, control)
        self.assertEqual(len([a for a in self.ami.actions[start:] if a['Action'] == 'CancelAtxfer']), 1)
        self.ami.send({'Event': 'BridgeEnter', 'Uniqueid': source, 'BridgeUniqueid': 'cancel-original', 'ChannelStateDesc': 'Up'})
        wait_for(lambda: self.transfer_job(request['request_id'])['phase'] == 'cancelled')
        for uid in [source, peer]: self.ami.send({'Event': 'Hangup', 'Uniqueid': uid})


    def test_13_callback_retry_dismiss_cleanup_are_scoped_and_idempotent(self):
        def outgoing():
            return {c['request_id']: c for c in self.request('/api/v1/telephony')[1]['outgoing']}

        def start_failed(client, extension):
            payload = {'client_id': client, 'extension': extension, 'number': '03055508888', 'request_id': str(uuid.uuid4())}
            self.assertEqual(self.request('/api/v1/calls', payload)[0], 202)
            self.ami.send({'Event': 'OriginateResponse', 'ActionID': 'kf-c2d-' + payload['request_id'], 'Response': 'Failure'})
            wait_for(lambda: outgoing()[payload['request_id']]['can_cleanup'])
            return payload

        original = start_failed('empfang-1', '201')
        foreign = start_failed('empfang-2', '202')
        endpoint = '/api/v1/calls/' + original['request_id']
        retry = {'client_id': 'empfang-1', 'request_id': str(uuid.uuid4()), 'number': '999', 'extension': '202'}
        count = len([a for a in self.ami.actions if a['Action'] == 'Originate'])
        self.assertEqual(self.request(endpoint + '/retry', retry, {'X-Kienzlefon-Token': ''})[0], 403)
        self.assertEqual(self.request(endpoint + '/retry', {**retry, 'client_id': 'empfang-2'})[0], 400)
        self.assertEqual(self.request(endpoint + '/dismiss', {'client_id': 'empfang-2'})[0], 400)
        self.assertEqual(self.request(endpoint + '/retry')[0], 404)
        status, repeated = self.request(endpoint + '/retry', retry)
        self.assertEqual(status, 202)
        self.assertEqual(repeated['number'], original['number'])
        self.assertEqual(repeated['extension'], original['extension'])
        self.assertEqual(self.request(endpoint + '/retry', retry)[1]['request_id'], retry['request_id'])
        self.assertEqual(len([a for a in self.ami.actions if a['Action'] == 'Originate']), count + 1)
        self.assertEqual(self.request(endpoint + '/retry', {**retry, 'request_id': str(uuid.uuid4())})[0], 503)
        self.assertFalse(outgoing()[original['request_id']]['can_retry'])
        active_endpoint = '/api/v1/calls/' + retry['request_id']
        mutation_start = len(self.ami.actions)
        self.assertEqual(self.request(active_endpoint + '/dismiss', {'client_id': 'empfang-1'})[1], {'removed': [retry['request_id']]})
        self.assertNotIn(retry['request_id'], outgoing())
        self.assertEqual(self.request('/api/v1/calls', {**original, 'request_id': str(uuid.uuid4())})[0], 503)
        self.assertEqual(self.request(endpoint + '/retry', retry)[1]['request_id'], retry['request_id'])
        cleaned = self.request('/api/v1/calls/cleanup', {'client_id': 'empfang-1'})[1]['removed']
        self.assertIn(original['request_id'], cleaned)
        self.assertNotIn(foreign['request_id'], cleaned)
        self.assertIn(foreign['request_id'], outgoing())
        self.assertFalse(any(a['Action'] in ['Originate', 'Hangup', 'Redirect'] for a in self.ami.actions[mutation_start:]))
        self.ami.send({'Event': 'OriginateResponse', 'ActionID': 'kf-c2d-' + retry['request_id'], 'Response': 'Failure'})


if __name__ == '__main__':
    unittest.main(verbosity=2)
