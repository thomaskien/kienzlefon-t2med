#!/bin/sh
set -eu

umask 077
source_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
output=${1:-${OUTPUT:-"$source_dir/fhir-importer"}}
build_os=${2:-${GOOS:-$(go env GOOS)}}
build_arch=${3:-${GOARCH:-$(go env GOARCH)}}
case "$output" in
	/*) ;;
	*) output="$(pwd)/$output" ;;
esac

build_dir=$(mktemp -d "${TMPDIR:-/tmp}/kienzlefon-fhir-build.XXXXXX")
chmod 700 "$build_dir"
trap 'rm -rf "$build_dir"' EXIT HUP INT TERM

secret_file="$build_dir/api-key"
key_source="$build_dir/key_generated.go"
cat >"$secret_file"
chmod 600 "$secret_file"
if [ ! -s "$secret_file" ]; then
	echo "API key missing on stdin" >&2
	exit 1
fi

cp "$source_dir/main.go" "$source_dir/go.mod" "$build_dir/"
{
	echo 'package main'
	echo 'func init() { embeddedAPIKey = string([]byte{'
	od -An -v -t u1 "$secret_file" | awk '{ for (i = 1; i <= NF; i++) printf "%s,", $i; print "" }'
	echo '}) }'
} >"$key_source"
chmod 600 "$key_source"

(
	cd "$build_dir"
	CGO_ENABLED=0 GOOS=$build_os GOARCH=$build_arch \
		go build -trimpath -ldflags='-s -w' -o "$output" .
)
chmod 755 "$output"
