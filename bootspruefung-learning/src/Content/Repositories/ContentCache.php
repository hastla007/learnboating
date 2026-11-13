<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Repositories;

/**
 * Zwischenspeicher für Inhaltsabfragen (Kurse, Module, Fragenlisten) in der Object-Cache-Gruppe
 * „bootspruefung“. Inhalte ändern sich nur durch Import und Rechte-Overrides; beide rufen invalidate().
 *
 * Ohne persistenten Object-Cache wirkt das je Anfrage, mit (Redis/Memcached) über Anfragen hinweg. Die
 * Schlüssel enthalten eine Generation: Auch Object-Cache-Drop-ins ohne wp_cache_flush_group() werden
 * so sicher ungültig (dort würde flush_group nur „_doing_it_wrong“ melden).
 */
final class ContentCache
{
    public const GROUP = 'bootspruefung';

    private const TTL = DAY_IN_SECONDS;

    /**
     * @template T
     * @param callable():T $compute
     * @return T
     */
    public static function remember(string $key, callable $compute): mixed
    {
        $full = $key.':'.self::generation();
        $found = false;
        $value = wp_cache_get($full, self::GROUP, false, $found);
        if ($found) {
            return $value;
        }
        $value = $compute();
        wp_cache_set($full, $value, self::GROUP, self::TTL);

        return $value;
    }

    /** Alle zwischengespeicherten Inhalte verwerfen (nach Import, Rechte-Änderung). */
    public static function invalidate(): void
    {
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group(self::GROUP);
        }
        wp_cache_set('generation', self::newGeneration(), self::GROUP);
        delete_transient('bp_learning_video_hosts'); // Video-Hosts für die Banner-Kategorie „Externe Medien“
    }

    private static function generation(): string
    {
        $generation = wp_cache_get('generation', self::GROUP);
        if (! is_string($generation) || $generation === '') {
            $generation = self::newGeneration();
            wp_cache_set('generation', $generation, self::GROUP);
        }

        return $generation;
    }

    private static function newGeneration(): string
    {
        return str_replace([' ', '.'], '', microtime()).wp_rand(100, 999);
    }
}
