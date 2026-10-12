[![Demovideo: kienzlefon und T2med – zum Abspielen anklicken](https://i.ytimg.com/vi/kX2JQL1cnUw/maxresdefault.jpg)](https://youtu.be/kX2JQL1cnUw)

# kienzlefon-t2med

Anrufer erkennen, Gespräche und Warteschlangen überblicken, direkt wählen und die passende T2med-Akte öffnen: kienzlefon-t2med verbindet Asterisk mit T2med über eine zentrale Middleware.

**Version 1.4.3 · von Dr. Thomas Kienzle** · [MIT-Lizenz](LICENSE) · [Änderungen](CHANGELOG.md)

[Installation](docs/INSTALLATION.md) · [FHIR und T2med-Aufruf](docs/FHIR.md) · [Bedienung und Weblinks](docs/WEB.md) · [Desktop-Clients](docs/CLIENTS.md) · [Asterisk](docs/AMI_DIALPLAN.md)

> **Hinweis zum T2med-Demo-Key:** Der mitgelieferte API-Key erlaubt nur **100 Requests**. Ein FHIR-Abgleich kann mehrere Requests benötigen; die lokale Patientensuche benötigt keine weiteren. [FHIR einrichten](docs/FHIR.md)

## Oberfläche

Die Übersicht zeigt Warteschlangen, Gespräche und Nebenstellen. Die Suche findet Patienten nach Name, Patientennummer oder Telefonnummer; mehrere Treffer bleiben zur Auswahl getrennt. Ein Klick auf einen Patienten übergibt dessen Akte per GDT an den gewählten Arbeitsplatz.

![Vollständige Telefonieübersicht](docs/images/telefonieuebersicht.png)

Die Kompaktansicht zeigt eigene Gespräche und Wartende. Nebenstellen stehen als **FREI** (grün), **klingelt** (gelb) oder **BELEGT** (rot) bereit. Ein Klick auf eine andere konfigurierte Nebenstelle wählt sie direkt über den eigenen ausgewählten Arbeitsplatz: zuerst das eigene Telefon abheben, danach wird das Ziel gerufen.

![Kompaktansicht](docs/images/kompaktansicht.jpg)

Der Pfeil **↪** am eigenen verbundenen Gespräch öffnet die Vermittlung zu einer Nebenstelle oder externen Nummer, direkt oder mit Rückfrage.

![Vermittlungsdialog](docs/images/vermittlung.jpg)

*Die Screenshots zeigen einen älteren Oberflächenstand. Bedienung und Statusangaben in der Dokumentation gelten für 1.4.3.*

## Aufbau

- **Asterisk / kienzlefon:** Gespräche, Warteschlangen, AMI und Dialplan; Einrichtung im [kienzlefon-Projekt](https://github.com/thomaskien/kienzlefon).
- **T2med / FHIR:** stößt einen vollständigen Patientenabruf an; danach sucht die Middleware lokal in SQLite.
- **Middleware:** PHP-CLI-Dienst unter systemd, Weboberfläche/API, AMI, Telefonverzeichnis und GDT.
- **Arbeitsplatz:** Browser, zugeordnete Nebenstelle und T2med-Kalenderanbindung zum eigenen GDT-Verzeichnis.

Unterstützt werden Debian 12+, Ubuntu 24.04+ und Raspberry Pi OS Bookworm+ mit PHP 8.2+. Ein zusätzlicher Webserver ist nicht erforderlich. Die Oberfläche hat keine Benutzeranmeldung und gehört in ein abgeschottetes Praxisnetz.

FHIR ist die Standardquelle. APS bleibt nur als ausdrücklich aktivierter Altbetrieb erhalten. GDT wird weiterhin für den Aktenaufruf verwendet.

## Schnellstart

1. AMI und Dialplan nach dem [Asterisk-Vertrag](docs/AMI_DIALPLAN.md) einrichten; für Vermittlung zusätzlich die [Transfer-Ergänzung](docs/TRANSFER_HANDOFF.md).
2. Middleware installieren:

   ```sh
   git clone https://github.com/thomaskien/kienzlefon-t2med.git
   cd kienzlefon-t2med
   sudo bash installer/install.sh
   ```

3. In T2med den Aufruf **Telefonverzeichnis aktualisieren** gemäß [FHIR-Anleitung](docs/FHIR.md) anlegen und einmal ausführen.
4. Pro Arbeitsplatz Client-ID, Nebenstelle und GDT-Unterverzeichnis zuordnen. Die [GDT-Vorlage „Telefonaufruf“ herunterladen](https://raw.githubusercontent.com/thomaskien/kienzlefon-t2med/main/docs/templates/GDTGeraet_Telefonaufruf_Konfiguration.json) und nach der [Importanleitung](docs/INSTALLATION.md#gdt-vorlage-in-t2med-importieren) einrichten.
5. Webansicht unter `http://<Middleware-Server>:8766/` öffnen (Standardport **8766**), Arbeitsplatz wählen und Patientensuche, Aktenaufruf und Telefonie praktisch prüfen.

Details zu Installation, Updates, Samba und Diagnose stehen in [Installation und Betrieb](docs/INSTALLATION.md). Die Bedienung einschließlich Rückrufaufträgen, Vermittlung und Weblinks beschreibt [Oberfläche und Weblinks](docs/WEB.md).

## Aktualisieren und Demo

```sh
git pull --ff-only
sudo bash installer/install.sh --keep-config
```

Beim Umstieg von einer älteren APS-Version auf FHIR anschließend in T2med **Telefonverzeichnis aktualisieren** ausführen. Erst nach dem ersten erfolgreichen Abgleich ist die Patientensuche verfügbar.

Für geänderte Verbindungen oder Arbeitsplätze `--reconfigure` verwenden. Die Konfiguration liegt in `/etc/kienzlefon-t2med/config.toml`, der Dienst heißt `kienzlefon-t2med.service`.

Eine lokale Demo mit fiktiven Daten startet auf Port **8765** ohne Telefonate und GDT-Dateien:

```sh
composer install
php bin/server.php --demo --listen=127.0.0.1:8765
```

Danach `http://127.0.0.1:8765/` öffnen. Tests und praktische Abnahme: [docs/TESTING.md](docs/TESTING.md); technische Schnittstellen: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
