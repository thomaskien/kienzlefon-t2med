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

Die Anwendung landet root-owned unter `/opt/kienzlefon-t2med`, die Konfiguration unter `/etc/kienzlefon-t2med/config.toml` mit `root:kienzlefon-t2med` und Modus `0640`. Der unprivilegierte systemd-Dienst schreibt nur in den konfigurierten GDT-Root und sein privates Runtime-Verzeichnis. Das kommentierte Konfigurationsschema steht in [config.example.toml](../config.example.toml); `config.example.json` bleibt als Legacy-Referenz erhalten.

Der Installer prüft die HTTP-Listenadresse vor den Anwendungs- und Samba-Änderungen.
Beim Update einer laufenden Middleware erfolgt die Prüfung nach deren regulärem
Stop. Ein fremder Dienst auf demselben Port wird nicht beendet. Bei einem Konflikt
(beispielsweise `Address already in use` auf 8765) einen freien Port wählen und
auch die öffentliche URL anpassen, etwa Listen-Port `8766` und
`http://middleware.example:8766`. Bestehende Client-Installationen müssen ebenfalls
die neue URL verwenden. Die Prüfung reserviert den Port nicht dauerhaft; ein
späterer Konflikt wird weiterhin durch die Startdiagnose erfasst.

Die systemd-Unit deaktiviert PHPs PCRE-JIT nur für diesen Dienst mit
`-d pcre.jit=0`, damit die Regex-Verarbeitung mit `MemoryDenyWriteExecute=yes`
ohne JIT-Speicherwarnung läuft. Die systemweite PHP-Konfiguration bleibt erhalten.

## Update und Neukonfiguration

Ein erneuter normaler Lauf aktualisiert Anwendung, Abhängigkeiten und Unit idempotent. Im Terminal fragt er `Vorhandene Einstellungen ändern (z. B. Nebenstellen/Arbeitsplätze)? [j/N]`. Bei „Nein“ bleibt eine vorhandene TOML-Datei einschließlich Kommentaren bytegleich. Ohne Terminal werden vorhandene Werte ebenfalls ohne Nachfrage übernommen. `--keep-config` überspringt die Nachfrage ausdrücklich. Die Option kann nicht mit `--reconfigure` kombiniert werden.

Eine Installation mit ausschließlich `/etc/kienzlefon-t2med/config.json` wird beim Update validiert und verlustfrei nach `config.toml` migriert. Die ursprüngliche JSON-Datei bleibt unverändert als Altbestand/Sicherung liegen. Sind beide Dateien vorhanden, hat TOML Vorrang; ungültiges TOML führt zu einem Fehler und wird nicht durch JSON verdeckt. Eine JSON-Datei kann weiterhin explizit mit `--config=...json` geladen werden.

Für bewusste Änderungen gilt weiterhin:

```sh
sudo installer/install.sh --reconfigure
```

Bei der Neukonfiguration werden APS-Passwort und AMI-Secret getrennt behandelt: „beibehalten“ übernimmt den vorhandenen Wert; nach „nein“ setzt eine leere Eingabe den Wert ausdrücklich leer. Vorhandene Arbeitsplätze werden mit Label, Nebenstelle und Verzeichnis angeboten und können geändert, entfernt oder ergänzt werden. Individuelle Netzwerk-, Queue-, Timeout- und GDT-Kennwerte bleiben erhalten. Ein Fehlschlag stellt bekannte vorherige App-, TOML-, Unit- und Samba-Dateien wieder her; nach einer fehlgeschlagenen Erst-Migration bleiben die alte JSON-Datei und ihre bisherige Unit nutzbar. Neu angelegte Paketinstallationen, der Dienstbenutzer und sichere leere GDT-Verzeichnisse werden nicht automatisch entfernt.

## Vermitteln

Die neue Bedienung benötigt zusätzlich die [Einrichtung im kienzlefon-Telefonprojekt](TRANSFER_HANDOFF.md). `telephony.transfer_context` erhält bei bestehenden Konfigurationen automatisch den Standard `kienzlefon-transfer`. Der Middleware-Installer ändert den Asterisk-Server nicht.

## Samba und GDT

Ein neuer Aktenaufruf ersetzt eine noch nicht importierte `AUFRUFi.gdt` atomar im gewählten Clientverzeichnis. Es gilt der neueste Aufruf; daraus entsteht keine Warteschlange mehrerer GDT-Dateien.

Auf einer frischen Maschine richtet der Installer einen Standalone-Guest-Server ein. Bei vorhandenem Samba akzeptiert er nur die bereits wirksamen Werte `server role = standalone server` und `map to guest = Bad User`. Er ergänzt dann ein separates Include `/etc/samba/kienzlefon-t2med.conf`; der bestehende Text der Hauptkonfiguration bleibt bis auf die eingefügte Include-Zeile erhalten; andere Freigaben, Benutzer und globale Einstellungen werden nicht ersetzt. Die neue Freigabe heißt `kienzlefon-GDT`, nutzt `force user = kienzlefon-t2med` und erlaubt Gästen Lesen, Schreiben und Löschen von `AUFRUFi.gdt`. `testparm` prüft die Kandidatenkonfiguration vor dem Reload. Ein bereits aktiver Samba-Dienst wird nur neu geladen, nicht neu gestartet.

Eine fremde Freigabe gleichen Namens, ein abweichendes bestehendes Managed-Include, eine andere Serverrolle oder ein inkompatibles Guest-Mapping führt vor Änderungen an bestehenden Samba-Dateien zu einem konkreten Konflikt. Die globale Authentifizierung wird nie still geändert. In diesem Fall kann ein Administrator das Share auf demselben oder einem separaten Host selbst bereitstellen und danach ausführen:

```sh
sudo installer/install.sh --skip-samba
```

Das externe Share muss pro konfiguriertem Client dessen Unterverzeichnis bereitstellen. SMB-Gäste müssen `AUFRUFi.gdt` darin lesen und löschen können; der Dienstbenutzer muss schreiben können. Vorhandene GDT-Verzeichnisse werden weder rekursiv übernommen noch umgechownt. Bei `--skip-samba` prüft der Installer daher nur lokale Pfade und Berechtigungen; die externe SMB-Funktion muss der Betreiber separat testen.

APS, AMI und Middleware dürfen auf demselben oder auf getrennten Hosts laufen. `aps.url`, `ami.host` und `http.public_url` müssen jeweils auf den tatsächlich erreichbaren Host zeigen. Nur benötigte LAN-Verbindungen freigeben: standardmäßig Middleware-TCP laut `http.listen` (Beispiel 8765), SMB TCP 445, APS laut Konfiguration (Beispiel 16567) und AMI laut Konfiguration (Beispiel 5038). Middleware und AMI nicht ins Internet exponieren.

## Lokale Click-to-Dial-Registrierung

Die Weboberfläche bietet im Menü **Telefonie-App einrichten** passende Befehle
für den ausgewählten Arbeitsplatz und Windows, macOS oder Linux. Die Installer
registrieren `tel:` und `callto:` sowie einen Launcher für die Kompaktübersicht.
Voraussetzung ist Python 3.9 oder neuer. Installation im Benutzerprofil, ohne
Administratorrechte beziehungsweise `sudo`.

Der lokale Helper speichert nur Middleware-URL, Client-ID und zugeordnete
Nebenstelle. Vor dem Öffnen prüft er diese Zuordnung per GET am Server. Der
Desktopaufruf öffnet die Wählbestätigung im Browser; den Anruf startet der dortige
Button. APS-/AMI-Zugangsdaten bleiben auf dem Server. Die Windows-/macOS-Auswahl
der Standard-App erfolgt über das Betriebssystem.

Ausführliche Anleitungen, Update und lesende Registrierungsprüfung:
[Desktop-Clients](CLIENTS.md). Kompaktansicht, Direktwahl ohne Rückfrage und
Aktenlinks: [Oberfläche und Weblinks](WEB.md).

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

Der Dienst braucht keine PID-Datei. Nach Konfigurationsänderungen kann er mit `systemctl restart kienzlefon-t2med.service` neu gestartet werden. Für eine reine Verbindungsprüfung dient `php bin/check-connections.php --config=/etc/kienzlefon-t2med/config.toml`. Die Web/API-Anwendung hat keine Benutzeranmeldung; Zugriffsschutz erfolgt deshalb durch LAN-Firewall, `allowed_hosts`, `allowed_networks`, Same-Origin-Prüfung und das flüchtige Bootstrap-Token. Die explizite Direktwahl-URL mit `sofort=1` startet nach aktiver Navigation im sichtbaren Browser einen Anruf ohne zusätzlichen Dialog; normale Wähl-URLs und Desktop-Handler verwenden die Bestätigung. Bloße HTTP-GETs führen keinen Anruf aus.

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
