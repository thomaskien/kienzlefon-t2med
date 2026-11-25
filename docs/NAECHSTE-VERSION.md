# Middleware 1.5.0 · Umsetzungsstand

Stand: 18.11.2026. Lokal umgesetzt; noch nicht veröffentlicht oder auf dem
Praxisserver installiert. Grundlage ist der gemeinsame Vertrag **1.0.2**.

| Bestandteil | Stand |
| --- | --- |
| Tagesstatistik, eigene SQLite, Wege-Tooltip | Middleware und eigenes Menü umgesetzt |
| Control-Client, Live-Zahlen Warten/IVR/AI | Middleware umgesetzt; Gegenstelle erforderlich |
| Normal/ROT, Rechteprüfung, dauerhaftes Auftragsjournal | Middleware und Voll-/Kompaktmenü umgesetzt |
| Eng begrenzte App-API | Middleware umgesetzt; App-Proxy separat |
| Administrative Freigaben, atomarer letzter-Teilnehmer-Schutz, Rücksetzen bei Asterisk-Neustart | Anlagenprojekt; dort umzusetzen und gemeinsam abzunehmen |
| Echte Eingangs-/Wegehooks und Live-Erfassungsquelle | Anlagenprojekt |
| App-Oberfläche und Same-Origin-Proxy | Separat entwickeltes App-Projekt |

[Einrichtung und Verhalten](TELEFONIE-STEUERUNG.md) ·
[Gemeinsamer Vertrag](telefonie-v1/README.md) · [Tests](TESTING.md)

Normal und ROT bleiben unabhängig. Freigaben gelten je betroffener Nebenstelle,
Gruppe und Richtung, auch für andere Nebenstellen. Der letzte Teilnehmer bleibt
aktiv. Beim ROT-Weg hat ein eingeschaltetes rotes Telefon seinen ersten Einzelruf;
sonst startet der ROT-Gruppenruf direkt. Nur eingeschaltete ROT-Mitglieder folgen.
Temporäre Bedienänderungen enden beim Asterisk-Neustart; administrative
Grundeinstellungen bleiben erhalten. Browser-/Middleware-Neustarts ändern keine
Teilnahme. Die Anlage bleibt für Rechte und Rufverhalten maßgeblich.

FHIR bleibt Standard. Der veröffentlichte Tag v1.4.3 bleibt unverändert;
der inzwischen zusammengeführte Patienten-Tab-Abschluss ist enthalten.
