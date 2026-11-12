=== Bootsprüfung Lernplattform ===
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.13.0
WC requires at least: 11.2
WC tested up to: 11.3

Lernplattform für Bootsführerscheine mit Fragenkatalog, Prüfungssimulation, Spezialtrainern und WooCommerce-Freischaltung.

== Description ==

Stand 0.11.0 (Konzept Kap. 11, Schritte 3–9c):

* Content-Import: ZIP-Upload oder `wp bootspruefung import <paket>`, Prüfung (Prüfsummen, Schemata, Querverweise), Dry-Run, Freigabe, Import in Teilschritten, Revisionen, Archivierung, Protokoll; Rollback auf einen früheren Stand (`wp bootspruefung rollback`)
* Content-Browser: Übersicht, Kurse & Module, Fragen, Medien, Prüfungsregeln, Rechtefreigabe mit Begründung und Audit-Log
* Lernbereich unter /bootsscheine/: Kursübersicht, Smarttrainer, Modi Neu/Falsch/Merkliste, Modulfilter, offene Fragen
* Favoriten & Fehler (/bootsscheine/<kurs>/merkliste/): Favoriten in eigene Sammlungen je Kurs einsortieren (nur gemerkte Fragen), jede Sammlung gezielt lernen; Fehlerliste; Smarttrainer-Seite (/<kurs>/smarttrainer/, früher „Fragen nach Themen“, /themen/ leitet weiter) mit Fortschritt je Thema
* PDF-Dateien je Lernbereich: In der Mediathek eine PDF mit „Lernzugang erforderlich: Kurs · Navigationstrainer / Prüfungssimulation / Praxistrainer / Funk“ markieren – sie erscheint oben auf der jeweiligen Lernseite (Titel, Beschriftung als Kurztext, Reihenfolge); Kombikurse (SRC + UBI, SBF See + Binnen) zeigen auch die PDFs ihrer Teilkurse; „Anzeige im Lernbereich: Funk – auch auf der Vokabelseite“ für das Vokabel-PDF. Herunterladen nur mit vollem Zugang, sonst gesperrt mit Kaufangebot; Seekarten (D49) bleiben in ihrer eigenen Anzeige
* Funk (SRC, UBI, Kombis): Seiten „Muster-Funksprüche“ (je Meldungsart ein vollständiges Beispiel aus den Funkpraxis-Szenarien, Teilumfang beachtet) und „Internationale Buchstabiertafel“ (Alphabet der Quelle mit Aussprachehilfe aus den Vokabeln, Ziffern SRC englisch/UBI deutsch, Ausprobieren, Vorlesen per Browser-Sprachausgabe); PDF-Anzeigeort wählbar
* Prüfung anmelden: Prüfungsausschüsse mit Deutschlandkarte (Bundesländer aus Natural Earth, gemeinfrei, lokal unter assets/maps; Zuordnung aus den Namen der Ausschüsse, neu erzeugbar mit tools/maps/make_de_states.py)
* Spezialtrainer: Navigationsaufgaben, Praxiskarten, Videokurs (Fortschritt, Schrittgalerien, Quiz), Funktraining (SRC-Seefunktexte mit Auswertung, UBI-Aufnahme/-Abgabe, Vokabeln, Buchstabiertafel), Funkpraxis-Szenarien mit Auswertung, Gerätesimulation (Original-JavaScript der Quelle unter assets/device-sim; kostenlose Testversion mit Geräte-Lektionen und einem Routineanruf, Vollversion über Simulator-Pässe 14 Tage/3/12 Monate, Kapitäns-Bundle, 1-/3-Monats-Pass oder das Checkout-Upgrade zu einem Funkkurs)
* Prüfungssimulation: Zufallsprüfung nach Regelwerk, feste Bögen, Zeitlimit, Teilgrenzen, Ergebnis mit Rückblick
* REST unter /wp-json/bootspruefung/v1/ (Status, Import-Schritte, Kurse, Fragen, Antworten, Merkliste, Prüfungen)
* WooCommerce: Produktfeld „Lernzugang“ (Kurse × Funktionen, Dauer, Verlängerung, Erstattungsregel, Variationen), Freischaltung bei bezahlter Bestellung unabhängig vom Gateway (Überweisung, Klarna, PayPal, Stripe, weitere), Aussetzung bei Dispute, Widerruf bei Erstattung/Storno je Position, Kundenkonto Pflicht, „Mein Lernen“ in Mein Konto, Kurslink in Bestätigung und E-Mail, Bestellaktionen, Abgleich `wp bootspruefung reconcile`; HPOS und Checkout-Blocks
* Membership: kostenlose Teilumfänge (n Fragen je Modul, n Prüfungsbögen, erste n Lektionen/Aufgaben/Übungen), zeitgesteuerte Freigabe von Modulen und Videokapiteln, Seiten/Beiträge per Metabox, Abschnitte per Block „Nur mit Lernzugang“ oder Shortcode [bootspruefung_zugang], geschützte Dateien (PDF) über /bootsscheine/datei/<id>/, Gruppenlizenzen (Plätze je Paket, Einladung per Link oder E-Mail, Verwaltung unter /bootsscheine/gruppen/), Mitgliederrabatte im Shop
* Prüfung anmelden (vorbereitet, standardmäßig deaktiviert): Anleitung je Prüfung wie die Quelle (Gebühr, Fristen, Mindestalter, Unterlagen, Prüfungsformen mit Umfang und Bestehensgrenze, Prüfungsteile, FAQ, DSV/DMYV) und alle Prüfungsausschüsse mit Suche; Lernende melden sich selbst an
* FAQ-Sektion (Bootsprüfung › FAQ): eigener Beitragstyp `bp_faq` mit Rubriken (Allgemein und je Schein bzw. Produkt, Schlüssel wie `SBF-SEE`, `SRC-UBI`, `KAPITAEN`) und Unterkategorien, Reihenfolge je Ebene, ★ „Im Theme anzeigen“ und Redaktionshinweis (nur Admin); 92 Standard-FAQs werden bei Aktivierung bzw. Update angelegt, solange es keine FAQ gibt (Platzhalter in eckigen Klammern oder Redaktionshinweis ohne ★ → Entwurf); bei einem neuen Datenstand (FaqImporter::VERSION) werden unveränderte Standard-FAQs auf den neuen Text gebracht, vom Betreiber geänderte nie (Prüfsumme in _bp_faq_default_hash); Knopf „Fehlende Standard-FAQs importieren“ (ändert vorhandene FAQs nie) und „FAQ-Seite anlegen“ (/faq-bootsfuehrerschein/, nutzt eine vorhandene Seite mit dem Shortcode); Ausgabe per Shortcode [bootspruefung_faq] (Rubrik-Navigation, Suche, `<details>`, JSON-LD FAQPage; Attribute rubric, theme_only, subcategory, title, search, schema – schema="0" ohne FAQPage für Auszüge), Theme-Funktion `bootspruefung_faqs()` und REST `GET /wp-json/bootspruefung/v1/faqs?rubric=&theme_only=` (nur veröffentlichte FAQs); Platzhalter {{agb}} = Link auf die AGB-Seite
* Tipps & Tricks (/bootsscheine/tipps/, im Admin pflegbar), Seekarten (BSH) per Upload, Sammelfreigaben für Rechte und Prüfungsregelwerke, korrigierte Gebühren in den Prüfungsanleitungen
* Shop-Katalog (17 Produkte, davon 12 Kurse in zwei Stufen „Theorie“ und „Komplett mit Videokurs“ plus 12 nicht gelistete Video-Upgrades, 17 Gruppenpakete, 4 Simulator-Zugänge; Preise, Laufzeiten; Preise ohne Code änderbar unter Bootsprüfung → Preise, mit Katalogabgleich). Video-Upgrade im Checkout und im Lernbereich nur zur passenden Theorie-Stufe (`VideoUpgrade`); Kombis, Bundle, Pässe und Gruppen enthalten den Videokurs und Schnupper-Paket per Knopf oder `wp bootspruefung shop setup`; Geld-zurück-Garantie je Produkt (Tage, Lernstand-Grenze) mit Anfrage unter „Mein Lernen“
* Checkout-Block: Gäste mit Lernprodukt sehen statt Bestellknopf Konto-Hinweis mit Anmeldung/Registrierung und kehren danach zur Kasse zurück; Rückkehr nach Registrierung auch für Gruppeneinladungen; `reconcile`-Vorschau zeigt Gruppenänderungen; Systemstatus nennt, was ins Backup gehört
* Downloads (PDF) je Kurs, Zuordnungsfehler in der Admin-Übersicht, Dispute-Webhooks für PayPal und gateway-neutral (Klarna u. a.)
* Zugang: kostenlose Bereiche, manuelle Freigaben (mit Begründung) und Shop-Freischaltungen; solange der Shop nicht live ist, wahlweise alle angemeldeten Nutzer
* Datenbankschema mit InnoDB und Fremdschlüsseln, Migration bei Aktivierung/Update, Datenschutz-Export/-Löschung, sichere Deinstallation
* Shortcode [bootspruefung] für einen Einstiegslink
* Kursübersicht: passende Ergänzungen als „Empfohlen“, bereits abgedeckte Kurse ausgeblendet (Tabelle `CourseRelations`, Filter `bootspruefung_course_relations`); Hinweis und tägliche E-Mail-Erinnerung vor Ablauf eines Zugangs (abschaltbar, Tage einstellbar); Warenkorb und Kasse (klassisch und Block) weisen angemeldete Kunden auf Kurse hin, die ein besessener Kurs schon abdeckt, und bieten die günstigere Alternative mit Tausch-Knopf an, z. B. Ergänzung Binnen → See statt Kombi (`CartRelationHint`, Filter `bootspruefung_cart_relation_hint`)

Noch offen: Content-Freigabe und Go-live (Schritt 10).
