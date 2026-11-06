<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

use Bootspruefung\Learning\Settings\Settings;

/**
 * Zugriffsprüfung je Kurs und Feature (Kap. 7.1–7.3).
 *
 * - voll: aktives, gültiges Entitlement, Free-Regel ohne Limit oder Einstellung
 *   „Zugang ohne Shop“ = alle angemeldeten Nutzer;
 * - Teilumfang: Free-Regel `free_limited` (z. B. erste n Fragen je Modul,
 *   n Prüfungsbögen, erste n Lektionen/Aufgaben);
 * - zeitgesteuert: Regel `drip` gibt ein Modul/Kapitel n Tage nach Beginn des
 *   Zugangs frei (Beginn = frühester aktiver Zugang, sonst Registrierung).
 */
final class AccessService
{
    public const QUESTION_BANK = 'question_bank';
    public const EXPLANATIONS = 'learning_explanations';
    public const EXAM = 'exam_simulation';
    public const NAVIGATION = 'navigation_trainer';
    public const PRAXIS = 'praxis_trainer';
    public const VIDEO = 'video_course';
    public const RADIO = 'radio_training';
    public const DEVICE = 'device_simulation';

    public const FEATURES = [self::QUESTION_BANK, self::EXPLANATIONS, self::EXAM, self::NAVIGATION, self::PRAXIS, self::VIDEO, self::RADIO, self::DEVICE];

    /** Limit-Typen je Feature für `free_limited` (Kap. 7.2). */
    public const LIMIT_TYPES = [
        self::QUESTION_BANK => 'questions_per_module',
        self::EXAM => 'exam_papers',
        self::NAVIGATION => 'first_items',
        self::PRAXIS => 'first_items',
        self::VIDEO => 'first_items',
        self::RADIO => 'first_items',
    ];

    /** Features mit Modulen/Kapiteln für zeitgesteuerte Freigabe. */
    public const DRIP_FEATURES = [self::QUESTION_BANK, self::VIDEO];

    public function can(int $userId, string $courseCode, string $feature): bool
    {
        $scope = $this->scope($userId, $courseCode, $feature);

        return $scope['full'] || ($scope['limit'] ?? 0) > 0;
    }

    /** Voller Zugriff (ohne Teilumfang). */
    public function full(int $userId, string $courseCode, string $feature): bool
    {
        return $this->scope($userId, $courseCode, $feature)['full'];
    }

    /** null = unbegrenzt, 0 = kein Zugriff, n = Teilumfang. */
    public function limit(int $userId, string $courseCode, string $feature): ?int
    {
        $scope = $this->scope($userId, $courseCode, $feature);

        return $scope['full'] ? null : (int) ($scope['limit'] ?? 0);
    }

    /** @return array{full:bool,limit:?int} */
    public function scope(int $userId, string $courseCode, string $feature): array
    {
        if ($userId <= 0) {
            return ['full' => false, 'limit' => null];
        }
        $full = $this->freeRule($courseCode, $feature) || $this->entitled($userId, $courseCode, $feature)
            || Settings::get('default_access') === 'logged_in';
        /** Filter: Zugriffsentscheidung erweitern/einschränken (z. B. für Gruppen oder Fremdsysteme). */
        $full = (bool) apply_filters('bootspruefung_can_access', $full, $userId, $courseCode, $feature);
        $limit = $full ? null : $this->freeLimit($courseCode, $feature);

        return ['full' => $full, 'limit' => $limit];
    }

    /** @return array<string,bool> Feature => erlaubt (voll oder Teilumfang) */
    public function features(int $userId, string $courseCode): array
    {
        $out = [];
        foreach (self::FEATURES as $feature) {
            $out[$feature] = $this->can($userId, $courseCode, $feature);
        }

        return $out;
    }

    /** @return array<string,int> Feature => Limit, nur für Features im Teilumfang */
    public function limits(int $userId, string $courseCode): array
    {
        $out = [];
        foreach (self::FEATURES as $feature) {
            $scope = $this->scope($userId, $courseCode, $feature);
            if (! $scope['full'] && ($scope['limit'] ?? 0) > 0) {
                $out[$feature] = (int) $scope['limit'];
            }
        }

        return $out;
    }

    /**
     * Zeitpunkt (UTC), ab dem ein Modul/Kapitel freigegeben ist; null = sofort.
     */
    public function unlockAt(int $userId, string $courseCode, string $feature, string $moduleCode): ?string
    {
        global $wpdb;
        $days = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(limit_value) FROM {$wpdb->prefix}bp_access_rule WHERE course_code = %s AND feature_code = %s AND module_code = %s AND access_type = 'drip'",
            $courseCode, $feature, $moduleCode
        ));
        if ($days === null || (int) $days <= 0) {
            return null;
        }
        $start = $this->accessStart($userId, $courseCode, $feature);

        return gmdate('Y-m-d H:i:s', strtotime($start.' UTC') + (int) $days * DAY_IN_SECONDS);
    }

    public function unlocked(int $userId, string $courseCode, string $feature, string $moduleCode): bool
    {
        $at = $this->unlockAt($userId, $courseCode, $feature, $moduleCode);

        return $at === null || $at <= current_time('mysql', true);
    }

    /** Beginn des Zugangs: frühester aktiver Zugang, sonst Registrierung. */
    public function accessStart(int $userId, string $courseCode, string $feature): string
    {
        global $wpdb;
        $start = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(valid_from) FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d AND course_code = %s AND feature_code IN (%s, 'all') AND status = 'active'",
            $userId, $courseCode, $feature
        ));
        if ($start !== null) {
            return (string) $start;
        }
        $user = get_userdata($userId);

        return $user && $user->user_registered ? (string) $user->user_registered : current_time('mysql', true);
    }

    private function freeRule(string $courseCode, string $feature): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}bp_access_rule WHERE course_code = %s AND feature_code = %s AND module_code IS NULL AND access_type = 'free'
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s) LIMIT 1",
            $courseCode, $feature, $now, $now
        ));
    }

    private function freeLimit(string $courseCode, string $feature): ?int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(limit_value) FROM {$wpdb->prefix}bp_access_rule WHERE course_code = %s AND feature_code = %s AND module_code IS NULL AND access_type = 'free_limited'
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s)",
            $courseCode, $feature, $now, $now
        ));

        return $value === null ? null : max(0, (int) $value);
    }

    private function entitled(int $userId, string $courseCode, string $feature): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d AND course_code = %s AND feature_code IN (%s, 'all') AND status = 'active'
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s) LIMIT 1",
            $userId, $courseCode, $feature, $now, $now
        ));
    }
}
