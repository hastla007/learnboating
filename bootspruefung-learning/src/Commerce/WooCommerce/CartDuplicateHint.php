<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\AccessStatus;
use Bootspruefung\Learning\Commerce\ExpiryReminder;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Hinweis im Warenkorb und an der Kasse gegen Doppelkäufe (wie CartRelationHint: Hinweis, keine Sperre).
 * Liegt ein Kurs im Warenkorb, für den der angemeldete Kunde schon einen Zugang hat oder dessen
 * Bestellung noch auf Zahlung wartet, sagt der Hinweis das – mit Link zur offenen Bestellung bzw. mit
 * dem neuen Laufzeitende. Verlängern eines abgelaufenen oder bald ablaufenden Zugangs ist erwünscht und
 * wird als Verlängerung erklärt. Nur für angemeldete Kunden; abschaltbar über den Filter
 * `bootspruefung_cart_duplicate_hint`.
 */
final class CartDuplicateHint
{
    /** Katalog-Gruppen mit einem Hauptkurs (wie CartRelationHint); Gruppen und Simulator nicht. */
    private const GROUPS = ['sbf', 'funk', 'signal', 'addon'];

    /** Pakete (Kapitäns-Bundle, Pässe): enthaltene Kurse einzeln prüfen (T50). */
    private const PACKAGE_GROUPS = ['bundle'];

    public const ORDERED = 'ordered';
    public const OWNED = 'owned';
    public const OWNED_UNLIMITED = 'owned_unlimited';
    public const RENEW_SOON = 'renew_soon';
    public const RENEW_EXPIRED = 'renew_expired';
    public const SUSPENDED = 'suspended';
    /** Kombi, Bundle oder Pass: einige bzw. alle enthaltenen Kurse hat der Kunde schon (T50). */
    public const OVERLAP = 'overlap';

    public function register(): void
    {
        add_action('woocommerce_before_cart', [$this, 'classicBox'], 6);
        add_action('woocommerce_before_checkout_form', [$this, 'classicBox'], 5);
        add_filter('render_block_woocommerce/cart', [$this, 'blockBox'], 33, 1);
        add_filter('render_block_woocommerce/checkout', [$this, 'blockBox'], 33, 1);
    }

    /**
     * Ermittlung ohne Datenbank und ohne WooCommerce.
     *
     * @param array<string,array<string,mixed>> $states Kaufstatus je Kurs (AccessStatus::forUser)
     * @param int|null $days Laufzeit des Produkts im Warenkorb (null = unbefristet bzw. unbekannt)
     * @param string $renewalMode extend (schließt an) oder parallel
     * @return array{kind:string,sku:string,course:string,until:?string,new_until:?string,days:?int,order_id:?int,video_hint:bool}|null
     */
    public static function evaluate(array $states, string $sku, ?int $days, string $now, int $soonDays, string $renewalMode = 'extend'): ?array
    {
        $base = ShopCatalog::tierBase($sku);
        if ($base !== null && str_ends_with($sku, ShopCatalog::VIDEO_SUFFIX)) {
            return null; // Video-Upgrade: eigener Hinweis (VideoUpgrade)
        }
        $def = ShopCatalog::find($base ?? $sku);
        $group = (string) ($def['group'] ?? '');
        if ($def === null || ! in_array($group, [...self::GROUPS, ...self::PACKAGE_GROUPS], true) || ($def['courses'] ?? []) === []) {
            return null;
        }
        $main = (string) $def['courses'][0];
        $state = $states[$main] ?? null;
        // Pakete immer, Kombis ohne eigenen Kauf: enthaltene Kurse einzeln prüfen (T50).
        if (in_array($group, self::PACKAGE_GROUPS, true) || ($state === null && count($def['courses']) > 1)) {
            return self::overlap($states, $sku, array_map('strval', $def['courses']), $now, $days, $renewalMode);
        }
        if ($state === null) {
            return null;
        }
        $until = $state['valid_until'] ?? null;
        $soon = gmdate('Y-m-d H:i:s', (int) strtotime($now.' UTC') + $soonDays * DAY_IN_SECONDS);
        $pending = (array) ($state['pending_orders'] ?? []);
        $kind = match (true) {
            $pending !== [] || $state['status'] === 'pending' => self::ORDERED,
            $state['status'] === 'active' && $until === null => self::OWNED_UNLIMITED,
            $state['status'] === 'active' && $until > $soon => self::OWNED,
            $state['status'] === 'active' => self::RENEW_SOON,
            $state['status'] === 'suspended' => self::SUSPENDED,
            $state['status'] === 'expired' => self::RENEW_EXPIRED,
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $newUntil = null;
        if (in_array($kind, [self::OWNED, self::RENEW_SOON], true) && $days !== null && $days > 0 && $renewalMode === 'extend') {
            $newUntil = gmdate('Y-m-d H:i:s', (int) strtotime(max((string) $until, $now).' UTC') + $days * DAY_IN_SECONDS);
        }

        return [
            'kind' => $kind,
            'sku' => $sku,
            'course' => $main,
            'until' => $until,
            'new_until' => $newUntil,
            'days' => $days,
            'order_id' => $pending !== [] ? (int) $pending[count($pending) - 1] : ($state['order_id'] ?? null),
            // Komplett-Stufe gewählt, Videokurs fehlt noch: Hinweis auf das Video-Upgrade.
            'video_hint' => $base !== null && empty($state['video']) && in_array($kind, [self::OWNED, self::OWNED_UNLIMITED], true),
            // Theorie-Stufe verlängert, Videokurs vorhanden: Der Videokurs wird nicht mitverlängert (T50).
            'video_lost' => $base === null && ! empty($state['video']) && in_array($kind, [self::OWNED, self::RENEW_SOON], true) && $until !== null,
            'parts' => [],
            'all_parts' => false,
        ];
    }

    /**
     * Kombi, Bundle, Pass: Welche enthaltenen Kurse hat der Kunde schon bzw. bestellt?
     *
     * @param array<string,array<string,mixed>> $states
     * @param list<string> $courses
     * @return array<string,mixed>|null
     */
    private static function overlap(array $states, string $sku, array $courses, string $now, ?int $days, string $renewalMode): ?array
    {
        $owned = [];
        foreach ($courses as $code) {
            $st = $states[$code] ?? null;
            if ($st === null) {
                continue;
            }
            if ((array) ($st['pending_orders'] ?? []) !== [] || $st['status'] === 'pending') {
                $pending = (array) ($st['pending_orders'] ?? []);

                return ['kind' => self::ORDERED, 'sku' => $sku, 'course' => $code, 'until' => $st['valid_until'] ?? null, 'new_until' => null, 'days' => $days,
                    'order_id' => $pending !== [] ? (int) $pending[count($pending) - 1] : ($st['order_id'] ?? null), 'video_hint' => false, 'video_lost' => false, 'parts' => [], 'all_parts' => false];
            }
            if ($st['status'] === 'active' && ($st['valid_until'] === null || $st['valid_until'] >= $now)) {
                $owned[] = $code;
            }
        }
        if ($owned === []) {
            return null;
        }
        // Mitgekaufte Teilkurse (z. B. SBF See in der Kombi See & Binnen) nicht doppelt nennen.
        $owned = array_keys(\Bootspruefung\Learning\Commerce\ExpiryReminder::collapse(array_fill_keys($owned, '')));

        return ['kind' => self::OVERLAP, 'sku' => $sku, 'course' => $owned[0], 'until' => null, 'new_until' => null, 'days' => $days, 'order_id' => null,
            'video_hint' => false, 'video_lost' => false, 'parts' => $owned, 'all_parts' => count($owned) >= count(array_keys(\Bootspruefung\Learning\Commerce\ExpiryReminder::collapse(array_fill_keys($courses, '')))), 'renewal' => $renewalMode];
    }

    /**
     * Hinweise für einen Nutzer: je Kurs höchstens einer.
     *
     * @param array<string,array{sku:string,product_id:int,variation_id:int}> $items Warenkorb-Schlüssel => Produkt
     * @return array<string,array<string,mixed>>
     */
    public static function forUser(int $userId, array $items, ?string $now = null): array
    {
        /** Filter: Hinweis auf schon gekaufte bzw. bestellte Kurse im Warenkorb abschalten (false). */
        if ($userId <= 0 || $items === [] || ! apply_filters('bootspruefung_cart_duplicate_hint', true, $userId)) {
            return [];
        }
        $states = AccessStatus::forUser($userId);
        if ($states === []) {
            return [];
        }
        global $wpdb;
        $repo = new ProductAccessRepository($wpdb);
        $now ??= current_time('mysql', true);
        $out = [];
        $seen = [];
        foreach ($items as $key => $item) {
            $def = ShopCatalog::find(ShopCatalog::tierBase($item['sku']) ?? $item['sku']);
            $main = (string) ($def['courses'][0] ?? '');
            [$days, $mode] = self::term($repo->forItem($item['product_id'], $item['variation_id']), $main);
            $hint = self::evaluate($states, $item['sku'], $days, $now, ExpiryReminder::days(), $mode);
            if ($hint === null || isset($seen[$hint['course']])) {
                continue;
            }
            $seen[$hint['course']] = true;
            $out[(string) $key] = $hint;
        }

        return $out;
    }

    /**
     * Laufzeit und Verlängerungsart des Produkts für den Kurs (Zuordnung Fragenkatalog bzw. alle Funktionen).
     * @param list<object> $rows
     * @return array{0:?int,1:string}
     */
    private static function term(array $rows, string $course): array
    {
        foreach ($rows as $row) {
            if ((string) $row->course_code === $course && in_array((string) $row->feature_code, [AccessService::QUESTION_BANK, ProductAccessRepository::FEATURE_ALL], true)) {
                $days = (int) ($row->duration_days ?? 0);

                return [$days > 0 ? $days : null, (string) ($row->renewal_mode ?? 'extend')];
            }
        }

        return [null, 'extend'];
    }

    /** @return array{flag:string,title:string,text:string} Texte des Hinweises */
    public static function message(array $hint): array
    {
        $course = Labels::course((string) $hint['course']);
        $date = static fn (?string $mysql): string => $mysql !== null ? ExpiryReminder::date($mysql) : '';
        switch ($hint['kind']) {
            case self::ORDERED:
                $order = $hint['order_id'] ? AccessStatus::order((int) $hint['order_id']) : null;
                $number = $order['number'] ?? (string) ($hint['order_id'] ?? '');

                return [
                    'flag' => __('Schon bestellt', 'bootspruefung-learning'),
                    'title' => __('Diesen Kurs hast du schon bestellt', 'bootspruefung-learning'),
                    /* translators: 1: order number, 2: course name */
                    'text' => sprintf(__('Deine Bestellung #%1$s für %2$s wartet noch auf deine Zahlung. Sobald sie eingegangen ist, wird dein Zugang automatisch freigeschaltet – ein zweiter Kauf ist dafür nicht nötig.', 'bootspruefung-learning'), $number, $course),
                ];
            case self::OWNED_UNLIMITED:
                return [
                    'flag' => __('Schon in deinem Konto', 'bootspruefung-learning'),
                    'title' => __('Diesen Kurs hast du schon', 'bootspruefung-learning'),
                    /* translators: %s: course name */
                    'text' => sprintf(__('Du hast %s schon unbefristet freigeschaltet – ein weiterer Kauf ist dafür nicht nötig.', 'bootspruefung-learning'), $course)
                        .($hint['video_hint'] ? ' '.__('Für den Videokurs allein reicht das Video-Upgrade.', 'bootspruefung-learning') : ''),
                ];
            case self::OVERLAP:
                $names = implode(', ', array_map([Labels::class, 'course'], (array) $hint['parts']));

                return [
                    'flag' => __('Teilweise schon in deinem Konto', 'bootspruefung-learning'),
                    'title' => ! empty($hint['all_parts']) ? __('Alle enthaltenen Kurse hast du schon', 'bootspruefung-learning') : __('Einen Teil davon hast du schon', 'bootspruefung-learning'),
                    /* translators: %s: course names */
                    'text' => sprintf(__('Diese Kurse aus dem Angebot hast du schon: %s.', 'bootspruefung-learning'), $names).' '
                        .(($hint['renewal'] ?? 'extend') === 'extend'
                            ? __('Mit dem Kauf verlängert sich ihr Zugang. Brauchst du nur die übrigen Kurse, wähle sie einzeln.', 'bootspruefung-learning')
                            : __('Du bezahlst sie mit diesem Angebot noch einmal. Brauchst du nur die übrigen Kurse, wähle sie einzeln.', 'bootspruefung-learning')),
                ];
            case self::OWNED:
                $text = $hint['new_until'] !== null
                    /* translators: 1: course name, 2: end date, 3: new end date */
                    ? sprintf(__('Dein Zugang zu %1$s läuft noch bis %2$s. Kaufst du ihn noch einmal, verlängert sich dein Zugang bis %3$s.', 'bootspruefung-learning'), $course, $date($hint['until']), $date($hint['new_until']))
                    /* translators: 1: course name, 2: end date */
                    : sprintf(__('Dein Zugang zu %1$s läuft noch bis %2$s.', 'bootspruefung-learning'), $course, $date($hint['until']));

                return [
                    'flag' => __('Schon in deinem Konto', 'bootspruefung-learning'),
                    'title' => __('Diesen Kurs hast du schon', 'bootspruefung-learning'),
                    'text' => $text.($hint['video_hint'] ? ' '.__('Für den Videokurs allein reicht das Video-Upgrade.', 'bootspruefung-learning') : '').self::videoLostText($hint),
                ];
            case self::RENEW_SOON:
                return [
                    'flag' => __('Verlängerung', 'bootspruefung-learning'),
                    'title' => __('Du verlängerst deinen Zugang', 'bootspruefung-learning'),
                    'text' => ($hint['new_until'] !== null
                        /* translators: 1: course name, 2: end date, 3: new end date */
                        ? sprintf(__('Dein Zugang zu %1$s läuft am %2$s ab. Mit diesem Kauf verlängerst du ihn bis %3$s; dein Lernstand bleibt erhalten.', 'bootspruefung-learning'), $course, $date($hint['until']), $date($hint['new_until']))
                        /* translators: 1: course name, 2: end date */
                        : sprintf(__('Dein Zugang zu %1$s läuft am %2$s ab. Mit diesem Kauf verlängerst du ihn; dein Lernstand bleibt erhalten.', 'bootspruefung-learning'), $course, $date($hint['until'])))
                        .self::videoLostText($hint),
                ];
            case self::RENEW_EXPIRED:
                $term = $hint['days'] !== null
                    /* translators: %s: duration, e.g. "12 Monate" */
                    ? ' '.sprintf(__('Der neue Zugang läuft %s ab Zahlungseingang.', 'bootspruefung-learning'), Labels::duration((int) $hint['days']))
                    : '';

                return [
                    'flag' => __('Verlängerung', 'bootspruefung-learning'),
                    'title' => __('Du verlängerst deinen Zugang', 'bootspruefung-learning'),
                    /* translators: 1: course name, 2: date */
                    'text' => sprintf(__('Dein Zugang zu %1$s ist am %2$s abgelaufen. Mit diesem Kauf schaltest du ihn wieder frei; dein Lernstand ist noch gespeichert.', 'bootspruefung-learning'), $course, $date($hint['until'])).$term,
                ];
            default: // gesperrt
                return [
                    'flag' => __('Hinweis', 'bootspruefung-learning'),
                    'title' => __('Dein Zugang ist gesperrt', 'bootspruefung-learning'),
                    /* translators: %s: course name */
                    'text' => sprintf(__('Dein Zugang zu %s ist derzeit gesperrt, zum Beispiel wegen einer Rückbuchung. Bitte melde dich bei uns, bevor du den Kurs noch einmal kaufst.', 'bootspruefung-learning'), $course),
                ];
        }
    }

    /** Theorie-Stufe verlängert, Videokurs vorhanden: Hinweis, dass der Videokurs nicht mitverlängert wird. */
    private static function videoLostText(array $hint): string
    {
        if (empty($hint['video_lost'])) {
            return '';
        }

        return ' '.sprintf(
            /* translators: %s: date */
            __('Achtung: Der Videokurs ist in der Theorie-Stufe nicht enthalten und endet weiterhin am %s. Willst du die Videos weiter nutzen, wähle „Komplett mit Videokurs“.', 'bootspruefung-learning'),
            ExpiryReminder::date((string) $hint['until'])
        );
    }

    /** @return array<string,array{sku:string,product_id:int,variation_id:int}> Warenkorb-Schlüssel => Produkt */
    private static function cartItems(): array
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return [];
        }
        $items = [];
        foreach (WC()->cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            if ($product instanceof \WC_Product) {
                $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : null;
                $sku = $product->get_sku() ?: ($parent ? $parent->get_sku() : '');
                if ($sku !== '') {
                    $items[(string) $key] = ['sku' => (string) $sku, 'product_id' => (int) ($item['product_id'] ?? 0), 'variation_id' => (int) ($item['variation_id'] ?? 0)];
                }
            }
        }

        return $items;
    }

    /** Boxen für den aktuellen Warenkorb (eine je Kurs). */
    public static function box(): string
    {
        $hints = self::forUser(get_current_user_id(), self::cartItems());
        $html = '';
        $i = 0;
        foreach ($hints as $hint) {
            $msg = self::message($hint);
            $id = 'bp-duplicate-hint-h'.(++$i);
            $link = '';
            if ($hint['kind'] === self::ORDERED && $hint['order_id']) {
                $order = AccessStatus::order((int) $hint['order_id']);
                if ($order !== null && $order['url'] !== null) {
                    /* translators: %s: order number */
                    $link = '<p><a href="'.esc_url($order['url']).'">'.esc_html(sprintf(__('Bestellung #%s ansehen', 'bootspruefung-learning'), $order['number'])).'</a></p>';
                }
            } elseif ($hint['kind'] === self::SUSPENDED && ($contact = AccessStatus::contactUrl()) !== null) {
                $link = '<p><a href="'.esc_url($contact).'">'.esc_html__('Kontakt aufnehmen', 'bootspruefung-learning').'</a></p>';
            }
            $html .= '<aside class="bp-upgrade bp-relation-hint bp-duplicate-hint is-'.esc_attr($hint['kind']).'" aria-labelledby="'.esc_attr($id).'"><p class="bp-upgrade-flag">'.esc_html($msg['flag']).'</p>'
                .'<h2 id="'.esc_attr($id).'">'.esc_html($msg['title']).'</h2><p>'.esc_html($msg['text']).'</p>'.$link.'</aside>';
        }

        return $html;
    }

    public function blockBox($content)
    {
        if (is_admin() || ! is_user_logged_in()) {
            return $content;
        }
        $box = self::box();

        return $box !== '' ? SimUpgrade::styles().$box.$content : $content;
    }

    public function classicBox(): void
    {
        if (! is_user_logged_in()) {
            return;
        }
        $box = self::box();
        if ($box !== '') {
            echo SimUpgrade::styles().$box; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
        }
    }
}
