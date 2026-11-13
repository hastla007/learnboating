<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Frontend;

use Bootspruefung\Learning\Gate\ContentGate;

/**
 * Seiten, die je Person verschieden sind, als nicht zwischenspeicherbar kennzeichnen (T78): Lernbereich
 * /bootsscheine/… (mit Gruppeneinladung „gruppe/<token>“ und Abmeldeseite „erinnerungen“), Widerrufsseite
 * und Seiten mit geschütztem Inhalt (Metabox „Lernzugang erforderlich“, Block bzw. Shortcode „Nur mit
 * Lernzugang“). Sonst könnte ein Seiten-Cache die Seite eines Käufers an Gäste ausliefern oder umgekehrt.
 *
 * Gesetzt wird alles, was gängige Caches verstehen:
 * - Konstanten DONOTCACHEPAGE, DONOTCACHEOBJECT, DONOTCACHEDB (WP Super Cache, W3 Total Cache, WP Rocket u. a.),
 * - nocache_headers() (Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private),
 * - LiteSpeed Cache: Aktion litespeed_control_set_nocache und Kopfzeile X-LiteSpeed-Cache-Control: no-cache
 *   (wirkt auch ohne das LiteSpeed-Plugin direkt im LiteSpeed-Server).
 */
final class NoCache
{
    /** @var array<string,true> Gründe dieser Anfrage (Anzeige, Tests) */
    private static array $reasons = [];

    public static function register(): void
    {
        // Vor allen Weiterleitungen und Ausgaben (ReminderOptOut, Widerruf und Gate laufen ab Priorität 1).
        add_action('template_redirect', [self::class, 'detect'], 0);
    }

    /** Aktuelle Anfrage prüfen und ggf. kennzeichnen. */
    public static function detect(): void
    {
        if (get_query_var('bp_app')) {
            self::mark('learning-area');

            return;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(\Bootspruefung\Learning\Commerce\WooCommerce\WithdrawalFunction::ENDPOINT)) {
            self::mark('withdrawal');

            return;
        }
        if (is_singular()) {
            $post = get_queried_object();
            if ($post instanceof \WP_Post && self::protects($post)) {
                self::mark('protected-content');
            }
        }
    }

    /** Hat der Beitrag geschützten Inhalt (ganz oder in Abschnitten) bzw. die Widerrufsfunktion? */
    public static function protects(\WP_Post $post): bool
    {
        $content = (string) $post->post_content;

        // Eigene Shortcodes am Text erkennen: has_shortcode() greift nur, wenn der Shortcode registriert ist (die
        // Widerrufsfunktion z. B. nur mit passendem WooCommerce) – die Seite darf trotzdem nie im Cache landen.
        $shortcodes = str_contains($content, '[')
            && preg_match('/'.get_shortcode_regex(['bootspruefung_zugang', 'bootspruefung_vertrag_widerrufen']).'/', $content) === 1;

        return ContentGate::requirement((int) $post->ID) !== null
            || $shortcodes
            || (function_exists('has_block') && has_block('bootspruefung/zugang', $content));
    }

    /** Diese Antwort nicht zwischenspeichern. Mehrfacher Aufruf ist unschädlich. */
    public static function mark(string $reason): void
    {
        $first = self::$reasons === [];
        self::$reasons[$reason] = true;
        foreach (['DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB'] as $constant) {
            if (! defined($constant)) {
                define($constant, true);
            }
        }
        if (! $first) {
            return;
        }
        /** LiteSpeed Cache (Plugin): diese Seite nicht speichern. */
        do_action('litespeed_control_set_nocache', 'bootspruefung-learning: '.$reason);
        if (! headers_sent()) {
            nocache_headers();
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
        /** Aktion: Antwort wurde als nicht zwischenspeicherbar gekennzeichnet (z. B. für eigene CDN-Regeln). */
        do_action('bootspruefung_nocache', $reason);
    }

    /** @return list<string> Gründe dieser Anfrage */
    public static function reasons(): array
    {
        return array_keys(self::$reasons);
    }

    /** Nur für Tests: Gründe vergessen (die Konstanten bleiben in diesem Prozess gesetzt). */
    public static function reset(): void
    {
        self::$reasons = [];
    }
}
