<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Repositories;

use Bootspruefung\Learning\Audit\AuditLog;
use InvalidArgumentException;

/**
 * Lokale Overrides importierter Felder (Konzept Kap. 3.3: Quelle bleibt
 * autoritativ, lokale Änderungen werden getrennt gespeichert und überleben
 * Reimporte). Jede Änderung braucht eine Begründung und landet im Audit-Log.
 */
final class Overrides
{
    public const FIELDS = [
        'question' => ['rights_status'],
        'media' => ['rights_status'],
        'navigation_task' => ['rights_status'],
        'praxis_task' => ['rights_status'],
        'exam_rule' => ['verified', 'valid_from', 'valid_until'],
    ];

    public const RIGHTS = ['unknown', 'review', 'licensed', 'public', 'restricted'];

    /** Rechte-Status, die eine öffentliche Auslieferung sperren (Kap. 4.10). */
    public const BLOCKING_RIGHTS = ['unknown', 'restricted'];

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'bp_override';
    }

    public static function get(string $type, int $id, string $field): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare('SELECT value FROM '.self::table().' WHERE object_type = %s AND object_id = %d AND field = %s', $type, $id, $field));

        return $value === null ? null : (string) $value;
    }

    /** @return array<int,array<string,?string>> object_id => field => value */
    public static function forType(string $type): array
    {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT object_id, field, value FROM '.self::table().' WHERE object_type = %s', $type)) as $row) {
            $out[(int) $row->object_id][$row->field] = $row->value;
        }

        return $out;
    }

    /**
     * Setzt (oder entfernt mit $value === null) einen Override für mehrere Objekte.
     * @param list<int> $ids
     */
    public static function set(string $type, array $ids, string $field, ?string $value, string $reason): int
    {
        if (! in_array($field, self::FIELDS[$type] ?? [], true)) {
            throw new InvalidArgumentException("Feld {$field} ist für {$type} nicht überschreibbar.");
        }
        if ($field === 'rights_status' && $value !== null && ! in_array($value, self::RIGHTS, true)) {
            throw new InvalidArgumentException("Ungültiger Rechte-Status {$value}.");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Eine Begründung ist erforderlich.');
        }
        global $wpdb;
        $count = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($value === null) {
                $count += (int) $wpdb->delete(self::table(), ['object_type' => $type, 'object_id' => $id, 'field' => $field]);
                continue;
            }
            $wpdb->query($wpdb->prepare(
                'INSERT INTO '.self::table().' (object_type, object_id, field, value, reason, user_id, created_at) VALUES (%s, %d, %s, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), reason = VALUES(reason), user_id = VALUES(user_id), created_at = VALUES(created_at)',
                $type, $id, $field, $value, $reason, get_current_user_id(), current_time('mysql', true)
            ));
            $count++;
        }
        AuditLog::record('override_'.$field, $type, implode(',', array_slice($ids, 0, 50)).(count($ids) > 50 ? ',…' : ''), $reason, ['value' => $value, 'count' => count($ids)]);
        wp_cache_flush_group('bootspruefung');

        return $count;
    }

    /** SQL-Ausdruck für den effektiven Rechte-Status (Join-Alias $alias muss die Tabelle des Objekts sein). */
    public static function rightsJoin(string $type, string $objectAlias, string $overrideAlias): string
    {
        global $wpdb;

        return $wpdb->prepare(
            ' LEFT JOIN '.self::table()." {$overrideAlias} ON {$overrideAlias}.object_type = %s AND {$overrideAlias}.object_id = {$objectAlias}.id AND {$overrideAlias}.field = 'rights_status' ",
            $type
        );
    }

    public static function blockingList(): string
    {
        return "'".implode("','", self::BLOCKING_RIGHTS)."'";
    }
}
