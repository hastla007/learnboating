<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

defined('ABSPATH') || exit;

/**
 * Zwischenspeicher für die Dauer einer lesenden REST-Anfrage (GET/HEAD in bootspruefung/v1 und
 * boatingschool/v1). Kursübersichten fragen Zugangsregeln, Zugänge und Produktzuordnungen sonst je
 * Kurs und Feature erneut ab (/me/courses: über 300 Abfragen).
 *
 * Außerhalb eines solchen Bereichs ist der Speicher aus: Schreibende Anfragen, Cron, CLI und Tests,
 * die zwischen zwei Prüfungen Daten ändern, sehen immer den aktuellen Stand. Nach der Anfrage wird
 * alles verworfen.
 */
final class RequestMemo
{
    private const NAMESPACES = ['/bootspruefung/v1/', '/boatingschool/v1/'];

    private static int $depth = 0;

    /** @var array<string,mixed> */
    private static array $data = [];

    /** @var array<int,true> spl_object_id der Anfragen, die einen Bereich geöffnet haben */
    private static array $requests = [];

    public static function register(): void
    {
        add_filter('rest_request_before_callbacks', [self::class, 'beforeCallbacks'], 10, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'afterCallbacks'], 10, 3);
    }

    /** @param mixed $response */
    public static function beforeCallbacks($response, $handler, $request)
    {
        if ($request instanceof \WP_REST_Request && in_array($request->get_method(), ['GET', 'HEAD'], true) && self::ownRoute((string) $request->get_route())) {
            self::$requests[spl_object_id($request)] = true;
            self::$depth++;
        }

        return $response;
    }

    /** @param mixed $response */
    public static function afterCallbacks($response, $handler, $request)
    {
        if ($request instanceof \WP_REST_Request && isset(self::$requests[spl_object_id($request)])) {
            unset(self::$requests[spl_object_id($request)]);
            self::leave();
        }

        return $response;
    }

    /**
     * Führt $fn mit eingeschaltetem Zwischenspeicher aus (z. B. eine Seite, die viele Kurse rendert).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function scope(callable $fn): mixed
    {
        self::$depth++;
        try {
            return $fn();
        } finally {
            self::leave();
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Wert aus dem Speicher oder berechnen; ohne aktiven Bereich immer berechnen.
     *
     * @template T
     * @param callable():T $compute
     * @return T
     */
    public static function get(string $key, callable $compute): mixed
    {
        if (self::$depth === 0) {
            return $compute();
        }
        if (! array_key_exists($key, self::$data)) {
            self::$data[$key] = $compute();
        }

        return self::$data[$key];
    }

    /** Alles verwerfen (z. B. nachdem eine Anfrage doch Zugänge geändert hat). */
    public static function forget(): void
    {
        self::$data = [];
    }

    private static function leave(): void
    {
        self::$depth = max(0, self::$depth - 1);
        if (self::$depth === 0) {
            self::$data = [];
        }
    }

    private static function ownRoute(string $route): bool
    {
        foreach (self::NAMESPACES as $ns) {
            if (str_starts_with($route.'/', $ns)) {
                return true;
            }
        }

        return false;
    }
}
