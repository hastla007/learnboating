# Änderungsprotokoll – LearnBoating Kursgutschein-Connector

Die Version steht im Plugin-Kopf (`Version:`) und in der Konstante `LBVC_VERSION`; beide müssen gleich sein (Test
`learnboating-affiliate-connector/tests/connector-test.php`, Abschnitt „Add-on-Versionen“). Auslieferung:
`docs/installation-und-updates.md`.

## 1.0.1 – 13.11.2026

- Keine Änderung an der Funktion. Erste Auslieferung als ZIP mit Änderungsprotokoll; Autor „LearnBoating“
  (Projektname, vom Betreiber festzulegen).

## 1.0.0 – 13.11.2026

Erste Fassung: Kursgutscheine aus Voucher Captain in „Mein Lernen“ (App und Einlöseseite) einlösen; reserviert den
Gutschein, schaltet den Kurs über die Produktzuordnung „Lernzugang“ frei und verbucht erst danach.
