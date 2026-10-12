# Installation und Betrieb

[← Projektübersicht](../README.md) · [FHIR einrichten](FHIR.md) · [Bedienung](WEB.md) · [Desktop-Clients](CLIENTS.md)

FHIR ist die Standardquelle. APS wird nur verwendet, wenn `patients.provider = "aps"` ausdrücklich gesetzt ist; es gibt keinen automatischen Rückfall.

## Voraussetzungen

Unterstützt werden Debian 12+, Ubuntu 24.04+ und Raspberry Pi OS Bookworm+ (arm64/armhf) mit systemd und PHP 8.2+. Der Installer ergänzt fehlende APT-/Composer-Pakete; PHP benötigt `json`, `openssl`, `mbstring` und für FHIR `sqlite3`.

Vorab bereitlegen:

- Asterisk-/AMI-Host, Port, Benutzer, Secret und TLS-Einstellung; üblicher AMI-Port ist `5038`.
- Erreichbare Middleware-Adresse, freien HTTP-Port (Standard **8766**) und absoluten GDT-Root. Vorhandene abweichende Ports bleiben bei Updates erhalten.
- Pro Arbeitsplatz Client-ID, Bezeichnung, Nebenstelle und GDT-Unterverzeichnis.
- FHIR-Basisadresse; den kurzlebigen OAuth-Token liefert T2med bei jedem Abgleich.

AMI und Dialplan werden nach dem [Asterisk-Vertrag](AMI_DIALPLAN.md) eingerichtet. Zugangsdaten nie in URLs, Beispieldateien oder Logs übernehmen.

## Installieren

```sh
git clone https://github.com/thomaskien/kienzlefon-t2med.git
cd kienzlefon-t2med
sudo bash installer/install.sh
```

Eine Live-Installation auf macOS ist gesperrt; dort stehen nur `installer/install.sh --help` und `installer/install.sh --dry-run` zur Verfügung.

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

## Samba und GDT

Der Installer richtet die Guestfreigabe `kienzlefon-GDT` ein. Bei kompatiblem vorhandenem Samba (`standalone server`, `map to guest = Bad User`) ergänzt er `/etc/samba/kienzlefon-t2med.conf`, ohne andere Freigaben oder Benutzer zu ersetzen. Konflikte werden vor Änderungen gemeldet. Für eine selbst verwaltete Freigabe:

```sh
sudo bash installer/install.sh --skip-samba
```

Dann muss jedes Client-Unterverzeichnis extern bereitstehen; SMB-Gäste müssen `AUFRUFi.gdt` lesen und löschen können, der Dienstbenutzer muss sie schreiben können.

### GDT-Vorlage in T2med importieren

**[GDT-Vorlage „Telefonaufruf“ herunterladen](https://raw.githubusercontent.com/thomaskien/kienzlefon-t2med/main/docs/templates/GDTGeraet_Telefonaufruf_Konfiguration.json)** · [Datei im Repository](templates/GDTGeraet_Telefonaufruf_Konfiguration.json)

1. JSON-Datei herunterladen und in T2med unter **Hauptmenü → Geräte → Geräte verwalten** importieren. Der Import steht seit T2med 26.1 zur Verfügung ([Versionshinweise](https://hilfe.t2med.de/blog/Version%2026.1/)).
2. Beim Gerät **Telefonaufruf** Import-/Exportverzeichnis, Stationszuordnung und Programm für den jeweiligen Arbeitsplatz anpassen. `/Volumes/kienzlefon-GDT/mac` und TextEdit sind nur macOS-Beispiele.
3. Die Kalenderanbindung auf die Importdatei des Arbeitsplatzes setzen, etwa `\\middleware-server\kienzlefon-GDT\empfang-1\AUFRUFi.gdt` unter Windows.
4. Einen freigegebenen Testpatienten öffnen und prüfen, ob die Akte am richtigen Arbeitsplatz erscheint.

`gdtId`/Absender ist `AUFRUF`, der T2med-Absender/Empfänger `T2MED_PX`, die Importdatei `AUFRUFi.gdt`. Die Middleware schreibt nur eine echte Patientennummer in den GDT-Satz; eine FHIR-ID wird nicht ersatzweise verwendet. Ein neuer Aufruf ersetzt eine noch nicht importierte Datei desselben Arbeitsplatzes atomar.

Client-ID, Nebenstelle und Unterverzeichnis gehören zusammen. `001` und `1` sind verschiedene IDs. Beispiel:

```text
kienzlefon-GDT/
  empfang-1/AUFRUFi.gdt
  empfang-2/AUFRUFi.gdt
```

## Telefonie-App und Netzwerk

Das Webmenü **Telefonie-App einrichten** bietet die Befehle für Windows, macOS und Linux. Die lokalen Installer registrieren `tel:` und `callto:` sowie den Kompakt-Launcher im Benutzerprofil; Details stehen unter [Desktop-Clients](CLIENTS.md).

Nur benötigte LAN-Verbindungen freigeben: Middleware laut `http.listen`, SMB TCP 445 und AMI laut Konfiguration. Middleware und AMI nicht ins Internet exponieren. Die Web/API-Anwendung hat keinen Benutzerlogin; `allowed_hosts`, `allowed_networks`, Same-Origin-Prüfung und flüchtiges Bootstrap-Token ersetzen keine Netztrennung.

## Optionaler APS-Altbetrieb

Nur mit `patients.provider = "aps"` fragt der Installer beziehungsweise Dienst APS-Host, Port, Benutzer, Passwort und TLS ab; üblicher Port ist `16567`. Ein leeres Passwort ist zulässig. Eine TLS-Prüfausnahme wird nur ausdrücklich gesetzt; alternativ `aps.ca_file` verwenden. Mit bekannter Rufnummer prüfen, ob das APS-Konto die nötigen Patienten sehen darf. Vorhandene APS-Zugangsdaten bleiben bei Updates erhalten.

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
