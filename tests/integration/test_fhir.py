"""Real browser API -> PHP -> compiled Go -> fake HTTPS FHIR -> SQLite, no practice data."""
import base64
import hashlib
import http.server
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import unittest
import uuid
import urllib.error
import urllib.parse
import urllib.request
from test_middleware import AMIServer, wait_for

ROOT = Path(__file__).resolve().parents[2]
SYSTEM = 'https://fhir.t2med.de/identifier/patientennummer'


def patient(id, number, phones):
    return dict(resourceType='Patient', id=id, identifier=[] if number is None else [dict(system=SYSTEM, value=str(number))],
                name=[dict(family='Example', given=['Test'])], telecom=[dict(system='phone', value=n) for n in phones])


class FHIR(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        self.server.requests.append((self.path, dict(self.headers)))
        token = self.headers.get('Authorization', '')
        if 'expired' in token:
            self.send_response(401); self.end_headers(); return
        if self.headers.get('X-API-Key') != 'mock-fhir-key':
            self.send_response(403); self.end_headers(); return
        page2 = 'page=2' in self.path
        if 'broken' in token and page2:
            self.send_response(500); self.end_headers(); return
        if 'slow' in token and page2:
            time.sleep(.8)
        people = [patient('resource-A',8100,['030 55501111','+49 30 55501111']),
                  patient('resource-B',8131,[]), patient('resource-C',None,['03055502222']),
                  patient('resource-D',9000,['03055503333']), patient('resource-E',9001,['+493055503333'])]
        if 'local-numbers' in token:
            people = [patient('local-three',8200,['111']),patient('local-four',8201,['2111']),
                      patient('local-long',8202,['55502111']),patient('local-complete',8203,['03055502111']),
                      patient('local-too-short',8204,['12'])]
        if 'search-patients' in token:
            people=[patient('search-mara',810000000,[]),patient('search-paul',8301,[]),
                    patient('search-petra',None,[]),patient('search-anna',8303,['8301'])]
            for p,(first,last) in zip(people,[('Mara','Müller'),('Paul','Becker'),('Petra','Becker'),('Anna','Meier')]):
                p['name']=[dict(family=last,given=[first])]
        if 'empty' in token:
            body = dict(resourceType='Bundle',type='searchset',total=0)
        else:
            body = dict(resourceType='Bundle',type='searchset',total=len(people),entry=[{'resource':p} for p in (people[2:] if page2 else people[:2])])
            if not page2: body['link']=[dict(relation='next',url='?page=2')]
        data=json.dumps(body).encode()
        self.send_response(200); self.send_header('Content-Type','application/fhir+json'); self.send_header('Content-Length',str(len(data))); self.end_headers(); self.wfile.write(data)


class FhirIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='kf-fhir-'); cls.root=Path(cls.temp.name)
        binary=cls.root/'importer'
        env={**os.environ,'GOCACHE':os.environ.get('GOCACHE','/tmp/kienzlefon-fhir-go-cache')}
        subprocess.run(['sh',str(ROOT/'fhir-importer/build.sh'),str(binary)],input=b'mock-fhir-key',env=env,check=True,capture_output=True)
        cert,key=cls.root/'cert.pem',cls.root/'key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=localhost'],check=True,capture_output=True)
        cls.fhir=http.server.ThreadingHTTPServer(('127.0.0.1',0),FHIR);cls.fhir.requests=[]
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key);cls.fhir.socket=context.wrap_socket(cls.fhir.socket,server_side=True)
        threading.Thread(target=cls.fhir.serve_forever,daemon=True).start()
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        cls.url=f'http://127.0.0.1:{port}';cls.base=f'https://127.0.0.1:{cls.fhir.server_port}/aps/fhir/api/r4'
        config=json.loads((ROOT/'config.example.json').read_text());config['http'].update(listen=f'127.0.0.1:{port}',public_url=cls.url)
        cls.ami=AMIServer(); threading.Thread(target=cls.ami.serve_forever,daemon=True).start()
        config['telephony']['extensions']['21']='Kurzes Ziel'
        config['ami'].update(host='127.0.0.1',port=cls.ami.server_address[1],username='mock',secret='mock-secret');config['fhir'].update(base_url=cls.base,binary=str(binary),database=str(cls.root/'private'/'patients.sqlite'),tls_verify=False)
        config['gdt']['root']=str(cls.root/'gdt'); (cls.root/'gdt'/'empfang-1').mkdir(parents=True)
        cls.config=cls.root/'config.json';cls.config.write_text(json.dumps(config));cls.db=Path(config['fhir']['database']);cls.log=open(cls.root/'server.log','w+')
        cls.start_server()

    @classmethod
    def start_server(cls):
        cls.server=subprocess.Popen(['php',str(ROOT/'bin/server.php'),'--config='+str(cls.config)],cwd=ROOT,stdout=cls.log,stderr=cls.log)
        for _ in range(100):
            try:
                cls.csrf=cls.request('/api/v1/bootstrap')['token'];return
            except (OSError,ValueError): time.sleep(.05)
        raise AssertionError('PHP server did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate();cls.server.wait(timeout=5);cls.fhir.shutdown();cls.fhir.server_close();cls.ami.shutdown();cls.ami.server_close();cls.log.close();cls.temp.cleanup()

    @classmethod
    def request(cls,path,body=None):
        req=urllib.request.Request(cls.url+path,data=None if body is None else json.dumps(body).encode(),headers={} if body is None else {'Content-Type':'application/json','X-Kienzlefon-Token':cls.csrf})
        with urllib.request.urlopen(req,timeout=5) as response:return json.load(response)

    def test_dial_names_and_internal_routing(self):
        self.launch('dial-names'); self.assertEqual(self.finished()['job']['state'],'success')
        wait_for(lambda: not self.request('/api/v1/telephony')['stale'])
        before=len(self.fhir.requests)
        for number,name in [('03055501111','Test Example'),('+493055501111','Test Example'),('03055503333',''),('03055509999',''),('202',''),('21','')]:
            request=dict(client_id='empfang-1',extension='201',number=number,request_id=str(uuid.uuid4()))
            call=self.request('/api/v1/calls',request)
            self.assertEqual(call['phase'],'ringing_extension')
            action=next(a for a in self.ami.actions if a.get('ChannelId')=='kf-'+request['request_id'])
            self.assertEqual(action['CallerID'],f'"{name}" <{number}>')
            if number in ['202','21']:
                self.assertEqual(action['Application'],'Dial'); self.assertEqual(action['Data'],f'PJSIP/{number},20,f(201)')
                self.assertNotIn('Context',action); self.assertNotIn('Exten',action)
            else:
                self.assertEqual(action['Context'],'kienzlefon-click-to-dial'); self.assertEqual(action['Exten'],number)
                self.assertNotIn('Application',action)
            self.assertEqual(self.request('/api/v1/calls',request)['request_id'],call['request_id'])
            self.assertEqual(sum(a.get('ChannelId')=='kf-'+request['request_id'] for a in self.ami.actions),1)
            self.ami.send(dict(Event='OriginateResponse',ActionID=action['ActionID'],Response='Failure'))
            wait_for(lambda: any(c['request_id']==request['request_id'] and c['phase']=='failed' for c in self.request('/api/v1/telephony')['outgoing']))
        self.assertEqual(len(self.fhir.requests),before)

    def launch(self,token):
        return self.request('/api/v1/fhir/import',dict(kontextId='test-context',fhirBasisUrl=self.base,oAuthToken=token,request_id=str(uuid.uuid5(uuid.NAMESPACE_URL,token))))

    def finished(self):
        for _ in range(120):
            status=self.request('/api/v1/fhir/status')
            if status['job']['state']!='running':return status
            time.sleep(.05)
        self.fail('Import did not finish')

    def test_01_import_lookup_gdt_and_restart_without_credentials(self):
        self.assertEqual(self.request('/api/v1/lookup',{'number':'03055501111'})['status'],'error')
        with self.assertRaises(urllib.error.HTTPError) as error:self.request('/api/v1/patients/search',{'query':'Example'})
        self.assertEqual(error.exception.code,503)
        before=len(self.fhir.requests)
        for path in ['/telefonverzeichnis-aktualisieren','/telefonverzeichnis-aktualisieren.php']:
            with urllib.request.urlopen(self.url+path+'?oAuthToken=mock-launch-secret') as r:
                self.assertNotIn(b'mock-launch-secret',r.read());self.assertEqual(r.headers['Referrer-Policy'],'no-referrer')
        self.assertEqual(len(self.fhir.requests),before)
        self.assertTrue(self.launch('valid-once')['accepted']);status=self.finished()
        self.assertEqual(status['job']['state'],'success',status);self.assertEqual(status['directory']['patient_count'],5);self.assertEqual(status['directory']['invalid_patient_numbers'],1)
        self.assertEqual(len(self.fhir.requests)-before,2)
        self.assertEqual(os.stat(self.db).st_mode&0o777,0o600)
        for value in ['03055501111','+493055501111','00493055501111','493055501111']:
            match=self.request('/api/v1/lookup',{'number':value});self.assertEqual(match['status'],'resolved');self.assertEqual(match['patients'][0]['patient_number'],8100);self.assertEqual(match['patients'][0]['id'],'resource-A')
        self.assertEqual(len(self.fhir.requests)-before,2)
        self.assertEqual(self.request('/api/v1/lookup',{'number':'03055503333'})['status'],'ambiguous')
        missing=self.request('/api/v1/lookup',{'number':'03055502222'});self.assertIsNone(missing['patients'][0]['patient_number'])
        with self.assertRaises(urllib.error.HTTPError):self.request('/api/v1/patient-open',dict(client_id='empfang-1',lookup_id=missing['lookup_id'],patient_number=1,request_id='00000000-0000-0000-0000-000000000001'))
        self.request('/api/v1/patient-open',dict(client_id='empfang-1',lookup_id=match['lookup_id'],patient_number=8100,request_id='00000000-0000-0000-0000-000000000002'))
        self.assertIn(b'30008100', (self.root/'gdt'/'empfang-1'/'AUFRUFi.gdt').read_bytes())
        self.assertTrue(self.launch('valid-once')['duplicate']);self.assertEqual(len(self.fhir.requests)-before,2)
        self.server.terminate();self.server.wait(timeout=5);type(self).start_server()
        self.assertEqual(self.request('/api/v1/lookup',{'number':'03055501111'})['status'],'resolved');self.assertEqual(len(self.fhir.requests)-before,2)

    def test_02_mid_import_failure_and_expired_token_preserve_cache(self):
        before=self.db.read_bytes()
        for token in ['broken-on-page-two','expired-credential']:
            self.launch(token);status=self.finished();self.assertEqual(status['job']['state'],'error');self.assertEqual(self.db.read_bytes(),before)
        self.assertEqual(self.request('/api/v1/lookup',{'number':'03055501111'})['status'],'resolved')
        self.log.flush();self.log.seek(0);log=self.log.read()
        for secret in ['valid-once','broken-on-page-two','expired-credential','mock-launch-secret','mock-fhir-key']:
            self.assertNotIn(secret,log);self.assertNotIn(secret,Path(str(self.db)+'.status.json').read_text());self.assertNotIn(secret.encode(),self.db.read_bytes())

    def test_03_wrong_target_concurrent_import_and_csrf(self):
        before=len(self.fhir.requests)
        with self.assertRaises(urllib.error.HTTPError) as error:self.request('/api/v1/fhir/import',dict(kontextId='context',fhirBasisUrl='https://foreign.example/fhir',oAuthToken='test'))
        self.assertEqual(error.exception.code,400);self.assertEqual(len(self.fhir.requests),before)
        self.launch('slow-one')
        with self.assertRaises(urllib.error.HTTPError) as error:self.launch('another-launch')
        self.assertEqual(error.exception.code,503);self.assertEqual(self.finished()['job']['state'],'success')
        request=urllib.request.Request(self.url+'/api/v1/fhir/import',data=b'{}',headers={'Content-Type':'application/json'})
        with self.assertRaises(urllib.error.HTTPError) as error:urllib.request.urlopen(request)
        self.assertEqual(error.exception.code,403)

    def test_04_local_numbers_from_three_digits_offer_all_matching_patients(self):
        self.launch('local-numbers');status=self.finished();self.assertEqual(status['job']['state'],'success')
        self.assertEqual(status['directory']['patients_with_phone'],4)
        self.assertEqual(status['directory']['suffix_phone_count'],3)
        self.assertEqual(status['directory']['unusable_phone_count'],1)
        before=len(self.fhir.requests)
        match=self.request('/api/v1/lookup',{'number':'+493055502111'})
        self.assertEqual(match['status'],'ambiguous')
        self.assertEqual({p['patient_number'] for p in match['patients']},{8200,8201,8202,8203})
        self.assertEqual(self.request('/api/v1/lookup',{'number':'04055509111'})['patients'][0]['patient_number'],8200)
        for value in ['4930111','030111','+4930111','004930111']:
            self.assertEqual(self.request('/api/v1/lookup',{'number':value})['patients'][0]['patient_number'],8200)
        self.assertEqual(self.request('/api/v1/lookup',{'number':'03055501112'})['status'],'not_found')
        self.assertEqual(self.request('/api/v1/lookup',{'number':'+443055502111'})['status'],'not_found')
        self.server.terminate();self.server.wait(timeout=5);type(self).start_server()
        self.assertEqual(len(self.request('/api/v1/lookup',{'number':'03055502111'})['patients']),4)
        self.assertEqual(len(self.fhir.requests),before)

    def test_05_manual_search_names_numbers_and_verified_file_open(self):
        self.launch('search-patients');self.assertEqual(self.finished()['job']['state'],'success')
        before=len(self.fhir.requests)
        for query in ['müll','MUELLER','Müller, Mara','810000000']:
            result=self.request('/api/v1/patients/search',{'query':query})
            self.assertEqual(result['status'],'resolved');self.assertEqual(result['patients'][0]['id'],'search-mara')
            self.assertFalse(result['truncated']);self.assertNotIn('phones',result['patients'][0])
        self.assertEqual(self.request('/api/v1/lookup',{'number':'810000000'})['status'],'not_found')
        self.assertEqual(len(self.request('/api/v1/patients/search',{'query':'Beck'})['patients']),2)
        collision=self.request('/api/v1/patients/search',{'query':'8301'})
        self.assertEqual(collision['status'],'ambiguous');self.assertEqual({p['patient_number'] for p in collision['patients']},{8301,8303})
        with self.assertRaises(urllib.error.HTTPError):self.request('/api/v1/patient-open',dict(client_id='empfang-1',lookup_id=result['lookup_id'],patient_number=8303,request_id=str(uuid.uuid4())))
        self.request('/api/v1/patient-open',dict(client_id='empfang-1',lookup_id=result['lookup_id'],patient_number=810000000,request_id=str(uuid.uuid4())))
        self.assertIn(b'3000810000000',(self.root/'gdt'/'empfang-1'/'AUFRUFi.gdt').read_bytes())
        missing=self.request('/api/v1/patients/search',{'query':'Petra'})
        with self.assertRaises(urllib.error.HTTPError):self.request('/api/v1/patient-open',dict(client_id='empfang-1',lookup_id=missing['lookup_id'],patient_number=None,request_id=str(uuid.uuid4())))
        for invalid in ['',[],123,'x'*257]:
            with self.assertRaises(urllib.error.HTTPError) as error:self.request('/api/v1/patients/search',{'query':invalid})
            self.assertEqual(error.exception.code,400)
        self.server.terminate();self.server.wait(timeout=5);type(self).start_server()
        self.assertEqual(self.request('/api/v1/patients/search',{'query':'Müller'})['status'],'resolved')
        self.assertEqual(len(self.fhir.requests),before)

    def test_06_complete_empty_inventory_removes_previous_matches(self):
        previous=self.request('/api/v1/patients/search',{'query':'Müller'})
        self.launch('empty-inventory');status=self.finished();self.assertEqual(status['job']['state'],'success');self.assertEqual(status['directory']['patient_count'],0)
        self.assertEqual(self.request('/api/v1/lookup',{'number':'03055501111'})['status'],'not_found')
        self.assertEqual(self.request('/api/v1/patients/search',{'query':'Müller'})['status'],'not_found')
        with self.assertRaises(urllib.error.HTTPError):self.request('/api/v1/patient-open',dict(client_id='empfang-1',lookup_id=previous['lookup_id'],patient_number=810000000,request_id=str(uuid.uuid4())))


if __name__=='__main__':unittest.main()
