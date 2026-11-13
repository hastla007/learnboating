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
        Notices::register(); // gemeinsame Admin-Hinweise (T99)
        GoLiveChecklist::register(); // Livegang-Checkliste auf der Übersicht (T99)
        add_action('admin_notices', [$this, 'render']);
        add_action('admin_post_'.self::ACTION, [$this, 'handle']);
    }

    /** Shop sichtbar, Lernprodukte veröffentlicht, aber noch kostenloser Zugang für alle Angemeldeten? */
    public static function shouldWarn(): bool
    {
        if (Settings::get('default_access') !== 'logged_in' || ! class_exists('WooCommerce') || get_option('woocommerce_coming_soon') === 'yes') {
            return false;
        }

        return GoLiveCheck::publishedLearningProducts() > 0;
    }

    /** Shop für Kunden sichtbar (WooCommerce aktiv, „Demnächst verfügbar“ aus)? */
    public static function shopVisible(): bool
    {
        return class_exists('WooCommerce') && get_option('woocommerce_coming_soon') !== 'yes';
    }

    /**
     * Livegang-Ampel als Hinweis (T97): Ist der Shop schon sichtbar und ist ein Punkt rot, Warnung auf Dashboard,
     * Bootsprüfung-Übersicht und WooCommerce-Einstellungen. Ist er noch „Demnächst verfügbar“, zeigt der Reiter
     * WooCommerce › Einstellungen › Sichtbarkeit die Ampel vor dem Umschalten (Rot blockiert nicht).
     */
    public static function ampelNotice(): string
    {
        $args = self::ampelArgs();

        return $args === null ? '' : Notices::render($args);
    }

    /** @return array<string,mixed>|null Hinweis für Notices oder null */
    public static function ampelArgs(): ?array
    {
        if (! class_exists('WooCommerce') || ! current_user_can('manage_woocommerce')) {
            return null;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        $tab = sanitize_key((string) ($_GET['tab'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification -- nur Anzeige
        if (self::shopVisible()) {
            if ($screen && ! in_array($id, ['dashboard', 'toplevel_page_bootspruefung', 'woocommerce_page_wc-settings'], true)) {
                return null;
            }
            $checks = GoLiveCheck::run();
            if (GoLiveCheck::allGreen($checks)) {
                return null;
            }

            // Dringend: nur vertagbar; neue rote Punkte zeigen den Hinweis wieder (Version).
            return ['key' => 'golive_visible', 'type' => 'warning', 'dismiss' => 'snooze', 'snooze_days' => 3, 'version' => md5(implode('|', GoLiveCheck::redLabels($checks))),
                'title' => __('Der Shop ist für Kunden sichtbar, aber die Livegang-Ampel hat offene Punkte.', 'bootspruefung-learning'), 'html' => GoLiveCheck::html($checks)];
        }
        if ($id !== 'woocommerce_page_wc-settings' || $tab !== 'site-visibility') {
            return null;
        }
        $checks = GoLiveCheck::run();
        $green = GoLiveCheck::allGreen($checks);

        return ['key' => 'golive_before', 'type' => $green ? 'success' : 'warning', 'dismiss' => 'none',
            'title' => __('Livegang-Ampel vor „Shop für Kunden sichtbar machen“', 'bootspruefung-learning'),
            'text' => $green ? __('Alle Punkte sind grün.', 'bootspruefung-learning') : __('Es sind noch Punkte offen. Sichtbar machen ist trotzdem möglich – bitte nur bewusst.', 'bootspruefung-learning'),
            'html' => GoLiveCheck::html($checks)];
    }

    public function render(): void
    {
        if (($args = self::ampelArgs()) !== null) {
            Notices::add($args);
        }
        if (! current_user_can('manage_options') || ! self::shouldWarn()) {
            return;
        }
        // Fehler: nicht dauerhaft wegklickbar, nur um einen Tag vertagbar.
        Notices::add([
            'key' => 'golive_free_access', 'type' => 'error', 'dismiss' => 'snooze', 'snooze_days' => 1,
            'title' => __('Der Shop ist für Kunden sichtbar, aber alle angemeldeten Nutzer lernen noch kostenlos.', 'bootspruefung-learning'),
            'text' => __('„Zugang ohne Shop“ steht auf „Alle angemeldeten Nutzer“. Stell auf „Nur kostenlose Regeln und Freischaltungen“ um, damit nur gekaufte Kurse und die kostenlosen Teilumfänge freigeschaltet sind.', 'bootspruefung-learning'),
            'html' => '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0 0 .8em"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">'
                .wp_nonce_field(self::ACTION, '_wpnonce', true, false)
                .'<button type="submit" class="button button-primary">'.esc_html__('Jetzt umstellen', 'bootspruefung-learning').'</button></form>',
        ]);
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
