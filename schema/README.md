# Content-Paket – Format v1

JSON-Schemata (Draft 2020-12) für das Paket, das `tools/content-build/` aus
`content-source/boatingschool/` erzeugt und das WordPress-Plugin importiert.

## Aufbau

```text
<package_id>/
  manifest.json              # manifest.schema.json
  data/courses.json          # courses.schema.json
  data/modules.json          # modules.schema.json (inkl. Fragenzuordnung)
  data/questions.json        # questions.schema.json (Frage + aktuelle Revision + Antworten + Medienbezug)
  data/media.json            # media.schema.json
  data/exam_rules.json       # exam_rules.schema.json (inkl. Blueprints)
  data/exam_papers.json      # exam_papers.schema.json
  data/navigation_tasks.json # navigation_tasks.schema.json (inkl. Teilaufgaben)
  data/praxis_tasks.json     # praxis_tasks.schema.json
  media/…                    # Dateien, Pfade wie in media.json
```

Das ZIP `<package_id>.zip` enthält dieselbe Struktur.

## Regeln

- **Stabile IDs:** `source_id` ist je Entität eindeutig und bleibt über Re-Konvertierungen
  gleich. Fragen: `content_id` der Quelle (`SBF-SEE-0001`, `FKN-0001`); Bögen:
  `<Kurs>#<Nr>`; Regelwerke: `<Kurs>@<Version>`; Navigation: `NAV-01`, Teilaufgabe
  `NAV-01-01`; Medien: `elwis/<datei>`, `praxistrainer/<datei>`.
- **Hash:** Jeder Datensatz trägt `hash` = `sha256:` + SHA-256 seines JSON ohne das Feld
  `hash`. Gleicher Hash → unverändert; anderer Hash → neue Revision bzw. Update.
- **Paket-ID:** `boatingschool-<commit7>-<content12>`; identischer Inhalt ergibt dieselbe ID.
- **Fragen:** Das Paket liefert je Frage den aktuellen Stand. Revisionen entstehen erst
  beim Import in WordPress (neuer Hash → neue Revision, alte bleibt für Versuche erhalten).
- **Fragetypen:** `single_choice`, `multi_choice`, `open_text` (eine Musterantwort `MODEL`),
  `chart_task` (freie Kartenaufgabe ohne Antworten; nie `published`).
- **Status:** `editorial_status`/`status` wie in der Quelle (`draft`, `review`, `approved`,
  `published`, `deprecated`). `rights_status` (`unknown`, `review`, `licensed`, `public`,
  `restricted`) entscheidet beim Import über die öffentliche Auslieferung.
- **Manifest:** enthält Herkunft (Commit), Zähler, alle Dateien mit SHA-256, Warnungen,
  übersprungene Quellzeilen mit Grund und die noch nicht konvertierten Bereiche
  (`not_converted`). In der Quelle entfernte IDs erscheinen als Warnung `source_removed`,
  wenn mit `--previous` gebaut wird.
