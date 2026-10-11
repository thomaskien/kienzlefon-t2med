# Tests und gemeinsame Abnahme

## Automatisierte Prüfung

Voraussetzungen: PHP 8.2+, Composer-Abhängigkeiten, Python 3.9+ und das OpenSSL-Kommando für das kurzlebige HTTPS-Testzertifikat.

```sh
composer install
php tests/php/run.php
php tests/installer/run.php
python3 -m unittest discover -s tests/clients -v
python3 -m unittest discover -s tests/installer -v
python3 -m unittest discover -s tests/integration -v
bash -n installer/install.sh clients/install-linux.sh clients/install-macos.sh
bash installer/install.sh --dry-run
```

Lokal geprüft am 11.10.2026: 27 PHP-Kerntests, 26 Installer-Prüfungen, 12 Clienttests, drei Rollbacktests und sieben Integrationstests. Die GitHub-Actions-Konfiguration wiederholt die Tests unter Linux mit PHP 8.2 und 8.4. Ein eingecheckter Workflow allein bestätigt noch keinen erfolgreichen CI-Lauf.

Die Integrationstests starten simulierte AMI- und HTTPS-APS-Server ausschließlich auf Loopback sowie den echten PHP-Dienst mit temporärer Konfiguration. Es werden keine Praxisserver kontaktiert und keine echten Patientenakten geöffnet. Prüffälle:

- APS-Basic-Auth mit leerem Passwort und `ignoreSichtbarkeit: false`.
- Mehrere Patienten, keine Treffer, unveränderte Referenzen zur internen Auswahlprüfung.
- GDT-Format, Arbeitsplatztrennung, kein Überschreiben und Wiederholung desselben Auftrags nach simulierter Dateiübernahme.
- Host-/Origin-/Token-Prüfung, Patientennummern- und Pfadeingaben, GET ohne Dateiänderung.
- Setvar-Reihenfolge und Commit durch `LOOKUP_DONE` nach Bestätigung der vorherigen Aktionen; leere Namensvariable bei Mehrfachtreffern; keine Setvar-Kette für verspätete Antworten.
- Originate nur einmal je Auftrags-ID, keine Connected-Meldung allein aufgrund der AMI-Annahme oder einer frühen Medienbrücke.
- Zweiter Rufabschnitt direkt und über Local-Kanäle; Verbindung erst bei beantwortetem Ziel und nachgewiesener Brückenverbindung.
- AMI-Ausfall, veralteter Zustand, Wiederverbindung und Hangup während des Bestandsabgleichs.
- Bestehende Samba-Konfiguration, Konflikte und idempotente Planung; die drei Shell-Rollbacktests führen die tatsächliche Wiederherstellungsfunktion gegen temporäre Dateien aus.

Im Browser wurden mit der Demo Arbeitsplatzwahl, Namensauswahl, Mehrfachtreffer, Rufnummernsuche, Akten-Weblink und Rückrufdialog geprüft. Die Demo schreibt keine GDT-Dateien und startet kein Telefonat.

## Nach Installation auf dem Linux-Server

```sh
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/server.php --config=/etc/kienzlefon-t2med/config.json --check-config
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/check-connections.php --config=/etc/kienzlefon-t2med/config.json
```

Der zweite Befehl prüft Anmeldung und lesende Aktionen. Er meldet keine Patienteninformationen und löst kein Originate oder GDT aus. Setvar-/Originate-Rechte werden erst beim jeweiligen Funktionstest mitgeprüft. Eine technisch gültige APS-Antwort allein belegt nicht die Sichtbarkeit aller Praxispatienten.

Anschließend gemeinsam mit dem angepassten kienzlefon-Dialplan testen:

1. Eingehender bekannter Anruf: Name erscheint rechtzeitig auf dem Telefon, Rufnummer bleibt erhalten; Warteschlange und Gespräch referenzieren denselben Anruf.
2. Mehrere Patienten an einer Nummer: Telefon übernimmt keinen beliebigen Namen; Webansicht zeigt alle Kandidaten zur Auswahl.
3. Unbekannte/unterdrückte Nummer und ausgefallene oder langsame Middleware: Telefonie läuft weiter, maximal eine Sekunde Lookup-Wartezeit; späteres Ergebnis ändert die Telefonanzeige nicht.
4. Pro Arbeitsplatz einen freigegebenen Testpatienten öffnen. T2med muss dessen eigene `AUFRUFi.gdt` aus dem Share verarbeiten. Empfang 1 darf nicht versehentlich Empfang 2 ansteuern. Eine wartende Importdatei darf nicht überschrieben werden.
5. Click-to-Dial: richtige Nebenstelle zuerst, erst nach Abheben Zielruf, korrekte Praxis-Absenderrufnummer. Besetzt, keine Antwort und Verbindungsabbruch ebenfalls prüfen.
6. Transfer, Warteschlangenwechsel, Local-Kanaloptimierung sowie AMI-Neuverbindung prüfen. Der periodische Snapshot korrigiert verpasste Ereignisse.
7. Bestehende SMB-Freigaben weiter nutzen; Guest-Lese- und Löschzugriff auf die neue GDT-Datei von Windows, macOS und Linux prüfen. OS-seitige Einschränkungen für Gastzugriff werden vom Installer nicht global geändert.
8. `tel:`/`callto:` unter den jeweiligen Betriebssystemen registrieren und prüfen. Windows-Registry, macOS-LaunchServices, Linux-systemd/APT/Samba und reale Telefonate wurden im lokalen Mac-Entwicklungslauf nicht verändert.

GDT- und Click-to-Dial-Wiederholungskennungen sind im Arbeitsspeicher und überleben keinen Neustart. Bei ungewissem Wahlauftrag das Telefon prüfen, bevor ein neuer Auftrag angelegt wird. Die Webmeldung „Aufruf an T2med übergeben“ ist keine Rückmeldung über die tatsächlich sichtbare Akte.
