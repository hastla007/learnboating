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

## Geld-zurück-Garantie am Produkt (T107)

Produkt › Reiter „Lernzugang“ › „Geld-zurück-Garantie“ (Tage ab Zahlungseingang, „solange gelernt unter … %“):

- **Vorbelegung:** Neue Produkte sind mit den Werten der AGB vorbelegt (Shop-Katalog: 14 Tage, unter 20 %).
  „Keine Geld-zurück-Garantie“ gibt es nur ausdrücklich per Checkbox. Ein bestehendes Produkt ohne Garantie zeigt die
  Checkbox angekreuzt und bleibt so.
- **Leere Felder:** Ein leeres Tage- oder %-Feld speichert den AGB-Wert. 0 % wird nie gespeichert (damit wäre jede
  Anfrage sofort abgelehnt). Jede solche Ersetzung steht nach dem Speichern als Meldung über dem Produkt.
- **Abweichung:** Weichen Tage oder Grenze von den AGB-Werten ab, zeigt das Produkt einen Hinweis („bitte prüfen, ob AGB,
  Garantieseite und Produkttext dazu passen“). Variationen zeigen die Garantie des Produkts (sie gilt für alle
  Variationen) mit demselben Hinweis.
- **Altbestand:** Früher konnte ein leeres %-Feld als 0 % gespeichert werden. Solche Produkte stehen in der Übersicht
  unter „Produktzuordnungen prüfen“ als Hinweis. Abhilfe: Produkt öffnen, Grenze prüfen (vorgeschlagen sind 20 %),
  speichern.

## Protokoll (T102)

Bootsprüfung › Protokoll zeigt die Tabelle `bp_audit_log` lesbar an. Nur für Administratoren (Recht „Optionen
verwalten“), weil dort Vorgänge aller Bereiche stehen (Zugänge, Gruppen, Bestellungen, Inhalte, Importe, Datenschutz,
Einstellungen).

- **Filter:** Zeitraum (von/bis), Ereignis (ein Bereich wie „Zugänge (alle Ereignisse)“ oder ein einzelnes Ereignis),
  Nutzer (Nummer, E-Mail oder Login; findet eigene Handlungen, Einträge zum Konto und zu den Zugängen der Person) und
  Bezug (Zugang, Gruppe oder Bestellung mit Nummer). 50 Einträge je Seite.
- **Anzeige:** Zeit, wer gehandelt hat (Anzeigename oder „System (automatisch)“), Ereignis auf Deutsch, Bezug als Link,
  Begründung und Details als Text (z. B. „von aktiv auf ausgesetzt · Kurs: SBF See · Funktion: Fragen lernen“).
  Unbekannte Ereignisse erscheinen als „Sonstiges Ereignis (interner Name)“. Seit T119 hat jede Aktion, die Plugin,
  Theme oder Add-ons ins Protokoll schreiben, eine Bezeichnung (auch Livegang-Checkliste, „Shop trotz offener Punkte
  sichtbar gemacht“, Sicherheitseinstellungen, Datenschutz-Löschung); `admin-test.php` sucht dazu alle Aufrufe im Code
  und schlägt fehl, wenn eine Bezeichnung oder ein Bereich fehlt. Neue zusammengesetzte Namen (`'präfix_'.$wert`)
  bitte dort in `$auditDynamic` ergänzen.
- **Bereiche:** „Einstellungen und Livegang“ umfasst auch Sicherheit, Livegang-Checkliste und das Sichtbarmachen des
  Shops trotz offener Punkte; „Datenschutz und Konten“ auch das Löschen der Lerndaten.
- **Werte im Klartext:** „Zugang ohne Shop“ mit den Bezeichnungen der Einstellung, PayPal-Umgebung als
  „Testumgebung (Sandbox)“ bzw. „Echtbetrieb“, Quelle als „Bestellung“, „manuell“, „Gruppe“, „Kursgutschein“, „PayPal“,
  „Klarna“. Gutscheincodes (Prüfwert) und Fehlermeldungen werden nicht angezeigt.
- **Datenschutz:** keine E-Mail-Adressen; Personen nur als Anzeigename bzw. Nutzer-Nummer. Prüfwerte gelöschter Konten
  und Fehlermeldungen von Löschvorgängen werden nicht angezeigt. Einträge werden nach 4 Jahren gelöscht.
- **Verlauf:** In Zugänge › Zugang und in Gruppenlizenzen › Gruppe steht ein aufklappbarer „Verlauf“ mit den
  passenden Einträgen (beim Zugang auch die der Bestellung bzw. manuellen Freischaltung) und, für Administratoren,
  ein Link „Im Protokoll öffnen“.

## Zugänge und Inhaltspakete: Fehler, Ergebnis, Tempo (T121, T124)

- **Zugang manuell freischalten:** Schlägt die Freischaltung fehl (z. B. „Nutzer nicht gefunden: … Bitte E-Mail-Adresse,
  Login oder Nutzer-Nummer prüfen.“), springt die Seite zurück zum Formular und zeigt die Eingaben wieder (Nutzer, Kurs,
  Funktionen, Datum, „ohne Ablauf“, Begründung; 5 Minuten je Person, Transient `bp_grant_input_<Nutzer>`).
- **Import-Ergebnis:** Die Tabelle „Ergebnis“ eines Imports nennt die Aktionen auf Deutsch wie das Import-Protokoll
  (neu, geändert, unverändert, archiviert, übersprungen); die Hinweise aus dem Paket zeigen eine lesbare Art
  (z. B. „Pflichtbild fehlt“, „Regelwerk nicht geprüft (Übungsmodus)“), der interne Code steht im Tooltip.
- **Liste „Zugänge“ und Protokoll:** Der Link zur Bestellung entsteht ohne Laden der Bestellung
  (`OrderUtil::get_order_admin_edit_url`, sonst `post.php?post=…`), die Namen der Personen werden vorab in einer
  Abfrage geladen. Die Zahl der Datenbankabfragen hängt nicht mehr von der Zahl der Zeilen ab.

## Feinschliff der Admin-Seiten (T126)

- **Begriffe:** Inhaltspakete ohne „Dry-Run“, „Rollback“, „Commit“, „Fragenrevisionen“ und ohne Pfad ins Repository:
  „Vorschau“, „Wiederherstellung“, Spalte „Stand“ (vollständige Kennung im Tooltip), „Fassungen der Fragen“. Rohwerte
  wie `unknown`/`restricted` erscheinen nur noch als Tooltip. Die Livegang-Checkliste erklärt „Mischstand“
  (Inhalte teils aus dem neuen, teils aus dem bisherigen Paket).
- **Keine Sackgassen:** „Zugang Nr. … nicht gefunden“ und „Import #… nicht gefunden“ mit Knopf zurück zur Liste,
  „Gruppe Nr. … nicht gefunden“ über der Liste. Eine beendete Gruppe zeigt statt eines leeren Formulars „Die Gruppe ist
  beendet – nicht mehr änderbar.“
- **Einstellungen › Prüfungsanmeldung:** „Derzeit vorbereitet, aber deaktiviert.“ steht nur noch, wenn die Seite aus ist.
- **Handy:** Tabellen der Plugin-Seiten (`Ui::table()`) liegen in einem seitlich scrollbaren Bereich
  (`.bp-table-wrap` in `assets/admin/admin.css`), die Seite wird nicht mehr breiter als der Bildschirm.
- **Barrierefreiheit:** Datei-Feld „ZIP-Datei des Inhaltspakets“ mit Beschriftung, Auswahl im Ablageordner als
  Gruppe mit Überschrift, Fortschrittsbalken des Imports mit Namen.

## Rechte und Regelwerke freigeben (T103)

Seiten „Rechte“ und „Prüfungsregeln“ (sowie der Rechte-Status einer einzelnen Frage):

- **Rückfrage mit Anzahl:** Vor jeder Änderung fragt die Seite nach, z. B. „12 Inhalte mit Rechte-Status „ungeklärt“
  als „lizenziert“ freigeben?“ oder „Wert für 40 Einträge ändern?“. Mit JavaScript als Dialog im Browser; ohne
  JavaScript erscheint oben auf der Seite ein Zwischenschritt mit Anzahl, neuem Wert und Begründung und den Knöpfen
  „Ja, ausführen“ und „Abbrechen“ (gilt 10 Minuten).
- **Auswahlfeld:** zeigt den aktuellen Stand (wirksamer Rechte-Status der Gruppe bzw. die lokale Prüfung des
  Regelwerks; ohne lokale Abweichung „Quelle gilt“). Früher war immer „lizenziert“ vorausgewählt.
- **Begründung:** bleibt Pflicht und ist nicht mehr vorbelegt – bitte eigene Begründung mit Datum und Namen eintragen.
- **Anzeige:** lokale Abweichungen eines Regelwerks und „Letzte Freigaben“ stehen als Text (wie im Protokoll),
  nicht mehr als JSON.

## Einheitliche Begriffe (T100)

Alle Admin-Texte folgen der Begriffsliste in `docs/glossar.md` (im Admin: Übersicht › „Hilfe“ › „Begriffe (Glossar)“).
Wichtigste Umbenennungen auf den Seiten:

| Vorher | Jetzt |
|---|---|
| Zugang manuell freigeben / „Zugang freigeben“ | Zugang manuell freischalten / „Zugang freischalten“ |
| Spalte „Umfang“ bei einem einzelnen Zugang | „Funktion“ |
| Zeitgesteuerte Freigabe | Zeitversetzte Freischaltung |
| Platz freigeben (Gruppe) | Entfernen (Platz wird frei) |
| Override, lokaler Override, „Override entfernen“ | Lokale Abweichung, „Lokale Abweichung entfernen (importierter Wert gilt)“ |
| live geschaltet / nur Übung / Live-Modus | Prüfungsmodus / Übungsmodus |
| Content-Paket(e) | Inhaltspaket(e) (Menüpunkt „Inhaltspakete“) |
| Revision, Rev. | Fassung |
| Tenant-Slug | App-Kennung |
| Audit-Log | Protokoll |

Kapitelverweise („Kap. 7.2“) und Pfade wie `docs/…` stehen nicht mehr in Admin-Texten.

## Menü, Einstellungen und Barrierefreiheit (T104)

**Menü-Reihenfolge:** Übersicht · Inhalte (Inhaltspakete, Kurse & Module, Fragen, Medien, Prüfungsregeln, Rechte,
Prüfungsanmeldung, FAQ, Kunden-FAQs, Tipps) · Zugänge · Kostenlose Bereiche · Gruppenlizenzen · Mitgliederrabatte ·
Preise · Protokoll · Einstellungen · Mobile App · Systemstatus. Seiten anderer Teile ordnet das Plugin ein
(`Menu::ORDER`).

**Einstellungen** sind in Bereiche gegliedert (Sprungmarken oben): „Zugang und Erinnerungen“, „Darstellung und
Lernen“, „Datenschutz und Daten“, „Betrieb“, „Sicherheit“, „Zahlungskonflikte“. Die gespeicherten Optionen und ihre
Schlüssel sind unverändert.

**Betrieb (T117):** „Auf neue Versionen prüfen“ (Standard aus, `update_check_enabled`). Eingeschaltet fragt der
stündliche Wartungslauf höchstens einmal am Tag die GitHub-Releases ab (`Infrastructure\UpdateCheck`, Quelle per
Konstante `BOOTSPRUEFUNG_UPDATE_SOURCE` bzw. Filter `bootspruefung_update_source`). Ergebnis: Zeile „Neue Version“ im
Systemstatus und ein Hinweis auf Dashboard, Plugins, Aktualisierungen und den Bootsprüfung-Seiten (wegklickbar bis
zur nächsten Version; Sicherheitsupdates als Warnung, nur einen Tag vertagbar). Nichts wird automatisch installiert.
Ausschalten verwirft das gespeicherte Ergebnis.

**Website-Zustand (T127):** Unter Werkzeuge › Website-Zustand › Bericht steht ein Abschnitt „Bootsprüfung“
(Versionen, Schema, Migration, Wartungslauf, Speicher-Selbsttest, Zugang ohne Shop, WooCommerce, Versionsprüfung –
ohne Pfade und Geheimnisse; wird beim „Bericht kopieren“ mitgenommen). Unter „Status“ prüfen zwei Tests ohne Anfrage
nach außen: „Datenbank-Migration“ (fehlgeschlagen = kritisch, fällig = empfohlen) und „Inhaltspakete und geschützte
Dateien“ (nutzt das Ergebnis des Selbsttests im Systemstatus; noch nicht geprüft = empfohlen). Unter Einstellungen ›
Datenschutz › Leitfaden liegt ein Vorschlagstext „Bootsprüfung Lernplattform“ (Lernstand, App-Sitzungen,
Anmeldeschutz, Protokoll 4 Jahre, Ablauf-Erinnerung mit den eingestellten Tagen) – keine Rechtsberatung, bitte
mit der Datenschutzerklärung des Themes abgleichen.

**Sicherheit:** „Vertrauenswürdige Proxys“ steht jetzt hier (vorher unter „Mobile App“) und gilt für Website und App
(Anmelde-Grenzen je IP). Gespeichert wird weiter in `bp_learning_mobile[trusted_proxies]`; die Seite „Mobile App“
verweist darauf und lässt den Wert beim Speichern unverändert. Die Warnung „Client-IP“ erscheint im Bereich
Sicherheit und im Systemstatus.

**Produktkatalog:** Anlegen/abgleichen nur noch unter „Preise“ › „Speichern und Katalog abgleichen“ (mit Haken
„Produkte dabei veröffentlichen“). „Kostenlose Bereiche“ behält nur „Schnupper-Paket anlegen“ und verlinkt auf „Preise“.

**Barrierefreiheit:**
- Status-Badges mit weißer Schrift auf dunkleren Farben, Kontrast mindestens 4,5:1 (WCAG AA): grün `#007017`,
  blau `#2271b1`, gelbbraun `#996800`, rot `#b32d2e`, grau `#646970` (`Ui::BADGE_COLORS`).
- Alle Formularfelder der Plugin-Seiten haben eine Beschriftung (`label for` oder `aria-label`), auch Filter,
  Begründungsfelder und Auswahlfelder in Tabellen.
- Text statt Symbol: „ja“/„nein“ statt ✓/✗ (Regelwerk geprüft), „vorhanden“ statt ✓ (Mediendatei); richtige
  Antworten und Pflichtbilder haben einen Text für Bildschirmleser.
