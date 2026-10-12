# Oberfläche und Weblinks

## Kompaktansicht

Standard ist die kompakte Ansicht; die letzte Ansicht wird pro Browser gespeichert.
Ein direkter Einstieg ist `/?view=compact&client_id=empfang-1`. Für die vollständige
Übersicht `/?view=full&client_id=empfang-1` verwenden oder unten „Übersicht“ wählen.

Die Kopfzeile zeigt eigene Nebenstelle, Wartende, verbundene Gespräche und
Verbindungsstatus. DEMO bleibt erkennbar. Eigene Verbindungen stehen vor der
Warteliste. Wartende erscheinen pro Queue in der gemeldeten Position; bei mehreren
belegten Queues kennzeichnet eine Überschrift deren getrennte Reihenfolge. Die
Warteliste scrollt unabhängig, während eigene Verbindung und Fußzeile erreichbar
bleiben. **Alle Gespräche** in der Fußzeile klappt die Verbindungen aller
Nebenstellen einschließlich der eigenen auf. Die Liste zeigt Nebenstelle, Name
bzw. Rufnummer und Dauer; Details und Aktenaufruf bleiben verfügbar. Diese Wahl
bleibt während der Tab-Sitzung gespeichert, auch bei Polling, Ansichtswechsel und
Neuladen. Ein erneuter Klick klappt sie ein. Nebenstellen haben einen getrennten
Schalter. Bei Verbindungsverlust werden auch hier keine alten Gespräche angezeigt.

Die installierte Version steht im Menü unter den Einstellungen.

Bei eindeutigem Treffer öffnet ein Klick auf den Patientennamen dessen Akte. Der
kleine Pfeil klappt Rufnummer, Geburtsdatum, Patientennummer, Adresse und Rückruf
auf. Mehrfachtreffer zeigen zunächst Rufnummer und Trefferzahl. Erst die Auswahl
eines konkreten Patienten erzeugt die GDT-Datei. Unterdrückte/unbekannte Rufnummern
bleiben ohne erzwungene Zuordnung sichtbar.

Bei Mausbedienung passen ein eigenes Gespräch und zwei Wartende in ungefähr
250 × 160 Pixel Inhaltsfläche. Touchgeräte erhalten größere Bedienelemente;
Browserleisten kommen zu den Maßen hinzu. Menü, Details und lange Listen brauchen
zusätzlichen Platz. Über das Menü kann ein eigenes Kompaktfenster geöffnet werden;
dessen endgültige Größe und Position verwaltet der Browser. Es wird beim Polling
nicht automatisch nach vorne geholt.

Im Menü: Arbeitsplatzwechsel, Rufnummernsuche, Wählen, Rückrufaufträge und die
Einrichtung der Desktop-Telefonie-App. Die Rückrufaufträge sind die vorhandene
flüchtige Diensthistorie, keine dauerhafte Anrufliste.

## Status im Fenstertitel

Der Titel lautet beispielsweise `2 warten · 1 Gespräch · kienzlefon`. Bei
Verbindungsverlust wechseln Titel und Monitor auf einen Fehlerstatus; alte Anrufe
und Zähler werden nicht als aktuell weiter angezeigt. Die Daten werden sichtbar
alle zwei Sekunden, verdeckt nominell alle 15 Sekunden abgefragt. Browser und
Betriebssystem können Hintergrundseiten drosseln oder ganz anhalten. Bei Rückkehr
zur Ansicht wird sofort neu abgefragt.

In Windows kann der Titel bei eingeblendeten Taskleistenbeschriftungen in einem
eigenen Browserfenster sichtbar bleiben. Anzeige, Kürzung und Aktualisierung im
minimierten Zustand müssen am Zielsystem geprüft werden. Diese Version enthält
keinen Tray-Dienst und ändert keine Taskleisteneinstellungen.

## Wähl- und Aktenlinks für kienzlefon

Basisadresse, `client_id` und Rufnummer entsprechend dem Arbeitsplatz einsetzen.
`telefon` immer URL-kodieren, insbesondere ein internationales Plus als `%2B`.
Bei Ländercode `49` werden vollständige Rufnummern in den Formen `0176…`,
`+49176…`, `0049176…` und `49176…` für APS gemeinsam gesucht und gecacht.

**Direkt wählen, ohne zusätzlichen Dialog:**

```text
http://middleware.example:8765/waehlen?client_id=empfang-1&telefon=%2B493055501111&sofort=1
```

Die sichtbare, frisch geöffnete Seite sendet einen Anrufauftrag. Zuerst klingelt
die zugeordnete Nebenstelle; nach Annahme wählt Asterisk das Ziel. Ohne `sofort=1`
erscheint die normale Wählbestätigung. Ein bloßer HTTP-GET startet keinen Anruf.
Die Serverzuordnung der Client-ID bestimmt die Nebenstelle. Native registrierte
Clients übergeben zusätzlich `expected_extension`; bei abweichender Zuordnung
wird die automatische Aktion abgebrochen.

**Patientenakte anhand einer Rufnummer öffnen:**

```text
http://middleware.example:8765/patient-oeffnen?client_id=empfang-1&telefon=%2B493055501111
```

Bei einem eindeutigen Treffer erzeugt die Seite die GDT-Datei für den gewählten
Arbeitsplatz. Bei mehreren Treffern ist eine Auswahl erforderlich; bei keinem
Treffer wird nichts geschrieben. Erfolg bestätigt die GDT-Übergabe, nicht deren
bereits abgeschlossenen Import durch T2med.

Aktionsparameter verschwinden vor der Verarbeitung aus der Browserhistorie.
Neuladen/Zurück löst keine erneute Wahl oder GDT aus. Ein erneuter bewusster Klick
auf den vollständigen Originallink ist ein neuer Auftrag. Bei ungewissem Ergebnis
zuerst das Telefon beziehungsweise den T2med-Import prüfen.

Beide Linktypen können `&view=compact` oder `&view=full` enthalten. Benutzerdaten,
AMI-Secret oder APS-Passwort gehören nie in diese URLs.
