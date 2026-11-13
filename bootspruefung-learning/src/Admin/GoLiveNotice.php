<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Livegang-Warnung „Zugang ohne Shop“ (To-do T15): Ist der Shop für Kunden sichtbar (WooCommerce-Modus
 * „Demnächst verfügbar“ aus) und gibt es veröffentlichte Lernprodukte, aber „Zugang ohne Shop“ steht noch auf
 * „Alle angemeldeten Nutzer“, lernen alle Angemeldeten kostenlos. Dann zeigt der Admin überall eine Warnung mit
 * Knopf zum Umstellen auf „Nur kostenlose Regeln und Freischaltungen“ (protokolliert im Audit-Log).
 */
final class GoLiveNotice
{
    public const ACTION = 'bp_learning_set_entitled_only';

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
        add_action('admin_post_'.self::ACTION, [$this, 'handle']);
    }

    /** Shop sichtbar, Lernprodukte veröffentlicht, aber noch kostenloser Zugang für alle Angemeldeten? */
    public static function shouldWarn(): bool
    {
        if (Settings::get('default_access') !== 'logged_in' || ! class_exists('WooCommerce') || get_option('woocommerce_coming_soon') === 'yes') {
            return false;
        }

        return self::publishedLearningProducts() > 0;
    }

    private static function publishedLearningProducts(): int
    {
        global $wpdb;
        $ids = (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->productIds();
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_map('intval', $ids));

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_status = 'publish'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nur Ganzzahlen
    }

    public function render(): void
    {
        if (! current_user_can('manage_options') || ! self::shouldWarn()) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>'.esc_html__('Lernplattform: Der Shop ist für Kunden sichtbar, aber alle angemeldeten Nutzer lernen noch kostenlos.', 'bootspruefung-learning').'</strong> '
            .esc_html__('„Zugang ohne Shop“ steht auf „Alle angemeldeten Nutzer“. Stell auf „Nur kostenlose Regeln und Freischaltungen“ um, damit nur gekaufte Kurse und die kostenlosen Teilumfänge freigeschaltet sind.', 'bootspruefung-learning').'</p>'
            .'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0 0 .8em"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">'
            .wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            .'<button type="submit" class="button button-primary">'.esc_html__('Jetzt umstellen', 'bootspruefung-learning').'</button></form></div>';
    }

    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), 403);
        }
        check_admin_referer(self::ACTION);
        self::apply();
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** Auf „Nur kostenlose Regeln und Freischaltungen“ umstellen und protokollieren. */
    public static function apply(): void
    {
        $settings = Settings::all();
        if (($settings['default_access'] ?? '') === 'entitled_only') {
            return;
        }
        update_option(Settings::OPTION, array_merge($settings, ['default_access' => 'entitled_only']));
        AuditLog::record('settings_default_access', 'settings', 'default_access', __('Livegang: Zugang ohne Shop auf „Nur kostenlose Regeln und Freischaltungen“ umgestellt', 'bootspruefung-learning'), ['from' => 'logged_in', 'to' => 'entitled_only']);
    }
}
