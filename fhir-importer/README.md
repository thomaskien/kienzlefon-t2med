# T2med FHIR importer

Die eigenständige Go-Binary liest genau ein JSON-Objekt von stdin und schreibt ausschließlich NDJSON nach stdout. Sie fragt den vollständigen `Patient`-Bestand eines zuvor serverseitig freigegebenen T2med-FHIR-Endpunkts ab. Sie schreibt nie auf den FHIR-Server.

## Eingabe

```json
{"base_url":"https://fhir.example.test/fhir","token":"...","context_id":"...","tls_verify":true,"ca_file":"","timeout_seconds":30,"max_pages":1000,"max_patients":1000000}
```

`tls_verify` ist standardmäßig `true`; nur ein explizites `false` schaltet die Zertifikatsprüfung aus. `timeout_seconds` muss zwischen 1 und 120 liegen. Der Wert 0 wählt für Timeout, Seiten und Patienten die oben gezeigten Standardwerte. `context_id` wird validiert, aber nicht als Suchfilter gesendet.

Fortschrittszeilen haben den Typ `progress`. Darauf folgt genau eine `complete`-Zeile, oder bei einem Abbruch genau eine `error`-Zeile mit einem generischen Code. Fehlerausgaben enthalten keine Patientendaten oder Zugangsdaten.

## Build

Der API-Key wird nur über stdin an das Build-Skript übergeben. Das Skript legt Quellkopie und generierte Schlüsseldatei in einem geschützten temporären Verzeichnis ab und entfernt sie anschließend.

```sh
printf '%s' "$API_KEY_FROM_SECURE_SOURCE" | OUTPUT=./fhir-importer GOOS=linux GOARCH=amd64 ./build.sh
```

`OUTPUT`, `GOOS` und `GOARCH` steuern Ausgabe und Zielplattform; der Key darf weder als Argument noch als Umgebungsvariable übergeben werden. Der Build benötigt keine externen Go-Module und setzt `CGO_ENABLED=0`, `-trimpath` sowie `-s -w`.

## Entwicklung

```sh
GOCACHE=/private/tmp/kienzlefon-fhir-go-cache go test ./...
GOCACHE=/private/tmp/kienzlefon-fhir-go-cache go vet ./...
```
