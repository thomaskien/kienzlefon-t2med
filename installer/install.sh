#!/bin/sh
# T2med-Middleware aus dem lokalen Paket installieren.
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
AMI_CONNECTION_FILE=
AMI_SNAPSHOT=
WORK_DIR=
MUTATING=0
SUCCESS=0
APP_SOURCE_IP=
SERVICE_TOKEN=
LINK_LOCAL_APP=auto
FULL_LOCAL=0
FON_PYTHON=/opt/kienzlefon/venv/bin/python
fon_counters() { "$FON_PYTHON" -Im kienzlefon.t2med_counters "$@"; }
PHONE_CONFIG_DIR=/etc/kienzlefon-app
PHONE_CONFIG=$PHONE_CONFIG_DIR/telephony-config.php
WEB_PROXY=/var/www/html/telepraxis-telephony.php
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
  --ami-connection-file DATEI
                  übernimmt Host, Port, Benutzer, Secret und Transport aus einem
                  geschützten Kienzlefon-TOML-Export (Python 3.11+ erforderlich)
  --skip-samba    richtet Samba nicht ein (Share muss extern bereitstehen)
  --with-app-telephony  Fon-Zähler, Tagesstatistik und App lokal verbinden, ab Fon 2.6.11 auch Rufgruppen
  --app-source-ip IP    zusätzlich diesen externen App-Server zum Dienstzugang zulassen
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

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SOURCE_ROOT=$(dirname "$SCRIPT_DIR")
INSTALL_VERSION=$(sed -n "s/^[[:space:]]*public const CURRENT = '\([^']*\)';[[:space:]]*$/\1/p" "$SOURCE_ROOT/src/Version.php")
[ -n "$INSTALL_VERSION" ] || fail "Programmversion aus src/Version.php konnte nicht gelesen werden."
say "Kienzlefon-T2med Installer – Version $INSTALL_VERSION"

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --skip-samba) SKIP_SAMBA=1 ;;
        --reconfigure) RECONFIGURE=1 ;;
        --keep-config) KEEP_CONFIG=1 ;;
        --with-app-telephony) LINK_LOCAL_APP=yes; FULL_LOCAL=1 ;;
        --app-source-ip)
            shift
            [ -n "${1:-}" ] || fail "--app-source-ip benötigt eine IP-Adresse."
            APP_SOURCE_IP=$1 ;;
        --ami-connection-file)
            [ -z "$AMI_CONNECTION_FILE" ] || fail "--ami-connection-file mehrfach angegeben."
            shift
            case "${1:-}" in ''|--*) fail "--ami-connection-file benötigt einen Dateipfad." ;; esac
            AMI_CONNECTION_FILE=$1 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; fail "unbekannte Option: $1" ;;
    esac
    shift
done

[ "$RECONFIGURE" -eq 0 ] || [ "$KEEP_CONFIG" -eq 0 ] || fail "--reconfigure und --keep-config können nicht kombiniert werden."
[ -z "$AMI_CONNECTION_FILE" ] || [ "$KEEP_CONFIG" -eq 0 ] || fail "--ami-connection-file und --keep-config können nicht kombiniert werden."
. "$SCRIPT_DIR/package-files.sh"

read_ami_connection() {
    command -v python3 >/dev/null 2>&1 || fail "Für den AMI-Import ist Python 3.11 oder neuer erforderlich."
    python3 -IB "$SCRIPT_DIR/ami_connection.py" "$AMI_CONNECTION_FILE" "$@"
}

if [ "$DRY_RUN" -eq 1 ]; then
    stage_package_files "$SOURCE_ROOT" ""
    if [ -n "$AMI_CONNECTION_FILE" ]; then
        read_ami_connection
        say "AMI-Übergabe geprüft. Plan: exportierte AMI-Verbindungswerte übernehmen."
    fi
    say "Dry-run: keine Fragen; keine Dateien, Pakete, Benutzer oder Dienste werden verändert."
    say "Plan: Plattform/PHP prüfen; Konfiguration validieren; Anwendung vollständig vorbereiten und austauschen."
    if [ "$RECONFIGURE" -eq 1 ]; then
        say "Plan: vorhandene Einstellungen interaktiv bearbeiten. Importierte AMI-Werte werden direkt übernommen."
    elif [ "$KEEP_CONFIG" -eq 1 ]; then
        say "Plan: vorhandene Einstellungen ohne Nachfrage übernehmen; Legacy-JSON gegebenenfalls verlustfrei nach TOML migrieren."
    elif [ -n "$AMI_CONNECTION_FILE" ]; then
        say "Plan: Beim Update nur die AMI-Verbindung ersetzen. Bei Erstinstallation übrige Einstellungen abfragen."
    else
        say "Plan: bei vorhandener Installation im Terminal nach Änderungen fragen; ohne Terminal Werte übernehmen; Legacy-JSON migrieren."
        say "Plan: bei Erstinstallation die Konfiguration interaktiv anlegen."
    fi
    if [ "$SKIP_SAMBA" -eq 1 ]; then
        say "Plan: Samba auslassen; externes Share kienzlefon-GDT wird vorausgesetzt."
    else
        say "Plan: Samba-Kompatibilität und Kollisionen vorab prüfen, additives Include testen, dann reload."
    fi
    say "Plan: lokalen App-Dienstzugang vorbereiten und eine installierte Praxis-App auf Nebenstellen prüfen."
    say "Plan: systemd-Unit härten, Dienst aktivieren und nur bekannte Installationsziele bei Fehlern zurückrollen."
    if [ "$FULL_LOCAL" -eq 1 ]; then
        say "Plan: Fon-Control und Tagesstatistik lokal verbinden, geladene Dialplan-Ereignisse und vier Zähler über den App-PHP-Proxy prüfen."
        say "Plan: Bei Fon-Kopplung Version 1 einen getrennten Rufgruppenschlüssel einrichten und vorhandene Arbeitsplatzbeschränkungen erhalten."
    fi
    say "Plan: aktivierte Anrufstatistik mit privatem Datenverzeichnis erhalten; SQLite-Datei nicht ersetzen."
    exit 0
fi

WORK_DIR=$(mktemp -d /tmp/kienzlefon-install.XXXXXX)
chmod 700 "$WORK_DIR"

rollback() {
    status=$?
    if [ "$SUCCESS" -eq 0 ] && [ "$MUTATING" -eq 1 ]; then
        printf 'Installation fehlgeschlagen; bekannte Systemdateien werden zurückgerollt.\n' >&2
        if [ -f "$WORK_DIR/fon-applying" ]; then
            fon_counters rollback --work "${FON_WORK:-$WORK_DIR}" || printf 'Fon-Rollback prüfen.\n' >&2
        fi
        for entry in weblookup webworkplace servicelookup serviceworkplace webtoken webconfig servicetoken controltoken routingtoken; do
            if [ -f "$WORK_DIR/$entry.target" ]; then
                target=$(cat "$WORK_DIR/$entry.target")
                if [ -f "$WORK_DIR/$entry.old" ]; then cp -p -- "$WORK_DIR/$entry.old" "$target"; else rm -f -- "$target"; fi
            fi
        done
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

if [ -n "$AMI_CONNECTION_FILE" ]; then
    AMI_SNAPSHOT=$WORK_DIR/imported-ami.json
    read_ami_connection "$AMI_SNAPSHOT"
fi

[ "$(uname -s)" = Linux ] || fail "Live-Installation wird nur unter Linux unterstützt (nutzen Sie --dry-run)."
[ "$(id -u)" -eq 0 ] || fail "Live-Installation muss als root ausgeführt werden."
exec 8>>/run/kienzlefon-app-telephony.lock
flock -n 8 || fail "Eine Telefonie-Einrichtung läuft bereits."
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
if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1 || ! php -r 'exit(extension_loaded("mbstring") && extension_loaded("pdo_sqlite") ? 0 : 1);'; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y --no-upgrade php-cli php-mbstring php-sqlite3 composer
fi
[ -x /usr/bin/php ] || fail "/usr/bin/php fehlt; die systemd-Unit verwendet diesen festen Pfad."
/usr/bin/php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || fail "PHP 8.2 oder neuer ist erforderlich."
/usr/bin/php -r 'foreach (["json", "openssl", "mbstring", "pdo_sqlite"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, $e." fehlt\n"); exit(1); }}' || fail "Erforderliche PHP-Erweiterungen fehlen."
command -v systemctl >/dev/null 2>&1 || fail "systemd/systemctl fehlt."
systemctl is-active --quiet smbd.service && SMB_WAS_ACTIVE=1 || true
systemctl is-enabled --quiet smbd.service && SMB_WAS_ENABLED=1 || true

if [ -e "$CONFIG_DIR" ] || [ -L "$CONFIG_DIR" ]; then [ -d "$CONFIG_DIR" ] && [ ! -L "$CONFIG_DIR" ] || fail "$CONFIG_DIR ist kein echtes Verzeichnis."; fi
if [ -e "$CONFIG_FILE" ] || [ -L "$CONFIG_FILE" ]; then [ -f "$CONFIG_FILE" ] && [ ! -L "$CONFIG_FILE" ] || fail "$CONFIG_FILE ist keine reguläre Datei."; fi
if [ -e "$LEGACY_CONFIG_FILE" ] || [ -L "$LEGACY_CONFIG_FILE" ]; then [ -f "$LEGACY_CONFIG_FILE" ] && [ ! -L "$LEGACY_CONFIG_FILE" ] || fail "$LEGACY_CONFIG_FILE ist keine reguläre Datei."; fi
if [ -e "$UNIT_FILE" ]; then [ -f "$UNIT_FILE" ] && [ ! -L "$UNIT_FILE" ] || fail "$UNIT_FILE ist keine reguläre Datei."; fi
if [ "$SKIP_SAMBA" -eq 0 ] && [ -e "$SMB_INCLUDE" ]; then [ -f "$SMB_INCLUDE" ] && [ ! -L "$SMB_INCLUDE" ] || fail "$SMB_INCLUDE ist keine reguläre Datei."; fi

STAGE=$WORK_DIR/app-stage
mkdir "$STAGE"
stage_package_files "$SOURCE_ROOT" "$STAGE"
case "$(uname -m)" in
    x86_64|amd64) FHIR_ARCH=amd64 ;;
    aarch64|arm64) FHIR_ARCH=arm64 ;;
    *) fail "Keine FHIR-Import-Binary für diese Architektur vorhanden." ;;
esac
install -m 0755 "$STAGE/libexec/fhir-importer-linux-$FHIR_ARCH" "$STAGE/libexec/fhir-importer"
(cd "$STAGE" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-plugins --no-scripts --prefer-dist --no-interaction --no-progress --classmap-authoritative)
run_configure() { /usr/bin/php "$STAGE/installer/configure.php" "$@"; }

T2MED_REQUIRE_WORKPLACE=0
if [ "$LINK_LOCAL_APP" = yes ]; then T2MED_REQUIRE_WORKPLACE=1; fi
case "$LINK_LOCAL_APP" in
    auto)
        # Die lokale Verbindung auch für eine später installierte App vorbereiten.
        # Eine bewusst konfigurierte andere Quelle bleibt ausgewählt.
        if [ ! -e "$PHONE_CONFIG" ] || grep -Fqx '// Kienzlefon Installer: t2med' "$PHONE_CONFIG"; then
            LINK_LOCAL_APP=yes
        elif runuser -u www-data -- php -r '$v=require $argv[1]; exit(is_array($v) && ($v["enabled"]??false)===false ? 0 : 1);' "$PHONE_CONFIG" >/dev/null 2>&1; then
            LINK_LOCAL_APP=yes
        else
            LINK_LOCAL_APP=no
        fi ;;
esac
if [ "$LINK_LOCAL_APP" = yes ] && [ -f "$WEB_PROXY" ]; then T2MED_REQUIRE_WORKPLACE=1; fi
if [ "$FULL_LOCAL" -eq 1 ]; then
    [ -r "$WEB_PROXY" ] || fail "Der installierte App-PHP-Proxy fehlt: $WEB_PROXY"
    [ -x "$FON_PYTHON" ] && "$FON_PYTHON" -Im kienzlefon.t2med_counters --help >/dev/null 2>&1 ||
        fail "Für die lokale Einrichtung zuerst Kienzlefon 2.6.10 oder neuer mit --kombi --mit-t2med installieren."
    fon_counters describe > "$WORK_DIR/fon-before.json"
    if php -r '$v=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); exit(($v["routing_pairing_version"]??null) === 1 ? 0 : 1);' "$WORK_DIR/fon-before.json"; then
        T2MED_REQUIRE_WORKPLACE=fon
    fi
fi
export T2MED_REQUIRE_WORKPLACE

PLANNED_CONFIG=$WORK_DIR/config.toml
EXISTING_CONFIG=$(select_existing_config)

WRITE_INTERACTIVE=0
if [ -n "$EXISTING_CONFIG" ]; then
    run_configure validate "$EXISTING_CONFIG"
    if [ "$RECONFIGURE" -eq 1 ]; then
        [ -t 0 ] || fail "--reconfigure benötigt ein interaktives Terminal."
        WRITE_INTERACTIVE=1
    elif [ -z "$AMI_SNAPSHOT" ] && [ "$KEEP_CONFIG" -eq 0 ] && [ -t 0 ]; then
        printf 'Vorhandene Einstellungen ändern (z. B. Nebenstellen/Arbeitsplätze)? [j/N]: ' >&2
        IFS= read -r CHANGE_CONFIG || fail "Eingabe wurde abgebrochen."
        case "$CHANGE_CONFIG" in j|J|ja|Ja|JA|y|Y|yes|Yes|YES) WRITE_INTERACTIVE=1 ;; esac
    fi
    if [ "$WRITE_INTERACTIVE" -eq 1 ]; then
        run_configure interactive "$PLANNED_CONFIG" "$EXISTING_CONFIG" "$AMI_SNAPSHOT"
    elif [ -n "$AMI_SNAPSHOT" ]; then
        run_configure import-ami "$EXISTING_CONFIG" "$AMI_SNAPSHOT" "$PLANNED_CONFIG"
        say "AMI-Verbindung wird aus der Übergabedatei aktualisiert."
    elif [ "$EXISTING_CONFIG" = "$CONFIG_FILE" ]; then
        cp -p -- "$CONFIG_FILE" "$PLANNED_CONFIG"
        say "Vorhandene TOML-Konfiguration wird als Grundlage übernommen."
    else
        run_configure migrate "$LEGACY_CONFIG_FILE" "$PLANNED_CONFIG"
        say "Legacy-JSON wird nach TOML migriert; die JSON-Datei bleibt unverändert erhalten."
    fi
else
    [ "$RECONFIGURE" -eq 0 ] || fail "--reconfigure benötigt eine vorhandene Konfiguration."
    [ -t 0 ] || fail "Erstkonfiguration benötigt ein interaktives Terminal."
    run_configure interactive "$PLANNED_CONFIG" "" "$AMI_SNAPSHOT"
fi
run_configure validate "$PLANNED_CONFIG"
# A running managed instance may own this port during an update. Check again
# after its normal update stop below; never stop a foreign listener for this test.
if ! systemctl is-active --quiet kienzlefon-t2med.service; then
    run_configure check-listen "$PLANNED_CONFIG"
fi
if [ "$FULL_LOCAL" -eq 1 ] || [ "$WRITE_INTERACTIVE" -eq 1 ] || [ -z "$EXISTING_CONFIG" ] ||
    { [ "$KEEP_CONFIG" -eq 0 ] && [ -z "$AMI_SNAPSHOT" ]; }; then
    run_configure prepare-directory-host "$PLANNED_CONFIG"
fi
if [ "$FULL_LOCAL" -eq 1 ]; then
    fon_counters describe --middleware "$PLANNED_CONFIG" > "$WORK_DIR/fon-info.json"
    run_configure prepare-counters "$PLANNED_CONFIG" "$WORK_DIR" "$CONFIG_DIR/control.token"
    FON_WORK=$WORK_DIR
    if ! fon_counters plan --work "$WORK_DIR" 2>"$WORK_DIR/fon-plan.err"; then
        # With the old digest in both slots, Fon accepts only an already registered
        # external writer. Never apply this probe or promote an unverified key.
        [ -f "$WORK_DIR/check-legacy-writer" ] || { cat "$WORK_DIR/fon-plan.err" >&2; exit 1; }
        proof=$WORK_DIR/writer-proof
        mkdir -m 0700 "$proof"
        cp "$WORK_DIR/fon-info.json" "$proof/"
        cp -p "$WORK_DIR/control.sha256" "$proof/control.sha256"
        cp -p "$WORK_DIR/control.sha256" "$proof/routing.sha256"
        fon_counters plan --work "$proof" || { cat "$WORK_DIR/fon-plan.err" >&2; exit 1; }
        FON_WORK=$WORK_DIR/legacy-plan
        mkdir -m 0700 "$FON_WORK"
        cp "$WORK_DIR/fon-info.json" "$FON_WORK/"
        cp -p "$WORK_DIR/control.sha256" "$FON_WORK/routing.sha256"
        cp -p "$WORK_DIR/routing.sha256" "$FON_WORK/control.sha256"
        fon_counters plan --work "$FON_WORK" || { cat "$WORK_DIR/fon-plan.err" >&2; exit 1; }
        run_configure retain-routing-key "$PLANNED_CONFIG" "$CONFIG_DIR/counters.token"
    fi
fi
GDT_ROOT=$(run_configure paths "$PLANNED_CONFIG" | sed -n '1p')
[ -n "$GDT_ROOT" ] || fail "GDT-Root konnte nicht ermittelt werden."

if [ "$LINK_LOCAL_APP" = yes ] || [ -n "$APP_SOURCE_IP" ]; then
    if [ -e "$PHONE_CONFIG_DIR" ] || [ -L "$PHONE_CONFIG_DIR" ]; then
        [ -d "$PHONE_CONFIG_DIR" ] && [ ! -L "$PHONE_CONFIG_DIR" ] && [ "$(stat -c %u "$PHONE_CONFIG_DIR")" = 0 ] &&
            [ -z "$(find "$PHONE_CONFIG_DIR" -maxdepth 0 -perm /022)" ] || fail "Ungeschütztes App-Konfigurationsverzeichnis."
    fi
    app_policy=
    if [ "$LINK_LOCAL_APP" = yes ] && [ -f "$PHONE_CONFIG" ]; then
        app_policy=$WORK_DIR/app-policy.json
        # Read executable App configuration only under the App server's identity.
        runuser -u www-data -- php -r '
            $v = require $argv[1];
            if (!is_array($v)) exit(1);
            $policy = array_intersect_key($v, array_flip(["clients","workplace_mode","pbx_id"]));
            echo json_encode((object)$policy, JSON_THROW_ON_ERROR);
        ' "$PHONE_CONFIG" > "$app_policy" || fail "Vorhandene App-Arbeitsplatzfreigabe konnte nicht gelesen werden."
    fi
    SERVICE_TOKEN=$(run_configure prepare-app "$PLANNED_CONFIG" "$WORK_DIR/webconfig" "$APP_SOURCE_IP" "$LINK_LOCAL_APP" "$app_policy")
fi

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
        LC_ALL=C testparm -s "$SMB_MAIN" >"$WORK_DIR/smb-effective.conf" 2>"$WORK_DIR/testparm.err" || {
            sed -n '1,12p' "$WORK_DIR/testparm.err" >&2
            fail "Bestehende Samba-Konfiguration ist ungültig."
        }
        MAP_TO_GUEST=$(LC_ALL=C testparm -s --parameter-name='map to guest' "$SMB_MAIN" 2>/dev/null) || fail "testparm konnte 'map to guest' nicht ermitteln."
        SERVER_ROLE=$(LC_ALL=C testparm -s --parameter-name='server role' "$SMB_MAIN" 2>/dev/null) || fail "testparm konnte 'server role' nicht ermitteln."
        # The configured role can be "auto". The full dump reports the resolved role on stderr.
        REPORTED_ROLE=$(LC_ALL=C awk '
            sub(/^[[:blank:]]*Server role:[[:blank:]]*/, "") {
                sub(/[[:space:]]+$/, "")
                if (seen && $0 != role) { role = "widersprüchliche Rollenmeldungen"; exit }
                role = $0
                seen = 1
            }
            END { print role }
        ' "$WORK_DIR/testparm.err")
        /usr/bin/php "$STAGE/installer/samba.php" --main="$SMB_MAIN" --managed="$SMB_INCLUDE" --effective="$WORK_DIR/smb-effective.conf" --root="$GDT_ROOT" --include="$SMB_INCLUDE" --map="$MAP_TO_GUEST" --role="$SERVER_ROLE" --reported-role="$REPORTED_ROLE" --output="$SAMBA_PLAN"
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
STATISTICS_DIR=$(run_configure statistics-directory "$PLANNED_CONFIG")
if [ -n "$STATISTICS_DIR" ]; then
    if [ -e "$STATISTICS_DIR" ] || [ -L "$STATISTICS_DIR" ]; then
        [ -d "$STATISTICS_DIR" ] && [ ! -L "$STATISTICS_DIR" ] || fail "Statistikverzeichnis ist kein echtes Verzeichnis."
        runuser -u "$SERVICE_USER" -- test -w "$STATISTICS_DIR" || fail "Statistikverzeichnis ist für $SERVICE_USER nicht schreibbar."
    else
        install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0700 "$STATISTICS_DIR"
    fi
fi
CONTROL_DIR=$(run_configure control-directory "$PLANNED_CONFIG")
if [ -n "$CONTROL_DIR" ]; then
    if [ -e "$CONTROL_DIR" ] || [ -L "$CONTROL_DIR" ]; then
        [ -d "$CONTROL_DIR" ] && [ ! -L "$CONTROL_DIR" ] || fail "Auftragsverzeichnis ist kein echtes Verzeichnis."
        runuser -u "$SERVICE_USER" -- test -w "$CONTROL_DIR" || fail "Auftragsverzeichnis ist für $SERVICE_USER nicht schreibbar."
    else
        install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0700 "$CONTROL_DIR"
    fi
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
chmod 0755 "$APP_DIR/libexec/fhir-importer" "$APP_DIR/libexec/fhir-importer-linux-amd64" "$APP_DIR/libexec/fhir-importer-linux-arm64"

install -d -o root -g "$SERVICE_USER" -m 0750 "$CONFIG_DIR"
publish_phone_file() {
    entry=$1 source=$2 target=$3 owner=$4 mode=$5
    if [ -e "$target" ] || [ -L "$target" ]; then
        [ -f "$target" ] && [ ! -L "$target" ] || fail "Unerwarteter Dateityp: $target"
        cp -p -- "$target" "$WORK_DIR/$entry.old"
    fi
    tmpfile=$(mktemp "${target}.XXXXXX")
    if install -o "$owner" -m "$mode" "$source" "$tmpfile"; then
        printf '%s\n' "$target" > "$WORK_DIR/$entry.target"
        mv -fT -- "$tmpfile" "$target"
    else
        rm -f -- "$tmpfile"; return 1
    fi
}
if [ "${FULL_LOCAL:-0}" -eq 1 ]; then
    if [ -f "$WORK_DIR/control.token" ]; then
        [ ! -e "$CONFIG_DIR/control.token" ] && [ ! -L "$CONFIG_DIR/control.token" ] || fail "Control-Schlüssel wurde zwischenzeitlich angelegt."
        publish_phone_file controltoken "$WORK_DIR/control.token" "$CONFIG_DIR/control.token" "$SERVICE_USER" 0600
    fi
    if [ -f "$WORK_DIR/routing.token" ]; then
        target="$CONFIG_DIR/routing.token"
        if [ "$FON_WORK" != "$WORK_DIR" ]; then target="$CONFIG_DIR/counters.token"; fi
        [ ! -e "$target" ] && [ ! -L "$target" ] || fail "Dienstschlüssel wurde zwischenzeitlich angelegt."
        publish_phone_file routingtoken "$WORK_DIR/routing.token" "$target" "$SERVICE_USER" 0600
    fi
    touch "$WORK_DIR/fon-applying"
    fon_counters apply --work "$FON_WORK"
fi
if [ -n "${SERVICE_TOKEN:-}" ]; then
    if [ ! -e "$SERVICE_TOKEN" ] && [ ! -L "$SERVICE_TOKEN" ]; then
        [ "$SERVICE_TOKEN" = "$CONFIG_DIR/app.token" ] || fail "Konfigurierter App-Dienstschlüssel fehlt."
        (umask 077; openssl rand -hex 32 > "$WORK_DIR/new.token")
        publish_phone_file servicetoken "$WORK_DIR/new.token" "$SERVICE_TOKEN" "$SERVICE_USER" 0600
    fi
    for role in lookup workplace; do
        target="$SERVICE_TOKEN.$role"
        if [ ! -e "$target" ] && [ ! -L "$target" ]; then
            (umask 077; openssl rand -hex 32 > "$WORK_DIR/new.$role")
            publish_phone_file "service$role" "$WORK_DIR/new.$role" "$target" "$SERVICE_USER" 0600
        fi
        if [ "$LINK_LOCAL_APP" = yes ]; then
            [ -d "$PHONE_CONFIG_DIR" ] || install -d -o root -g root -m 0755 "$PHONE_CONFIG_DIR"
            (umask 077; runuser -u "$SERVICE_USER" -- php -r 'require $argv[1]."/vendor/autoload.php"; echo Kienzlefon\TelephonyContract::secret($argv[2]);' "$APP_DIR" "$target" > "$WORK_DIR/web.$role") || fail "Dienstzugang $role ungültig."
            publish_phone_file "web$role" "$WORK_DIR/web.$role" "$PHONE_CONFIG_DIR/t2med-token.$role" www-data 0600
        fi
    done
    if [ "$LINK_LOCAL_APP" = yes ]; then
        [ -d "$PHONE_CONFIG_DIR" ] || install -d -o root -g root -m 0755 "$PHONE_CONFIG_DIR"
        (umask 077; runuser -u "$SERVICE_USER" -- php -r 'require $argv[1]."/vendor/autoload.php"; try { echo Kienzlefon\TelephonyContract::secret($argv[2]); } catch (Throwable) { exit(1); }' "$APP_DIR" "$SERVICE_TOKEN" > "$WORK_DIR/webtoken") ||
            fail "App-Dienstschlüssel für $SERVICE_USER nicht lesbar, ungültig oder ohne Modus 0600."
        publish_phone_file webtoken "$WORK_DIR/webtoken" "$PHONE_CONFIG_DIR/t2med-token" www-data 0600
        publish_phone_file webconfig "$WORK_DIR/webconfig" "$PHONE_CONFIG" root 0644
    fi
fi
if [ -f "$CONFIG_FILE" ]; then CONFIG_EXISTED=1; CONFIG_BACKUP=$WORK_DIR/config.backup; cp -p -- "$CONFIG_FILE" "$CONFIG_BACKUP"; fi
CONFIG_TOUCHED=1
install -o root -g "$SERVICE_USER" -m 0640 "$PLANNED_CONFIG" "$CONFIG_FILE"
# Full validation needs the provisioned 0600 keys and the service identity.
runuser -u "$SERVICE_USER" -- /usr/bin/php "$APP_DIR/bin/server.php" --config="$CONFIG_FILE" --check-config
if [ "${FULL_LOCAL:-0}" -eq 0 ]; then
    runuser -u "$SERVICE_USER" -- /usr/bin/php "$APP_DIR/bin/check-telephony-control.php" --config="$CONFIG_FILE"
fi

if [ -f "$UNIT_FILE" ]; then UNIT_EXISTED=1; UNIT_BACKUP=$WORK_DIR/unit.backup; cp -p -- "$UNIT_FILE" "$UNIT_BACKUP"; fi
sed -e "s|@@GDT_ROOT@@|$GDT_ROOT|g" -e "s|@@STATISTICS_DIR@@|$STATISTICS_DIR|g" -e "s|@@CONTROL_DIR@@|$CONTROL_DIR|g" "$SCRIPT_DIR/kienzlefon-t2med.service" >"$WORK_DIR/service.rendered"
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

read_app_status() {
    runuser -u www-data -- php -r '$_GET["action"]="status"; $_SERVER["DOCUMENT_ROOT"]=dirname($argv[1]); require $argv[1];' "$WEB_PROXY" > "$WORK_DIR/app-answer.json"
}
if [ "$FULL_LOCAL" -eq 1 ]; then
    attempt=1
    while :; do
        if read_app_status && php "$APP_DIR/installer/check-app-counters.php" "$WORK_DIR/app-answer.json" 2>"$WORK_DIR/counter-error"; then break; fi
        if [ "$attempt" -ge 10 ]; then
            [ ! -f "$WORK_DIR/counter-error" ] || cat "$WORK_DIR/counter-error" >&2
            fail "Abschlussprüfung über den App-PHP-Proxy fehlgeschlagen."
        fi
        attempt=$((attempt + 1))
        sleep 2
    done
elif [ "$LINK_LOCAL_APP" = yes ] && [ -f "$WEB_PROXY" ]; then
    read_app_status
    php -r '$v=json_decode(file_get_contents($argv[1]),true); exit(($v["schema_version"]??0)===1 && ($v["source"]??"")==="live" && !empty($v["extensions"]) ? 0 : 1);' "$WORK_DIR/app-answer.json" ||
        fail "Die Praxis-App erhält keine Nebenstellen vom installierten Dienst."
    say "Nebenstellenübersicht der Praxis-App geprüft."
fi

SUCCESS=1
if [ -f "$WORK_DIR/webconfig.old" ] && grep -Fqx '// Kienzlefon Installer: asterisk' "$WORK_DIR/webconfig.old"; then
    systemctl disable --now kienzlefon-app-asterisk.service ||
        say "Nebenstellen kommen von T2med. Der frühere lokale Statusdienst konnte nicht beendet werden."
fi
say "Installation abgeschlossen. Konfiguration: $CONFIG_FILE"
say "Dienststatus: systemctl status kienzlefon-t2med.service"
say "Logs: journalctl -u kienzlefon-t2med.service"
exit 0
