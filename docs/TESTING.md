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

Lokal geprüft für Version 1.3.3 am 12.10.2026: 46 PHP-Kerntests, 49 PHP-Installerprüfungen, 21 Python-Installerprüfungen, 28 Clienttests, 14 Integrationstests und 21 Browser-Szenarien. Ein zusätzlicher Linux-spezifischer Socket-Test wird auf macOS übersprungen. GitHub Actions wiederholt die Tests unter Linux mit PHP 8.2 und 8.4 sowie Chromium.

Für 1.3.4 sind lokal alle 29 Clienttests erfolgreich, einschließlich eines echten macOS-Applet-Builds ohne vorhandene Bundle-ID und mit bereits vorhandenen Metadaten. Der Test prüft die URL-Schemas, den eingebetteten Helper und die gültige Signatur; eine nachträgliche Ressourcenänderung muss erkannt werden. Die Applets bleiben in temporären Verzeichnissen und werden weder gestartet noch registriert. GitHub Actions führt die Clienttests zusätzlich auf macOS aus; unter Linux wird der native Bundle-Test übersprungen.

Am 12.10.2026 wurde 1.3.4 zusätzlich auf dem lokalen Mac installiert: Signaturprüfung und Arbeitsplatzzuordnung per Bootstrap-GET erfolgreich; LaunchServices bestätigt `de.kienzlefon.t2med` als Standardhandler für `tel` und `callto`. Die URI-Verarbeitung wurde ohne Browserstart und ohne Telefonat per Dry-run geprüft.

Für 1.3.5 sind lokal alle 35 Clienttests erfolgreich. Neue Prüfungen decken die Direktwahl für beide URI-Schemas ab: genau ein POST mit normalisierter Rufnummer, Arbeitsplatz und Token, keine Rückfrage, kein Browser und keine Erfolgsmeldung. Falsche Zuordnung und ungültige URI verhindern den POST; Fehler, ungewisse Ergebnisse und Demobetrieb bleiben sichtbar. Ein POST-Timeout führt zu keiner Wiederholung. HTTP-Antworten werden simuliert; es werden keine echten Anrufe ausgelöst.

Die lokale Mac-App wurde auf 1.3.5 aktualisiert. Signatur, eingebetteter Direktaufruf, URI-Dry-run und die Zuordnung `mac` → `202` per Bootstrap-GET wurden geprüft. Ein echter Anruf wurde dabei nicht ausgelöst.

Die Integrationstests starten simulierte AMI- und HTTPS-APS-Server ausschließlich auf Loopback sowie den echten PHP-Dienst mit temporärer Konfiguration. Es werden keine Praxisserver kontaktiert und keine echten Patientenakten geöffnet. Prüffälle:

- APS-Basic-Auth mit leerem Passwort und `ignoreSichtbarkeit: false`.
- Gleiche APS-Treffer und gemeinsamer Cache für nationale Rufnummern, `+49…`, `0049…` und `49…`, auch wenn APS nur eine Schreibweise findet.
- Mehrere Patienten, keine Treffer, unveränderte Referenzen zur internen Auswahlprüfung.
- GDT-Format, Arbeitsplatztrennung, atomarer Ersatz einer wartenden GDT und Wiederholung desselben Auftrags nach simulierter Dateiübernahme.
- Host-/Origin-/Token-Prüfung, Patientennummern- und Pfadeingaben, GET ohne Dateiänderung.
- Setvar-Reihenfolge und Commit durch `LOOKUP_DONE` nach Bestätigung der vorherigen Aktionen; leere Namensvariable bei Mehrfachtreffern; keine Setvar-Kette für verspätete Antworten.
- Originate nur einmal je Auftrags-ID, keine Connected-Meldung allein aufgrund der AMI-Annahme oder einer frühen Medienbrücke.
- Zweiter Rufabschnitt direkt und über Local-Kanäle; Verbindung erst bei beantwortetem Ziel und nachgewiesener Brückenverbindung.
- Vermittlung: eigene Zweierverbindung, Local-Zwischenkanäle, Kanalbindung, interne/externe Ziele, vorbereiteter Telefonserver, bestätigte Setvar-Reihenfolge vor dem Transfer, Rückfrage erst nach Zielantwort, Abschluss-/Abbruchbestätigung und ungewisse AMI-Antwort ohne Doppelauftrag.
- AMI-Ausfall, veralteter Zustand, Wiederverbindung und Hangup während des Bestandsabgleichs.
- Bestehende Samba-Konfiguration, Konflikte und idempotente Planung; vier Shell-Rollbacktests führen die tatsächliche Wiederherstellungsfunktion gegen temporäre Dateien aus.
- TOML im laufenden Dienst; verlustfreie JSON-Migration mit Sonderzeichen und leeren Werten, generische Parserfehler ohne Secrets und Vorrang vorhandener TOML-Dateien.
- Numerische Client-IDs (`201`, `0`, `001`, `1`): interaktive Einrichtung und spätere Änderung, TOML-Roundtrip, Bootstrap mit Zeichenketten, getrennte GDT-Ziele, korrekte AMI-Nebenstelle und Registrierung der Telefonie-App. JSON-Zahlwerte statt Zeichenketten, unbekannte IDs und unpassende Nebenstellen bleiben abgewiesen. Der Browser prüft Arbeitsplatzwahl, gespeicherte Auswahl, Aktenaufruf, Direktwahl und Neuladen.
- Installer-Nachfrage im Terminal, Beibehalten einschließlich TOML-Kommentaren, `--keep-config`, Übernahme ohne Terminal sowie Änderung und Ergänzung vorhandener Arbeitsplätze. Fehlgeschlagene Erst-Migration stellt die bisherige JSON-Installation wieder her.

Im Browser wurden mit der Demo Arbeitsplatzwahl, Namensauswahl, Mehrfachtreffer, Rufnummernsuche, Akten-Weblink und Rückrufdialog geprüft. Die Demo schreibt keine GDT-Dateien und startet kein Telefonat.

## Nach Installation auf dem Linux-Server

```sh
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/server.php --config=/etc/kienzlefon-t2med/config.toml --check-config
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/check-connections.php --config=/etc/kienzlefon-t2med/config.toml
```

Der zweite Befehl prüft Anmeldung und lesende Aktionen. Er meldet keine Patienteninformationen und löst kein Originate oder GDT aus. Setvar-/Originate-Rechte werden erst beim jeweiligen Funktionstest mitgeprüft. Eine technisch gültige APS-Antwort allein belegt nicht die Sichtbarkeit aller Praxispatienten.

Anschließend gemeinsam mit dem angepassten kienzlefon-Dialplan testen:

1. Eingehender bekannter Anruf: Name erscheint rechtzeitig auf dem Telefon, Rufnummer bleibt erhalten; Warteschlange und Gespräch referenzieren denselben Anruf.
2. Mehrere Patienten an einer Nummer: Telefon übernimmt keinen beliebigen Namen; Webansicht zeigt alle Kandidaten zur Auswahl.
3. Unbekannte/unterdrückte Nummer und ausgefallene oder langsame Middleware: Telefonie läuft weiter, maximal eine Sekunde Lookup-Wartezeit; späteres Ergebnis ändert die Telefonanzeige nicht.
4. Pro Arbeitsplatz einen freigegebenen Testpatienten öffnen. T2med muss dessen eigene `AUFRUFi.gdt` aus dem Share verarbeiten. Empfang 1 darf nicht versehentlich Empfang 2 ansteuern. Eine wartende Importdatei muss durch den neuesten Aufruf desselben Arbeitsplatzes vollständig ersetzt werden; andere Arbeitsplätze bleiben unverändert.
5. Click-to-Dial: richtige Nebenstelle zuerst, erst nach Abheben Zielruf, korrekte Praxis-Absenderrufnummer. Besetzt, keine Antwort und Verbindungsabbruch ebenfalls prüfen.
6. Internes/externes Vermitteln direkt und mit Rückfrage gemäß [Transfer-Abnahme](TRANSFER_HANDOFF.md#6-abnahme-auf-dem-telefonserver) prüfen. Warteschlangenwechsel, Local-Kanaloptimierung sowie AMI-Neuverbindung prüfen. Der periodische Snapshot korrigiert verpasste Ereignisse.
7. Bestehende SMB-Freigaben weiter nutzen; Guest-Lese- und Löschzugriff auf die neue GDT-Datei von Windows, macOS und Linux prüfen. OS-seitige Einschränkungen für Gastzugriff werden vom Installer nicht global geändert.
8. `tel:`/`callto:` unter den jeweiligen Betriebssystemen registrieren und prüfen. Die macOS-Registrierung wurde mit 1.3.4 wie oben beschrieben lokal geprüft. Windows-Registry, Linux-systemd/APT/Samba und reale Telefonate wurden im lokalen Mac-Entwicklungslauf nicht verändert.

GDT-, Click-to-Dial- und Vermittlungs-Wiederholungskennungen sind im Arbeitsspeicher und überleben keinen Neustart. Bei ungewissem Wahlauftrag das Telefon prüfen, bevor ein neuer Auftrag angelegt wird. Die Webmeldung „Aufruf an T2med übergeben“ ist keine Rückmeldung über die tatsächlich sichtbare Akte.

## Browserprüfung der Kompaktansicht

```sh
npm install --no-save --package-lock=false --ignore-scripts playwright@1.62.1
npx playwright install chromium
node tests/ui/compact.cjs
```

Die Tests bedienen Chromium mit vollständig gemockten HTTP-Endpunkten und
fiktiven Patientendaten: 250 × 190 Pixel, getrennte Bereiche für Gespräche und
Warteschlange, eigene Verbindung, Reihenfolge der
Wartenden, Details/Mehrdeutigkeit, Fokus bei Polling und Entfernen einer
vorhergehenden Zeile, Offline/Recovery, unterdrückte Rufnummer, leerer/voller
Monitor, Übersicht, Touch-Ziele, Geburtsdatum, Direktwahl und GDT ohne
Wiederholung bei Reload/Zurück, Standard-Wählbestätigung sowie abweichende lokale
Nebenstellenzuordnung. „Alle Gespräche“ bleibt nach Polling, Ansichtwechsel und
Reload geöffnet, zeigt weitere Nebenstellen ohne Verdopplung des eigenen Gesprächs
und reagiert auf Hangup und Verbindungsverlust. Verdeckte Ansichten pollen weiter; Direktaktionen warten
auf Sichtbarkeit. Zusätzlich: Vermittlungspfeil nur an eigenen erlaubten Verbindungen, beide Zielarten/Modi, Rückfrageaktionen nach Serverflags, Arbeitsplatzwechsel bei offenem Dialog, ungewisse Ergebnisse, Fokus-/Eingabeerhalt, Datum mit Schriftgewicht 400 sowie neue GDT-Aufträge nach jedem bestätigten bewussten Klick. Es werden keine echten Anrufe oder GDT-Dateien erzeugt.

`UI_BROWSER_CHANNEL=chrome` verwendet ein vorhandenes Chrome mit isoliertem
Testprofil. `UI_SCREENSHOT=/absoluter/pfad/kompakt.png` speichert zusätzlich eine
Ansicht mit fiktiven Daten. Die tatsächliche Windows-Taskleistenanzeige, Browser-
Drosselung im minimierten Zustand und Betriebssystem-Standardzuordnungen brauchen
weiterhin die Prüfung auf dem jeweiligen Arbeitsplatz.

Die macOS-Launcher- und
Standardzuordnungs-Skripte wurden in einem temporären Verzeichnis erfolgreich
kompiliert, ohne sie zu registrieren oder auszuführen. Windows PowerShell und
echte Desktop-Standardzuordnungen wurden lokal nicht ausgeführt.
