<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/** /wp/v2/bp_cfaq_category: wie der Core-Controller, Lesen aber nur für Redakteure (CustomerFaqRestGuard). */
final class CustomerFaqTermsController extends \WP_REST_Terms_Controller
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
