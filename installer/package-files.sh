# Shared by the packer and the installer. An empty destination only checks
# the working tree. New runtime files belong here even before the first commit.
stage_package_files() (
    set -eu
    cd -P -- "$1"
    destination=$2
    while IFS= read -r file; do
        case $file in */*) directory=${file%/*} ;; *) directory=. ;; esac
        # Every directory in the list is directly below the package root.
        if [ -L "$directory" ] || [ -L "$file" ] || [ ! -f "$file" ] || [ ! -r "$file" ]; then
            printf 'Fehler: Paketbestandteil fehlt oder ist keine reguläre lesbare Datei: %s\n' "$file" >&2
            exit 1
        fi
        if [ -n "$destination" ]; then
            mkdir -p "$destination/$directory"
            cp "$file" "$destination/$file"
        fi
    done <<'PACKAGE_FILES'
LICENSE
composer.json
composer.lock
config.example.json
config.example.toml
bin/server.php
bin/check-connections.php
bin/check-telephony-control.php
clients/install-linux.sh
clients/install-macos.sh
clients/install-windows.ps1
clients/kienzlefon_call.py
clients/macos-bundle.sh
installer/install.sh
installer/check-app-counters.php
installer/configure.php
installer/ami_connection.py
installer/kienzlefon-t2med.service
installer/package-files.sh
installer/samba.php
libexec/fhir-importer-linux-amd64
libexec/fhir-importer-linux-arm64
libexec/README.md
libexec/SHA256SUMS
public/app.js
public/favicon.svg
public/fhir.css
public/fhir.html
public/fhir.js
public/index.html
public/style.css
resources/telephony-v1.schema.json
scripts/pack.sh
src/Ami.php
src/Aps.php
src/CallStatistics.php
src/Config.php
src/ControlError.php
src/ControlTransport.php
src/FhirImport.php
src/Gdt.php
src/HttpApi.php
src/PatientDirectory.php
src/Patients.php
src/PracticeApp.php
src/Phone.php
src/RoutingJournal.php
src/Runtime.php
src/Telephony.php
src/TelephonyContract.php
src/TelephonyControl.php
src/Transfers.php
src/Version.php
PACKAGE_FILES
)
