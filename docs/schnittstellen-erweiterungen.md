# Schnittstellen für Gutschein- und Ticket-Plugins

Das Lern-Plugin `bootspruefung-learning` stellt Gegenstellen für zwei Erweiterungs-Plugins bereit:

- ein **Gutschein-Plugin** (Kursgutscheine verkaufen und einlösen, Shop-Gutscheine),
- ein **Ticket-Plugin** (Praxis-Termine/Tickets mit Einheiten, Entwertung per Scan, Praxis-Angebote).

Alle Namen haben den Präfix `bootspruefung_`. Das Lern-Plugin funktioniert ohne diese Plugins unverändert:
Ohne Gegenstelle bleiben Formular und Abschnitte unsichtbar, Felder sind leer.

Code im Lern-Plugin: `src/Extensions/Vouchers.php`, `src/Extensions/Tickets.php`, `src/Extensions/Extensions.php`,
`src/Mobile/Controllers/CouponController.php`, `src/Mobile/Controllers/TicketController.php`,
`src/Commerce/WooCommerce/MoneyBackWoo.php`. Test: `bootspruefung-learning/tests/integration/extensions-test.php`.

**Richtung:** „→ Lern“ = das andere Plugin ruft auf (`do_action`/`apply_filters`), das Lern-Plugin hört zu.
„Lern →“ = das Lern-Plugin ruft auf, das andere Plugin kann sich einhängen.

## Übersicht

| Name | Art | Richtung | Wofür |
|---|---|---|---|
| `bootspruefung_voucher_redeemed` | Aktion | Gutschein → Lern | Kursgutschein eingelöst: Zugang freischalten (Grant-Dienst der Kursgutscheine) |
| `bootspruefung_voucher_redeem` | Filter | Lern → Gutschein | Code aus App bzw. „Mein Lernen“ prüfen; Gutschein-Plugin liefert das Produkt |
| `bootspruefung_voucher_redeem_failed` | Aktion | Lern → Gutschein | Einlösung im Lern-Plugin gescheitert, nachdem der Filter den Code angenommen hat |
| `bootspruefung_voucher_granted` | Aktion | Lern → Gutschein | Zugang zu einem Gutschein freigeschaltet (Ergebnis des Grant-Dienstes); ersetzt `bootspruefung_voucher_order_created` |
| `bootspruefung_voucher_money_back` | Filter | Lern (Betreiber) | Garantie für Gutschein-Bestellungen (Standard `false`) |
| `bootspruefung_user_owns_course` | Filter | Gutschein → Lern | Hat der Nutzer vollen Zugang zum Kurs? |
| `bootspruefung_coupon_allow_with_member_discount` | Filter | Gutschein → Lern | Shop-Gutschein zusätzlich zu einem Mitgliederrabatt erlaubt? |
| `_bp_paid_by_voucher` | Bestell-Meta | Gutschein → Lern | per Gutschein bezahlter Betrag einer Bestellung (Garantie) |
| `bootspruefung_money_back_cash_amount` | Filter | Lern (Betreiber/Gutschein) | als Geld zu erstattender Garantiebetrag |
| `bootspruefung_ticket_issued` | Aktion | Ticket → Lern | Ticket ausgegeben |
| `bootspruefung_ticket_redeemed` | Aktion | Ticket → Lern | Ticket (Einheit) entwertet |
| `bootspruefung_ticket_cancelled` | Aktion | Ticket → Lern | Ticket storniert bzw. Termin ausgefallen |
| `bootspruefung_tickets_for_user` | Filter | Lern → Ticket | aktuelle Ticket-Liste eines Kontos (statt der gespeicherten Kopie) |
| `bootspruefung_practice_offers` | Filter | Lern → Ticket | „Praxis in deiner Nähe“: Angebote je Kurs |
| `bootspruefung_practice_region` | Filter | Lern (Betreiber) | Region (Bundesland) eines Nutzers für Praxis-Angebote |
| `POST /wp-json/boatingschool/v1/coupons/redeem` | App-Route | App → Lern | Kursgutschein in der App einlösen (`kind: course_voucher`) |
| `GET /wp-json/boatingschool/v1/me/tickets` | App-Route | App → Lern | Praxis-Tickets des Kontos |
| `practice_offers` in `GET /wp-json/boatingschool/v1/courses/{courseId}` | App-Feld | App → Lern | Praxis-Angebote zum Kurs |
| `tickets`, `practice_offers` in `GET /wp-json/bootspruefung/v1/courses/{code}` | Web-Feld | Web-App → Lern | Kurs-Hub der Web-App |

App-Vertrag: `docs/mobile-api/openapi.yaml` (Schemas `CouponRedemptionResponse`, `PracticeTicket`, `PracticeOffer`),
Änderungsprotokoll 1.11.0 in `docs/mobile-api/README.md`.

---

## Gutschein-Plugin → Lern-Plugin

### Aktion `bootspruefung_voucher_redeemed`

```php
do_action('bootspruefung_voucher_redeemed', string $code, int $user_id, int $product_id, array $args = []);
```

| Parameter | Bedeutung |
|---|---|
| `$code` | eingelöster Gutscheincode. Gespeichert wird nur `sha256(trim($code))`. |
| `$user_id` | Konto, für das freigeschaltet wird (muss existieren) |
| `$product_id` | WooCommerce-Produkt mit Lernzugang (Reiter „Lernzugang“); einfaches Produkt oder **Variation** (nicht das variable Elternprodukt) |
| `$args['voucher_order_id']` | optional: Bestell-ID des Gutscheinkaufs (wird als `_bp_voucher_order` vermerkt) |

Was passiert:

1. Freigeschaltet wird über den **Grant-Dienst der Kursgutscheine** (`Commerce\VoucherGrants`, Vertrag
   `bootspruefung_voucher_grants_v1`, Einzelheiten in `docs/kursgutscheine.md`) – denselben Weg wie für Voucher
   Captain. Es gibt keinen zweiten Freischaltweg: Kurs, Bereiche und Laufzeit kommen aus der Produktzuordnung
   „Lernzugang“, Gruppenlizenzen und Upgrades sind ausgeschlossen.
2. **Laufzeit ab jetzt**, Dauer laut Produktzuordnung. Hat das Konto denselben Kurs schon befristet und ist die
   Zuordnung „verlängern“, schließt die neue Laufzeit an die vorhandene an; ist er schon dauerhaft freigeschaltet,
   wird abgelehnt (`bp_voucher_access_exists`, Gutschein bleibt unverbraucht).
3. **Idempotent je Code:** Einlöse-Schlüssel ist `code-` + SHA-256 des Codes (nur der Hash wird gespeichert). Der
   Zugang trägt die Referenz `voucher:code-…`; ein zweiter Aufruf für dasselbe Konto liefert dieselbe Freischaltung,
   für ein anderes Konto wird abgelehnt (`bp_voucher_used`). Gesperrt über die Grant-Sperre.
4. Audit-Log: `entitlement_voucher_grant` (Nutzer, Kursprodukt, Einlöse-Schlüssel, Herkunft `voucher_plugin`,
   `source_reference` = `order:{voucher_order_id}`) und `voucher_redeemed`. Ohne WooCommerce wird nichts angelegt
   (`voucher_redeem_skipped`); weitere Fehler stehen als `voucher_redeem_failed` im Audit-Log. „Mein Konto ›
   Lernzugänge“ zeigt die Freischaltung mit Quelle „Kursgutschein“.
5. Einlösungen aus Version 1.11 (damals interne 0-€-Bestellung mit Meta `_bp_voucher_code_hash`) werden weiter
   erkannt: gleiches Konto → Erfolg ohne Neues, anderes Konto → `bp_voucher_used`.

Die Aktion liefert kein Ergebnis zurück. Wer das Ergebnis braucht, ruft direkt auf:

```php
$result = \Bootspruefung\Learning\Extensions\Vouchers::redeem($code, $userId, $productId, ['voucher_order_id' => $orderId]);
// array{created:bool, redemption_key:string, grant:array, order_id:int} oder WP_Error
// grant = Ergebnis von VoucherGrants::grant() (grant_id, entitlements mit valid_from/valid_until, …)
```

Fehlercodes: `bp_voucher_invalid`, `bp_voucher_no_shop`, `bp_voucher_product` (kein Lernzugang hinterlegt,
Produkt entfernt/deaktiviert, Gruppenlizenz oder Upgrade), `bp_voucher_used`, `bp_voucher_access_exists`,
`bp_voucher_busy` (vorübergehend, erneut versuchen). Die Meldungen sind Nutzertexte; `get_error_data()['grant_error']`
nennt den Code des Grant-Dienstes.

**Widerruf** (z. B. Gutscheinkauf erstattet): `apply_filters('bootspruefung_voucher_grants_v1', null)->revoke(
Vouchers::redemptionKey($code), 'Grund')` – wirkt nach der Erstattungsregel der Produktzuordnung.

Beispiel im Gutschein-Plugin:

```php
// Nach erfolgreicher Prüfung und Verbuchung des Kursgutscheins:
do_action('bootspruefung_voucher_redeemed', $code, get_current_user_id(), $voucher->course_product_id, [
    'voucher_order_id' => $voucher->purchase_order_id,
]);
```

### Aktion `bootspruefung_voucher_granted` (Lern → Gutschein)

```php
add_action('bootspruefung_voucher_granted', function (array $grant): void {
    // $grant['redemption_key'], $grant['user_id'], $grant['entitlements'], …
});
```

Bei jeder Freischaltung über den Grant-Dienst (auch Voucher Captain). Die frühere Aktion
`bootspruefung_voucher_order_created` (Version 1.11, interne Bestellung) wird nicht mehr ausgelöst.

**Gutschein-Plugins mit Reservierung** (reservieren → freischalten → verbuchen, wiederholbar bei Fehlern) nutzen
den vollständigen Vertrag in `docs/kursgutscheine.md` statt dieser Aktion; Voucher Captain tut das über das Add-on
`learnboating-voucher-connector` und die App-Routen `POST /vouchers/check` und `/vouchers/redeem`.

### Filter `bootspruefung_voucher_redeem` (App und „Mein Lernen“)

```php
apply_filters('bootspruefung_voucher_redeem', null, string $code, int $user_id);
```

Das Lern-Plugin fragt diesen Filter, wenn jemand in der App (`POST /coupons/redeem`) oder unter
„Mein Konto › Mein Lernen“ einen Code eingibt, der **kein** Gruppen-Beitrittscode und kein Voucher-Captain-Kursgutschein
(App: 422 `COURSE_VOUCHER` mit Verweis auf `/vouchers/redeem`) ist.

| Rückgabe des Gutschein-Plugins | Wirkung |
|---|---|
| `null` | kein Kursgutschein dieses Plugins → bisheriges Verhalten (App: `COUPON_NOT_SUPPORTED` für WooCommerce-Gutscheine, sonst `COUPON_INVALID`) |
| `['product_id' => int, 'voucher_order_id' => int]` | gültig: Das Lern-Plugin schaltet über den Grant-Dienst frei wie bei `bootspruefung_voucher_redeemed` (gleiche Funktion) |
| `WP_Error` | abgelehnt: dessen Meldung wird angezeigt (App: 422 `COUPON_INVALID`, `detail` = Meldung) |

Das **Gutschein-Plugin muss** im Filter den Code prüfen und als eingelöst verbuchen (bzw. reservieren). Es soll
für denselben Vorgang **nicht zusätzlich** die Aktion `bootspruefung_voucher_redeemed` auslösen (schadet aber
nicht: idempotent). Scheitert danach die Freischaltung, löst das Lern-Plugin
`bootspruefung_voucher_redeem_failed` aus – das Gutschein-Plugin sollte die Verbuchung dann zurücknehmen:

```php
add_filter('bootspruefung_voucher_redeem', function ($result, string $code, int $userId) {
    $voucher = my_voucher_find($code);
    if ($voucher === null) {
        return $result; // nicht unserer
    }
    if ($voucher->is_used()) {
        return new WP_Error('voucher_used', __('Dieser Gutschein wurde bereits eingelöst.', 'mein-gutschein'));
    }
    $voucher->mark_used($userId);

    return ['product_id' => $voucher->course_product_id, 'voucher_order_id' => $voucher->purchase_order_id];
}, 10, 3);

add_action('bootspruefung_voucher_redeem_failed', function (string $code, int $userId, WP_Error $error): void {
    my_voucher_find($code)?->release($userId);
}, 10, 3);
```

App-Antwort bei Erfolg (Format wie beim Gruppencode):

```json
{"data": {"redeemed": true, "kind": "course_voucher", "group_name": null, "course_id": "12", "course_name": "SKN",
  "courses": [{"course_id": "12", "code": "SKN", "name": "SKN"}],
  "entitlement": {"status": "active", "valid_from": "2026-11-13T10:00:00Z", "valid_until": "2027-05-14T10:00:00Z"}}}
```

Idempotency-Key und Rate-Limit wie beim Gruppencode: 10 Versuche je Konto und Stunde, App und Web-Formular
zusammen; eine Wiederholung mit demselben Idempotency-Key liefert die gespeicherte Antwort.

Das Formular „Kursgutschein einlösen“ unter „Mein Lernen“ (Nonce, gleiches Limit) erscheint nur, wenn ein Plugin
den Filter belegt (`has_filter('bootspruefung_voucher_redeem')`).

### Filter `bootspruefung_voucher_money_back`

```php
apply_filters('bootspruefung_voucher_money_back', false, int $order_id);
```

Kursgutschein-Freischaltungen sind keine Bestellungen und damit nicht Teil der Geld-zurück-Garantie. Für die
internen 0-€-Bestellungen aus Version 1.11 bietet „Mein Lernen“ standardmäßig **keine** Garantie an
(Status-Grund `voucher`). Ob und wie die Garantie bei Kursgutscheinen gilt (beim Gutscheinkauf? bei der
Einlösung?), entscheidet der Betreiber; `true` schaltet die Garantie der Produktregel für solche Bestellungen zu.

### Filter `bootspruefung_user_owns_course`

```php
$owns = apply_filters('bootspruefung_user_owns_course', false, int $user_id, string $course_code);
```

Antwort des Lern-Plugins: `true`, wenn der Nutzer **vollen Zugang zum Fragenkatalog** des Kurses hat
(`AccessService::full(…, 'question_bank')`: gekaufter, manueller oder Gruppenzugang; auch eine kostenlose
Vollfreigabe bzw. die Einstellung „Zugang ohne Shop: alle angemeldeten Nutzer“ zählt). Kurscode oder Slug
(`SKN`, `skn`, `SBF-SEE`). Gibt der Aufrufer `true` vor, bleibt es `true`.

```php
if (apply_filters('bootspruefung_user_owns_course', false, $userId, 'SBF-SEE')) {
    // z. B. Gutschein für den Kurs nicht noch einmal verkaufen
}
```

### Filter `bootspruefung_coupon_allow_with_member_discount`

```php
$allow = apply_filters('bootspruefung_coupon_allow_with_member_discount', true, $coupon, WC()->cart);
```

Antwort des Lern-Plugins: `false`, wenn im Warenkorb ein **Mitgliederrabatt** des Lern-Plugins greift
(Bootsprüfung → Mitgliederrabatte; vermerkt an einer Warenkorb-Position oder für Kunde und Produkt berechnet).
Ausnahme: Option `bp_learning_voucher_with_member_discount = 'yes'` erlaubt die Kombination; ein späterer Filter
(Priorität > 10) kann ebenfalls umentscheiden. Das **Gutschein-Plugin muss** den Filter selbst fragen, z. B. in
`woocommerce_coupon_is_valid`:

```php
add_filter('woocommerce_coupon_is_valid', function ($valid, WC_Coupon $coupon) {
    if ($valid && my_is_voucher_coupon($coupon) && ! apply_filters('bootspruefung_coupon_allow_with_member_discount', true, $coupon, WC()->cart)) {
        throw new Exception(__('Dieser Gutschein ist nicht mit dem Mitgliederrabatt kombinierbar.', 'mein-gutschein'));
    }
    return $valid;
}, 10, 2);
```

### Geld-zurück-Garantie: bezahlter Betrag und `_bp_paid_by_voucher`

- Die Garantie erstattet je Position den **tatsächlich bezahlten Betrag** (Positionsbetrag nach Rabatten und
  WooCommerce-Gutscheinen, brutto: `get_total() + get_total_tax()`); Positionen ohne Garantie (Produkte ohne
  `_bp_money_back`, Gruppenpakete, Simulator) sind ausgenommen (`MoneyBackWoo::breakdown()`).
- **Gutschein als Zahlungsmittel:** Speichert das Gutschein-Plugin an der Bestellung die Meta
  `_bp_paid_by_voucher` = Betrag (brutto, Dezimalzahl), wird dieser Anteil **nicht als Geld** erstattet: Standard
  `Garantiebetrag × (1 − _bp_paid_by_voucher / Bruttosumme der Bestellung vor dem Gutschein)`; die Bruttosumme
  zählt Positionen, Versand und positive Gebühren. Nur für Gutscheine setzen, die als Zahlung bzw. negative Gebühr
  gebucht werden – **nicht** für WooCommerce-Gutscheine (deren Rabatt steckt schon im Positionsbetrag).
- Filter für eine andere Aufteilung:

```php
add_filter('bootspruefung_money_back_cash_amount', function (float $cash, float $amount, float $paidByVoucher, WC_Order $order): float {
    return $cash; // als Geld zu erstatten; der Rest gilt als Gutscheinanteil
}, 10, 4);
```

Kunde, Bestellnotiz und Betreiber-Mail nennen den Gutscheinanteil („nicht als Geld erstatten“). Ob das
Gutschein-Plugin den Gutscheinanteil wieder gutschreibt, regelt das Gutschein-Plugin bzw. der Betreiber.

---

## Ticket-Plugin → Lern-Plugin

### Aktionen `bootspruefung_ticket_issued`, `…_redeemed`, `…_cancelled`

```php
do_action('bootspruefung_ticket_issued', string $ticket_id, int $order_id, int $user_id, array $args = [
    'course_code' => 'SKN',            // Kurscode des Lern-Plugins
    'title'       => 'Praxisstunde Motorboot',
    'units'       => 5,                // Einheiten gesamt (1–999)
    'date'        => '2026-12-01',     // ISO-Datum/-Zeit oder Anzeigetext
    'location'    => 'Hafen Musterstadt',
]);
do_action('bootspruefung_ticket_redeemed', string $ticket_id, int $units_left, $scanner_id);
do_action('bootspruefung_ticket_cancelled', string $ticket_id, string $reason);
```

- `$ticket_id`: eindeutig, `[A-Za-z0-9._:-]{1,64}`. Erneutes `issued` mit derselben ID aktualisiert das Ticket
  (bereits absolvierte Einheiten bleiben).
- `redeemed`: `$units_left` = verbleibende Einheiten nach der Entwertung; bei 0 Status `redeemed`, sonst
  `issued`. `$scanner_id` wird **nicht** gespeichert (Personenbezug Dritter).
- `cancelled`: Status `cancelled` mit Grund (z. B. „Ausfall wegen Sturm“), sichtbar im Kurs-Hub und in der App.

Gespeichert in der Nutzer-Meta `_bp_tickets` (Liste; je Ticket `id`, `order_id`, `course_code`, `title`,
`units_total`, `units_left`, `status` issued/redeemed/cancelled, `date`, `location`, `reason`, `updated_at`;
höchstens 100 je Konto) und je Ticket `_bp_ticket_id` zum Wiederfinden. Keine personenbezogenen Daten Dritter.
Datenschutz: Export (Gruppe „Praxis-Tickets“) und Löschung (`PersonalData`) umfassen beide Meta-Einträge; die
Tickets selbst führt das Ticket-Plugin (eigene Export-/Löschfunktion nötig).

### Filter `bootspruefung_tickets_for_user`

```php
add_filter('bootspruefung_tickets_for_user', function ($tickets, int $userId) {
    return array_map(fn ($t) => [
        'id' => $t->id, 'course_code' => $t->course, 'title' => $t->title,
        'units_total' => $t->units, 'units_left' => $t->units_left,
        'status' => $t->status,  // issued | redeemed | cancelled
        'date' => $t->date, 'location' => $t->location, 'reason' => $t->cancel_reason,
    ], my_tickets_for($userId));
}, 10, 2);
```

`null` (Standard) = die gespeicherte Liste aus den Aktionen. Eine Liste hat Vorrang (z. B. aktueller Stand nach
einer Umbuchung).

### Anzeige

- **Kurs-Hub der Web-App** (`GET bootspruefung/v1/courses/{code}`, Feld `tickets`): Abschnitt „Praxis-Tickets“ mit
  „Praxis: 3 von 5 Einheiten absolviert“, Termin, Ort, Storno mit Grund und dem Hinweis „kein amtlicher
  Ausbildungsnachweis“. Ohne Tickets kein Abschnitt.
- **App:** `GET /me/tickets` → `{"data": [PracticeTicket, …]}` (ohne Tenant-Header; leer ohne Ticket-Plugin).

### Filter `bootspruefung_practice_offers` – „Praxis in deiner Nähe“

```php
add_filter('bootspruefung_practice_offers', function (array $offers, string $courseCode, ?string $region) {
    foreach (my_offers($courseCode, $region) as $o) {
        $offers[] = ['title' => $o->title, 'url' => get_permalink($o->id), 'date' => $o->date, 'location' => $o->city, 'price' => $o->price_text];
    }
    return $offers;
}, 10, 3);
```

- Pflicht: `title`, `url` (http/https); sonst wird der Eintrag verworfen. Höchstens 20.
- Erscheint im Kurs-Hub (Abschnitt „Praxis in deiner Nähe“) und in der App-Kursantwort (`practice_offers`).
  Ohne Ticket-Plugin leer und unsichtbar.
- `$region`: ISO-3166-2 (z. B. `DE-HH`) oder `null`. Das Lern-Plugin speichert **kein** Prüfungsprofil mit
  Bundesland (die Bundesland-Auswahl der Prüfungsausschuss-Suche bleibt im Browser) – daher ist `$region`
  standardmäßig `null`. Ein Plugin kann sie über `bootspruefung_practice_region` liefern:

```php
add_filter('bootspruefung_practice_region', fn ($region, int $userId, string $courseCode) => get_user_meta($userId, 'mein_bundesland', true) ?: $region, 10, 3);
```

### Ticket-Produkte und Geld-zurück-Garantie

Produkte ohne Meta `_bp_money_back` (`MoneyBack::policy()` = `null`) haben **keine** Geld-zurück-Garantie; in
einer gemischten Bestellung sind sie von der Erstattung ausgenommen. Ticket-Produkte brauchen also nichts zu setzen
(Test: `extensions-test.php`, „Produkte ohne Garantie“).
