<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Infrastructure\Cron;
use Bootspruefung\Learning\Infrastructure\Lock;
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
 *
 * Die Mail ist sachlich („Zugang läuft am … ab“), das Verlängern steht nur als Link darin (gekaufte Stufe).
 * Weil sie trotzdem als Werbung gelten kann (Bestandskunden, § 7 Abs. 3 UWG), enthält jede Mail den
 * Hinweis auf das Widerspruchsrecht mit Abmeldelink (ReminderOptOut, auch als List-Unsubscribe);
 * wer widersprochen hat, bekommt keine Erinnerung mehr.
 */
final class ExpiryReminder
{
    public const HOOK = 'bp_learning_daily';
    /** Einmaliges Ereignis: Fortsetzung eines Laufs, der seine Stapelgröße oder sein Zeitbudget erreicht hat (T124). */
    public const CONTINUE_HOOK = 'bp_learning_expiry_reminder_continue';
    public const META = 'bp_expiry_reminders';
    public const DEFAULT_DAYS = 14;
    public const MAX_DAYS = 90;
    /** Höchstens so viele Empfänger je Lauf (Filter bp_learning_expiry_reminder_batch_size). */
    public const DEFAULT_BATCH = 50;
    /** Zeitbudget je Lauf in Sekunden, höchstens die Hälfte von max_execution_time (Filter bp_learning_expiry_reminder_time_budget). */
    public const DEFAULT_TIME_BUDGET = 20;
    /** Abstand bis zur Fortsetzung in Sekunden. */
    public const CONTINUE_DELAY = 60;
    /** Sperre gegen parallele Läufe (Infrastructure\Lock); eine liegen gebliebene verfällt nach LOCK_TTL. */
    public const LOCK = 'bp_learning_expiry_reminder_lock';
    /** Ein Lauf dauert dank Stapel und Zeitbudget nur Sekunden; eine hängengebliebene Sperre blockiert nicht lange. */
    private const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

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

    /**
     * Verlängern: mit Nutzer die gekaufte Stufe (Produkt des Zugangs, mit Videokurs die Komplett-Stufe),
     * sonst bzw. ohne kaufbares Produkt das erste passende Shop-Angebot, ohne Shop die Kursseite im Lernbereich.
     *
     * @param array<string,array<string,mixed>>|null $states Kaufstatus des Nutzers (AccessStatus::forUser), falls schon geladen
     */
    public static function renewUrl(string $courseCode, ?int $userId = null, ?array $states = null): string
    {
        if ($userId !== null && $userId > 0) {
            $states ??= AccessStatus::forUser($userId);
        }

        return AccessStatus::renewUrl($courseCode, $states[$courseCode] ?? null);
    }

    /** Ablaufdatum im deutschen Format (Zeitzone der Website). */
    public static function date(string $mysqlGmt): string
    {
        return wp_date('d.m.Y', (int) strtotime($mysqlGmt.' UTC'));
    }

    /**
     * Cron: Erinnerungen senden. Gibt die Anzahl gesendeter Mails zurück.
     *
     * Robust und ohne Doppelversand (T80): Eine Sperre verhindert parallele Läufe (z. B. WP-Cron und System-Cron
     * gleichzeitig). Je Empfänger wird der Zugang vor dem Versand als erinnert markiert und nur bei einem
     * fehlgeschlagenen Versand wieder freigegeben; bricht der Lauf mittendrin ab, geht eher eine Erinnerung
     * verloren, als dass sie doppelt kommt. Ein Fehler bei einem Empfänger wird protokolliert (Systemstatus,
     * Infrastructure\Cron) und bricht den Lauf für die übrigen nicht ab.
     *
     * In Stapeln (T124): Ein Lauf bearbeitet höchstens batchSize() Empfänger (nach Nutzer-ID aufsteigend) und
     * hört nach timeBudget() Sekunden auf. Der Rest folgt eine Minute später als einmaliges Ereignis
     * CONTINUE_HOOK (resume()) mit demselben Zeitfenster und der zuletzt bearbeiteten Nutzer-ID; die Sperre
     * wird dazwischen freigegeben.
     */
    public static function run(?string $now = null): int
    {
        // do_action() ohne Argument übergibt '' – dann gilt die aktuelle Zeit.
        return self::process($now !== null && $now !== '' ? $now : null, 0, false);
    }

    /**
     * Fortsetzung eines Laufs (einmaliges Ereignis CONTINUE_HOOK): gleiches Fenster ($now des ersten Stapels), damit
     * Zugänge am Rand des Fensters nicht herausfallen, ab der Nutzer-ID nach $afterUserId. Zugänge, die inzwischen
     * schon abgelaufen sind, werden nicht mehr erinnert. Ist der erste Stapel älter als ein Tag, übernimmt der
     * nächste tägliche Lauf.
     *
     * @param mixed $now        Startzeit des ersten Stapels (UTC, MySQL-Format)
     * @param mixed $afterUserId zuletzt bearbeitete Nutzer-ID
     */
    public static function resume($now = '', $afterUserId = 0): int
    {
        $now = is_string($now) ? $now : '';
        $start = $now !== '' ? strtotime($now.' UTC') : false;
        if ($start === false || $start < time() - DAY_IN_SECONDS) {
            return 0;
        }

        return self::process($now, max(0, (int) $afterUserId), true);
    }

    /** Höchstens so viele Empfänger je Lauf (mindestens 1). */
    public static function batchSize(): int
    {
        return max(1, (int) apply_filters('bp_learning_expiry_reminder_batch_size', self::DEFAULT_BATCH));
    }

    /** Zeitbudget je Lauf in Sekunden: 20 s, höchstens die Hälfte von max_execution_time (0 = unbegrenzt). */
    public static function timeBudget(): float
    {
        $max = (int) ini_get('max_execution_time');
        $default = $max > 0 ? min(self::DEFAULT_TIME_BUDGET, max(1, intdiv($max, 2))) : self::DEFAULT_TIME_BUDGET;

        return max(0.0, (float) apply_filters('bp_learning_expiry_reminder_time_budget', $default));
    }

    /** Fortsetzung in einer Minute einplanen (WordPress verhindert gleiche Ereignisse innerhalb von 10 Minuten). */
    private static function scheduleResume(string $now, int $afterUserId): void
    {
        wp_schedule_single_event(time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK, [$now, $afterUserId]);
    }

    private static function process(?string $now, int $after, bool $resuming): int
    {
        if (! self::enabled()) {
            return 0;
        }
        if (! Lock::acquire(self::LOCK, self::LOCK_TTL)) {
            if ($resuming && $now !== null) {
                self::scheduleResume($now, $after); // ein anderer Lauf sendet gerade: später weitermachen
            }

            return 0;
        }
        $sent = 0;
        try {
            $now ??= current_time('mysql', true);
            // Fortsetzung: schon abgelaufene Zugänge nicht mehr erinnern („läuft am … ab“ läge in der Vergangenheit).
            $floor = $resuming ? max($now, current_time('mysql', true)) : $now;
            $limit = self::batchSize();
            $budget = self::timeBudget();
            $started = microtime(true);
            $count = 0;
            $users = self::expiring(null, null, $now);
            ksort($users);
            foreach ($users as $userId => $courses) {
                $userId = (int) $userId;
                if ($userId <= $after) {
                    continue;
                }
                // Mindestens ein Empfänger je Lauf, damit eine Fortsetzung immer vorankommt.
                if ($count > 0 && ($count >= $limit || microtime(true) - $started >= $budget)) {
                    self::scheduleResume($now, $after);
                    break;
                }
                $after = $userId;
                $count++;
                $courses = array_filter($courses, static fn (string $until): bool => $until >= $floor);
                if ($courses === []) {
                    continue;
                }
                try {
                    if (self::remind($userId, $courses, $now)) {
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    Cron::recordError('expiry_reminder', $e, 'Nutzer '.$userId);
                }
            }
        } catch (\Throwable $e) {
            Cron::recordError('expiry_reminder', $e);
        } finally {
            Lock::release(self::LOCK);
        }

        return $sent;
    }

    /**
     * Erinnerung an eine Person (höchstens eine Mail). @param array<string,string> $courses Kurscode => Ende
     * @return bool true, wenn eine Mail gesendet wurde
     */
    private static function remind(int $userId, array $courses, string $now): bool
    {
        $user = get_userdata($userId);
        if (! $user || ! is_email((string) $user->user_email) || ReminderOptOut::optedOut($userId)) {
            return false; // ohne Adresse oder nach Widerspruch keine Erinnerung
        }
        // Nur an Käufer (§ 7 Abs. 3 UWG): Kurse, die die Person selbst bezahlt gekauft hat (T44).
        $courses = self::boughtCourses($userId, $courses);
        if ($courses === []) {
            return false;
        }
        // Merker frisch aus der Datenbank (nicht aus dem Objekt-Cache eines anderen Laufs).
        wp_cache_delete($userId, 'user_meta');
        $previous = get_user_meta($userId, self::META, true);
        $done = self::reminded($userId, $now);
        $new = [];
        foreach ($courses as $code => $until) {
            if (! isset($done[self::key($code, $until)])) {
                $new[$code] = $until;
            }
        }
        if ($new === []) {
            return false;
        }
        asort($new);
        // Erst markieren, dann senden: ein zweiter Lauf sieht den Merker und sendet nicht noch einmal.
        foreach ($new as $code => $until) {
            $done[self::key($code, $until)] = $now;
        }
        update_user_meta($userId, self::META, $done);
        $ok = false;
        try {
            [$subject, $body] = self::mail($user, $new);
            $ok = Mail::send((string) $user->user_email, $subject, $body, ['List-Unsubscribe: <'.ReminderOptOut::url($userId).'>']);
        } finally {
            if (! $ok) {
                // Nicht gesendet: Merker zurücknehmen, beim nächsten Lauf erneut versuchen.
                if (is_array($previous)) {
                    update_user_meta($userId, self::META, $previous);
                } else {
                    delete_user_meta($userId, self::META);
                }
            }
        }

        return $ok;
    }

    /**
     * Nur Kurse, die die Person selbst gekauft und bezahlt hat (Zugang aus einer WooCommerce-Bestellung mit Betrag über
     * 0 €). Gruppenmitglieder, manuell Freigeschaltete und Gutschein-Einlöser bekommen keine Erinnerungsmail; den
     * Hinweis unter „Mein Lernen“ sehen alle (T44).
     *
     * @param array<string,string> $courses Kurscode => Ende
     * @return array<string,string>
     */
    public static function boughtCourses(int $userId, array $courses): array
    {
        if ($courses === []) {
            return [];
        }
        global $wpdb;
        $in = implode(',', array_fill(0, count($courses), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT course_code, order_id FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d AND source = %s AND order_id IS NOT NULL AND course_code IN ({$in})",
            $userId, EntitlementService::SOURCE_WOO, ...array_keys($courses)
        ));
        $bought = [];
        foreach ((array) $rows as $r) {
            if (function_exists('wc_get_order')) {
                // Mit Shop zusätzlich: eigene, bezahlte Bestellung über 0 € (keine Gutschein-Einlösung).
                $order = wc_get_order((int) $r->order_id);
                if (! $order instanceof \WC_Order || (int) $order->get_customer_id() !== $userId || (float) $order->get_total() <= 0 || $order->get_date_paid() === null) {
                    continue;
                }
            }
            $bought[(string) $r->course_code] = true;
        }

        return array_intersect_key($courses, $bought);
    }

    /** Hinweis bei der Erhebung der E-Mail-Adresse (§ 7 Abs. 3 Nr. 4 UWG): Kasse und Registrierung. */
    public static function collectionNotice(): string
    {
        return __('Kurz bevor ein gekaufter Zugang abläuft, erinnern wir dich per E-Mail daran. Du kannst dem jederzeit widersprechen – über den Link in jeder Erinnerung oder unter „Mein Lernen“ –, ohne dass dir dafür andere als die Übermittlungskosten nach den Basistarifen entstehen.', 'bootspruefung-learning');
    }

    public static function collectionNoticeHtml(): string
    {
        return self::enabled() ? '<p class="bp-reminder-notice"><small>'.esc_html(self::collectionNotice()).'</small></p>' : '';
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
     * Sachliche Erinnerung: welcher Zugang wann abläuft, Lernstand bleibt; Verlängern nur als Link zur
     * gekauften Stufe; Hinweis auf das Widerspruchsrecht mit Abmeldelink (ohne Anmeldung).
     *
     * @param array<string,string> $courses Kurscode => Ende
     * @return array{0:string,1:string} Betreff, Text
     */
    public static function mail(\WP_User $user, array $courses): array
    {
        $userId = (int) $user->ID;
        $states = AccessStatus::forUser($userId);
        $name = trim((string) $user->first_name);
        /* translators: %s: first name */
        $lines = [$name !== '' ? sprintf(__('Hallo %s,', 'bootspruefung-learning'), $name) : __('Hallo,', 'bootspruefung-learning'), ''];
        if (count($courses) === 1) {
            $code = (string) array_key_first($courses);
            $course = Labels::course($code);
            /* translators: 1: course name, 2: date */
            $subject = sprintf(__('Dein Zugang zu %1$s läuft am %2$s ab', 'bootspruefung-learning'), $course, self::date($courses[$code]));
            /* translators: 1: course name, 2: date */
            $lines[] = sprintf(__('dein Zugang zu %1$s läuft am %2$s ab.', 'bootspruefung-learning'), $course, self::date($courses[$code]));
            $lines[] = __('Dein Lernstand bleibt erhalten.', 'bootspruefung-learning');
            $lines[] = '';
            /* translators: %s: link */
            $lines[] = sprintf(__('Verlängern: %s', 'bootspruefung-learning'), self::renewUrl($code, $userId, $states));
        } else {
            $subject = __('Deine Lernzugänge laufen bald ab', 'bootspruefung-learning');
            $lines[] = __('diese Zugänge laufen bald ab:', 'bootspruefung-learning');
            $lines[] = '';
            foreach ($courses as $code => $until) {
                /* translators: 1: course name, 2: date */
                $lines[] = '- '.sprintf(__('%1$s: läuft am %2$s ab', 'bootspruefung-learning'), Labels::course($code), self::date($until));
                /* translators: %s: link */
                $lines[] = '  '.sprintf(__('Verlängern: %s', 'bootspruefung-learning'), self::renewUrl($code, $userId, $states));
            }
            $lines[] = '';
            $lines[] = __('Dein Lernstand bleibt erhalten.', 'bootspruefung-learning');
        }
        $lines[] = '';
        /* translators: %s: link */
        $lines[] = sprintf(__('Zum Lernbereich: %s', 'bootspruefung-learning'), Router::url());
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = __('Du bekommst diese Erinnerung, weil du bei uns einen Lernzugang gekauft hast.', 'bootspruefung-learning');
        /* translators: %s: link */
        $lines[] = sprintf(__('Du kannst diesen Erinnerungen jederzeit widersprechen, ohne dass dir dafür andere als die Übermittlungskosten nach den Basistarifen entstehen. Abbestellen: %s', 'bootspruefung-learning'), ReminderOptOut::url($userId));
        $lines[] = __('Oder in deinem Konto unter „Mein Lernen“.', 'bootspruefung-learning');

        return [$subject, implode("\n", $lines)];
    }

    /** Datenschutz-Löschung: Merker entfernen (der Widerspruch bleibt bestehen). */
    public static function forget(int $userId): bool
    {
        return (bool) delete_user_meta($userId, self::META);
    }
}
