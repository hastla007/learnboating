<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/** /wp/v2/bp_customer_faq: wie der Core-Controller, Lesen aber nur für Redakteure (CustomerFaqRestGuard). */
final class CustomerFaqPostsController extends \WP_REST_Posts_Controller
{
    public function get_items_permissions_check($request)
    {
        return CustomerFaqRestGuard::check() ?? parent::get_items_permissions_check($request);
    }

    public function get_item_permissions_check($request)
    {
        return CustomerFaqRestGuard::check() ?? parent::get_item_permissions_check($request);
    }
}
