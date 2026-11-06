# Offene Punkte (Stand 06.11.2026, Plugin 0.9.0)

Legende Status: 🔴 offen · 🟡 teilweise/zu prüfen · ⏸ bewusst zurückgestellt
Wer: **Du** = Entscheidung/Lieferung Betreiber · **Dev** = Umsetzung im Code · **Beide** = Test/Abnahme gemeinsam

| # | Bereich | Punkt | Status | Wer | Blockiert Go-live | Nächster Schritt |
|---|---|---|---|---|---|---|
| 1 | Recht & Content | Rechtsfreigabe SRC/UBI-Kataloge, 527 Fragen ohne Quellenangabe, ELWIS-Bilder, Seekarten, Funktexte | 🔴 | Du | ja | Freigaben einholen, dann im Admin „Rechte“ je Katalog/Quelle freigeben |
| 2 | Recht & Content | Prüfungsregelwerke verifizieren (sonst nur Übungsmodus) | 🔴 | Du | ja | Regelwerke prüfen, im Admin „Prüfungsregeln“ verifizieren |
| 3 | Recht & Content | Platzhaltervideos ersetzen | 🔴 | Du | ja | Videos liefern (URL/Datei je Lektion) |
| 4 | Recht & Content | Katalogaktualität bestätigen (Prüfstand Commit 136a243) | 🔴 | Du | ja | Stand bestätigen oder neuen Quell-Commit nennen |
| 5 | Shop | Produkt- und Variantenliste (Preise, Dauer, Erstattungsregel, Bundles, Gruppenpakete) | 🔴 | Du | ja | Liste liefern; Anlage im Reiter „Lernzugang“ |
| 6 | Shop | Free-vs-Pro festlegen (ganz, Teilumfang, zeitgesteuert) | 🔴 | Du | ja | Umfang je Kurs nennen; Pflege unter „Kostenlose Bereiche“ |
| 7 | Shop | „Zugang ohne Shop“ auf „Nur Freischaltungen“ umstellen | 🔴 | Du | ja | Am Tag des Shopstarts in den Einstellungen |
| 8 | Shop | Kaufablauf-Browsertest (Block + klassisch) grün in CI | 🟡 | Dev | nein | CI-Ergebnis abwarten, ggf. Test nachjustieren |
| 9 | Zahlung | Stripe/PayPal/Klarna echt end-to-end bezahlen (Sandbox) | 🔴 | Beide | ja | Sandbox-Zugänge einrichten, je Gateway Kauf, Erstattung, Dispute durchspielen |
| 10 | Zahlung | PayPal-Webhook mit echter Signaturprüfung testen | 🔴 | Beide | nein | Client-ID/Secret/Webhook-ID eintragen, Sandbox-Dispute auslösen |
| 11 | Zahlung | Klarna-Disputes: Weiterleitung an den allgemeinen Webhook einrichten | 🔴 | Du | nein | Automatisierung (z. B. Händlerportal-Mail → Webhook) oder manuell per Bestellaktion |
| 12 | Zahlung | Abos (wiederkehrende Zahlung) | ⏸ | Du | nein | Nur falls gewünscht: WooCommerce Subscriptions anbinden |
| 13 | Prüfungsanmeldung | Seite „Prüfung anmelden“ für Lernende freischalten | ⏸ | Du | nein | Einstellung „Prüfungsanmeldung“ aktivieren, wenn gewünscht |
| 14 | Prüfungsanmeldung | Gebühren/Fristen der Anleitungen aktualisieren (Stand 2024–2026 laut Quelle) | 🟡 | Du | nein | Vor Freischaltung prüfen; Änderungen über neues Content-Paket |
| 15 | Prüfungsanmeldung | Kurszuordnung der Prüfungsausschüsse pflegen (in der Quelle leer; steuert nur die Reihenfolge) | 🟡 | Du | nein | Unter „Prüfungsanmeldung › Prüfungsausschüsse“ |
| 16 | Prüfungsanmeldung | Texte der Quelle mit Bootsschul-Bezug (z. B. FAQ „nicht an die Bootsschule“) | 🟡 | Du | nein | Wortlaut ggf. im Quell-Repo anpassen oder als lokale Änderung |
| 17 | Shop | Gruppenlizenzen: Produkte für Paare/Vereine anlegen (Plätze je Paket) | 🔴 | Du | nein | Im Reiter „Lernzugang“ „Plätze je Stück“ setzen |
| 18 | Betrieb | Tipps-Bereich der Quelle übernehmen? | 🔴 | Du | nein | Entscheidung |
| 19 | Betrieb | Backup/Restore testen, Supportablauf festlegen | 🔴 | Beide | ja | Backup-Plugin/Hoster festlegen, Restore einmal üben |
| 20 | Betrieb | Ziel-WP/WooCommerce-Version, Theme, Checkout-Variante | 🔴 | Du | ja | Festlegen; danach Test gegen genau diese Versionen |
| 21 | Betrieb | nginx: Ordner für geschützte Dateien sperren | 🔴 | Du/Hoster | ja, wenn nginx und geschützte PDFs | Regel für `uploads/bootspruefung/protected-*` |
| 22 | Design | Designreferenzen und Markentexte | 🔴 | Du | nein | Liefern; Anpassung der CSS/Texte |
| 23 | Technik | Rückkehr zur Gruppeneinladung nach Registrierung (nur nach Login automatisch) | 🟡 | Dev | nein | Redirect nach Registrierung ergänzen |
| 24 | Technik | Vorschau von `wp bootspruefung reconcile` zeigt Gruppenänderungen nicht | 🟡 | Dev | nein | Gruppen in die Vorschau aufnehmen |
| 25 | Technik | Herkunft der übrig gebliebenen Einstellung „entitled_only“ zwischen CI-Schritten | 🟡 | Dev | nein | E2E-Setup stellt den Zustand jetzt selbst her; Ursache klären |

Erledigt seit dem letzten Stand: Downloads (PDF) je Kurs, Zuordnungsfehler in der Übersicht, Dispute-Webhooks (PayPal, allgemein), Prüfungsausschüsse und Anleitung zur Selbstanmeldung (deaktiviert), FKN-Entscheidung (dabei), Zielgruppe B2C festgelegt.
