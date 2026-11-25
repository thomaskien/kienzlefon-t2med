# Architektur und Schnittstellen

Ein PHP-CLI-Prozess unter systemd liefert Weboberfläche und API und hält AMI über ReactPHP offen. Standardquelle ist ein persistenter SQLite-Patientenbestand; die Go-Binary `libexec/fhir-importer` erneuert ihn nach einem T2med-Aufruf atomar. APS-Abfragen laufen nur im ausdrücklich konfigurierten Altbetrieb. Anrufzustand, Suchindex, Lookup-Cache und Wahl-/GDT-Aufträge liegen im Arbeitsspeicher. Rufgruppenaufträge liegen dauerhaft in einer eigenen SQLite-Datei.

Start: `php bin/server.php --config=/etc/kienzlefon-t2med/config.toml`; Demo: `php bin/server.php --demo --listen=127.0.0.1:8765`. Schema: [config.example.toml](../config.example.toml). FHIR-Daten liegen unter `/var/lib/kienzlefon-t2med`, Konfiguration und Secrets unter `/etc/kienzlefon-t2med`, jeweils außerhalb des GDT-Shares.

## HTTP-Vertrag

Es gibt keinen Benutzerlogin und keine CORS-Freigabe. Browser-POSTs benötigen gleichen Origin und `X-Kienzlefon-Token` aus dem Bootstrap; native Clients holen dasselbe flüchtige Token. Erlaubte Netze und Hostnamen werden zusätzlich geprüft. Das Token ist kein Benutzerpasswort.

| Methode und Route | Vertrag |
| --- | --- |
| `GET /api/v1/bootstrap` | Token, Clients `{id,label,extension}`, Demo, Version und Autor. |
| `GET /healthz` | Dienststatus, Demo und Version. |
| `GET /api/v1/telephony` | Zeitstempel, AMI-/Stale-Status, Gespräche, Queues, Nebenstellen, Wahl- und Vermittlungsaufträge sowie `call_statistics.today` mit Tageszahl und Wegen. |
| `GET /api/v1/telephony/overview` | Übersicht nach Telefonievertrag 1.0.2 ohne Patienten oder Gespräche; auch im vollständigen Telefonie-GET enthalten. |
| `POST /api/v1/extension-routing` | Administrativ erlaubte Änderung an Normal/ROT; dauerhafte UUID und bestätigte Revision. |
| `GET /api/v1/extension-routing/requests/{id}` | Auftrag aus Journal, auch nach Middleware-Neustart. |
| `POST /api/v1/lookup` | Reine Rufnummernsuche `{number}`; liefert Lookup-ID, Status und Patienten. |
| `POST /api/v1/patients/search` | Lokale Suche `{query}` nach Name, exakter Patientennummer oder Telefon; maximal 200 Namenstreffer, Telefonkandidaten vollständig. |
| `POST /api/v1/patient-open` | `{client_id,lookup_id,patient_number,request_id}`; validierten Kandidaten atomar als GDT schreiben. |
| `POST /api/v1/calls` | `{client_id,extension,number,request_id}`; zugeordnete eigene Nebenstelle zuerst, danach Ziel. HTTP 202 bedeutet angenommen, nicht verbunden. |
| `POST /api/v1/calls/{id}/retry` | Abgeschlossenen eigenen Wahlauftrag mit neuer UUID wiederholen, wenn Nebenstelle frei und Zustand aktuell ist. |
| `POST /api/v1/calls/{id}/dismiss` | Eigenen Eintrag ausblenden, ohne AMI oder das Gespräch zu verändern. |
| `POST /api/v1/calls/cleanup` | Nur erledigte eigene Einträge ohne Kanäle ausblenden. |
| `POST /api/v1/transfers` | `{client_id,call_id,target_type,target,mode,request_id}`; Zielart intern/extern, Modus direkt/Rückfrage. |
| `POST /api/v1/transfers/{id}/complete`, `/cancel` | Rückfrage nach serverseitiger Zustandsprüfung abschließen oder abbrechen. |
| `POST /api/v1/fhir/import` | T2med-Kontext, fest konfigurierte Basisadresse, Bearer-Token und UUID an den Importer übergeben. |
| `GET /api/v1/fhir/status` | Fortschritt, letztes Ergebnis und Bestandszahlen ohne Patienten oder Token. |

`GET /waehlen`, `/patient-oeffnen` und `/telefonverzeichnis-aktualisieren.php` sind Browser-Einstiege. Der GET selbst mutiert nichts; JavaScript entfernt Aktionsparameter aus der Historie und sendet nach Prüfung höchstens einen POST. `/?view=compact|full&client_id=…` wählt Ansicht und Arbeitsplatz.

Lookup-Status sind `pending`, `resolved`, `ambiguous`, `not_found`, `timeout` und `error`. Mehrere Patienten bleiben getrennt. GDT darf nur die echte Patientennummer aus dem geprüften Lookup verwenden; nie die FHIR-ID.

## AMI und Telefonie

Bei AMI-Verbindung und periodisch werden `CoreShowChannels` und `QueueStatus` abgeglichen; Ereignisse während des Snapshots werden danach angewendet. Ausfall oder unvollständiger Snapshot setzt `stale`. Logische Anrufe, Kanäle, Brücken und Local-Kanalpaare bleiben getrennt.

Für eingehende Lookup-Anfragen setzt die Middleware nach bestätigten `Setvar`-Aktionen `LOOKUP_RESULT_ID`, `LOOKUP_NAME`, `LOOKUP_STATUS` und zuletzt `LOOKUP_DONE=1`. Nur ein eindeutiger Patient liefert den echten Namen; bei Mehrdeutigkeit bleibt `LOOKUP_NAME` leer. Asterisk wartet höchstens eine Sekunde und übernimmt nur rechtzeitige Ergebnisse.

Click-to-Dial verwendet `Originate` zuerst zu `PJSIP/<extension>` und wählt das Ziel nach Annahme. Es wird weder ein künstlicher Rückrufname noch die Zielnummer als Anzeigename gesetzt. UUIDs verhindern versehentliche Wiederholung; ein ungewisses Ergebnis wird nicht automatisch erneut gesendet.

Konfigurierte interne Ziele nutzen `Originate` mit `Application: Dial` und `Data: PJSIP/<Ziel>,<Rufzeit>,f(<eigene Nebenstelle>)`; externe Ziele bleiben im konfigurierten Click-to-Dial-Kontext. Die eigene Nebenstelle wird als Anrufernummer an das interne Ziel übergeben ([Asterisk Dial](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/Dialplan_Applications/Dial/)). Eigene Zielnebenstelle, belegter eigener Anschluss und `stale` werden abgewiesen. Vermittlung bindet sich serverseitig an das eigene laufende Gespräch; frei übergebene AMI-Kanalnamen sind unzulässig.

## FHIR, APS und GDT

Die optionale [Anruf- und Wegestatistik](ANRUFSTATISTIK.md) verarbeitet ausschließlich definierte Dialplan-UserEvents und speichert technische Eingangskennungen getrennt in `call-statistics.sqlite`. AMI-Snapshots und HTTP-GETs zählen nicht. Erfassungslücken bleiben als `partial` sichtbar; die Live-Telefonie kann gleichzeitig wieder aktuell sein.

Der FHIR-Importer liest paginiert `GET /Patient`. Nur ein vollständiger validierter Lauf ersetzt die SQLite-Datei. Der Bearer-Token gelangt über stdin zum Kindprozess; der eingebettete Demo-API-Key erlaubt 100 Requests. Der TEMP-Suchindex normalisiert Namen und Rufnummern, ergänzt den Ortsnummern-Endvergleich und wird beim Dienststart aus Originalwerten aufgebaut.

APS-Altbetrieb: `POST /aps/rest/praxis/patient/suche/find` mit Basic Auth und `{suchmaske, ignoreSichtbarkeit:false}`. Es werden keine weiteren APS-Stammdaten-Endpunkte vorausgesetzt.

Pro Client veröffentlicht die Middleware `AUFRUFi.gdt` als ID-only-Satztyp 6301 mit CRLF und korrekten Byte-Längen. Eine temporäre Datei auf demselben Dateisystem wird atomar umbenannt und ersetzt die vorhandene Importdatei. Lokale Clients speichern nur Middleware-URL, Client-ID und Nebenstelle und registrieren `tel:` sowie `callto:`; APS-/AMI-Zugangsdaten bleiben auf dem Server.

## Anlagensteuerung und App

`TelephonyControl` pollt den geschützten Control-Dienst alle zwei Sekunden,
prüft Identität und Schema und lässt Live-Zähler, Routing und unabhängigen
AMI-Status getrennt ausfallen. Alterswerte enthalten Transport- und Wartezeit;
ab zehn Sekunden sind die betroffenen Daten unbekannt. AMI-Pings alle drei
Sekunden bestätigen die Verbindung für den separaten Nebenstellenstatus.

`RoutingJournal` bestätigt die lokale Speicherung vor dem POST. Nach möglichem
Versand werden offene UUIDs ausschließlich per GET abgefragt; terminale Ergebnisse
bleiben mindestens sieben Tage, ungewisse Ergebnisse ohne automatische Löschung.
Die Anlage prüft Freigaben, Revision und letzten Teilnehmer atomar beim Anwenden.

`app_access` authentifiziert ausschließlich Overview und die beiden Routingrouten.
Ein Bearer-Zugriff fällt niemals in Patienten-, Wahl-, Vermittlungs- oder
GDT-Funktionen durch. Schlüsselrotation wird ohne Browser-Geheimnisse unterstützt.
Konfiguration und Abnahme: [Telefonie-Steuerung](TELEFONIE-STEUERUNG.md).
