# Anrufzahlen und Normal/ROT

Die Middleware setzt den [Telefonievertrag 1.0.2](telefonie-v1/README.md) um.
Anlagen-Control-Dienst, administrative Freigaben, Dialplan-Hooks und App-Proxy
werden in ihren jeweiligen Projekten entwickelt. Der Dienst auf Port 8767 muss
bereitstehen, bevor die neuen Funktionen aktiviert werden. AMI und das
Admininterface auf 8088 ersetzen diesen Dienst nicht.

## Einrichten

1. Auf der Anlage eine feste `pbx_id`, den Control-Zugang und erlaubte
   Middleware-Client-IDs einrichten. Je Nebenstelle und Gruppe Ein-/Ausschalten
   administrativ erlauben. Normal und ROT bleiben unabhängig.
2. Zwei unterschiedliche zufällige Dienstschlüssel mit jeweils 64 Hex-Zeichen
   erzeugen. Control-Schlüssel auf der Anlage registrieren; App-Schlüssel nur
   dem App-Server geben. Dateien außerhalb des Webroots mit Modus **0600** und
   Eigentümer `kienzlefon-t2med` ablegen. Keine Schlüssel in URLs oder Browser.
3. Die folgenden Abschnitte in `/etc/kienzlefon-t2med/config.toml` ergänzen.
   URLs, Anlagenkennung, Client-Liste und Quellnetz anpassen:

   ```toml
   [telephony_control]
   enabled = true
   base_url = "http://pbx-vpn.example:8767"
   token_file = "/etc/kienzlefon-t2med/control.token"
   pbx_id = "praxis"
   request_database = "/var/lib/kienzlefon-t2med/routing-requests.sqlite"

   [app_access]
   enabled = true
   token_file = "/etc/kienzlefon-t2med/app.token"
   allowed_clients = ["empfang-1"]
   allowed_networks = ["127.0.0.1/32"]
   ```

   App-Quellnetz zusätzlich in `http.allowed_networks` und den verwendeten
   Middleware-Host in `http.allowed_hosts` erlauben. HTTP nur im geschützten
   Praxisnetz/VPN oder festen SSH-Tunnel; sonst HTTPS mit gültigem Zertifikat.
   Es gibt keine CORS-Freigabe. `app_access` bleibt deaktiviert, wenn nur das
   eigene Telefonmenü gebraucht wird.
4. Installer mit `--keep-config` ausführen. Er erhält die Konfiguration,
   richtet das private Auftragsverzeichnis ein und prüft Schlüssel sowie
   Erreichbarkeit/Identität des aktivierten Control-Dienstes. Danach kann der
   lesende Test jederzeit wiederholt werden:

   ```sh
   sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/check-telephony-control.php --config=/etc/kienzlefon-t2med/config.toml
   ```

   Ohne aktivierte Module erfolgt kein neuer Anlagenzugriff. Für **Anrufe heute**
   zusätzlich die separate [Statistik und Ereignisquelle](ANRUFSTATISTIK.md)
   einrichten. Sie benötigt keine FHIR-Daten.

## App-Schnittstelle

Serverseitig `Authorization: Bearer <App-Schlüssel>` verwenden:

| Methode | Pfad | Ergebnis |
| --- | --- | --- |
| GET | `/api/v1/telephony/overview` | Live-Zahlen, Rufgruppen/Rechte, Nebenstellenstatus, Tagesstatistik; keine Patienten oder Gespräche |
| GET | `/api/v1/extension-routing/workplaces` | Wirksame Arbeitsplatzfreigabe der gekoppelten Anlage ab 1.5.6 |
| POST | `/api/v1/extension-routing` | Auftrag gemäß `RoutingCommand` |
| GET | `/api/v1/extension-routing/requests/{request_id}` | Bestätigtes oder weiterhin ungewisses Ergebnis |

Exakte Felder, Fehler und App-PHP-Proxy stehen im [HTTP-Vertrag](telefonie-v1/HTTP.md).
Der App-Schlüssel ist für andere Middleware-Funktionen gesperrt. Der App-Server
muss seine eigene Sitzung und CSRF-Prüfung durchführen. Das eigene Telefonmenü
verwendet weiterhin Bootstrap und `X-Kienzlefon-Token`.

## Gekoppelte Praxis-Arbeitsplätze ab Middleware 1.5.6 und Fon 2.6.11

Der App-Server fragt mit seinem praxisgebundenen Bearer-Schlüssel `GET /api/v1/extension-routing/workplaces` ab. Die Middleware liest den aktuellen Umfang mit dem privaten Schaltzugang von Fon und schneidet ihn mit den App-Beschränkungen. Die Antwort bei HTTP 200 hat genau diese Felder.

```json
{"schema_version":1,"pbx_id":"praxis-test","remote_enabled":true,"mode":"all","client_ids":[]}
```

Bei `all` ist jeder durch die App authentifizierte Arbeitsplatz der angegebenen Praxis zugelassen. Bei `selected` enthält `client_ids` ausschließlich freigegebene Platzkennungen, z. B. `["platz-a","platz-b"]`. Eine leere Auswahl oder `remote_enabled: false` sperrt die Bedienung. Fehlerantworten enthalten `error.code`. Bei ausgebliebener Kopplung meldet dieser Endpunkt HTTP 501 mit `not_supported`. Für diesen Fall gilt der bisherige Weg mit expliziter App-Liste und bekannten Middleware-Clients. Ein fehlgeschlagener Umfangsabruf erteilt keine Freigabe.

Der **App-Server** prüft die Praxis und den aktiven Arbeitsplatz seiner angemeldeten Sitzung. Für `POST /api/v1/extension-routing` und `GET /api/v1/extension-routing/requests/{request_id}` setzt er selbst neben `Authorization` die Header `X-Kienzlefon-Pbx-Id: praxis-test` und `X-Kienzlefon-Workplace: platz-a`. Die PBX-Kennung muss zur angebundenen Praxis gehören. Das Formular darf diese Zuordnung nicht vorgeben. Die Middleware prüft die Header gegen ihre Kopplung, den aktuellen Fon-Umfang und die `client_id` im Auftrag oder gespeicherten Ergebnis. Ein fehlender oder abweichender Sitzungsnachweis führt zu HTTP 403 mit `client_forbidden`. Gekoppelte Ergebnisse sind an Praxis und Arbeitsplatz gebunden.

Für die Haken bleiben die Felder aus `GET /api/v1/telephony/overview` maßgeblich. Die App zeigt `extensions[].label` neben `extensions[].id`. Unter `routing` steht die bestätigte Mitgliedschaft in `normal` und `specialist` (ROT). Zum Setzen gilt `permissions.<gruppe>.can_enable`, zum Entfernen `can_disable`. Zusätzlich müssen der authentifizierte Platz zugelassen und der Rufgruppenstatus aktuell, verfügbar und aktiviert sein. Eine veraltete Revision, ein Anlagenneustart und das letzte Mitglied werden weiter separat geprüft.

Der lokale Installer erkennt `routing_pairing_version: 1` im Fon-Handoff. Reader und Writer erhalten getrennte private Schlüssel. Fon erhält jeweils nur den Fingerabdruck. Bei einem bestehenden registrierten Schaltzugang verwendet das Update den ursprünglichen Schlüssel und dessen Arbeitsplatzbeschränkungen weiter. `telephony_control.routing_token_file` bezeichnet den Schaltzugang, `telephony_control.token_file` den Zugang für Anlagenzustand und Zähler. Der Installer setzt `legacy_routing` nur bei Migration eines nachgewiesenen früheren Schaltzugangs, damit auch frühere Control-Aufträge unter ihrer ursprünglichen Identität abgeglichen werden.

In `[app_access]` ist `workplace_mode = "fon"` die Vorgabe. Eine belegte `allowed_clients`-Liste schränkt den Fon-Umfang zusätzlich ein. `workplace_mode = "selected"` mit `allowed_clients = []` sperrt alle Plätze ausdrücklich. Für gekoppelte App-Arbeitsplätze ist kein GDT-/Click-to-Dial-Eintrag erforderlich.

Für Neuinstallation, Update und Reparatur der lokalen Kopplung im entpackten Paket `sudo bash installer/install.sh --with-app-telephony` ausführen. Bei der Erstinstallation mit Fon 2.6.11 kann die GDT-Auswahl leer bleiben. In diesem Fall stammen die angezeigten Nebenstellen aus dem Fon-Inventar. Der Installer übernimmt die Anlagenkennung als `pbx_id` in die lokale App-Konfiguration. Ein vorhandener Arbeitsplatzmodus und individuelle App-Plätze bleiben beim erneuten Lauf erhalten. Der App-Server berücksichtigt seine eigene Platzauswahl zusätzlich zum von der Middleware gemeldeten Umfang.

## Verhalten

- Kästchen zeigen bestätigte Teilnahme. Administrativ gesperrte Richtungen,
  unbekannte Daten und offene Aufträge sind deaktiviert. Der letzte Teilnehmer
  bleibt erhalten; die Anlage muss dies auch bei gleichzeitigen Aufträgen prüfen.
- Die Middleware speichert die UUID **vor** dem Versand. Nach verlorener Antwort
  und nach Neustart wird ausschließlich dieser Auftrag abgefragt, nie automatisch
  erneut gesendet. Bestätigte Ergebnisse bleiben mindestens sieben Tage erhalten;
  ungewisse Aufträge werden nicht zeitgesteuert gelöscht. Journal nicht löschen.
- Änderungen gelten bis zum Asterisk-Neustart gemäß Anlagenvertrag. Ein Neustart
  der Middleware oder des Browsers setzt keine Gruppenteilnahme zurück.
- Warten/IVR/AI kommen ausschließlich vom Control-Dienst. Ab zehn Sekunden ohne
  gültigen Stand erscheint `—`. Ein Control-Ausfall lässt die unabhängige
  AMI-Telefonie und Tagesstatistik weiterarbeiten.
- „Anrufe heute“ zeigt bei Erfassungslücken `≈`. Der Tooltip enthält alle Wege;
  ein Anruf kann mehrere Wege durchlaufen. Demo simuliert Steuerung und Zähler.

Vor Produktivfreigabe die [gemeinsame Abnahme](telefonie-v1/ACCEPTANCE.md) mit
Anlage und separat entwickelter App durchführen. Lokale Tests mit simulierten
Diensten ersetzen diese Prüfung nicht.
