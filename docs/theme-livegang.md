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
| Einrichtungs-Assistent | – | Leitet einmal auf Design › Kurswasser einrichten. Plugins und Inhaltsseiten werden erst dort angelegt, Inhaltsseiten als Entwurf. |
| Cookie-Banner | aktiv | Kommt mit dem Theme; ohne Kurswasser fehlt es (siehe Rückweg). |

Bestehende Seiten, Beiträge, Produkte, Bestellungen und Lernstände ändert das Aktivieren nicht.

## Empfohlener Ablauf

1. Auf Staging aktivieren, Assistent durchlaufen, Screenshots prüfen.
2. Auf Live: Shop-Modus „Demnächst verfügbar“ von WooCommerce an (Assistent Schritt 3, Häkchen „Shop sichtbar“
   nicht setzen), dann Theme aktivieren.
3. Kursseiten unter `/kurse/` sind sofort öffentlich. Wenn sie erst mit dem Shop sichtbar sein sollen, direkt
   nach dem Aktivieren auf Entwurf stellen.
4. Rechtstexte prüfen und veröffentlichen (T11, T52). Danach Shop sichtbar schalten.

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
