# Architektur und Schnittstellen

Ein PHP-CLI-Prozess unter systemd liefert Weboberfläche und API und hält AMI über ReactPHP offen. Standardquelle ist ein persistenter SQLite-Patientenbestand; die Go-Binary `libexec/fhir-importer` erneuert ihn nach einem T2med-Aufruf atomar. APS-Abfragen laufen nur im ausdrücklich konfigurierten Altbetrieb. Anrufzustand, Suchindex, Lookup-Cache und Auftrags-IDs liegen im Arbeitsspeicher.

Start: `php bin/server.php --config=/etc/kienzlefon-t2med/config.toml`; Demo: `php bin/server.php --demo --listen=127.0.0.1:8765`. Schema: [config.example.toml](../config.example.toml). FHIR-Daten liegen unter `/var/lib/kienzlefon-t2med`, Konfiguration und Secrets unter `/etc/kienzlefon-t2med`, jeweils außerhalb des GDT-Shares.

## HTTP-Vertrag

Es gibt keinen Benutzerlogin und keine CORS-Freigabe. Browser-POSTs benötigen gleichen Origin und `X-Kienzlefon-Token` aus dem Bootstrap; native Clients holen dasselbe flüchtige Token. Erlaubte Netze und Hostnamen werden zusätzlich geprüft. Das Token ist kein Benutzerpasswort.

| Methode und Route | Vertrag |
| --- | --- |
| `GET /api/v1/bootstrap` | Token, Clients `{id,label,extension}`, Demo, Version und Autor. |
| `GET /healthz` | Dienststatus, Demo und Version. |
| `GET /api/v1/telephony` | Zeitstempel, AMI-/Stale-Status, Gespräche, Queues, Nebenstellen, Wahl- und Vermittlungsaufträge. |
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

Der FHIR-Importer liest paginiert `GET /Patient`. Nur ein vollständiger validierter Lauf ersetzt die SQLite-Datei. Der Bearer-Token gelangt über stdin zum Kindprozess; der eingebettete Demo-API-Key erlaubt 100 Requests. Der TEMP-Suchindex normalisiert Namen und Rufnummern, ergänzt den Ortsnummern-Endvergleich und wird beim Dienststart aus Originalwerten aufgebaut.

APS-Altbetrieb: `POST /aps/rest/praxis/patient/suche/find` mit Basic Auth und `{suchmaske, ignoreSichtbarkeit:false}`. Es werden keine weiteren APS-Stammdaten-Endpunkte vorausgesetzt.

Pro Client veröffentlicht die Middleware `AUFRUFi.gdt` als ID-only-Satztyp 6301 mit CRLF und korrekten Byte-Längen. Eine temporäre Datei auf demselben Dateisystem wird atomar umbenannt und ersetzt die vorhandene Importdatei. Lokale Clients speichern nur Middleware-URL, Client-ID und Nebenstelle und registrieren `tel:` sowie `callto:`; APS-/AMI-Zugangsdaten bleiben auf dem Server.
