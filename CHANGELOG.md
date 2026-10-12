# Änderungen

## 1.3.4 – 12.10.2026

- macOS-Installer erzeugt eine fehlende `CFBundleIdentifier` korrekt und signiert die fertige App einschließlich Protokollregistrierung und Python-Helper neu. Die Konfiguration wird erst nach erfolgreichem App-Aufbau und LaunchServices-Registrierung gespeichert.
- Native macOS-Bundleprüfung für Erstinstallation und vorhandene Metadaten, auch in GitHub Actions.

## 1.3.3 – 12.10.2026

- Installer zeigt beim Start die Programmversion aus der zentralen Versionsdatei an, auch bei `--dry-run` und `--help`.
- Rein numerische Client-IDs wie `201` werden im Installer akzeptiert und von der Bootstrap-API immer als Zeichenkette geliefert. Arbeitsplatzwahl, Aktenaufruf und Telefonie-App behalten dadurch die korrekte Zuordnung; führende Nullen bleiben erhalten.

## 1.3.2 – 12.10.2026

- Click-to-Dial sendet die Zielrufnummer als Anzeigenamen an die Nebenstelle. Der feste Name „Rueckruf“ wird nicht mehr übertragen, damit er nicht als Name zur Zielnummer gespeichert werden kann. Bereits gespeicherte Telefonanzeigen werden dadurch nicht rückwirkend geändert.

## 1.3.1 – 12.10.2026

- Getrennte Bereiche für Gespräche und Warteschlange; „Alle Gespräche“ / „Eigene Gespräche“ als Umschalter.
- Geburtsdatum ungefettet direkt hinter dem Patientennamen in der Kompaktansicht.
- Vermittlung über einen kleinen Pfeil: intern/extern, direkt oder mit Rückfrage; Abschluss und Rückkehr zum Anrufer anhand des AMI-Zustands. Erfordert die ergänzte Einrichtung im Telefonprojekt, siehe `docs/TRANSFER_HANDOFF.md`.
- Eine noch nicht importierte GDT-Datei wird atomar durch den neuesten Aktenaufruf ersetzt. Wiederholung desselben Auftrags schreibt eine neuere Datei nicht zurück.

## 1.3 – 12.10.2026

- Kompaktansicht: „Alle Gespräche“ zeigt auf Wunsch Verbindungen aller Nebenstellen. Die Auswahl bleibt während der Tab-Sitzung auch nach Neuladen erhalten.
- Patientensuche: nationale Rufnummern sowie `+49…`, `0049…` und `49…` verwenden dieselben APS-Suchvarianten und denselben Cache.
- Versionsanzeige im Menü, in Bootstrap/Health-API und über `bin/server.php --version`.
- Serverkonfiguration in TOML mit Übernahme bestehender JSON-Konfigurationen.
- Installer fragt bei bestehenden Installationen nach gewünschten Änderungen und bietet bisherige Werte an.
