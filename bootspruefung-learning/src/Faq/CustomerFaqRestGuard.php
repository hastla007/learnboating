<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

use Bootspruefung\Learning\Security\Capabilities;
use WP_Error;

/**
 * Lesezugriff der Core-REST-API (/wp/v2) auf Kunden-FAQs und ihre Kategorien: nur für Redakteure
 * (manage_bp_content, wie der Block-Editor). Gäste und Kunden bekommen 401/403 – Kunden lesen die
 * FAQs ihres Kurses nur über die Kurs-Endpunkte mit Zugangsprüfung.
 */
final class CustomerFaqRestGuard
{
    public static function check(): ?WP_Error
    {
        if (current_user_can(Capabilities::MANAGE_CONTENT)) {
            return null;
        }

        return new WP_Error('rest_forbidden', __('Kunden-FAQs sind nur für die Redaktion lesbar.', 'bootspruefung-learning'), ['status' => rest_authorization_required_code()]);
    }
}
