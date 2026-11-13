# Betreiber-Schritte vor und nach dem Livegang (To-do T61, T63, T67–T69, T71, T72, T74–T76)

Diese Punkte brauchen Entscheidungen, Zugänge oder Rechte des Betreibers. Die Technik dafür ist fertig. Je Punkt
steht hier, was es schon gibt, was zu tun ist und woran man erkennt, dass es erledigt ist. Die Reihenfolge am
Livegang-Tag steht in `docs/livegang-drehbuch.md`.

## T63 Zahlungsarten in der Sandbox Ende-zu-Ende

**Vorhanden:** Browser-Test des Kaufablaufs mit Überweisung (Block- und klassische Kasse) in der CI.
Dispute-Webhooks für PayPal und Stripe, Klarna-Konflikte per Bestellaktion (T64/T65). Garantie-Erstattung und
Widerruf.

**Zu tun:** Sandbox-Konten bei PayPal, Stripe und Klarna anlegen und auf Staging eintragen. Dann je Zahlungsart:

| Fall | Erwartet |
|---|---|
| Kauf | Bestellung „In Bearbeitung“, Lernzugang frei, Bestellmail mit Laufzeit und Garantie-Erklärung |
| Teilerstattung (Garantie) | Zugang der erstatteten Kurse endet, Erstattungsmail, Betrag beim Anbieter zurück |
| Vollerstattung | Zugang endet, Erstattungsmail |
| Dispute eröffnet (PayPal/Stripe per Sandbox-Werkzeug, Klarna per Bestellaktion) | Zugang ausgesetzt, Bestellnotiz, Eintrag im Audit-Log |
| Dispute gewonnen | Zugang wieder frei |
| Dispute verloren | Zugang bleibt gesperrt |
| Abgebrochene Zahlung | Bestellung bleibt offen, kein Zugang |

**Erledigt, wenn:** jede Zeile je Zahlungsart mit Datum abgehakt ist. Fehler mit Bestellnummer an die Entwicklung.

## T67 Staging-Abnahme mit dem Zielsystem

**Vorhanden:** Docker-Staging im Repo (`staging/`) mit PHP 8.5, WordPress 7.1, MariaDB 12.3 und WooCommerce 11.2.
CI-Läufe mit PHP 8.1 und 8.5 sowie WooCommerce 11.2 und 11.3 (Beta), Block-Kasse im Browser-Test.

**Zu tun:**
1. Auf dem echten Staging-Server dieselben Versionen wie später live installieren.
2. Alle **eigenen Drittanbieter-Plugins** einspielen, z. B. Zahlungs-Plugins, Rechnungs-Plugin (T53), SMTP-Plugin (T54),
   Yoast, Gutschein- und Partner-Plugin.
3. `define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);`, dann Livegang-Drehbuch
   Abschnitt 3 bis zum Testkauf durchspielen.
4. `wp-content/debug.log` lesen: Keine Fatal Errors, keine Warnungen aus `bootspruefung-learning` oder `kurswasser`.
   Warnungen anderer Plugins notieren und mit deren Anbietern klären.
5. Kasse mit WooCommerce 11.3 (oder der dann aktuellen Version) als Block-Kasse: Kauf, Überweisungsdaten,
   Freischaltung.

**Erledigt, wenn:** Protokoll mit Versionen, Plugin-Liste und leerem Log vorliegt.

## T68 Rechte und Prüfungsregelwerke im Live-System freigeben

**Vorhanden:** Bootsprüfung › Rechte (Admin) und WP-CLI:
`wp bootspruefung rights release --dry-run` zählt, was freigegeben würde. Danach
`wp bootspruefung rights release --reason="Freigabe <Datum>, <Name>" --exam-rules --yes`. Das gibt alle Inhalte mit
Rechte-Status „unknown“ ohne eigene Einstellung frei, außer Seekarten, und veröffentlicht die Prüfungsregelwerke.
Jede Freigabe steht im Protokoll (Bootsprüfung › Protokoll). Im Admin fragt jede Freigabe vorher mit der Anzahl der
betroffenen Einträge nach; die Begründung ist Pflicht und nicht vorbelegt (`docs/admin-bereich.md`).

**Zu tun:** Vorher klären, dass die Nutzungsrechte bestehen: amtliche Fragen, Abbildungen, Navigationsaufgaben,
Praxiskarten, Funktexte, Videos, Untertitel. Regelwerke mit Übungsmodus, z. B. Ergänzung FKN → SKN, erst nach der
fachlichen Bestätigung (T19/T37) freigeben.

**Erledigt, wenn:** `wp bootspruefung rights release --dry-run` nichts mehr zum Freigeben findet und bewusst gesperrte
Inhalte gesperrt bleiben.

## T69 Seekarte D49

**Vorhanden:** Die Karte ist besonders geschützt (nicht frei zugänglich zu machen). WP-CLI:
`wp bootspruefung chart status D49`, `wp bootspruefung chart upload D49 <datei>`,
`wp bootspruefung chart license D49 --note="BSH-Lizenz Nr. …, gültig bis …"`.

**Zu tun:** Lizenz beim Bundesamt für Seeschifffahrt und Hydrographie (BSH) für die Nutzung in einem kostenpflichtigen
Online-Kurs einholen. Datei in der lizenzierten Auflösung hochladen und Lizenz mit Nachweis bestätigen.

**Erledigt, wenn:** `chart status D49` „Lizenz bestätigt“ und „für Lernende sichtbar“ zeigt und die Navigationsaufgaben die Karte anzeigen.

## T71 Produktkatalog im Live-System

**Vorhanden:** Design › Kurswasser einrichten, Schritt 3, bzw. `wp bootspruefung shop setup --dry-run`. Das zeigt
die Produkte mit Preisen der Preis-Seite, Laufzeit, Lernzugang und Garantie. Danach
`wp bootspruefung shop setup --publish --yes`. Der Befehl ist wiederholbar und gleicht bestehende Produkte ab.

**Zu tun:** Preise final (T16), dann auf Live anlegen und veröffentlichen. Stichprobe je Kategorie: Produktseite,
Warenkorb, Kasse, Zuordnung „Lernzugang“ am Produkt.

**Erledigt, wenn:** Testkauf je Kategorie freischaltet (Drehbuch Abschnitt 3).

## T72 Widerrufsfunktion live, Widerrufs- und Garantietexte prüfen

**Vorhanden:** WooCommerce-Funktion „Order withdrawal“ mit Knopf „Vertrag hier widerrufen“ unter „Mein Konto“, Seite
`/vertrag-widerrufen/` und Fristberechnung (`WithdrawalFunction`). Das Plugin schaltet die Funktion beim ersten
Admin-Aufruf einmal ein. Wurde sie später ausgeschaltet, zeigt der Admin einen Hinweis mit Knopf „Widerrufsfunktion
einschalten“. Texte: AGB Nr. 9 und 10, Widerrufsbelehrung, Bestellmail.

**Zu tun:** Auf Live prüfen, dass sie eingeschaltet ist (sonst Knopf im Hinweis bzw. WooCommerce › Einstellungen ›
Erweitert › Funktionen).
Einmal mit einem Testkauf widerrufen. Widerrufsbelehrung, Muster-Widerrufsformular, AGB Nr. 9/10 und die
Garantie-Erklärung der Bestellmail vom Anwalt prüfen lassen (T52, `docs/anwalt-pruefung.md`).

**Erledigt, wenn:** Testwiderruf mit Eingangsbestätigung per Mail und Prüfung durch den Anwalt.

## T74 Theme-Abnahme, Rechte der Hero-Fotos

**Vorhanden:** Die CI erstellt bei jedem Lauf Screenshots aller Seiten (Desktop und Handy). Sie liegen auf GitHub ›
Actions › Lauf › Artefakt „theme-kurswasser-screenshots“. Kursfotos liegen in `themes/kurswasser/assets/images/courses/`
(Austausch: `tools/course-images/README.md`).

**Zu tun:** Screenshots durchsehen und Änderungswünsche als Liste schicken. Für jedes Foto (Hero, Kursfotos) die
Nutzungsrechte belegen (Lizenz, Fotograf, Bildnachweis). Fotos ohne Rechte austauschen.

**Erledigt, wenn:** schriftliche Abnahme und Rechtenachweis je Foto vorliegen.

## T61 Umzug zum Laravel-Original (Recht)

**Vorhanden:** Export für das Laravel-Original (`wp bootspruefung export entitlements`, Format 2, vollständig, T46),
dazu Zugangsregeln und Kursdateien (`export access-rules`, `export course-files`).

**Zu klären (Anwalt, vor einem Umzug):**
1. Rechtsgrundlage für die Übertragung der Kundendaten. Gleicher Verantwortlicher oder neuer? Gegebenenfalls
   Vertragsübernahme bzw. Einwilligung.
2. Information der Kunden vor dem Umzug: was übertragen wird, Widerspruch, neue Datenschutzerklärung.
3. Lizenzen: Gelten die Rechte an Inhalten, Fotos, Seekarte und Videos auch für das andere System?
4. Vollständigkeit: Exportzahlen gegen den Systemstatus prüfen (Kunden, aktive Zugänge, Gruppen) und ein Probelauf im
   Ziel.

**Erledigt, wenn:** schriftliche Freigabe des Anwalts und ein Probelauf mit Abgleich der Zahlen vorliegen.

## T75 Abos (zurückgestellt)

Heute gibt es nur Einmalzahlungen mit fester Laufzeit („kein Abo“ steht in Kasse, Mail und AGB). Abos bräuchten ein
Abo-Plugin, Änderungen an Zugangslogik, AGB, Kündigungsbutton (§ 312k BGB) und Preisangaben. **Entscheidung:** nur
umsetzen, wenn ausdrücklich gewünscht. Bis dahin nichts tun.

## T76 Prüfungsanmeldung freischalten (zurückgestellt)

**Vorhanden:** Anleitung zur Prüfungsanmeldung und Prüfungsausschüsse (T26). Sie sind ausgeschaltet über
Bootsprüfung › Einstellungen › „Prüfungsanmeldung“.

**Zu tun, wenn gewünscht:** Inhalte fachlich prüfen (Gebühren, Fristen, Formulare mit Stand), Häkchen setzen, im
Lernbereich ansehen.
