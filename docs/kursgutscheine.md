# Kursgutscheine – Vertrag, Regeln, Fehlercodes

Ein Kursgutschein wird im Shop verkauft (Voucher Captain) und in „Mein Lernen“ eingelöst (App oder
Gutschein-Einlöseseite). Er schaltet genau das Kursprodukt frei, das im Gutscheinprodukt gewählt ist – mit den
Kursen, Bereichen und der Laufzeit aus dessen Produktzuordnung „Lernzugang“. Die Lernplattform kennt keine
Gutscheine; sie stellt einen Grant-Dienst bereit. Der Connector `learnboating-voucher-connector/` verbindet beides.

```
Kunde ──► App POST /vouchers/redeem ─┐
      └─► Einlöseseite [vcap_redeem] ┴─► Connector ──► Voucher Captain reserve (learnboating:grant:{id})
                                                   ──► VoucherGrants::grant (idempotent, Referenz voucher:{Schlüssel})
                                                   ──► Voucher Captain commit
```

## 1. PHP-Vertrag `bootspruefung_voucher_grants_v1` (Lernplattform)

`apply_filters('bootspruefung_voucher_grants_v1', null)` liefert `Bootspruefung\Learning\Commerce\VoucherGrants`
(`CONTRACT_VERSION` 1.x; Hauptversion wechselt nur bei inkompatiblen Änderungen). Fehler sind `WP_Error` mit
stabilem Code, `data.status` (HTTP-Stil) und `data.retryable` (true = vorübergehend, mit demselben Schlüssel
wiederholen).

| Methode | Zweck |
|---|---|
| `target(int $product_id, int $variation_id = 0)` | Ist das Kursprodukt ein gültiges Gutscheinziel? Liefert Name, SKU und je Bereich `course`, `course_id`, `course_name`, `feature`, `duration_days`, `renewal_mode`, `refund_rule`. |
| `check(int $user_id, int $product_id, int $variation_id = 0)` | Vorschau für einen Nutzer: je Bereich vorhandener Zugang (`none`/`until`/`permanent`), geplanter Beginn und Ende. |
| `grant(array $request)` | Freischalten (siehe Eingaben). Idempotent: gleicher Schlüssel → derselbe Grant (`created: false`). |
| `get(string $redemption_key)` | Grant zu einem Schlüssel (Support, Wiederholung) oder `null`. |
| `revoke(string $redemption_key, string $reason)` | Widerruf nach Erstattungsregel; liefert `revoked`/`kept`. |

**Eingaben von `grant()`** (TODO „Vertragseingaben“):

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `redemption_key` | ja | Einlöse-ID des Gutschein-Plugins = Idempotency-Key (8–64 Zeichen `A–Z a–z 0–9 . _ : -`) |
| `user_id` | ja | angemeldeter Learnboating-Nutzer (App: aus dem Token; Web: eingeloggtes Konto) |
| `product_id`, `variation_id` | ja / nein | zugeordnetes WooCommerce-Kursprodukt bzw. Variation |
| `voucher_id` | nein | Gutschein-ID im Gutschein-Plugin |
| `source` | nein | Herkunft, z. B. `voucher-captain` |
| `source_reference` | nein | Herkunftsreferenz, z. B. `vcap:voucher:123` |

Kurs-Bereiche (Features) und Laufzeit werden **nicht** übergeben: sie kommen aus der Zuordnung „Lernzugang“
(`ProductAccessRepository::forItem()`), damit es keine zweite, abweichende Konfiguration im Gutschein-Plugin gibt.
Einen **Wunsch-Startzeitpunkt gibt es nicht** (Widerspruch in der Anforderungsliste aufgelöst): die Laufzeit beginnt
mit der bestätigten Einlösung.

**Speicherung:** je Bereich eine Zeile in `bp_entitlement` mit `source = 'voucher'`,
`reference_key = 'voucher:{redemption_key}'` (eindeutig zusammen mit Kurs und Bereich – parallele Aufrufe legen nichts
doppelt an), `product_id`/`variation_id`, `sku_snapshot`, und in `meta`: `voucher_id`, `redemption_key`,
`grant_source`, `source_reference`, `redeemed_at`, dazu wie beim Kauf `duration_days`, `renewal_mode`, `refund_rule`.
Audit-Log: `entitlement_voucher_grant` (Nutzer, Gutschein-ID, Kursprodukt, Einlöse-ID, Bereiche, Zeitpunkt) und
`entitlement_voucher_revoke`. Hooks: `bootspruefung_voucher_granted` (Ergebnis), `bootspruefung_voucher_revoked`.

## 2. Regeln (TODO „Regeln für Erstattung, Widerruf, …“)

| Fall | Regel |
|---|---|
| Laufzeit | Beginn = bestätigte Einlösung; Dauer = `duration_days` der Zuordnung; ohne Dauer dauerhaft. |
| Vorhandener befristeter Zugang | wie beim Kauf: Verlängerungsregel `extend` → schließt nahtlos an das Ende an; `parallel` → läuft ab sofort. |
| Vorhandener dauerhafter Zugang für **alle** Bereiche | keine Freischaltung (`lb_access_exists`), der Gutschein bleibt ungenutzt und verschenkbar. |
| Ziel ungültig | Produkt gelöscht/nicht veröffentlicht (`lb_target_unavailable`), ohne aktiven Lernzugang (`lb_target_unmapped`), Gruppenlizenz (`lb_target_group`), Upgrade `*-VIDEO`/`SIM-UPGRADE-CHECKOUT` (`lb_target_upgrade`), Kurs nicht veröffentlicht/abgelaufen (`lb_course_unavailable`) → keine Reservierung, Gutschein bleibt gültig. |
| Gutscheinablauf | abgelaufene Gutscheine lehnt Voucher Captain ab (`vcap_expired`); eine schon erfolgte Freischaltung bleibt bis zu ihrem eigenen Ende gültig. |
| Erstattung des Gutscheinkaufs **vor** der Einlösung | Voucher Captain macht den Gutschein ungültig; nichts zu widerrufen. |
| Erstattung **nach** der Einlösung | Voucher Captain lehnt die Erstattung eines eingelösten Gutscheins standardmäßig ab (`vcap_allow_refund_of_spent_vouchers`). Wird sie zugelassen, wird der Gutschein ungültig → `revoke()` nach `refund_rule`: `revoke_on_full_refund`/`revoke_on_any_refund` widerrufen, `keep` behält. Noch nicht begonnene Verlängerungen rücken nach vorn. |
| Geld-zurück-Garantie | gilt für den Kauf des Gutscheins (Bestellung im Shop); Bearbeitung wie eine Erstattung nach der Einlösung. |
| Zielzuordnung | zum **Kaufzeitpunkt**: das Kursprodukt wird in der Bestellposition festgehalten (`_vcap_line.learnboating_target`); spätere Änderungen am Gutscheinprodukt ändern verkaufte Gutscheine nicht. Geprüft wird beim Einlösen. |
| Gutscheinprodukt | darf selbst keinen Lernzugang tragen (sonst Freischaltung schon beim Kauf über `OrderSync`) – der Connector lehnt das Speichern ab. |

Betreiber-Entscheidungen, die diese Regeln festlegen (Laufzeitbeginn, Verlängern statt Ablehnen, Erstattung nach
Einlösung nur ausdrücklich, Zielzuordnung beim Kauf), sind als Standard umgesetzt und über die genannten Filter
bzw. die Zuordnung je Produkt änderbar. Offen für den Livegang: Gültigkeit und AGB-Text der Kursgutscheine, die
Theme-Seite „Bootsführerschein-Gutschein“ (Entwurf) und die FAQ „Kosten, Gutscheine und Garantien“, die bis dahin
„Gutscheine bieten wir derzeit nicht an“ sagt.

## 3. App-Route (boatingschool/v1, seit 1.12.0)

`POST /vouchers/check` (Vorschau) und `POST /vouchers/redeem` – Nutzer immer aus dem Token, nie aus dem Request.
Optional `course_id` (aus einem Kurs heraus eingelöst). `POST /coupons/redeem` bleibt für Gruppencodes und verweist
Kursgutscheine mit 422 `COURSE_VOUCHER` (`details.redeem_path`) auf die neue Route. Vertrag: `docs/mobile-api/openapi.yaml`.

Der Connector stellt über `bootspruefung_course_voucher_redeemer_v1` ein Objekt mit `check($code, $user_id)`,
`redeem($code, $user_id)` und `is_course_voucher($code)` bereit; ohne Connector antwortet die Route mit 503
`COURSE_VOUCHERS_UNAVAILABLE`.

## 4. Fehlercodes und Nutzertexte (TODO „Fehlercodes und Nutzertexte“)

| App-Code | HTTP | Ursache (Connector/Grant) | Nutzertext |
|---|---|---|---|
| `VOUCHER_INVALID` | 422 | `vcap_not_found` | Dieser Gutscheincode ist ungültig. Bitte prüfe die Eingabe. |
| `VOUCHER_INVALID` | 410 | `vcap_cancelled` (ungültig gemacht) | Dieser Gutschein ist nicht mehr gültig. |
| `VOUCHER_RESERVED` | 409 | `vcap_reserved` | Dieser Gutschein wird gerade an anderer Stelle eingelöst, zum Beispiel in einer offenen Bestellung. Bitte versuche es später erneut. |
| `VOUCHER_REDEEMED` | 409 | `vcap_already_redeemed` | Dieser Gutschein wurde bereits eingelöst. |
| `VOUCHER_EXPIRED` | 410 | `vcap_expired` | Dieser Gutschein ist abgelaufen. |
| `VOUCHER_NOT_YET_VALID` | 409 | `vcap_not_active` | Dieser Gutschein ist noch nicht gültig. |
| `VOUCHER_WRONG_COURSE` | 422 | `lb_wrong_course` | Dieser Gutschein gilt für einen anderen Kurs. |
| `VOUCHER_NOT_FOR_COURSES` | 422 | `lb_not_course_voucher`, `vcap_not_redeemable` | Das ist kein Kursgutschein. Shop-Gutscheine löst du beim Kauf im Shop ein. |
| `VOUCHER_NOT_ALLOWED` | 403 | `vcap_not_allowed` | Dieser Gutschein ist für ein anderes Konto ausgestellt. Melde dich mit der E-Mail-Adresse an, für die er bestimmt ist. |
| `ACCESS_NOT_POSSIBLE` | 409 | `lb_target_*`, `lb_course_unavailable`, `lb_access_exists`, `vcap_target_unavailable` | fachlicher Grund der Lernplattform (z. B. „Du hast diesen Kurs bereits dauerhaft freigeschaltet. Der Gutschein bleibt gültig – du kannst ihn verschenken.“) |
| `TEMPORARY_ERROR` | 503 | `lb_temporary_failure`, `vcap_temporary_failure`, unbekannte Fehler | Gerade ist ein vorübergehender Fehler aufgetreten. Dein Gutschein ist nicht verloren – bitte versuche es in ein paar Minuten noch einmal. (`retry_after`) |
| `RATE_LIMITED` | 429 | zu viele Versuche | Zu viele Versuche. Bitte warte kurz und versuche es dann erneut. |

`details.reason` nennt immer den ursprünglichen Code.

## 5. Abnahme (CI)

| Kriterium | Nachweis |
|---|---|
| exakt Kurs und Bereiche über die Zuordnung | `learnboating-voucher-connector/tests/connector-integration-test.php`, `bootspruefung-learning/tests/integration/voucher-grant-test.php` |
| gleicher Schlüssel → derselbe Grant; parallel kein zweiter Zugang, Gutschein genau einmal verbucht | beide Tests (je zwei gleichzeitige Prozesse) |
| Laufzeit ab bestätigter Einlösung, Produktlaufzeit | beide Tests |
| nicht gleichzeitig Warenkorb und „Mein Lernen“ | Connector-Test (Kanal, offene Bestellung, Reservierung) |
| Fehler/Timeout wiederholbar; Reservierung neu verarbeitet oder freigegeben | Connector-Test (Ausfall der Lernplattform → Warteschlange; Ziel ungültig → Freigabe) |
| Verlauf und Supportansicht | Connector-Test (Gutscheinverlauf, Zugangs-Meta, Admin-Link, Suche nach Einlöse-ID) |
| App-Route: Nutzer aus Token, Fehlercodes, Idempotency-Key | `bootspruefung-learning/tests/integration/mobile-api-test.php` (Abschnitt Kursgutscheine) |

CI-Job „WordPress-Plugin“: Schritte „Kursgutscheine – Grant-Service“ und „Kursgutscheine mit Voucher Captain –
Connector-Abnahme“ (Voucher Captain wird aus `hastla007/wp-couponmaster` gebaut wie das Release-ZIP).

## 6. Andere Gutschein-Plugins (Extensions\Vouchers)

Neben Voucher Captain gibt es eine einfache Gegenstelle für Gutschein-Plugins ohne eigenen Reservierungsablauf
(`docs/schnittstellen-erweiterungen.md`: Aktion `bootspruefung_voucher_redeemed`, Filter
`bootspruefung_voucher_redeem` für `POST /coupons/redeem` und das Formular unter „Mein Lernen“). Sie schaltet
**über denselben Grant-Dienst** frei – Einlöse-Schlüssel `code-` + SHA-256 des Codes, Herkunft `voucher_plugin` –,
es gibt also nur einen Freischaltweg mit denselben Regeln, Fehlercodes, Audit-Einträgen und derselben Supportansicht.
Die 0-€-Bestellungen der Version 1.11 dieser Gegenstelle entstehen nicht mehr; vorhandene werden weiter erkannt.
Voucher-Captain-Codes beantwortet `POST /coupons/redeem` mit 422 `COURSE_VOUCHER` (Verweis auf `/vouchers/redeem`),
die Gruppen-Code-Route wird also nicht für Voucher Captain umgewidmet.
