# Steuer: Fragen an die Steuerberatung (To-do T14, T53)

Keine Steuerberatung. Diese Liste sammelt, was vor dem Livegang mit der Steuerberatung zu klären ist. Die Technik
dazu ist gebaut: Der Einrichtungs-Assistent des Themes (Schritt 3 „Shop einrichten“) bietet drei Wege an und legt die
Steuersätze in WooCommerce an. Die Statusseite des Lern-Plugins warnt, wenn Steuer und Preis-Hinweis nicht
zusammenpassen.

| Weg im Assistenten | Was passiert |
|---|---|
| OSS | Steuersatz des Kundenlands für alle 27 EU-Länder, wahlweise mit gleichem Bruttopreis für alle EU-Kunden |
| Eigener Satz | ein Satz für alle Kunden, z. B. 19 % (DE) oder 20 % (AT) |
| Kleinunternehmer | keine Steuerberechnung, Hinweis „§ 19 UStG“ (DE) bzw. „§ 6 Abs. 1 Z 27 UStG“ (AT) an allen Preisen, in Kasse und Mails |

Die EU-Normalsätze im Assistenten haben den Stand 01.08.2025 (`KURSWASSER_EU_VAT_RATES_STAND` in
`themes/kurswasser/inc/setup-shop.php`). Neuere Änderungen sind nicht eingepflegt. Bitte vor dem Livegang prüfen, zum
Beispiel bei der EU-Kommission unter „VAT rates in the EU“.

## Fragen

1. **Sitz und Rechtsform:** In welchem Land sitzt das Unternehmen? Davon hängen Steuersatz, Rechtstexte (z. B.
   Österreich: FAGG, ECG, MedienG, siehe T56) und Rechnungspflichten ab.
2. **Kleinunternehmer oder Regelbesteuerung:**
   - Greift die Kleinunternehmerregelung?
   - Wie wird die Umsatzgrenze überwacht, und was passiert beim Überschreiten mitten im Jahr? Dann muss der Weg im
     Assistenten gewechselt werden.
3. **OSS:**
   - Ab wann ist die Teilnahme Pflicht? Die Schwelle liegt bei 10.000 € im Jahr für Verkäufe an Privatkunden in anderen
     EU-Ländern.
   - Soll freiwillig von Anfang an teilgenommen werden?
   - Wer überwacht die Schwelle? WooCommerce tut das nicht.
4. **Gleicher Bruttopreis für alle EU-Kunden:** Diese Option ist vorausgewählt. Passt sie zur Preisstrategie? Die
   Nettomarge sinkt dann in Ländern mit höherem Satz.
5. **Aktuelle Sätze:** Gibt es seit dem 01.08.2025 Änderungen der EU-Normalsätze?
6. **Schweiz und weitere Nicht-EU-Länder:**
   - Wird an Kunden in der Schweiz verkauft?
   - Wie sind digitale Leistungen an Schweizer Privatkunden zu behandeln? Ist eine Schweizer Registrierung nötig, und
     ist deutsche Umsatzsteuer geschuldet?
   - Bis zur Klärung die Schweiz nicht als Verkaufsland freigeben (WooCommerce › Einstellungen › Allgemein).
7. **Firmen und Vereine (Gruppenpakete):**
   - Wie sind B2B-Käufe aus anderen EU-Ländern abzurechnen (Reverse Charge, USt-IdNr. des Kunden)?
   - Braucht die Kasse ein Feld für die USt-IdNr.?
8. **Rechnungen (siehe T53):**
   - Reicht die WooCommerce-Bestellbestätigung, oder braucht es Rechnungen mit fortlaufender Nummer und Storno-Belege?
   - Ab wann gilt die E-Rechnungspflicht im B2B (Deutschland stufenweise ab 2027/2028)?
   - Wie wird GoBD-konform archiviert?
9. **Gutscheine (Gutschein-Plugin):** Einzweck- oder Mehrzweckgutschein? Davon hängt ab, ob die Steuer beim Verkauf
   oder bei der Einlösung entsteht.
10. **Geld-zurück-Garantie und Erstattungen:** Wie werden Erstattungen und Teilerstattungen steuerlich und in der
    Buchhaltung behandelt?
11. **Aufbewahrung bei Kontolöschung:** Welche Bestellangaben müssen nach einer Kontolöschung aufbewahrt werden, und wie
    lange (z. B. Firma, Land, USt-IdNr.; siehe T45)?
