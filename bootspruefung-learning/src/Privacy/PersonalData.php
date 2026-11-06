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
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            return ['data' => [], 'done' => true];
        }
        global $wpdb;
        $items = [];
        foreach (array_merge(Schema::USER_DATA_TABLES, ['entitlement']) as $table) {
            $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.esc_sql($wpdb->prefix.'bp_'.$table).'` WHERE user_id = %d', $user->ID));
            $items[] = ['name' => $table, 'value' => (string) $count];
        }
        $items[] = ['name' => 'learning_group_member', 'value' => (string) (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.esc_sql($wpdb->prefix.'bp_learning_group_member').'` WHERE user_id = %d OR email = %s', $user->ID, strtolower($email)))];

        return [
            'data' => [[
                'group_id' => self::GROUP,
                'group_label' => __('Bootsprüfung – Lernstand', 'bootspruefung-learning'),
                'item_id' => 'bp-summary-'.$user->ID,
                'data' => $items,
            ]],
            'done' => true,
        ];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
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
        $retained = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.esc_sql($wpdb->prefix.'bp_entitlement').'` WHERE user_id = %d', $user->ID)) > 0;

        return [
            'items_removed' => $removed,
            'items_retained' => $retained,
            'messages' => $retained ? [__('Kurszugänge bleiben wegen Aufbewahrungsfristen der zugehörigen Bestellungen erhalten.', 'bootspruefung-learning')] : [],
            'done' => true,
        ];
    }
}
