<?php
declare(strict_types=1);

use Kienzlefon\{CallStatistics,Config,Runtime,HttpApi};
use React\Http\Message\ServerRequest;

function statisticsEvent(int $number, float $entered, ?string $route = null): array
{
    $event = ['Event'=>'UserEvent','UserEvent'=>$route === null ? 'KienzlefonCallEntered' : 'KienzlefonCallRoute',
        'SchemaVersion'=>'1','PbxId'=>'practice-test','CallId'=>sprintf('11111111-2222-4333-8444-%012d', $number),'EnteredAt'=>(string)(int)$entered];
    if ($route !== null) $event['Route'] = $route;
    return $event;
}

function statisticsFixture(callable $test, string $time = '2026-11-17T12:00:00+01:00'): void
{
    $dir = sys_get_temp_dir() . '/kf-statistics-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
    $now = (float)strtotime($time);
    $clock = static function () use (&$now): float { return $now; };
    $config = ['enabled'=>true,'database_path'=>$dir . '/statistics.sqlite','timezone'=>'Europe/Berlin','pbx_id'=>'practice-test'];
    $statistics = new CallStatistics($config, false, $clock, static fn() => null);
    try { $test($statistics, $config, $clock, $now, $dir); }
    finally { foreach (glob($dir . '/*') as $path) unlink($path); rmdir($dir); }
}

function routeCount(array $today, string $route): int
{
    return array_column($today['routes'], 'incoming_calls', 'id')[$route];
}

test('Statistik: alter Konfigurationsstand bleibt ohne Datenzugriff deaktiviert', function () {
    $c = config(); unset($c['call_statistics']);
    eq(Config::validate($c)['call_statistics']['enabled'], false);
    $s = new CallStatistics(['enabled'=>false,'database_path'=>'/unavailable/statistics.sqlite','pbx_id'=>'']);
    $s->connected(); $s->event(statisticsEvent(1, time())); $s->checkpoint(); $s->stop();
    $today = $s->view()['today'];
    eq($today['status'], 'unavailable'); eq($today['reason_code'], 'not_configured');
    eq($today['incoming_calls'], null); eq($today['routes'], null); eq($today['as_of'], null);
});

test('Statistik: Konfiguration trennt FHIR, Webroot, GDT und sichere Anlagenkennung', function () {
    foreach (['/srv/kienzlefon-GDT/statistics.sqlite', dirname(__DIR__, 2) . '/public/statistics.sqlite', '/opt/kienzlefon-t2med/private/statistics.sqlite', '/tmp/../bad.sqlite', '/tmp/bad.sqlite/', '/tmp/file|bad'] as $path) {
        $c = config(); $c['call_statistics']['database_path'] = $path; rejects(fn()=>Config::validate($c));
    }
    $c = config(); $c['call_statistics']['database_path'] = $c['fhir']['database']; rejects(fn()=>Config::validate($c));
    $c = config(); $c['fhir']['database'] = $c['call_statistics']['database_path'] . '-journal'; rejects(fn()=>Config::validate($c));
    $c = config(); $c['call_statistics']['enabled'] = true; rejects(fn()=>Config::validate($c));
    $c['call_statistics']['pbx_id'] = "host\r\nInjected: true"; rejects(fn()=>Config::validate($c));
    $c = config(); $c['call_statistics']['timezone'] = 'UTC'; rejects(fn()=>Config::validate($c));
});

test('Statistik: genau ein Eingang, beliebig viele schreibfreie GETs', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $s->event(statisticsEvent(1, $now)); $s->event(statisticsEvent(1, $now));
    $before = hash_file('sha256', $config['database_path']); $asOf = $s->view()['today']['as_of'];
    $now += 5;
    for ($i=0; $i<20; $i++) { $today=$s->view()['today']; eq($today['incoming_calls'],1); eq($today['as_of'],$asOf); }
    eq(hash_file('sha256',$config['database_path']),$before);
    eq(fileperms($config['database_path']) & 0777, 0600);
    eq($today['unclassified_calls'],1); eq($today['reason_code'],'initial_partial_day');
}));

test('Statistik: lückenlos ab Tagesbeginn ist null eine bekannte Zahl', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $now+=120; $s->checkpoint();
    $today=$s->view()['today']; eq($today['status'],'complete'); eq($today['incoming_calls'],0); eq($today['reason_code'],null);
}, '2026-11-17T00:00:00+01:00'));

test('Statistik: FHIR-Bestandswechsel erhält Statistikdatei und Zählschlüssel', fn()=>statisticsFixture(function ($s, $config, $clock, &$now, $dir) {
    $s->connected(); $s->event(statisticsEvent(1,$now,'queue'));
    $before=hash_file('sha256',$config['database_path']); $c=config(); $c['fhir']['database']=$dir.'/patients.sqlite';
    $directory=new Kienzlefon\PatientDirectory($c['fhir']);
    $directory->replace([directoryPatient('one',8100)]); $directory->replace([directoryPatient('two',8101)]);
    eq(hash_file('sha256',$config['database_path']),$before); eq($s->view()['today']['incoming_calls'],1);
    $s->event(statisticsEvent(1,$now,'queue')); eq(routeCount($s->view()['today'],'queue'),1);
}));

test('Statistik: anonym und gleiche Rufnummer zählen je technischem Eingang', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected();
    foreach ([1,2,3,4] as $id) $s->event(statisticsEvent($id,$now)+['CallerIDNum'=>$id<3?'anonymous':'03055501111','CallerIDName'=>'PRIVATE_TEST_NAME']);
    eq($s->view()['today']['incoming_calls'],4);
    $data=file_get_contents($config['database_path']);
    eq(str_contains($data,'PRIVATE_TEST_NAME'),false); eq(str_contains($data,'03055501111'),false);
}));

test('Statistik: interne, ausgehende und Snapshot-Ereignisse zählen nie', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected();
    foreach (['Newchannel','CoreShowChannel','QueueCallerJoin','DialBegin','BridgeEnter','OriginateResponse','Hangup','VarSet'] as $type) {
        $event=statisticsEvent(1,$now); $event['Event']=$type; $s->event($event);
    }
    $s->event(['Event'=>'UserEvent','UserEvent'=>'KienzlefonClickToDialProgress']);
    eq($s->view()['today']['incoming_calls'],0);
}));

test('Statistik: Wege mehrfach besucht, Anruf nur einmal; weitere Wege getrennt', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $s->event(statisticsEvent(1,$now));
    foreach (['ivr','ai','queue','callback','queue','ai','opening_hours'] as $route) $s->event(statisticsEvent(1,$now,$route));
    $today=$s->view()['today']; eq($today['incoming_calls'],1); eq($today['unclassified_calls'],0);
    foreach (['ivr','ai','queue','callback','opening_hours'] as $route) eq(routeCount($today,$route),1);
    eq(routeCount($today,'prescription'),0);
    eq(array_sum(array_column($today['routes'],'incoming_calls')),5);
    $s->event(statisticsEvent(2,$now,'prescription')); // Route can carry the missed original ingress.
    eq($s->view()['today']['incoming_calls'],2); eq(routeCount($s->view()['today'],'prescription'),1);
}));

test('Statistik: fremde, manipulierte und widersprüchliche Marker verändern keine Eingänge', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected();
    foreach ([['PbxId'=>'other'],['SchemaVersion'=>'2'],['CallId'=>'not-a-uuid'],['EnteredAt'=>'1'],['EnteredAt'=>(string)($now+120)],['EnteredAt'=>'now']] as $change) $s->event(array_replace(statisticsEvent(1,$now),$change));
    $s->event(statisticsEvent(1,$now,'bad-route'));
    eq($s->view()['today']['incoming_calls'],0);
    $s->event(statisticsEvent(1,$now));
    $s->event(statisticsEvent(1,$now-86400,'queue'));
    $today=$s->view()['today']; eq($today['incoming_calls'],1); eq(routeCount($today,'queue'),0); eq($today['reason_code'],'capture_gap');
}));

test('Statistik: Anlagenkennung trennt identische technische IDs', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->event(statisticsEvent(1,$now));
    $config['pbx_id']='other'; $other=new CallStatistics($config,false,$clock,static fn()=>null);
    $other->event(array_replace(statisticsEvent(1,$now),['PbxId'=>'other']));
    eq($s->view()['today']['incoming_calls'],1); eq($other->view()['today']['incoming_calls'],1);
    $db=new PDO('sqlite:'.$config['database_path']); eq((int)$db->query('SELECT COUNT(*) FROM calls')->fetchColumn(),2);
}));

test('Statistik: Neustart, Replay und Weiterleitung nach Mitternacht erhalten Eingangstag', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $entered=$now; $event=statisticsEvent(1,$entered); $s->event($event);
    $now+=120;
    $restarted=new CallStatistics($config,false,$clock,static fn()=>null); $restarted->connected();
    $restarted->event($event); $restarted->event(statisticsEvent(1,$entered,'queue'));
    $today=$restarted->view()['today']; eq($today['date'],'2026-11-18'); eq($today['incoming_calls'],0); eq(routeCount($today,'queue'),0); eq($today['status'],'partial');
    $db=new PDO('sqlite:'.$config['database_path']); eq($db->query('SELECT day FROM calls')->fetchColumn(),'2026-11-17'); eq((int)$db->query('SELECT COUNT(*) FROM routes')->fetchColumn(),1);
}, '2026-11-17T23:59:00+01:00'));

test('Statistik: Abbruch vor Annahme zählt weiter und löst keine zweite Zählung aus', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $s->event(statisticsEvent(1,$now)); $s->event(['Event'=>'Hangup','Uniqueid'=>'1','Cause'=>'16']);
    eq($s->view()['today']['incoming_calls'],1); eq($s->view()['today']['unclassified_calls'],1);
}));

test('Statistik: Erststart, Ausfall und Reconnect bleiben unvollständig; späterer Tag erholt sich', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); eq($s->view()['today']['reason_code'],'initial_partial_day');
    $s->event(statisticsEvent(1,$now)); $asOf=$s->view()['today']['as_of'];
    $now+=60; $s->disconnected(); $now+=300;
    eq($s->view()['today']['as_of'],$asOf); eq($s->view()['today']['reason_code'],'capture_gap');
    $s->connected(); eq($s->view()['today']['reason_code'],'capture_gap'); eq($s->view()['today']['incoming_calls'],1);
    $now=(float)strtotime('2026-11-18T12:00:00+01:00'); $s->checkpoint();
    eq($s->view()['today']['status'],'complete'); eq($s->view()['today']['incoming_calls'],0);
}));

test('Statistik: Ausfall über Mitternacht bleibt an beiden Tagen partiell', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $s->event(statisticsEvent(1,$now)); $now+=30; $s->disconnected();
    eq($s->view()['today']['status'],'partial');
    $now+=120; $s->connected();
    eq($s->view()['today']['status'],'partial'); eq($s->view()['today']['reason_code'],'capture_gap');
}, '2026-11-17T23:59:00+01:00'));

test('Statistik: Prozessabsturz behält Zahl und markiert Lücke dauerhaft', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $s->event(statisticsEvent(1,$now)); $now+=3600;
    $restarted=new CallStatistics($config,false,$clock,static fn()=>null); $restarted->connected(); $restarted->checkpoint();
    eq($restarted->view()['today']['incoming_calls'],1); eq($restarted->view()['today']['reason_code'],'capture_gap');
    $restarted->stop(); $now+=1;
    $again=new CallStatistics($config,false,$clock,static fn()=>null); $again->connected();
    eq($again->view()['today']['reason_code'],'capture_gap');
}));

test('Statistik: lokale Tagesgrenzen bilden 23- und 25-Stunden-Tage ab', function () {
    foreach (['2026-03-29T12:00:00+02:00'=>23,'2026-10-25T12:00:00+01:00'=>25] as $time=>$hours) statisticsFixture(function ($s) use ($hours) {
        $today=$s->view()['today']; eq((strtotime($today['period_end'])-strtotime($today['period_start']))/3600,$hours);
    },$time);
});

test('Statistik: Datenbankdefekt und fehlendes Verzeichnis isoliert, API bleibt nutzbar', fn()=>statisticsFixture(function ($s, $config, $clock, &$now, $dir) {
    $broken=$dir.'/broken.sqlite'; file_put_contents($broken,'not sqlite'); $config['database_path']=$broken;
    $bad=new CallStatistics($config,false,$clock,static fn()=>null); $bad->connected();
    eq($bad->view()['today']['reason_code'],'storage_unavailable'); eq($bad->view()['today']['incoming_calls'],null);
    eq(str_contains(json_encode($bad->view()),$dir),false);
    $config['database_path']=$dir.'/missing/data.sqlite'; $bad=new CallStatistics($config,false,$clock,static fn()=>null);
    eq($bad->view()['today']['reason_code'],'storage_unavailable');
    $runtimeConfig=Config::validate(config()); $runtimeConfig['call_statistics']=$config;
    $runtime=new Runtime($runtimeConfig); $api=new HttpApi($runtime);
    $response=$api(new ServerRequest('GET','http://localhost:8766/api/v1/telephony',[],'','1.1',['REMOTE_ADDR'=>'127.0.0.1']));
    eq($response->getStatusCode(),200); $body=json_decode((string)$response->getBody(),true);
    eq($body['call_statistics']['today']['reason_code'],'storage_unavailable'); eq(isset($body['extensions']),true);
}));

test('Statistik: fremde SQLite-Datei und Symlink bleiben unangetastet', fn()=>statisticsFixture(function ($s, $config, $clock, &$now, $dir) {
    $foreign=$dir.'/patients.sqlite'; $db=new PDO('sqlite:'.$foreign); $db->exec('CREATE TABLE patients(id TEXT)'); $before=hash_file('sha256',$foreign);
    $config['database_path']=$foreign; $bad=new CallStatistics($config,false,$clock,static fn()=>null);
    eq($bad->view()['today']['reason_code'],'storage_unavailable'); eq(hash_file('sha256',$foreign),$before);
    symlink($foreign,$dir.'/link.sqlite'); $config['database_path']=$dir.'/link.sqlite';
    $bad=new CallStatistics($config,false,$clock,static fn()=>null); eq($bad->view()['today']['reason_code'],'storage_unavailable'); eq(hash_file('sha256',$foreign),$before);
}));

test('Statistik: Sperre blockiert begrenzt, danach bleibt Verlust als Lücke erhalten', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $s->event(statisticsEvent(1,$now));
    $locker=new PDO('sqlite:'.$config['database_path']); $locker->exec('BEGIN EXCLUSIVE');
    $started=microtime(true); $s->event(statisticsEvent(2,$now)); eq(microtime(true)-$started<0.25,true);
    eq($s->view()['today']['status'],'unavailable'); $locker->exec('ROLLBACK');
    $now+=60; $s->checkpoint(); $today=$s->view()['today'];
    eq($today['incoming_calls'],1); eq($today['reason_code'],'capture_gap');
    $s->event(statisticsEvent(2,$now-60)); eq($s->view()['today']['incoming_calls'],2); eq($s->view()['today']['status'],'partial');
}));

test('Statistik: fehlgeschlagene Weg-Transaktion hinterlässt keinen halben Eingang', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $s->connected(); $db=new PDO('sqlite:'.$config['database_path']);
    $db->exec("CREATE TRIGGER fail_route BEFORE INSERT ON routes BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
    $s->event(statisticsEvent(1,$now,'ai'));
    eq((int)$db->query('SELECT COUNT(*) FROM calls')->fetchColumn(),0);
    $db->exec('DROP TRIGGER fail_route'); $now+=1; $s->checkpoint();
    $s->event(statisticsEvent(1,$now,'ai')); eq($s->view()['today']['incoming_calls'],1); eq(routeCount($s->view()['today'],'ai'),1);
}));

test('Statistik: konkurrierende Schreiber bleiben idempotent', fn()=>statisticsFixture(function ($s, $config, $clock, &$now) {
    $other=new CallStatistics($config,false,$clock,static fn()=>null);
    foreach (range(1,10) as $id) { $s->event(statisticsEvent($id,$now,'queue')); $other->event(statisticsEvent($id,$now,'queue')); }
    eq($s->view()['today']['incoming_calls'],10); eq(routeCount($other->view()['today'],'queue'),10);
}));

test('Statistik: Demo öffnet produktiven Pfad nie und liefert synthetische API-Werte', function () {
    $path=sys_get_temp_dir().'/kf-demo-no-statistics-'.bin2hex(random_bytes(5));
    $config=Config::validate(config()); $config['call_statistics']=['enabled'=>true,'database_path'=>$path.'/file.sqlite','timezone'=>'Europe/Berlin','pbx_id'=>'practice-test'];
    $runtime=new Runtime($config,true); $runtime->start();
    $api=new HttpApi($runtime); $request=new ServerRequest('GET','http://localhost:8766/api/v1/telephony',[],'','1.1',['REMOTE_ADDR'=>'127.0.0.1']);
    $body=json_decode((string)$api($request)->getBody(),true); eq($body['call_statistics']['today']['incoming_calls'],47); eq(is_dir($path),false);
    eq($api($request->withHeader('Origin','https://evil.example'))->getStatusCode(),403);
    $runtime->stop();
});
