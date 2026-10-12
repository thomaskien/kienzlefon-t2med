# Architektur und Schnittstellen

Zielsystem: Debian 12+, Ubuntu 24.04+ und Raspberry Pi OS Bookworm+ mit PHP 8.2 oder neuer. Ein PHP-CLI-Prozess unter systemd stellt Weboberfläche und API bereit. ReactPHP hält AMI dauerhaft offen und fragt APS asynchron ab. Keine Datenbank; Anrufzustand, Lookup-Cache und kurzlebige Auftrags-IDs liegen im Arbeitsspeicher. APS-/AMI-Zugangsdaten liegen nur in der geschützten Serverkonfiguration außerhalb des GDT-Shares.

## Laufzeit und Konfiguration

`php bin/server.php --config=/etc/kienzlefon-t2med/config.toml`; Demo: `php bin/server.php --demo --listen=127.0.0.1:8765`. Schema siehe `config.example.toml`. Ein leeres APS-Passwort ist ein gültiger Wert. `aps.tls_verify=false` ist eine ausdrücklich konfigurierte Ausnahme für den vorhandenen Testserver. Der Installer bietet sie an; sie wird nie bei einem Fehler automatisch aktiviert. AMI und APS können auf verschiedenen Rechnern laufen.

## HTTP-Vertrag

Kein Benutzerlogin. Netzwerkbereich und erlaubte Hostnamen sind konfiguriert. Browser-POSTs brauchen denselben Origin und das Headerfeld `X-Kienzlefon-Token` aus `GET /api/v1/bootstrap`. Native lokale Clients holen ebenfalls dieses flüchtige Token; es ist ein Schutz gegen fremde Webseiten, kein Benutzerpasswort. Die Weboberfläche wird vom selben Dienst ausgeliefert. Keine CORS-Freigabe.

- `GET /api/v1/bootstrap`: `token`, `clients` als Liste `{id,label,extension}`, `demo`, `version`.
- `GET /healthz`: `status`, `demo`, `version`. CLI-Version: `php bin/server.php --version`.
- `GET /api/v1/telephony`: `generated_at`, `ami_connected`, `stale`, `calls`, `queues`, `extensions`, `outgoing`, `transfers`. Verbundene Anrufe enthalten `transfer_extensions` mit den aktuell eindeutig zugeordneten, bedienbaren Nebenstellen.
- `POST /api/v1/lookup` mit `{number}`: `{lookup_id,number,status,patients}`. Patienten enthalten `patient_number`, `first_name`, `last_name`, `birth_date`, `address`; interne Referenzen bleiben für die Auswahlprüfung im Dienst.
- `POST /api/v1/patient-open` mit `{client_id,lookup_id,patient_number,request_id}`: ausgewählten Kandidaten als GDT schreiben. Nur zum Lookup gehörende, eindeutig identifizierbare Patientennummern akzeptieren. Eine noch nicht konsumierte Datei atomar durch den neuen Aufruf ersetzen. `request_id` ist eine UUID und dient der Wiederholungserkennung.
- `POST /api/v1/calls` mit `{client_id,extension,number,request_id}`: Nebenstelle muss zur serverseitigen Client-Zuordnung passen. Erst Nebenstelle, dann Ziel anrufen. HTTP 202 bedeutet angenommen, nicht verbunden.
- `POST /api/v1/transfers` mit `{client_id,call_id,target_type,target,mode,request_id}`: `target_type` ist `internal` oder `external`, `mode` ist `blind` oder `attended`. Die Middleware ermittelt den eigenen Gesprächskanal selbst. Keine frei übergebenen AMI-Kanalnamen. HTTP 202 liefert den Auftrag samt aktuellem Zustand.
- `POST /api/v1/transfers/{UUID}/complete` oder `/cancel` mit `{client_id,request_id}`: Rückfrage abschließen oder zum Anrufer zurückkehren, nur wenn die serverseitigen Zustandsmerkmale dies erlauben.
- `GET /patient-oeffnen?client_id=empfang-1&telefon=…`: Einstiegsseite für den Weblink. Der bloße GET schreibt keine Datei. Die Oberfläche verarbeitet eine aktive Navigation, löst den Lookup aus und bei eindeutigem Treffer den POST; mehrere Kandidaten erfordern Auswahl.
- `GET /waehlen?client_id=empfang-1&telefon=…`: Einstiegsseite mit Wählbestätigung.
- `GET /waehlen?client_id=empfang-1&telefon=…&sofort=1`: explizite Direktwahl. Nach frischer Navigation in sichtbarer, nicht vorgerenderter Seite genau einen Anruf-POST auslösen; kein Bestätigungsdialog. Aktionsparameter vor der ersten Netzwerkanfrage aus der Browserhistorie entfernen, damit Reload/Zurück keinen neuen Auftrag erzeugt. Bloßer GET bleibt lesend. Jede erneute bewusst geöffnete vollständige Direktwahl-URL ist ein neuer Wahlauftrag.
- `expected_extension` prüft bei Client-Wähl-URLs zusätzlich die lokal registrierte Nebenstelle gegen Bootstrap; bei Abweichung kein automatischer Wahlauftrag.
- `/?view=compact&client_id=empfang-1` und `/?view=full&client_id=empfang-1` wählen die Ansicht. Ohne expliziten Parameter gilt die gespeicherte Ansicht, sonst Kompakt.

Status der Auflösung: `pending`, `resolved`, `ambiguous`, `not_found`, `timeout`, `error`. Allgemeine APS-Suche kann Patientennummern finden; verdächtige Zahlentreffer verlangen Auswahl und werden nicht automatisch als Telefonname verwendet. Verschiedene Patienten mit identischen Namen bleiben getrennt. Das API liefert Geburtsdaten unverändert. Die Oberfläche formatiert kalendergültige, eindeutig erkennbare Werte in YYYYMMDD, DDMMYYYY oder YYYY-MM-DD als DD.MM.YYYY; unklare Werte bleiben unverändert.

## AMI und APS

APS: `POST /aps/rest/praxis/patient/suche/find`, Basic Auth, JSON `{suchmaske: <Rufnummer>, ignoreSichtbarkeit: false}`. Antwort ist eine Liste mit `nummer`, `vorname`, `nachname`, `geburtsdatum`, `adresse` und vollständigem `ref`. Keine weiteren Stammdaten-Endpunkte voraussetzen.

APS-Suchvarianten für den konfigurierten Ländercode: nationale führende Null,
`+`-Format, `00`-Format und Landeskennzahl ohne Präfix. Alle verwenden denselben
Cache-Schlüssel; Treffer über mehrere Schreibweisen werden per Patienten-ID und
Referenz zusammengeführt. Kurze lokale Nummern werden nicht allein wegen der
Ziffernfolge `49` international interpretiert (mindestens sieben weitere Ziffern).
Die generische Normalisierung für die Wahl bleibt davon getrennt.

AMI: `Events: on`; Snapshots `CoreShowChannels` und `QueueStatus` bei Verbindung sowie periodisch. Währenddessen Events puffern und anschließend auf den vollständigen Snapshot anwenden. Verbindungsausfall oder unvollständiger Snapshot bedeutet `stale`. Kanäle und logische Anrufe unterscheiden; Brücken und Local-Kanalpaare berücksichtigen, getrennte Gesprächsbrücken trotz gleichem Linkedid getrennt halten. Warteschlangeneinträge referenzieren die Anruf-ID. Gesprächsdauer nur bei beobachteter Verbindung, sonst unbekannt; Kanalalter gesondert.

Lookup-UserEvent: `KienzlefonLookupRequest`, `SchemaVersion: 1`, `RequestId`, `SourceChannel`, `SourceUniqueid`, `SourceLinkedid`, `Number`, `BudgetMs: 1000`. Middleware setzt per bestätigtem `Setvar` nacheinander `LOOKUP_RESULT_ID`, `LOOKUP_NAME`, `LOOKUP_STATUS`, zuletzt `LOOKUP_DONE=1`. Nur temporäre Variablen auf dem jeweiligen Kanal, niemals `CALLERID`. Asterisk selbst begrenzt die Wartezeit auf eine Sekunde und übernimmt ausschließlich rechtzeitige Ergebnisse. Ende über `KienzlefonLookupFinished` mit `RequestId`, `SourceUniqueid`, `Status`, `Applied`.

Click-to-Dial: `Originate` mit `PJSIP/<extension>`, `Context` aus Konfiguration, `Exten` als normalisierte Zielnummer, `Priority: 1`, `Async: true`, `Timeout: 20000`, `EarlyMedia: false`, eigenem `ChannelId`. `CallerID` enthält die Zielnummer als Anzeigename und Nummer (`03055501111 <03055501111>`), keine dauerhafte Personenbezeichnung wie „Rueckruf“. Variablen `__KF_C2D_ID`, `__KF_C2D_CLIENT`, `__KF_C2D_EXTENSION`. Dialplan meldet `KienzlefonClickToDialProgress` mit `RequestId`, `Phase: dialing_target`, `SourceUniqueid`. Eigentliche Verbindung erst nach Antwort des zweiten Rufabschnitts und Brücke melden. Kein automatisches Wiederholen bei ungewissem Ergebnis.

## Vermittlungszustand

`transfers` enthält `request_id`, `client_id`, `call_id`, `extension`, `target_type`, `target`, `mode`, `phase`, `message`, `created_at`, `can_cancel`, `can_complete`. Phasen: `preparing`, `requested`, `ringing`, `consulting`, `completing`, `cancelling`, `transferred`, `cancelled`, `failed`, `unknown`. Die Ansicht bleibt auch bei einer geänderten logischen Gesprächs-ID verfügbar. Serverseitig werden ursprüngliche Uniqueids, die tatsächliche Zielverbindung und Local-Zwischenkanäle verfolgt.

Start- und Steueraufträge verwenden UUIDs zur Wiederholungserkennung. Neue UUIDs sind neue bewusste Bedienaktionen. Ein ungewisser Auftrag wird nicht automatisch erneut gesendet und blockiert weitere Vermittlung desselben laufenden Gesprächs. Bestätigter AMI-Auftrag und bestätigte Gesprächsverbindung sind getrennt. Details zur nötigen Asterisk-Einrichtung: [Transfer-Übergabe](TRANSFER_HANDOFF.md).

## GDT und Clients

Share `kienzlefon-GDT`, Guest ohne Anmeldung. Pro Client ein vorab konfiguriertes Unterverzeichnis, darin `AUFRUFi.gdt`. ID-only Satztyp 6301 mit korrekten Byte-Längen und CRLF. Temporäre Datei auf demselben Dateisystem und atomare Veröffentlichung per Rename; eine vorhandene Importdatei wird vollständig ersetzt. T2med überwacht pro Arbeitsplatz den zugehörigen SMB-Pfad über die Kalenderanbindung.

Linux-Installer installiert Dienst, fragt Verbindungsdaten verdeckt ab, legt geschützte Konfiguration an und ergänzt Samba additiv. Bestehende Shares/Benutzer/Globaleinstellungen erhalten. Falls ein bestehender Samba-Server Guest nicht unterstützt, keine globale Authentifizierung eigenmächtig ändern; Konflikt vor Änderungen melden und dokumentierte separate Freigabe ermöglichen.

Lokale Installer für Windows/macOS/Linux speichern Middleware-URL, Client-ID und Nebenstelle. Sie registrieren `tel:` und `callto:`. Nummern strikt prüfen, keine Ausführung von URI-Inhalt als Shellcode. Auswahl des Standardprogramms bleibt bei vom Betriebssystem verwalteten Benutzereinstellungen. Keine APS-/AMI-Zugangsdaten auf Arbeitsplätzen.
