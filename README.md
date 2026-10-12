# kienzlefon-t2med

Zentrale PHP-Middleware zwischen Asterisk und T2med: Anrufer erkennen, Warteschlangen und Gespräche im Browser anzeigen, Patientenakten über GDT öffnen und Rückrufe vom richtigen Arbeitsplatz starten.

## Funktionen

- Kompaktansicht für den Arbeitsplatz (ca. 250 × 160 px bei einem eigenen Gespräch und zwei Wartenden), aufklappbare Patientenauswahl und vollständige Übersicht.
- Dauerhafte AMI-Verbindung mit Wiederverbindung und Bestandsabgleich; Warteschlangen, Gespräche und Nebenstellen als Webansicht und JSON.
- Asynchrone Patientensuche über den bestätigten APS-Suchendpunkt. Mehrere Patienten zu einer Rufnummer bleiben getrennt und werden zur Auswahl angeboten.
- Namensauflösung für die Telefonanzeige über temporäre Kanalvariablen. Die Ein-Sekunden-Frist und die Übernahme in `CALLERID(name)` bleiben im Asterisk-Dialplan.
- Patientenklick oder Weblink mit Rufnummer erzeugt eine ID-only-GDT im zugeordneten Arbeitsplatzverzeichnis.
- Click-to-Dial: zuerst klingelt die eigene Nebenstelle, nach dem Abheben wird die Zielnummer gewählt. Registrierbare `tel:`-/`callto:`-Clients für Windows, macOS und Linux.
- Linux-Installer mit systemd, geschützter Zugangskonfiguration und additiver Guestfreigabe `kienzlefon-GDT`.

PHP 8.2+ mit ReactPHP, ohne Datenbank und ohne Webserver-/PHP-FPM-Pflicht. Unterstützte Server: Debian 12+, Ubuntu 24.04+, Raspberry Pi OS Bookworm+ mit systemd. AMI, APS und Middleware können auf unterschiedlichen Rechnern laufen. Die Weboberfläche benötigt keine Anmeldung und ist für das abgeschottete Praxisnetz konfiguriert.

## Installation

Auf dem vorgesehenen Linux-Server:

```sh
git clone https://github.com/thomaskien/kienzlefon-t2med.git
cd kienzlefon-t2med
sudo bash installer/install.sh
```

Der Installer installiert fehlende PHP-/Composer-Pakete und fragt APS- und AMI-Server samt Zugangsdaten, Middleware-Adresse und Arbeitsplätze ab. Ein ausdrücklich leeres APS-Passwort ist gültig. Die bestehende APS-Zertifikatsausnahme kann für den Integrationstest eingestellt werden. Konfiguration: `/etc/kienzlefon-t2med/config.json`; Dienst: `kienzlefon-t2med.service`.

Bei vorhandenem Samba werden dessen globale Authentifizierung und fremde Freigaben erhalten. Ist Guestzugriff damit nicht kompatibel, meldet der Installer den Konflikt. Mit `--skip-samba` lässt sich eine separat eingerichtete Freigabe verwenden. Details und Arbeitsplatzregistrierungen stehen in [Installation und Betrieb](docs/INSTALLATION.md).

```text
kienzlefon-GDT/
  empfang-1/AUFRUFi.gdt
  empfang-2/AUFRUFi.gdt
```

In T2med wird die **Kalenderanbindung** für den jeweiligen Arbeitsplatz auf dessen Importdatei eingerichtet. Eine noch nicht abgeholte Datei wird nicht überschrieben. Die Webmeldung bestätigt die Dateiübergabe; die tatsächliche Anzeige der Akte erfolgt durch T2med.

## Demo und Entwicklung

```sh
composer install
php bin/server.php --demo --listen=127.0.0.1:8765
```

Dann `http://127.0.0.1:8765/` öffnen. Die Demo verwendet ausschließlich fiktive Daten und erzeugt weder Telefonate noch GDT-Dateien. Der Schalter `--demo` gilt für den gesamten Prozess; eine URL kann einen produktiven Dienst nicht in den Demomodus umschalten.

## Schnittstellen

- [Architektur, Konfiguration und HTTP-API](docs/ARCHITECTURE.md)
- [AMI- und Dialplan-Vertrag für den kienzlefon-Installer](docs/AMI_DIALPLAN.md)
- [Tests und Abnahme](docs/TESTING.md)

Einbettbarer Aktenlink, mit URL-kodierter Telefonnummer und registrierter Client-ID:

```text
http://middleware.example:8765/patient-oeffnen?client_id=empfang-1&telefon=%2B493055501111
```

Bei eindeutiger Zuordnung erfolgt die GDT-Übergabe, bei mehreren Kandidaten eine Auswahl. Ein bloßer GET liefert nur die Seite. Der Browser löst den schreibenden POST aus. Die Arbeitsplatzwahl wird lokal im Browser gespeichert.

Direktwahl ohne zusätzliche Bestätigung, zum Einbau in die kienzlefon-Oberfläche:

```text
http://middleware.example:8765/waehlen?client_id=empfang-1&telefon=%2B493055501111&sofort=1
```

Zuerst klingelt die zugeordnete Nebenstelle, nach dem Abheben das Ziel. Ohne
`sofort=1` erscheint die Wählbestätigung. Reload und Zurücknavigation wiederholen
keinen Auftrag. [Weblinks und Bedienung](docs/WEB.md) ·
[Windows/macOS/Linux-Registrierung](docs/CLIENTS.md).

## Prüfstand

PHP-Tests, simulierte AMI-/HTTPS-APS-Integration, GDT-Format, Installer-Konfigurations-/Samba-Planung, Rollback und Client-URI-Validierung sind automatisiert prüfbar. Die Weboberfläche wurde mit fiktiven Daten lokal geprüft. Eine echte Linux-Installation mit dem angepassten kienzlefon-Dialplan, SMB und T2med sowie die OS-Registrierung auf allen Arbeitsplatzsystemen sind Teil des anschließenden Praxistests.

Der Anrufzustand und Auftragskennungen sind flüchtig. Bei ungewissem Click-to-Dial-Ergebnis zuerst das Telefon prüfen; es erfolgt keine automatische Wahlwiederholung. Nach einem Dienstneustart werden aktive Gespräche neu eingelesen, frühere Rückruf-Auftragskennungen aber nicht wiederhergestellt. Gesprächsdauer ist bei schon vor Dienststart bestehenden Gesprächen unbekannt; Kanalalter wird separat geliefert.
