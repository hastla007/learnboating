# Kurswasser als Live-Theme (To-do T25)

Entscheidung des Betreibers: Soll „Kurswasser 2.0“ das Theme der Live-Website werden? Dieses Blatt sagt, was beim
Aktivieren passiert, was vorher erledigt sein muss und wie man zurückwechselt.

## Voraussetzungen

- WordPress ab 7.0, PHP ab 8.1 (`style.css`).
- Lern-Plugin ab Version 0.13.0 mit passendem Vertrag. Ist ein älteres Plugin aktiv, zeigt das Theme einen Hinweis
  im Admin und zeigt Kurse, Preise und Prüfungsanmeldung ohne Plugin-Daten an.
- Abnahme auf Staging anhand der CI-Screenshots (T74), Rechte an den Hero-Fotos bestätigt (T74).

## Was beim Aktivieren passiert

| Was | Status | Hinweis |
|---|---|---|
| Rechtsseiten Impressum, Datenschutz, AGB, Widerruf | Entwurf | Nur wenn es die Seite noch nicht gibt. Entwürfe werden mit den Vorlagen und den Betreiberangaben gefüllt. Veröffentlichte, bearbeitete Seiten bleiben unberührt. |
| Seite Kontakt | Entwurf | Nur wenn es sie noch nicht gibt. |
| Kursseiten `/kurse/` (Übersicht, je Kurs, „Pakete & Pässe“, „Weitere Kurse“) | **veröffentlicht** | Nur fehlende Seiten. Sie sind sofort öffentlich sichtbar. |
| Einrichtungs-Assistent | – | Leitet einmal auf Design › Kurswasser einrichten. Plugins und Inhaltsseiten werden erst dort angelegt, Inhaltsseiten als Entwurf. Das Lern-Plugin ist nicht im Theme-Paket: vorher installieren oder im Assistenten „Plugin-ZIP hochladen“. |
| Cookie-Banner | aktiv | Kommt mit dem Theme; ohne Kurswasser fehlt es (siehe Rückweg). |

Bestehende Seiten, Beiträge, Produkte, Bestellungen und Lernstände ändert das Aktivieren nicht.

## Empfohlener Ablauf

1. Auf Staging aktivieren, Assistent durchlaufen, Screenshots prüfen. Liegt im Theme-Ordner noch
   `plugins/bootspruefung-learning.zip` aus einer älteren Theme-Version, zeigt der Assistent eine Warnung: die Datei
   mit „Datei löschen“ entfernen (sie wäre öffentlich abrufbar).
2. Auf Live: Shop-Modus „Demnächst verfügbar“ von WooCommerce an (Assistent Schritt 3, Häkchen „Shop sichtbar“
   nicht setzen), dann Theme aktivieren.
3. Kursseiten unter `/kurse/` sind sofort öffentlich. Wenn sie erst mit dem Shop sichtbar sein sollen, direkt
   nach dem Aktivieren auf Entwurf stellen.
4. Rechtstexte prüfen und veröffentlichen (T11, T52). Danach Shop sichtbar schalten.
5. Vor dem Sichtbarschalten: Hinweis „Inhaltsseiten aus dem Theme“ (Dashboard, Seiten) muss ohne Warnung sein –
   keine Seite „neuer Vorlagenstand“ oder „von dir bearbeitet“ offen. Prüfen per CLI:
   `wp kurswasser content-pages status` bzw. Probelauf `wp kurswasser content-pages sync --dry-run`.

## Inhaltsseiten bei Theme-Updates (To-do T59)

Die Info- und Marketingseiten aus `content/` (z. B. „So funktioniert die Lernplattform“) beschreiben Funktionen
der Lernplattform. Ändert ein Theme-Update ihre Vorlagen, gleicht das Theme **automatisch** ab:

- Beim nächsten Aufruf des Admins merkt das Theme den neuen Vorlagenstand (Fingerabdruck über Manifest und
  Seitendateien) und startet einen Lauf im Hintergrund (WP-Cron).
- **Unveränderte Seiten** bekommen den neuen Stand. Status, Eltern und vom Betreiber geänderte SEO-Felder bleiben.
- **Von dir bearbeitete Seiten** werden nicht angetastet. Sie erscheinen im Hinweis als Warnung: dort prüfen, ob
  veraltete Angaben stehen (Lernregeln, Preise, Fristen), und die Seite anpassen oder unter Design › Kurswasser
  einrichten „Alles aus dem Theme übernehmen“ wählen.
- **Neue Seiten** legt der Lauf nicht an (bewusste Entscheidung, Knopf im Hinweis). Live-Seiten, deren neue
  Vorlage Platzhalter enthält, bleiben ebenfalls stehen und werden gemeldet.
- Das Ergebnis erscheint einmal als Hinweis „Inhaltsseiten nach dem Theme-Update automatisch abgeglichen“.
- Ist Yoast aktiv und noch nicht gewählt, wohin die SEO-Daten sollen, wartet der Lauf auf diese Wahl.
- Abschalten (nur Abgleich per Knopf oder CLI): `add_filter('kurswasser_content_auto_sync_enabled', '__return_false');`

## Rückweg

Unter Design › Themes das vorherige Theme aktivieren. Was dabei bleibt:

- Alle angelegten Seiten bleiben erhalten. Seiten mit Kurswasser-Shortcodes zeigen dann den Shortcode als Text,
  z. B. `[kurswasser_course id="sbf-see"]`, `[kurswasser_offers]`, `[kurswasser_page_hero …]`. Diese Seiten auf
  Entwurf stellen.
- Das Cookie-Banner und die Banner-Kategorie „Externe Medien“ entfallen. Kurswasser schaltet die
  WooCommerce-Bestellzuordnung (Statistik-Cookies `sbjs_*`) bis zur Einwilligung ab. Ohne Kurswasser setzt
  WooCommerce diese Cookies wieder ohne Einwilligung. Das andere Theme braucht dann ein eigenes Banner, oder die
  Bestellzuordnung wird in WooCommerce abgeschaltet.
- Lernbereich, Shop, Bestellungen und Zugänge laufen unverändert über das Lern-Plugin und WooCommerce.

## Frage an den Betreiber

Kurswasser 2.0 als Live-Theme: ja oder nein? Und sollen die Kursseiten beim Aktivieren sofort öffentlich sein oder
erst mit dem Shop?
