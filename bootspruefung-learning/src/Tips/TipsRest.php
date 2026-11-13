<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Tips;

use WP_Error;

/**
 * Lesezugriff der Core-REST-API (/wp/v2/bp_tip, /wp/v2/bp_tip_category) auf Tipps: nur für Konten, die Tipps
 * bearbeiten dürfen (edit_posts, wie der Block-Editor). Gäste und Kunden bekommen 401/403 – Lernende lesen Tipps
 * über /bootspruefung/v1/tips im Lernbereich (T88).
 */
final class TipsRest
{
    public static function check(): ?WP_Error
    {
        $type = get_post_type_object(Tips::POST_TYPE);
        if (current_user_can($type !== null ? $type->cap->edit_posts : 'edit_posts')) {
            return null;
        }

        return new WP_Error('rest_forbidden', __('Tipps sind über diese Schnittstelle nur für die Redaktion lesbar.', 'bootspruefung-learning'), ['status' => rest_authorization_required_code()]);
    }
}
