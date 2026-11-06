<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

use Bootspruefung\Learning\Settings\Settings;

/**
 * Zugriffsprüfung je Kurs und Feature (Kap. 7.1–7.3). Zugang besteht, wenn
 * eine Free-Regel greift oder mindestens ein aktives, gültiges Entitlement
 * vorliegt. Solange kein Shop angebunden ist, regelt die Einstellung
 * „Zugang ohne Shop“, ob angemeldete Nutzer alles nutzen dürfen.
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

    public const FEATURES = [self::QUESTION_BANK, self::EXPLANATIONS, self::EXAM, self::NAVIGATION, self::PRAXIS, self::VIDEO, self::RADIO];

    public function can(int $userId, string $courseCode, string $feature): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $allowed = $this->freeRule($courseCode, $feature) || $this->entitled($userId, $courseCode, $feature)
            || Settings::get('default_access') === 'logged_in';

        /** Filter: Zugriffsentscheidung erweitern/einschränken (z. B. für den WooCommerce-Adapter). */
        return (bool) apply_filters('bootspruefung_can_access', $allowed, $userId, $courseCode, $feature);
    }

    /** @return array<string,bool> Feature => erlaubt */
    public function features(int $userId, string $courseCode): array
    {
        $out = [];
        foreach (self::FEATURES as $feature) {
            $out[$feature] = $this->can($userId, $courseCode, $feature);
        }

        return $out;
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
