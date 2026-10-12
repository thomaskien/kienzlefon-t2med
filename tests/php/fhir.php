<?php
declare(strict_types=1);

use Kienzlefon\{PatientDirectory,Patients};

function directoryFixture(callable $fn): void {
    $path = sys_get_temp_dir() . '/kf-directory-' . bin2hex(random_bytes(8));
    $c = config(); $c['fhir']['database'] = $path . '/patients.sqlite';
    try { $fn(new PatientDirectory($c['fhir']), $c); }
    finally { foreach (glob($path . '/*') ?: [] as $file) if (is_file($file) || is_link($file)) unlink($file); foreach (glob($path . '/.patients-*') ?: [] as $file) unlink($file); if (is_dir($path)) rmdir($path); }
}
function directoryPatient(string $id, ?int $number, array $phones = ['030 55501111']): array {
    return ['id'=>$id,'patient_number'=>$number,'first_name'=>'Test','last_name'=>'Person','phones'=>$phones];
}
test('FHIR ist Standard; alte APS-Konfiguration bleibt erhalten und deaktiviert', function () {
    $c = config(); unset($c['patients'], $c['fhir']); $c['aps']['password'] = 'retained';
    $v = Kienzlefon\Config::validate($c); eq($v['patients']['provider'], 'fhir'); eq($v['aps']['password'], 'retained');
    eq($v['fhir']['base_url'], rtrim($c['aps']['url'],'/') . '/aps/fhir/api/r4');
});
test('Patientenbestand darf nicht im GDT-Share oder Webverzeichnis liegen', function () {
    $c = config(); $c['fhir']['database'] = $c['gdt']['root'] . '/patients.sqlite'; rejects(fn()=>Kienzlefon\Config::validate($c));
    $c['fhir']['database'] = dirname(__DIR__,2) . '/public/patients.sqlite'; rejects(fn()=>Kienzlefon\Config::validate($c));
});
test('FHIR-Ortsnummern erhalten nur mit konfigurierter Vorwahl einen vollständigen Suchschlüssel', function () {
    eq(PatientDirectory::phoneKey('55501111','49',''),null);
    eq(PatientDirectory::phoneKey('55501111','49','030'),'+493055501111');
    eq(PatientDirectory::phoneKey('+49 (0)30 55501111','49',''),'+493055501111');
    eq(PatientDirectory::phoneKey('03055501111 oder 03055502222','49',''),null);
});
test('FHIR-Ortsnummern passen vollständig ans nationale Rufnummernende, Werte unter drei Ziffern nicht', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('local',8100,['5550 1111','tel:55501111']), directoryPatient('short',8101,['12']), directoryPatient('full',8102,['03055509999'])]);
    foreach (['03055501111','+493055501111','00493055501111','493055501111','04055501111','55501111'] as $value) {
        $r=$d->lookup($value); eq($r['status'],'resolved'); eq(array_column($r['patients'],'id'),['local']);
    }
    foreach (['03055501112','+443055501111','03055501234','1234','04055509999','030555011110','+4955501111'] as $value) eq($d->lookup($value)['status'],'not_found');
    eq($d->status()['phone_count'],4); eq($d->status()['suffix_phone_count'],2); eq($d->status()['unusable_phone_count'],1); eq($d->status()['patients_with_phone'],2);
}); });
test('Dreistellige und unterschiedlich lange Ortsnummern bieten sämtliche passenden Akten an', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('three',8100,['111']),directoryPatient('four',8101,['2111']),directoryPatient('six',8102,['552111']),directoryPatient('complete',8103,['030552111']),directoryPatient('test-value',8104,['1234'])]);
    $r=$d->lookup('030552111'); eq($r['status'],'ambiguous'); eq(count($r['patients']),4); eq(array_column($r['patients'],'id'),['complete','four','six','three']);
    eq($d->lookup('040999111')['patients'][0]['id'],'three'); eq($d->lookup('111')['patients'][0]['id'],'three'); eq($d->lookup('030991234')['patients'][0]['id'],'test-value');
    eq($d->lookup('030552112')['status'],'not_found'); eq($d->status()['patients_with_phone'],5); eq($d->status()['unusable_phone_count'],0); eq($d->status()['suffix_phone_count'],4);
}); });
test('Kurze vollständige nationale Rufnummern bleiben extern, Nebenstellen bleiben intern', function () {
    foreach (['030111','+4930111','004930111'] as $n) eq(Kienzlefon\Phone::external($n),Kienzlefon\Phone::normalize($n));
    foreach (['111','03011','555111'] as $n) eq(Kienzlefon\Phone::external($n),'');
    eq(Kienzlefon\Phone::external('030111',['030111'=>'interne Nebenstelle']),'');
});
test('Kurze Vollnummern ohne Plus liefern sowohl vollständige als auch lokale Kandidaten', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('local',8100,['111']),directoryPatient('full',8101,['030111'])]);
    foreach (['4930111','030111','+4930111','004930111'] as $n) {
        $r=$d->lookup($n); eq($r['status'],'ambiguous'); eq(array_column($r['patients'],'id'),['full','local']);
    }
    $d->replace([directoryPatient('bare',8102,['4930111'])]);
    eq($d->lookup('0304930111')['patients'][0]['id'],'bare'); eq($d->lookup('4930111')['patients'][0]['id'],'bare');
}); });
test('Unbrauchbare internationale Kurzwerte verhindern keinen vollständigen FHIR-Import', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('local',8100,['111','000','0012'])]);
    eq($d->lookup('030111')['status'],'resolved'); eq($d->status()['phone_count'],3); eq($d->status()['unusable_phone_count'],2);
}); });
test('FHIR-Endvergleich liefert alle Patienten einschließlich vollständiger und überlappender Treffer', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('local',8100,['55501111']), directoryPatient('full',8101,['03055501111']), directoryPatient('overlap',8102,['5501111'])]);
    $r=$d->lookup('03055501111'); eq($r['status'],'ambiguous'); eq($r['name'],''); eq(count($r['patients']),3);
    $d->replace([directoryPatient('same',8100,['55501111','03055501111','5501111'])]);
    eq($d->lookup('03055501111')['status'],'resolved'); eq(count($d->lookup('03055501111')['patients']),1);
}); });
test('Ausdrücklich konfigurierte Ortsvorwahl bleibt für lokale Nummern maßgeblich', function () { directoryFixture(function ($d,$c) {
    $c['fhir']['local_area_code']='030'; $d=new PatientDirectory($c['fhir']);
    $d->replace([directoryPatient('local',8100,['55501111'])]);
    eq($d->lookup('03055501111')['status'],'resolved'); eq($d->lookup('04055501111')['status'],'not_found'); eq($d->status()['suffix_phone_count'],0);
}); });
test('FHIR-Endvergleich nutzt vorhandene Version-1-Dateien ohne Import oder Änderung der Datei', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('local',8100,['55501111'])]);
    $db=new PDO('sqlite:'.$c['fhir']['database']); $meta=json_decode($db->query('SELECT value FROM metadata')->fetchColumn(),true);
    unset($meta['suffix_phone_count']); $meta['patients_with_phone']=0; $meta['unusable_phone_count']=1; $meta['imported_at']='2026-10-01T08:00:00+00:00';
    $db->prepare('UPDATE metadata SET value=?')->execute([json_encode($meta)]); $db=null; $before=file_get_contents($c['fhir']['database']);
    $d=new PatientDirectory($c['fhir']); eq($d->lookup('03055501111')['status'],'resolved'); eq($d->status()['patients_with_phone'],1); eq($d->status()['unusable_phone_count'],0); eq($d->status()['suffix_phone_count'],1); eq($d->status()['imported_at'],$meta['imported_at']);
    eq(file_get_contents($c['fhir']['database']),$before);
    $d->replace([]); eq($d->lookup('03055501111')['status'],'not_found'); eq($d->status()['suffix_phone_count'],0);
}); });
test('Vollständiger FHIR-Bestand überlebt Neustart und sucht alle Rufnummernvarianten', function () { directoryFixture(function ($d,$c) {
    rejects(fn()=>$d->lookup('03055501111'));
    $d->replace([directoryPatient('resource-abc',8100,['030 55501111','+49 30 55501111','ortsnummer']),directoryPatient('no-phone',8131,[])]);
    eq($d->status()['patient_count'],2); eq($d->status()['unusable_phone_count'],1); eq(fileperms($c['fhir']['database'])&0777,0600);
    $d = new PatientDirectory($c['fhir']);
    foreach (['03055501111','+493055501111','00493055501111','493055501111'] as $n) { $r=$d->lookup($n); eq($r['status'],'resolved'); eq(count($r['patients']),1); eq($r['patients'][0]['id'],'resource-abc');eq($r['patients'][0]['patient_number'],8100); }
    $c['fhir']['base_url']='https://other.example/fhir'; eq((new PatientDirectory($c['fhir']))->status()['available'],false);
}); });
test('Familiennummern bleiben mehrdeutig; fehlende Patientennummer bleibt null', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('a',8100),directoryPatient('b',null)]);
    $r=$d->lookup('03055501111'); eq($r['status'],'ambiguous');eq($r['name'],'');eq(count($r['patients']),2);eq($r['patients'][1]['patient_number'],null);eq($d->status()['invalid_patient_numbers'],1);
    $runtime=new Kienzlefon\Runtime($c); $lookup=null; $runtime->lookup('03055501111')->then(function ($r) use (&$lookup) {$lookup=$r;});
    rejects(fn()=>$runtime->openPatient(['client_id'=>'empfang-1','lookup_id'=>$lookup['lookup_id'],'patient_number'=>null,'request_id'=>'12345678-1234-1234-1234-123456789abc']));
}); });
test('Doppelte IDs/Patientennummern erhalten bisherigen Bestand atomar', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('a',8100)]); $before=file_get_contents($c['fhir']['database']);
    rejects(fn()=>$d->replace([directoryPatient('a',8100),directoryPatient('a',8101)]));
    rejects(fn()=>$d->replace([directoryPatient('a',8100),directoryPatient('b',8100)]));
    eq(file_get_contents($c['fhir']['database']),$before);eq($d->lookup('03055501111')['status'],'resolved');eq(glob(dirname($c['fhir']['database']).'/.patients-*'),[]);
    $d->replace([]);eq($d->status()['complete'],true);eq($d->lookup('03055501111')['status'],'not_found');
}); });
test('Manuelle Namenssuche findet Teilnamen, umgekehrte Reihenfolge und Umlaute ohne Telefon', function () { directoryFixture(function ($d,$c) {
    $a=directoryPatient('anne',8100,[]); $a['first_name']='Anne-Marie'; $a['last_name']='Müller';
    $b=directoryPatient('anna',8101,[]); $b['first_name']='Anna'; $b['last_name']='Müller';
    $g=directoryPatient('joerg',null,[]); $g['first_name']='Jörg'; $g['last_name']='Groß';
    $d->replace([$a,$b,$g]);
    eq(count($d->search('mÜlLeR')['patients']),2); eq($d->search('müll')['status'],'ambiguous');
    foreach (['mueller anne','Müller, Anne',"Mu\u{0308}ller Anne",'  ANNE   MÜLLER  '] as $q) eq(array_column($d->search($q)['patients'],'id'),['anne']);
    eq($d->search('gross joerg')['patients'][0]['patient_number'],null);
    foreach (['Nobody','%','_müller',"' OR 1=1 --"] as $q) eq($d->search($q)['status'],'not_found');
}); });
test('Manuelle Zahlensuche vereinigt Patientennummer und Telefon ohne Doppelung', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('id-match',8100,[]),directoryPatient('phone-match',8101,['8100']),directoryPatient('both',8102,['8102'])]);
    eq(count($d->search('8100')['patients']),2); eq($d->search('8100')['status'],'ambiguous');
    eq(array_column($d->search('08100')['patients'],'id'),['id-match']);
    eq(count($d->search('8102')['patients']),1); eq($d->search('810')['status'],'not_found');
}); });
test('Namenssuche begrenzt nur breite Namenstreffer und erhält sämtliche Telefontreffer', function () { directoryFixture(function ($d,$c) {
    $people=[]; for($i=1;$i<=205;$i++) { $p=directoryPatient('p-'.$i,8100+$i,['111']); $p['last_name']='Suchname'; $p['first_name']='Vorname'.$i; $people[]=$p; }
    $d->replace($people);
    $r=$d->search('Suchname'); eq(count($r['patients']),200); eq($r['truncated'],true);
    $r=$d->search('Suchname Vorname205'); eq(count($r['patients']),1); eq($r['truncated'],false);
    $r=$d->search('030111'); eq(count($r['patients']),205); eq($r['truncated'],false);
}); });
test('Namenssuche nutzt alte Dateien ohne Änderung und ersetzt ihren Index beim Import', function () { directoryFixture(function ($d,$c) {
    $p=directoryPatient('one',8100,[]); $p['last_name']='Altname'; $d->replace([$p]); $before=file_get_contents($c['fhir']['database']);
    $d=new PatientDirectory($c['fhir']); eq($d->search('Altname')['status'],'resolved'); eq(file_get_contents($c['fhir']['database']),$before);
    $p['last_name']='Neuname'; $d->replace([$p]); eq($d->search('Altname')['status'],'not_found'); eq($d->search('Neuname')['status'],'resolved');
}); });
test('Suchtext wird begrenzt und ungültige Eingaben liefern keine vollständige Patientenliste', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('one',8100)]);
    foreach (['','   ',str_repeat('x',257),"Name\nZwei","\xff"] as $q) rejects(fn()=>$d->search($q));
}); });
test('Manuelle Suche verändert keine Anruferkennung und bindet Aktenauswahl an Ergebnis', function () { directoryFixture(function ($d,$c) {
    $d->replace([directoryPatient('one',810000000,[]),directoryPatient('two',8101,[])]); $runtime=new Kienzlefon\Runtime($c); $search=null;$call=null;
    $runtime->searchPatients('810000000')->then(function($r)use(&$search){$search=$r;});
    $runtime->lookup('810000000')->then(function($r)use(&$call){$call=$r;});
    eq($search['status'],'resolved'); eq($call['status'],'not_found');
    rejects(fn()=>$runtime->openPatient(['client_id'=>'empfang-1','lookup_id'=>$search['lookup_id'],'patient_number'=>8101,'request_id'=>'12345678-1234-1234-1234-123456789abc']));
}); });
test('Manuelle Such-API verlangt CSRF und gültigen Text, Demo unterstützt Namen', function () {
    $runtime=new Kienzlefon\Runtime(Kienzlefon\Config::validate(config()),true); $api=new Kienzlefon\HttpApi($runtime);
    $base='http://localhost:8766'; $server=['REMOTE_ADDR'=>'127.0.0.1'];
    $bootstrap=$api(new React\Http\Message\ServerRequest('GET',$base.'/api/v1/bootstrap',[],'','1.1',$server)); $token=json_decode((string)$bootstrap->getBody(),true)['token'];
    $request=new React\Http\Message\ServerRequest('POST',$base.'/api/v1/patients/search',['Content-Type'=>'application/json'],'{"query":"becker"}','1.1',$server);
    eq($api($request)->getStatusCode(),403);
    $response=null; $api($request->withHeader('X-Kienzlefon-Token',$token))->then(function($r)use(&$response){$response=$r;});
    eq($response->getStatusCode(),200); $body=json_decode((string)$response->getBody(),true); eq(count($body['patients']),2); eq(isset($body['patients'][0]['_ref']),false);
    foreach (['{"query":[]}','{"query":""}'] as $input) {
        $bad=new React\Http\Message\ServerRequest('POST',$base.'/api/v1/patients/search',['Content-Type'=>'application/json','X-Kienzlefon-Token'=>$token],$input,'1.1',$server);
        eq($api($bad)->getStatusCode(),400);
    }
});
test('Beide Browserpfade liefern nur die Seite und spiegeln keine Zugangsdaten', function () {
    $api=new Kienzlefon\HttpApi(new Kienzlefon\Runtime(Kienzlefon\Config::validate(config()),true));
    foreach (['/telefonverzeichnis-aktualisieren','/telefonverzeichnis-aktualisieren.php'] as $path) {
        $r=$api(new React\Http\Message\ServerRequest('GET','http://localhost:8766'.$path.'?oAuthToken=test-secret',[],'','1.1',['REMOTE_ADDR'=>'127.0.0.1']));
        eq($r->getStatusCode(),200);eq(str_contains((string)$r->getBody(),'test-secret'),false);eq($r->getHeaderLine('Referrer-Policy'),'no-referrer');
    }
});

test('Wahlhilfe verwendet eindeutige FHIR-Namen und lässt sonst den Namen leer', function () { directoryFixture(function ($d,$c) {
    $p=directoryPatient('one',8100,['03055501111','111']); $p['first_name']='Jörg'; $p['last_name']='Müller';
    $d->replace([$p,directoryPatient('two',8101,['03055502222']),directoryPatient('three',8102,['03055502222']),directoryPatient('internal',8103,['201'])]);
    $runtime=new Kienzlefon\Runtime($c); $method=new ReflectionMethod($runtime,'dialCallerId');
    foreach (['03055501111','+493055501111','00493055501111','111'] as $number) eq($method->invoke($runtime,$number),'"Jörg Müller" <'.$number.'>');
    foreach (['03055502222','03055509999','201','8100'] as $number) eq($method->invoke($runtime,$number),'"" <'.$number.'>');
    $p['first_name']='Anne "Test"'; $p['last_name']='Name <123>'; $d->replace([$p]);
    $runtime=new Kienzlefon\Runtime($c); eq($method->invoke($runtime,'03055501111'),'"Anne  Test  Name  123" <03055501111>');
}); });
test('Wahlhilfe ohne FHIR-Bestand überträgt keinen Ersatznamen', function () { directoryFixture(function ($d,$c) {
    $runtime=new Kienzlefon\Runtime($c); eq((new ReflectionMethod($runtime,'dialCallerId'))->invoke($runtime,'03055509999'),'"" <03055509999>');
}); });
