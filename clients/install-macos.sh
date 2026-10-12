#!/bin/sh
set -eu

DRY_RUN=0
MAKE_DEFAULT=0
URL=
CLIENT_ID=
EXTENSION=
usage() { printf 'Aufruf: clients/install-macos.sh [--dry-run] [--make-default] [--url URL --client-id ID --extension NUMMER]\n'; }
while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --make-default) MAKE_DEFAULT=1; shift ;;
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
"$PYTHON" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 9) else 1)' || {
    printf 'Fehler: Python 3.9 oder neuer ist erforderlich.\n' >&2
    exit 1
}
command -v osacompile >/dev/null 2>&1 || { printf 'Fehler: osacompile fehlt.\n' >&2; exit 1; }
if [ -z "$URL" ]; then printf 'Middleware-URL: '; IFS= read -r URL; fi
if [ -z "$CLIENT_ID" ]; then printf 'Client-ID: '; IFS= read -r CLIENT_ID; fi
if [ -z "$EXTENSION" ]; then printf 'Nebenstelle: '; IFS= read -r EXTENSION; fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP="$HOME/Applications/Kienzlefon T2med.app"
CONFIG_FILE="$HOME/Library/Application Support/KienzlefonT2med/client.json"
CR=$(printf '\r')
case "$PYTHON$CONFIG_FILE" in
    *"
"*|*"$CR"*) printf 'Fehler: Installationspfade mit Zeilenumbrüchen werden nicht unterstützt.\n' >&2; exit 1 ;;
esac
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
"$PYTHON" "$SCRIPT_DIR/kienzlefon_call.py" --write-config --config "$VALIDATION/client.json" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION" >/dev/null
if [ "$DRY_RUN" -eq 1 ]; then
    printf 'Dry-run: würde %s und %s installieren; keine Registrierung und kein Telefonat.\n' "$APP" "$CONFIG_FILE"
    [ "$MAKE_DEFAULT" -eq 0 ] || printf 'Würde zusätzlich die Standard-App für tel/callto auf Kienzlefon T2med setzen.\n'
    exit 0
fi

mkdir -p -- "$HOME/Applications" "$(dirname "$CONFIG_FILE")"
AS_PYTHON=$(printf '%s' "$PYTHON" | sed 's/\\/\\\\/g; s/"/\\"/g')
AS_CONFIG=$(printf '%s' "$CONFIG_FILE" | sed 's/\\/\\\\/g; s/"/\\"/g')
cat >"$VALIDATION/main.applescript" <<EOF
use scripting additions

property pythonPath : "$AS_PYTHON"
property configPath : "$AS_CONFIG"

on invokeHelper(theURL)
    set helperPath to (POSIX path of (path to me)) & "Contents/Resources/kienzlefon_call.py"
    set commandText to quoted form of pythonPath & " " & quoted form of helperPath & " --config " & quoted form of configPath
    if theURL is "" then
        set commandText to commandText & " --open-monitor"
    else
        set commandText to commandText & " --open-browser -- " & quoted form of theURL
    end if
    try
        do shell script commandText
    on error errorMessage
        display alert "Kienzlefon T2med" message errorMessage as critical
    end try
end invokeHelper

on open location theURL
    my invokeHelper(theURL)
end open location

on run
    my invokeHelper("")
end run
EOF
if [ -e "$APP" ]; then
    IDENTIFIER=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$APP/Contents/Info.plist" 2>/dev/null || true)
    [ "$IDENTIFIER" = de.kienzlefon.t2med ] || { printf 'Fehler: Vorhandene App am Ziel gehört nicht zu dieser Installation.\n' >&2; exit 1; }
fi
STAGED_APP="$VALIDATION/Kienzlefon T2med.app"
osacompile -o "$STAGED_APP" "$VALIDATION/main.applescript"
. "$SCRIPT_DIR/macos-bundle.sh"
prepare_macos_bundle "$STAGED_APP" "$SCRIPT_DIR/kienzlefon_call.py"
if [ -e "$APP" ]; then mv -- "$APP" "$VALIDATION/previous.app"; PREVIOUS=1; fi
REPLACED=1
mv -- "$STAGED_APP" "$APP"
/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister -f "$APP"
"$PYTHON" "$APP/Contents/Resources/kienzlefon_call.py" --write-config --config "$CONFIG_FILE" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION"
COMMITTED=1
if [ "$MAKE_DEFAULT" -eq 1 ]; then
    /usr/bin/osascript -l JavaScript <<'JXA'
ObjC.import('CoreServices');
['tel', 'callto'].forEach(function (scheme) {
    var status = $.LSSetDefaultHandlerForURLScheme($(scheme), $('de.kienzlefon.t2med'));
    if (status !== 0) throw new Error('Standard-App für ' + scheme + ' konnte nicht gesetzt werden (OSStatus ' + status + ').');
});
JXA
    printf 'Installiert und als Standard für tel/callto angefordert. Eine Nachfrage von macOS bitte bestätigen.\n'
else
    printf 'Installiert. Für die Zuordnung als Standard-App denselben Befehl mit --make-default erneut ausführen.\n'
fi
