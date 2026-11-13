# Rechnungen, Storno, B2B, E-Rechnung, Schweiz, OSS (To-do T53)

Dieses Blatt sagt, was technisch schon da ist, was ein Rechnungs-Plugin leisten muss und was mit der Steuerberatung
zu klären ist. Die Fragen dazu stehen in `docs/steuer-fragen.md` (Nr. 3, 6, 7, 8, 10, 11). Keine Steuerberatung:
Fristen und Pflichten bitte dort bestätigen lassen.

## Schon umgesetzt

| Thema | Stand |
|---|---|
| OSS-Schwelle | Lern-Plugin, `OssMonitor`: Systemstatus › „Steuer: OSS-Schwelle“ zeigt die Netto-Umsätze an Kunden in anderen EU-Ländern im laufenden und im Vorjahr, je Land, abzüglich Erstattungen. Ab 80 % der Schwelle von 10.000 € und bei Überschreitung erscheint im Dashboard eine Warnung, solange keine OSS-Sätze angelegt sind. Firmenkunden mit USt-IdNr. sind mitgezählt, weil sie ohne Rechnungs-Plugin nicht sicher erkennbar sind. Der Wert ist also eine Obergrenze. Die Summen rechnet die Datenbank direkt (HPOS-Tabellen bzw. Beitragstabellen, je nach Bestellspeicher); sie werden eine Stunde zwischengespeichert und bei jeder Status-Änderung oder Erstattung für das laufende Jahr und das Vorjahr neu berechnet (Jahr nach der Zeitzone der Website). |
| OSS-Sätze | Theme-Assistent (Design › Kurswasser einrichten, Schritt 3): „OSS“ legt die Normalsätze der 27 EU-Länder an. „Gleicher Bruttopreis“ ist vorausgewählt. |
| Schweiz | Der Systemstatus zeigt, ob die Schweiz Verkaufsland ist, mit Verweis auf die Klärung. Bis dahin die Schweiz nicht freigeben (WooCommerce › Einstellungen › Allgemein › Verkaufsländer). |
| Erstattungen | Garantie, Widerruf und Erstattung laufen über WooCommerce-Erstattungen. Diese sind die Grundlage für Storno-Belege. |
| Aufbewahrung | Bei einer Kontolöschung bleiben Bestellungen, auf Wunsch anonymisiert. Transaktionsnummer, Firma, Land und Bundesland bleiben (T45). |

## Was fehlt: ein Rechnungs-Plugin

WooCommerce erstellt keine Rechnungen. Die Bestellbestätigung ist keine Rechnung im Sinne von § 14 UStG. Das
Lern-Plugin baut bewusst keine eigene Rechnungsstellung. Diese Aufgabe gehört in ein bewährtes Rechnungs-Plugin
oder in die Buchhaltungssoftware.

Anforderungen an die Lösung:

1. **Rechnungsnummer:** fortlaufend und einmalig (§ 14 Abs. 4 Nr. 4 UStG). Die WooCommerce-Bestellnummer genügt nicht,
   weil sie Lücken hat und auch für nicht bezahlte Bestellungen vergeben wird.
2. **Pflichtangaben:** Name und Anschrift von Anbieter und Kunde, Steuernummer oder USt-IdNr., Ausstellungs- und
   Leistungsdatum, Leistung (Kursname und Laufzeit), Entgelt, Steuersatz und -betrag. Bei OSS gilt der Satz des
   Kundenlands, bei Kleinunternehmern der Hinweis nach § 19 UStG.
3. **Zeitpunkt:** bei Zahlungseingang (Status „In Bearbeitung“), als PDF an die Bestellmail angehängt und unter „Mein
   Konto“ abrufbar.
4. **Storno-Beleg (Rechnungskorrektur):** bei jeder WooCommerce-Erstattung, auch bei Teilerstattungen (Garantie,
   Widerruf, Konflikt), mit Bezug auf die ursprüngliche Rechnung.
5. **B2B:** Feld für die USt-IdNr. in der Kasse, Prüfung über VIES. Bei gültiger USt-IdNr. aus einem anderen EU-Land
   ohne Steuer und mit Hinweis „Steuerschuldnerschaft des Leistungsempfängers“ (Reverse Charge). Betrifft vor allem
   Gruppenpakete für Firmen und Vereine.
6. **E-Rechnung im Inland-B2B:** Seit 2025 müssen Unternehmen in Deutschland E-Rechnungen empfangen können. Ausstellen
   wird nach unserem Stand ab 2027 Pflicht für Unternehmen mit mehr als 800.000 € Vorjahresumsatz, ab 2028 für alle.
   Privatkunden sind nicht betroffen. Die Lösung sollte XRechnung bzw. ZUGFeRD erzeugen können.
7. **Archiv (GoBD):** Rechnungen und Storno-Belege unveränderbar archivieren, z. B. durch Export an die
   Buchhaltung oder ein revisionssicheres Archiv. Aufbewahrung nach aktuellem Stand 8 Jahre für Buchungsbelege
   (seit 2025), bitte bestätigen.
8. **Zusammenspiel mit dem Lern-Plugin:** Kein Eingriff nötig. Die Laufzeit steht schon in der Bestellmail (T52) und
   am Produkt. Wichtig: Das Plugin darf Bestellstatus und Erstattungen nicht selbst ändern, sonst laufen
   Freischaltung und Garantie anders als geplant.

Vor dem Einsatz auf Staging testen: Kauf mit Überweisung und mit PayPal, Teilerstattung über die Garantie,
Widerruf, Kontolöschung (Rechnung bleibt), Gruppenpaket mit USt-IdNr.

## Schweiz

Nach unserem Kenntnisstand müssen sich ausländische Anbieter elektronischer Dienstleistungen an Schweizer
Privatkunden in der Schweiz für die Mehrwertsteuer registrieren, sobald ihr weltweiter Umsatz 100.000 CHF erreicht.
Dann ist auch ein Steuervertreter in der Schweiz nötig. Ob und ab wann das zutrifft, klärt die Steuerberatung (Frage
6). Bis dahin die Schweiz nicht als Verkaufsland freigeben. Der Systemstatus zeigt, ob sie freigegeben ist.

## Entscheidungen für dich

- [ ] Rechnungs-Plugin bzw. Buchhaltungs-Anbindung auswählen (Anforderungen 1–8), auf Staging testen.
- [ ] Mit der Steuerberatung: Fragen 3, 6, 7, 8, 10 und 11 in `docs/steuer-fragen.md`.
- [ ] Schweiz als Verkaufsland: ja (mit Registrierung) oder nein.
- [ ] Gruppenpakete an Firmen und Vereine: mit Reverse Charge (USt-IdNr.-Feld) oder nur mit Steuer.
