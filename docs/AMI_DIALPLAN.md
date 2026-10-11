# Vertrag mit dem kienzlefon-Installer

Version 1. Der Asterisk-Dialplan wird im **kienzlefon-Projekt** umgesetzt. Diese Middleware ändert weder `manager.conf` noch den Dialplan. Folgende Namen und Felder verwendet die Implementierung verbindlich.

## AMI

Bestehende Manager-Benutzer und globale Bind-/TLS-Einstellungen erhalten. Middleware-IP ist die am Asterisk sichtbare Quelladresse, bei getrennten Servern also nicht `127.0.0.1`.

```ini
[kienzlefon-middleware]
secret = <lokal eingerichtetes Secret>
deny = 0.0.0.0/0.0.0.0
permit = <Middleware-IPv4>/255.255.255.255
read = system,call,agent,user,dialplan
write = call,originate,reporting
```

AMI aktiviert, Port konfigurierbar (üblich 5038), vom Middleware-Server erreichbar. Middleware meldet sich mit `Events: on` an. Benötigte Aktionen: `Login`, `Ping`, `CoreShowChannels`, `QueueStatus`, `Setvar`, `Originate`. Kein `Command`-/Konfigurationszugriff. `call` erlaubt Setvar, `originate` Originate, `reporting` die Bestandsabfrage. AMI-Kategorien sind gröber als einzelne Aktionen; die Anwendung begrenzt ihre eigenen Aktionen zusätzlich.

## Eingehende Anrufe

Einmal auf dem externen Eingangskanal vor dem ersten `Dial()` oder `Queue()`, nachdem die eigentliche Anrufernummer feststeht. Kein zusätzliches `Answer()`. Folgende Variablen vor dem Request zurücksetzen:

```text
LOOKUP_NAME=
LOOKUP_STATUS=pending
LOOKUP_RESULT_ID=
LOOKUP_DONE=0
```

Der Dialplan sendet:

```text
UserEvent: KienzlefonLookupRequest
SchemaVersion: 1
RequestId: <pro Lookup eindeutige Kennung>
SourceChannel: <Eingangskanal>
SourceUniqueid: <UNIQUEID>
SourceLinkedid: <CHANNEL(linkedid)>
Number: <CALLERID(num)>
BudgetMs: 1000
```

Die Middleware schreibt auf genau diesen Kanal nacheinander und jeweils nach AMI-Bestätigung:

1. `LOOKUP_RESULT_ID` = RequestId
2. `LOOKUP_NAME` = Name, bei nicht eindeutiger Zuordnung leer
3. `LOOKUP_STATUS` = `resolved`, `ambiguous`, `not_found` oder `error`
4. `LOOKUP_DONE` = `1` als letzte Schreiboperation

Asterisk wartet bis DONE oder maximal eine Sekunde ab Beginn des Lookup-Vorgangs. Ein frühes positives **oder negatives** Ergebnis beendet die Wartezeit sofort. Nur bei rechtzeitigem DONE, passender RequestId, Status `resolved` und nicht leerem Namen übernimmt der Dialplan `LOOKUP_NAME` in `CALLERID(name)`. `CALLERID(num)` bleibt erhalten. Ohne Ergebnis wird normal weitergerufen; die Middleware schreibt niemals direkt die Caller-ID.

**Die Frist muss Asterisk selbst durchsetzen.** Die Middleware begrenzt zusätzlich ihre Setvar-Kette auf höchstens 900 ms ab Empfang und stoppt bei Hangup oder LookupFinished. Das ersetzt die Asterisk-Frist nicht. Bei `WaitForCondition` die Bedingung dynamisch auswerten, eine bereits abgelaufene Restfrist nicht als Timeout 0 übergeben (0 bedeutet unbegrenzt), und nach dem Warten die Deadline vor Übernahme des Namens erneut prüfen. Asterisk 20 prüft im Modul die Bedingung vor dem Timeout. Fehlendes `app_waitforcond` bei Installation erkennen und den Lookup-Hook bis zur Behebung auslassen, ohne die Telefonie zu blockieren.

Danach immer, auch bei Timeout, senden:

```text
UserEvent: KienzlefonLookupFinished
SchemaVersion: 1
RequestId: <dieselbe Kennung>
SourceUniqueid: <UNIQUEID>
Status: resolved|ambiguous|not_found|error|timeout
Applied: 0|1
```

Eine verspätete APS-Antwort darf weiter die Webansicht aktualisieren, nicht die Telefonanzeige dieses Anrufs. Allgemeine APS-Suchtreffer, die mit der Patientennummer kollidieren, sowie Mehrfachtreffer erhalten keinen automatischen Telefon-Anzeigenamen.

## Ausgehende Anrufe

Standardkontext: `kienzlefon-click-to-dial` (in der Middleware konfigurierbar). AMI-Auftrag:

```text
Action: Originate
ActionID: kf-c2d-<UUID>
Channel: PJSIP/<Nebenstelle>
ChannelId: kf-<UUID>
Context: kienzlefon-click-to-dial
Exten: <normalisierte Zielnummer>
Priority: 1
Async: true
EarlyMedia: false
Timeout: 20000
CallerID: Rueckruf <Zielnummer>
Variable: __KF_C2D_ID=<UUID>
Variable: __KF_C2D_CLIENT=<Client-ID>
Variable: __KF_C2D_EXTENSION=<Nebenstelle>
```

Der Kontext wird erst nach Annahme an der Nebenstelle ausgeführt. Er prüft Nebenstelle und Ziel, meldet die nächste Phase und übergibt dann an die vorhandene kienzlefon-Ausgehroute:

```text
UserEvent: KienzlefonClickToDialProgress
SchemaVersion: 1
RequestId: <KF_C2D_ID>
Phase: dialing_target
SourceUniqueid: <UNIQUEID>
```

Die vorhandene Route übernimmt Providerwahl, Rufberechtigungen und **Praxis-Absenderrufnummer**. Das Originate-`CallerID` dient der Anzeige an der Mitarbeiter-Nebenstelle; es darf nicht ungeprüft als Absenderrufnummer zum Provider gelangen. Berechtigungen anhand `KF_C2D_EXTENSION` prüfen. Den Sonderkontext nicht pauschal für externe eingehende Anrufe freigeben.

`OriginateResponse: Success` ist nur die Annahme der ersten Rufphase. Die Middleware meldet erst `connected`, wenn der zweite Rufabschnitt und eine Brücke mit beantworteten Kanälen nachgewiesen sind. Eine AMI-Unterbrechung kann das Ergebnis ungewiss machen; kein automatischer Originate-Retry.

## Ereignisse und Bestandsabgleich

`telephony.queues: []` zeigt alle über AMI erkannten Warteschlangen. Eine explizite
Liste begrenzt die Anzeige auf diese Namen. Auch leere Warteschlangen ohne
Mitglieder werden über `QueueParams` aus `QueueStatus` übernommen. Ohne
erfolgreiche AMI-Anmeldung und abgeschlossenen Bestandsabgleich bleibt die Ansicht
als veraltet markiert; die unabhängig funktionierende APS-Suche bestätigt keine
AMI-Verbindung.

Newchannel/Newstate/NewCallerid/NewConnectedLine/Newexten/VarSet, DialBegin/DialEnd, Hangup, BridgeEnter/Leave/Destroy, Rename, QueueCallerJoin/Leave/Abandon, AgentConnect, QueueMemberStatus/Pause/Added/Removed, UserEvent und OriginateResponse dürfen nicht herausgefiltert werden. Dazu Snapshot-Ereignisse samt ActionID und Abschlussereignissen von CoreShowChannels/QueueStatus. Snapshots laufen bei Verbindung und alle 60 Sekunden. Während des Abgleichs werden Live-Ereignisse gepuffert; die Webansicht ist bis zu dessen Abschluss als veraltet markiert.

## Quellen

[Asterisk Setvar](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/Setvar/), [Originate](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/Originate/), [CoreShowChannels](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/CoreShowChannels/), [WaitForCondition](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/Dialplan_Applications/WaitForCondition/), [Asterisk-20-Implementierung von WaitForCondition](https://github.com/asterisk/asterisk/blob/20/apps/app_waitforcond.c).
