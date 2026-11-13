<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * § 312f Abs. 2 BGB: Vertragsbestätigung auf einem dauerhaften Datenträger mit den Vertragsbestimmungen.
 * Hängt den vollständigen Text der veröffentlichten Seiten „AGB“ (WooCommerce-Option
 * woocommerce_terms_page_id) und „Widerrufsbelehrung“ (Slug widerruf, inkl. Muster-Widerrufsformular)
 * an die erste dieser Bestellmails an Kundinnen und Kunden (in Bearbeitung, in Wartestellung,
 * abgeschlossen) je Bestellung an – mit Stand-Datum (Versanddatum). Der Versand wird in der Bestellung
 * vermerkt (_bp_legal_texts_sent), weitere Mails derselben Bestellung wiederholen die Texte nicht.
 *
 * Enthält eine der Seiten noch [[Betreiber: …]]-Platzhalter, wird nichts angehängt (und nichts vermerkt);
 * der Betreiber sieht dazu einen Admin-Hinweis.
 *
 * Der Inhalt wird über do_blocks() gerendert, nicht über the_content: Dessen Theme-Filter markieren
 * Platzhalter für die Seitenansicht. Hinweis-Absätze der Vorlage für den Betreiber (kw-placeholder)
 * werden weggelassen.
 */
final class LegalTextsEmail
{
    public const EMAILS = ['customer_processing_order', 'customer_on_hold_order', 'customer_completed_order'];

    /** Zeitpunkt (Unix) des Versands mit Rechtstexten. */
    public const SENT_META = '_bp_legal_texts_sent';

    public const PLACEHOLDER = '[[Betreiber:';

    /** @var array<int,array{email:string,time:int}> In dieser Anfrage vermerkt (Mehrteil-Mails rendern HTML und Text). */
    private static array $sentNow = [];

    private const ALLOWED = [
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'a' => ['href' => true],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
    ];

    public function register(): void
    {
        // Nach den Kundendaten (Adressen: Priorität 20), vor dem Zusatztext der Mail.
        add_action('woocommerce_email_customer_details', [$this, 'email'], 60, 4);
        add_action('admin_notices', [$this, 'adminNotice']);
    }

    public static function heading(?int $time = null): string
    {
        /* translators: %s: Datum TT.MM.JJJJ */
        return sprintf(__('Unsere AGB und Widerrufsbelehrung (Stand: %s)', 'bootspruefung-learning'), (string) wp_date('d.m.Y', $time ?? time()));
    }

    /**
     * Seiten, die noch [[Betreiber: …]]-Platzhalter enthalten.
     *
     * @param list<\WP_Post>|null $pages
     * @return list<\WP_Post>
     */
    public static function withPlaceholders(?array $pages = null): array
    {
        return array_values(array_filter($pages ?? self::pages(), static fn (\WP_Post $p): bool => str_contains((string) $p->post_content, self::PLACEHOLDER)));
    }

    public function adminNotice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $open = self::withPlaceholders();
        if ($open === []) {
            return;
        }
        $links = array_map(static function (\WP_Post $p): string {
            $edit = get_edit_post_link($p->ID);

            return $edit ? '<a href="'.esc_url($edit).'">'.esc_html(self::title($p)).'</a>' : esc_html(self::title($p));
        }, $open);
        $buttons = [];
        if (($operator = \Bootspruefung\Learning\Admin\GoLiveCheck::operatorUrl()) !== null && current_user_can('manage_options')) {
            $buttons[] = [__('Betreiberangaben ausfüllen', 'bootspruefung-learning'), $operator, true];
        }
        \Bootspruefung\Learning\Admin\Notices::add([
            'key' => 'legal_mail_placeholders', 'type' => 'warning', 'version' => md5(implode(',', array_map(static fn (\WP_Post $p) => $p->ID, $open))),
            'title' => __('Rechtstexte enthalten Platzhalter – werden nicht in Bestellmails übernommen.', 'bootspruefung-learning'),
            'text' => __('Die Bestellbestätigung soll AGB und Widerrufsbelehrung enthalten (§ 312f Abs. 2 BGB). Bitte ersetze die [[Betreiber: …]]-Platzhalter in:', 'bootspruefung-learning'),
            'html' => '<p>'.implode(', ', $links).'</p>', // oben escaped
            'buttons' => $buttons,
        ]);
    }

    /**
     * Veröffentlichte Rechtstexte in Anzeigereihenfolge.
     *
     * @return list<\WP_Post>
     */
    public static function pages(): array
    {
        $pages = [];
        // (Filter unten erlaubt Tests und eigene Rechtstext-Seiten.)
        $terms = (int) get_option('woocommerce_terms_page_id');
        $agb = $terms > 0 ? get_post($terms) : null;
        if ($agb instanceof \WP_Post && $agb->post_type === 'page' && $agb->post_status === 'publish') {
            $pages[] = $agb;
        }
        $widerruf = get_page_by_path('widerruf', OBJECT, 'page');
        if ($widerruf instanceof \WP_Post && $widerruf->post_status === 'publish' && ! in_array($widerruf->ID, array_map(static fn ($p) => $p->ID, $pages), true)) {
            $pages[] = $widerruf;
        }
        $pages = apply_filters('bootspruefung_legal_texts_email_pages', $pages);

        return is_array($pages) ? array_values(array_filter($pages, static fn ($p) => $p instanceof \WP_Post)) : [];
    }

    public function email($order, $sentToAdmin = false, $plainText = false, $email = null): void
    {
        $emailId = is_object($email) && isset($email->id) ? (string) $email->id : '';
        if ($sentToAdmin || ! $order instanceof \WC_Order || ! in_array($emailId, self::EMAILS, true)) {
            return;
        }
        $id = (int) $order->get_id();
        $sent = (int) $order->get_meta(self::SENT_META, true);
        $now = self::$sentNow[$id] ?? null;
        if ($sent > 0 && ($now === null || $now['email'] !== $emailId)) {
            return; // schon mit einer früheren Mail verschickt
        }
        $pages = self::pages();
        if ($pages === [] || self::withPlaceholders($pages) !== []) {
            return;
        }
        $time = $now['time'] ?? time();
        $out = $plainText ? self::plain($time) : self::html($time);
        if ($out === '') {
            return;
        }
        if ($id > 0 && $sent === 0) {
            $order->update_meta_data(self::SENT_META, (string) $time);
            $order->save_meta_data();
            self::$sentNow[$id] = ['email' => $emailId, 'time' => $time];
        }
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- in html()/plain() bereinigt
    }

    /** HTML-Fassung: Überschriften, Absätze, Listen und Links bleiben erhalten. */
    public static function html(?int $time = null): string
    {
        $pages = self::pages();
        if ($pages === []) {
            return '';
        }
        $out = '<div class="bp-legal-texts" style="margin-top:32px;border-top:1px solid #e5e5e5;padding-top:16px;">'
            .'<h2>'.esc_html(self::heading($time)).'</h2>';
        foreach ($pages as $page) {
            $body = self::bodyHtml($page);
            $out .= (self::startsWithTitle($page, $body) ? '' : '<h2>'.esc_html(self::title($page)).'</h2>').$body;
        }

        return $out.'</div>';
    }

    /** Klartext-Fassung für Text-Mails. */
    public static function plain(?int $time = null): string
    {
        $pages = self::pages();
        if ($pages === []) {
            return '';
        }
        $line = str_repeat('=', 60);
        $out = "\n".$line."\n".self::heading($time)."\n".$line."\n\n";
        foreach ($pages as $page) {
            $title = self::title($page);
            $body = self::bodyHtml($page);
            if (self::startsWithTitle($page, $body)) {
                $body = (string) preg_replace('#^<h[1-6]>.*?</h[1-6]>#s', '', $body, 1);
            }
            $out .= mb_strtoupper($title, 'UTF-8')."\n".str_repeat('-', max(3, mb_strlen($title, 'UTF-8')))."\n\n".self::toText($body)."\n\n";
        }

        return $out;
    }

    private static function title(\WP_Post $page): string
    {
        return html_entity_decode(wp_strip_all_tags(get_the_title($page)), ENT_QUOTES, 'UTF-8');
    }

    /** Beginnt der Seitentext schon mit dem Seitentitel als Überschrift (z. B. „Widerrufsbelehrung“)? */
    private static function startsWithTitle(\WP_Post $page, string $body): bool
    {
        return (bool) preg_match('#^<h[1-6]>(.*?)</h[1-6]>#s', $body, $m)
            && trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8')) === trim(self::title($page));
    }

    private static function bodyHtml(\WP_Post $page): string
    {
        $html = do_blocks((string) $page->post_content);
        // Hinweis-Absätze für den Betreiber gehören nicht in die Vertragsbestätigung.
        $html = (string) preg_replace('#<(p|div)\b[^>]*class="[^"]*\bkw-placeholder\b[^"]*"[^>]*>.*?</\1>#s', '', $html);
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = wp_kses($html, self::ALLOWED);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $html));
    }

    private static function toText(string $html): string
    {
        // Links mit Adresse ausschreiben (im Klartext sonst nicht nutzbar).
        $html = (string) preg_replace_callback(
            '#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#s',
            static function (array $m): string {
                $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                $text = trim(wp_strip_all_tags($m[2]));

                return $url === '' || $url === $text ? $text : $text.' ('.$url.')';
            },
            $html
        );
        $html = (string) preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|h[1-6]|ul|ol|table|tr)>#i', "\n\n", $html);
        $html = (string) preg_replace('#<h[1-6]\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n[ \t]+(?!-)/", "\n", $text);
        $text = (string) preg_replace("/\n\s*\n- /", "\n- ", $text); // Listenpunkte ohne Leerzeilen dazwischen

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
