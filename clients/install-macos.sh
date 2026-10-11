#!/bin/sh
set -eu

DRY_RUN=0
URL=
CLIENT_ID=
EXTENSION=
usage() { printf 'Aufruf: clients/install-macos.sh [--dry-run] [--url URL --client-id ID --extension NUMMER]\n'; }
while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --url) [ "$#" -ge 2 ] || exit 2; URL=$2; shift 2 ;;
        --client-id) [ "$#" -ge 2 ] || exit 2; CLIENT_ID=$2; shift 2 ;;
        --extension) [ "$#" -ge 2 ] || exit 2; EXTENSION=$2; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done
[ "$(uname -s)" = Darwin ] || { printf 'Fehler: Dieser Installer ist nur für macOS.\n' >&2; exit 1; }
[ "$(id -u)" -ne 0 ] || { printf 'Fehler: Als normaler Benutzer, nicht mit sudo, ausführen.\n' >&2; exit 1; }
PYTHON=$(command -v python3 || true)
[ -n "$PYTHON" ] || { printf 'Fehler: Python 3 ist auf diesem Mac nicht verfügbar. Bitte Python 3 installieren und erneut ausführen.\n' >&2; exit 1; }
command -v osacompile >/dev/null 2>&1 || { printf 'Fehler: osacompile fehlt.\n' >&2; exit 1; }
if [ -z "$URL" ]; then printf 'Middleware-URL: '; IFS= read -r URL; fi
if [ -z "$CLIENT_ID" ]; then printf 'Client-ID: '; IFS= read -r CLIENT_ID; fi
if [ -z "$EXTENSION" ]; then printf 'Nebenstelle: '; IFS= read -r EXTENSION; fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP="$HOME/Applications/Kienzlefon T2med.app"
CONFIG_FILE="$HOME/Library/Application Support/KienzlefonT2med/client.json"
VALIDATION=$(mktemp -d "${TMPDIR:-/tmp}/kienzlefon-client.XXXXXX")
COMMITTED=0
REPLACED=0
PREVIOUS=0
cleanup() {
    status=$?
    if [ "$COMMITTED" -eq 0 ] && [ "$REPLACED" -eq 1 ]; then
        rm -rf -- "$APP"
        if [ "$PREVIOUS" -eq 1 ]; then mv -- "$VALIDATION/previous.app" "$APP"; fi
    fi
    rm -rf -- "$VALIDATION"
    exit "$status"
}
trap cleanup EXIT HUP INT TERM
python3 "$SCRIPT_DIR/kienzlefon_call.py" --write-config --config "$VALIDATION/client.json" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION" >/dev/null
if [ "$DRY_RUN" -eq 1 ]; then
    printf 'Dry-run: würde %s und %s installieren; keine Registrierung und kein Telefonat.\n' "$APP" "$CONFIG_FILE"
    exit 0
fi

mkdir -p -- "$HOME/Applications" "$(dirname "$CONFIG_FILE")"
AS_PYTHON=$(printf '%s' "$PYTHON" | sed 's/\\/\\\\/g; s/"/\\"/g')
AS_HELPER=$(printf '%s' "$APP/Contents/Resources/kienzlefon_call.py" | sed 's/\\/\\\\/g; s/"/\\"/g')
cat >"$VALIDATION/main.applescript" <<EOF
on open location theURL
    display dialog "Rufnummer wirklich über die Praxis-Telefonanlage wählen?" buttons {"Abbrechen", "Wählen"} default button "Abbrechen" cancel button "Abbrechen"
    set responseText to do shell script quoted form of "$AS_PYTHON" & space & quoted form of "$AS_HELPER" & space & "--confirmed" & space & quoted form of theURL
    display dialog responseText buttons {"OK"} default button "OK"
end open location
EOF
if [ -e "$APP" ]; then
    IDENTIFIER=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$APP/Contents/Info.plist" 2>/dev/null || true)
    [ "$IDENTIFIER" = de.kienzlefon.t2med ] || { printf 'Fehler: Vorhandene App am Ziel gehört nicht zu dieser Installation.\n' >&2; exit 1; }
fi
STAGED_APP="$VALIDATION/Kienzlefon T2med.app"
osacompile -o "$STAGED_APP" "$VALIDATION/main.applescript"
mkdir -p -- "$STAGED_APP/Contents/Resources"
install -m 0755 "$SCRIPT_DIR/kienzlefon_call.py" "$STAGED_APP/Contents/Resources/kienzlefon_call.py"
python3 "$STAGED_APP/Contents/Resources/kienzlefon_call.py" --write-config --config "$CONFIG_FILE" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION"
/usr/libexec/PlistBuddy -c 'Set :CFBundleIdentifier de.kienzlefon.t2med' "$STAGED_APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Delete :CFBundleURLTypes' "$STAGED_APP/Contents/Info.plist" >/dev/null 2>&1 || true
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes array' "$STAGED_APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0 dict' "$STAGED_APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLName string de.kienzlefon.t2med.call' "$STAGED_APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes array' "$STAGED_APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes:0 string tel' "$STAGED_APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes:1 string callto' "$STAGED_APP/Contents/Info.plist"
if [ -e "$APP" ]; then mv -- "$APP" "$VALIDATION/previous.app"; PREVIOUS=1; fi
REPLACED=1
mv -- "$STAGED_APP" "$APP"
/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister -f "$APP"
COMMITTED=1
printf 'Installiert. macOS kann beim ersten tel:/callto:-Aufruf die Auswahl des Standardprogramms verlangen; bitte Kienzlefon T2med bestätigen.\n'
