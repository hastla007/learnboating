# Änderungsprotokoll – Theme Kurswasser

Format wie beim Lern-Plugin: je Version die sichtbaren Änderungen. Die Version steht in `style.css` (`Version:`) und
in `functions.php` (`KURSWASSER_VERSION`); beide müssen gleich sein (Test `tests/learning-compat-test.php`, Abschnitt
„Theme-Version“). Mit einer neuen Version laden Browser `offers.js` neu, die Rechtstext-Vorlagen werden neu geprüft und
die Inhaltsseiten abgeglichen (`docs/theme-livegang.md`). Auslieferung: `docs/installation-und-updates.md`.

## 2.1.0 – 13.11.2026

Zusammenfassung der Änderungen seit 2.0.0. Sie liefen bis hierher ohne neue Versionsnummer; ab jetzt bekommt jede
Auslieferung eine eigene Theme-Version. Benötigt das Lern-Plugin ab 0.13.0 (Versionsvertrag).

**Einrichtung und Inhaltsseiten**
- Einrichtungs-Assistent „Kurswasser einrichten“: Plugins installieren (WooCommerce, Yoast SEO, Lern-Plugin per
  „Plugin-ZIP hochladen“ oder `KURSWASSER_LEARNING_PLUGIN_ZIP`), Inhaltsseiten anlegen, Schritt 3 „Shop einrichten“,
  Shop sichtbar machen.
- Das Theme-Paket enthält das Lern-Plugin und die Markdown-Quellen der Inhaltsseiten (`content/src/`) nicht mehr
  (wären öffentlich abrufbar). Eine alte `plugins/*.zip` im Theme-Ordner meldet der Assistent mit „Datei löschen“.
- 35 Inhaltsseiten aus Vorlagen mit SEO-Angaben; Übernahme in Yoast SEO (mit Yoast SEO 28.8 geprüft); feste
  Abgleiche nach Theme-Updates (nur unveränderte Seiten).
- Startseite, Kurs-Infoseiten, Kursübersicht und „Weitere Kurse“ mit Kursfotos und Angebotskarten.

**Shop und Kasse**
- Angebotskarten mit echten Produkten, Monatswert als „kein Abo“ gekennzeichnet, Video-Upgrade auch für Gäste.
- Hinweise zu Zahlungsarten, Verkaufsländern und Lieferung vor der Kasse (Shortcode `[kurswasser_purchase_info]`).
- Block-Kasse für Gäste, Hinweise im Warenkorb, mobiler Warenkorb ohne Überlappung.
- Ergänzung FKN → SKN vorerst nicht im Verkauf (Entscheidung Betreiber).
- Livegang-Ampel vor „Shop für Kunden sichtbar machen“: Rechtstexte, Produkte, Regelwerke, Schnupper-Paket, „Zugang
  ohne Shop“, Inhaltspaket; bei Rot nur mit ausdrücklicher Bestätigung (T97).

**Admin und Technik**
- Hinweise mit Absender „Bootsprüfung:“, gebündelt und vertagbar; Rechtstext-Hinweis mit Knopf „Betreiberangaben
  ausfüllen“ (T99).
- Fingerabdruck der Inhaltsvorlagen und Kursfoto-CSS zwischengespeichert statt bei jedem Seitenaufruf berechnet;
  Adresse des Lernbereichs kommt vom Plugin statt fest `/bootsscheine/` (T105).
- Übersetzbar: Sprachordner `languages/` mit Vorlage `kurswasser.pot` (T89).

**Rechtstexte und Datenschutz**
- Vorlagen für Impressum, Kontakt, Datenschutz, AGB, Widerrufsbelehrung und Widerrufsfunktion („Vertrag hier
  widerrufen“); Platzhalter werden aus den Betreiberangaben gefüllt; an die Umsetzung angeglichen.
- Selbst gehosteter Einwilligungsbanner; Videos auf fremden Servern erst nach Klick oder Einwilligung „Externe Medien“.
- Markenname ohne öffentlichen Platzhalter „XXXX“.

**Technik**
- Versionsvertrag mit dem Lern-Plugin: Bei zu altem oder inkompatiblem Plugin arbeitet das Theme ohne Plugin weiter und
  zeigt einen Admin-Hinweis. `Update URI: false`.
- `offers.js` trägt die Theme-Version (Browser laden nach einem Update neu).
- Theme-Kopf: Autor „LearnBoating“ (Projektname, vom Betreiber festzulegen).
- Auslieferung als ZIP ohne `tests/`, `tools/`, `content/src/` und ohne Lern-Plugin (`tools/release/build-zips.sh`).

**Audit 13.11., Runde 2 (T106, T124, T126)**
- Theme-ZIP enthält kein Plugin-ZIP und keine Inhaltsquellen mehr; Hinweis auf alte ZIP-Reste im Theme-Ordner (T106).
- Icon-CSS wird zwischengespeichert (T124). Rechtstext- und Betreiberprüfung in der Livegang-Checkliste (T126).

## 2.0.0 – 06.11.2026

Neues Block-Theme „Kurswasser 2.0“ für Startseite, Shop, Kasse und Lernbereich.
