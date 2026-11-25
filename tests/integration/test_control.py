"""Real PHP middleware + loopback contract server. No real PBX, app or patients."""
import copy
import datetime as dt
import http.server
import json
import os
from pathlib import Path
import socket
import subprocess
import tarfile
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request
import uuid
from test_middleware import AMIServer, wait_for

ROOT = Path(__file__).resolve().parents[2]
def stamp(): return dt.datetime.now(dt.timezone.utc).isoformat()

class Source(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def reply(self, body, status=200):
        raw=json.dumps(body).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
    def do_GET(self):
        self.server.auth.append(self.headers.get('Authorization'))
        if self.headers.get('Authorization') != 'Bearer '+self.server.secret: return self.reply({},401)
        if self.path.endswith('/capabilities'): return self.reply(self.server.caps)
        if '/requests/' in self.path:
            job=self.server.jobs.get(self.path.rsplit('/',1)[-1]);return self.reply({'request':job,'error':None})
        if self.path != '/control/v1/state': self.server.unexpected.append(self.path); return self.reply({},404)
        if self.server.mode=='redirect':
            self.send_response(302);self.send_header('Location',f'http://127.0.0.1:{self.server.server_port}/forbidden-redirect');self.end_headers();return
        if self.server.mode=='auth': return self.reply({},401)
        state=copy.deepcopy(self.server.state);state['generated_at']=stamp()
        for field in ['live_counters','routing_control']: state[field]['as_of']=stamp();state[field]['age_ms']=0
        if self.server.mode=='bad-routing': state['routing_control']['revision']='invalid'
        if self.server.mode=='bad-id': state['pbx_id']='another-pbx'
        return self.reply(state)
    def do_POST(self):
        self.server.posts+=1
        if self.headers.get('Authorization') != 'Bearer '+self.server.secret: return self.reply({},401)
        command=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        state=self.server.state
        for e in state['extensions']:
            if e['id']==command['extension']: e['routing'][command['route']]=command['member']
        revision=state['routing_control']['revision'];state['routing_control']['revision']=revision.rsplit(':',1)[0]+':'+str(int(revision.rsplit(':',1)[1])+1)
        job={**command,'state':'applied','created_at':stamp(),'updated_at':stamp(),'applied_at':stamp(),'result_revision':state['routing_control']['revision'],'changed':True,'error':None};self.server.jobs[command['request_id']]=job
        if self.server.lose:
            self.close_connection=True;self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
        return self.reply({'request':job,'error':None})

class ControlIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='kf-control-http-');cls.root=Path(cls.temp.name);cls.log=open(cls.root/'middleware.log','w+')
        fixtures=json.loads((ROOT/'docs/telefonie-v1/fixtures.json').read_text())['fixtures'];overview=next(f['body'] for f in fixtures if f['name']=='overview-current')
        cls.source=http.server.ThreadingHTTPServer(('127.0.0.1',0),Source);cls.source.secret='c'*64;cls.source.auth=[];cls.source.unexpected=[];cls.source.jobs={};cls.source.posts=0;cls.source.lose=False;cls.source.mode='ok'
        cls.source.state={k:copy.deepcopy(overview[k]) for k in ['schema_version','generated_at','live_counters','routing_control']};cls.source.state.update(pbx_id='practice-test',pbx_epoch=overview['routing_control']['pbx_epoch'],extensions=[{k:copy.deepcopy(e[k]) for k in ['id','label','routing']} for e in overview['extensions']]);cls.baseline=copy.deepcopy(cls.source.state)
        cls.source.caps={'schema_version':1,'pbx_id':'practice-test','features':{'live_counters':True,'routing_control':True,'statistics_events':True},'limits':{'command_ttl_seconds':30,'max_body_bytes':8192,'max_age_ms':10000,'request_retention_seconds':604800}}
        cls.ami=AMIServer()
        for server in [cls.source,cls.ami]:threading.Thread(target=server.serve_forever,daemon=True).start()
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        cls.url=f'http://127.0.0.1:{port}';cls.app_secret='a'*64
        c=json.loads((ROOT/'config.example.json').read_text());c['http'].update(listen=f'127.0.0.1:{port}',public_url=cls.url);c['ami'].update(port=cls.ami.server_address[1],username='mock',secret='mock');c['fhir']['database']=str(cls.root/'patients.sqlite');c['gdt']['root']=str(cls.root/'gdt');(cls.root/'gdt'/'empfang-1').mkdir(parents=True)
        for name,key in [('app',cls.app_secret),('control',cls.source.secret)]:
            p=cls.root/(name+'.token');p.write_text(key);p.chmod(0o600)
        c['telephony_control'].update(enabled=True,base_url=f'http://127.0.0.1:{cls.source.server_port}',pbx_id='practice-test',token_file=str(cls.root/'control.token'),request_database=str(cls.root/'requests.sqlite'))
        c['app_access'].update(enabled=True,token_file=str(cls.root/'app.token'),allowed_clients=['empfang-1'],allowed_networks=['127.0.0.1/32'])
        c['call_statistics'].update(enabled=True,pbx_id='practice-test',database_path=str(cls.root/'stats.sqlite'))
        cls.path=cls.root/'config.json';cls.path.write_text(json.dumps(c));cls.path.chmod(0o600);cls.launch=['php',str(ROOT/'bin/server.php'),'--config='+str(cls.path)];cls.start()
    @classmethod
    def start(cls):
        cls.process=subprocess.Popen(cls.launch,cwd=ROOT,stdout=cls.log,stderr=cls.log)
        def ready():
            try:return cls.req('/api/v1/telephony/overview')[1]['routing_control']['available']
            except (OSError,KeyError):
                if cls.process.poll() is not None:cls.log.seek(0);raise AssertionError(cls.log.read())
                return False
        wait_for(ready)
    @classmethod
    def req(cls,path,body=None,headers=None):
        req=urllib.request.Request(cls.url+path,data=json.dumps(body).encode() if body is not None else None,headers={'Authorization':'Bearer '+cls.app_secret,'Content-Type':'application/json',**(headers or {})})
        try:
            with urllib.request.urlopen(req,timeout=8) as r:return r.status,json.load(r)
        except urllib.error.HTTPError as e:
            with e:return e.code,json.load(e)
    def setUp(self):
        self.source.mode='ok';self.source.lose=False;self.source.state=copy.deepcopy(self.baseline)
        wait_for(lambda:self.req('/api/v1/telephony/overview')[1]['routing_control']['available'])
    @classmethod
    def tearDownClass(cls):
        cls.process.terminate();cls.process.wait(timeout=5)
        for server in [cls.source,cls.ami]:server.shutdown();server.server_close()
        cls.log.close();cls.temp.cleanup()
    def test_01_projection_scopes_and_partial_faults(self):
        status,v=self.req('/api/v1/telephony/overview');self.assertEqual(status,200);self.assertEqual(v['live_counters']['waiting'],2);self.assertNotIn('calls',v)
        for route in ['/api/v1/bootstrap','/api/v1/lookup','/api/v1/fhir/status','/api/v1/patient-open','/api/v1/calls']:self.assertEqual(self.req(route)[0],403)
        for mode,live in [('bad-routing','current'),('bad-id','unavailable'),('auth','unavailable'),('redirect','unavailable')]:
            self.source.mode=mode
            v=wait_for(lambda:(lambda x:x if not x['routing_control']['available'] and x['live_counters']['status']==live else None)(self.req('/api/v1/telephony/overview')[1]))
            self.assertEqual(v['extensions'][0]['state'],'idle');self.assertEqual(v['call_statistics']['today']['status'],'partial')
            if mode=='redirect':time.sleep(2.1)
        self.assertEqual(self.source.unexpected,[])
        self.assertTrue(all(x=='Bearer '+self.source.secret for x in self.source.auth))
    def test_02_lost_reply_restart_idempotency_and_reconciliation(self):
        v=self.req('/api/v1/telephony/overview')[1];r=v['routing_control'];command={'request_id':str(uuid.uuid4()),'client_id':'empfang-1','extension':'201','route':'normal','member':False,'expected_revision':r['revision'],'pbx_epoch':r['pbx_epoch'],'expires_at':(dt.datetime.now(dt.timezone.utc)+dt.timedelta(seconds=30)).isoformat()}
        self.source.lose=True;before=self.source.posts;status,result=self.req('/api/v1/extension-routing',command);self.assertEqual(status,202);self.assertIn(result['request']['state'],['accepted','unknown'])
        wait_for(lambda:self.source.posts==before+1)
        cls=type(self);cls.process.kill();cls.process.wait(timeout=5);cls.start()
        status,job=self.req('/api/v1/extension-routing',command);self.assertIn(status,[200,202]);self.assertEqual(self.source.posts,before+1)
        result=wait_for(lambda:(lambda x:x if x['request']['state']=='applied' else None)(self.req('/api/v1/extension-routing/requests/'+command['request_id'])[1]));self.assertTrue(result['request']['changed']);self.assertEqual(self.source.posts,before+1)
        self.assertEqual(self.req('/api/v1/extension-routing',{**command,'member':True})[0],409)
        self.assertEqual((self.root/'requests.sqlite').stat().st_mode&0o777,0o600)
    def test_03_service_rotation_and_input_validation(self):
        self.assertEqual(self.req('/api/v1/telephony/overview',headers={'Authorization':'Bearer wrong'})[0],401)
        self.assertEqual(self.req('/api/v1/telephony/overview',headers={'Origin':'https://other.example'})[0],403)
        self.assertEqual(self.req('/api/v1/extension-routing',{})[0],400)
        self.assertEqual(self.req('/api/v1/extension-routing',{},headers={'Content-Type':'text/plain'})[0],415)
        self.assertEqual(self.req('/api/v1/extension-routing',{'junk':'x'*8192})[0],413)
        (self.root/'app.token').write_text('b'*64)
        try:self.assertEqual(self.req('/api/v1/telephony/overview')[0],401)
        finally:(self.root/'app.token').write_text(self.app_secret)

    def test_04_installed_app_proxy_counts_and_reports_failures(self):
        """Use the actual App release, its cURL client, the installer candidate and SQLite."""
        package = Path(os.environ.get('T2MED_TEST_APP_PACKAGE',
            ROOT.parent/'kombi-installer-fon-fax/pakete/kienzlefon-app-3.7-telefonie.tar.gz'))
        if not package.exists():
            self.skipTest('App-Paket über T2MED_TEST_APP_PACKAGE bereitstellen')
        cls = type(self)
        web = self.root / 'web'
        web.mkdir()
        with tarfile.open(package) as archive:
            for name in ('telepraxis-telephony.php', 'telepraxis-telephony-schema.json'):
                (web / name).write_bytes(archive.extractfile('kienzlefon-app/' + name).read())
        original = cls.path
        settings = json.loads(original.read_text())
        settings['telephony_control'].update(enabled=False, pbx_id='', base_url='http://127.0.0.1:8767')
        settings['call_statistics'].update(enabled=False, pbx_id='')
        repair = self.root / 'repair.json'
        repair.write_text(json.dumps(settings))
        planned = self.root / 'planned.toml'
        webconfig = self.root / 'webconfig.php'
        cli = ['php', ROOT/'installer/configure.php']
        subprocess.run([*cli, 'migrate', repair, planned], check=True)
        subprocess.run([*cli, 'prepare-app', planned, webconfig, '', 'yes'], capture_output=True, check=True)
        # Relocate the installer-generated www-data token reference into this private test directory.
        webconfig.write_text(webconfig.read_text().replace('/etc/kienzlefon-app/t2med-token', str(self.root/'app.token')))
        (self.root/'fon-info.json').write_text(json.dumps(dict(
            pbx_id='practice-test', base_url=f'http://127.0.0.1:{self.source.server_port}')))
        prepare = [*cli, 'prepare-counters', planned, self.root, self.root/'control.token']
        subprocess.run(prepare, check=True)
        verified = planned.read_bytes()
        cls.launch[-1] = '--config=' + str(planned)

        def restart():
            cls.process.terminate()
            cls.process.wait(timeout=5)
            cls.start()

        answer = self.root/'app-answer.json'
        shell = (ROOT/'installer/install.sh').read_text()
        read_status = shell.split('read_app_status() {', 1)[1].split('\n}', 1)[0]
        env = dict(os.environ, WEB_PROXY=str(web/'telepraxis-telephony.php'), WORK_DIR=str(self.root),
                   KIENZLEFON_TELEPHONY_CONFIG=str(webconfig))
        def proxy():
            subprocess.run(['sh', '-ec', 'runuser() { shift 3; "$@"; }\n' + read_status], env=env, check=True)
            return json.loads(answer.read_text())

        def validate():
            return subprocess.run(['php', ROOT/'installer/check-app-counters.php', answer],
                                  capture_output=True, text=True)

        def daily_count():
            return proxy()['call_statistics']['today']['incoming_calls']

        try:
            self.source.state['live_counters'].update(waiting=0, ivr=0, ai=0)
            restart()
            wait_for(lambda: proxy()['live_counters']['waiting'] == 0 and validate().returncode == 0)
            zero = daily_count()
            self.assertEqual(zero, 0)
            self.assertIn('Warteschleife 0, IVR 0, AI 0', validate().stdout)
            self.ami.send(dict(Event='UserEvent', UserEvent='KienzlefonCallEntered', SchemaVersion='1',
                               PbxId='practice-test', CallId=str(uuid.uuid4()), EnteredAt=str(int(time.time()))))
            wait_for(lambda: daily_count() == 1)
            self.assertEqual(validate().returncode, 0)
            subprocess.run(prepare, check=True)
            self.assertEqual(planned.read_bytes(), verified)
            restart()
            self.assertEqual(daily_count(), 1)
            self.assertEqual(validate().returncode, 0)
            self.source.mode = 'auth'
            wait_for(lambda: proxy()['live_counters']['reason_code'] == 'source_disconnected')
            result = validate()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('source_disconnected', result.stderr)
            self.source.mode = 'ok'
            # Disable the real middleware statistic and read the result back over the proxy.
            planned.write_bytes(verified.replace(b'[call_statistics]\nenabled = true',
                                                 b'[call_statistics]\nenabled = false'))
            self.assertNotEqual(planned.read_bytes(), verified)
            restart()
            proxy()
            result = validate()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Anrufe heute: not_configured', result.stderr)
        finally:
            cls.launch[-1] = '--config=' + str(original)
            self.source.mode = 'ok'
            restart()

if __name__=='__main__':unittest.main()
