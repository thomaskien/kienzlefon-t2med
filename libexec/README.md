# FHIR-Import-Binaries

`fhir-importer-linux-amd64` und `fhir-importer-linux-arm64` enthalten den für die
erste Integration vereinbarten T2med-Demo-API-Key. Der Installer kopiert die
passende Datei als `fhir-importer`. Es wird keine Schlüsseldatei installiert.

Der Quellcode steht unter `fhir-importer/`. Zum Neubau liest `build.sh` den
API-Key über stdin; Ausgabe und Plattform können als Argumente übergeben werden:

```sh
./fhir-importer/build.sh /absoluter/ausgabepfad linux amd64
```

Eine kontrollierte Quelle muss den Schlüssel an stdin liefern. Der Schlüssel
gehört nicht in Kommandozeilenargumente, Sourcecode oder Build-Logs. Der Build
benötigt Go, aber keine zusätzlichen Module oder Netzwerkdownloads.
