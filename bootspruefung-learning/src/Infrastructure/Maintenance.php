<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Mobile\Problem;
use Bootspruefung\Learning\Plugin;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Wartung nach einem Plugin-Update (To-do T47).
 *
 * Bis die fällige Datenbank-Migration gelaufen ist, passen neue Spalten und Tabellen nicht zum Code. Schreibende
 * Anfragen an die Lern-API (Web bootspruefung/v1 und App boatingschool/v1) bekommen deshalb 503 mit Retry-After,
 * statt Antworten oder Lernstand halb zu speichern; die Prüfung puffert und sendet erneut, App und Web zeigen
 * die Meldung. Gleichzeitig stößt die Anfrage den Migrationslauf sofort an (nach einem Fehlschlag erst nach dem
 * Backoff). Lesende Anfragen laufen weiter.
 */
final class Maintenance
{
    public const RETRY_AFTER = 30;

    private static ?bool $pending = null;

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'guard'], 5, 3);
    }

    /** Ist eine Migration fällig? (einmal je Anfrage geprüft) */
    public static function pending(): bool
    {
        if (self::$pending === null) {
            global $wpdb;
            self::$pending = (new Migrator($wpdb))->needsMigration();
        }

        return self::$pending;
    }

    /** Nur für Tests: gespeicherten Stand verwerfen. */
    public static function reset(): void
    {
        self::$pending = null;
    }

    /**
     * rest_pre_dispatch: schreibende Anfragen der Lern-API während einer fälligen Migration abweisen.
     *
     * @param mixed $result
     * @param mixed $server
     * @return mixed
     */
    public static function guard($result, $server, $request)
    {
        if ($result !== null || ! $request instanceof WP_REST_Request || in_array($request->get_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $result;
        }
        $route = (string) $request->get_route();
        $mobile = str_starts_with($route, '/boatingschool/v1');
        if (! $mobile && ! str_starts_with($route, '/bootspruefung/v1')) {
            return $result;
        }
        if (! self::pending()) {
            return $result;
        }
        // Nach einem Fehlschlag erst nach dem Backoff erneut (Plugin::MIGRATION_BACKOFF); dann kein spawn_cron().
        if (Plugin::scheduleMigration() && function_exists('spawn_cron')) {
            spawn_cron();
        }
        $message = __('Die Lernplattform wird gerade aktualisiert. Bitte versuche es in einer halben Minute erneut – deine Eingabe ist nicht verloren.', 'bootspruefung-learning');
        $response = $mobile
            ? Problem::response('MAINTENANCE', $message, 503, ['retry_after' => self::RETRY_AFTER])
            : new WP_REST_Response(['code' => 'bp_maintenance', 'message' => $message, 'data' => ['status' => 503, 'retry_after' => self::RETRY_AFTER]], 503);
        $response->header('Retry-After', (string) self::RETRY_AFTER);

        return $response;
    }
}
