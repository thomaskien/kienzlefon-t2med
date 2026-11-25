"""Read a Kienzlefon v1 handoff before the installer changes the system."""
import ipaddress
import json
import os
import re
import stat
import sys

try:
    import tomllib
except ImportError:
    sys.exit("AMI-Import benötigt Python 3.11 oder neuer (tomllib).")


class HandoffError(ValueError):
    """Only these fixed diagnostics may be shown for a file containing secrets."""


def read_connection(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as source:
        mode = os.fstat(fd).st_mode
        if not stat.S_ISREG(mode) or mode & 0o077:
            raise HandoffError("Eine reguläre, geschützte Datei ist erforderlich (z. B. Modus 0600).")
        try:
            data = tomllib.load(source)
        except (ValueError, RecursionError, OverflowError):
            raise HandoffError("Ungültiges TOML in der Übergabedatei.") from None

    if type(data.get("schema_version")) is not int or data["schema_version"] != 1:
        raise HandoffError("Die Übergabedatei benötigt schema_version = 1.")
    if data.get("enabled") is not True:
        raise HandoffError("Der exportierte AMI-Zugang muss aktiviert sein (enabled = true).")
    ami = data.get("ami")
    if not isinstance(ami, dict):
        raise HandoffError("Der Abschnitt [ami] fehlt.")
    host = ami.get("host")
    try:
        if not isinstance(host, str) or "%" in host:
            raise ValueError
        address = ipaddress.ip_address(host)
        if address.is_unspecified or address.is_multicast or host == "255.255.255.255":
            raise ValueError
    except ValueError:
        raise HandoffError("ami.host muss eine konkrete IPv4- oder IPv6-Zieladresse sein.") from None
    port = ami.get("port")
    if type(port) is not int or not 1 <= port <= 65535:
        raise HandoffError("ami.port muss eine ganze Zahl von 1 bis 65535 sein.")
    transport = ami.get("transport")
    if transport not in ("tcp", "tls"):
        raise HandoffError("ami.transport muss tcp oder tls sein.")
    username = ami.get("username")
    if (not isinstance(username, str) or username.lower() == "general"
            or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,63}", username)):
        raise HandoffError("Ungültiger AMI-Benutzer in ami.username.")
    secret = ami.get("secret")
    if not isinstance(secret, str) or not re.fullmatch(r"[A-Za-z0-9_-]{32,128}", secret):
        raise HandoffError("ami.secret entspricht nicht dem Kienzlefon-Exportformat.")
    if ami.get("events") != "on":
        raise HandoffError('Die Middleware benötigt ami.events = "on".')
    # The installer uses these numbers as workplace IDs on first install.
    # Routing permissions remain separate from the workstation list.
    extensions = []
    for name in ("click_to_dial", "transfer"):
        section = data.get(name, {})
        if not isinstance(section, dict):
            raise HandoffError(f"{name} muss ein TOML-Abschnitt sein.")
        numbers = section.get("extensions", [])
        if not isinstance(numbers, list) or not all(
            isinstance(number, str) and re.fullmatch(r"[0-9]{1,12}", number) for number in numbers
        ):
            raise HandoffError(f"{name}.extensions muss eine Liste numerischer Nebenstellen als Text enthalten.")
        extensions.extend(numbers)
    return {
        "ami": {"host": host, "port": port, "username": username, "secret": secret, "tls": transport == "tls"},
        "extensions": list(dict.fromkeys(extensions)),
    }


if __name__ == "__main__":
    try:
        connection = read_connection(sys.argv[1])
        # Dry run uses only the input path. Live installs take a private snapshot
        # so a concurrent rotation cannot change the values after preflight.
        if len(sys.argv) == 3:
            fd = os.open(sys.argv[2], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as target:
                json.dump(connection, target)
    except HandoffError as error:
        sys.exit(f"AMI-Import: {error}")
    except OSError:
        sys.exit("AMI-Import: Übergabedatei nicht lesbar oder private Arbeitskopie nicht schreibbar.")
