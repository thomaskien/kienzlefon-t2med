# Nebenstellensteuerung: gemeinsamer Vertrag

Stand: **18.11.2026**. Die technische Festlegung ist abgeschlossen.
Maßgeblich ist der [Telefonievertrag v1](telefonie-v1/README.md), einschließlich
HTTP-Endpunkten, JSON-Schema, Beispielen und gemeinsamer Abnahme.
Die Middleware-Endpunkte und das eigene Telefonmenü sind für 1.5.0 lokal
umgesetzt. Anlagen-Control-Dienst und App werden separat bereitgestellt;
Einrichtung und gemeinsamer Test: [Telefonie-Steuerung](TELEFONIE-STEUERUNG.md).

Festgelegt sind:

- **Normal** (`normal`) und **ROT** (`specialist`), unabhängig von IVR-Tasten.
- Freigabe je Nebenstelle, Gruppe und Schaltrichtung durch die Administration;
  keine zusätzliche Beschränkung auf den eigenen Arbeitsplatz.
- Letzten Teilnehmer erhalten; konkurrierende Aufträge gemeinsam prüfen.
- ROT: eigener gemeinsamer Ruf ohne Vererbung aus Normal; bei aktiviertem
  rotem Telefon zuvor dessen separater Einzelversuch.
- Bedienänderungen bis zum Anlagenneustart; danach administrative Standards.
  Reine Dienst-/Browser-Neustarts ändern die Teilnahmen nicht.
- Browser → eigener App-PHP-Proxy → Middleware → geschützter Kienzlefon-Dienst.
  Dienstschlüssel bleiben serverseitig; keine neue Browser-CORS-Freigabe.
- Bestätigte Zustände, Rechte/Sperrgründe, Revisionen, Aufträge, Fristen,
  Wiederholungserkennung und Neustarts sind im Vertrag exakt beschrieben.

Der [Gesamtumfang](NAECHSTE-VERSION.md) unterscheidet Definition und Umsetzung.
Die separat entwickelte App bleibt ein eigener Implementierungsauftrag.
