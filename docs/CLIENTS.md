# Desktop-Clients für `tel:` und `callto:`

Die Desktop-Integration öffnet eine Wählbestätigung im normalen Browser. Sie führt selbst keinen Anruf aus und speichert keine APS-/AMI-Zugangsdaten. Vor jedem Browserstart fragt der Helper einmal `GET /api/v1/bootstrap` ab und prüft, ob Client-ID und Nebenstelle noch zusammengehören. Erst danach öffnet er:

```text
/waehlen?client_id=…&telefon=…&expected_extension=…&view=compact
```

Direktes Starten der installierten Anwendung öffnet nach derselben Prüfung die Kompaktübersicht. Der ältere, interaktiv bestätigte CLI-Direktmodus bleibt für bewusst manuell ausgeführte Befehle verfügbar; die Desktop-Registrierungen verwenden ihn nicht.

## Voraussetzungen

- Python 3.9 oder neuer muss für den jeweiligen Benutzer erreichbar sein.
- Die Middleware-URL darf nur aus `http://` oder `https://`, Host und optionalem Port bestehen, zum Beispiel `http://127.0.0.1:8765`.
- Client-ID und Nebenstelle müssen exakt der Serverkonfiguration entsprechen.
- Installation und Aktualisierung erfolgen als normaler Benutzer, nie mit `sudo` oder als Administrator.

Die Installer prüfen Eingaben zunächst in einem temporären Verzeichnis. `--dry-run` erzeugt keine dauerhafte Registrierung, öffnet keinen Browser und startet keinen Anruf. Eine bestehende eigene Installation wird aktualisiert; eine unbekannte Datei am gleichen Ziel bleibt erhalten und führt zu einer Fehlermeldung.

## Windows 10 und 11

In PowerShell aus dem Projektverzeichnis:

```powershell
powershell -ExecutionPolicy Bypass -File clients\install-windows.ps1 `
  -Url http://127.0.0.1:8765 -ClientId empfang-1 -Extension 201
```

Der Installer kopiert den Helper nach `%LOCALAPPDATA%\KienzlefonT2med`, legt die Konfiguration unter `%APPDATA%\KienzlefonT2med\client.json` mit eingeschränktem Benutzerbezug ab, registriert die Anwendung unter `HKCU` und erzeugt „Kienzlefon T2med“ im Startmenü. Der registrierte Aufruf verwendet die `pythonw.exe` derselben geprüften Python-Installation, sodass kein Konsolenfenster erscheint. Fehler werden als Windows-Dialog angezeigt.

Windows schützt die Protokoll-Standardwahl; der Installer verändert `UserChoice` nicht. Anschließend in **Einstellungen → Apps → Standard-Apps → Standards nach Linktyp auswählen** für `TEL` und `CALLTO` jeweils „Kienzlefon T2med“ auswählen. Je nach Windows-10/11-Version kann die Bezeichnung der Einstellungsseite leicht abweichen.

## macOS

```sh
clients/install-macos.sh --make-default \
  --url http://127.0.0.1:8765 --client-id empfang-1 --extension 201
```

Der Installer erzeugt `~/Applications/Kienzlefon T2med.app`, registriert deren `tel`-/`callto`-URL-Schemas bei LaunchServices und speichert die Konfiguration in `~/Library/Application Support/KienzlefonT2med/client.json`. Ein Protokollklick öffnet ohne zusätzlichen Bestätigungsdialog direkt die Browser-Bestätigung; ein Doppelklick auf die App öffnet die Kompaktübersicht. Fehler erscheinen als macOS-Hinweis.

Seit 1.3.4 wird eine von `osacompile` ausgelassene Bundle-ID angelegt. Nach dem Einfügen des Helpers und der URL-Schemas wird die App lokal neu signiert und die Signatur geprüft. Die Konfiguration wird erst nach erfolgreichem Aufbau und Registrierung der App gespeichert. Bei einem älteren Abbruch mit `CFBundleIdentifier ... Does Not Exist` das vollständige aktuelle Projekt herunterladen und den Installer erneut ausführen.

Die URL im Befehl als reine Adresse einfügen, ohne Markdown-Linksyntax. Aus dem Terminal startet die App so (Anführungszeichen wegen der Leerzeichen):

```sh
open "$HOME/Applications/Kienzlefon T2med.app"
```

Mit `--make-default` fordert der Installer über LaunchServices die benutzerspezifische Standardzuordnung für beide Protokolle an. Eine eventuelle macOS-Nachfrage bestätigen. Ohne diese Option wird die App nur registriert; eine bestehende Standard-App bleibt ausgewählt. Zum späteren Wechsel denselben Installationsbefehl mit `--make-default` erneut ausführen. Der Dry-run setzt auch mit dieser Option keine Standardzuordnung.

Technische Grundlage: Apples [LaunchServices-Zuordnung für URL-Schemas](https://developer.apple.com/documentation/coreservices/1447760-lssetdefaulthandlerforurlscheme).

## Linux

Zusätzlich zu Python werden `xdg-mime` aus `xdg-utils` und mindestens eines der Programme `zenity`, `kdialog`, `xmessage` oder `notify-send` für sichtbare Desktopfehler benötigt.

```sh
clients/install-linux.sh \
  --url http://127.0.0.1:8765 --client-id empfang-1 --extension 201
```

Der Installer verwendet `$XDG_DATA_HOME` und `$XDG_CONFIG_HOME`, ersatzweise `~/.local/share` und `~/.config`. Er installiert einen sichtbaren Anwendungsstarter für die Kompaktübersicht und einen terminalfreien Protokollhandler. `xdg-mime` setzt diesen wie bisher benutzerspezifisch für `tel` und `callto`. Manche Desktopumgebungen fragen beim ersten Aufruf trotzdem nach; dort „Kienzlefon T2med URL-Handler“ bestätigen.

## Aktualisierung und Prüfung

Für ein Update denselben Installer mit den aktuellen Werten erneut ausführen. Vorab kann die geplante Installation ohne bleibende Änderungen geprüft werden:

```sh
clients/install-macos.sh --dry-run --url http://127.0.0.1:8765 --client-id empfang-1 --extension 201
```

Unter Linux gilt derselbe Aufruf mit `install-linux.sh`; unter Windows wird `-DryRun` verwendet. Die gespeicherte Zuordnung lässt sich ohne POST und ohne Browser prüfen:

```sh
python3 clients/kienzlefon_call.py \
  --config ~/.config/kienzlefon-t2med/client.json --check-registration
```

Die macOS- und Windows-Konfigurationspfade stehen oben. Ein lokaler URI-Test ohne Netzwerk- oder Browserzugriff ist ebenfalls möglich:

```sh
python3 clients/kienzlefon_call.py \
  --config ~/.config/kienzlefon-t2med/client.json \
  --open-browser --dry-run 'tel:+493012345'
```

Akzeptiert werden nur Rufnummern mit 3 bis 20 Ziffern, optional führendem `+` und üblichen Trennzeichen. URI-Parameter, Fragmente, Steuerzeichen und andere Schemas werden abgewiesen. Die Browser-URL wird mit standardkonformer URL-Kodierung erzeugt.
