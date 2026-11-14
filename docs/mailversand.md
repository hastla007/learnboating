# Mailversand live (To-do T54)

Die Plattform verschickt Bestellbestätigungen mit Rechtstexten, Passwort-Links, Bestätigungen für Datenexport und
Kontolöschung, Gruppeneinladungen, Ablauf-Erinnerungen und Garantie-Bestätigungen. Kommen sie nicht an oder landen im
Spam, fehlen Kunden Zugang, Belege und Fristen. Prüfen unter **Bootsprüfung › Systemstatus › E-Mail-Versand**.

## Schon umgesetzt

- **Ein Absender für alles:** Die Mails des Lern-Plugins nutzen den Absender aus WooCommerce › Einstellungen ›
  E-Mails. Seit T54 gilt das auch für die WordPress-eigenen Mails (Passwort, Datenschutzanfragen) statt
  „WordPress &lt;wordpress@…&gt;“. Eigene Einstellungen eines SMTP-Plugins bleiben unverändert.
- **Systemstatus „E-Mail-Versand“:** Absender, Absender-Domain (Warnung, wenn sie von der Website-Domain abweicht),
  Versandweg (SMTP-Plugin, Mail-Dienst oder PHP `mail()`), SPF- und DMARC-Eintrag aus dem DNS, letzte Testmail.
- **Knopf „Testmail senden“** an die eigene Adresse, mit Fehlermeldung, falls der Versand scheitert.
- **Anbieter-Angaben im Fuß (T58):** Die Mails des Lern-Plugins an Kunden (Gruppeneinladung, Ablauf-Erinnerung,
  Bestätigung der Geld-zurück-Anfrage, Testmail) enden mit einem einheitlichen Fuß nach „-- “: Name bzw. Firma,
  Anschrift, E-Mail. Mit dem Theme Kurswasser kommen die Werte aus **Design › Betreiberangaben** (Firma, Straße,
  PLZ/Ort, Land; dazu Vertretung und Register, wenn eingetragen). Ohne vollständige Betreiberangaben: Website-Name,
  Shop-Adresse (WooCommerce › Einstellungen › Allgemein) und Absenderadresse. Interne Mails an den Betreiber haben
  keinen Fuß. Anpassen über den Filter `bootspruefung_mail_footer`. Die Bestellmails von WooCommerce haben ihren
  eigenen Fuß (WooCommerce › Einstellungen › E-Mails › „Fußzeilentext“) – dort dieselben Angaben eintragen.

## Schritte vor dem Livegang (Betreiber bzw. Hoster)

1. **Absender festlegen:** eine echte Adresse der eigenen Domain, z. B. `kurse@deine-domain.de`. Antworten darauf
   müssen jemanden erreichen. Eintragen unter WooCommerce › Einstellungen › E-Mails (Name und Adresse).
2. **Versandweg:** kein PHP `mail()` des Webservers. Stattdessen ein SMTP-Konto des Mail-Hosters oder ein Mail-Dienst,
   eingebunden über ein SMTP-Plugin. Zugangsdaten nur im Plugin bzw. in `wp-config.php`, nicht im Repository.
3. **SPF:** TXT-Eintrag der Absender-Domain mit dem Mail-Server bzw. Dienst, z. B. `v=spf1 include:… -all`. Den
   genauen Wert nennt der Anbieter.
4. **DKIM:** beim Anbieter einschalten und den angegebenen DNS-Eintrag (Selektor) setzen.
5. **DMARC:** TXT-Eintrag `_dmarc.deine-domain.de`. Zum Start z. B. `v=DMARC1; p=none; rua=mailto:dmarc@deine-domain.de`,
   nach einigen Wochen ohne Fehler `p=quarantine`.
6. **Zustelltest:** Im Systemstatus die Testmail senden, dazu je einen Testkauf, Passwort-Reset und Datenexport. Ziel
   sind Postfächer bei mindestens zwei großen Anbietern (z. B. Gmail, GMX/Web.de, Outlook). Im Quelltext der Mail muss
   `Authentication-Results` für SPF, DKIM und DMARC „pass“ melden. Keine Mail darf im Spam landen.
7. **Rücklauf:** Bounces und Antworten landen im Postfach des Absenders. Regelmäßig lesen (Support-Ablauf, T58).
8. **Datenschutzerklärung:** Mail-Dienst als Auftragsverarbeiter eintragen (Design › Betreiberangaben „E-Mail-Versand“,
   Datenschutz Nr. 14) und Vertrag zur Auftragsverarbeitung abschließen (T57).

## Ergebnis festhalten

| Prüfung | Datum | Ergebnis |
|---|---|---|
| Testmail Systemstatus | | |
| Bestellbestätigung (Überweisung) | | |
| Passwort-Reset | | |
| Datenexport-Bestätigung | | |
| SPF / DKIM / DMARC „pass“ | | |
