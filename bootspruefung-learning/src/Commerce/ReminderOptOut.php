<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Frontend\Router;

/**
 * Widerspruch gegen die Ablauf-Erinnerung (§ 7 Abs. 3 UWG, Art. 21 DSGVO):
 * - signierter Link in jeder Erinnerung (ohne Anmeldung) → Bestätigungsseite im Lernbereich
 *   (`/<Lernbereich>/erinnerungen/?bp_u=…&bp_sig=…`, Knopf statt Abmeldung per Aufruf, damit Link-Scanner
 *   der Postfächer nichts abbestellen); dort auch wieder einschaltbar;
 * - Schalter unter „Mein Konto“ › „Mein Lernen“.
 * Gespeichert in der Nutzer-Meta bp_expiry_reminder_optout (Zeitpunkt und Weg); ExpiryReminder::run() respektiert sie.
 */
final class ReminderOptOut
{
    public const META = 'bp_expiry_reminder_optout';
    public const PATH = 'erinnerungen';
    public const FORM_FIELD = 'bp_expiry_reminder_pref';
    /** Parameter des Abmeldelinks (nicht `s`/`u`: `s` ist die WordPress-Suche). */
    public const ARG_USER = 'bp_u';
    public const ARG_SIG = 'bp_sig';
    public const ARG_DONE = 'bp_done';
    private const NONCE_ACTION = 'bp_expiry_reminder_pref';

    public function register(): void
    {
        add_action('template_redirect', [$this, 'handle'], 1);
        // Hinweis auf die Erinnerung und das Widerspruchsrecht schon bei der Erhebung der E-Mail-Adresse (T44):
        // Kasse (klassisch und Block, nur mit Lernprodukt) und Registrierung (WooCommerce „Mein Konto“, wp-login).
        add_action('woocommerce_review_order_before_submit', static function (): void {
            if (\Bootspruefung\Learning\Commerce\WooCommerce\Integration::cartHasLearning()) {
                echo ExpiryReminder::collectionNoticeHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- maskiert
            }
        }, 20);
        add_filter('render_block_woocommerce/checkout', static fn ($content): string => is_admin() || ! \Bootspruefung\Learning\Commerce\WooCommerce\Integration::cartHasLearning() ? (string) $content : (string) $content.ExpiryReminder::collectionNoticeHtml(), 31, 1);
        add_action('woocommerce_register_form', static function (): void {
            echo ExpiryReminder::collectionNoticeHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- maskiert
        }, 30);
        add_action('register_form', static function (): void {
            echo ExpiryReminder::collectionNoticeHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- maskiert
        }, 30);
    }

    public static function optedOut(int $userId): bool
    {
        return $userId > 0 && get_user_meta($userId, self::META, true) !== '';
    }

    /** Erinnerungen ab- ($off = true) oder wieder einschalten; $via = link|account. */
    public static function set(int $userId, bool $off, string $via): void
    {
        if ($off) {
            update_user_meta($userId, self::META, ['at' => current_time('mysql', true), 'via' => $via]);
        } else {
            delete_user_meta($userId, self::META);
        }
        \Bootspruefung\Learning\Audit\AuditLog::record($off ? 'expiry_reminder_optout' : 'expiry_reminder_optin', 'user', (string) $userId, null, ['via' => $via]);
    }

    /** Signatur des Abmeldelinks (ohne Ablauf; an Konto und Registrierungszeitpunkt gebunden). */
    public static function signature(int $userId): string
    {
        $user = get_userdata($userId);
        $registered = $user ? (string) $user->user_registered : '';

        return substr(hash_hmac('sha256', 'bp_expiry_optout|'.$userId.'|'.$registered, wp_salt('auth')), 0, 32);
    }

    public static function verify(int $userId, string $signature): bool
    {
        return $userId > 0 && get_userdata($userId) !== false && hash_equals(self::signature($userId), $signature);
    }

    /** Abmeldelink für die Mail (öffnet die Bestätigungsseite). */
    public static function url(int $userId): string
    {
        return add_query_arg([self::ARG_USER => $userId, self::ARG_SIG => self::signature($userId)], Router::url(self::PATH));
    }

    /** Aufruf der Bestätigungsseite im Lernbereich? */
    public static function isRequest(): bool
    {
        return (bool) get_query_var('bp_app') && trim((string) get_query_var('bp_path'), '/') === self::PATH;
    }

    /** POST der Bestätigungsseite bzw. des Schalters in „Mein Konto“ (danach Weiterleitung, PRG). */
    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- Bestätigungsseite: signierter Link statt Nonce (ohne Anmeldung)
        if (self::isRequest() && isset($_POST['bp_reminder'])) {
            $uid = (int) ($_POST[self::ARG_USER] ?? 0);
            $sig = sanitize_text_field(wp_unslash((string) ($_POST[self::ARG_SIG] ?? '')));
            $off = (string) $_POST['bp_reminder'] === 'off';
            if (self::verify($uid, $sig)) {
                self::set($uid, $off, 'link');
                wp_safe_redirect(add_query_arg(self::ARG_DONE, $off ? 'off' : 'on', self::url($uid)), 303);
                exit;
            }

            return; // ungültig: Seite zeigt den Hinweis
        }
        // phpcs:enable
        if (isset($_POST[self::FORM_FIELD.'_save']) && is_user_logged_in()) {
            $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_bp_reminder_nonce'] ?? '')));
            if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
                return;
            }
            $uid = get_current_user_id();
            $off = empty($_POST[self::FORM_FIELD]);
            if ($off !== self::optedOut($uid)) {
                self::set($uid, $off, 'account');
            }
            if (function_exists('wc_add_notice')) {
                wc_add_notice($off ? __('Du bekommst keine Erinnerungen mehr, bevor ein Lernzugang abläuft.', 'bootspruefung-learning') : __('Wir erinnern dich per E-Mail, bevor ein Lernzugang abläuft.', 'bootspruefung-learning'), 'success');
            }
            $back = wp_get_referer();
            wp_safe_redirect($back !== false && $back !== '' ? $back : (Integration::accountUrl() ?? home_url('/')), 303);
            exit;
        }
    }

    /** Inhalt der Bestätigungsseite (im Lernbereich, auch ohne Anmeldung). */
    public static function page(): string
    {
        $d = 'bootspruefung-learning';
        $uid = (int) ($_REQUEST[self::ARG_USER] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification -- signierter Link
        $sig = sanitize_text_field(wp_unslash((string) ($_REQUEST[self::ARG_SIG] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification
        $done = sanitize_key((string) ($_GET[self::ARG_DONE] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        $account = Integration::accountUrl();
        $accountLink = $account !== null ? '<p><a href="'.esc_url($account).'">'.esc_html__('Zu „Mein Lernen“ in deinem Konto', $d).'</a></p>' : '';
        $html = '<div class="bp-card bp-reminder-optout"><h1>'.esc_html__('Erinnerungen vor Ablauf', $d).'</h1>';
        if (! self::verify($uid, $sig)) {
            return $html.'<p>'.esc_html__('Dieser Link ist ungültig oder unvollständig. Du kannst die Erinnerungen auch in deinem Konto unter „Mein Lernen“ abbestellen.', $d).'</p>'.$accountLink.'</div>';
        }
        $off = self::optedOut($uid);
        if ($done === 'off' && $off) {
            $html .= '<p role="status">'.esc_html__('Erledigt: Du bekommst keine E-Mail-Erinnerungen mehr, bevor ein Lernzugang abläuft. Mails zu deinen Bestellungen bekommst du weiter.', $d).'</p>';
        } elseif ($done === 'on' && ! $off) {
            $html .= '<p role="status">'.esc_html__('Erledigt: Wir erinnern dich wieder per E-Mail, bevor ein Lernzugang abläuft.', $d).'</p>';
        } elseif ($off) {
            $html .= '<p>'.esc_html__('Du bekommst derzeit keine Erinnerungen, bevor ein Lernzugang abläuft.', $d).'</p>';
        } else {
            $html .= '<p>'.esc_html__('Wir schicken dir eine E-Mail, kurz bevor ein Lernzugang abläuft. Möchtest du diese Erinnerungen nicht mehr bekommen?', $d).'</p>';
        }
        $html .= '<form method="post" action="'.esc_url(self::url($uid)).'"><input type="hidden" name="'.self::ARG_USER.'" value="'.esc_attr((string) $uid).'"><input type="hidden" name="'.self::ARG_SIG.'" value="'.esc_attr($sig).'">'
            .'<input type="hidden" name="bp_reminder" value="'.($off ? 'on' : 'off').'">'
            .'<p><button type="submit" class="bp-button'.($off ? '' : ' bp-button-primary').'">'.esc_html($off ? __('Erinnerungen wieder einschalten', $d) : __('Erinnerungen abbestellen', $d)).'</button></p></form>';

        return $html.'<p class="bp-muted">'.esc_html__('Du kannst diese Einstellung jederzeit in deinem Konto unter „Mein Lernen“ ändern.', $d).'</p>'.$accountLink.'</div>';
    }

    /** Schalter unter „Mein Konto“ › „Mein Lernen“ (nur, wenn die Erinnerung eingeschaltet ist). */
    public static function renderAccountSection(int $userId): void
    {
        if ($userId <= 0 || ! ExpiryReminder::enabled()) {
            return;
        }
        $d = 'bootspruefung-learning';
        echo '<section class="bp-reminder-pref"><h2>'.esc_html__('E-Mail-Erinnerungen', $d).'</h2>'
            .'<form method="post" action=""><p><label><input type="checkbox" name="'.esc_attr(self::FORM_FIELD).'" value="1"'.(self::optedOut($userId) ? '' : ' checked').'> '
            .esc_html__('Erinnere mich per E-Mail, bevor ein Lernzugang abläuft.', $d).'</label></p>'
            .wp_nonce_field(self::NONCE_ACTION, '_bp_reminder_nonce', true, false)
            .'<p><button type="submit" class="button" name="'.esc_attr(self::FORM_FIELD).'_save" value="1">'.esc_html__('Speichern', $d).'</button></p></form></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
