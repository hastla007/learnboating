# Änderungsprotokoll – LearnBoating Kursgutschein-Connector

Die Version steht im Plugin-Kopf (`Version:`) und in der Konstante `LBVC_VERSION`; beide müssen gleich sein (Test
`learnboating-affiliate-connector/tests/connector-test.php`, Abschnitt „Add-on-Versionen“). Auslieferung:
`docs/installation-und-updates.md`.

## 1.1.0 – 13.11.2026

- Sperre je Gutschein: Einlösung und Warteschlange bearbeiten einen Gutschein nie doppelt gleichzeitig; eine zweite
  Einlösung wartet bis zu 10 Sekunden, sonst Meldung „wird gerade eingelöst“ (T83).
- Einlöseseite `[vcap_redeem]` mit Grenze je IP (IPv6 je /64-Netz) und je Konto (T88).
- Grenze je IP gemeinsam mit App und „Mein Lernen“ (ein Zähler über alle Einlösewege); der Filter heißt jetzt
  `bootspruefung_voucher_redeem_per_ip` (statt `lbvc_redeem_per_ip`). Im Warenkorb nennt die Meldung „Das ist ein
  Kursgutschein …“ den Code nur angemeldet und nach bestandener Limitprüfung, sonst neutral (T109).
- Warteschlange nie ohne Sperre geschrieben: belegt → in 60 Sekunden per WP-Cron nachgeholt (`lbvc_queue_edit`),
  nur ohne benannte Sperren der Datenbank (NULL) direkt; Sperren mit HyperDB/LudicrousDB über den Hauptserver (T115).
- Hinweis auf Gutscheinprodukte mit ungültigem Ziel nach jeder Produktänderung sofort neu geprüft, sonst spätestens
  nach 10 Minuten (vorher bis zu 1 Stunde alt, T105).
- `uninstall.php`: löscht Warteschlange, Zwischenspeicher und Cron-Ereignis, bei Multisite je Website (T82).
- Übersetzbar: Sprachordner `languages/` mit Vorlage `.pot`, Text-Domain wird geladen (T89).
- Erste Auslieferung als ZIP mit Änderungsprotokoll; Autor „LearnBoating“ (Projektname, vom Betreiber festzulegen).

## 1.0.0 – 13.11.2026

Erste Fassung: Kursgutscheine aus Voucher Captain in „Mein Lernen“ (App und Einlöseseite) einlösen; reserviert den
Gutschein, schaltet den Kurs über die Produktzuordnung „Lernzugang“ frei und verbucht erst danach.
