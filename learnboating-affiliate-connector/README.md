# LearnBoating Partnerprogramm-Connector

Separat installierbares Add-on, das die **Bootsprüfung-Lernplattform** (`bootspruefung-learning`) mit **WP Affiliate Captain** verbindet (öffentlicher Vertrag v1, Konzept „WP Affiliate Captain“ Kap. 13). Affiliate Captain bleibt frei von LearnBoating-Logik; der Connector nutzt nur dessen öffentliche Funktionen und Filter und keine Affiliate-Captain-Tabellen.

## Was der Connector macht

| Thema | Umsetzung |
|---|---|
| App → Shop | Die Mobile-API (`boatingschool/v1`) hängt einen **gültigen** Partnercode an `webshop_url` und alle Kaufangebote (`offers`) an: `…/product/sbf-see-theorie/?partner=CODE&utm_campaign=app`. Ungültige, gesperrte oder abgelaufene Codes werden nicht weitergegeben. Web-Seiten und Store API bleiben unverändert. |
| Zuordnung ohne Cookie | Im Browser übernimmt Affiliate Captain den Code beim „In den Warenkorb“ in die Shop-Sitzung (ohne Marketing-Cookie). Kampagne `app` und Produktseite erscheinen in den Kampagnen-/Content-Berichten. Das Checkout-Feld „Partnercode“ funktioniert zusätzlich, auch ohne Rabatt. |
| Keine Provision auf | Gruppenpakete (Katalog-Gruppe `groups` oder Plätze in der Produktfreischaltung), Upgrades im Checkout (Videokurs-Upgrade `*-VIDEO`, `SIM-UPGRADE-CHECKOUT`) und frei konfigurierbare Artikelnummern (`BP-ADD-*`, `BP-PASS-1M`, …). Ergänzungskurse `BP-ADD-*` sind standardmäßig provisionsfähig. |
| Haltefrist | Provision wird frühestens nach dem späteren Ende von **Geld-zurück-Garantie** (je Produkt, `_bp_money_back`) und **Widerrufsfrist** (Standard 14 Tage) plus Puffer (Standard 2 Tage) freigegeben. Ein späteres Datum von Affiliate Captain (Standard-Haltefrist) bleibt bestehen. |
| Kurszugangscodes | `POST /coupons/redeem` und die Zugangscodes der App bleiben unberührt – Partnercodes und Zugangscodes sind getrennt. |
| Diagnose | Fehlt Affiliate Captain oder meldet es eine andere Vertrags-Hauptversion, bleibt der Connector kontrolliert inaktiv und zeigt im Admin einen verständlichen Hinweis. Status auch unter *Affiliate Captain → Integrationen*. Die Prüfung beim Start (`plugins_loaded`) speichert nur einen Fehlercode (`Connector::diagnose()`), übersetzt wird erst bei der Anzeige (`Connector::problemText()`), also nie vor `init`. |

Einstellungen: *Einstellungen → LearnBoating-Partner*.

## Für das App-Team

Den Partnercode aus einem Deep Link (`…?partner=CODE`) bei den Kurs-Anfragen mitschicken – entweder als Query-Parameter oder als Header:

```http
GET /wp-json/boatingschool/v1/courses/1?partner=SEGELSCHULE-NORD
GET /wp-json/boatingschool/v1/courses/1
X-Partner-Code: SEGELSCHULE-NORD
```

Die zurückgegebenen `webshop_url`/`offers[].url` unverändert im Browser öffnen. Der Code muss nicht gespeichert oder geprüft werden; der Server gibt nur gültige Codes weiter.

## Voraussetzungen

- Bootsprüfung-Lernplattform (mit dem Filter `bootspruefung_shop_offer_url`, ab dieser Version)
- WP Affiliate Captain mit Vertrag 1.x (ab 0.6.0 empfohlen)
- WooCommerce, PHP 8.1+

## Test

```bash
php learnboating-affiliate-connector/tests/connector-test.php
```

Läuft ohne WordPress (Plattform und Vertrag werden nachgebildet). Live geprüft auf WordPress 7.1.4, WooCommerce 11.3.0, Lernplattform 0.13.0 und WP Affiliate Captain 0.6.0: Bestellung Kurs + Video-Upgrade + Gruppenpaket + Ergänzungskurs → Provision nur auf Kurs und Ergänzungskurs; Garantie 45 Tage → Haltefrist 47 Tage; Mobile-API hängt nur gültige Codes an; App-Link → Kauf ohne Cookie mit Kampagne `app`.

## Deinstallation

`uninstall.php` löscht die Einstellungen des Connectors (Option `lbac_settings`), bei Multisite je Website.
Provisionen und Partnerdaten gehören WP Affiliate Captain und bleiben.
