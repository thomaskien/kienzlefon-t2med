# Tests und praktische Abnahme

Automatisierte Tests verwenden fiktive Daten sowie lokale AMI-, APS- und FHIR-Testserver. Sie kontaktieren keine Praxisserver, öffnen keine echte Akte und lösen keinen echten Anruf aus.

## Automatisierte Prüfung

Voraussetzungen sind PHP 8.2+, Composer-Abhängigkeiten, Python 3.11+ für den Fon-TOML-Import und seine Tests, Go, Node.js und OpenSSL.

```sh
composer install
php tests/php/run.php
php tests/installer/run.php
(cd fhir-importer && go test ./... && go vet ./...)
python3 -m unittest discover -s tests/clients -v
python3 -m unittest discover -s tests/installer -v
python3 -m unittest discover -s tests/integration -v
for script in scripts/pack.sh installer/install.sh installer/package-files.sh clients/install-linux.sh clients/install-macos.sh; do bash -n "$script" || exit 1; done
bash installer/install.sh --dry-run
```

Die Kern- und Integrationstests decken Konfiguration, FHIR-Pagination und atomaren Bestandswechsel, lokale Patienten- und Rufnummernsuche, AMI-Zustände, GDT, Click-to-Dial, Rückrufaufträge, Vermittlung, Samba-Planung und Rollback ab. Betriebssystemfunktionen wie Registry, LaunchServices, systemd und echtes Samba brauchen zusätzlich den jeweiligen Zielrechner.

`tests/php/call_statistics.php` prüft Tages-/Wegezähler, Deduplizierung, Mitternacht/DST, Ausfälle, private Speicherung, Transaktionsabbruch, Datenbanksperren, Demo und schreibfreie API-Lesezugriffe. Die AMI-Integration prüft Marker, Wege, Speicherfehler und Prozessneustart mit Replay. Für die reale Erfassung sind anschließend die neuen Dialplan-Hooks gemäß [Anrufstatistik](ANRUFSTATISTIK.md) einzubauen und dort mit synthetischen Ereignisfolgen zu prüfen; das Beispiel allein aktiviert keine Zählung.

## Abnahme des Kombi-Pakets am 18.11.2026

Geprüft wurde der lokale, unveröffentlichte Arbeitsstand auf macOS. Der Pakettest läuft innerhalb der Python-Installer-Suite.

| Prüfung | Ergebnis |
| --- | --- |
| Paket bauen und frisch entpacken | Genau ein Ordner `t2med/` mit 51 regulären Dateien. Alle Dateien waren bytegleich mit dem Arbeitsbaum, einschließlich Composer-Lockdatei, neuer PHP-Klassen, Schema, Clients und systemd-Unit. Beide FHIR-Binaries bestanden die SHA-256-Prüfung. |
| Erneut paketieren | Aus einem entpackten Baum ohne Git wurde eine lokale Codeänderung übernommen. Synthetische Konfigurationen, Exporte, Patientendateien, Testausgaben und der private APS-Helfer blieben außerhalb des Archivs. Bei fehlender Lockdatei blieb das vorherige Archiv erhalten. |
| Installer aus dem Paket | `bash installer/install.sh --dry-run` war mit und ohne gültige Übergabe erfolgreich. Composer installierte die 13 fixierten Abhängigkeiten. `php bin/server.php --config=config.example.toml --check-config` bestätigte die Konfiguration. |
| Fon-Import | Ein synthetischer Schema-v1-Export gemäß `kienzlefon/middleware.py` und `docs/MIDDLEWARE.md` des Fon-Projekts wurde für lokale TCP- und separate TLS-Verbindungen verarbeitet. IPv6 und optionale Felder wurden akzeptiert. Fehlerhafte, deaktivierte oder unzureichend geschützte Übergaben sowie der Konflikt mit `--keep-config` wurden abgewiesen. Die geprüften Ausgaben und der Shell-Aufruftrace enthielten kein Test-Secret. |
| Update | Die 12 TOML-Installertests liefen zusätzlich gegen den entpackten Installer mit temporären Bestandskonfigurationen. AMI-Wechsel, interaktive Neukonfiguration, JSON-Migration und bytegleicher TOML-Erhalt mit `--keep-config` bestanden. |
| Weitere Tests | 58 PHP-Installerprüfungen, 111 PHP-Kerntests, 35 Clienttests und 37 lokale Integrationstests bestanden. Von 30 Python-Installertests bestanden 29. Der Linux-spezifische Wildcard-Porttest wurde auf macOS übersprungen. Go-Tests, `go vet` und Shell-Syntaxprüfungen bestanden. |

Die praktische Linux-Abnahme mit laufendem systemd, Samba und einer Fon-Anlage steht aus. [Installationsorte und Aufrufe](INSTALLATION.md#optionale-dritte-komponente-des-kombi-installers)

### Samba-Vorprüfung für Paket 1.5.1 am 19.11.2026

Der Regressionstest führt den Samba-Prüfblock des Shell-Installers mit dem PHP-Planer aus. Ein simuliertes `testparm` liefert die Konfiguration auf stdout und die Rollenmeldung auf stderr. Im nachgestellten Kienzlefax-Fall mit `auto`, `ROLE_STANDALONE` und `Bad User` enthält die geplante Hauptkonfiguration genau eine zusätzliche Include-Zeile. Bei Domänenrolle, fehlender oder widersprüchlicher Meldung und fehlgeschlagenem Gesamtlauf entstand kein Konfigurationsplan.

Lokal auf macOS bestanden 68 PHP-Installerprüfungen, 32 von 33 Python-Installertests und 17 Middleware-Integrationstests. Der Linux-spezifische Porttest wurde übersprungen. Aus dem frisch entpackten Paket bestanden der Dry-Run mit synthetischem AMI-Export und alle drei Shell/PHP-Samba-Regressionstests. Der Frischtest mit echtem Samba auf der Ubuntu-VM gehört zur anschließenden Kombi-Abnahme.

### Arbeitsplätze und Telepraxis-App für Paket 1.5.3 am 23.11.2026

Die Paketprüfung des Kombi-Installers 1.9 erkannte Version 1.5.3. Alle 51 Dateien im Archiv waren bytegleich mit dem Arbeitsbaum. Die SHA-256 des geprüften Archivs lautet `9ad035e0ea5ccc67c3ccce53c02f4d47beaea784f95b269811388ff44c4529d4`.

Im frisch entpackten Paket bestanden der Dry-Run mit und ohne synthetische Fon-Übergabe. Ein ungültiger Nebenstelleneintrag wurde vor der Installation abgewiesen. Der Konfigurationslauf mit den 14 Nummern aus dem gemeldeten Fall und synthetischen Zugangsdaten erzeugte mit Enter alle 14 Zuordnungen. Der Installer lieferte für jede Nummer dasselbe GDT-Unterverzeichnis. Beim zweiten Durchlauf mit `202` und `Müller & Co` befüllte der erzeugte Startpfad die Felder der Telepraxis-App 3.7. Bei einem synthetischen Statuswechsel speicherte die App Platz `202` und den vollständigen Bearbeiternamen.

Auf macOS bestanden 68 PHP-Installerprüfungen, 111 PHP-Kernprüfungen, 35 Clienttests, 37 Integrationstests und 38 von 39 Python-Installertests. Der Linux-spezifische Porttest wurde übersprungen. Geprüft wurden unter anderem die FHIR-/APS-Vorgaben, aktivierte CA-Prüfung, Migration, Erhalt der Zertifikatseinstellung beim Update und führende Nullen in Arbeitsplatznummern. Die Ubuntu-VM wurde für die Diagnose lesend geprüft. Die Live-Installation von 1.5.3 mit Samba und systemd ist Teil der anschließenden Kombi-Abnahme.

### FHIR-IP, Nebenstellenmenü und App-Dienstschlüssel in 1.5.4

Die Paketprüfung des Kombi-Installers 1.9 erkannte am 23.11.2026 Version 1.5.4. Alle 51 Paketdateien stimmten mit dem Arbeitsbaum überein. Der Dry-Run aus dem Archiv bestand mit und ohne synthetische Fon-Übergabe. Die SHA-256 des geprüften Pakets lautet `575a1aa2cbf4dfbbe08d2a8d6f47feb117d5c3c45a94a755fb39f070a0bf7d6b`.

Mit den 14 Nebenstellen des gemeldeten Falls und synthetischen Zugangsdaten ergab die Eingabe `1 2 3 4 11` genau die fünf GDT-Ziele `201`, `202`, `203`, `204` und `212`. Die übrigen neun waren als inaktiv gespeichert. Die fünf Startpfade befüllten in der Telepraxis-App 3.7 jeweils Platz und Bearbeiter mit derselben Nummer. Aus `10.0.83.120` entstand die erwartete FHIR-Adresse mit Port 16567 und dem Pfad `/aps/fhir/api/r4`. Die Installertests prüften zusätzlich IPv6, Eingabekorrektur, den Erhalt einer individuellen Bestandsadresse und die Wiederaufnahme der Nebenstellenauswahl.

Der Schlüsseltest führte den Bereitstellungsabschnitt des Shell-Installers mit temporären Dateien aus. Die vollständige PHP-Konfigurationsprüfung bestand beim ersten Anlegen und beim erneuten Lauf mit demselben Schlüssel. Beide Schlüsselkopien hatten Modus 0600. Eine Datei mit Modus 0640 wurde abgewiesen. Unter macOS wurden die Linux-Benutzerwechsel für diesen Test nachgebildet.

Lokal bestanden 68 PHP-Installerprüfungen, 112 PHP-Kernprüfungen, 40 von 41 Python-Installertests und 37 Integrationstests. Der Linux-spezifische Porttest wurde auf macOS übersprungen. Der Live-Start unter systemd gehört zur anschließenden Kombi-Abnahme.

### Lokale Vollinstallation mit T2med 1.5.5 und Fon 2.6.10 am 23.11.2026

| Geprüfter Fall | Ergebnis |
| --- | --- |
| Frische Einrichtung | Der Fon-Installer übernimmt die automatisch erzeugte Anlagenkennung und aktiviert seinen lokalen Control-Dienst. Die Middleware richtet mit derselben Kennung die Tagesstatistik und einen Control-Leseschlüssel mit Modus 0600 ein. Der echte Fon-HTTP-Handler akzeptierte lesende Abfragen und lehnte einen Schaltauftrag dieses Schlüssels mit HTTP 403 ab. |
| Reparatur und Wiederholung | Bei eingerichtetem Fon-Control, leerer Fon-Statistikkennung und deaktivierten Middleware-Zählern übernahm der Installer die Control-Kennung. Der zweite Durchlauf behielt Schlüssel, vorhandene Identitäten, Konfiguration und SQLite-Inhalt. Widersprüchliche Kennungen und falsche Dialplan-Ausgaben wurden vor dem Veröffentlichen abgelehnt. Auch der Rückbau der beiden Fon-Dateien wurde geprüft. |
| App-Abnahme | Der PHP-Proxy aus dem lokalen App-Paket 3.7 fragte per HTTP die laufende Middleware mit synthetischen AMI- und Control-Gegenstellen ab. Drei Live-Nullen und der Berliner Teiltag mit null Anrufen bestanden die Prüfung. Ein synthetisches `KienzlefonCallEntered` erhöhte den Tageswert auf eins. Wiederholung und Dienstneustart erhielten diesen Wert. Control-Ausfall und deaktivierte Statistik lieferten `source_disconnected` und `not_configured`. |
| Telefonverzeichnis | Mit der bisherigen localhost-Hostliste ergab die Aktualisierungsseite HTTP 403. Für die simulierte Middleware `10.0.83.85` und das FHIR-Ziel `10.0.83.120` ergänzte die Reparatur genau die Middleware-IP. Die PHP-Seite war danach erreichbar. Der Integrationstest mit dieser Browseradresse, synthetischem Token und lokalem HTTPS-FHIR-Testserver erhielt HTTP 202 und importierte fünf Patienten. Fremder Ursprung und gesperrtes Quellnetz ergaben weiterhin 403. |
| Pakete | Der Paketprüfer des Universalinstallers entpackte beide Archive. Die 52 T2med-Dateien waren bytegleich mit dem Arbeitsstand. Der Dry-Run bestand im entpackten Baum im Standardmodus und im Vollmodus mit und ohne synthetische Fon-Übergabe. Das Fon-Paket enthielt 104 Dateien, sein Übergabehelfer war aus dem Archiv aufrufbar. |

Auf macOS bestanden 75 PHP-Installerprüfungen, 112 PHP-Kernprüfungen, 44 Python-Installertests, 39 Middleware-Integrationstests und 247 betroffene Fon-Tests. Ein weiterer Python-Installertest zur Linux-Portbindung wurde plattformbedingt übersprungen. Die Dialplan-Prüfung verwendete simulierte Asterisk-CLI-Ausgaben. Die Abnahme mit laufendem Asterisk, Linux-Dienstbenutzern und systemd steht aus.

Für den Paketpaartest verwendet `tests/installer/test_local_counters.py` den benachbarten Fon-Quellbaum oder `KIENZLEFON_SOURCE`. `tests/integration/test_control.py` verwendet das App-Archiv im benachbarten Kombi-Projekt oder `T2MED_TEST_APP_PACKAGE`. Für diese Abnahme waren beide verfügbar.

| Paket | SHA-256 |
| --- | --- |
| `t2med-1.5.5.tar.gz` | `c7ba48e89be8d9e22c0e8f30937ecbc202000fb55e271945aaf7d32d0b26d369` |
| `kienzlefon-2.6.10.tar.gz` | `3410c3b19106c8711bd0ecd29ed1082fb4eb236338caa35cbd25115a94eff3af` |

### Rufgruppen mit T2med 1.5.6 und Fon 2.6.11 am 23.11.2026

Der Integrationstest startet den PHP-Middlewareprozess und den HTTP-Control-Dienst aus Fon 2.6.11 auf Loopback. Die Anlagensteuerung verwendet ein simuliertes Queue-Inventar und private SQLite-Journale. Beide Testplätze waren in der Middleware für Click-to-Dial und GDT unregistriert.

| Fall | Verifiziertes Ergebnis |
| --- | --- |
| Alle Praxis-Arbeitsplätze | Beide Plätze entfernten Nebenstelle 201 „Empfang“ aus Normal und fügten sie wieder hinzu. 202 blieb Mitglied. Für ROT wurden beide Richtungen mit HTTP 403 abgewiesen. |
| Platz- und Praxisbindung | Fehlende oder abweichende Sitzungsheader, die fremde Auftrags-ID eines anderen Platzes und der Ergebnisabruf nach Entzug der Freigabe wurden abgewiesen. |
| Beschränkungen | Ausgewählter Platz zugelassen, nicht ausgewählter Platz gesperrt. Leere Auswahl, reine Leseberechtigung und global deaktivierte Fernbedienung sperrten die Änderung. Falsche Anlagenkennung sowie ein fehlerhafter oder fehlender Umfangsendpunkt erteilten keine Freigabe. |
| Neuinstallation und Update | Zwei private Schlüsseldateien und getrennte Fingerabdrücke. Der bisherige Statistikleser sowie ein individuell beschränkter Schaltzugang behielten Schlüssel und Rechte. Bei Wiederholung blieben die Konfiguration, Dienstzugänge und vorhandene Statistikdaten erhalten. Das Originalpaket Fon 2.6.10 richtete ausschließlich den bisherigen Lesezugang ein. |
| Paket | Der lokale Kombi-Paketprüfer erkannte 1.5.6. Alle 52 Dateien waren bytegleich mit dem Arbeitsbaum. Dry-Run im entpackten Baum bestand im Standardmodus und im Vollmodus mit und ohne synthetischen AMI-Import. SHA-256 `ebc2a2393de4ba98f0e2ec1108c5cf168a03ed54bfc188d079187ee55305c898`. |

Auf macOS bestanden 113 PHP-Kernprüfungen, 75 PHP-Installerprüfungen, 35 Clienttests, 49 Python-Installertests und 41 Integrationstests. Der Linux-spezifische Portbindungstest wurde übersprungen. Die App-seitige Umsetzung der Haken und die Abnahme mit laufendem Asterisk gehören in die anschließende Kombi-Prüfung.

## Browserprüfung

```sh
npm install --no-save --package-lock=false --ignore-scripts playwright@1.62.1
npx playwright install chromium
node tests/ui/compact.cjs
node tests/ui/control.cjs
node tests/ui/fhir.cjs
```

Die Browserfälle prüfen Ansichten, Patientensuche, Mehrfachtreffer, Nebenstellenstatus und -wahl, Rückruf- und Vermittlungsaktionen, FHIR-Übergabe, URL-Bereinigung, Neuladen ohne Wiederholung, Offline-Zustand und Tastaturfokus. `UI_BROWSER_CHANNEL=chrome` verwendet ein vorhandenes Chrome mit isoliertem Profil; `UI_SCREENSHOT=/absoluter/pfad/kompakt.png` speichert eine Ansicht mit fiktiven Daten.

## Prüfung auf dem Linux-Server

```sh
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/server.php --config=/etc/kienzlefon-t2med/config.toml --check-config
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/check-connections.php --config=/etc/kienzlefon-t2med/config.toml
```

Der zweite Befehl prüft im FHIR-Modus den lokalen Bestand und AMI, löst aber weder FHIR-Import, Originate noch GDT aus.

## Praktische Abnahme für 1.5.0

1. In T2med den FHIR-Abgleich einmal mit `?`, einmal mit `#` starten. Erfolg und erwartete Bestandszahl prüfen; einen Fehlerfall auslösen und bestätigen, dass der vorherige Bestand aktiv bleibt.
2. Bekannte, unbekannte und gemeinsam verwendete Rufnummern prüfen: bei der Wahlhilfe setzt nur eine eindeutige Zuordnung den echten Namen, sonst bleibt die Namensangabe leer; Mehrfachtreffer erscheinen vollständig in der UI.
3. Nach Name, Umlautumschrift, Patientennummer und Telefonnummer suchen. Nur einen freigegebenen Testpatienten öffnen; GDT muss dessen echte Patientennummer enthalten und im richtigen Arbeitsplatzverzeichnis landen.
4. Kompaktansicht prüfen: **FREI** grün, **klingelt** gelb, **BELEGT** rot. Andere freie konfigurierte Nebenstelle anklicken, eigenes Telefon abheben und erst danach den Zielruf erwarten. Eigene Nebenstelle, belegter eigener Anschluss und veralteter Zustand müssen die Aktion sperren.
5. Externe Wahl über Weblink sowie `tel:` und `callto:` prüfen: eigenes Telefon zuerst, Ziel danach; kein künstlicher Name `Rueckruf`/`Rückruf` und kein Nummerntext als Name. Besetzt, keine Antwort und Abbruch mitprüfen.
6. Rückrufaktionen, internes/externes Vermitteln direkt und mit Rückfrage sowie ungewisse Ergebnisse testen. Kein Auftrag darf automatisch wiederholt werden.
7. Untere Zeile der Kompaktansicht auf **kienzlefon v1.5.0 von Dr. Thomas Kienzle** prüfen. Verbindungsverlust darf keine alten Gesprächsdaten als aktuell zeigen.
8. GDT-Freigabe von jedem Arbeitsplatz lesen/löschen lassen und kontrollieren, dass Empfang 1 nicht Empfang 2 öffnet. Eine wartende Datei muss atomar durch den neuesten Aufruf desselben Arbeitsplatzes ersetzt werden.

Die Webmeldung zur GDT-Übergabe bestätigt nur die Datei, nicht die sichtbare Akte. Bei einem ungewissen Wahl- oder Vermittlungsauftrag zuerst das Telefon prüfen. Windows-Standard-App, macOS-LaunchServices, Linux/Samba und reale Telefonie müssen auf den vorgesehenen Systemen abgenommen werden.

Stand 12.10.2026: 81 PHP-Kerntests, 22 Integrationstests, 28 Telefonie-Browserfälle und 9 FHIR-Browserfälle erfolgreich. Die Übergaben mit `?` und `#` sind außerdem vom Anwender aus T2med bestätigt.

## Neue Telefonieschnittstelle

`tests/php/telephony_control.php` prüft das gepinnte Schema (81 Fixtures),
Teilfehlertoleranz, Alter, Rechte und letzten Teilnehmer sowie Journal,
Idempotenz und Wiederanlauf. `tests/integration/test_control.py` startet die
wirkliche Middleware gegen lokale AMI-/Control-Dienste und prüft die HTTP-Scopes,
Redirect-Sperre, Teilfehler, verlorene Antwort und Prozessneustart.
`tests/ui/control.cjs` prüft Zähler/Tooltip, Kästchen, offene Aufträge, Neuladen
und Ablauf während stockender Verbindung. Die reale gemeinsame Abnahme folgt
[dem Vertrag](telefonie-v1/ACCEPTANCE.md); Anlagenservice und App sind separat.

Stand 18.11.2026, Middleware 1.5.0: 111 PHP-Tests, 58 Installer-Konfigurationsprüfungen,
27 lokale Integrationstests, 29 bestehende und 6 neue Telefonie-Browserfälle bestanden.
Die Python-Installer-Suite umfasst 24 Fälle; 23 bestanden, ein Linux-spezifischer
Porttest wurde auf macOS übersprungen. Schema und 81 Fixtures entsprechen Vertrag
1.0.2. Keine produktive Anlage oder separat entwickelte App wurde damit abgenommen.
