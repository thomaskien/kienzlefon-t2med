#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Kienzlefon\{Config,TelephonyControl};
use React\EventLoop\Loop;
$options=getopt('',['config:']);$ok=false;
try {
    $config=Config::load($options['config']??Config::defaultPath());
    Config::checkServiceKeys($config);
    if (!$config['telephony_control']['enabled']) { echo "Anlagensteuerung deaktiviert; keine Verbindung geprüft.\n"; exit(0); }
    $control=new TelephonyControl($config);
    $control->refresh()->then(function()use($control,&$ok){
        $ok=$control->connected();
        $v=$control->view([],null,[]);
        echo ($ok?'OK: ':'FEHLER: ')."Anlagen-Control-API / Identität / Vertrag.\n";
        echo 'Live-Zahlen: '.$v['live_counters']['status'].'; Rufgruppen: '.($v['routing_control']['available']?'aktuell':$v['routing_control']['reason_code'])."\n";
        Loop::stop();
    });
    Loop::run();
} catch(Throwable) { fwrite(STDERR,"Dienstkonfiguration, private Schlüsseldateien und Erreichbarkeit prüfen.\n"); }
exit($ok?0:1);
