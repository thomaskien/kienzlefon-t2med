# Anrufe heute und gewählte Wege

Praxisweite Orientierung für das Team: Jeder externe Eingang zählt einmal, auch anonym oder vor Annahme abgebrochen. Interne und ausgehende Anrufe zählen nicht. Wege werden je Anruf und Weg einmal gezählt; ein Anruf kann mehrere Wege haben. Rufnummern, Patientendaten und Gesprächsinhalte werden nicht gespeichert.

Gemeinsame Anbindung einschließlich Live-Zahlen, App-Zugriff und Rufgruppen:
[Telefonievertrag v1](telefonie-v1/README.md). Der Tagesstatistikvertrag dieser
Datei bleibt erhalten.

## Einrichtung

Die lokale Vollinstallation mit Kienzlefon ab 2.6.10 und Middleware ab 1.5.5 richtet die Ereignisse und beide Statistik-Einstellungen ein. Für eine separat betriebene Middleware die Kennung der Fon-Anlage übernehmen. Eine Beispielkonfiguration folgt.

```toml
[call_statistics]
enabled = true
database_path = "/var/lib/kienzlefon-t2med/call-statistics.sqlite"
timezone = "Europe/Berlin"
pbx_id = "practice-1"
```

Die Anlagenkennung muss zu den Ereignissen passen und über Updates/Neustarts gleich bleiben. Alte Konfigurationen bleiben gültig und liefern `unavailable/not_configured`. `--check-config` prüft das Schema, nicht die Installation der Ereignisquelle.

Der Installer erhält die Einstellungen, richtet das Datenverzeichnis für den Dienstbenutzer ein und berücksichtigt einen abweichenden Pfad in der systemd-Schreibfreigabe. Nach manueller Aktivierung den Installer erneut mit `--keep-config` ausführen. PHP `pdo_sqlite` ist bereits Installationsvoraussetzung. Die SQLite-Datei benötigt einschließlich Journaldateien ein dienstschreibbares Verzeichnis; Standard: `/var/lib/kienzlefon-t2med`, Verzeichnis 0700, Datei 0600. Statistikdatei getrennt sichern; FHIR-Import und Software-Upgrade ersetzen sie nicht.

## API und Übergabe an die separat entwickelte App

`GET /api/v1/telephony` enthält zusätzlich `call_statistics.today`. Die vorhandenen Host-, Netzwerk- und Origin-Prüfungen bleiben bestehen; es gibt keine neue CORS-Freigabe. Eine direkte Browserabfrage von einem anderen Origin muss in der App-Architektur gesondert gelöst werden.

```json
{
  "date": "2026-11-17",
  "timezone": "Europe/Berlin",
  "period_start": "2026-11-17T00:00:00+01:00",
  "period_end": "2026-11-18T00:00:00+01:00",
  "incoming_calls": 47,
  "status": "partial",
  "as_of": "2026-11-17T15:30:00+01:00",
  "reason_code": "capture_gap",
  "routes": [{"id": "queue", "label": "Warteschlange", "incoming_calls": 23}],
  "unclassified_calls": 2
}
```

Das Beispiel kürzt `routes`; die API liefert alle unten aufgeführten Wege, einschließlich 0. `period_end` ist exklusiv. Eingänge und ihre späteren Wege bleiben dem ursprünglichen Eingangstag in Berlin zugeordnet; Sommer-/Winterzeit wird berücksichtigt. GETs verändern weder Zähler noch `as_of`.

| Status | Zahl | App-Anzeige |
| --- | --- | --- |
| `complete`, Grund `null` | bekannte Ganzzahl | `Anrufe heute: 47` |
| `partial`, `initial_partial_day` oder `capture_gap` | bekannte Mindestzahl | `Anrufe heute: ≈47`; Tooltip: „Ungefährer Wert; Erfassung nicht durchgehend.“ |
| `unavailable`, `not_configured` oder `storage_unavailable` | `null` | `Anrufe heute: —` |

Im Tooltip die Wege als `label: incoming_calls` anzeigen, ergänzt um „Ohne erfassten Weg: N“ und „Mehrere Wege pro Anruf möglich“. Letzteres erklärt, warum Wegesummen größer als der Tageszähler sein können. „Ohne erfassten Weg“ bedeutet **nicht** automatisch aufgelegt. Bei `unavailable` sind auch `routes`, `unclassified_calls` und `as_of` `null`; eine ältere API ohne Statistik ebenfalls als nicht verfügbar behandeln. Bei fehlgeschlagener Abfrage einen alten Wert höchstens als veraltet anzeigen. Die App zählt weder aus JSON-Vorgängen noch Browseraktionen selbst.

## Telefonie-Folgeauftrag: eindeutige Marker senden

Vorlage: [asterisk-call-statistics.conf](../examples/asterisk-call-statistics.conf). Sie wird nicht automatisch installiert oder in laufende Anrufe eingebaut. `UUID()` ([Asterisk-Dokumentation](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Functions/UUID/)) und `UserEvent` sowie AMI-Leserecht `user` müssen verfügbar sein. `CoreShowChannels` und die Namensabfrage sind keine Zählquelle; letztere überspringt unter anderem anonyme Anrufer.

1. In `kienzlefon` die tatsächlichen externen Telefoneingänge markieren: Hauptanschluss/Überlauf in der eingebundenen `extensions-kfx-telefonie.conf` sowie `kienzlefon-direct-queue-in` und `kienzlefon-direct-red-in`. Den Entry-Hook **vor** Kapazitätsprüfung, Annahme und Namensabfrage aufrufen. Interne Ziele `8800…`, Click-to-Dial, Faxwege und Vermittlung dürfen ihn nicht erstmals aufrufen. Die endgültigen Trunk-Kontexte auf der Anlage prüfen.
2. Beim Eingang eine zufällige UUID v4 erzeugen, zusammen mit Anlagenkennung und ursprünglicher Unixzeit über `__KF_STATS_*` an Folgekanäle vererben. Nicht `Linkedid`, Telefonnummer oder Anzeige-ID verwenden. Ein erneuter externer Eingang erhält eine neue UUID; Wiederholung desselben Hooks behält die vorhandene UUID.
3. Route-Hook an den tatsächlich gewählten/erreichten Wegen auslösen; in `ivr.py` beispielsweise `channel.exec("Gosub", "kienzlefon-statistics-route,s,1(prescription)")`. Er darf keine neue Eingangskennung erzeugen. Automatischen AI-Einstieg und Warteschlangen-Fallback ebenfalls markieren.

| Route-ID | Bedeutung / Stelle |
| --- | --- |
| `ivr` | Klassisches Sprachmenü erreicht |
| `ai` | AI-Verbindung erreicht, nicht nur ein abgewiesener AI-Versuch |
| `queue` | Warteschlange betreten, auch Direktwahl/Fallback |
| `prescription`, `referral`, `appointment` | Menüauswahl Rezept, Überweisung, Termin |
| `callback`, `other` | Untermenü Rückrufwunsch, Sonstiges |
| `opening_hours` | Öffnungszeiten gewählt |
| `pharmacy`, `specialist` | Apotheken- bzw. Fachstellenweg gewählt |

AMI-Vertrag: `Event: UserEvent`, `UserEvent: KienzlefonCallEntered` bzw. `KienzlefonCallRoute`, `SchemaVersion: 1`, `PbxId: practice-1`, `CallId: <UUID v4>`, `EnteredAt: <ursprüngliche Unixsekunden>`; beim Wegeereignis zusätzlich `Route: <ID>`. Ein gültiges Wegeereignis kann einen verpassten Eingang mit dessen Originalzeit ergänzen. Andere Anlagen, unbekannte Wege, ungültige UUIDs oder widersprüchliche Eingangszeiten werden nicht als neue Eingänge übernommen.

## Ausfälle und Aufbewahrung

Erster Start mitten am Tag und AMI-/Prozesslücken führen zu `partial`. Erfolgreiche Reconnects und Snapshots löschen diese Kennzeichnung nicht. Bereits gespeicherte Werte bleiben erhalten; eine spätere lückenlos erfasste Tagesperiode kann wieder `complete` sein. `as_of` wird durch erfolgreiche AMI-Erfassung/Pings fortgeschrieben und während einer Trennung eingefroren. Demo verwendet ausschließlich synthetische Werte.

Es gibt bewusst keine Journal-Nachberechnung für Ausfälle. Zahlen sind Team-Orientierung und keine Abrechnungsgrundlage. Technische Zählschlüssel, Wege und Erfassungsintervalle werden zunächst ohne automatische Löschung aufbewahrt, damit alte Wiederholungen nicht neu zählen. Nicht im laufenden Betrieb einzelne Tabellen leeren; eine spätere Aufbewahrungsgrenze muss Wiederholungszeitraum und aktive Anrufe berücksichtigen.
