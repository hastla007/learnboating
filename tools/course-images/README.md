# Kursfotos

Jeder Kurs hat einen Bildplatz für Kachel (Startseite) und Kopfbereich (Kursseite):
`themes/kurswasser/assets/images/courses/<kurs>.webp` (1600 px) und `<kurs>-sm.webp` (800 px).

Stand: Für die 8 Kurse SBF See, SBF Binnen, Binnen Segel, SRC, UBI, SKN, FKN und Pakete liegen
echte Fotos vor (KI-generiert, Rechte in `IMAGE-RIGHTS.md`). Kombi SBF, Kapitäns-Bundle, Kombi Funk,
Weitere Kurse, Ergänzungen, Pässe und Gruppen nutzen vorläufig gespiegelte/gezoomte Ausschnitte
daraus. Foto austauschen oder ergänzen:

1. Foto als `<bildplatz>.jpg` (mind. 1800 px breit, Querformat ca. 16:7) in den Ordner legen.
2. `python3 tools/course-images/make_placeholders.py` ausführen (Originale bleiben unangetastet).
3. Nutzungsrechte in `IMAGE-RIGHTS.md` ergänzen.

Wichtig fürs Layout: Hauptmotiv in der **rechten Bildhälfte**, links ruhige Fläche (Wasser,
Himmel) – dort liegt der Text auf einem dunklen Verlauf.

## Bildbeschreibungen (für KI-Bildgenerator oder Stocksuche)

Gemeinsamer Stil: *fotorealistisch, natürliches Licht, leicht kühle Farbstimmung, ruhiges
Wasser, keine Personen mit erkennbaren Gesichtern, keine Schrift/Logos, Querformat 16:7,
Hauptmotiv rechts, links viel ruhige Fläche.*

| Kurs (Datei) | Motiv |
|---|---|
| `sbf-see` | Motoryacht auf offener See vor Küstenlinie, leichte Dünung, Tonne/Seezeichen im Hintergrund |
| `sbf-binnen` | Sportboot mit Motor auf einem Fluss oder Kanal, Ufer mit Bäumen, Brücke oder Schleuse in der Ferne |
| `sbf-binnen-segel` | Jolle oder kleine Segelyacht auf einem Binnensee, gefüllte Segel, Ufer und Hügel im Hintergrund |
| `src` | Cockpit einer Segelyacht mit UKW-Seefunkgerät (Handmikrofon) im Vordergrund, unscharf das Meer |
| `ubi` | Steuerstand eines Binnenmotorboots mit Funkgerät, Fluss mit Fahrrinnen-Tonnen im Hintergrund |
| `skn` | Rote Handfackel / Seenotsignal über dem Wasser in der Dämmerung, Rettungsweste am Bildrand |
| `fkn` | Seenotsignalmittel (Signalrakete, Rauchtopf) geordnet auf Bootsdeck, Meer im Hintergrund |
| `pakete` | Segelyacht und Motorboot nebeneinander im Abendlicht auf ruhigem Wasser |

Noch ohne eigenes Foto (Vorschläge):

| Bildplatz | Motiv |
|---|---|
| `sbf-kombi` | Motorboot auf der Übergangszone Fluss/Küste, Seezeichen und Ufer zugleich sichtbar |
| `kapitaen` | Segelyacht und Motorboot, im Vordergrund Seekarte mit Kursdreieck und Funkgerät |
| `funk-kombi` | Zwei Funkgeräte (Seefunk und Binnenfunk) am Steuerstand, Wasser im Hintergrund |
| `weitere` / `paesse` / `ergaenzung` / `gruppen` | frei, z. B. Hafen, Lernende mit Tablet an Bord, Crew von hinten |
