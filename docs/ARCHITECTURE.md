# Architektur und Schnittstellen

Zielsystem: Debian 12+, Ubuntu 24.04+ und Raspberry Pi OS Bookworm+ mit PHP 8.2 oder neuer. Ein PHP-CLI-Prozess unter systemd stellt Weboberfläche und API bereit. ReactPHP hält AMI dauerhaft offen und fragt APS asynchron ab. Keine Datenbank; Anrufzustand, Lookup-Cache und kurzlebige Auftrags-IDs liegen im Arbeitsspeicher. APS-/AMI-Zugangsdaten liegen nur in der geschützten Serverkonfiguration außerhalb des GDT-Shares.

## Laufzeit und Konfiguration

`php bin/server.php --config=/etc/kienzlefon-t2med/config.json`; Demo: `php bin/server.php --demo --listen=127.0.0.1:8765`. Schema siehe `config.example.json`. Ein leeres APS-Passwort ist ein gültiger Wert. `aps.tls_verify=false` ist eine ausdrücklich konfigurierte Ausnahme für den vorhandenen Testserver. Der Installer bietet sie an; sie wird nie bei einem Fehler automatisch aktiviert. AMI und APS können auf verschiedenen Rechnern laufen.

## HTTP-Vertrag

Kein Benutzerlogin. Netzwerkbereich und erlaubte Hostnamen sind konfiguriert. Browser-POSTs brauchen denselben Origin und das Headerfeld `X-Kienzlefon-Token` aus `GET /api/v1/bootstrap`. Native lokale Clients holen ebenfalls dieses flüchtige Token; es ist ein Schutz gegen fremde Webseiten, kein Benutzerpasswort. Die Weboberfläche wird vom selben Dienst ausgeliefert. Keine CORS-Freigabe.

- `GET /api/v1/bootstrap`: `token`, `clients` als Liste `{id,label,extension}`, `demo`.
- `GET /api/v1/telephony`: `generated_at`, `ami_connected`, `stale`, `calls`, `queues`, `extensions`, `outgoing`.
- `POST /api/v1/lookup` mit `{number}`: `{lookup_id,number,status,patients}`. Patienten enthalten `patient_number`, `first_name`, `last_name`, `birth_date`, `address`; interne Referenzen bleiben für die Auswahlprüfung im Dienst.
- `POST /api/v1/patient-open` mit `{client_id,lookup_id,patient_number,request_id}`: ausgewählten Kandidaten als GDT schreiben. Nur zum Lookup gehörende, eindeutig identifizierbare Patientennummern akzeptieren. Noch nicht konsumierte Datei nicht überschreiben. `request_id` ist eine UUID und dient der Wiederholungserkennung.
- `POST /api/v1/calls` mit `{client_id,extension,number,request_id}`: Nebenstelle muss zur serverseitigen Client-Zuordnung passen. Erst Nebenstelle, dann Ziel anrufen. HTTP 202 bedeutet angenommen, nicht verbunden.
- `GET /patient-oeffnen?client_id=empfang-1&telefon=…`: Einstiegsseite für den Weblink. Der bloße GET schreibt keine Datei. Die Oberfläche verarbeitet eine aktive Navigation, löst den Lookup aus und bei eindeutigem Treffer den POST; mehrere Kandidaten erfordern Auswahl.
- `GET /waehlen?client_id=empfang-1&telefon=…`: Einstieg für native URI-Handler, falls kein direkter API-Aufruf erfolgt.

Status der Auflösung: `pending`, `resolved`, `ambiguous`, `not_found`, `timeout`, `error`. Allgemeine APS-Suche kann Patientennummern finden; verdächtige Zahlentreffer verlangen Auswahl und werden nicht automatisch als Telefonname verwendet. Verschiedene Patienten mit identischen Namen bleiben getrennt. Geburtsdatum aus APS unverändert anzeigen, solange dessen Format nicht gesichert ist.

## AMI und APS

APS: `POST /aps/rest/praxis/patient/suche/find`, Basic Auth, JSON `{suchmaske: <Rufnummer>, ignoreSichtbarkeit: false}`. Antwort ist eine Liste mit `nummer`, `vorname`, `nachname`, `geburtsdatum`, `adresse` und vollständigem `ref`. Keine weiteren Stammdaten-Endpunkte voraussetzen.

AMI: `Events: on`; Snapshots `CoreShowChannels` und `QueueStatus` bei Verbindung sowie periodisch. Währenddessen Events puffern und anschließend auf den vollständigen Snapshot anwenden. Verbindungsausfall oder unvollständiger Snapshot bedeutet `stale`. Kanäle und logische Anrufe unterscheiden; Brücken und Local-Kanalpaare berücksichtigen, getrennte Gesprächsbrücken trotz gleichem Linkedid getrennt halten. Warteschlangeneinträge referenzieren die Anruf-ID. Gesprächsdauer nur bei beobachteter Verbindung, sonst unbekannt; Kanalalter gesondert.

Lookup-UserEvent: `KienzlefonLookupRequest`, `SchemaVersion: 1`, `RequestId`, `SourceChannel`, `SourceUniqueid`, `SourceLinkedid`, `Number`, `BudgetMs: 1000`. Middleware setzt per bestätigtem `Setvar` nacheinander `LOOKUP_RESULT_ID`, `LOOKUP_NAME`, `LOOKUP_STATUS`, zuletzt `LOOKUP_DONE=1`. Nur temporäre Variablen auf dem jeweiligen Kanal, niemals `CALLERID`. Asterisk selbst begrenzt die Wartezeit auf eine Sekunde und übernimmt ausschließlich rechtzeitige Ergebnisse. Ende über `KienzlefonLookupFinished` mit `RequestId`, `SourceUniqueid`, `Status`, `Applied`.

Click-to-Dial: `Originate` mit `PJSIP/<extension>`, `Context` aus Konfiguration, `Exten` als normalisierte Zielnummer, `Priority: 1`, `Async: true`, `Timeout: 20000`, `EarlyMedia: false`, eigenem `ChannelId`. Variablen `__KF_C2D_ID`, `__KF_C2D_CLIENT`, `__KF_C2D_EXTENSION`. Dialplan meldet `KienzlefonClickToDialProgress` mit `RequestId`, `Phase: dialing_target`, `SourceUniqueid`. Eigentliche Verbindung erst nach Antwort des zweiten Rufabschnitts und Brücke melden. Kein automatisches Wiederholen bei ungewissem Ergebnis.

## GDT und Clients

Share `kienzlefon-GDT`, Guest ohne Anmeldung. Pro Client ein vorab konfiguriertes Unterverzeichnis, darin `AUFRUFi.gdt`. ID-only Satztyp 6301 mit korrekten Byte-Längen und CRLF. Temporäre Datei auf demselben Dateisystem und atomare Veröffentlichung ohne Überschreiben einer vorhandenen Datei. T2med überwacht pro Arbeitsplatz den zugehörigen SMB-Pfad über die Kalenderanbindung.

Linux-Installer installiert Dienst, fragt Verbindungsdaten verdeckt ab, legt geschützte Konfiguration an und ergänzt Samba additiv. Bestehende Shares/Benutzer/Globaleinstellungen erhalten. Falls ein bestehender Samba-Server Guest nicht unterstützt, keine globale Authentifizierung eigenmächtig ändern; Konflikt vor Änderungen melden und dokumentierte separate Freigabe ermöglichen.

Lokale Installer für Windows/macOS/Linux speichern Middleware-URL, Client-ID und Nebenstelle. Sie registrieren `tel:` und `callto:`. Nummern strikt prüfen, keine Ausführung von URI-Inhalt als Shellcode. Auswahl des Standardprogramms bleibt bei vom Betriebssystem verwalteten Benutzereinstellungen. Keine APS-/AMI-Zugangsdaten auf Arbeitsplätzen.
