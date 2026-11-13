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

## Protokoll (T102)

Bootsprüfung › Protokoll zeigt die Tabelle `bp_audit_log` lesbar an. Nur für Administratoren (Recht „Optionen
verwalten“), weil dort Vorgänge aller Bereiche stehen (Zugänge, Gruppen, Bestellungen, Inhalte, Importe, Datenschutz,
Einstellungen).

- **Filter:** Zeitraum (von/bis), Ereignis (ein Bereich wie „Zugänge (alle Ereignisse)“ oder ein einzelnes Ereignis),
  Nutzer (Nummer, E-Mail oder Login; findet eigene Handlungen, Einträge zum Konto und zu den Zugängen der Person) und
  Bezug (Zugang, Gruppe oder Bestellung mit Nummer). 50 Einträge je Seite.
- **Anzeige:** Zeit, wer gehandelt hat (Anzeigename oder „System (automatisch)“), Ereignis auf Deutsch, Bezug als Link,
  Begründung und Details als Text (z. B. „von aktiv auf ausgesetzt · Kurs: SBF See · Funktion: Fragen lernen“).
  Unbekannte Ereignisse erscheinen als „Sonstiges Ereignis (interner Name)“.
- **Datenschutz:** keine E-Mail-Adressen; Personen nur als Anzeigename bzw. Nutzer-Nummer. Prüfwerte gelöschter Konten
  und Fehlermeldungen von Löschvorgängen werden nicht angezeigt. Einträge werden nach 4 Jahren gelöscht.
- **Verlauf:** In Zugänge › Zugang und in Gruppenlizenzen › Gruppe steht ein aufklappbarer „Verlauf“ mit den
  passenden Einträgen (beim Zugang auch die der Bestellung bzw. manuellen Freischaltung) und, für Administratoren,
  ein Link „Im Protokoll öffnen“.
