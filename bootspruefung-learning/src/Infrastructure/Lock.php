<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

defined('ABSPATH') || exit;

/**
 * Atomare Sperre über eine Zeile in wp_options (Migration, Import, FAQ-Importe, Abgleich).
 *
 * add_option() ist dafür ungeeignet: Es prüft vorher über den (ggf. veralteten) notoptions-Cache und
 * schreibt mit „ON DUPLICATE KEY UPDATE“ – zwei gleichzeitige Läufe bekämen beide die Sperre. Hier legt
 * ein „INSERT IGNORE“ die Zeile an; nur der Lauf, dessen INSERT eine Zeile erzeugt, hält die Sperre.
 *
 * Wert: „<Besitzer>|<Unix-Zeit>“. Eine liegen gebliebene Sperre (abgebrochener Lauf) verfällt nach $ttl
 * Sekunden; übernommen wird sie nur, wenn sie seit dem Lesen unverändert ist (DELETE mit altem Wert), so
 * dass auch bei der Übernahme nur ein Lauf gewinnt. Freigeben darf nur der Besitzer.
 */
final class Lock
{
    /** @var array<string,string> Name => Besitzer der in diesem Prozess gehaltenen Sperren */
    private static array $held = [];

    /**
     * @param string      $name  Optionsname der Sperre
     * @param int         $ttl   Sekunden, nach denen eine fremde Sperre als hängengeblieben gilt
     * @param string|null $owner Besitzerkennung (z. B. Import-ID), sonst zufällig für diesen Lauf
     */
    public static function acquire(string $name, int $ttl, ?string $owner = null): bool
    {
        global $wpdb;
        $owner = $owner !== null && $owner !== '' ? str_replace('|', '-', $owner) : wp_generate_password(12, false, false);
        if (self::insert($name, $owner)) {
            return true;
        }
        $current = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($current === null) {
            // Zwischen INSERT und SELECT freigegeben: ein neuer Versuch.
            return self::insert($name, $owner);
        }
        $since = self::since((string) $current);
        if ($since > 0 && time() - $since < $ttl) {
            return false;
        }
        // Abgelaufen: nur entfernen, wenn noch dieselbe Sperre gesetzt ist (ein paralleler Übernehmer gewinnt sonst).
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $current));

        return self::insert($name, $owner);
    }

    /** Gibt die Sperre frei, wenn sie (noch) $owner bzw. diesem Lauf gehört. */
    public static function release(string $name, ?string $owner = null): void
    {
        global $wpdb;
        $owner = $owner !== null ? str_replace('|', '-', $owner) : (self::$held[$name] ?? null);
        if ($owner === null || $owner === '') {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            $name,
            $wpdb->esc_like($owner.'|').'%'
        ));
        unset(self::$held[$name]);
        self::forgetCache($name);
    }

    /** Besitzer der Sperre oder null (Anzeige, Tests). */
    public static function holder(string $name): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($value === null) {
            return null;
        }
        $pos = strrpos((string) $value, '|');

        return $pos === false ? '' : substr((string) $value, 0, $pos);
    }

    private static function insert(string $name, string $owner): bool
    {
        global $wpdb;
        $ok = 1 === (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $name,
            $owner.'|'.time()
        ));
        if ($ok) {
            self::$held[$name] = $owner;
            self::forgetCache($name);
        }

        return $ok;
    }

    /** Zeitpunkt der Sperre; versteht auch die früheren Formate „<Zeit>“ und „<Import-ID>:<Zeit>“. */
    private static function since(string $value): int
    {
        foreach (['|', ':'] as $sep) {
            $pos = strrpos($value, $sep);
            if ($pos !== false) {
                return (int) substr($value, $pos + 1);
            }
        }

        return (int) $value;
    }

    /** Die Sperre läuft an get_option() vorbei – zwischengespeicherte Werte verwerfen. */
    private static function forgetCache(string $name): void
    {
        wp_cache_delete($name, 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$name])) {
            unset($notoptions[$name]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }
}
