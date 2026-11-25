# Installation und Betrieb

[← Projektübersicht](../README.md) · [FHIR einrichten](FHIR.md) · [Bedienung](WEB.md) · [Desktop-Clients](CLIENTS.md)

FHIR ist die Standardquelle. APS wird nur verwendet, wenn `patients.provider = "aps"` ausdrücklich gesetzt ist; es gibt keinen automatischen Rückfall.

## Voraussetzungen

Unterstützt werden Debian 12+, Ubuntu 24.04+ und Raspberry Pi OS Bookworm+ mit systemd und PHP 8.2+. Die mitgelieferten FHIR-Binaries benötigen ein 64-Bit-Linux für amd64 oder arm64. Der Installer ergänzt fehlende APT-/Composer-Pakete. PHP benötigt `json`, `openssl`, `mbstring` und für FHIR `pdo_sqlite`.

Vorab bereitlegen:

- Asterisk-/AMI-Host, Port, Benutzer, Secret und TLS-Einstellung; üblicher AMI-Port ist `5038`.
- Erreichbare Middleware-Adresse, freien HTTP-Port (Standard **8766**) und absoluten GDT-Root. Vorhandene abweichende Ports bleiben bei Updates erhalten.
- Die Fon-Übergabe liefert die Nebenstellen zur Auswahl. Ohne Übergabe lassen sich Nebenstellen im Menü anlegen.
- Die IP-Adresse des T2med-Servers für FHIR. Den kurzlebigen OAuth-Token liefert T2med bei jedem Abgleich.

AMI und Dialplan werden nach dem [Asterisk-Vertrag](AMI_DIALPLAN.md) eingerichtet. Zugangsdaten nie in URLs, Beispieldateien oder Logs übernehmen.

## Installieren

```sh
git clone https://github.com/thomaskien/kienzlefon-t2med.git
cd kienzlefon-t2med
sudo bash installer/install.sh
```

Eine Live-Installation auf macOS ist gesperrt; dort stehen nur `installer/install.sh --help` und `installer/install.sh --dry-run` zur Verfügung.

### Optionale dritte Komponente des Kombi-Installers

Ein Aufrufer kann die T2med-Middleware auf dem Fon-Server oder auf einem separaten Linux-Rechner einrichten. Ein Release- und ein unveröffentlichter Entwicklungsstand verwenden denselben lokalen Quellbaum. Das Paket auf macOS oder Linux im Wurzelverzeichnis des gewünschten Stands bauen. Das Ausgabeverzeichnis muss existieren.

```sh
bash scripts/pack.sh --output /absoluter/pfad/t2med.tar.gz
```

Die Dateiliste in `installer/package-files.sh` bestimmt den Paketinhalt. Neue Programmdateien dort ergänzen. Der Bau übernimmt den aktuellen Dateiinhalt einschließlich uncommitteter Änderungen und prüft die beiden FHIR-Binaries gegen `libexec/SHA256SUMS`. Auf dem Zielrechner in ein neues Verzeichnis entpacken und den Inhalt prüfen.

```sh
tar -xzf /absoluter/pfad/t2med.tar.gz
cd t2med
bash installer/install.sh --dry-run
```

Der Dry-Run prüft die Paketbestandteile. Für einen expliziten AMI-Import benötigt der Zielrechner zusätzlich `python3` ab 3.11. Das Fon-Setup erzeugt bei aktiviertem AMI-Zugang eine geschützte TOML-Datei mit `schema_version = 1`. Mit `--dry-run --ami-connection-file DATEI` lässt sich auch diese Datei vorab prüfen.

**Auf dem Fon-Server** liest der Installer den lokalen Export direkt.

```sh
sudo bash installer/install.sh --ami-connection-file /etc/kienzlefon/middleware-connection.toml
```

**Auf einem separaten Linux-Rechner** im Fon-Setup dessen Quelladresse bzw. VPN-Freigabe und eine von dort erreichbare AMI-Zieladresse einrichten. Den Export über einen geschützten Kanal, etwa SSH, in ein privates Verzeichnis auf dem Middleware-Rechner übertragen. Die Kopie nur für ihren Eigentümer lesbar bereitstellen, üblicherweise mit Modus `0600`.

```sh
sudo bash installer/install.sh --ami-connection-file /geschuetzter/pfad/uebergabe.toml
```

Der Installer übernimmt `ami.host`, `ami.port`, `ami.username`, `ami.secret` und TCP/TLS aus `ami.transport`. Er erwartet aktivierte AMI-Ereignisse (`events = "on"`). Die Quellfreigabe `allowed_source_ip` kann auch `"*"` sein.

Das Arbeitsplatzmenü führt die Nummern aus `click_to_dial.extensions` und `transfer.extensions` jeweils einmal auf. Neue Nebenstellen starten als **inaktiv**. Zeigt das Menü etwa `2) 202 [inaktiv]`, aktiviert die Eingabe `2` diesen Platz. Mehrere Menünummern lassen sich gemeinsam eingeben, etwa `1 2 4`. Das Menü zeigt danach den neuen Zustand. Mit Enter oder `0` die Auswahl übernehmen. Bei `--reconfigure` erscheinen auch die früher deaktivierten Nummern. Bestehende GDT-Dateien bleiben auf der Platte. Die Wahl- und Vermittlungsrechte verwaltet die Fon-Anlage.

Wichtige Pfade:

| Inhalt | Pfad |
| --- | --- |
| Anwendung | `/opt/kienzlefon-t2med` |
| FHIR-Import-Binary | `/opt/kienzlefon-t2med/libexec/fhir-importer` |
| Konfiguration | `/etc/kienzlefon-t2med/config.toml` (`0640`) |
| FHIR-Daten und Status | `/var/lib/kienzlefon-t2med` (`0700`, Dateien `0600`) |
| GDT-Root (Beispiel) | `/srv/kienzlefon-GDT` |
| systemd-Dienst | `kienzlefon-t2med.service` |

Der Dienst läuft unprivilegiert und schreibt in das private Datenverzeichnis und den konfigurierten GDT-Root. Das Schema steht in [config.example.toml](../config.example.toml). Nach der Installation den [T2med-FHIR-Aufruf](FHIR.md#aufruf-in-t2med-einrichten) anlegen und erstmals ausführen.

## Update und Neukonfiguration

```sh
git pull --ff-only
sudo bash installer/install.sh --keep-config
```

`--keep-config` übernimmt eine vorhandene Konfiguration ohne Nachfrage. Für bewusste Änderungen an Verbindungen oder Arbeitsplätzen:

```sh
sudo bash installer/install.sh --reconfigure
```

Ein normaler interaktiver Lauf fragt, ob vorhandene Einstellungen geändert werden sollen. Eine allein vorhandene `config.json` wird validiert und nach TOML migriert; die JSON-Datei bleibt als Altbestand erhalten. Sind beide vorhanden, hat TOML Vorrang. Bei einem Installationsfehler werden bekannte vorherige App-, TOML-, Unit- und Samba-Dateien wiederhergestellt; neu installierte Pakete, Dienstbenutzer und sichere leere Verzeichnisse werden nicht automatisch entfernt.

Beim Update aus einem Paket den Installer aus dem frisch entpackten `t2med/` starten. `--keep-config` erhält vorhandenes TOML bytegleich. Ein expliziter Import ersetzt nur die fünf exportierten AMI-Werte, auch ohne Terminal. Nach einer Secret-Rotation dafür den aktuellen Export bzw. eine neue geschützte Kopie verwenden. Für weitere Änderungen im selben Lauf `--reconfigure` mit `--ami-connection-file` kombinieren. Der Installer lehnt die Kombination aus `--keep-config` und Import sowie ungültige oder deaktivierte Übergaben vor Dienst- und Konfigurationsänderungen ab.

## Samba und GDT

Der Installer richtet die Gastfreigabe `kienzlefon-GDT` ein. Ein vorhandener Samba-Server benötigt `map to guest = Bad User` und die Standalone-Rolle. Bei `server role = auto` muss der erfolgreiche Aufruf `testparm -s /etc/samba/smb.conf` auf stderr eindeutig `Server role: ROLE_STANDALONE` melden. Eine ausdrücklich konfigurierte Rolle `standalone server` wird weiterhin akzeptiert.

Auf einer kompatiblen Kienzlefax-Installation ergänzt der Installer das Include `/etc/samba/kienzlefon-t2med.conf`. Die automatische Rollenwahl und die vorhandenen Fax-Freigaben bleiben erhalten. Bei einer Domänenrolle, fehlender oder widersprüchlicher Bestätigung für `auto` oder einer Kollision mit der GDT-Freigabe bzw. dem Include bricht die Vorprüfung vor Samba-Änderungen ab. Für eine selbst verwaltete Freigabe den Installer mit `--skip-samba` aufrufen.

```sh
sudo bash installer/install.sh --skip-samba
```

Dann muss jedes Client-Unterverzeichnis extern bereitstehen; SMB-Gäste müssen `AUFRUFi.gdt` lesen und löschen können, der Dienstbenutzer muss sie schreiben können.

### GDT-Vorlage in T2med importieren

**[GDT-Vorlage „Telefonaufruf“ herunterladen](https://raw.githubusercontent.com/thomaskien/kienzlefon-t2med/main/docs/templates/GDTGeraet_Telefonaufruf_Konfiguration.json)** · [Datei im Repository](templates/GDTGeraet_Telefonaufruf_Konfiguration.json)

1. JSON-Datei herunterladen und in T2med unter **Hauptmenü → Geräte → Geräte verwalten** importieren. Der Import steht seit T2med 26.1 zur Verfügung ([Versionshinweise](https://hilfe.t2med.de/blog/Version%2026.1/)).
2. Beim Gerät **Telefonaufruf** Import-/Exportverzeichnis, Stationszuordnung und Programm für den jeweiligen Arbeitsplatz anpassen. `/Volumes/kienzlefon-GDT/mac` und TextEdit sind nur macOS-Beispiele.
3. Die Kalenderanbindung auf die Importdatei des Arbeitsplatzes setzen, etwa `\\middleware-server\kienzlefon-GDT\202\AUFRUFi.gdt` unter Windows.
4. Einen freigegebenen Testpatienten öffnen und prüfen, ob die Akte am richtigen Arbeitsplatz erscheint.

`gdtId`/Absender ist `AUFRUF`, der T2med-Absender/Empfänger `T2MED_PX`, die Importdatei `AUFRUFi.gdt`. Die Middleware schreibt nur eine echte Patientennummer in den GDT-Satz; eine FHIR-ID wird nicht ersatzweise verwendet. Ein neuer Aufruf ersetzt eine noch nicht importierte Datei desselben Arbeitsplatzes atomar.

Für neue Arbeitsplätze bestimmt dieselbe Nummer den Platz in der App, das Telefon und das GDT-Unterverzeichnis. Führende Nullen zählen, etwa bei `001`. Bestehende individuelle Arbeitsplatzkennungen und GDT-Pfade bleiben beim Update erhalten.

Für Platz **202** den Startpfad `/telepraxis-app.php?arbeitsplatz=202&benutzer=202` auf dem App-Server öffnen und als Lesezeichen speichern. Damit übernimmt die Telepraxis-App beim Öffnen Platz und Bearbeiter. Bei einem bestehenden Arbeitsplatz mit individueller Kennung setzt der Installer diese als Platz und die zugehörige Nebenstellennummer als Bearbeiter.

## Telefonie-App und Netzwerk

Das Webmenü **Telefonie-App einrichten** bietet die Befehle für Windows, macOS und Linux. Die lokalen Installer registrieren `tel:` und `callto:` sowie den Kompakt-Launcher im Benutzerprofil; Details stehen unter [Desktop-Clients](CLIENTS.md).

Nur benötigte LAN-Verbindungen freigeben: Middleware laut `http.listen`, SMB TCP 445 und AMI laut Konfiguration. Middleware und AMI nicht ins Internet exponieren. Die Web/API-Anwendung hat keinen Benutzerlogin; `allowed_hosts`, `allowed_networks`, Same-Origin-Prüfung und flüchtiges Bootstrap-Token ersetzen keine Netztrennung.

## Optionaler APS-Altbetrieb

Mit `patients.provider = "aps"` fragt der Installer APS-Host, Port, Benutzer und Passwort ab. Der übliche Port ist `16567`, ein leeres Passwort ist zulässig. FHIR und APS verwenden HTTPS. Bei der Erstinstallation ist die Prüfung des Serverzertifikats standardmäßig aus. Mit **j** lässt sie sich aktivieren, wahlweise mit der System-CA oder einer eigenen CA-Datei. Updates übernehmen die bisherige Einstellung. Mit bekannter Rufnummer prüfen, ob das APS-Konto die nötigen Patienten sehen darf.

## Betrieb und Diagnose

```sh
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/server.php --config=/etc/kienzlefon-t2med/config.toml --check-config
sudo -u kienzlefon-t2med php -d pcre.jit=0 /opt/kienzlefon-t2med/bin/check-connections.php --config=/etc/kienzlefon-t2med/config.toml
systemctl status kienzlefon-t2med.service
journalctl -u kienzlefon-t2med.service -b -n 100 --no-pager -o cat
```

Im FHIR-Modus prüft `check-connections.php` AMI und den gespeicherten Bestand; den FHIR-Zugang prüft nur ein regulärer Abgleich aus T2med.

| Beobachtung | Prüfen |
| --- | --- |
| `Address already in use` | Freien Listen-Port sowie `http.public_url` und lokale Clients passend konfigurieren. |
| `Unbekannter Hostname` | Geöffnete Adresse gegen `http.allowed_hosts` und `http.public_url` prüfen. |
| Patientensuche leer/alt | Letzten FHIR-Status und erfolgreichen vollständigen Abgleich prüfen; ein fehlgeschlagener Import lässt den vorherigen Bestand aktiv. |
| AMI-Anmeldung fehlgeschlagen | Benutzer, Secret und IP-ACL am Asterisk-Server prüfen. |
| Warteschlange fehlt | AMI-Verbindung, Queue-Name und Berechtigungen nach [Asterisk-Vertrag](AMI_DIALPLAN.md) prüfen. |
| GDT übergeben, Akte bleibt zu | Arbeitsplatz, SMB-Pfad und T2med-Kalenderanbindung prüfen; die Webmeldung bestätigt nur die Dateiübergabe. |
## Nebenstellenanzeige der Kienzlefon-App

Das Setup richtet einen lokalen App-Zugang ein und prüft eine bereits installierte Praxis-App über ihren eigenen PHP-Proxy. Eine ausdrücklich gewählte andere Telefoniequelle bleibt bei Updates ausgewählt. `--with-app-telephony` wählt die lokale Middleware auch dann ausdrücklich aus. Neue Dienstschlüssel werden pro Installation erzeugt. Bei einem erneuten Lauf bleibt der vorhandene Schlüssel gültig.

### Vollinstallation und Reparatur auf dem Fon-Server

Für „Vollständige T2med-Middleware hier installieren und mit der App verbinden“ Kienzlefon **2.6.11** und T2med **1.5.6** zusammen auswählen. Damit lassen sich die Rufgruppen einer gekoppelten Praxis über einen authentifizierten App-Arbeitsplatz bedienen. Fon 2.6.10 unterstützt die lokale Zählereinrichtung. Der Universalinstaller übergibt Fon `--kombi --mit-t2med` und der Middleware `--with-app-telephony`. Bei einem Bestand mit Fon 2.6.9 den Fon-Schritt in diesen Lauf aufnehmen. Ist Fon bereits aktualisiert und der Control-Dienst eingerichtet, genügt für die Reparatur der Middleware:

```sh
bash installer/install.sh --with-app-telephony
```

Fon übernimmt die vorhandene Anlagenkennung aus Control oder Statistik. Fehlt sie überall, erzeugt der Fon-Installer eine Kennung und speichert sie. Die Middleware prüft die Kennung in beiden Konfigurationen und in den geladenen Asterisk-Eingangs- und Ereigniskontexten. Bei widersprüchlichen Kennungen nennt sie die betroffenen Einstellungen. Eine Wiederholung verwendet die vorhandenen Schlüssel und dieselbe SQLite-Datei.

Für neue Verbindungen erhält die Middleware einen eigenen Control-Schlüssel mit Modus 0600. Fon speichert den SHA-256-Fingerabdruck mit einer leeren Arbeitsplatzliste. Die Abschlussprüfung liest als `www-data` über den installierten PHP-Proxy alle vier Werte. Null ist gültig. Beginnt die Erfassung tagsüber, zeigt die Prüfung den Berliner Tageswert als Teiltag an.

Ab Fon 2.6.11 registriert der lokale Installer zusätzlich den Fingerabdruck eines privaten Rufgruppenschlüssels. Die Fon-Administration gibt die Arbeitsplätze sowie Hinzufügen und Entfernen je Gruppe und Nebenstelle frei. Für eine Erstinstallation ohne GDT-Arbeitsplätze kann das Nebenstellenmenü mit Enter abgeschlossen werden. Die Anmeldung, die Arbeitsplatzbindung und die Antwortfelder für die App beschreibt der [Vertrag für Rufgruppen](TELEFONIE-STEUERUNG.md#gekoppelte-praxis-arbeitsplätze-ab-middleware-156-und-fon-2611).

Für eine App auf einem anderen Rechner beim Middleware-Setup `--app-source-ip <private-IP-des-App-Servers>` verwenden. Der konfigurierte HTTP-Listener, seine erlaubten Hosts und das Praxisnetz/VPN müssen vom App-Server aus erreichbar sein. Dem dortigen App-Installer die private Basis-URL und den Wert der in `app_access.token_file` bezeichneten Schlüsseldatei über den geschützten Administrationsweg bereitstellen. Der App-Installer akzeptiert den Schlüssel über eine verdeckte Eingabe oder `--token-file`. Eine leere Liste von `allowed_clients` erlaubt die Übersicht. Für Schaltvorgänge gelten die zusätzlich eingerichteten Arbeitsplatz- und Anlagenrechte.
