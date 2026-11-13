# Texte zur rechtlichen Prüfung

Keine Rechtsberatung. Diese Datei sammelt Texte, die Betreiber und Anwalt vor dem Livegang prüfen sollen, mit
heutigem Wortlaut, Fundstelle und offener Frage. Weitere Punkte: T52 (AGB/Datenschutz an Umsetzung angleichen),
T56 (Barrierefreiheit, Österreich), T72 (Widerruf und Garantie).

## T36 a) Ablauf-Erinnerung in der Datenschutzerklärung

**Fundstelle:** Vorlage `themes/kurswasser/legal/datenschutz.html`, Abschnitt 8 „E-Mails“.

> Kurz bevor ein gekaufter Lernzugang abläuft, erinnern wir dich per E-Mail an das Ablaufdatum, mit einem Link zum
> Verlängern. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO in Verbindung mit § 7 Abs. 3 UWG; unser berechtigtes
> Interesse ist, dich als Kundin oder Kunden rechtzeitig auf das Ende deines Zugangs hinzuweisen. Du kannst diesen
> Erinnerungen jederzeit widersprechen – über den Link in jeder Erinnerung oder in deinem Konto unter „Mein
> Lernen“ –, ohne dass dir dafür andere als die Übermittlungskosten nach den Basistarifen entstehen. Weitere
> Werbe-E-Mails oder einen Newsletter versenden wir nicht.

**Wie es technisch umgesetzt ist:**
- Die Erinnerung geht je Zugang einmal raus, standardmäßig 14 Tage vor Ablauf (einstellbar 1 bis 90 Tage, ganz
  abschaltbar unter Bootsprüfung → Einstellungen).
- Jede Erinnerung enthält einen Abmeldelink (signiert, mit Bestätigungsknopf) und die Kopfzeile
  `List-Unsubscribe`.
- Unter „Mein Lernen“ gibt es einen Schalter zum Widersprechen und Wieder-Einschalten.
- Die Mail ist sachlich: „Zugang läuft am … ab“, Verlängern nur als Link.

**Seit T44 behoben:**
- Die Erinnerungsmail geht nur an Käufer, also bei einem Zugang aus einer eigenen, bezahlten Bestellung über 0 €.
  Gruppenmitglieder, manuell Freigeschaltete und Gutschein-Einlöser bekommen keine Mail, sehen den Hinweis aber unter
  „Mein Lernen“.
- Der Hinweis auf Erinnerung und Widerspruch steht schon bei der Erhebung der E-Mail-Adresse: in der Kasse (mit
  Lernprodukt) und bei der Registrierung (WooCommerce „Mein Konto“ und wp-login). Wortlaut: „Kurz bevor ein gekaufter
  Zugang abläuft, erinnern wir dich per E-Mail daran. Du kannst dem jederzeit widersprechen – über den Link in jeder
  Erinnerung oder unter „Mein Lernen“ –, ohne dass dir dafür andere als die Übermittlungskosten nach den
  Basistarifen entstehen.“

**Bitte prüfen:**
1. Trägt § 7 Abs. 3 UWG die Erinnerung? Ist sie Werbung oder Vertragsinformation?
2. Wortlaut des Hinweises bei der Erhebung der E-Mail-Adresse (Kasse, Registrierung), siehe oben.

## T36 b) Hinweis „Lieferung: digital, kein Versand – Zugang nach Zahlungseingang“

**Fundstelle:** Lern-Plugin, `src/Commerce/WooCommerce/PurchaseInfo.php`. Kleiner Hinweis unter dem Kaufknopf der
Produktseite und unter der Summe im Warenkorb, nur bei rein digitalen Lernprodukten. Vollständig, zum Beispiel:

> Zahlungsarten: PayPal, Überweisung · Verkaufsländer: EU-Staaten, Schweiz · Lieferung: digital, kein Versand –
> Zugang nach Zahlungseingang

Zahlungsarten und Verkaufsländer kommen aus den WooCommerce-Einstellungen.

**Bitte prüfen:**
1. Reicht der Wortlaut als Angabe zu Lieferung und Zugang (Beginn des Verkaufsvorgangs, § 312j Abs. 1 BGB)?
2. Stimmt „nach Zahlungseingang“ für alle Zahlungsarten? Bei Überweisung kommt der Zugang erst mit dem
   Zahlungseingang. Bei PayPal, Karte und Klarna schaltet die Bestätigung des Zahlungsanbieters frei.
3. Muss der Hinweis zusätzlich auf Kursseiten bzw. in der Fußzeile stehen (siehe T38)?
