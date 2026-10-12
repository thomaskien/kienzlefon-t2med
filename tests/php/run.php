<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Kienzlefon\{Config,Phone,Gdt,Aps,Ami,Telephony,HttpApi,Runtime};
use React\Http\Message\ServerRequest;
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void { global $passed,$failed; try { $fn(); $passed++; echo "OK $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; } }
function eq(mixed $actual, mixed $expected): void { if ($actual !== $expected) throw new RuntimeException('Erwartet ' . var_export($expected,true) . ', erhalten ' . var_export($actual,true)); }
function rejects(callable $fn): void { try { $fn(); } catch (Throwable) { return; } throw new RuntimeException('Fehler erwartet.'); }
function config(): array { $c = json_decode(file_get_contents(dirname(__DIR__,2).'/config.example.json'),true); $c['aps']['username'] = 'test'; return $c; }
function patient(int $id, string $ref = ''): array { return ['nummer'=>$id,'vorname'=>'Test','nachname'=>'Person','geburtsdatum'=>'19800101','adresse'=>'Musterweg','ref'=>['objectId'=>['id'=>$ref ?: 'p'.$id],'revision'=>3]]; }
function channel(string $id, string $name, string $linked, string $bridge = '', string $caller = '03055501111', string $connected = ''): array { return ['Event'=>'CoreShowChannel','Uniqueid'=>$id,'Channel'=>$name,'Linkedid'=>$linked,'BridgeId'=>$bridge,'CallerIDNum'=>$caller,'ConnectedLineNum'=>$connected,'ChannelStateDesc'=>$bridge ? 'Up' : 'Ringing','Duration'=>'00:02:00']; }

test('Leeres APS-Passwort bleibt gültig', fn() => eq(Config::validate(config())['aps']['password'], ''));
test('Clientpfad kann nicht aus Share entkommen', function () { $c=config(); $c['clients']['empfang-1']['directory']='../foo'; rejects(fn()=>Config::validate($c)); });
test('Doppelte Arbeitsplatzverzeichnisse sind verboten', function () { $c=config();$c['clients']['zweiter']=$c['clients']['empfang-1'];rejects(fn()=>Config::validate($c)); });
test('Nebenstelle muss konfiguriert sein', function () { $c=config();$c['clients']['empfang-1']['extension']='999';rejects(fn()=>Config::validate($c)); });
test('APS-URL darf keine Zugangsdaten enthalten', function () { $c=config();$c['aps']['url']='https://user:pass@host';rejects(fn()=>Config::validate($c)); });
test('Steuerzeichen und ungültige Netzwerke werden verworfen', function () { $c=config();$c['ami']['secret']="a\r\nAction: Originate";rejects(fn()=>Config::validate($c));$c=config();$c['http']['allowed_networks']=['0.0.0.0/99'];rejects(fn()=>Config::validate($c)); });
test('Nationale und internationale Rufnummern haben denselben Schlüssel', function () { eq(Phone::key('030 555-01111'),'+493055501111');eq(Phone::key('0049 (30) 55501111'),'+493055501111');eq(Phone::variants('+493055501111'),['03055501111','+493055501111','00493055501111','493055501111']); });
test('Mobilnummern mit 0, +49, 0049 und 49 nutzen dieselben Suchvarianten', function () {
 $variants=['01765550173','+491765550173','00491765550173','491765550173'];
 foreach($variants as $number){eq(Phone::key($number),'+491765550173');eq(Phone::variants($number),$variants);}
 eq(Phone::variants('0049 (176) 555-0173'),$variants);
 eq(Phone::key('49123'),'49123');eq(Phone::key('0305550173'),'+49305550173');
 eq(Phone::key('436601234567','43'),'+436601234567');eq(Phone::variants('+33612345678'),['+33612345678','0033612345678']);
});
test('Rufnummerninjektionen sind ungültig', function () { foreach (["030555\r\nAction: Originate",'123;456','123?x=3','++123','$(1234567)','anonymous'] as $v) rejects(fn()=>Phone::normalize($v)); });
test('Interne und kurze Nummern werden nicht in APS gesucht', function () { eq(Phone::external('201',['201'=>'Empfang']), '');eq(Phone::external('anonymous'), ''); });
test('APS-Mehrfachtreffer bleiben auch bei gleichem Namen getrennt', function () { $r=Aps::classify([[patient(90001),patient(90002)],[patient(90001)]],['03055501111']);eq($r['status'],'ambiguous');eq(count($r['patients']),2);eq($r['name'],'');eq($r['patients'][0]['_ref']['revision'],3); });
test('APS-Patientennummern-Kollision löst keine automatische Zuordnung aus', fn()=>eq(Aps::classify([[patient(12345678)]],['12345678'])['status'],'ambiguous'));
test('Eindeutiger APS-Treffer und keine Treffer', function () { eq(Aps::classify([[patient(90001)]],['03055501111'])['status'],'resolved');eq(Aps::classify([[]],['03055501111'])['status'],'not_found'); });
test('Interne Referenz wird nicht an Browser ausgegeben', function () { $r=Aps::classify([[patient(90001)]],['03055501111']);eq(isset(Aps::publicPatients($r['patients'])[0]['_ref']),false); });
test('AMI kodiert wiederholte Variable-Header und sperrt CRLF', function () { $wire=Ami::encode(['Action'=>'Originate','Variable'=>['A=1','B=2']]);eq(substr_count($wire,'Variable:'),2);rejects(fn()=>Ami::encode(['Value'=>"x\r\nAction: Command"]));eq(Ami::parse("Asterisk Call Manager/1.0\r\nResponse: Success\r\nActionID: one")['ActionID'],'one'); });
test('GDT-Byte-Längen und ID-only-Inhalt', function () { $r=Gdt::record(90001);$lines=explode("\r\n",rtrim($r,"\r\n"));foreach($lines as $line)eq((int)substr($line,0,3),strlen($line)+2);eq((int)substr($lines[1],7),strlen($r));eq(str_contains($r,"014300090001\r\n"),true);eq(count($lines),7); });
test('GDT atomar ersetzen und Arbeitsplätze trennen', function () {
 $root=sys_get_temp_dir().'/kf-test-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/one');mkdir($root.'/two');$c=config();$c['gdt']['root']=$root;$writer=new Gdt($c['gdt'],['one'=>['directory'=>'one'],'two'=>['directory'=>'two']]);
 try { eq($writer->write('one',90001)['status'],'sent');$writer->write('two',90003);eq($writer->write('one',90002)['status'],'sent');eq(file_get_contents($root.'/one/AUFRUFi.gdt'),Gdt::record(90002));eq(file_get_contents($root.'/two/AUFRUFi.gdt'),Gdt::record(90003));eq(fileperms($root.'/one/AUFRUFi.gdt')&0777,0660);eq(glob($root.'/one/.kienzlefon-*'),[]);rejects(fn()=>$writer->write('../x',90001)); }
 finally { foreach(['one','two'] as $f){@unlink($root.'/'.$f.'/AUFRUFi.gdt');rmdir($root.'/'.$f);}rmdir($root); }
});
test('GDT ersetzt Symlink ohne dessen Ziel zu verändern und räumt temporäre Dateien auf', function () {
 $root=sys_get_temp_dir().'/kf-test-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/one');$external=$root.'/external.gdt';file_put_contents($external,'extern');$target=$root.'/one/AUFRUFi.gdt';symlink($external,$target);$c=config();$c['gdt']['root']=$root;$writer=new Gdt($c['gdt'],['one'=>['directory'=>'one']]);
 try { eq($writer->write('one',90004)['status'],'sent');eq(is_link($target),false);eq(file_get_contents($target),Gdt::record(90004));eq(file_get_contents($external),'extern');eq(glob($root.'/one/.kienzlefon-*'),[]); }
 finally { @unlink($target);@unlink($external);rmdir($root.'/one');rmdir($root); }
});
test('GDT-Publikationsfehler räumt temporäre Datei auf', function () {
 $root=sys_get_temp_dir().'/kf-test-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/one');$target=$root.'/one/AUFRUFi.gdt';mkdir($target);$c=config();$c['gdt']['root']=$root;$writer=new Gdt($c['gdt'],['one'=>['directory'=>'one']]);
 try { rejects(fn()=>$writer->write('one',90005));eq(glob($root.'/one/.kienzlefon-*'),[]); }
 finally { rmdir($target);rmdir($root.'/one');rmdir($root); }
});
test('IPv4, IPv6 und gemappte IPv4-Netze', function () { eq(HttpApi::allowedAddress('192.168.4.5',['192.168.0.0/16']),true);eq(HttpApi::allowedAddress('8.8.8.8',['192.168.0.0/16']),false);eq(HttpApi::allowedAddress('::ffff:192.168.4.5',['192.168.0.0/16']),true);eq(HttpApi::allowedAddress('::1',['::1/128']),true); });
test('Warteschlange referenziert denselben logischen Anruf', function () { $s=new Telephony(config()['telephony']);$s->beginSync();$s->finishSync([channel('a','PJSIP/trunk-0001','a'),channel('b','PJSIP/201-0002','a')],[['Event'=>'QueueEntry','Queue'=>'praxis','Channel'=>'PJSIP/trunk-0001','Position'=>'2','Wait'=>'45']]);$v=$s->view();eq(count($v['calls']),1);eq($v['queues'][0]['entries'][0]['call_id'],$v['calls'][0]['id']);eq($v['calls'][0]['state'],'waiting');eq($v['calls'][0]['duration_seconds'],null); });
test('Leere Warteschlange ohne Mitglieder erscheint aus QueueParams', function () {
 $s=new Telephony(config()['telephony']);$s->beginSync();
 $s->event(['Event'=>'QueueParams','Queue'=>'praxis','ActionID'=>'snapshot']);
 $s->finishSync([],[['Event'=>'QueueParams','Queue'=>'praxis','Calls'=>'0','ActionID'=>'snapshot']]);
 eq($s->view()['queues'],[['name'=>'praxis','waiting_count'=>0,'entries'=>[]]]);eq($s->view()['calls'],[]);
});
test('Snapshot und Verbindungsabbruch entfernen nicht mehr vorhandene Warteschlangen', function () {
 $s=new Telephony(config()['telephony']);$s->beginSync();$s->finishSync([],[['Event'=>'QueueParams','Queue'=>'alt']]);
 $s->beginSync();$s->finishSync([],[['Event'=>'QueueParams','Queue'=>'neu']]);eq(array_column($s->view()['queues'],'name'),['neu']);
 $s->disconnect();eq($s->view()['queues'],[]);$s->beginSync();$s->finishSync([],[]);eq($s->view()['queues'],[]);
});
test('Expliziter Queue-Filter gilt auch für QueueParams', function () {
 $c=config()['telephony'];$c['queues']=['praxis'];$s=new Telephony($c);$s->beginSync();
 $s->finishSync([],[['Event'=>'QueueParams','Queue'=>'andere'],['Event'=>'QueueParams','Queue'=>'praxis']]);
 eq(array_column($s->view()['queues'],'name'),['praxis']);
});
test('Hangup während Snapshot verhindert Wiederbelebung', function () { $s=new Telephony(config()['telephony']);$s->beginSync();$s->event(['Event'=>'Hangup','Uniqueid'=>'a','Channel'=>'PJSIP/trunk-0001']);$s->finishSync([channel('a','PJSIP/trunk-0001','a')],[]);eq(count($s->view()['calls']),0); });
test('Stale-Zustand zeigt keine alten Gespräche', function () { $s=new Telephony(config()['telephony']);$s->beginSync();$s->finishSync([channel('a','PJSIP/trunk-0001','a')],[]);$s->disconnect();eq($s->view()['stale'],true);eq($s->view()['calls'],[]); });
test('Unterschiedliche Brücken trotz gleichem Linkedid bleiben getrennt', function () { $s=new Telephony(config()['telephony']);$s->beginSync();$s->finishSync([channel('a','PJSIP/trunk-1','a','bridge1'),channel('b','PJSIP/201-2','a','bridge1'),channel('c','PJSIP/202-3','a','bridge2'),channel('d','PJSIP/other-4','a','bridge2')],[]);eq(count($s->view()['calls']),2); });
test('Local-Kanalpaar verbindet zwei technische Brücken', function () { $s=new Telephony(config()['telephony']);$s->beginSync();$s->finishSync([channel('a','PJSIP/trunk-1','a','b1'),channel('b','Local/201@ring-0001;1','a','b1'),channel('c','Local/201@ring-0001;2','a','b2'),channel('d','PJSIP/201-4','a','b2')],[]);eq(count($s->view()['calls']),1); });
test('Ausgehender Anruf nutzt externe ConnectedLine statt Praxisnummer', function () { $s=new Telephony(config()['telephony']);$s->beginSync();$s->finishSync([channel('a','PJSIP/201-1','a','bridge1','03055509999','03055508888'),channel('b','PJSIP/trunk-2','a','bridge1','03055509999')],[]);$c=$s->view()['calls'][0];eq($c['number'],'03055508888');eq($c['direction'],'outgoing');eq($c['state'],'connected');eq($c['duration_seconds'],null); });
test('HTTP Origin, CSRF, Host und GET-Seiteneffekt', function () { $runtime=new Runtime(Config::validate(config()),true);$api=new HttpApi($runtime);$request=new ServerRequest('GET','http://localhost:8765/api/v1/bootstrap',[],'','1.1',['REMOTE_ADDR'=>'127.0.0.1']);eq($api($request)->getStatusCode(),200);eq($api($request->withUri(new React\Http\Message\Uri('http://evil.test/api/v1/bootstrap')))->getStatusCode(),403);eq($api($request->withHeader('Origin','https://evil.test'))->getStatusCode(),403);eq($api($request->withMethod('POST'))->getStatusCode(),403);$r=$api(new ServerRequest('GET','http://localhost:8765/patient-oeffnen?telefon=03055501111',[],'','1.1',['REMOTE_ADDR'=>'127.0.0.1']));eq($r->getStatusCode(),200); });
test('Bootstrap liefert auch numerische Client-IDs als unveränderte Zeichenketten', function () {
 $c=config();$c['clients']=[];$expected=[];
 foreach(['201','0','001','1','pc-201'] as $id) {
  $client=['label'=>'Arbeitsplatz '.$id,'extension'=>'201','directory'=>$id];
  $c['clients'][$id]=$client;$expected[]=['id'=>$id,'label'=>$client['label'],'extension'=>'201'];
 }
 $api=new HttpApi(new Runtime(Config::validate($c),true));
 $response=$api(new ServerRequest('GET','http://localhost:8765/api/v1/bootstrap',[],'','1.1',['REMOTE_ADDR'=>'127.0.0.1']));
 eq($response->getStatusCode(),200);
 eq(json_decode((string)$response->getBody(),true,64,JSON_THROW_ON_ERROR)['clients'],$expected);
});
test('APS-Nummernkollision mit führender Null verlangt Auswahl', fn()=>eq(Aps::classify([[patient(305551111)]],['0305551111'])['status'],'ambiguous'));
test('Frühe Medienbrücke zählt nicht als verbunden', function () { $s=new Telephony(config()['telephony']); $s->beginSync();$a=channel('a','PJSIP/201-1','a','b');$b=channel('b','PJSIP/trunk-2','a','b');$b['ChannelStateDesc']='Ring';$s->finishSync([$a,$b],[]);eq($s->bridged('a','b'),false);eq($s->view()['calls'][0]['state'] === 'connected',false); });
test('Beantworteter Local-Zwischenkanal allein bedeutet kein Patientengespräch', function () { $s=new Telephony(config()['telephony']);$s->beginSync();$s->finishSync([channel('a','PJSIP/201-1','a','b1'),channel('b','Local/out@route-01;1','a','b1'),channel('c','Local/out@route-01;2','a','b2'),channel('d','PJSIP/trunk-2','a')],[]);eq($s->view()['calls'][0]['state'] === 'connected',false); });
require __DIR__ . '/transfers.php';
require __DIR__ . '/outgoing.php';
\React\EventLoop\Loop::stop();
echo "$passed bestanden, $failed fehlgeschlagen\n";exit($failed ? 1 : 0);
