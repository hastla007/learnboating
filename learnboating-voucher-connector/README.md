# LearnBoating Kursgutschein-Connector

Separat installierbares Add-on, das die **Bootsprüfung-Lernplattform** (`bootspruefung-learning`) mit **Voucher
Captain** verbindet: Kursgutscheine im Shop verkaufen und in „Mein Lernen“ einlösen. Voucher Captain bleibt frei von
LearnBoating-Logik (nur öffentliche Filter und die Einlöse-API), die Lernplattform frei von Gutscheinlogik (nur der
Grant-Vertrag `bootspruefung_voucher_grants_v1`). Vertrag, Regeln und Fehlercodes: [`docs/kursgutscheine.md`](../docs/kursgutscheine.md).

## Einrichtung

1. Kursprodukt in WooCommerce mit Reiter „Lernzugang“ (Kurse, Bereiche, Laufzeit, Verlängerungs- und Erstattungsregel).
2. Gutscheinprodukt in Voucher Captain: Option „Gutschein“, Typ „Kursgutschein (LearnBoating)“, Feld
   **„Kursprodukt (LearnBoating)“** wählen. Angeboten werden nur Kursprodukte mit aktivem Lernzugang (keine
   Gruppenlizenzen, keine Upgrades). Das Gutscheinprodukt selbst darf keinen Lernzugang haben.
3. Fertig: Der Gutschein wird verkauft und zugestellt wie jeder Voucher-Captain-Gutschein.

## Was der Connector macht

| Thema | Umsetzung |
|---|---|
| Einlösung | App `POST /vouchers/redeem` und Gutschein-Einlöseseite `[vcap_redeem]` (angemeldet): reservieren (`learnboating:grant:{Gutschein-ID}`, 24 h) → Grant → verbuchen. |
| Wiederholung | Gleicher Gutschein → dieselbe Freischaltung. Vorübergehender Fehler: Reservierung bleibt, Warteschlange `lbvc_pending` (stündlich und vor jeder Einlösung) schaltet frei und verbucht, oder gibt frei, wenn kein Zugang mehr möglich ist bzw. die Reservierung abläuft. |
| Kanal | `learnboating`: Kursgutscheine nur in „Mein Lernen“, nicht im Warenkorb oder an der Kasse – außer „Shop“ ist am Produkt angehakt. Warenkorb und Kurseinlösung nutzen denselben Gutscheinstatus: eine offene Bestellung oder Reservierung sperrt den anderen Weg. |
| Ziel | beim Kauf in der Bestellposition festgehalten; beim Speichern des Produkts und beim Einlösen gegen die Lernplattform geprüft; Admin-Hinweis für Gutscheinprodukte, deren Kursprodukt fehlt oder keinen Lernzugang mehr hat (wird nach jeder Produktänderung sofort neu geprüft, sonst spätestens nach 10 Minuten). |
| Widerruf | Gutschein ungültig gemacht (z. B. Kauf erstattet) → Zugang nach Erstattungsregel widerrufen (Filter `lbvc_revoke_on_void`). |
| Verlauf | Gutscheinverlauf in Voucher Captain: „Kurs freigeschaltet“ mit Nutzer, Kursprodukt, Einlöse-ID; Zugang in der Lernplattform mit Gutschein-ID, Einlöse-ID, Herkunft, Zeitpunkt und Link zum Gutschein. |

Filter: `lbvc_voucher_target` (Ziel für Gutscheine ohne Produkt, z. B. importierte Codes), `lbvc_reservation_minutes`,
`lbvc_learning_url` (Link „Jetzt lernen“), `lbvc_revoke_on_void`. Aktion: `lbvc_course_granted`.

## Voraussetzungen

- Bootsprüfung-Lernplattform mit Grant-Vertrag 1.x (ab der Version mit `VoucherGrants`)
- Voucher Captain mit Einlöse-API v1 (`vcap()->redemptions`), WooCommerce, PHP 8.1+

## Test

Im CI-Job „WordPress-Plugin“ (echtes WordPress, WooCommerce, Voucher Captain aus `hastla007/wp-couponmaster`):

```bash
WP_DIR=/pfad/zu/wordpress php learnboating-voucher-connector/tests/setup.php
```

```bash
WP_DIR=/pfad/zu/wordpress php learnboating-voucher-connector/tests/connector-integration-test.php
```
