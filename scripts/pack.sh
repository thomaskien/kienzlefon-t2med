#!/usr/bin/env bash
set -euo pipefail

usage() { echo 'Aufruf im Quellverzeichnis: bash scripts/pack.sh --output /absoluter/pfad/t2med.tar.gz'; }
if [[ $# == 1 && ( $1 == --help || $1 == -h ) ]]; then usage; exit 0; fi
if [[ $# != 2 || $1 != --output || $2 != /* || $2 == */ || -d $2 ]]; then usage >&2; exit 2; fi
output=$2
source_root=$(pwd -P)
source "$source_root/installer/package-files.sh"

# Place the temporary archive on the destination filesystem for an atomic rename.
work=$(mktemp -d "$(dirname "$output")/.t2med-pack.XXXXXX")
trap 'rm -rf -- "$work"' EXIT
trap 'exit 1' HUP INT TERM
export COPYFILE_DISABLE=1
mkdir "$work/t2med"
stage_package_files "$source_root" "$work/t2med"
(
    cd "$work/t2med/libexec"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum -c SHA256SUMS
    else
        shasum -a 256 -c SHA256SUMS
    fi
)
# ustar contains only portable file metadata, including executable bits.
tar --format=ustar -czf "$work/archive.tar.gz" -C "$work" t2med
mv -f -- "$work/archive.tar.gz" "$output"
printf 'Paket erstellt: %s\n' "$output"
