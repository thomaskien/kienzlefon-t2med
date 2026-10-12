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
                    self.server.send({'Response': 'Success', 'ActionID': aid})
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

    def test_01_aps_auth_empty_password_and_multiple_candidates(self):
        result = self.lookup('03055501022')
        self.assertEqual(result['status'], 'ambiguous')
        self.assertEqual([p['patient_number'] for p in result['patients']], [90001, 90002])
        self.assertTrue(all(h == 'Basic ' + base64.b64encode(b'test:').decode() for h in self.aps.headers_seen))
        self.assertTrue(all(p['ignoreSichtbarkeit'] is False for p in self.aps.requests))
        self.assertTrue(all('_ref' not in p for p in result['patients']))

    def test_02_gdt_selection_no_overwrite_and_idempotency(self):
        lookup = self.lookup('03055501022')
        request = {'client_id': 'empfang-1', 'lookup_id': lookup['lookup_id'], 'patient_number': 90002, 'request_id': str(uuid.uuid4())}
        target = self.share / 'empfang-1' / 'AUFRUFi.gdt'
        status, result = self.request('/api/v1/patient-open', request)
        self.assertEqual(status, 200)
        data = target.read_bytes()
        self.assertIn(b'014300090002\r\n', data)
        self.assertNotIn(b'Patient', data)
        for line in data.splitlines(keepends=True):
            self.assertEqual(int(line[:3]), len(line))
        self.assertEqual(self.request('/api/v1/patient-open', {**request, 'request_id': str(uuid.uuid4())})[0], 503)
        target.unlink()  # Simulated consumption by T2med.
        self.assertEqual(self.request('/api/v1/patient-open', request), (status, result))
        self.assertFalse(target.exists())
        self.assertEqual(self.request('/api/v1/patient-open', {**request, 'client_id': 'empfang-2'})[0], 200)
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
        self.assertEqual(self.request('/api/v1/bootstrap')[1]['version'], '1.3')
        self.assertEqual(self.request('/healthz')[1]['version'], '1.3')

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


if __name__ == '__main__':
    unittest.main(verbosity=2)
