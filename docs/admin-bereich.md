# Admin-Bereich „Bootsprüfung“: Bedienung

Kurzbeschreibung der Verwaltungsseiten des Lern-Plugins für den Betrieb. Die Begriffe stehen in `docs/glossar.md`
(dieselbe Liste ist im Admin auf der Übersicht aufklappbar).

## Laufzeit neuer Zugänge (T101)

Neue Zugänge bekommen immer eine Laufzeit vorbelegt. „Ohne Ablauf (dauerhaft)“ gibt es nur noch ausdrücklich per
Checkbox.

| Wo | Vorbelegung | Ohne Ablauf |
|---|---|---|
| Produkt › Reiter „Lernzugang“ › „Laufzeit in Tagen“ | Laufzeit der SKU im Shop-Katalog, sonst 365 Tage | Checkbox „ohne Ablauf (dauerhaft)“ |
| Zugänge › „Zugang manuell freischalten“ › „Gültig bis“ | heute + Laufzeit des Kurses im Shop-Katalog (Vollkurse 365 Tage, SKN/FKN und Ergänzungen 182 Tage), sonst 182 Tage | Checkbox „ohne Ablauf (dauerhaft)“ |
| Zugang ändern › „Neues Enddatum“ | – | Checkbox „ohne Ablauf (dauerhaft)“ |

- Ein leeres Laufzeit-Feld im Produkt speichert die Vorbelegung, nicht mehr „dauerhaft“. Ein leeres Datum bei der
  manuellen Freischaltung wird abgelehnt.
- Bereits gespeicherte Werte bleiben unverändert. Ein Produkt, das schon „dauerhaft“ freischaltet, zeigt die Checkbox
  angekreuzt und bleibt so, bis jemand eine Laufzeit einträgt.
- Übersicht › „Produktzuordnungen prüfen“ nennt jedes Produkt mit Laufzeit „ohne Ablauf“ als Hinweis. Ist das gewollt
  (z. B. ein Sonderprodukt), kann der Hinweis stehen bleiben.
