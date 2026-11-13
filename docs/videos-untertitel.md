# Echte Videos mit Untertiteln (To-do T62)

Heute verweisen alle **449 Video-Lektionen** auf das Platzhaltervideo des Laravel-Originals. Der Lernbereich zeigt
dort nur einen Hinweis. Für den Livegang brauchen die Lektionen echte Videos, für die Barrierefreiheit Untertitel
(T56).

## 1. Videos bereitstellen

- Ablage: eigener Server oder Video-Dienst (T23). Liegen die Videos fremd, lädt die Website sie erst nach Klick oder
  Einwilligung „Externe Medien“. Den Dienst in Datenschutz Nr. 7 und als Auftragsverarbeiter eintragen (T57).
- Format: MP4 (H.264/AAC), Adresse mit `https://`. Kein Einbettungs-Player (YouTube, Vimeo-Player).
- Untertitel: WebVTT-Datei (`.vtt`, UTF-8) **neben dem Video mit gleichem Namen**, z. B.
  `…/sbf-see/01-alles-zum-fuehrerschein.mp4` und `…/sbf-see/01-alles-zum-fuehrerschein.vtt`.
- Fremder Server mit Untertiteln: Er muss für die Website-Domain CORS erlauben
  (`Access-Control-Allow-Origin: https://deine-domain.de`), sonst zeigt der Browser die Untertitel nicht an.

## 2. Adressen eintragen (Konverter)

Die Video-Adressen kommen ins Content-Paket. Das Laravel-Original wird nicht geändert. Stattdessen gibt es eine
eigene Pflegedatei:

1. Vorlage aller Lektionen erzeugen:
   `php tools/content-build/bin/video-lessons.php build/<package_id> > /tmp/video_urls.vorlage.json`
2. Die Lektionen mit fertigem Video nach `tools/content-build/data/video_urls.json` übernehmen, z. B.
   `"SBF-SEE::Einführung in den Sportbootführerschein::Alles zum Führerschein": "https://…/01-alles-zum-fuehrerschein.mp4"`.
   Nur `https://`-Adressen. Schlüssel, die mit `_` beginnen, sind Kommentare.
3. Paket neu bauen (`php tools/content-build/bin/build-package.php`). Der Bericht meldet weiter, wie viele Lektionen
   noch einen Platzhalter haben, und warnt bei Schlüsseln, die es nicht gibt. Der Golden-Master-Abgleich wendet die
   Datei auch auf die Laravel-Seite an.
4. Paket im Admin importieren (Bootsprüfung › Content-Pakete).

## 3. Untertitel einschalten

Bootsprüfung › Einstellungen › „Untertitel der Lernvideos“ auf „WebVTT-Datei neben dem Video“ stellen. Erst
einschalten, wenn zu jedem eingetragenen Video eine `.vtt`-Datei vorliegt. Der Player zeigt dann die Spur „Deutsch“,
standardmäßig an. Andere Ablage der Untertitel: Filter `bootspruefung_video_captions_url` (Lektion → Adresse).

**App:** Die App-Schnittstelle liefert die Untertitel-Adresse noch nicht. Das folgt mit der nächsten App-API-Version,
sobald die App Untertitel abspielen kann.

## 4. Prüfen

- Je Kurs eine Lektion am Desktop und am Handy abspielen, Untertitel sichtbar und synchron.
- Barrierefreiheit: Untertitel enthalten auch wichtige Geräusche bzw. Sprecherwechsel.
- Rechte: Video- und Untertitel-Rechte liegen beim Betreiber (T68).
