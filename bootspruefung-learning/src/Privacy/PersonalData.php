<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Privacy;

use Bootspruefung\Learning\Audit\AuditLog;
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

    /**
     * Kindtabellen der Lerndaten ohne eigene Nutzerspalte: Tabelle => [Spalte, Elterntabelle]. Die Löschung entfernt
     * sie ausdrücklich vor den Elternzeilen (nicht nur über ON DELETE CASCADE, falls ein Fremdschlüssel fehlt).
     */
    public const CHILD_TABLES = [
        'attempt_answer' => ['attempt_id', 'attempt'],
        'exam_session_question' => ['session_id', 'exam_session'],
        'favorite_collection_item' => ['collection_id', 'favorite_collection'],
    ];

    /**
     * Eigene Tabellen ohne Daten von Lernenden (Inhalte, Regeln, Importprotokoll je Eintrag, Zähler mit Hashes) –
     * bewusst nicht im Export (T111). Jede neue Tabelle gehört entweder hierher oder in sources().
     */
    public const NO_PERSONAL_DATA_TABLES = [
        'course', 'module', 'course_module', 'question', 'question_revision', 'answer', 'module_question',
        'media', 'content_media', 'exam_blueprint', 'exam_paper', 'exam_paper_question',
        'navigation_task', 'navigation_task_course', 'navigation_question', 'praxis_task',
        'video_module', 'video_lesson', 'video_lesson_step', 'video_lesson_question',
        'radio_scenario', 'radio_text', 'radio_vocabulary', 'device', 'device_lesson', 'device_step',
        'import_item_log', 'access_rule', 'product_access', 'exam_committee', 'exam_guide', 'mobile_rate_limit',
    ];

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
        add_action('deleted_user', [self::class, 'deletedUser'], 10, 3);
        // Bestätigte Löschanträge: Konto wirklich löschen; Selbstbedienung unter „Mein Konto“ (LAW-04, QA-08).
        AccountDeletion::register();
        SelfService::register();
        // Protokoll: Einträge älter als AUDIT_RETENTION_YEARS Jahre löschen (stündlicher Plugin-Cron).
        add_action('bp_learning_hourly', \Bootspruefung\Learning\Infrastructure\Cron::guard('audit_prune', [self::class, 'pruneAuditLog']));
    }

    /**
     * deleted_user: Daten dieser Website löschen. Multisite (T112): wp_delete_user entfernt das Konto nur aus dieser
     * Website (es bleibt im Netzwerk) – dann nur diese Website. Ist das Konto danach netzweit weg (wpmu_delete_user,
     * auch „Netzwerkverwaltung › Benutzer › Löschen“), alle Websites mit Plugin-Tabellen.
     *
     * @param mixed $userId @param mixed $reassign @param mixed $user
     */
    public static function deletedUser($userId, $reassign = null, $user = null): void
    {
        $userId = (int) $userId;
        $email = $user instanceof \WP_User ? (string) $user->user_email : '';
        $net = AccountDeletion::network();
        $current = $net->currentSite();
        $sites = $net->isMultisite() && ! $net->userExists($userId) ? $net->sitesWithTables() : [$current];
        foreach ($sites as $siteId) {
            $net->inSite((int) $siteId, static function () use ($userId, $email, $siteId, $current): void {
                global $wpdb;
                (new \Bootspruefung\Learning\Commerce\GroupService($wpdb))->releaseUser($userId);
                // Auf anderen Websites keinen Nutzungsstand an Bestellungen festschreiben (Konto ist dort ohnehin weg).
                self::eraseUser($userId, $email, (int) $siteId === $current);
                // Keine Zugangszeilen ohne Konto stehen lassen (T42).
                AccountDeletion::closeEntitlements($userId);
            });
        }
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
     * Quellen des Exports (Art. 15/20 DSGVO): Tabelle => [Bezeichnung, vorbereitete WHERE-Bedingung, Sortierspalte,
     * ausgelassene Spalten]. Je Seite eine Quelle, je Zeile ein Eintrag mit allen Feldern (bei großen Tabellen
     * seitenweise). Kindtabellen ohne eigene Nutzerspalte (gewählte Antworten, Fragen der Prüfungssimulationen,
     * Fragen der Sammlungen) über eine Unterabfrage auf die eigenen Elternzeilen (T111). Ausgelassen werden nur
     * Geheimnisse (Einladungs-/Beitrittslinks, Token-Hashes) und reine Kopien anderer Quellen.
     * Ohne Konto bleiben nur Gruppeneinladungen an diese Adresse.
     *
     * Abgleich mit Schema::TABLES (T111): Inhaltstabellen (Kurse, Fragen, Medien, Regeln, Prüfungsausschüsse …)
     * enthalten keine Daten von Lernenden; mobile_rate_limit nur Hashes ohne Kontobezug.
     *
     * @return array<string,array{0:string,1:string,2:string,3:list<string>}>
     */
    private static function sources(int $userId, string $email): array
    {
        global $wpdb;
        $email = strtolower(trim($email));
        $members = [__('Gruppenmitgliedschaften und Einladungen', 'bootspruefung-learning'), $userId > 0
            ? $wpdb->prepare('user_id = %d OR email = %s', $userId, $email)
            : $wpdb->prepare('email = %s', $email), 'id', ['invite_token']];
        if ($userId <= 0) {
            return ['learning_group_member' => $members];
        }
        $p = $wpdb->prefix.'bp_';
        $plain = [
            'attempt' => [__('Antworten (Lernmodus)', 'bootspruefung-learning'), 'user_id = %d', 'id', ['idempotency_key']],
            'attempt_answer' => [__('Antworten (Lernmodus) – gewählte Antworten und Freitexte', 'bootspruefung-learning'), "attempt_id IN (SELECT id FROM {$p}attempt WHERE user_id = %d)", 'id', []],
            'progress' => [__('Lernstand je Frage', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'favorite' => [__('Favoriten', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'favorite_collection' => [__('Sammlungen', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'favorite_collection_item' => [__('Sammlungen – enthaltene Fragen', 'bootspruefung-learning'), "collection_id IN (SELECT id FROM {$p}favorite_collection WHERE user_id = %d)", 'collection_id, question_id', []],
            'exam_session' => [__('Prüfungssimulationen', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'exam_session_question' => [__('Prüfungssimulationen – Fragen, eigene Antworten und Markierungen', 'bootspruefung-learning'), "session_id IN (SELECT id FROM {$p}exam_session WHERE user_id = %d)", 'id', []],
            'navigation_progress' => [__('Navigationsaufgaben (eigene Antworten)', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'praxis_progress' => [__('Praxiskarten', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'video_progress' => [__('Videokurs', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'radio_progress' => [__('Funktraining', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'entitlement' => [__('Kurszugänge', 'bootspruefung-learning'), 'user_id = %d', 'id', ['idempotency_key']],
            'learning_group' => [__('Eigene Gruppen', 'bootspruefung-learning'), 'owner_user_id = %d', 'id', ['join_token']],
            'learning_group_member' => null,
            'mobile_session' => [__('App-Gerätesitzungen', 'bootspruefung-learning'), 'user_id = %d', 'id', ['access_hash', 'refresh_hash', 'prev_refresh_hash']],
            // Technische App-Daten (werden automatisch gelöscht): ohne gespeicherte Antworten (Kopien, teils mit Beitrittscodes),
            // ohne Hashes und ohne den Inhalt früherer Exporte.
            'mobile_idempotency' => [__('App – Schutz vor doppelten Vorgängen (technisch, wird automatisch gelöscht)', 'bootspruefung-learning'), 'user_id = %d', 'id', ['request_hash', 'response']],
            'mobile_export' => [__('App – angeforderte Datenexporte', 'bootspruefung-learning'), 'user_id = %d', 'id', ['public_id', 'payload']],
            'mobile_web_code' => [__('App – Anmeldungen in der Webansicht (technisch)', 'bootspruefung-learning'), 'user_id = %d', 'id', ['code_hash', 'verifier_hash']],
            'audit_log' => [__('Protokoll (eigene Vorgänge)', 'bootspruefung-learning'), 'actor_user_id = %d OR (object_type = \'user\' AND object_id = %s)', 'id', []],
            // Verwaltungskonten: wer Inhalte importiert, freigegeben, korrigiert oder Prüfungsregeln geprüft hat.
            'import_batch' => [__('Verwaltung – Importe (angelegt oder freigegeben)', 'bootspruefung-learning'), 'created_by = %d OR approved_by = %d', 'id', []],
            'override' => [__('Verwaltung – manuelle Korrekturen an Inhalten', 'bootspruefung-learning'), 'user_id = %d', 'id', []],
            'exam_rule' => [__('Verwaltung – geprüfte Prüfungsregeln', 'bootspruefung-learning'), 'verified_by = %d', 'id', []],
        ];
        $out = [];
        foreach ($plain as $table => $source) {
            if ($source === null) {
                $out[$table] = $members;
                continue;
            }
            [$label, $where, $order, $omit] = $source;
            $args = [];
            foreach ((array) preg_split('/(%[ds])/', $where, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                if ($part === '%d') {
                    $args[] = $userId;
                } elseif ($part === '%s') {
                    $args[] = (string) $userId;
                }
            }
            $out[$table] = [$label, $wpdb->prepare($where, ...$args), $order, $omit];
        }

        return $out;
    }

    /** Tabellen (ohne Präfix), die der Export für ein Konto abfragt. @return list<string> */
    public static function exportTables(): array
    {
        return array_keys(self::sources(1, 'export@example.invalid'));
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
        $extra = $page === 1 && $user ? array_merge(self::appSettingsItems((int) $user->ID), self::ticketItems((int) $user->ID), self::reminderItems((int) $user->ID)) : [];
        $table = $tables[$index];
        [, $where, $order, $omit] = $sources[$table];
        $rows = $wpdb->get_results(self::sql('SELECT *', $table, $where).' ORDER BY '.$order.' LIMIT '.self::EXPORT_LIMIT.' OFFSET '.(int) $offset, ARRAY_A) ?: [];
        $data = [];
        foreach ($rows as $row) {
            $row = array_diff_key($row, array_flip($omit));
            $data[] = [
                'group_id' => self::GROUP.'-'.$table,
                'group_label' => __('Bootsprüfung', 'bootspruefung-learning').' – '.$sources[$table][0],
                'item_id' => 'bp-'.$table.'-'.($row['id'] ?? implode('-', array_map('strval', $row))),
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

    /** Praxis-Tickets (Nutzer-Meta _bp_tickets, vom Ticket-Plugin gemeldet). @return list<array<string,mixed>> */
    private static function ticketItems(int $userId): array
    {
        return \Bootspruefung\Learning\Extensions\Tickets::exportItems($userId, self::GROUP.'-tickets', __('Bootsprüfung', 'bootspruefung-learning').' – '.__('Praxis-Tickets', 'bootspruefung-learning'));
    }

    /**
     * Ablauf-Erinnerungen (Nutzer-Meta): bereits gesendete Erinnerungen (Kurs|Ablaufdatum => gesendet am) und ein
     * Widerspruch gegen die Erinnerungs-Mails (Zeitpunkt und Weg). @return list<array<string,mixed>>
     */
    private static function reminderItems(int $userId): array
    {
        $items = [];
        $label = __('Bootsprüfung', 'bootspruefung-learning').' – '.__('Erinnerungen an den Ablauf des Zugangs', 'bootspruefung-learning');
        $sent = get_user_meta($userId, \Bootspruefung\Learning\Commerce\ExpiryReminder::META, true);
        foreach (is_array($sent) ? $sent : [] as $key => $at) {
            $pos = (int) strrpos((string) $key, '|');
            $items[] = [
                'group_id' => self::GROUP.'-expiry_reminders',
                'group_label' => $label,
                'item_id' => 'bp-expiry-reminder-'.md5((string) $key),
                'data' => [
                    ['name' => __('Kurs', 'bootspruefung-learning'), 'value' => substr((string) $key, 0, $pos)],
                    ['name' => __('Zugang endet', 'bootspruefung-learning'), 'value' => substr((string) $key, $pos + 1)],
                    ['name' => __('Erinnerung gesendet am (UTC)', 'bootspruefung-learning'), 'value' => (string) $at],
                ],
            ];
        }
        $optout = get_user_meta($userId, \Bootspruefung\Learning\Commerce\ReminderOptOut::META, true);
        if (is_array($optout) && $optout !== []) {
            $items[] = [
                'group_id' => self::GROUP.'-expiry_reminders',
                'group_label' => $label,
                'item_id' => 'bp-expiry-reminder-optout-'.$userId,
                'data' => [
                    ['name' => __('Widerspruch gegen Erinnerungs-E-Mails am (UTC)', 'bootspruefung-learning'), 'value' => (string) ($optout['at'] ?? '')],
                    ['name' => __('Weg', 'bootspruefung-learning'), 'value' => (string) ($optout['via'] ?? '')],
                ],
            ];
        }

        return $items;
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
    public static function eraseUser(int $userId, string $email, bool $beforeHook = true): array
    {
        if ($beforeHook) {
            /**
             * Vor dem Löschen der Lerndaten (z. B. Geld-zurück-Garantie: Nutzungsstand an der Bestellung festschreiben).
             *
             * @param int $userId
             */
            do_action('bootspruefung_before_erase_user', $userId);
        }
        $user = (object) ['ID' => $userId];
        global $wpdb;
        $removed = false;
        $wpdb->query('START TRANSACTION');
        try {
            // Kindtabellen zuerst (zusätzlich zu ON DELETE CASCADE, falls ein Fremdschlüssel fehlt).
            foreach (self::CHILD_TABLES as $child => [$column, $parent]) {
                $deleted = $wpdb->query($wpdb->prepare(
                    'DELETE FROM `'.esc_sql($wpdb->prefix.'bp_'.$child).'` WHERE `'.esc_sql($column).'` IN (SELECT id FROM `'.esc_sql($wpdb->prefix.'bp_'.$parent).'` WHERE user_id = %d)',
                    $user->ID
                ));
                if ($deleted === false) {
                    throw new \RuntimeException($wpdb->last_error);
                }
                $removed = $removed || $deleted > 0;
            }
            foreach (Schema::USER_DATA_TABLES as $table) {
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
            // Praxis-Tickets (Nutzer-Meta; die Tickets selbst führt das Ticket-Plugin).
            $removed = \Bootspruefung\Learning\Extensions\Tickets::erase((int) $user->ID) || $removed;
            // Gruppenplätze: E-Mail-Adresse entfernen, Platzhistorie bleibt (Kontingent der Bestellung).
            $members = self::eraseGroupMembers((int) $user->ID, $email);
            if ($members === false) {
                throw new \RuntimeException($wpdb->last_error);
            }
            $removed = $removed || $members > 0;
            // Eigene Gruppen: frei gewählten Namen neutralisieren (kann Personenbezug enthalten).
            $wpdb->query($wpdb->prepare('UPDATE `'.esc_sql($wpdb->prefix.'bp_learning_group').'` SET name = CONCAT(%s, id) WHERE owner_user_id = %d', __('Gruppe #', 'bootspruefung-learning'), $user->ID));
            // Protokoll mit Zeitpunkt in UTC wie alle Einträge (AuditLog::record), Kennung nur als Hash.
            AuditLog::record('personal_data_erased', 'user', hash('sha256', (string) $user->ID.wp_salt()));
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
        if (\Bootspruefung\Learning\Commerce\ReminderOptOut::optedOut((int) $user->ID)) {
            $messages[] = __('Dein Widerspruch gegen die Erinnerungs-E-Mails bleibt gespeichert, damit wir ihn weiter beachten.', 'bootspruefung-learning');
        }
        $p = $wpdb->prefix.'bp_';
        $staff = (int) $wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$p}import_batch WHERE created_by = %d OR approved_by = %d) + (SELECT COUNT(*) FROM {$p}override WHERE user_id = %d) + (SELECT COUNT(*) FROM {$p}exam_rule WHERE verified_by = %d)", $user->ID, $user->ID, $user->ID, $user->ID));
        if ($staff > 0) {
            $messages[] = __('Vermerke, wer Inhalte importiert, freigegeben, korrigiert oder Prüfungsregeln geprüft hat, bleiben zum Nachweis der Inhaltspflege erhalten.', 'bootspruefung-learning');
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
