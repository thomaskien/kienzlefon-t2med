# Änderungen

## 1.4.3 · 2026-10-12

- Nebenstellen zeigen FREI grün, klingelt gelb und BELEGT rot; ein Klick wählt über den ausgewählten Arbeitsplatz.
- Wahlhilfe zeigt bei eindeutiger Rufnummernzuordnung den Namen aus dem FHIR-Bestand, sonst eine leere Namensangabe.
- Kompakte Fußzeile: „kienzlefon v1.4.3 von Dr. Thomas Kienzle“.
- Hinweis zum Demo-Key (100 Requests), gestraffte Dokumentation und T2med-Einrichtung unter „Drittanbieter konfigurieren“. Beide Übergaben (`?` und `#`) sind praktisch bestätigt.
- Kurze Hinweise zum ersten und erneuten FHIR-Abgleich, einheitlicher Middleware-Port 8766 in Beispielen und MIT-Lizenz ergänzt.

## 1.4.2 — 12.10.2026

- Gemeinsames Suchfeld für Patientennamen, Namensbestandteile, Patientennummern und Telefonnummern. Vor- und Nachname funktionieren in beliebiger Reihenfolge, einschließlich Umlautschreibweisen wie Müller/Mueller.
- Patienten ohne Telefonnummer werden gefunden. Akten werden aus der Trefferliste ausgewählt; die automatische Anruferkennung verwendet weiterhin ausschließlich Telefonnummern.
- Namen können keine Wählanfrage auslösen. Breite Namenssuchen zeigen bis zu 200 Treffer mit Hinweis zum Präzisieren; Rufnummerntreffer bleiben vollständig.
- „Telefonverzeichnis über FHIR aktualisieren“ benennt die Schnittstelle prominent in Überschrift und Einleitung.

## 1.4.1 — 12.10.2026

- Ortsnummern ohne Vorwahl werden ab drei Ziffern über ihr vollständiges Rufnummernende erkannt. Alle passenden Akten bleiben auswählbar, auch bei unterschiedlich langen oder zusätzlich vollständigen Treffern.
- Vorhandene FHIR-Bestände verwenden die Erkennung ohne erneuten Import. Originalwerte und Importzeit bleiben erhalten.
- Statusseite zählt nutzbare Ortsnummern mit und erklärt die Erkennung; gespeicherte, nicht nutzbare Werte werden eindeutig benannt.

## 1.4.0 — 12.10.2026

- FHIR ersetzt APS als Standardquelle. APS bleibt ausdrücklich aktivierbar; GDT verwendet unverändert die echte Patientennummer.
- T2med-Browserstart mit Query (`?`) und Fragment (`#`), Statusseite mit Fortschritt, Bestandszahl, Alter und Fehlern.
- Serverseitige Linux-Binary mit eingebettetem Demo-API-Key; kurzlebiger Bearer-Token ausschließlich über Arbeitsspeicher und stdin.
- Vollständiger paginierter Import, atomarer SQLite-Bestand, Schutz vor parallelen Importen und Erhalt des bisherigen Bestands bei Fehlern.
- Mehrfachtreffer bleiben getrennt; fehlende Patientennummer sperrt den Aktenaufruf. Nicht zuordenbare Telefonwerte werden gezählt.

## 1.3.6 – 12.10.2026

- Rückrufaufträge mit „Nochmal versuchen“, „Löschen“ und „Liste bereinigen“. Löschen betrifft die Historie; laufende Gespräche und der Schutz vor doppelten Wählaufträgen bleiben erhalten. Wiederholen verwendet die ursprüngliche Rufnummer und Nebenstelle und ist nur nach abgeschlossenem Auftrag und bei freier Nebenstelle möglich.
- Verwaiste Wählstatus werden nach einem vollständigen AMI-Bestandsabgleich abgeschlossen. Laufende und ungewisse Aufträge verlieren ihren Doppelanrufschutz nicht mehr nach zehn Minuten.
- Footer in beiden Ansichten mit Autor „Dr. Thomas Kienzle“ und installierter Version.
- Kein separater Vermittlungsbereich in der Kompaktansicht; aktive Rückfragen bleiben in der vollständigen Übersicht bedienbar. Rückrufknopf aus den Details laufender Anrufe entfernt.

## 1.3.5 – 12.10.2026

- `tel:`-/`callto:`-Handler für Windows, macOS und Linux senden den Wählauftrag direkt an die Middleware, ohne Rückfrage, Browserfenster oder Erfolgsmeldung. Vor dem Auftrag wird die Arbeitsplatzzuordnung geprüft; Fehler bleiben sichtbar, Aufträge werden nicht automatisch wiederholt.
- Bestehende lokale Clients durch erneuten Aufruf ihres Installers aktualisieren. Ein normaler App-Start öffnet weiterhin die Kompaktübersicht.

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
