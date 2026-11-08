<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * § 312f Abs. 2 BGB: Vertragsbestätigung auf einem dauerhaften Datenträger mit den Vertragsbestimmungen.
 * Hängt den vollständigen Text der veröffentlichten Seiten „AGB“ (WooCommerce-Option
 * woocommerce_terms_page_id) und „Widerrufsbelehrung“ (Slug widerruf, inkl. Muster-Widerrufsformular)
 * an die Bestellmails an Kundinnen und Kunden (in Bearbeitung, in Wartestellung, abgeschlossen) an.
 *
 * Der Inhalt wird über do_blocks() gerendert, nicht über the_content: Dessen Theme-Filter markieren
 * Platzhalter für die Seitenansicht. Hinweis-Absätze der Vorlage für den Betreiber (kw-placeholder)
 * werden weggelassen.
 */
final class LegalTextsEmail
{
    public const EMAILS = ['customer_processing_order', 'customer_on_hold_order', 'customer_completed_order'];

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
    }

    public static function heading(): string
    {
        return __('Unsere AGB und Widerrufsbelehrung (Stand bei Bestellung)', 'bootspruefung-learning');
    }

    /**
     * Veröffentlichte Rechtstexte in Anzeigereihenfolge.
     *
     * @return list<\WP_Post>
     */
    public static function pages(): array
    {
        $pages = [];
        $terms = (int) get_option('woocommerce_terms_page_id');
        $agb = $terms > 0 ? get_post($terms) : null;
        if ($agb instanceof \WP_Post && $agb->post_type === 'page' && $agb->post_status === 'publish') {
            $pages[] = $agb;
        }
        $widerruf = get_page_by_path('widerruf', OBJECT, 'page');
        if ($widerruf instanceof \WP_Post && $widerruf->post_status === 'publish' && ! in_array($widerruf->ID, array_map(static fn ($p) => $p->ID, $pages), true)) {
            $pages[] = $widerruf;
        }

        return $pages;
    }

    public function email($order, $sentToAdmin = false, $plainText = false, $email = null): void
    {
        $emailId = is_object($email) && isset($email->id) ? (string) $email->id : '';
        if ($sentToAdmin || ! $order instanceof \WC_Order || ! in_array($emailId, self::EMAILS, true)) {
            return;
        }
        echo $plainText ? self::plain() : self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- in html()/plain() bereinigt
    }

    /** HTML-Fassung: Überschriften, Absätze, Listen und Links bleiben erhalten. */
    public static function html(): string
    {
        $pages = self::pages();
        if ($pages === []) {
            return '';
        }
        $out = '<div class="bp-legal-texts" style="margin-top:32px;border-top:1px solid #e5e5e5;padding-top:16px;">'
            .'<h2>'.esc_html(self::heading()).'</h2>';
        foreach ($pages as $page) {
            $body = self::bodyHtml($page);
            $out .= (self::startsWithTitle($page, $body) ? '' : '<h2>'.esc_html(self::title($page)).'</h2>').$body;
        }

        return $out.'</div>';
    }

    /** Klartext-Fassung für Text-Mails. */
    public static function plain(): string
    {
        $pages = self::pages();
        if ($pages === []) {
            return '';
        }
        $line = str_repeat('=', 60);
        $out = "\n".$line."\n".self::heading()."\n".$line."\n\n";
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
