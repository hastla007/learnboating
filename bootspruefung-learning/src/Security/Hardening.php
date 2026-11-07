<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Security;

/**
 * Kleine Härtungen für die Lernplattform: Benutzernamen nicht öffentlich aufzählbar
 * (REST-Benutzerliste und Autorenarchive nur für angemeldete Redakteure).
 */
final class Hardening
{
    public static function register(): void
    {
        add_filter('rest_endpoints', static function (array $endpoints): array {
            // Der Block-Editor braucht die Liste (Autorenauswahl) – nur Gäste/Lernende ausschließen.
            if (! current_user_can('edit_posts')) {
                unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);
            }

            return $endpoints;
        });
        // ?author=1 bzw. /author/<login>/ verraten den Anmeldenamen.
        add_action('template_redirect', static function (): void {
            if (is_author() && ! current_user_can('edit_posts')) {
                wp_safe_redirect(home_url('/'), 301);
                exit;
            }
        }, 1);
    }
}
