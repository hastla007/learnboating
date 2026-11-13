# Preise prüfen (To-do T16)

Diese Seite ist eine Rechenhilfe; die Preise selbst ändert sie nicht. Preise entscheidest du; eingetragen werden sie
im Admin unter Bootsprüfung › Preise („Speichern und Katalog abgleichen“).

Grundlage sind die Katalogpreise im Code (`src/Commerce/ShopCatalog.php`, Stand 13.11.2026). Eigene Preise aus der
Preis-Seite gehen vor; dann bitte mit den tatsächlichen Preisen nachrechnen.

## Gruppenpakete je Platz

Gruppenpakete enthalten den Videokurs. Der Vergleich nimmt deshalb die Einzelpreise mit Videokurs: bei SBF See und
Binnen die Stufe „Komplett“, bei den Kombis den Kombipreis, der den Videokurs schon enthält.

| Paket | Plätze | Preis | je Platz | Einzelpreis mit Videokurs | Ersparnis je Platz |
|---|---|---|---|---|---|
| Crew-Ticket: SBF See (2 Pers.) | 2 | 68,90 € | 34,45 € | 40,80 € | 16 % |
| Crew-Ticket: SBF Binnen (2 Pers.) | 2 | 66,90 € | 33,45 € | 39,70 € | 16 % |
| Crew-Ticket: Kombi See/Binnen (2 Pers.) | 2 | 103,90 € | 51,95 € | 60,80 € | 15 % |
| Crew-Ticket: Funk SRC/UBI (2 Pers.) | 2 | 79,90 € | 39,95 € | 46,80 € | 15 % |
| Familien-Flotte: SBF See (3 Pers.) | 3 | 98,90 € | 32,97 € | 40,80 € | 19 % |
| Familien-Flotte: Kombi See/Binnen (3 Pers.) | 3 | 148,90 € | 49,63 € | 60,80 € | 18 % |
| Familien-Flotte: Kombi See/Binnen (4 Pers.) | 4 | 187,90 € | 46,98 € | 60,80 € | 23 % |
| Skipper-Crew 5: SBF See | 5 | 157,90 € | 31,58 € | 40,80 € | 23 % |
| Skipper-Crew 5: Kombi See/Binnen | 5 | 237,90 € | 47,58 € | 60,80 € | 22 % |
| Skipper-Crew 5: Funk SRC/UBI | 5 | 181,90 € | 36,38 € | 46,80 € | 22 % |
| Yacht-Crew 7: SBF See | 7 | 216,90 € | 30,99 € | 40,80 € | 24 % |
| Yacht-Crew 7: Kombi See/Binnen | 7 | 316,90 € | 45,27 € | 60,80 € | 26 % |
| Flottille 10: SBF See | 10 | 294,90 € | 29,49 € | 40,80 € | 28 % |
| Flottille 10: Kombi See/Binnen | 10 | 424,90 € | 42,49 € | 60,80 € | 30 % |
| Vereins-Paket 20: SBF See | 20 | 559,90 € | 27,99 € | 40,80 € | 31 % |
| Vereins-Paket 20: Kombi See/Binnen | 20 | 789,90 € | 39,49 € | 60,80 € | 35 % |
| Partner-Flat 50: Kombi See/Binnen | 50 | 1.725,00 € | 34,50 € | 60,80 € | 43 % |

## Auffälligkeiten

1. **Kombi See/Binnen: 4 Plätze sind je Platz günstiger als 5 Plätze.**
   - „Familien-Flotte (4 Pers.)“ kostet 46,98 € je Platz, „Skipper-Crew 5“ dagegen 47,58 € je Platz.
   - Wer 5 Personen anmelden will, fährt mit 4 + 1 einzeln trotzdem nicht günstiger (187,90 € + 60,80 € = 248,70 €
     statt 237,90 €). Das Größere wirkt aber teurer je Kopf.
   - Vorschlag zur Entscheidung: Skipper-Crew 5 Kombi auf höchstens 234,90 € (46,98 € je Platz) senken, oder die
     4er-Flotte anheben.
   - Die Inhaltsseite „Angebote für Bootsschulen und Vereine“ vermeidet deshalb die Aussage „je größer, desto
     günstiger“.
2. **Kleine Pakete sparen wenig:** 2 Plätze SBF See sparen 16 % gegenüber zwei Einzelkursen „Komplett“, kaufen aber
   zwei „Theorie“-Kurse (2 × 28,90 € = 57,80 €) günstiger, wenn der Videokurs nicht gebraucht wird. Das ist kein
   Fehler, sollte aber bewusst so sein.
3. **Funk-Gruppen** enthalten wie der Funk-Kombikurs keinen Funkgerät-Simulator (kostenlose Testversion bleibt).

## Einzelkurse und Bundles (Katalog)

| Produkt | Theorie | Komplett mit Videokurs | Laufzeit |
|---|---|---|---|
| SBF See | 28,90 € | 40,80 € | 12 Monate |
| SBF Binnen | 27,80 € | 39,70 € | 12 Monate |
| SBF Binnen unter Segel | 18,90 € | 30,80 € | 12 Monate |
| SRC | 23,90 € | 31,80 € | 12 Monate |
| UBI | 23,90 € | 31,80 € | 12 Monate |
| SKN | 13,90 € | 18,80 € | 6 Monate |
| FKN | 13,90 € | 18,80 € | 6 Monate |
| Ergänzungskurse (je) | 13,90 € | 18,80 € | 6 Monate |
| SBF See & Binnen (Kombi, mit Videokurs) | 60,80 € | – | 12 Monate |
| SRC & UBI (Kombi, mit Videokurs) | 46,80 € | – | 12 Monate |
| Kapitäns-Bundle (SBF See + Binnen + Funk) | 88,70 € | – | 12 Monate |
| 1-Monats-Pass (alle Inhalte) | 16,90 € | – | 30 Tage |
| 3-Monats-Pass (alle Inhalte) | 34,90 € | – | 90 Tage |

Die Simulator-Pässe und das Simulator-Upgrade stehen im Code und auf der Preis-Seite.
