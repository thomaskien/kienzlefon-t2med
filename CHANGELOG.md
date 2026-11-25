# Änderungen

## 1.5.8

Die Aktentreffer für die Praxis-App enthalten Vorname und Nachname als getrennte Felder. Damit kann die App auch mehrteilige Namen in der Reihenfolge „Nachname, Vorname“ anzeigen.

## 1.5.7

Die Praxis-App erhält einen eigenen Lesezugang zur Rufnummernsuche und einen separaten Zugang für Arbeitsplatzgespräche, Wählen, Vermitteln und das Öffnen eines bestätigten Aktentreffers. Der FHIR-Import übernimmt vollständige Geburtsdaten für die Aktentreffer.

Bei einem Rufgruppenanruf meldet die Middleware das Klingeln an den tatsächlich gerufenen Arbeitsplätzen bereits während der Wartezeit. Für klingelnde und angenommene Gespräche liefert sie die einzelnen T2med-Aktentreffer.


## 1.5.6 · Rufgruppen für authentifizierte Praxis-Arbeitsplätze

Mit Kienzlefon 2.6.11 koppelt die lokale Einrichtung einen eigenen Rufgruppenschlüssel. Die Praxis-App kann den wirksamen Arbeitsplatzumfang abrufen. Bei administrativ freigegebenem „Alle Arbeitsplätze“ können auch neue authentifizierte Plätze die erlaubten Richtungen bedienen. Ausgewählte Plätze, Lesezugänge, Gruppenschutz und Arbeitsplatzbindung der Ergebnisse bleiben wirksam. Den HTTP-Vertrag beschreibt [die Anbindungsdokumentation](docs/TELEFONIE-STEUERUNG.md).

## 1.5.5 · Anrufzähler bei lokaler Vollinstallation

Mit Kienzlefon ab 2.6.10 richtet die lokale Vollinstallation alle vier App-Zähler ein. Fon und Middleware verwenden dieselbe Anlagenkennung. Ein eigener privater Control-Schlüssel erlaubt das Lesen der Live-Zähler. Die Abschlussprüfung liest Warteschleife, IVR, AI und den Berliner Tageszähler über den installierten App-PHP-Proxy.

Bei einer bisherigen localhost-Standardkonfiguration ergänzt die Einrichtung die lokale IP-Adresse für den Zugriff auf das Telefonverzeichnis aus T2med. Die HTTP-Fehlermeldung unterscheidet eine nicht freigegebene Zieladresse vom gesperrten Quellnetz.

## 1.5.4 · Nebenstellen auswählen und FHIR per IP einrichten

- Bei der Erstinstallation genügt die IP-Adresse des T2med-Servers. HTTPS, Port 16567 und der FHIR-Pfad sind vorgegeben. Enter übernimmt bei einer Neukonfiguration die vorhandene Adresse.
- Das nummerierte Arbeitsplatzmenü zeigt für jede angebotene Nebenstelle den Zustand. Gewählte Menünummern schalten die Plätze um. Der Startpfad für die Telepraxis-App setzt den Bearbeiter auf die Nebenstellennummer. Die Auswahl bleibt für spätere Änderungen gespeichert.
- Bei der Ersteinrichtung des App-Zugangs erzeugt der Installer zuerst den privaten Dienstschlüssel. Anschließend prüft er die vollständige Konfiguration als Dienstbenutzer. Ein Update verwendet den bestehenden Schlüssel.

## 1.5.3 · Nebenstellennummer als Arbeitsplatz

Die Erstinstallation übernimmt die Nebenstellen aus der Fon-Übergabe als Arbeitsplätze mit gleichnamigem GDT-Ordner. Enter schließt die optionale Ergänzung weiterer Plätze ab. In der Telepraxis-App verwendet die Person am Telefon ihre Nebenstelle als Platz und ihren eigenen Namen als Benutzer. Der Installer kann für eine eingegebene Nummer und einen Namen auch den passenden App-Startpfad ausgeben.

Bei neuen FHIR- und APS-Verbindungen ist die Serverzertifikatsprüfung standardmäßig aus. Sie lässt sich während der Einrichtung aktivieren. Updates übernehmen die bisherige Zertifikatsprüfung und vorhandene Arbeitsplatzzuordnungen.

## 1.5.2 · Nebenstellenanzeige der Praxis-App

Der Installer richtet den lokalen App-Dienstzugang ein. Jede Dienstidentität liest ihre eigene Schlüsseldatei mit Modus 0600. Ein Update verwendet den vorhandenen Dienstschlüssel. Bei installierter lokaler App prüft der Installer deren Nebenstellenliste. Für einen externen App-Server erlaubt `--app-source-ip` eine einzelne zusätzliche Quelladresse. Neue App-Zugänge zeigen Nebenstellen an, Schaltrechte erfordern freigegebene Arbeitsplätze.

## 1.5.1 · noch nicht veröffentlicht

- Der Installer akzeptiert bei vorhandenem Samba `server role = auto`, wenn der vollständige `testparm -s`-Aufruf erfolgreich ist und auf stderr eindeutig `Server role: ROLE_STANDALONE` meldet. Damit kann er auf einem Kienzlefax-Server die GDT-Freigabe ergänzen und die vorhandene Fax-Konfiguration beibehalten. Eine Domänenrolle oder eine fehlende oder widersprüchliche Bestätigung führt zum Abbruch vor Samba-Änderungen.

## 1.5.0 · noch nicht veröffentlicht

- Normal-/ROT-Schalter in Voll- und Kompaktansicht gemäß administrativen Freigaben; letzter Teilnehmer bleibt geschützt.
- Control-Client nach Vertrag 1.0.2, Live-Zahlen und Wege-Tooltip; veraltete oder fehlende Daten sperren die Steuerung.
- Eng begrenzte App-API und dauerhaftes Auftragsjournal; verlorene Antworten werden mit derselben UUID abgefragt, ohne automatischen erneuten Versand.
- Neue Module standardmäßig deaktiviert; Installer erhält Einstellungen und prüft den aktivierten Anlagendienst. Anlage und App müssen separat bereitgestellt und gemeinsam abgenommen werden.
- Optionaler dauerhafter Tageszähler und Wege-Auswertung über `call_statistics.today` in der Telefonie-API. Grundlage sind neue, explizite Asterisk-Eingangs- und Wegeereignisse.
- Eigene SQLite-Datei, Deduplizierung, Berliner Tagesgrenzen und Kennzeichnung ungefährer Werte nach Erfassungslücken. Keine Nachberechnung von Ausfällen.
- Konfiguration, Installer, Tests und Übergabe für den Tooltip der separat entwickelten App ergänzt; Dialplan-Vorlage muss vor Aktivierung eingebunden werden.

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
