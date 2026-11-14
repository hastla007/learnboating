<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;

/** Lesbare Bezeichnungen für Features, Kurse und Zugangsstatus. */
final class Labels
{
    /** @var array<string,string>|null */
    private static ?array $courses = null;

    public static function feature(string $code): string
    {
        $labels = [
            'all' => __('Alle Funktionen', 'bootspruefung-learning'),
            'question_bank' => __('Fragen lernen', 'bootspruefung-learning'),
            'learning_explanations' => __('Erklärungen', 'bootspruefung-learning'),
            'exam_simulation' => __('Prüfungssimulation', 'bootspruefung-learning'),
            'navigation_trainer' => __('Navigationstrainer', 'bootspruefung-learning'),
            'praxis_trainer' => __('Praxistrainer', 'bootspruefung-learning'),
            'video_course' => __('Videokurs', 'bootspruefung-learning'),
            'radio_training' => __('Funktraining', 'bootspruefung-learning'),
            'device_simulation' => __('Gerätesimulation', 'bootspruefung-learning'),
            'downloads' => __('Downloads (PDF)', 'bootspruefung-learning'),
        ];

        return $labels[$code] ?? $code;
    }

    public static function status(string $status): string
    {
        $labels = [
            'active' => __('aktiv', 'bootspruefung-learning'), 'pending' => __('wartet auf Zahlung', 'bootspruefung-learning'), 'suspended' => __('ausgesetzt', 'bootspruefung-learning'),
            'revoked' => __('beendet', 'bootspruefung-learning'), 'expired' => __('abgelaufen', 'bootspruefung-learning'),
        ];

        return $labels[$status] ?? $status;
    }

    public static function course(string $code): string
    {
        return self::courses()[$code] ?? $code;
    }

    /** @return array<string,string> Code => Name der veröffentlichten Kurse */
    public static function courses(): array
    {
        if (self::$courses === null) {
            self::$courses = [];
            foreach ((new ContentRepository())->courses() as $course) {
                self::$courses[$course->code] = $course->name;
            }
        }

        return self::$courses;
    }

    /** Laufzeit wie in den Produkttexten (ShopCatalog): „12 Monate“, „6 Monate“, „3 Monate“, sonst Tage. */
    public static function duration(int $days): string
    {

        return match (true) {
            $days >= 360 => __('12 Monate', 'bootspruefung-learning'),
            $days >= 180 => __('6 Monate', 'bootspruefung-learning'),
            $days >= 90 => __('3 Monate', 'bootspruefung-learning'),
            /* translators: %d: number of days */
            default => sprintf(_n('%d Tag', '%d Tage', $days, 'bootspruefung-learning'), $days),
        };
    }

    /**
     * „Gültig bis“ in „Mein Lernen“: offene Zahlung = Laufzeit ab Zahlungseingang (das Ende steht erst dann fest),
     * sonst Datum bzw. „dauerhaft“.
     */
    public static function validity(string $status, ?string $mysqlGmt, ?int $durationDays): string
    {
        if ($status === EntitlementPlanner::PENDING) {
            return $durationDays !== null && $durationDays > 0
                /* translators: %s: duration, e.g. "12 Monate" */
                ? sprintf(__('%s ab Zahlungseingang', 'bootspruefung-learning'), self::duration($durationDays))
                : __('dauerhaft ab Zahlungseingang', 'bootspruefung-learning');
        }

        return self::date($mysqlGmt);
    }

    public static function date(?string $mysqlGmt): string
    {
        if ($mysqlGmt === null || $mysqlGmt === '') {
            return __('dauerhaft', 'bootspruefung-learning');
        }

        return wp_date(get_option('date_format'), strtotime($mysqlGmt.' UTC'));
    }
}
