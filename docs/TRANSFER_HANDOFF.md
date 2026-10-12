# Übergabe an das kienzlefon-Telefonprojekt: Vermitteln

Die PHP-Middleware stellt die Bedienung für **direktes Vermitteln und Rückfrage**, jeweils zu einer **internen Nebenstelle oder externen Rufnummer**, bereit. Der kienzlefon-Installer muss die folgenden Asterisk-Voraussetzungen ergänzen. Die Middleware verändert den Telefonserver nicht. Ohne die neue Freigabevariable und den Zielkontext bricht sie die Vorbereitung vor der ersten schreibenden AMI-Aktion ab.

## 1. AMI-Vertrag ergänzen

Bestehender Benutzer `kienzlefon-middleware`, Secret, Quell-IP-Regeln und Netzwerkbindung bleiben erhalten. Zusätzlich zu den bisherigen Aktionen werden benötigt:

- `Getvar`: ausschließlich kanalspezifische Variablen und Funktionen lesen.
- `BlindTransfer`: das Gespräch direkt weitergeben.
- `Atxfer`: die native Rückfragefunktion starten.
- `CancelAtxfer`: zur ursprünglichen Verbindung zurückkehren.
- `PlayDTMF` mit `Receive: true`: den konfigurierten Abschlusscode in die Mitarbeiterseite einspeisen.

Diese Aktionen benötigen in Asterisk 20 die bereits vorgesehene Schreibkategorie `call`; `command` oder Konfigurationszugriff sind nicht erforderlich. Die Middleware beschränkt zusätzlich ihre eigene Aktionsliste. Native `BlindTransfer`-/`AttendedTransfer`-Ereignisse sowie `UserEvent`, `DialBegin`/`DialEnd`, `Newstate`, `BridgeEnter`/`BridgeLeave`, `Hangup` und `Rename` müssen durch die Ereignisfilter gelangen.

## 2. Mitarbeiterkanäle für native Rückfrage vorbereiten

Die tatsächlichen verbundenen PJSIP-Mitarbeiterkanäle müssen die Asterisk-Transferfunktionen besitzen. Das gilt für eingehende Queue-/Direktrufe, ausgehende Gespräche einschließlich Click-to-Dial sowie interne Gespräche. Local-Zwischenkanäle berücksichtigen.

- In `Dial()` bzw. `Queue()` gibt `t` der angerufenen Seite und `T` der anrufenden Seite das Transferrecht. Optionen entsprechend der **Mitarbeiterseite** ergänzen, nicht pauschal beide Seiten eines externen Gesprächs freigeben.
- Die Funktion `atxfer` sowie `atxferabort` und `atxfercomplete` müssen eingerichtet sein. Bereits belegte Featurecodes erhalten; keine bestehenden globalen `features.conf`-Werte blind ersetzen. Die Middleware liest die wirksamen Codes am Kanal: `FEATUREMAP(atxfer)`, `FEATURE(atxferabort)`, `FEATURE(atxfercomplete)`.
- Ein möglicher konfliktfreier Satz ist `atxfer=*2`, `atxferabort=*3`, `atxfercomplete=*4`. Das ist ein Beispiel, keine zwingende Belegung. Der Client verarbeitet Codes aus ein bis acht Zeichen `0–9`, `*`, `#`.
- Rückkehr bei besetztem, nicht erreichbarem oder nicht antwortendem Ziel mit den nativen Asterisk-Einstellungen erhalten; den wartenden Anrufer nicht durch ein eigenes vorsorgliches Hangup verlieren.
- **Erst auf korrekt vorbereiteten Mitarbeiterkanälen `KF_TRANSFER_READY=1` setzen.** Bei angerufenen Mitarbeitern dazu den passenden Pre-Dial-/Kanal-Hook verwenden; eine Variable nur auf dem externen Eingangskanal reicht nicht.

Die Middleware prüft vor jedem Auftrag `KF_TRANSFER_READY`, `DIALPLAN_EXISTS(kienzlefon-transfer,<Ziel>,1)` und bei Rückfrage die drei Featurecodes. Sie startet nur aus einem aktuellen, beantworteten Gespräch mit eindeutig zwei Teilnehmern und einer eindeutig zugeordneten Mitarbeiternummer. Konferenzen und veraltete AMI-Zustände sind ausgeschlossen.

## 3. Privaten Zielkontext hinzufügen

Standardname **`kienzlefon-transfer`**. Abweichungen müssen mit `telephony.transfer_context` in `/etc/kienzlefon-t2med/config.toml` übereinstimmen. Der Kontext gehört nicht in öffentliche Eingangs-/Endpoint-Includes.

Die Middleware sendet auf dem **Mitarbeiterkanal**:

```text
Action: BlindTransfer | Atxfer
Channel: <aktueller eigener PJSIP-Kanal>
Context: kienzlefon-transfer
Exten: <numerisches internes oder externes Ziel>
```

Zuvor setzt sie auf Mitarbeiter, Gegenstelle und gegebenenfalls unmittelbar gebrücktem Local-Kanal folgende vererbbare Variablen, jeweils nach bestätigtem `Setvar`:

| AMI-Variablenname | Inhalt |
| --- | --- |
| `__KF_TRANSFER_ID` | UUID des Vermittlungsauftrags |
| `__KF_TRANSFER_CLIENT` | Registrierte Client-ID |
| `__KF_TRANSFER_EXTENSION` | Eigene Nebenstelle, z. B. `202` |
| `__KF_TRANSFER_SOURCE_UID` | Uniqueid des ursprünglichen Mitarbeiterkanals |
| `__KF_TRANSFER_MODE` | `blind` oder `attended` |
| `__KF_TRANSFER_TARGET_TYPE` | `internal` oder `external` |
| `__KF_TRANSFER_TARGET` | Numerisches Ziel, identisch zu `Exten` |

Im Dialplan werden die Namen ohne Unterstriche gelesen. Vererbung in die von Atxfer erzeugten Local-Kanäle erhalten. Der Kontext läuft auf der weitergegebenen Gegenstelle bzw. einem neuen Rückfragekanal. **Dort nicht `CHANNEL(endpoint)` mit der ursprünglichen Mitarbeiternummer vergleichen.** Der bisherige Click-to-Dial-Prüfer kann deshalb nicht unverändert übernommen werden.

Ein eigener Transfer-Prüfer soll vor der Wahl UUID/Clientformat, Modus, Zielart, konfigurierte Mitarbeiter-Nebenstelle, Zielgleichheit mit `${EXTEN}` sowie Zielberechtigung prüfen. Nur explizite Ziffernmuster zulassen, kein universelles `_.`; Sonderextensions `h`, `i`, `t` separat beenden, ohne den Prüfer noch einmal als Wahlauftrag aufzurufen.

**Intern:** Nur konfigurierte freigegebene andere Nebenstellen wählen. Bestehende Nebenstellenzuordnung verwenden.

**Extern:** Dieselben Rufnummernsperren, Providerregeln und Kapazitätsprüfungen wie bei einer normalen ausgehenden Wahl verwenden. Provider und Praxis-Absenderrufnummer anhand `KF_TRANSFER_EXTENSION` bestimmen. Die externe Anrufernummer nicht ungeprüft als eigene Absendernummer zum Provider senden. Vollständige internationale Nummern kommen als `00…`, beispielsweise `00491765550173`; nationale Schreibweisen werden für den konfigurierten Ländercode ebenfalls vereinheitlicht.

## 4. Fortschritt aus dem Zielkontext melden

Nach erfolgreicher Zielprüfung **unmittelbar vor dem tatsächlichen `Dial()`** auf dem Kanal, der dieses `Dial()` ausführt:

```text
UserEvent: KienzlefonTransferProgress
SchemaVersion: 1
RequestId: <KF_TRANSFER_ID>
TransfererUniqueid: <KF_TRANSFER_SOURCE_UID>
SourceUniqueid: <UNIQUEID des wählenden Zielkontext-Kanals>
Phase: dialing_target
```

Bei vorgeschaltetem `Goto()` den Hook also dort platzieren, wo anschließend wirklich gewählt wird. Die Middleware verfolgt ab `SourceUniqueid` den zweiten Rufabschnitt anhand von `DialBegin` und den Brücken. Wiederholte Fortschrittsereignisse dürfen keine zweite Wahl erzeugen.

Bei endgültiger Validierungs-/Routingablehnung dasselbe Ereignis mit `Phase: failed` senden. Ein normales Besetzt/Nichtmelden bei einer Rückfrage soll die native Rückkehr zum Anrufer durchlaufen; dafür kein vorschnelles terminales `failed` senden. Ein `h`-Handler darf nach erfolgreicher Vermittlung keinen nachträglichen Fehler melden.

## 5. Abschluss und Abbruch

- Direkt: `BlindTransfer` übergibt den Anrufer an den Zielkontext. Erfolg bestätigt die Weitergabe, noch nicht die Antwort des Ziels.
- Rückfrage: `Atxfer` startet die native Rückfrage. Die ursprüngliche Gegenstelle wartet; erst eine beobachtete beantwortete Brücke zum tatsächlichen Ziel schaltet **Abschließen** frei. Ein beantworteter Local-Kanal allein genügt nicht.
- **Zurück zum Anrufer:** `CancelAtxfer` auf dem ursprünglichen Mitarbeiterkanal. Die API-Bestätigung allein genügt nicht; die Oberfläche wartet auf die wiederhergestellte ursprüngliche Brücke.
- **Abschließen:** `FEATURE(atxfercomplete)` erneut am Mitarbeiterkanal lesen, dann dessen Ziffern nacheinander mit `PlayDTMF`, `Receive: true`, `Duration: 100` senden. Kein blindes `Hangup` auf dem wartenden Anrufer. Das native `AttendedTransfer`-Ereignis bestätigt den Ausgang.
- Kanalnamen können sich ändern; die Middleware hält die Zuordnung über Uniqueids und `Rename` aktuell. Native Ereignisse können als Transferee den unmittelbar benachbarten Local-Kanal statt des externen PJSIP-Kanals nennen.
- Nach AMI-Verlust oder fehlender eindeutiger Bestätigung: Ergebnis **ungewiss**, keine automatische Wiederholung. Der Auftrag bleibt im Web sichtbar; Bedienung am Telefon ist weiterhin möglich. Flüchtige Aufträge überleben keinen Neustart der Middleware.

## 6. Abnahme auf dem Telefonserver

Der Installer muss die Ergänzung dauerhaft erzeugen und bei Updates erhalten. Vor Reload die erzeugten Konfigurationen validieren. Bestehende Dialplanwege, Featurecodes und Manager-Benutzer erhalten.

Lesende Vorprüfung:

```bash
asterisk -rx 'manager show command BlindTransfer'
asterisk -rx 'manager show command Atxfer'
asterisk -rx 'manager show command CancelAtxfer'
asterisk -rx 'manager show command PlayDTMF'
asterisk -rx 'features show'
asterisk -rx 'dialplan show kienzlefon-transfer'
```

Anschließend mit vereinbarten Testgesprächen prüfen:

1. Eingehender Queue-Anruf an 202: direkt nach 201, dann separat zu einer externen Testnummer; richtige Praxis-Absendernummer.
2. Rückfrage intern und extern: ursprünglicher Anrufer wartet, Ziel klingelt, nach Annahme Rücksprache; danach einmal abschließen und einmal zum Anrufer zurückkehren.
3. Besetzt/Nichtmelden, Ziel legt auf, Anrufer legt auf, Mitarbeiter legt auf; keine falsche Erfolgsmeldung und native Rückkehr-/Rückrufregeln prüfen.
4. Dieselben Abläufe bei ausgehendem Click-to-Dial und bei Local-Kanälen; Featureberechtigung nur für die Mitarbeiterseite.
5. Doppelklick, Browser-Neuladen, Arbeitsplatzwechsel und AMI-Unterbrechung erzeugen keinen zweiten Vermittlungsauftrag. Fremde Gespräche und Warteschlangeneinträge lassen sich nicht vermitteln.

Die Middleware-Tests simulieren AMI-Frames und prüfen API, Kanalzuordnung, Rückfragezustände und Wiederholungsschutz. Sie ersetzen diesen Test gegen den tatsächlich installierten Asterisk-Dialplan nicht.

## Quellen

[Asterisk BlindTransfer](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/BlindTransfer/), [Atxfer](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/Atxfer/), [CancelAtxfer](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/CancelAtxfer/), [PlayDTMF](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/PlayDTMF/), [Transfer-Featurecodes und t/T-Rechte](https://docs.asterisk.org/Configuration/Features/Feature-Code-Call-Transfers/), [AttendedTransfer-Ereignis](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Events/AttendedTransfer/).
