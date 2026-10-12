# Änderungen

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
