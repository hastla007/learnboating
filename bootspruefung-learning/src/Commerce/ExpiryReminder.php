<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Support\Mail;

/**
 * Erinnerung vor Ablauf eines Zugangs zum Fragenkatalog (täglicher WP-Cron bp_learning_daily).
 *
 * Erinnert wird je Nutzer, Kurs und Ablaufdatum genau einmal (Nutzer-Meta bp_expiry_reminders),
 * mehrere Kurse eines Nutzers in einer Mail. Keine Erinnerung, wenn für denselben Kurs ein späterer
 * (z. B. Verlängerung) oder unbefristeter Zugang besteht, für den kostenlosen Teilumfang (keine
 * Zugänge) und solange „Zugang ohne Shop“ alle angemeldeten Nutzer freischaltet.
 */
final class ExpiryReminder
{
    public const HOOK = 'bp_learning_daily';
    public const META = 'bp_expiry_reminders';
    public const DEFAULT_DAYS = 14;
    public const MAX_DAYS = 90;

    public static function enabled(): bool
    {
        return (bool) Settings::get('expiry_reminder_enabled');
    }

    public static function days(): int
    {
        return max(1, min(self::MAX_DAYS, (int) (Settings::get('expiry_reminder_days') ?: self::DEFAULT_DAYS)));
    }

    /** Täglichen Cron planen (Aktivierung; bestehende Installationen über den stündlichen Cron). */
    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    /**
     * Bald endende Zugänge zum Fragenkatalog ohne späteren oder unbefristeten Zugang.
     * Eine Abfrage für alle Nutzer bzw. für einen Nutzer.
     *
     * @return array<int,array<string,string>> user_id => [Kurscode => Ende (UTC, MySQL-Format)]
     */
    public static function expiring(?int $userId = null, ?int $days = null, ?string $now = null): array
    {
        if (Settings::get('default_access') === 'logged_in') {
            return []; // Zugang endet nicht wirklich, solange alle angemeldeten Nutzer lernen dürfen.
        }
        global $wpdb;
        $t = $wpdb->prefix.'bp_entitlement';
        $now ??= current_time('mysql', true);
        $limit = gmdate('Y-m-d H:i:s', (int) strtotime($now.' UTC') + ($days ?? self::days()) * DAY_IN_SECONDS);
        $features = "e.feature_code IN ('".esc_sql(AccessService::QUESTION_BANK)."', 'all')";
        $userSql = $userId !== null ? $wpdb->prepare(' AND e.user_id = %d', $userId) : '';
        // Alle noch nicht abgelaufenen aktiven Zugänge der Kurse, in denen einer im Fenster endet.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.user_id, e.course_code, e.valid_from, e.valid_until FROM {$t} e
             WHERE e.status = 'active' AND {$features} AND (e.valid_until IS NULL OR e.valid_until >= %s){$userSql}
             AND EXISTS (SELECT 1 FROM {$t} x WHERE x.user_id = e.user_id AND x.course_code = e.course_code AND x.status = 'active'
                AND x.feature_code IN (%s, 'all') AND x.valid_until IS NOT NULL AND x.valid_until >= %s AND x.valid_until <= %s)",
            $now, AccessService::QUESTION_BANK, $now, $limit
        ));
        $groups = [];
        foreach ($rows as $r) {
            $groups[(int) $r->user_id][(string) $r->course_code][] = $r;
        }
        $free = $groups === [] ? [] : self::freeCourses($now);
        $out = [];
        foreach ($groups as $uid => $courses) {
            foreach ($courses as $code => $list) {
                if (isset($free[$code])) {
                    continue;
                }
                $current = false;
                $end = null;
                foreach ($list as $r) {
                    if ($r->valid_until === null) {
                        continue 2; // unbefristeter Zugang
                    }
                    $current = $current || $r->valid_from === null || $r->valid_from <= $now;
                    $end = $end === null ? (string) $r->valid_until : max($end, (string) $r->valid_until);
                }
                // Späterer Zugang (Verlängerung) verschiebt das Ende aus dem Fenster.
                if ($current && $end !== null && $end <= $limit) {
                    $out[$uid][$code] = $end;
                }
            }
        }

        return array_map([self::class, 'collapse'], $out);
    }

    /**
     * Mitgekaufte Teilkurse nicht einzeln nennen: Endet ein Kurs zum selben Zeitpunkt wie ein Kurs,
     * der ihn laut CourseRelations enthält (z. B. Kombi „SBF See & Binnen“ mit SBF See und SBF Binnen),
     * bleibt nur der umfassendere (mehr ausgeblendete Kurse) stehen.
     *
     * @param array<string,string> $courses Kurscode => Ende
     * @return array<string,string>
     */
    public static function collapse(array $courses): array
    {
        $rules = CourseRelations::rules();
        $weight = static fn (string $code): int => count($rules[$code]['hide'] ?? []);
        $drop = [];
        foreach ($courses as $code => $end) {
            foreach ($courses as $other => $otherEnd) {
                if ($other !== $code && $otherEnd === $end && in_array($code, $rules[$other]['hide'] ?? [], true) && $weight($other) > $weight($code)) {
                    $drop[$code] = true;
                    break;
                }
            }
        }

        return array_diff_key($courses, $drop);
    }

    /** Kurse, deren Fragenkatalog für alle kostenlos ist (endet nicht). @return array<string,true> */
    private static function freeCourses(string $now): array
    {
        global $wpdb;
        $codes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT course_code FROM {$wpdb->prefix}bp_access_rule WHERE feature_code = %s AND module_code IS NULL AND access_type = 'free'
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s)",
            AccessService::QUESTION_BANK, $now, $now
        ));

        return array_fill_keys(array_map('strval', $codes), true);
    }

    /** Verlängern: erstes passendes Shop-Angebot für den Fragenkatalog, sonst die Kursseite im Lernbereich. */
    public static function renewUrl(string $courseCode): string
    {
        $offers = Integration::offers($courseCode, [AccessService::QUESTION_BANK => false]);

        return (string) ($offers[AccessService::QUESTION_BANK][0]['url'] ?? Router::url(strtolower($courseCode)));
    }

    /** Ablaufdatum im deutschen Format (Zeitzone der Website). */
    public static function date(string $mysqlGmt): string
    {
        return wp_date('d.m.Y', (int) strtotime($mysqlGmt.' UTC'));
    }

    /**
     * Cron: Erinnerungen senden. Gibt die Anzahl gesendeter Mails zurück.
     */
    public static function run(?string $now = null): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $now ??= current_time('mysql', true);
        $sent = 0;
        foreach (self::expiring(null, null, $now) as $userId => $courses) {
            $user = get_userdata($userId);
            if (! $user || ! is_email((string) $user->user_email)) {
                continue;
            }
            $done = self::reminded($userId, $now);
            $new = [];
            foreach ($courses as $code => $until) {
                if (! isset($done[self::key($code, $until)])) {
                    $new[$code] = $until;
                }
            }
            if ($new === []) {
                continue;
            }
            asort($new);
            [$subject, $body] = self::mail($user, $new);
            if (! Mail::send((string) $user->user_email, $subject, $body)) {
                continue; // beim nächsten Lauf erneut versuchen
            }
            foreach ($new as $code => $until) {
                $done[self::key($code, $until)] = $now;
            }
            update_user_meta($userId, self::META, $done);
            $sent++;
        }

        return $sent;
    }

    /** Bereits erinnerte Zugänge (Kurs|Ende => gesendet am), ohne vergangene Ablaufdaten. @return array<string,string> */
    private static function reminded(int $userId, string $now): array
    {
        $stored = get_user_meta($userId, self::META, true);
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $key => $at) {
            $until = substr((string) $key, (int) strrpos((string) $key, '|') + 1);
            if ($until >= $now) {
                $out[(string) $key] = (string) $at;
            }
        }

        return $out;
    }

    private static function key(string $courseCode, string $until): string
    {
        return $courseCode.'|'.$until;
    }

    /**
     * @param array<string,string> $courses Kurscode => Ende
     * @return array{0:string,1:string} Betreff, Text
     */
    public static function mail(\WP_User $user, array $courses): array
    {
        $d = 'bootspruefung-learning';
        $name = trim((string) $user->first_name);
        /* translators: %s: first name */
        $lines = [$name !== '' ? sprintf(__('Hallo %s,', $d), $name) : __('Hallo,', $d), ''];
        if (count($courses) === 1) {
            $code = (string) array_key_first($courses);
            $course = Labels::course($code);
            /* translators: %s: course name */
            $subject = sprintf(__('Dein Zugang zu %s endet bald', $d), $course);
            /* translators: 1: course name, 2: date */
            $lines[] = sprintf(__('dein Zugang zu %1$s endet am %2$s.', $d), $course, self::date($courses[$code]));
            $lines[] = __('Dein Lernstand bleibt erhalten.', $d);
            $lines[] = '';
            /* translators: %s: link */
            $lines[] = sprintf(__('Jetzt verlängern: %s', $d), self::renewUrl($code));
        } else {
            $subject = __('Deine Lernzugänge enden bald', $d);
            $lines[] = __('diese Zugänge enden bald:', $d);
            $lines[] = '';
            foreach ($courses as $code => $until) {
                /* translators: 1: course name, 2: date */
                $lines[] = '- '.sprintf(__('%1$s: endet am %2$s', $d), Labels::course($code), self::date($until));
                /* translators: %s: link */
                $lines[] = '  '.sprintf(__('Jetzt verlängern: %s', $d), self::renewUrl($code));
            }
            $lines[] = '';
            $lines[] = __('Dein Lernstand bleibt erhalten.', $d);
        }
        $lines[] = '';
        /* translators: %s: link */
        $lines[] = sprintf(__('Zum Lernbereich: %s', $d), Router::url());

        return [$subject, implode("\n", $lines)];
    }

    /** Datenschutz-Löschung: Merker entfernen. */
    public static function forget(int $userId): bool
    {
        return (bool) delete_user_meta($userId, self::META);
    }
}
