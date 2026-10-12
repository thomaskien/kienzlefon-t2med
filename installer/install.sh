#!/bin/sh
set -eu

APP_DIR=/opt/kienzlefon-t2med
CONFIG_DIR=/etc/kienzlefon-t2med
CONFIG_FILE=$CONFIG_DIR/config.toml
LEGACY_CONFIG_FILE=$CONFIG_DIR/config.json
UNIT_FILE=/etc/systemd/system/kienzlefon-t2med.service
SMB_MAIN=/etc/samba/smb.conf
SMB_INCLUDE=/etc/samba/kienzlefon-t2med.conf
SERVICE_USER=kienzlefon-t2med
DRY_RUN=0
SKIP_SAMBA=0
RECONFIGURE=0
KEEP_CONFIG=0
WORK_DIR=
MUTATING=0
SUCCESS=0
APP_BACKUP=
CONFIG_BACKUP=
UNIT_BACKUP=
SMB_MAIN_BACKUP=
SMB_INCLUDE_BACKUP=
APP_EXISTED=0
CONFIG_EXISTED=0
UNIT_EXISTED=0
SMB_MAIN_EXISTED=0
SMB_INCLUDE_EXISTED=0
SERVICE_WAS_ACTIVE=0
SMB_WAS_ACTIVE=0
SMB_WAS_ENABLED=0
SMB_SERVICE_TOUCHED=0
APP_TOUCHED=0
CONFIG_TOUCHED=0
UNIT_TOUCHED=0
SMB_TOUCHED=0
SERVICE_TOUCHED=0

usage() {
    cat <<'EOF'
Aufruf: sudo installer/install.sh [OPTIONEN]

Installiert oder aktualisiert den Kienzlefon-T2med-Dienst auf Debian 12+,
Ubuntu 24.04+ oder Raspberry Pi OS Bookworm+.

  --dry-run       zeigt den Plan; funktioniert auch auf macOS und ändert nichts
  --reconfigure   fragt die Serverkonfiguration neu ab; Secrets können separat
                  beibehalten oder explizit leer gesetzt werden
  --keep-config   überspringt bei Updates die Nachfrage nach Änderungen
  --skip-samba    richtet Samba nicht ein (Share muss extern bereitstehen)
  -h, --help      zeigt diese Hilfe

Vorhandenes TOML bleibt bei Ablehnung bytegleich. Eine vorhandene Legacy-JSON-Datei
wird verlustfrei nach TOML migriert und als Sicherung nicht gelöscht. Secrets werden
nie als Kommandozeilenargument oder ins Log geschrieben.
EOF
}

say() { printf '%s\n' "$*"; }
fail() { printf 'Fehler: %s\n' "$*" >&2; exit 1; }

select_existing_config() {
    if [ -f "$CONFIG_FILE" ]; then
        printf '%s\n' "$CONFIG_FILE"
    elif [ -f "$LEGACY_CONFIG_FILE" ]; then
        printf '%s\n' "$LEGACY_CONFIG_FILE"
    fi
}

start_middleware() {
    # Collect the actual startup error while the failed unit still exists.
    # Do not print the configuration: it contains APS/AMI credentials.
    START_SINCE=$(date '+%Y-%m-%d %H:%M:%S')
    if [ "$SERVICE_WAS_ACTIVE" -eq 1 ]; then START_ACTION=restart; else START_ACTION=start; fi
    if systemctl "$START_ACTION" kienzlefon-t2med.service; then
        sleep 1
        if systemctl is-active --quiet kienzlefon-t2med.service; then return 0; fi
    fi
    printf 'Dienststart fehlgeschlagen. Diagnose vor dem Rollback:\n' >&2
    systemctl show kienzlefon-t2med.service --no-pager \
        --property=ActiveState,SubState,Result,ExecMainCode,ExecMainStatus >&2 || true
    journalctl -u kienzlefon-t2med.service --since "$START_SINCE" -n 80 --no-pager -o cat >&2 || true
    fail "Dienst ist nach Installation nicht aktiv (Startfehler siehe oben)."
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --skip-samba) SKIP_SAMBA=1 ;;
        --reconfigure) RECONFIGURE=1 ;;
        --keep-config) KEEP_CONFIG=1 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; fail "unbekannte Option: $1" ;;
    esac
    shift
done

[ "$RECONFIGURE" -eq 0 ] || [ "$KEEP_CONFIG" -eq 0 ] || fail "--reconfigure und --keep-config können nicht kombiniert werden."

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SOURCE_ROOT=$(dirname "$SCRIPT_DIR")

if [ "$DRY_RUN" -eq 1 ]; then
    say "Dry-run: keine Fragen; keine Dateien, Pakete, Benutzer oder Dienste werden verändert."
    say "Plan: Plattform/PHP prüfen; Konfiguration validieren; Anwendung vollständig vorbereiten und austauschen."
    if [ "$RECONFIGURE" -eq 1 ]; then
        say "Plan: vorhandene Werte interaktiv neu konfigurieren; Secrets einzeln beibehalten oder ersetzen."
    elif [ "$KEEP_CONFIG" -eq 1 ]; then
        say "Plan: vorhandene Einstellungen ohne Nachfrage übernehmen; Legacy-JSON gegebenenfalls verlustfrei nach TOML migrieren."
    else
        say "Plan: bei vorhandener Installation im Terminal nach Änderungen fragen; ohne Terminal Werte übernehmen; Legacy-JSON migrieren."
        say "Plan: bei Erstinstallation die Konfiguration interaktiv anlegen."
    fi
    if [ "$SKIP_SAMBA" -eq 1 ]; then
        say "Plan: Samba auslassen; externes Share kienzlefon-GDT wird vorausgesetzt."
    else
        say "Plan: Samba-Kompatibilität und Kollisionen vorab prüfen, additives Include testen, dann reload."
    fi
    say "Plan: systemd-Unit härten, Dienst aktivieren und nur bekannte Installationsziele bei Fehlern zurückrollen."
    exit 0
fi

[ "$(uname -s)" = Linux ] || fail "Live-Installation wird nur unter Linux unterstützt (nutzen Sie --dry-run)."
[ "$(id -u)" -eq 0 ] || fail "Live-Installation muss als root ausgeführt werden."
[ -r /etc/os-release ] || fail "/etc/os-release fehlt."
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
    debian) [ "${VERSION_ID%%.*}" -ge 12 ] 2>/dev/null || fail "Debian 12 oder neuer ist erforderlich." ;;
    ubuntu) [ "${VERSION_ID%%.*}" -ge 24 ] 2>/dev/null || fail "Ubuntu 24.04 oder neuer ist erforderlich." ;;
    raspbian) [ "${VERSION_CODENAME:-}" = bookworm ] || [ "${VERSION_ID%%.*}" -ge 12 ] 2>/dev/null || fail "Raspberry Pi OS Bookworm oder neuer ist erforderlich." ;;
    *) fail "Nicht unterstütztes Linux: ${ID:-unbekannt}." ;;
esac

# Bootstrap only PHP tooling here; do not install or reconfigure Samba before its preflight.
if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1 || ! php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y --no-upgrade php-cli php-mbstring composer
fi
[ -x /usr/bin/php ] || fail "/usr/bin/php fehlt; die systemd-Unit verwendet diesen festen Pfad."
/usr/bin/php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || fail "PHP 8.2 oder neuer ist erforderlich."
/usr/bin/php -r 'foreach (["json", "openssl", "mbstring"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, $e." fehlt\n"); exit(1); }}' || fail "Erforderliche PHP-Erweiterungen fehlen."
[ -f "$SOURCE_ROOT/composer.json" ] || fail "composer.json fehlt im Quellpaket."
[ -f "$SOURCE_ROOT/composer.lock" ] || fail "committed composer.lock fehlt; Installation wird nicht reproduzierbar fortgesetzt."
[ -f "$SOURCE_ROOT/bin/server.php" ] || fail "bin/server.php fehlt im Quellpaket."
command -v systemctl >/dev/null 2>&1 || fail "systemd/systemctl fehlt."
systemctl is-active --quiet smbd.service && SMB_WAS_ACTIVE=1 || true
systemctl is-enabled --quiet smbd.service && SMB_WAS_ENABLED=1 || true

if [ -e "$CONFIG_DIR" ] || [ -L "$CONFIG_DIR" ]; then [ -d "$CONFIG_DIR" ] && [ ! -L "$CONFIG_DIR" ] || fail "$CONFIG_DIR ist kein echtes Verzeichnis."; fi
if [ -e "$CONFIG_FILE" ] || [ -L "$CONFIG_FILE" ]; then [ -f "$CONFIG_FILE" ] && [ ! -L "$CONFIG_FILE" ] || fail "$CONFIG_FILE ist keine reguläre Datei."; fi
if [ -e "$LEGACY_CONFIG_FILE" ] || [ -L "$LEGACY_CONFIG_FILE" ]; then [ -f "$LEGACY_CONFIG_FILE" ] && [ ! -L "$LEGACY_CONFIG_FILE" ] || fail "$LEGACY_CONFIG_FILE ist keine reguläre Datei."; fi
if [ -e "$UNIT_FILE" ]; then [ -f "$UNIT_FILE" ] && [ ! -L "$UNIT_FILE" ] || fail "$UNIT_FILE ist keine reguläre Datei."; fi
if [ "$SKIP_SAMBA" -eq 0 ] && [ -e "$SMB_INCLUDE" ]; then [ -f "$SMB_INCLUDE" ] && [ ! -L "$SMB_INCLUDE" ] || fail "$SMB_INCLUDE ist keine reguläre Datei."; fi

WORK_DIR=$(mktemp -d /tmp/kienzlefon-install.XXXXXX)
chmod 700 "$WORK_DIR"

rollback() {
    status=$?
    if [ "$SUCCESS" -eq 0 ] && [ "$MUTATING" -eq 1 ]; then
        printf 'Installation fehlgeschlagen; bekannte Systemdateien werden zurückgerollt.\n' >&2
        if [ "$APP_TOUCHED" -eq 1 ] && [ "$APP_EXISTED" -eq 1 ] && [ -n "$APP_BACKUP" ] && [ -d "$APP_BACKUP" ]; then
            rm -rf -- "$APP_DIR"
            mv -- "$APP_BACKUP" "$APP_DIR"
        elif [ "$APP_TOUCHED" -eq 1 ] && [ "$APP_EXISTED" -eq 0 ]; then
            rm -rf -- "$APP_DIR"
        fi
        if [ "$CONFIG_TOUCHED" -eq 1 ]; then
            if [ "$CONFIG_EXISTED" -eq 1 ]; then cp -p -- "$CONFIG_BACKUP" "$CONFIG_FILE"; else rm -f -- "$CONFIG_FILE"; fi
        fi
        if [ "$UNIT_TOUCHED" -eq 1 ]; then
            if [ "$UNIT_EXISTED" -eq 1 ]; then cp -p -- "$UNIT_BACKUP" "$UNIT_FILE"; else rm -f -- "$UNIT_FILE"; fi
        fi
        if [ "$SMB_TOUCHED" -eq 1 ]; then
            if [ "$SMB_MAIN_EXISTED" -eq 1 ]; then cp -p -- "$SMB_MAIN_BACKUP" "$SMB_MAIN"; else rm -f -- "$SMB_MAIN"; fi
            if [ "$SMB_INCLUDE_EXISTED" -eq 1 ]; then cp -p -- "$SMB_INCLUDE_BACKUP" "$SMB_INCLUDE"; else rm -f -- "$SMB_INCLUDE"; fi
        fi
        systemctl daemon-reload >/dev/null 2>&1 || true
        if [ "$SERVICE_TOUCHED" -eq 1 ]; then
            if [ "$SERVICE_WAS_ACTIVE" -eq 1 ]; then systemctl restart kienzlefon-t2med.service >/dev/null 2>&1 || true; else systemctl disable --now kienzlefon-t2med.service >/dev/null 2>&1 || true; fi
        fi
        if [ "$SMB_TOUCHED" -eq 1 ] && [ "$SMB_WAS_ACTIVE" -eq 1 ]; then systemctl reload smbd.service >/dev/null 2>&1 || smbcontrol all reload-config >/dev/null 2>&1 || true; fi
        if [ "$SMB_SERVICE_TOUCHED" -eq 1 ] && [ "$SMB_WAS_ACTIVE" -eq 0 ]; then
            systemctl stop smbd.service >/dev/null 2>&1 || true
            if [ "$SMB_WAS_ENABLED" -eq 0 ]; then systemctl disable smbd.service >/dev/null 2>&1 || true; fi
        fi
    fi
    [ -z "$WORK_DIR" ] || rm -rf -- "$WORK_DIR"
    exit "$status"
}
trap rollback EXIT HUP INT TERM

STAGE=$WORK_DIR/app-stage
mkdir "$STAGE"
for item in composer.json composer.lock config.example.json config.example.toml bin src public installer clients; do
    [ -e "$SOURCE_ROOT/$item" ] || fail "Anwendungsbestandteil fehlt: $item"
    cp -R -- "$SOURCE_ROOT/$item" "$STAGE/$item"
done
for item in assets templates; do
    [ ! -e "$SOURCE_ROOT/$item" ] || cp -R -- "$SOURCE_ROOT/$item" "$STAGE/$item"
done
(cd "$STAGE" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-plugins --no-scripts --prefer-dist --no-interaction --no-progress --classmap-authoritative)
run_configure() { /usr/bin/php "$STAGE/installer/configure.php" "$@"; }

PLANNED_CONFIG=$WORK_DIR/config.toml
EXISTING_CONFIG=$(select_existing_config)

WRITE_INTERACTIVE=0
if [ -n "$EXISTING_CONFIG" ]; then
    run_configure validate "$EXISTING_CONFIG"
    if [ "$RECONFIGURE" -eq 1 ]; then
        [ -t 0 ] || fail "--reconfigure benötigt ein interaktives Terminal."
        WRITE_INTERACTIVE=1
    elif [ "$KEEP_CONFIG" -eq 0 ] && [ -t 0 ]; then
        printf 'Vorhandene Einstellungen ändern (z. B. Nebenstellen/Arbeitsplätze)? [j/N]: ' >&2
        IFS= read -r CHANGE_CONFIG || fail "Eingabe wurde abgebrochen."
        case "$CHANGE_CONFIG" in j|J|ja|Ja|JA|y|Y|yes|Yes|YES) WRITE_INTERACTIVE=1 ;; esac
    fi
    if [ "$WRITE_INTERACTIVE" -eq 1 ]; then
        run_configure interactive "$PLANNED_CONFIG" "$EXISTING_CONFIG"
    elif [ "$EXISTING_CONFIG" = "$CONFIG_FILE" ]; then
        cp -p -- "$CONFIG_FILE" "$PLANNED_CONFIG"
        say "Vorhandene TOML-Konfiguration wird bytegleich beibehalten."
    else
        run_configure migrate "$LEGACY_CONFIG_FILE" "$PLANNED_CONFIG"
        say "Legacy-JSON wird nach TOML migriert; die JSON-Datei bleibt unverändert erhalten."
    fi
else
    [ "$RECONFIGURE" -eq 0 ] || fail "--reconfigure benötigt eine vorhandene Konfiguration."
    [ -t 0 ] || fail "Erstkonfiguration benötigt ein interaktives Terminal."
    run_configure interactive "$PLANNED_CONFIG"
fi
run_configure validate "$PLANNED_CONFIG"
# A running managed instance may own this port during an update. Check again
# after its normal update stop below; never stop a foreign listener for this test.
if ! systemctl is-active --quiet kienzlefon-t2med.service; then
    run_configure check-listen "$PLANNED_CONFIG"
fi
GDT_ROOT=$(run_configure paths "$PLANNED_CONFIG" | sed -n '1p')
[ -n "$GDT_ROOT" ] || fail "GDT-Root konnte nicht ermittelt werden."

if [ -e "$GDT_ROOT" ]; then
    [ ! -L "$GDT_ROOT" ] && [ -d "$GDT_ROOT" ] || fail "GDT-Root existiert, ist aber kein echtes Verzeichnis: $GDT_ROOT"
fi

SAMBA_FRESH=0
SAMBA_PLAN=$WORK_DIR/samba-plan
mkdir "$SAMBA_PLAN"
if [ "$SKIP_SAMBA" -eq 0 ]; then
    if [ -e "$SMB_MAIN" ]; then
        [ -f "$SMB_MAIN" ] && [ ! -L "$SMB_MAIN" ] || fail "Samba-Hauptkonfiguration ist keine reguläre Datei."
        command -v testparm >/dev/null 2>&1 || fail "Bestehendes Samba erkannt, aber testparm fehlt. Installieren Sie samba-common-bin oder nutzen Sie --skip-samba."
        testparm -s "$SMB_MAIN" >"$WORK_DIR/smb-effective.conf" 2>"$WORK_DIR/testparm.err" || {
            sed -n '1,12p' "$WORK_DIR/testparm.err" >&2
            fail "Bestehende Samba-Konfiguration ist ungültig."
        }
        MAP_TO_GUEST=$(testparm -s --parameter-name='map to guest' "$SMB_MAIN" 2>/dev/null || true)
        SERVER_ROLE=$(testparm -s --parameter-name='server role' "$SMB_MAIN" 2>/dev/null || true)
        /usr/bin/php "$STAGE/installer/samba.php" --main="$SMB_MAIN" --managed="$SMB_INCLUDE" --effective="$WORK_DIR/smb-effective.conf" --root="$GDT_ROOT" --include="$SMB_INCLUDE" --map="$MAP_TO_GUEST" --role="$SERVER_ROLE" --output="$SAMBA_PLAN"
    else
        SAMBA_FRESH=1
        printf '[global]\n' >"$WORK_DIR/empty-smb.conf"
        : >"$WORK_DIR/smb-effective.conf"
        /usr/bin/php "$STAGE/installer/samba.php" --main="$WORK_DIR/empty-smb.conf" --effective="$WORK_DIR/smb-effective.conf" --root="$GDT_ROOT" --include="$SMB_INCLUDE" --map='' --role='' --fresh --output="$SAMBA_PLAN"
    fi
else
    say "Samba wird ausgelassen. Verifizieren Sie extern Gast-Lese-, Schreib- und Löschzugriff auf kienzlefon-GDT."
fi

# All validation and collision checks above are read-only. Package and filesystem changes start here.
MUTATING=1
export DEBIAN_FRONTEND=noninteractive
if [ "$SKIP_SAMBA" -eq 0 ] && ! command -v smbd >/dev/null 2>&1; then
    apt-get update
    SMB_SERVICE_TOUCHED=1
    apt-get install -y --no-upgrade samba
fi
command -v composer >/dev/null 2>&1 || fail "Composer wurde nicht bereitgestellt."
if [ "$SKIP_SAMBA" -eq 0 ]; then command -v testparm >/dev/null 2>&1 || fail "testparm fehlt nach Samba-Installation."; fi

/usr/bin/php "$STAGE/bin/server.php" --config="$PLANNED_CONFIG" --check-config

if getent group "$SERVICE_USER" >/dev/null 2>&1; then
    GROUP_MEMBERS=$(getent group "$SERVICE_USER" | cut -d: -f4)
    [ -z "$GROUP_MEMBERS" ] || [ "$GROUP_MEMBERS" = "$SERVICE_USER" ] || fail "Dienstgruppe enthält fremde Benutzer; Zugangskonfiguration wird nicht freigegeben."
else
    groupadd --system "$SERVICE_USER"
fi
if id "$SERVICE_USER" >/dev/null 2>&1; then
    [ "$(id -u "$SERVICE_USER")" -ne 0 ] || fail "Dienstbenutzer darf nicht UID 0 haben."
    EXISTING_HOME=$(getent passwd "$SERVICE_USER" | cut -d: -f6)
    [ "$EXISTING_HOME" = /nonexistent ] || [ "$EXISTING_HOME" = /var/empty ] || fail "Bestehender Benutzer $SERVICE_USER hat unerwartetes Home: $EXISTING_HOME"
else
    useradd --system --gid "$SERVICE_USER" --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
fi

if [ -d "$GDT_ROOT" ]; then
    runuser -u "$SERVICE_USER" -- test -w "$GDT_ROOT" || fail "Vorhandener GDT-Root ist für $SERVICE_USER nicht schreibbar. Eigentum wird aus Sicherheitsgründen nicht rekursiv geändert."
else
    install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0770 "$GDT_ROOT"
fi
run_configure paths "$PLANNED_CONFIG" | sed -n '2,$p' | while IFS= read -r directory; do
    target=$GDT_ROOT/$directory
    if [ -e "$target" ]; then
        [ ! -L "$target" ] && [ -d "$target" ] || fail "Clientpfad ist kein echtes Verzeichnis: $target"
        runuser -u "$SERVICE_USER" -- test -w "$target" || fail "Vorhandener Clientpfad ist nicht dienstschreibbar: $target"
    else
        install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0770 "$target"
    fi
done

systemctl is-active --quiet kienzlefon-t2med.service && SERVICE_WAS_ACTIVE=1 || true
if [ -e "$APP_DIR" ]; then
    [ -d "$APP_DIR" ] && [ ! -L "$APP_DIR" ] || fail "$APP_DIR ist kein echtes Verzeichnis."
    APP_EXISTED=1; APP_BACKUP=$WORK_DIR/app.backup
    [ -f "$APP_DIR/.kienzlefon-installed" ] || fail "Zielverzeichnis ist keine verwaltete Installation: $APP_DIR"
    if [ "$SERVICE_WAS_ACTIVE" -eq 1 ]; then SERVICE_TOUCHED=1; systemctl stop kienzlefon-t2med.service; fi
fi
run_configure check-listen "$PLANNED_CONFIG"
if [ "$APP_EXISTED" -eq 1 ]; then
    mv -- "$APP_DIR" "$APP_BACKUP"
    APP_TOUCHED=1
fi
APP_TOUCHED=1
install -d -o root -g root -m 0755 "$APP_DIR"
cp -R -- "$STAGE/." "$APP_DIR/"
touch "$APP_DIR/.kienzlefon-installed"
chown -R root:root "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0755 {} \;
find "$APP_DIR" -type f -exec chmod 0644 {} \;

install -d -o root -g "$SERVICE_USER" -m 0750 "$CONFIG_DIR"
if [ -f "$CONFIG_FILE" ]; then CONFIG_EXISTED=1; CONFIG_BACKUP=$WORK_DIR/config.backup; cp -p -- "$CONFIG_FILE" "$CONFIG_BACKUP"; fi
CONFIG_TOUCHED=1
install -o root -g "$SERVICE_USER" -m 0640 "$PLANNED_CONFIG" "$CONFIG_FILE"
runuser -u "$SERVICE_USER" -- /usr/bin/php "$APP_DIR/bin/server.php" --config="$CONFIG_FILE" --check-config

if [ -f "$UNIT_FILE" ]; then UNIT_EXISTED=1; UNIT_BACKUP=$WORK_DIR/unit.backup; cp -p -- "$UNIT_FILE" "$UNIT_BACKUP"; fi
sed "s|@@GDT_ROOT@@|$GDT_ROOT|g" "$SCRIPT_DIR/kienzlefon-t2med.service" >"$WORK_DIR/service.rendered"
UNIT_TOUCHED=1
install -o root -g root -m 0644 "$WORK_DIR/service.rendered" "$UNIT_FILE"

if [ "$SKIP_SAMBA" -eq 0 ]; then
    install -d -o root -g root -m 0755 /etc/samba
    if [ -f "$SMB_MAIN" ]; then SMB_MAIN_EXISTED=1; SMB_MAIN_BACKUP=$WORK_DIR/smb-main.backup; cp -p -- "$SMB_MAIN" "$SMB_MAIN_BACKUP"; fi
    if [ -f "$SMB_INCLUDE" ]; then SMB_INCLUDE_EXISTED=1; SMB_INCLUDE_BACKUP=$WORK_DIR/smb-include.backup; cp -p -- "$SMB_INCLUDE" "$SMB_INCLUDE_BACKUP"; fi
    # Validate an equivalent temporary include before touching the live Samba files.
    sed "s|$SMB_INCLUDE|$SAMBA_PLAN/kienzlefon-t2med.conf|g" "$SAMBA_PLAN/smb.conf" >"$WORK_DIR/smb-candidate.conf"
    testparm -s "$WORK_DIR/smb-candidate.conf" >/dev/null
    SMB_TOUCHED=1
    install -o root -g root -m 0644 "$SAMBA_PLAN/kienzlefon-t2med.conf" "$SMB_INCLUDE"
    install -o root -g root -m 0644 "$SAMBA_PLAN/smb.conf" "$SMB_MAIN"
    testparm -s "$SMB_MAIN" >/dev/null
    SMB_SERVICE_TOUCHED=1
    if [ "$SMB_WAS_ACTIVE" -eq 1 ]; then
        systemctl reload smbd.service || smbcontrol all reload-config
    else
        systemctl enable --now smbd.service
    fi
fi

systemctl daemon-reload
SERVICE_TOUCHED=1
systemctl enable kienzlefon-t2med.service
start_middleware

SUCCESS=1
say "Installation abgeschlossen. Konfiguration: $CONFIG_FILE"
say "Dienststatus: systemctl status kienzlefon-t2med.service"
say "Logs: journalctl -u kienzlefon-t2med.service"
exit 0
