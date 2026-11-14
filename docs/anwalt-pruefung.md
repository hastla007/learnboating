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

## T52 Rechtstexte an die Umsetzung angeglichen (Review LIVE-06 bis LIVE-10)

Geändert in den Vorlagen `themes/kurswasser/legal/` und im Lern-Plugin. Bitte jeweils den Wortlaut prüfen.

**a) AGB Nr. 3 (Kundenkonto)**
- Kontolöschung in Selbstbedienung („Meine Daten“, App, E-Mail). Gelöscht wird erst nach Klick auf „Konto jetzt
  löschen“ auf der Seite des Bestätigungslinks. Gekaufte Zugänge enden auch vor Laufzeitende.
- Neu: Login-Sperre für 15 Minuten nach mehreren Fehlversuchen, aufhebbar über „Passwort vergessen“.
- Frage: Reicht der Hinweis „Zugänge enden mit der Löschung“, oder braucht es eine ausdrückliche Bestätigung,
  dass kein Anspruch auf anteilige Erstattung besteht?

**b) Datenschutz Nr. 5, 7, 10 und 17**
- Nr. 5: Schutz vor Anmeldeversuchen (Zähler als Hash, 15 Minuten), Cookie `bp_known_device` (180 Tage), Art. 6
  Abs. 1 lit. f DSGVO, § 25 Abs. 2 Nr. 2 TDDDG.
- Nr. 7: neuer Absatz „App“ (Sitzungsdaten, Laufzeiten 30/90 Tage, Einstellungen im Konto). Platzhalter für die
  Dienste in der App selbst und die Stores (siehe T57).
- Nr. 10: neue Einträge `bp_known_device`, Prüfungspuffer `bp-exam-…` (localStorage, nur bis zur Bestätigung durch
  den Server), WooCommerce-Kasse (`storeApiNonce`, `storeApiCartHash`, `WOOCOMMERCE_CHECKOUT_IS_CUSTOMER_DATA_DIRTY`).
- Nr. 17: Ablauf über „Meine Daten“ bzw. App, Löschung erst per Knopf, was gelöscht wird und was in Bestellungen
  bleibt. Der Platzhalter dort hängt von der WooCommerce-Einstellung „Persönliche Daten in Bestellungen bei
  Löschanfragen entfernen“ ab (T45).

**c) Bestellmail: Laufzeit je Position und Garantie-Erklärung (LIVE-09, LIVE-10)**
- Kundenmails zur Bestellung (wartend, in Bearbeitung, abgeschlossen, Rechnung) enthalten den Abschnitt „Laufzeit
  deiner Lernzugänge“. Je Lernprodukt steht dort die Laufzeit, vor Zahlungseingang „6 Monate ab Zahlungseingang“,
  danach mit Enddatum „6 Monate, bis 13.05.2027“. Dazu der Satz „kein Abo“.
- Bei Produkten mit Garantie folgt die Garantie-Erklärung. Sie nennt die begünstigten Produkte, Frist und Grenze,
  den Verweis auf AGB Nr. 9 und den Garantiegeber: Name, Anschrift und E-Mail aus Design › Betreiberangaben, sonst
  Website-Name und Shop-Adresse. Außerdem steht dort, dass Widerrufsrecht und gesetzliche Mängelrechte unberührt
  bleiben. Derselbe Hinweis steht unter „Mein Lernen“.
- Frage: Genügt das den Anforderungen an eine Garantieerklärung auf einem dauerhaften Datenträger (§ 479 BGB)?

**d) Garantie-Ausnahme Simulator (LIVE-09)**
- „Mein Lernen“ nennt jetzt wie AGB Nr. 9 nur die *eigenständigen* Simulator-Zugänge (Simulator-Pässe und
  Simulator-Upgrade) als ausgenommen.

**e) Ergänzung FKN → SKN (LIVE-07)**
- Produkttext, FAQ und Kursübersicht versprechen keine verkürzte Ergänzungsprüfung mehr. Der Kurs enthält den
  vollständigen SKN-Fragenkatalog, die Anrechnung nennt der Prüfungsausschuss.
- **Entscheidung Betreiber:** Das Produkt hat denselben Inhalt und Preis (13,90 €) wie der SKN Vollkurs. Ausblenden
  oder behalten? Produkttexte im Live-Shop werden beim Anlegen geschrieben; bestehende Produkte bitte über den
  Assistenten aktualisieren oder von Hand anpassen.

**f) Zeitzone (LIVE-08)**
- Uhrzeit der Zustimmung in der Mail und Ende der Widerrufsfrist nutzen die Zeitzone der Website. Die Prüfung, dass
  sie auf Europe/Berlin steht, gehört zu T55.
