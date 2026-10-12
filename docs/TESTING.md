# Tests und praktische Abnahme

Automatisierte Tests verwenden fiktive Daten sowie lokale AMI-, APS- und FHIR-Testserver. Sie kontaktieren keine Praxisserver, öffnen keine echte Akte und lösen keinen echten Anruf aus.

## Automatisierte Prüfung

Voraussetzungen sind PHP 8.2+, Composer-Abhängigkeiten, Python 3.9+, Go, Node.js und OpenSSL.

```sh
composer install
php tests/php/run.php
php tests/installer/run.php
(cd fhir-importer && go test ./... && go vet ./...)
python3 -m unittest discover -s tests/clients -v
python3 -m unittest discover -s tests/installer -v
python3 -m unittest discover -s tests/integration -v
bash -n installer/install.sh clients/install-linux.sh clients/install-macos.sh
bash installer/install.sh --dry-run
```

Die Kern- und Integrationstests decken Konfiguration, FHIR-Pagination und atomaren Bestandswechsel, lokale Patienten- und Rufnummernsuche, AMI-Zustände, GDT, Click-to-Dial, Rückrufaufträge, Vermittlung, Samba-Planung und Rollback ab. Betriebssystemfunktionen wie Registry, LaunchServices, systemd und echtes Samba brauchen zusätzlich den jeweiligen Zielrechner.

## Browserprüfung

```sh
npm install --no-save --package-lock=false --ignore-scripts playwright@1.62.1
npx playwright install chromium
node tests/ui/compact.cjs
node tests/ui/fhir.cjs
```

Die Browserfälle prüfen Ansichten, Patientensuche, Mehrfachtreffer, Nebenstellenstatus und -wahl, Rückruf- und Vermittlungsaktionen, FHIR-Übergabe, URL-Bereinigung, Neuladen ohne Wiederholung, Offline-Zustand und Tastaturfokus. `UI_BROWSER_CHANNEL=chrome` verwendet ein vorhandenes Chrome mit isoliertem Profil; `UI_SCREENSHOT=/absoluter/pfad/kompakt.png` speichert eine Ansicht mit fiktiven Daten.

## Prüfung auf dem Linux-Server

```sh
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/server.php --config=/etc/kienzlefon-t2med/config.toml --check-config
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/check-connections.php --config=/etc/kienzlefon-t2med/config.toml
```

Der zweite Befehl prüft im FHIR-Modus den lokalen Bestand und AMI, löst aber weder FHIR-Import, Originate noch GDT aus.

## Praktische Abnahme für 1.4.3

1. In T2med den FHIR-Abgleich einmal mit `?`, einmal mit `#` starten. Erfolg und erwartete Bestandszahl prüfen; einen Fehlerfall auslösen und bestätigen, dass der vorherige Bestand aktiv bleibt.
2. Bekannte, unbekannte und gemeinsam verwendete Rufnummern prüfen: bei der Wahlhilfe setzt nur eine eindeutige Zuordnung den echten Namen, sonst bleibt die Namensangabe leer; Mehrfachtreffer erscheinen vollständig in der UI.
3. Nach Name, Umlautumschrift, Patientennummer und Telefonnummer suchen. Nur einen freigegebenen Testpatienten öffnen; GDT muss dessen echte Patientennummer enthalten und im richtigen Arbeitsplatzverzeichnis landen.
4. Kompaktansicht prüfen: **FREI** grün, **klingelt** gelb, **BELEGT** rot. Andere freie konfigurierte Nebenstelle anklicken, eigenes Telefon abheben und erst danach den Zielruf erwarten. Eigene Nebenstelle, belegter eigener Anschluss und veralteter Zustand müssen die Aktion sperren.
5. Externe Wahl über Weblink sowie `tel:` und `callto:` prüfen: eigenes Telefon zuerst, Ziel danach; kein künstlicher Name `Rueckruf`/`Rückruf` und kein Nummerntext als Name. Besetzt, keine Antwort und Abbruch mitprüfen.
6. Rückrufaktionen, internes/externes Vermitteln direkt und mit Rückfrage sowie ungewisse Ergebnisse testen. Kein Auftrag darf automatisch wiederholt werden.
7. Untere Zeile der Kompaktansicht auf **kienzlefon v1.4.3 von Dr. Thomas Kienzle** prüfen. Verbindungsverlust darf keine alten Gesprächsdaten als aktuell zeigen.
8. GDT-Freigabe von jedem Arbeitsplatz lesen/löschen lassen und kontrollieren, dass Empfang 1 nicht Empfang 2 öffnet. Eine wartende Datei muss atomar durch den neuesten Aufruf desselben Arbeitsplatzes ersetzt werden.

Die Webmeldung zur GDT-Übergabe bestätigt nur die Datei, nicht die sichtbare Akte. Bei einem ungewissen Wahl- oder Vermittlungsauftrag zuerst das Telefon prüfen. Windows-Standard-App, macOS-LaunchServices, Linux/Samba und reale Telefonie müssen auf den vorgesehenen Systemen abgenommen werden.

Stand 12.10.2026: 81 PHP-Kerntests, 22 Integrationstests, 28 Telefonie-Browserfälle und 9 FHIR-Browserfälle erfolgreich. Die Übergaben mit `?` und `#` sind außerdem vom Anwender aus T2med bestätigt.
