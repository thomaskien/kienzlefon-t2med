# Telefonverzeichnis über FHIR

FHIR ist seit 1.4.0 die Standardquelle. T2med stößt den vollständigen Abruf an; danach sucht die Middleware lokal in SQLite. APS bleibt nur mit `patients.provider = "aps"` als Altbetrieb aktiv. Es gibt keinen automatischen Rückfall.

Nach Änderungen an Patientendaten oder Telefonnummern in T2med **Telefonverzeichnis aktualisieren** erneut ausführen. Änderungen werden erst nach einem erfolgreichen Abgleich übernommen; es gibt keinen automatischen Abgleich.

## Aufruf in T2med einrichten

In T2med unter **Drittanbieter konfigurieren** einen Eintrag **Telefonverzeichnis aktualisieren** anlegen und eine der folgenden vollständigen URL-Zeilen eintragen.

Query-Variante:

```text
http://10.0.83.120:8766/telefonverzeichnis-aktualisieren.php?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}
```

Fragment-Variante:

```text
http://10.0.83.120:8766/telefonverzeichnis-aktualisieren.php#kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}
```

Beide Varianten funktionieren praktisch aus T2med. T2med ersetzt die drei Platzhalter und URL-kodiert ihre Werte. Die Zeilen enthalten normale `&`, keine Shell-Backslashes. Die Route ohne `.php` ist ebenfalls erreichbar; der PHP-Dienst liefert die Seite selbst aus.

Die Query-Variante übergibt Parameter in der aufgerufenen URL. Bei `#` verarbeitet der Browser das Fragment und sendet die Parameter erst per POST an die Middleware. **Beide Beispiele verwenden HTTP und übertragen deshalb unverschlüsselt**; die Query-Variante ist nicht als sicherer einzustufen. Zugangsdaten können bei Query zusätzlich in vorgeschalteten URL-Protokollen landen.

Die Seite entfernt Query oder Fragment aus der sichtbaren Adresse und startet genau einen Auftrag. Gemischte, doppelte, unvollständige oder nicht ersetzte Parameter werden abgewiesen. Neuladen wiederholt den Import nicht. Ohne Parameter zeigt die Seite nur den letzten Status.

## Kontingent des Demo-API-Keys

Der eingebettete T2med-Demo-API-Key erlaubt nur **100 Requests**. Das sind weder 100 Patienten noch 100 vollständige Abgleiche: Ein paginierter Patientenabruf benötigt mehrere Requests. Die anschließende lokale Suche im gespeicherten Bestand benötigt keinen FHIR-Request.

## Serverseitiger Ablauf

`POST /api/v1/fhir/import` übernimmt `kontextId`, `fhirBasisUrl`, `oAuthToken` und eine UUID. Die Basisadresse muss `fhir.base_url` entsprechen; der Kontext filtert keine Patienten. Netzwerk-, Host-, Origin- und Token-Prüfungen bleiben aktiv.

`/opt/kienzlefon-t2med/libexec/fhir-importer` enthält den Demo-API-Key. Der Bearer-Token geht nur über stdin an den Kindprozess, nicht über Argumente, Umgebung, Dateien, SQLite oder Status. Es gibt keinen automatischen Token-Refresh.

Der Importer liest `GET /Patient`, folgt nur zulässigen Folgeseiten am konfigurierten Ziel und prüft Bundles, IDs, Patientennummern, Gesamtzahl, Schleifen und Limits. Redirects und Umgebungs-Proxies sind deaktiviert. Nur ein vollständiger erfolgreicher Lauf aktiviert die neue SQLite-Datei atomar; bei Fehler oder Abbruch bleibt der bisherige Bestand aktiv. Parallele Importe werden verhindert. Das Schließen des Browsers bricht einen angenommenen Import nicht ab.

`GET /api/v1/fhir/status` liefert Fortschritt, letztes Ergebnis, Zeitpunkt und Bestandszahlen, aber keine Patienten oder Tokens. Ein fehlgeschlagener Import ist damit sichtbar, jedoch nicht automatisch behoben.

## Daten und Suche

Gespeichert werden FHIR-ID, echte Patientennummer, Vor-/Nachname, originale und normalisierte Telefonwerte sowie Bestandsmetadaten; keine klinischen Daten, Geburtsdaten oder Adressen.

Die Patientennummer stammt ausschließlich aus `https://fhir.t2med.de/identifier/patientennummer`. Fehlt sie oder ist sie widersprüchlich, bleibt sie leer und der GDT-Aufruf ist gesperrt. Die FHIR-ID wird nie als Patientennummer verwendet.

Gemeinsame Rufnummern liefern alle Patienten. Nationale Nummern sowie `+49`, `0049` und `49` verwenden denselben Suchschlüssel. `fhir.local_area_code` kann Ortsnummern ergänzen; ohne diese Einstellung werden Ortsnummern ab drei Ziffern gegen das vollständige Ende nationaler Nummern verglichen. Alle vollständigen Treffer und passenden Endungen werden vereinigt und pro Patient dedupliziert; ausländische Nummern erhalten keinen solchen Endvergleich.

Die manuelle Suche findet Teilnamen mit Umlautumschrift, Namen in beliebiger Reihenfolge, exakte Patientennummern und Telefonnummern. Rein numerische Eingaben vereinigen Patienten- und Telefontreffer. Patienten ohne Telefon bleiben auffindbar. Mehr als 200 Namenstreffer erfordern eine genauere Eingabe; Telefonkandidaten bleiben vollständig.

Die Wahlhilfe übergibt bei eindeutiger Rufnummernzuordnung den echten Namen an Asterisk. Bei keinem oder mehreren Patienten bleibt diese Namensangabe leer; in der UI bleiben mehrere Patienten auswählbar. Die lokale Suche funktioniert ohne neuen Token und ohne FHIR-Request.

## Konfiguration und Abnahme

Beispiele stehen in [config.example.toml](../config.example.toml) und `config.example.json`. Alte Konfigurationen leiten die FHIR-Basis aus der bisherigen APS-Adresse plus `/aps/fhir/api/r4` ab. Ein Update stellt die Standardquelle auf FHIR; bis zum ersten erfolgreichen Abgleich ist keine Patientensuche verfügbar. Alt-APS muss ausdrücklich gesetzt werden.

Der Bestand liegt in `/var/lib/kienzlefon-t2med/patients.sqlite`, der Importstatus in `patients.sqlite.status.json` im selben Verzeichnis, außerhalb des GDT-Shares. `bin/check-connections.php` prüft im FHIR-Modus den gespeicherten Bestand und AMI; den echten FHIR-Zugang prüft nur der T2med-Aufruf.

Zur Abnahme beide URL-Varianten aus T2med ausführen und jeweils Status sowie erwartete Bestandszahl prüfen. Danach bekannte, unbekannte und gemeinsam verwendete Nummern, Patientensuche, einen freigegebenen GDT-Aufruf und die Suche nach einem Dienstneustart testen. Ein abgelaufener Token oder fehlerhafter Import darf den letzten erfolgreichen Bestand nicht ersetzen.
