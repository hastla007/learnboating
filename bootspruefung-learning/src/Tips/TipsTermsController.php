<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Tips;

defined('ABSPATH') || exit;

/** /wp/v2/bp_tip_category: wie der Core-Controller, Lesen aber nur mit Bearbeitungsrecht (TipsRest). */
final class TipsTermsController extends \WP_REST_Terms_Controller
{
    public function get_items_permissions_check($request)
    {
        return TipsRest::check() ?? parent::get_items_permissions_check($request);
    }

    public function get_item_permissions_check($request)
    {
        return TipsRest::check() ?? parent::get_item_permissions_check($request);
    }
}
