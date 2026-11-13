# Barrierefreiheit (BFSG) und Rechtstexte Österreich (To-do T56)

Keine Rechtsberatung. Beide Fragen klärt der Anwalt. Dieses Blatt sammelt die Fakten aus der Umsetzung und Entwürfe.

## 1. Gilt das Barrierefreiheitsstärkungsgesetz (BFSG)?

Seit 28.06.2025 müssen Dienstleistungen im elektronischen Geschäftsverkehr an Verbraucher barrierefrei sein. Dazu
gehören Online-Shops und kostenpflichtige Online-Kurse. Nach unserem Kenntnisstand gilt eine Ausnahme für
**Kleinstunternehmen**, die Dienstleistungen anbieten: weniger als 10 Beschäftigte **und** Jahresumsatz oder
Jahresbilanzsumme höchstens 2 Mio. €.

**Entscheidung Betreiber/Anwalt:**
- [ ] Kleinstunternehmen? Dann gilt die Pflicht nicht. Eine freiwillige Erklärung (Entwurf unten) schadet nicht und
  hilft Kundinnen und Kunden mit Einschränkungen.
- [ ] Kein Kleinstunternehmen? Dann ist die Erklärung Pflicht, und die offenen Punkte in Abschnitt 3 müssen vor dem
  Livegang erledigt sein.
- [ ] Sitz in Österreich: Dort gilt das Barrierefreiheitsgesetz (BaFG) mit ähnlicher Ausnahme. Bitte mitprüfen.

## 2. Stand der Umsetzung (automatisch geprüft)

Prüfung mit axe-core 4.10 (Regeln WCAG 2.0/2.1 Stufe A und AA) am 13.11.2026 auf der Vorschau mit Theme Kurswasser,
jeweils am Desktop (1280 px) und am Handy (390 px):

- Als Gast: Startseite, Kursübersicht, Kursseite SBF See, Kasse, Anmeldung, Lernbereich (Anmeldehinweis).
- Angemeldet: Übersicht, Kursseite, Smarttrainer, Prüfungssimulation, Mein Konto.

Ergebnis: 22 Seitenaufrufe, nach einer Korrektur **0 Verstöße**. Gefunden und behoben (T56) wurde zu schwacher
Kontrast bei gesperrten Prüfungsbögen und gesperrten PDFs.

Was schon in der Umsetzung steckt (aus früheren Runden): Bedienung mit Tastatur, sichtbarer Fokus, Bezeichnungen
für Screenreader (ARIA), Statusmeldungen als `role="status"`, Darstellung bis 390 px ohne waagerechtes Scrollen
(Browser-Test).

**Grenzen:** Ein automatischer Test findet etwa ein Drittel der möglichen Barrieren. Nicht geprüft sind z. B.
verständliche Sprache, sinnvolle Alternativtexte bei Abbildungen der Prüfungsfragen und Navigationsaufgaben,
Bedienung mit Screenreader von Anfang bis Ende, Funkgerät-Simulator (Original-Skript der Quelle) und die Kasse der
Zahlungsanbieter (fremde Seiten).

## 3. Offene Punkte vor einer Pflicht-Erklärung

- [ ] **Videos:** Untertitel (WebVTT) für alle Lernvideos (T62). Heute stehen dort Platzhalter.
- [ ] **PDFs:** barrierefreie PDFs mit Tags und Lesereihenfolge (T70).
- [ ] **Abbildungen:** Alternativtexte der Fragen-Abbildungen im Content-Paket prüfen (Stichprobe je Kurs).
- [ ] **Funkgerät-Simulator:** Bedienung per Tastatur und Screenreader prüfen. Falls nicht machbar, in der Erklärung
  als Einschränkung nennen.
- [ ] **Manueller Test:** einmal mit NVDA (Windows) oder VoiceOver (iPhone): Registrierung, Kauf, Smarttrainer,
  Prüfungssimulation.

## 4. Entwurf Barrierefreiheitserklärung

Als eigene Seite (z. B. „Barrierefreiheit“, Fußzeile neben Impressum) anlegen. Platzhalter in `[[…]]`.

> **Erklärung zur Barrierefreiheit**
>
> [[Betreiber: Firma]] bietet auf [[Betreiber: Domain]] Online-Kurse zur Vorbereitung auf Sportbootführerscheine,
> Funkzeugnisse und Seenotsignalmittel an. Die Kurse kaufst du im Shop und nutzt sie im Lernbereich im Browser oder
> in unserer App.
>
> **So setzen wir Barrierefreiheit um:** Website und Lernbereich sind mit der Tastatur bedienbar, haben einen
> sichtbaren Fokus, Bezeichnungen für Screenreader und ausreichende Kontraste. Sie passen sich an kleine Bildschirme
> und Vergrößerung an. Die wichtigsten Seiten haben wir am [[Datum]] mit einem automatischen Prüfwerkzeug nach den Web
> Content Accessibility Guidelines (WCAG) 2.1, Stufe AA, geprüft [[Betreiber: und manuell mit Screenreader, falls
> erfolgt]].
>
> **Noch nicht barrierefrei:** [[Betreiber: nach Abschnitt 3 eintragen, z. B. „Einige Lernvideos haben noch keine
> Untertitel“, „Der Funkgerät-Simulator ist nur eingeschränkt mit Screenreader bedienbar“ – mit geplantem Datum der
> Behebung]]
>
> **Barriere melden:** Stößt du auf eine Barriere, schreib uns an [[Betreiber: E-Mail]]. Wir antworten innerhalb von
> [[Betreiber: Frist, z. B. 14 Tagen]].
>
> **Marktüberwachung:** Zuständig ist [[Anwalt: zuständige Marktüberwachungsbehörde mit Anschrift, in Deutschland
> nach unserem Stand die Marktüberwachungsstelle der Länder für die Barrierefreiheit von Produkten und
> Dienstleistungen (MLBF) in Magdeburg; in Österreich das Sozialministeriumservice]].
>
> Stand: [[Datum]]

## 5. Rechtstexte für Österreich (nur bei Sitz in Österreich)

Die Vorlagen in `themes/kurswasser/legal/` sind auf einen Anbieter mit Sitz in Deutschland ausgerichtet (BGB,
TDDDG, MStV). Bei Sitz in Österreich muss der Anwalt mindestens Folgendes anpassen:

| Text | Was sich ändert (Stichworte für den Anwalt) |
|---|---|
| Impressum | Angaben nach § 5 ECG, § 14 UGB und Offenlegung nach § 25 MedienG statt § 5 DDG und § 18 MStV; Kammer bzw. Gewerbebehörde, Firmenbuch |
| Widerrufsbelehrung | Fern- und Auswärtsgeschäfte-Gesetz (FAGG) statt BGB; Muster-Widerrufsformular; Erlöschen bei digitalen Inhalten nach § 18 FAGG |
| AGB | Konsumentenschutzgesetz (KSchG), Gewährleistung nach VGG, Gerichtsstand und anwendbares Recht |
| Datenschutz | Aufsichtsbehörde Datenschutzbehörde (DSB) Wien; § 165 TKG 2021 statt § 25 TDDDG für Cookies |
| Steuer | österreichische Umsatzsteuer (20 %), OSS über das österreichische Finanzamt (siehe T14, T53) |

Die technische Umsetzung, also Platzhalter, Betreiberangaben, Widerrufsfunktion, Garantie und Datenschutz-
Werkzeuge, bleibt gleich. Getauscht werden nur die Texte.
