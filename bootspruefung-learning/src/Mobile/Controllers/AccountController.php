<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Mobile\TokenAuth;
use WP_REST_Request;
use WP_REST_Response;

/** Profil, Mitgliedschaft (Tenant), Marke und Gerätesitzungen. */
final class AccountController extends BaseController
{
    /** @return array{user:array<string,mixed>,memberships:list<array<string,mixed>>} */
    public static function profile(int $userId): array
    {
        $user = get_userdata($userId);
        $name = $user ? trim((string) $user->display_name) : '';

        return [
            'user' => [
                'id' => (string) $userId,
                'name' => $name !== '' ? $name : ($user ? (string) $user->user_login : ''),
                'first_name' => $user ? (string) $user->first_name : '',
                'last_name' => $user ? (string) $user->last_name : '',
                'email' => $user ? (string) $user->user_email : '',
                // Shop-Konten haben keine eigene Bestätigung; der Zugang hängt nicht daran.
                'email_verified' => true,
            ],
            'memberships' => [self::membership()],
        ];
    }

    /** @return array<string,mixed> */
    public static function membership(): array
    {
        $logo = (string) get_site_icon_url(512);
        if ($logo === '' && ($logoId = (int) get_theme_mod('custom_logo')) > 0) {
            $logo = (string) wp_get_attachment_image_url($logoId, 'full');
        }

        return [
            'tenant_slug' => MobileSettings::tenantSlug(),
            'tenant_name' => MobileSettings::tenantName(),
            'status' => 'active',
            'role' => 'learner',
            'branding' => [
                'logo_url' => $logo !== '' ? $logo : null,
                'primary_color' => MobileSettings::primaryColor(),
                'support_email' => MobileSettings::supportEmail(),
            ],
            'links' => self::links(),
        ];
    }

    /** @return array<string,?string> Web-Seiten zum Öffnen im Systembrowser */
    public static function links(): array
    {
        $shop = function_exists('wc_get_page_permalink') && Integration::active() ? (string) wc_get_page_permalink('shop') : '';
        $privacy = (string) get_privacy_policy_url();

        return [
            'web_learning' => Router::url(),
            // Shop-Link der App wie die Kaufangebote durch den Filter (Partnercode, Kampagne).
            'shop' => $shop !== '' ? (string) apply_filters('bootspruefung_shop_offer_url', $shop, null, '', 'shop') : null,
            'account' => function_exists('wc_get_page_permalink') && Integration::active() ? (string) wc_get_page_permalink('myaccount') : null,
            'privacy_policy' => $privacy !== '' ? $privacy : null,
        ] + self::legalLinks();
    }

    /**
     * Rechtstexte für die App (auch ohne Anmeldung über /health): nur veröffentlichte Seiten.
     * Datenschutz aus den WordPress-Einstellungen, AGB aus WooCommerce bzw. Seite „agb“, sonst
     * die Seiten mit den Slugs impressum, widerruf, kontakt (wie das Theme sie anlegt).
     * @return array<string,?string>
     */
    public static function legalLinks(): array
    {
        $page = static function (string $slug): ?string {
            $p = get_page_by_path($slug, OBJECT, 'page');

            return $p instanceof \WP_Post && $p->post_status === 'publish' ? (string) get_permalink($p) : null;
        };
        $terms = null;
        $termsId = (int) get_option('woocommerce_terms_page_id');
        if ($termsId > 0 && get_post_status($termsId) === 'publish') {
            $terms = (string) get_permalink($termsId);
        }
        $privacy = (string) get_privacy_policy_url();
        $withdrawal = $page('widerruf');
        $links = [
            'privacy_policy' => $privacy !== '' ? $privacy : $page('datenschutz'),
            'imprint' => $page('impressum'),
            'terms' => $terms ?? $page('agb'),
            'withdrawal_policy' => $withdrawal,
            // Widerrufsbutton (§ 356a BGB): feste Adresse, leitet zum Formular bzw. zur Belehrung weiter.
            'withdrawal_function' => \Bootspruefung\Learning\Commerce\WooCommerce\Integration::active() || $withdrawal !== null ? home_url('/'.\Bootspruefung\Learning\Commerce\WooCommerce\WithdrawalFunction::SLUG.'/') : null,
            'contact' => $page('kontakt'),
        ];

        /** Filter: Rechtstexte-Links der App anpassen (z. B. andere Seiten). */
        return (array) apply_filters('bootspruefung_mobile_legal_links', $links);
    }

    public function me(): WP_REST_Response
    {
        return $this->ok(self::profile($this->user()));
    }

    public function sessions(): WP_REST_Response
    {
        $current = TokenAuth::current();
        $out = array_map(static fn ($s) => [
            'id' => (string) $s->id,
            'device_name' => (string) $s->device_name,
            'device_platform' => $s->device_platform !== null ? (string) $s->device_platform : null,
            'app_version' => $s->app_version !== null ? (string) $s->app_version : null,
            'created_at' => TokenAuth::iso($s->created_at),
            'last_used_at' => TokenAuth::iso($s->last_used_at),
            'current' => $current !== null && (int) $current->id === (int) $s->id,
        ], TokenAuth::active($this->user()));

        return $this->ok($out);
    }

    public function revokeSession(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['sessionId'];
        if ($id <= 0 || ! TokenAuth::revokeOwn($this->user(), $id)) {
            throw ApiException::notFound(__('Sitzung nicht gefunden.', 'bootspruefung-learning'));
        }

        return $this->noContent();
    }

    /** Alle anderen Geräte abmelden. */
    public function revokeOthers(): WP_REST_Response
    {
        $current = TokenAuth::current();
        TokenAuth::revokeAll($this->user(), 'user', $current !== null ? (int) $current->id : null);

        return $this->noContent();
    }
}
