<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Privacy;

use Bootspruefung\Learning\Infrastructure\Database\Schema;

/**
 * Datenexport/-löschung über die WordPress-Datenschutzwerkzeuge (Kap. 5.2, 10).
 * Die Löschung entfernt Lernversuche und Fortschritt vollständig (Ausnahme von
 * der Unveränderlichkeit, Art. 17 DSGVO). Zugänge (Entitlements) bleiben wegen
 * Aufbewahrungsfristen der Bestellung erhalten und werden nur gemeldet.
 */
final class PersonalData
{
    private const GROUP = 'bootspruefung-learning';

    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $exporters): array {
            $exporters[self::GROUP] = [
                'exporter_friendly_name' => __('Bootsprüfung – Lernstand', 'bootspruefung-learning'),
                'callback' => [self::class, 'export'],
            ];

            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $erasers): array {
            $erasers[self::GROUP] = [
                'eraser_friendly_name' => __('Bootsprüfung – Lernstand', 'bootspruefung-learning'),
                'callback' => [self::class, 'erase'],
            ];

            return $erasers;
        });
        // Konto gelöscht (Admin/Kundenwunsch): Lerndaten mitlöschen, nicht zwecklos aufbewahren.
        add_action('deleted_user', static function ($userId, $reassign = null, $user = null): void {
            self::eraseUser((int) $userId, $user instanceof \WP_User ? (string) $user->user_email : '');
        }, 10, 3);
        // Protokoll: Einträge älter als AUDIT_RETENTION_YEARS Jahre löschen (stündlicher Plugin-Cron).
        add_action('bp_learning_hourly', [self::class, 'pruneAuditLog']);
    }

    /** Aufbewahrung des Protokolls (Nachweis von Zugangs-/Kaufvorgängen, regelmäßige Verjährung 3 Jahre ab Jahresende). */
    public const AUDIT_RETENTION_YEARS = 4;

    public static function pruneAuditLog(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bp_audit_log WHERE created_at < %s LIMIT 5000",
            gmdate('Y-m-d H:i:s', strtotime('-'.self::AUDIT_RETENTION_YEARS.' years'))
        ));
    }

    /**
     * Quellen des Exports (Art. 15/20 DSGVO): Tabelle => [Bezeichnung, WHERE-Bedingung].
     * Je Seite eine Quelle, je Zeile ein Eintrag mit allen Feldern (bei großen Tabellen seitenweise).
     */
    private static function sources(): array
    {
        return [
            'attempt' => [__('Antworten (Lernmodus)', 'bootspruefung-learning'), 'user_id = %d'],
            'progress' => [__('Lernstand je Frage', 'bootspruefung-learning'), 'user_id = %d'],
            'favorite' => [__('Favoriten', 'bootspruefung-learning'), 'user_id = %d'],
            'favorite_collection' => [__('Sammlungen', 'bootspruefung-learning'), 'user_id = %d'],
            'exam_session' => [__('Prüfungssimulationen', 'bootspruefung-learning'), 'user_id = %d'],
            'navigation_progress' => [__('Navigationsaufgaben (eigene Antworten)', 'bootspruefung-learning'), 'user_id = %d'],
            'praxis_progress' => [__('Praxiskarten', 'bootspruefung-learning'), 'user_id = %d'],
            'video_progress' => [__('Videokurs', 'bootspruefung-learning'), 'user_id = %d'],
            'radio_progress' => [__('Funktraining', 'bootspruefung-learning'), 'user_id = %d'],
            'entitlement' => [__('Kurszugänge', 'bootspruefung-learning'), 'user_id = %d'],
            'learning_group' => [__('Eigene Gruppen', 'bootspruefung-learning'), 'owner_user_id = %d'],
            'learning_group_member' => [__('Gruppenmitgliedschaften', 'bootspruefung-learning'), 'user_id = %d'],
            'audit_log' => [__('Protokoll (eigene Vorgänge)', 'bootspruefung-learning'), 'actor_user_id = %d OR (object_type = \'user\' AND object_id = %s)'],
        ];
    }

    private const EXPORT_LIMIT = 2000;

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            return ['data' => [], 'done' => true];
        }
        global $wpdb;
        $sources = self::sources();
        $tables = array_keys($sources);
        // Seite n → Tabelle und Teilstück (Tabellen mit mehr als EXPORT_LIMIT Zeilen über mehrere Seiten).
        $index = 0;
        $offset = 0;
        $remaining = max(1, $page);
        foreach ($tables as $i => $table) {
            $count = (int) $wpdb->get_var(self::sql('SELECT COUNT(*)', $table, $sources[$table][1], $user->ID));
            $chunks = max(1, (int) ceil($count / self::EXPORT_LIMIT));
            if ($remaining <= $chunks) {
                $index = $i;
                $offset = ($remaining - 1) * self::EXPORT_LIMIT;
                break;
            }
            $remaining -= $chunks;
            $index = $i + 1;
        }
        if ($index >= count($tables)) {
            return ['data' => [], 'done' => true];
        }
        $table = $tables[$index];
        $rows = $wpdb->get_results(self::sql('SELECT *', $table, $sources[$table][1], $user->ID).' ORDER BY id LIMIT '.self::EXPORT_LIMIT.' OFFSET '.(int) $offset, ARRAY_A) ?: [];
        $data = [];
        foreach ($rows as $row) {
            unset($row['invite_token'], $row['join_token'], $row['idempotency_key']);
            $data[] = [
                'group_id' => self::GROUP.'-'.$table,
                'group_label' => __('Bootsprüfung', 'bootspruefung-learning').' – '.$sources[$table][0],
                'item_id' => 'bp-'.$table.'-'.($row['id'] ?? md5(serialize($row))),
                'data' => array_map(static fn ($k, $v) => ['name' => $k, 'value' => is_scalar($v) || $v === null ? (string) $v : wp_json_encode($v)], array_keys($row), $row),
            ];
        }
        $last = $index === count($tables) - 1 && count($rows) < self::EXPORT_LIMIT;

        return ['data' => $data, 'done' => $last];
    }

    private static function sql(string $select, string $table, string $where, int $userId): string
    {
        global $wpdb;
        $args = substr_count($where, '%d') + substr_count($where, '%s') === 2 ? [$userId, (string) $userId] : [$userId];

        return $wpdb->prepare($select.' FROM `'.esc_sql($wpdb->prefix.'bp_'.$table).'` WHERE '.$where, ...$args);
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        return self::eraseUser((int) $user->ID, $email);
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function eraseUser(int $userId, string $email): array
    {
        $user = (object) ['ID' => $userId];
        global $wpdb;
        $removed = false;
        $wpdb->query('START TRANSACTION');
        try {
            foreach (Schema::USER_DATA_TABLES as $table) {
                // Kindtabellen (attempt_answer, exam_session_question) folgen per ON DELETE CASCADE.
                $deleted = $wpdb->query($wpdb->prepare('DELETE FROM `'.esc_sql($wpdb->prefix.'bp_'.$table).'` WHERE user_id = %d', $user->ID));
                if ($deleted === false) {
                    throw new \RuntimeException($wpdb->last_error);
                }
                $removed = $removed || $deleted > 0;
            }
            // Gruppenplätze: E-Mail-Adresse entfernen, Platzhistorie bleibt (Kontingent der Bestellung).
            $wpdb->query($wpdb->prepare('UPDATE `'.esc_sql($wpdb->prefix.'bp_learning_group_member').'` SET email = NULL, invite_token = NULL WHERE user_id = %d OR email = %s', $user->ID, strtolower($email)));
            // Eigene Gruppen: frei gewählten Namen neutralisieren (kann Personenbezug enthalten).
            $wpdb->query($wpdb->prepare('UPDATE `'.esc_sql($wpdb->prefix.'bp_learning_group').'` SET name = CONCAT(%s, id) WHERE owner_user_id = %d', __('Gruppe #', 'bootspruefung-learning'), $user->ID));
            $wpdb->insert($wpdb->prefix.'bp_audit_log', [
                'actor_user_id' => get_current_user_id() ?: null,
                'action' => 'personal_data_erased',
                'object_type' => 'user',
                'object_id' => hash('sha256', (string) $user->ID.wp_salt()),
            ]);
            $wpdb->query('COMMIT');
        } catch (\RuntimeException $e) {
            $wpdb->query('ROLLBACK');

            return ['items_removed' => false, 'items_retained' => true, 'messages' => [$e->getMessage()], 'done' => true];
        }
        $messages = [];
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.esc_sql($wpdb->prefix.'bp_entitlement').'` WHERE user_id = %d', $user->ID)) > 0) {
            $messages[] = __('Kurszugänge bleiben wegen der Aufbewahrungsfristen der zugehörigen Bestellungen erhalten.', 'bootspruefung-learning');
        }
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.esc_sql($wpdb->prefix.'bp_learning_group').'` WHERE owner_user_id = %d', $user->ID)) > 0) {
            $messages[] = __('Eigene Gruppenlizenzen bleiben als Teil der Bestellung erhalten (Name neutralisiert); die Plätze der Mitglieder bleiben bestehen.', 'bootspruefung-learning');
        }
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `".esc_sql($wpdb->prefix.'bp_audit_log')."` WHERE actor_user_id = %d OR (object_type = 'user' AND object_id = %s)", $user->ID, (string) $user->ID)) > 0) {
            $messages[] = sprintf(__('Protokolleinträge zu Kauf- und Zugangsvorgängen bleiben zum Nachweis erhalten und werden nach %d Jahren gelöscht.', 'bootspruefung-learning'), self::AUDIT_RETENTION_YEARS);
        }

        return [
            'items_removed' => $removed,
            'items_retained' => $messages !== [],
            'messages' => $messages,
            'done' => true,
        ];
    }
}
