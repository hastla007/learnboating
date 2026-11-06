<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;

/** Lesbare Bezeichnungen für Features, Kurse und Zugangsstatus. */
final class Labels
{
    /** @var array<string,string>|null */
    private static ?array $courses = null;

    public static function feature(string $code): string
    {
        $d = 'bootspruefung-learning';
        $labels = [
            'all' => __('Alle Funktionen', $d),
            'question_bank' => __('Fragen lernen', $d),
            'learning_explanations' => __('Erklärungen', $d),
            'exam_simulation' => __('Prüfungssimulation', $d),
            'navigation_trainer' => __('Navigationstrainer', $d),
            'praxis_trainer' => __('Praxistrainer', $d),
            'video_course' => __('Videokurs', $d),
            'radio_training' => __('Funktraining', $d),
            'device_simulation' => __('Gerätesimulation', $d),
        ];

        return $labels[$code] ?? $code;
    }

    public static function status(string $status): string
    {
        $d = 'bootspruefung-learning';
        $labels = [
            'active' => __('aktiv', $d), 'pending' => __('wartet auf Zahlung', $d), 'suspended' => __('ausgesetzt', $d),
            'revoked' => __('widerrufen', $d), 'expired' => __('abgelaufen', $d),
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

    public static function date(?string $mysqlGmt): string
    {
        if ($mysqlGmt === null || $mysqlGmt === '') {
            return __('dauerhaft', 'bootspruefung-learning');
        }

        return wp_date(get_option('date_format'), strtotime($mysqlGmt.' UTC'));
    }
}
