# Oberfläche und Weblinks

[← Projektübersicht](../README.md) · [Installation](INSTALLATION.md) · [FHIR](FHIR.md) · [Desktop-Clients](CLIENTS.md)

## Übersicht und Patientensuche

Oben den eigenen Arbeitsplatz wählen. Er bestimmt die Nebenstelle für Wahlaufträge und das GDT-Verzeichnis für Aktenaufrufe. Die vollständige Ansicht zeigt Warteschlangen, Gespräche und Nebenstellen.

![Telefonieübersicht](images/telefonieuebersicht.png)

Das Suchfeld findet Namen, Namensbestandteile, exakte Patientennummern und Telefonnummern. `Müller Anna`, `Anna Müller` und `Mueller Anna` finden dieselben Namen. Rein numerische Eingaben suchen nach Patientennummer und Telefon. Mehrere Treffer bleiben getrennt; fehlt die echte Patientennummer, ist der Aktenaufruf gesperrt. Bei mehr als 200 Namenstreffern den Suchtext präzisieren.

Ein Patientenklick schreibt die GDT-Datei für den gewählten Arbeitsplatz. **Anrufen** ist nur für eine Telefonnummer bestimmt; ein Name löst keinen Wahlauftrag aus. Die FHIR-Verknüpfung zeigt Zeitpunkt und Ergebnis des letzten Imports.

Bei der Wahlhilfe erhält das Telefon nur bei eindeutiger Rufnummernzuordnung den echten Patientennamen. Bei keinem oder mehreren Patienten bleibt der Name leer; die UI bietet mehrdeutige Patienten weiterhin zur Auswahl an.

## Kompaktansicht und Nebenstellen

Standard ist die Kompaktansicht. Direkte Einstiege sind `/?view=compact&client_id=empfang-1` und `/?view=full&client_id=empfang-1`; ohne Parameter gilt die zuletzt gespeicherte Ansicht.

![Kompaktansicht](images/kompaktansicht.jpg)

Eigene Gespräche stehen vor den Wartenden. **Alle Gespräche** blendet andere Nebenstellen ein, **Eigene Gespräche** schaltet zurück. Details zeigen Rufnummer und Patientennummer, im APS-Altbetrieb gegebenenfalls auch Geburtsdatum und Adresse; bei mehreren Patienten erfolgt dort die Auswahl. Veraltete Telefoniedaten werden nicht als aktueller Zustand weitergezeigt.

Die konfigurierten Nebenstellen erscheinen als **FREI** (grün), **klingelt** (gelb) und **BELEGT** (rot). Ein Klick auf eine andere Nebenstelle startet ohne zusätzlichen Dialog einen internen Wahlauftrag über den ausgewählten eigenen Arbeitsplatz: zuerst das eigene Telefon abheben, danach klingelt das Ziel.

Der Nebenstellenaufruf ist bei veralteten Daten, für die eigene Nebenstelle oder bei beschäftigtem eigenen Anschluss gesperrt. Er ist kein Vermitteln eines laufenden Gesprächs.

Die untere Zeile der Kompaktansicht lautet **kienzlefon v1.4.3 von Dr. Thomas Kienzle**. Auch die vollständige Ansicht zeigt Version und Autor. Das Menü enthält Arbeitsplatzwechsel, Patientensuche, Wählen, Rückrufaufträge und die Einrichtung der Desktop-Telefonie-App.

## Rückrufaufträge

Die Liste enthält Wahlaufträge des ausgewählten Arbeitsplatzes, keine dauerhafte Anrufliste, und wird nach einem Dienstneustart nicht wiederhergestellt.

- **Nochmal versuchen** startet nach einem bewussten Klick denselben Zielruf neu, sofern der alte Auftrag abgeschlossen, der Zustand aktuell und die eigene Nebenstelle frei ist.
- **Löschen** blendet einen Eintrag aus, beendet aber keinen Anruf.
- **Liste bereinigen** entfernt nur erledigte Einträge dieses Arbeitsplatzes.

Die Wahlhilfe überträgt weder `Rueckruf`/`Rückruf` noch den Nummerntext als Namen. Zuerst klingelt immer die eigene Nebenstelle; erst nach dem Abheben wird das Ziel gewählt. Ungewisse Aufträge werden nicht automatisch wiederholt.

## Vermitteln

Der Pfeil **↪** am eigenen verbundenen Gespräch öffnet die Zielauswahl für eine andere Nebenstelle oder externe Rufnummer. **Direkt vermitteln** übergibt sofort; **Rückfrage starten** ruft zunächst das Ziel an. In der vollständigen Ansicht die Rückfrage anschließend **Abschließen** oder **Zurück zum Anrufer** wählen.

Fremde Gespräche, Wartende, veraltete Daten und ein Arbeitsplatzwechsel sperren die Aktion. Voraussetzung ist die [Telefonprojekt-Einrichtung](TRANSFER_HANDOFF.md); eine bestätigte AMI-Annahme ist noch keine bestätigte Gesprächsverbindung.

![Vermittlungsdialog](images/vermittlung.jpg)

## Status im Fenstertitel

Der Titel meldet beispielsweise `2 warten · 1 Gespräch · kienzlefon`. Sichtbare Seiten fragen ungefähr alle zwei Sekunden ab, verdeckte nominell alle 15 Sekunden; Browser dürfen Hintergrundseiten drosseln. Bei Rückkehr wird sofort aktualisiert. Verbindungsverlust setzt einen Fehlerstatus und entfernt alte Zähler.

## Wähl- und Aktenlinks für kienzlefon

Basisadresse, `client_id` und URL-kodierte Rufnummer ersetzen; ein führendes `+` wird `%2B`.

Direktwahl ohne zusätzlichen Dialog:

```text
http://middleware.example:8766/waehlen?client_id=empfang-1&telefon=%2B493055501111&sofort=1
```

Die sichtbare, frisch geöffnete Seite sendet genau einen Auftrag. Ohne `sofort=1` erscheint die Bestätigung. Ein bloßer GET startet keinen Anruf; Aktionsparameter verschwinden aus der Browserhistorie, damit Neuladen oder Zurück keinen Auftrag wiederholt. `expected_extension` kann zusätzlich die erwartete lokale Nebenstelle prüfen.

Patientenakte anhand der Rufnummer öffnen:

```text
http://middleware.example:8766/patient-oeffnen?client_id=empfang-1&telefon=%2B493055501111
```

Bei einem eindeutigen Treffer mit echter Patientennummer erzeugt die Seite die GDT-Datei, bei mehreren erscheint die Auswahl, bei keinem wird nichts geschrieben. Erfolg bestätigt die Übergabe, nicht die bereits sichtbare Akte. Beide Linktypen akzeptieren `&view=compact` oder `&view=full`.

Für `tel:`-/`callto:`-Links ohne Browserfenster dienen die [Desktop-Clients](CLIENTS.md). Zugangsdaten gehören nie in Weblinks.
