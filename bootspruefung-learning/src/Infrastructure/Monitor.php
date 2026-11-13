<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use WP_REST_Response;

/**
 * Betrieb (To-do T55):
 *
 * - Überwachung: GET /wp-json/bootspruefung/v1/monitor für einen Uptime-Dienst. Prüft Datenbank, fällige Migration und
 *   ob der stündliche Wartungslauf in den letzten 3 Stunden lief (System-Cron). 200 = ok, 503 = Problem; die Antwort
 *   nennt nur ok/Fehler je Prüfung, keine Versionen oder Daten. Optional mit Schlüssel (Konstante
 *   BOOTSPRUEFUNG_MONITOR_KEY, Parameter ?key=), damit Fremde den Endpunkt nicht abfragen.
 * - Zeitzone: Zeitpunkte in Mails (Zustimmung, Widerrufsfrist) und im Lernbereich nutzen die Zeitzone der Website.
 *   Steht sie nicht auf einer Ortszeit (z. B. UTC oder „UTC+1“ ohne Sommerzeit), weist der Admin darauf hin.
 */
final class Monitor
{
    public const OPTION_HOURLY = 'bp_cron_last_hourly';
    public const CRON_MAX_AGE = 3 * HOUR_IN_SECONDS;

    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route('bootspruefung/v1', '/monitor', [
                'methods' => 'GET',
                'callback' => [self::class, 'response'],
                'permission_callback' => [self::class, 'allowed'],
            ]);
        });
        add_action('admin_notices', [self::class, 'timezoneNotice']);
    }

    /** Ohne Schlüssel offen; mit BOOTSPRUEFUNG_MONITOR_KEY nur mit passendem ?key=. */
    public static function allowed(\WP_REST_Request $request): bool
    {
        $key = defined('BOOTSPRUEFUNG_MONITOR_KEY') ? (string) constant('BOOTSPRUEFUNG_MONITOR_KEY') : '';

        return $key === '' || hash_equals($key, (string) $request->get_param('key'));
    }

    /** @return array<string,bool> Prüfung => ok */
    public static function checks(?int $now = null): array
    {
        global $wpdb;
        $now ??= time();
        $db = (int) $wpdb->get_var('SELECT 1') === 1;
        $last = (int) get_option(self::OPTION_HOURLY, 0);

        return [
            'database' => $db,
            'migration' => $db && ! (new Migrator($wpdb))->needsMigration(),
            // Frisch installiert (noch kein Lauf): nicht als Fehler werten, solange der Lauf geplant ist.
            'cron' => $last > 0 ? $now - $last <= self::CRON_MAX_AGE : (bool) wp_next_scheduled(Cron::HOURLY),
            'timezone' => self::timezoneOk(),
        ];
    }

    public static function response(): WP_REST_Response
    {
        $checks = self::checks();
        // Zeitzone ist ein Hinweis, kein Ausfall.
        $ok = $checks['database'] && $checks['migration'] && $checks['cron'];
        $response = new WP_REST_Response(['status' => $ok ? 'ok' : 'error', 'checks' => $checks], $ok ? 200 : 503);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /** Ortszeit mit Sommerzeit (z. B. Europe/Berlin), nicht UTC oder fester Versatz. */
    public static function timezoneOk(): bool
    {
        $tz = (string) get_option('timezone_string', '');

        return $tz !== '' && str_contains($tz, '/') && ! str_starts_with($tz, 'Etc/');
    }

    public static function timezoneNotice(): void
    {
        if (! current_user_can('manage_options') || self::timezoneOk()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && ! in_array($screen->id, ['dashboard', 'options-general', 'toplevel_page_bootspruefung'], true)) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Zeitzone:', 'bootspruefung-learning'),
            esc_html__('Die Website steht nicht auf einer Ortszeit (z. B. „Berlin“). Uhrzeiten in Bestellmails (Zustimmung zum vorzeitigen Beginn), Widerrufs- und Garantiefristen und im Lernbereich wären sonst um eine Stunde oder mehr verschoben.', 'bootspruefung-learning'),
            esc_url(admin_url('options-general.php')),
            esc_html__('Unter Einstellungen › Allgemein „Berlin“ wählen', 'bootspruefung-learning')
        );
    }
}
