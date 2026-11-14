<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest;

defined('ABSPATH') || exit;

/**
 * Frischer wp_rest-Nonce für die Lern-App (admin-ajax, Aktion „bp_rest_nonce“).
 *
 * Läuft der Nonce der geöffneten Seite ab (z. B. nach > 24 h oder neuer Anmeldung in einem
 * anderen Tab), lehnt die REST-API jede Anfrage mit rest_cookie_invalid_nonce ab. Dieser
 * Endpunkt braucht selbst keinen Nonce: angemeldet wird über das Login-Cookie (wp_ajax_*),
 * ohne gültige Anmeldung antwortet er mit 401. Der Nonce allein ist kein Geheimnis gegenüber
 * fremden Seiten, weil sie die Antwort (gleiche Herkunft, kein CORS) nicht lesen können.
 */
final class NonceRefresh
{
    public const ACTION = 'bp_rest_nonce';

    public function register(): void
    {
        add_action('wp_ajax_'.self::ACTION, [self::class, 'handle']);
        add_action('wp_ajax_nopriv_'.self::ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        nocache_headers();
        if (! is_user_logged_in()) {
            wp_send_json_error(['code' => 'bp_login_required', 'message' => __('Bitte anmelden.', 'bootspruefung-learning')], 401);
        }
        wp_send_json_success(['nonce' => wp_create_nonce('wp_rest')]);
    }
}
