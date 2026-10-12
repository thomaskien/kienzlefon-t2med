#!/bin/sh
set -eu

DRY_RUN=0
URL=
CLIENT_ID=
EXTENSION=

usage() {
    cat <<'EOF'
Aufruf: clients/install-linux.sh [--dry-run] [--url URL --client-id ID --extension NUMMER]
Installiert den tel:/callto:-Handler nur für den aktuellen Desktopbenutzer (kein sudo).
EOF
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --url) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; URL=$2; shift 2 ;;
        --client-id) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; CLIENT_ID=$2; shift 2 ;;
        --extension) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; EXTENSION=$2; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

[ "$(uname -s)" = Linux ] || { printf 'Fehler: Dieser Installer ist nur für Linux.\n' >&2; exit 1; }
[ "$(id -u)" -ne 0 ] || { printf 'Fehler: Als normaler Desktopbenutzer, nicht als root, ausführen.\n' >&2; exit 1; }
PYTHON=$(command -v python3 || true)
[ -n "$PYTHON" ] || { printf 'Fehler: Python 3 muss installiert sein.\n' >&2; exit 1; }
"$PYTHON" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 9) else 1)' || {
    printf 'Fehler: Python 3.9 oder neuer ist erforderlich.\n' >&2
    exit 1
}

if [ -z "$URL" ]; then printf 'Middleware-URL: '; IFS= read -r URL; fi
if [ -z "$CLIENT_ID" ]; then printf 'Client-ID: '; IFS= read -r CLIENT_ID; fi
if [ -z "$EXTENSION" ]; then printf 'Nebenstelle: '; IFS= read -r EXTENSION; fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
INSTALL_DIR=${XDG_DATA_HOME:-"$HOME/.local/share"}/kienzlefon-t2med
CONFIG_FILE=${XDG_CONFIG_HOME:-"$HOME/.config"}/kienzlefon-t2med/client.json
DESKTOP_DIR=${XDG_DATA_HOME:-"$HOME/.local/share"}/applications
LAUNCHER_FILE=$DESKTOP_DIR/kienzlefon-t2med.desktop
HANDLER_FILE=$DESKTOP_DIR/kienzlefon-t2med-url.desktop
CR=$(printf '\r')
case "$PYTHON$INSTALL_DIR$CONFIG_FILE" in
    *"
"*|*"$CR"*) printf 'Fehler: Installationspfade mit Zeilenumbrüchen werden nicht unterstützt.\n' >&2; exit 1 ;;
esac

VALIDATION=$(mktemp -d "${TMPDIR:-/tmp}/kienzlefon-client.XXXXXX")
trap 'rm -rf -- "$VALIDATION"' EXIT HUP INT TERM
"$PYTHON" "$SCRIPT_DIR/kienzlefon_call.py" --write-config --config "$VALIDATION/client.json" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION" >/dev/null
if [ "$DRY_RUN" -eq 1 ]; then
    printf 'Dry-run: würde Helper nach %s, Konfiguration nach %s sowie Launcher %s und Protokollhandler %s installieren.\n' "$INSTALL_DIR" "$CONFIG_FILE" "$LAUNCHER_FILE" "$HANDLER_FILE"
    printf 'Keine Registrierung und kein Telefonat wurden ausgeführt.\n'
    exit 0
fi

command -v xdg-mime >/dev/null 2>&1 || { printf 'Fehler: xdg-mime fehlt (Paket xdg-utils).\n' >&2; exit 1; }
ERROR_TOOL=
for candidate in zenity kdialog xmessage notify-send; do
    if command -v "$candidate" >/dev/null 2>&1; then ERROR_TOOL=$candidate; break; fi
done
[ -n "$ERROR_TOOL" ] || {
    printf 'Fehler: Für sichtbare Desktopfehler wird zenity, kdialog, xmessage oder notify-send benötigt.\n' >&2
    exit 1
}

if [ -e "$INSTALL_DIR/kienzlefon_call.py" ] || [ -L "$INSTALL_DIR/kienzlefon_call.py" ]; then
    if [ -L "$INSTALL_DIR/kienzlefon_call.py" ] || [ ! -f "$INSTALL_DIR/kienzlefon_call.py" ] || ! grep -q 'Safe per-user tel:/callto: handler for Kienzlefon T2med' "$INSTALL_DIR/kienzlefon_call.py"; then
        printf 'Fehler: Fremde Datei bleibt erhalten: %s\n' "$INSTALL_DIR/kienzlefon_call.py" >&2
        exit 1
    fi
fi
if [ -e "$LAUNCHER_FILE" ] || [ -L "$LAUNCHER_FILE" ]; then
    if [ -L "$LAUNCHER_FILE" ] || [ ! -f "$LAUNCHER_FILE" ] || ! grep -q 'Name=Kienzlefon T2med' "$LAUNCHER_FILE"; then
        printf 'Fehler: Fremde Desktopdatei bleibt erhalten: %s\n' "$LAUNCHER_FILE" >&2
        exit 1
    fi
fi
if [ -e "$HANDLER_FILE" ] || [ -L "$HANDLER_FILE" ]; then
    if [ -L "$HANDLER_FILE" ] || [ ! -f "$HANDLER_FILE" ] || ! grep -q 'X-KienzlefonT2med=true' "$HANDLER_FILE"; then
        printf 'Fehler: Fremde Desktopdatei bleibt erhalten: %s\n' "$HANDLER_FILE" >&2
        exit 1
    fi
fi

mkdir -p -- "$INSTALL_DIR" "$DESKTOP_DIR"
chmod 700 "$INSTALL_DIR"
install -m 0755 "$SCRIPT_DIR/kienzlefon_call.py" "$INSTALL_DIR/kienzlefon_call.py"
"$PYTHON" "$INSTALL_DIR/kienzlefon_call.py" --write-config --config "$CONFIG_FILE" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION"

desktop_quote() {
    # Desktop entries decode string escapes before Exec argument quoting.
    "$PYTHON" -c 'import sys; s=sys.argv[1]; s="".join("\\"+c if c in "\\\"`$" else c for c in s); print(s.replace("\\", "\\\\").replace("%", "%%"), end="")' "$1"
}
Q_PYTHON=$(desktop_quote "$PYTHON")
Q_HELPER=$(desktop_quote "$INSTALL_DIR/kienzlefon_call.py")
Q_CONFIG=$(desktop_quote "$CONFIG_FILE")
cat >"$LAUNCHER_FILE" <<EOF
[Desktop Entry]
Type=Application
Name=Kienzlefon T2med
Comment=Kompaktübersicht der Praxis-Telefonanlage öffnen
Exec="$Q_PYTHON" "$Q_HELPER" --open-monitor --desktop-errors --config "$Q_CONFIG"
Terminal=false
NoDisplay=false
Categories=Utility;
X-KienzlefonT2med=true
EOF
cat >"$HANDLER_FILE" <<EOF
[Desktop Entry]
Type=Application
Name=Kienzlefon T2med URL-Handler
Comment=Direkt über die zugeordnete Nebenstelle wählen
Exec="$Q_PYTHON" "$Q_HELPER" --direct --desktop-errors --config "$Q_CONFIG" -- %u
Terminal=false
NoDisplay=true
MimeType=x-scheme-handler/tel;x-scheme-handler/callto;
X-KienzlefonT2med=true
EOF
chmod 0644 "$LAUNCHER_FILE" "$HANDLER_FILE"
if command -v update-desktop-database >/dev/null 2>&1; then update-desktop-database "$DESKTOP_DIR" >/dev/null || true; fi
xdg-mime default kienzlefon-t2med-url.desktop x-scheme-handler/tel
xdg-mime default kienzlefon-t2med-url.desktop x-scheme-handler/callto
printf 'Installiert. Falls der Desktop nachfragt, Kienzlefon T2med als Standard für tel/callto bestätigen.\n'
