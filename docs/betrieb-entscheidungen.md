# Betrieb: Entscheidungen des Betreibers

## T40 Aufbewahrung von Content-Paketen und Import-Protokoll

Der stündliche Wartungslauf räumt auf (`src/Content/Import/Retention.php`):

| Was | Behalten | Änderbar über Filter |
|---|---|---|
| Paketkopien (je Import rund 17 MB) | das aktive Paket und die letzten 2 weiteren veröffentlichten | `bootspruefung_import_keep_packages` |
| Importe in Arbeit (hochgeladen, aber nicht abgeschlossen) | 30 Tage | – |
| Import-Protokoll, Zeilen „unverändert“ (rund 98 % der Zeilen) | 14 Tage | `bootspruefung_import_log_unchanged_days` |
| Import-Protokoll, übrige Zeilen (Änderungen und Fehler) | 180 Tage; beim aktiven Import bleiben die Änderungen | `bootspruefung_import_log_days` |

**Folgen:**
- „Zurücksetzen“ auf ein früheres Paket geht nur zu den behaltenen Paketen. Ältere Pakete lassen sich erneut
  hochladen.
- Das Import-Protokoll enthält Inhalte (Fragen-IDs, Aktion, Meldung), keine Kundendaten. Wer einen Import
  angelegt und freigegeben hat, steht im Import selbst (Benutzer-ID), nicht im Protokoll.
- Speicherbedarf der Paketkopien: höchstens rund 3 × 17 MB, dazu Importe in Arbeit.

**Frage:** Passen 2 frühere Pakete, 14 Tage für „unverändert“ und 180 Tage für das übrige Protokoll? Müssen
Inhaltsänderungen länger nachweisbar sein (z. B. für Rechte an Fragen oder Bildern)?
