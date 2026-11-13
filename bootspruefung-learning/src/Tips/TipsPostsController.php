<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Tips;

defined('ABSPATH') || exit;

/** /wp/v2/bp_tip: wie der Core-Controller, Lesen aber nur mit Bearbeitungsrecht (TipsRest). */
final class TipsPostsController extends \WP_REST_Posts_Controller
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
