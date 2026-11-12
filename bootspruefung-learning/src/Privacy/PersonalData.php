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
        // Konto gelöscht (Admin/Kundenwunsch): Gruppenplätze freigeben (QA-05) und Lerndaten mitlöschen,
        // nicht zwecklos aufbewahren.
        add_action('deleted_user', static function ($userId, $reassign = null, $user = null): void {
            global $wpdb;
            (new \Bootspruefung\Learning\Commerce\GroupService($wpdb))->releaseUser((int) $userId);
            self::eraseUser((int) $userId, $user instanceof \WP_User ? (string) $user->user_email : '');
        }, 10, 3);
        // Bestätigte Löschanträge: Konto wirklich löschen; Selbstbedienung unter „Mein Konto“ (LAW-04, QA-08).
        AccountDeletion::register();
        SelfService::register();
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
     * Quellen des Exports (Art. 15/20 DSGVO): Tabelle => [Bezeichnung, vorbereitete WHERE-Bedingung].
     * Je Seite eine Quelle, je Zeile ein Eintrag mit allen Feldern (bei großen Tabellen seitenweise).
     * Ohne Konto bleiben nur Gruppeneinladungen an diese Adresse.
     *
     * @return array<string,array{0:string,1:string}>
     */
    private static function sources(int $userId, string $email): array
    {
        global $wpdb;
        $email = strtolower(trim($email));
        $members = [__('Gruppenmitgliedschaften und Einladungen', 'bootspruefung-learning'), $userId > 0
            ? $wpdb->prepare('user_id = %d OR email = %s', $userId, $email)
            : $wpdb->prepare('email = %s', $email)];
        if ($userId <= 0) {
            return ['learning_group_member' => $members];
        }
        $plain = [
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
            'learning_group_member' => null,
            'mobile_session' => [__('App-Gerätesitzungen', 'bootspruefung-learning'), 'user_id = %d'],
            'audit_log' => [__('Protokoll (eigene Vorgänge)', 'bootspruefung-learning'), 'actor_user_id = %d OR (object_type = \'user\' AND object_id = %s)'],
        ];
        $out = [];
        foreach ($plain as $table => $source) {
            if ($source === null) {
                $out[$table] = $members;
                continue;
            }
            [$label, $where] = $source;
            $args = substr_count($where, '%d') + substr_count($where, '%s') === 2 ? [$userId, (string) $userId] : [$userId];
            $out[$table] = [$label, $wpdb->prepare($where, ...$args)];
        }

        return $out;
    }

    private const EXPORT_LIMIT = 2000;

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        global $wpdb;
        $sources = self::sources($user ? (int) $user->ID : 0, $email);
        $tables = array_keys($sources);
        // Seite n → Tabelle und Teilstück (Tabellen mit mehr als EXPORT_LIMIT Zeilen über mehrere Seiten).
        $index = 0;
        $offset = 0;
        $remaining = max(1, $page);
        foreach ($tables as $i => $table) {
            $count = (int) $wpdb->get_var(self::sql('SELECT COUNT(*)', $table, $sources[$table][1]));
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
        $extra = $page === 1 && $user ? self::appSettingsItems((int) $user->ID) : [];
        $table = $tables[$index];
        $rows = $wpdb->get_results(self::sql('SELECT *', $table, $sources[$table][1]).' ORDER BY id LIMIT '.self::EXPORT_LIMIT.' OFFSET '.(int) $offset, ARRAY_A) ?: [];
        $data = [];
        foreach ($rows as $row) {
            unset($row['invite_token'], $row['join_token'], $row['idempotency_key'], $row['access_hash'], $row['refresh_hash'], $row['prev_refresh_hash']);
            $data[] = [
                'group_id' => self::GROUP.'-'.$table,
                'group_label' => __('Bootsprüfung', 'bootspruefung-learning').' – '.$sources[$table][0],
                'item_id' => 'bp-'.$table.'-'.($row['id'] ?? md5(serialize($row))),
                'data' => array_map(static fn ($k, $v) => ['name' => $k, 'value' => is_scalar($v) || $v === null ? (string) $v : wp_json_encode($v)], array_keys($row), $row),
            ];
        }
        $last = $index === count($tables) - 1 && count($rows) < self::EXPORT_LIMIT;

        return ['data' => array_merge($extra, $data), 'done' => $last];
    }

    /** Synchronisierte App-Einstellungen (Nutzer-Meta bp_app_settings, GET/PUT /me/settings). @return list<array<string,mixed>> */
    private static function appSettingsItems(int $userId): array
    {
        $state = \Bootspruefung\Learning\Mobile\AppSettings::get($userId);
        if ($state['version'] === 0) {
            return [];
        }

        return [[
            'group_id' => self::GROUP.'-app_settings',
            'group_label' => __('Bootsprüfung', 'bootspruefung-learning').' – '.__('App-Einstellungen', 'bootspruefung-learning'),
            'item_id' => 'bp-app-settings-'.$userId,
            'data' => [
                ['name' => 'settings', 'value' => (string) wp_json_encode($state['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ['name' => 'version', 'value' => (string) $state['version']],
                ['name' => 'updated_at', 'value' => (string) $state['updated_at']],
            ],
        ]];
    }

    /** $where ist bereits vorbereitet (sources()). */
    private static function sql(string $select, string $table, string $where): string
    {
        global $wpdb;

        return $select.' FROM `'.esc_sql($wpdb->prefix.'bp_'.$table).'` WHERE ('.$where.')';
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            // Kein Konto: nur Gruppeneinladungen an diese Adresse (Adresse und Link löschen).
            $affected = self::eraseGroupMembers(0, $email);
            if ($affected === false) {
                return ['items_removed' => false, 'items_retained' => true, 'messages' => [(string) $GLOBALS['wpdb']->last_error], 'done' => true];
            }

            return ['items_removed' => $affected > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        return self::eraseUser((int) $user->ID, $email);
    }

    /**
     * Gruppenplätze/Einladungen: E-Mail-Adresse und Einladungslink entfernen; offene Einladungen
     * gelten danach als entfernt (Platz frei). Die Platzhistorie bleibt (Kontingent der Bestellung).
     */
    private static function eraseGroupMembers(int $userId, string $email): int|false
    {
        global $wpdb;
        $email = strtolower(trim($email));
        $match = $userId > 0 ? $wpdb->prepare('(user_id = %d OR email = %s)', $userId, $email) : $wpdb->prepare('email = %s', $email);
        if ($email === '' && $userId <= 0) {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s');
        // MySQL wertet SET von links nach rechts aus: removed_at vor status setzen.
        $sql = $wpdb->prepare(
            'UPDATE `'.esc_sql($wpdb->prefix.'bp_learning_group_member')."` SET removed_at = IF(status = 'invited', %s, removed_at), status = IF(status = 'invited', 'removed', status),
             email = NULL, invite_token = NULL, updated_at = %s WHERE ",
            $now, $now
        ).$match.' AND (email IS NOT NULL OR invite_token IS NOT NULL)';
        $affected = $wpdb->query($sql);

        return $affected === false ? false : (int) $affected;
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function eraseUser(int $userId, string $email): array
    {
        /**
         * Vor dem Löschen der Lerndaten (z. B. Geld-zurück-Garantie: Nutzungsstand an der Bestellung festschreiben).
         *
         * @param int $userId
         */
        do_action('bootspruefung_before_erase_user', $userId);
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
            // Synchronisierte App-Einstellungen (Nutzer-Meta).
            $removed = \Bootspruefung\Learning\Mobile\AppSettings::delete((int) $user->ID) || $removed;
            // Merker der Ablauf-Erinnerungen (Nutzer-Meta).
            $removed = \Bootspruefung\Learning\Commerce\ExpiryReminder::forget((int) $user->ID) || $removed;
            // Gruppenplätze: E-Mail-Adresse entfernen, Platzhistorie bleibt (Kontingent der Bestellung).
            $members = self::eraseGroupMembers((int) $user->ID, $email);
            if ($members === false) {
                throw new \RuntimeException($wpdb->last_error);
            }
            $removed = $removed || $members > 0;
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
