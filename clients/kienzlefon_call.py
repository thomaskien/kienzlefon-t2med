#!/usr/bin/env python3
"""Safe per-user tel:/callto: handler for Kienzlefon T2med."""

from __future__ import annotations

import argparse
import json
import os
import re
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Any

DEFAULT_TIMEOUT = 4.0
MAX_RESPONSE = 1024 * 1024


class ClientError(Exception):
    pass


def normalize_number(value: str) -> str:
    if not isinstance(value, str) or not value:
        raise ClientError("Keine Rufnummer angegeben.")
    if "\r" in value or "\n" in value:
        raise ClientError("Steuerzeichen in der Rufnummer sind nicht erlaubt.")
    raw = value
    lowered = raw.lower()
    if lowered.startswith("tel:") or lowered.startswith("callto:"):
        raw = raw.split(":", 1)[1]
    elif ":" in raw:
        raise ClientError("Nur tel: und callto: werden unterstützt.")
    try:
        decoded = urllib.parse.unquote(raw, errors="strict")
    except (UnicodeDecodeError, ValueError) as exc:
        raise ClientError("Ungültige URL-Kodierung in der Rufnummer.") from exc
    if any(char in decoded for char in ";?&#\r\n"):
        raise ClientError("Parameter, Fragmente und Steuerzeichen sind in Rufnummern nicht erlaubt.")
    if decoded.startswith("//"):
        raise ClientError("Ungültige Rufnummer.")
    compact = re.sub(r"[\s()./\-]", "", decoded)
    if not re.fullmatch(r"\+?[0-9]+", compact):
        raise ClientError("Die Rufnummer enthält unzulässige Zeichen.")
    digits = compact[1:] if compact.startswith("+") else compact
    if not 3 <= len(digits) <= 20:
        raise ClientError("Die Rufnummer muss zwischen 3 und 20 Ziffern enthalten.")
    return compact


def validate_registration(public_url: str, client_id: str, extension: str) -> dict[str, str]:
    if not isinstance(public_url, str) or not public_url or re.search(r"[\x00-\x20\x7f]", public_url):
        raise ClientError("Ungültige Middleware-URL.")
    try:
        parts = urllib.parse.urlsplit(public_url)
        parsed_port = parts.port
    except ValueError as exc:
        raise ClientError("Ungültige Middleware-URL.") from exc
    if (
        parts.scheme not in {"http", "https"}
        or not parts.hostname
        or parts.username is not None
        or parts.password is not None
        or parts.query
        or parts.fragment
        or parts.path not in {"", "/"}
    ):
        raise ClientError("Middleware-URL muss HTTP(S) ohne Zugangsdaten, Pfad, Query oder Fragment sein.")
    if parsed_port is not None and not 1 <= parsed_port <= 65535:
        raise ClientError("Ungültiger Middleware-Port.")
    if not isinstance(client_id, str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,63}", client_id):
        raise ClientError("Ungültige Client-ID.")
    if not isinstance(extension, str) or not re.fullmatch(r"[0-9]{1,12}", extension):
        raise ClientError("Ungültige Nebenstelle.")
    return {"url": public_url.rstrip("/"), "client_id": client_id, "extension": extension}


def load_config(path: Path) -> dict[str, str]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise ClientError(f"Client-Konfiguration fehlt: {path}") from exc
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ClientError(f"Client-Konfiguration ist nicht lesbar: {exc}") from exc
    if not isinstance(data, dict):
        raise ClientError("Client-Konfiguration muss ein JSON-Objekt sein.")
    return validate_registration(data.get("url"), data.get("client_id"), data.get("extension"))


def write_config(path: Path, public_url: str, client_id: str, extension: str) -> None:
    config = validate_registration(public_url, client_id, extension)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ClientError(f"Unsicheres Konfigurationsziel: {path}")
    temporary = path.with_name(path.name + ".tmp")
    flags = os.O_WRONLY | os.O_CREAT | os.O_TRUNC
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    fd = os.open(temporary, flags, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(config, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        os.chmod(path, stat.S_IRUSR | stat.S_IWUSR)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req: urllib.request.Request, fp: Any, code: int, msg: str, headers: Any, newurl: str) -> None:
        return None


def request_json(opener: Any, request: urllib.request.Request, timeout: float) -> tuple[int, Any]:
    try:
        with opener.open(request, timeout=timeout) as response:
            status = response.getcode()
            content_type = response.headers.get_content_type()
            body = response.read(MAX_RESPONSE + 1)
    except urllib.error.HTTPError as exc:
        detail = exc.read(4096).decode("utf-8", "replace").strip()
        raise ClientError(f"Serverfehler HTTP {exc.code}: {detail or exc.reason}") from exc
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise ClientError(f"Middleware nicht erreichbar: {exc}") from exc
    if not 200 <= status < 300:
        raise ClientError(f"Unerwarteter HTTP-Status {status}.")
    if content_type != "application/json":
        raise ClientError("Middleware antwortete nicht mit JSON.")
    if len(body) > MAX_RESPONSE:
        raise ClientError("Middleware-Antwort ist zu groß.")
    try:
        return status, json.loads(body.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise ClientError("Middleware lieferte ungültiges JSON.") from exc


def verify_bootstrap(config: dict[str, str], opener: Any, timeout: float) -> str:
    request = urllib.request.Request(config["url"] + "/api/v1/bootstrap", method="GET", headers={"Accept": "application/json"})
    _, body = request_json(opener, request, timeout)
    if not isinstance(body, dict) or not isinstance(body.get("token"), str) or not body["token"]:
        raise ClientError("Bootstrap-Antwort enthält kein gültiges Token.")
    clients = body.get("clients")
    if not isinstance(clients, list):
        raise ClientError("Bootstrap-Antwort enthält keine Clientliste.")
    matching = [item for item in clients if isinstance(item, dict) and item.get("id") == config["client_id"]]
    if len(matching) != 1 or str(matching[0].get("extension", "")) != config["extension"]:
        raise ClientError("Client-ID und Nebenstelle stimmen nicht mit der Serverkonfiguration überein.")
    return body["token"]


def submit_call(config: dict[str, str], number: str, opener: Any | None = None, timeout: float = DEFAULT_TIMEOUT, request_id: str | None = None) -> dict[str, Any]:
    opener = opener or urllib.request.build_opener(NoRedirect())
    token = verify_bootstrap(config, opener, timeout)
    payload = {
        "client_id": config["client_id"],
        "extension": config["extension"],
        "number": number,
        "request_id": request_id or str(uuid.uuid4()),
    }
    encoded = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    request = urllib.request.Request(
        config["url"] + "/api/v1/calls",
        data=encoded,
        method="POST",
        headers={"Accept": "application/json", "Content-Type": "application/json", "X-Kienzlefon-Token": token},
    )
    status, body = request_json(opener, request, timeout)
    if status != 202:
        raise ClientError(f"Anrufauftrag wurde nicht angenommen (HTTP {status}, erwartet 202).")
    if not isinstance(body, dict):
        raise ClientError("Annahmeantwort ist kein JSON-Objekt.")
    return {"payload": payload, "response": body}


def confirm_call(number: str) -> None:
    if sys.platform == "win32":
        import ctypes

        choice = ctypes.windll.user32.MessageBoxW(
            None,
            f"Rufnummer {number} wirklich über die Nebenstelle wählen?",
            "Kienzlefon T2med",
            0x00000004 | 0x00000020 | 0x00000100,
        )
        if choice != 6:  # IDYES
            raise ClientError("Wählen wurde nicht bestätigt.")
        return
    if not sys.stdin.isatty():
        raise ClientError("Wählen abgebrochen: Es fehlt eine bestätigte Benutzeraktion.")
    try:
        answer = input(f"Rufnummer {number} wirklich über die Nebenstelle wählen? [j/N] ").strip().lower()
    except (EOFError, KeyboardInterrupt) as exc:
        raise ClientError("Wählen wurde nicht bestätigt.") from exc
    if answer not in {"j", "ja", "y", "yes"}:
        raise ClientError("Wählen wurde nicht bestätigt.")


def show_result(message: str) -> None:
    print(message)
    if sys.platform == "win32":
        import ctypes

        ctypes.windll.user32.MessageBoxW(None, message, "Kienzlefon T2med", 0x00000040)


def default_config_path() -> Path:
    if sys.platform == "win32":
        base = os.environ.get("APPDATA")
        if not base:
            raise ClientError("APPDATA ist nicht gesetzt.")
        return Path(base) / "KienzlefonT2med" / "client.json"
    if sys.platform == "darwin":
        return Path.home() / "Library" / "Application Support" / "KienzlefonT2med" / "client.json"
    return Path(os.environ.get("XDG_CONFIG_HOME", Path.home() / ".config")) / "kienzlefon-t2med" / "client.json"


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sicherer tel:/callto:-Handler für Kienzlefon T2med")
    parser.add_argument("uri", nargs="?", help="tel:/callto:-URI oder Rufnummer")
    parser.add_argument("--number", help="Rufnummer statt URI")
    parser.add_argument("--config", type=Path, default=None)
    parser.add_argument("--timeout", type=float, default=DEFAULT_TIMEOUT)
    parser.add_argument("--dry-run", action="store_true", help="validiert lokal und sendet keinen HTTP-Auftrag")
    parser.add_argument("--confirmed", action="store_true", help=argparse.SUPPRESS)
    parser.add_argument("--write-config", action="store_true")
    parser.add_argument("--url")
    parser.add_argument("--client-id")
    parser.add_argument("--extension")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    try:
        config_path = args.config or default_config_path()
        if args.write_config:
            if not all(isinstance(value, str) for value in (args.url, args.client_id, args.extension)):
                raise ClientError("--write-config benötigt --url, --client-id und --extension.")
            write_config(config_path, args.url, args.client_id, args.extension)
            print(f"Konfiguration gespeichert: {config_path}")
            return 0
        config = load_config(config_path)
        source = args.number if args.number is not None else args.uri
        if source is None:
            raise ClientError("Eine tel:/callto:-URI oder --number ist erforderlich.")
        number = normalize_number(source)
        payload = {
            "client_id": config["client_id"],
            "extension": config["extension"],
            "number": number,
            "request_id": str(uuid.uuid4()),
        }
        if args.dry_run:
            print("Dry-run: kein HTTP-Auftrag und kein Telefonat; Serverzuordnung wurde nicht online geprüft.")
            print(json.dumps(payload, ensure_ascii=False, sort_keys=True))
            return 0
        if not args.confirmed:
            confirm_call(number)
        result = submit_call(config, number, timeout=args.timeout, request_id=payload["request_id"])
        response = result["response"]
        server_id = response.get("request_id") or response.get("id") or payload["request_id"]
        if response.get("phase") == "unknown":
            show_result("Auftragsergebnis ungewiss. Bitte zuerst das Telefon prüfen; der Auftrag wird nicht automatisch wiederholt.")
        elif response.get("phase") == "demo":
            show_result("Demo: Kein Telefonat gestartet.")
        else:
            show_result(f"Rückruf angefordert: Zuerst klingelt Ihre Nebenstelle {config['extension']}. Nach dem Abheben wird {number} gewählt.")
        return 0
    except ClientError as exc:
        print(f"Kienzlefon-Fehler: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
