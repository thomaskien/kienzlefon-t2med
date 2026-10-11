# Installation und Betrieb

## Server vorbereiten

Unterstützt werden Debian 12+, Ubuntu 24.04+ und Raspberry Pi OS Bookworm+ (arm64/armhf) mit systemd und PHP 8.2 oder neuer. Fehlende PHP-/Composer-Pakete werden über APT installiert. PHP muss die Erweiterungen `json`, `openssl` und `mbstring` laden. Das Quellpaket muss die eingecheckte `composer.lock` sowie `bin/server.php` enthalten. Eine Live-Installation auf macOS ist absichtlich gesperrt; dort funktionieren nur Hilfe und Planansicht:

```sh
installer/install.sh --help
installer/install.sh --dry-run
```

Der echte Lauf benötigt root und installiert die Paketabhängigkeiten über APT:

```sh
sudo installer/install.sh
```

Nach Bereitstellung fehlender PHP-/Composer-Pakete fragt der Installer APS-Host, Port, Benutzer, Passwort, TLS und die ausdrückliche TLS-Prüfausnahme ab. Ein leeres APS-Passwort ist gültig. Ebenso werden AMI-Host, Port, Benutzer, Secret und TLS, die öffentliche Middleware-URL samt unprivilegiertem Listen-Port ab 1024, der absolute GDT-Root und mindestens ein Client mit ID, Label, Nebenstelle und Verzeichnis erfasst. Secrets werden verdeckt gelesen und weder in Argumenten noch Logs ausgegeben.

Die Anwendung landet root-owned unter `/opt/kienzlefon-t2med`, die Konfiguration unter `/etc/kienzlefon-t2med/config.json` mit `root:kienzlefon-t2med` und Modus `0640`. Der unprivilegierte systemd-Dienst schreibt nur in den konfigurierten GDT-Root und sein privates Runtime-Verzeichnis. Das Konfigurationsschema entspricht [config.example.json](../config.example.json).

## Update und Neukonfiguration

Ein erneuter normaler Lauf aktualisiert Anwendung, Abhängigkeiten und Unit idempotent. Eine vorhandene Serverkonfiguration wird validiert, aber nicht überschrieben. Für bewusste Änderungen gilt:

```sh
sudo installer/install.sh --reconfigure
```

Bei der Neukonfiguration werden APS-Passwort und AMI-Secret getrennt behandelt: „beibehalten“ übernimmt den vorhandenen Wert; nach „nein“ setzt eine leere Eingabe den Wert ausdrücklich leer. Ein Fehlschlag stellt bekannte vorherige App-, Config-, Unit- und Samba-Dateien wieder her. Neu angelegte Paketinstallationen, der Dienstbenutzer und sichere leere GDT-Verzeichnisse werden nicht automatisch entfernt.

## Samba und GDT

Auf einer frischen Maschine richtet der Installer einen Standalone-Guest-Server ein. Bei vorhandenem Samba akzeptiert er nur die bereits wirksamen Werte `server role = standalone server` und `map to guest = Bad User`. Er ergänzt dann ein separates Include `/etc/samba/kienzlefon-t2med.conf`; der bestehende Text der Hauptkonfiguration bleibt bis auf die eingefügte Include-Zeile erhalten; andere Freigaben, Benutzer und globale Einstellungen werden nicht ersetzt. Die neue Freigabe heißt `kienzlefon-GDT`, nutzt `force user = kienzlefon-t2med` und erlaubt Gästen Lesen, Schreiben und Löschen von `AUFRUFi.gdt`. `testparm` prüft die Kandidatenkonfiguration vor dem Reload. Ein bereits aktiver Samba-Dienst wird nur neu geladen, nicht neu gestartet.

Eine fremde Freigabe gleichen Namens, ein abweichendes bestehendes Managed-Include, eine andere Serverrolle oder ein inkompatibles Guest-Mapping führt vor Änderungen an bestehenden Samba-Dateien zu einem konkreten Konflikt. Die globale Authentifizierung wird nie still geändert. In diesem Fall kann ein Administrator das Share auf demselben oder einem separaten Host selbst bereitstellen und danach ausführen:

```sh
sudo installer/install.sh --skip-samba
```

Das externe Share muss pro konfiguriertem Client dessen Unterverzeichnis bereitstellen. SMB-Gäste müssen `AUFRUFi.gdt` darin lesen und löschen können; der Dienstbenutzer muss schreiben können. Vorhandene GDT-Verzeichnisse werden weder rekursiv übernommen noch umgechownt. Bei `--skip-samba` prüft der Installer daher nur lokale Pfade und Berechtigungen; die externe SMB-Funktion muss der Betreiber separat testen.

APS, AMI und Middleware dürfen auf demselben oder auf getrennten Hosts laufen. `aps.url`, `ami.host` und `http.public_url` müssen jeweils auf den tatsächlich erreichbaren Host zeigen. Nur benötigte LAN-Verbindungen freigeben: standardmäßig Middleware-TCP laut `http.listen` (Beispiel 8765), SMB TCP 445, APS laut Konfiguration (Beispiel 16567) und AMI laut Konfiguration (Beispiel 5038). Middleware und AMI nicht ins Internet exponieren.

## Lokale Click-to-Dial-Registrierung

Jeder Arbeitsplatz bekommt ausschließlich Middleware-URL, Client-ID und die dazu gehörige Nebenstelle. APS-/AMI-Zugangsdaten werden nie lokal gespeichert. Der gemeinsame Python-3-Helper validiert `tel:`/`callto:` streng, prüft Client-ID und Nebenstelle gegen `GET /api/v1/bootstrap` und sendet genau einen Auftrag an `POST /api/v1/calls`. Er wiederholt ein ungewisses Originate nicht automatisch. HTTP 202 wird als „Auftrag angenommen“, nicht als zustande gekommenes Gespräch, angezeigt.

Linux, als angemeldeter Desktopbenutzer ohne `sudo`:

```sh
clients/install-linux.sh --url http://middleware.example:8765 --client-id empfang-1 --extension 201
```

macOS, ebenfalls ohne `sudo` (Python 3 muss separat vorhanden sein):

```sh
clients/install-macos.sh --url http://middleware.example:8765 --client-id empfang-1 --extension 201
```

Windows PowerShell, ohne Administratorrechte:

```powershell
powershell -ExecutionPolicy Bypass -File .\clients\install-windows.ps1 -Url http://middleware.example:8765 -ClientId empfang-1 -Extension 201
```

Der Windows-Installer prüft ausdrücklich auf Python 3.9+. Linux legt eine Desktopdatei im Benutzerprofil an, macOS eine `.app` mit `CFBundleURLTypes`, Windows registriert eine eigene Anwendung unter `HKCU` für `tel` und `callto`; die Zuordnung erfolgt in den Windows-Standard-Apps. Vor jedem echten POST muss der Benutzer den Wahlvorgang bestätigen; ohne interaktives Terminal beziehungsweise den macOS-Dialog bricht der Helper ab. So löst das bloße Öffnen eines fremden Weblinks keinen automatischen Anruf aus. Das Betriebssystem kann zusätzlich eine Bestätigung oder Auswahl der Standard-App verlangen; diese Schutzabfrage wird nicht umgangen. Falls ein anderer Handler Standard bleibt, in den jeweiligen Standard-App-Einstellungen „Kienzlefon T2med“ für TEL/CALLTO wählen.

Die Shell-Installer besitzen `--dry-run`, der Windows-Installer `-DryRun`. Der Helper selbst kann ohne HTTP-POST und ohne Telefonat getestet werden:

```sh
python3 clients/kienzlefon_call.py --config ~/.config/kienzlefon-t2med/client.json --dry-run 'tel:%2B49%2030%2012345'
```

Der Helper weist dabei darauf hin, dass die Serverzuordnung offline nicht geprüft wurde. Ein normaler Aufruf verwendet kurze Timeouts, lehnt Redirects und HTTP-Fehler ab und führt URI-Inhalte nie als Shellcode aus.

## Betrieb und Diagnose

Schlägt der Dienststart während der Installation fehl, zeigt der Installer vor dem
Rollback den systemd-Status und die Journalmeldungen dieses Startversuchs an.
Die Konfigurationsdatei mit Zugangsdaten wird dabei nicht ausgegeben. Das
Dienstjournal kann auch nach dem Rollback gelesen werden:

```sh
journalctl -u kienzlefon-t2med.service -b -n 100 --no-pager -o cat
```

```sh
systemctl status kienzlefon-t2med.service
journalctl -u kienzlefon-t2med.service
systemctl status smbd.service
testparm -s /etc/samba/smb.conf
```

Der Dienst braucht keine PID-Datei. Nach Konfigurationsänderungen kann er mit `systemctl restart kienzlefon-t2med.service` neu gestartet werden. Die Web/API-Anwendung hat keine Benutzeranmeldung; Zugriffsschutz erfolgt deshalb durch LAN-Firewall, `allowed_hosts`, `allowed_networks`, Same-Origin-Prüfung und das flüchtige Bootstrap-Token. Ein fremder Weblink darf ohne echte Benutzeraktion keinen lokalen Wahlvorgang auslösen.

## Entwicklertests

```sh
bash -n installer/install.sh clients/install-linux.sh clients/install-macos.sh
php -l installer/configure.php
php -l installer/samba.php
python3 -m unittest discover -s tests/clients -v
php tests/installer/run.php
python3 -m unittest discover -s tests/installer -v
installer/install.sh --help
installer/install.sh --dry-run
git diff --check
```

Diese Tests prüfen Parser, Planer und Clients ohne Liveänderungen. Windows-Registry, macOS LaunchServices, Linux systemd/APT/Samba und ein echtes Originate müssen auf den jeweiligen Zielsystemen separat als Integrationstest geprüft werden.
