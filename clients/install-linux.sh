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
command -v python3 >/dev/null 2>&1 || { printf 'Fehler: Python 3 muss installiert sein.\n' >&2; exit 1; }

if [ -z "$URL" ]; then printf 'Middleware-URL: '; IFS= read -r URL; fi
if [ -z "$CLIENT_ID" ]; then printf 'Client-ID: '; IFS= read -r CLIENT_ID; fi
if [ -z "$EXTENSION" ]; then printf 'Nebenstelle: '; IFS= read -r EXTENSION; fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
INSTALL_DIR=${XDG_DATA_HOME:-"$HOME/.local/share"}/kienzlefon-t2med
CONFIG_FILE=${XDG_CONFIG_HOME:-"$HOME/.config"}/kienzlefon-t2med/client.json
DESKTOP_DIR=${XDG_DATA_HOME:-"$HOME/.local/share"}/applications

VALIDATION=$(mktemp -d "${TMPDIR:-/tmp}/kienzlefon-client.XXXXXX")
trap 'rm -rf -- "$VALIDATION"' EXIT HUP INT TERM
python3 "$SCRIPT_DIR/kienzlefon_call.py" --write-config --config "$VALIDATION/client.json" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION" >/dev/null
if [ "$DRY_RUN" -eq 1 ]; then
    printf 'Dry-run: würde Helper nach %s, Konfiguration nach %s und Desktopdatei nach %s installieren.\n' "$INSTALL_DIR" "$CONFIG_FILE" "$DESKTOP_DIR/kienzlefon-t2med.desktop"
    printf 'Keine Registrierung und kein Telefonat wurden ausgeführt.\n'
    exit 0
fi

mkdir -p -- "$INSTALL_DIR" "$DESKTOP_DIR"
chmod 700 "$INSTALL_DIR"
install -m 0755 "$SCRIPT_DIR/kienzlefon_call.py" "$INSTALL_DIR/kienzlefon_call.py"
python3 "$INSTALL_DIR/kienzlefon_call.py" --write-config --config "$CONFIG_FILE" --url "$URL" --client-id "$CLIENT_ID" --extension "$EXTENSION"
cat >"$DESKTOP_DIR/kienzlefon-t2med.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=Kienzlefon T2med
Comment=Rufnummer durch die Praxis-Telefonanlage wählen
Exec=python3 "$INSTALL_DIR/kienzlefon_call.py" %u
Terminal=true
NoDisplay=true
MimeType=x-scheme-handler/tel;x-scheme-handler/callto;
EOF
chmod 0644 "$DESKTOP_DIR/kienzlefon-t2med.desktop"
if command -v update-desktop-database >/dev/null 2>&1; then update-desktop-database "$DESKTOP_DIR" >/dev/null || true; fi
if command -v xdg-mime >/dev/null 2>&1; then
    xdg-mime default kienzlefon-t2med.desktop x-scheme-handler/tel || true
    xdg-mime default kienzlefon-t2med.desktop x-scheme-handler/callto || true
fi
printf 'Installiert. Falls der Desktop nachfragt, Kienzlefon T2med als Standard für tel/callto bestätigen.\n'
