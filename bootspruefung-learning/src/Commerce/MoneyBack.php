<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Audit\AuditLog;

/**
 * Geld-zurück-Garantie: innerhalb von N Tagen nach Zahlung, solange weniger als X % der
 * gekauften Kurse gelernt sind (beantwortete Fragen + abgeschlossene Video-Lektionen seit
 * Zahlung). Kunde stellt die Anfrage unter „Mein Lernen“, der Betreiber erstattet in
 * WooCommerce; die Erstattung entzieht den Zugang über die normale Erstattungsregel.
 *
 * Gilt zusätzlich zum gesetzlichen Widerrufsrecht und schränkt es nicht ein.
 */
final class MoneyBack
{
    public const META = '_bp_money_back';
    public const REQUEST_META = '_bp_money_back_request';

    /** @return array{days:int,max_progress:float}|null */
    public static function policy(int $productId): ?array
    {
        $raw = get_post_meta($productId, self::META, true);
        if (! is_array($raw) || (int) ($raw['days'] ?? 0) < 1) {
            return null;
        }

        return ['days' => (int) $raw['days'], 'max_progress' => max(0.0, min(100.0, (float) ($raw['max_progress'] ?? 0)))];
    }

    public static function setPolicy(int $productId, ?int $days, ?float $maxProgress): void
    {
        if ($days === null || $days < 1) {
            delete_post_meta($productId, self::META);

            return;
        }
        update_post_meta($productId, self::META, ['days' => $days, 'max_progress' => max(0.0, min(100.0, (float) $maxProgress))]);
    }

    /**
     * Lernstand in Prozent über alle Fragen und Video-Lektionen der Kurse (Vereinigung),
     * gezählt ab $since (UTC, Y-m-d H:i:s).
     *
     * @param list<string> $courses
     * @return array{percent:float,exact:float,done:int,total:int}
     */
    public static function progress(int $userId, array $courses, string $since, bool $questions = true, bool $lessons = true): array
    {
        global $wpdb;
        $p = $wpdb->prefix.'bp_';
        $courses = array_values(array_unique(array_filter($courses)));
        if ($courses === []) {
            return ['percent' => 0.0, 'done' => 0, 'total' => 0];
        }
        $in = implode(',', array_fill(0, count($courses), '%s'));
        $withQuestions = $questions;
        $withLessons = $lessons;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter dynamisch
        // Nenner und Zähler nutzen dieselben Mengen: ohne archivierte Inhalte und ohne Platzhalter-Lektionen (noch kein Video).
        $questions = "SELECT DISTINCT mq.question_id FROM {$p}course c JOIN {$p}course_module cm ON cm.course_id = c.id JOIN {$p}module_question mq ON mq.module_id = cm.module_id
            JOIN {$p}question q ON q.id = mq.question_id WHERE c.code IN ({$in}) AND q.status = 'published'";
        $lessons = "SELECT DISTINCT vl.id FROM {$p}course c JOIN {$p}video_module vm ON vm.course_id = c.id JOIN {$p}video_lesson vl ON vl.module_id = vm.id
            WHERE c.code IN ({$in}) AND vl.is_placeholder = 0 AND vl.status <> 'source_archived' AND vm.status <> 'source_archived'";
        $qTotal = ! $withQuestions ? 0 : (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ({$questions}) q", ...$courses));
        $lTotal = ! $withLessons ? 0 : (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ({$lessons}) l", ...$courses));
        $qDone = ! $withQuestions ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}progress pr WHERE pr.user_id = %d AND pr.attempts > 0 AND pr.last_answered_at >= %s AND pr.question_id IN ({$questions})",
            $userId, $since, ...$courses
        ));
        $lDone = ! $withLessons ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}video_progress vp WHERE vp.user_id = %d AND vp.completed = 1 AND vp.last_access_at >= %s AND vp.lesson_id IN ({$lessons})",
            $userId, $since, ...$courses
        ));
        // phpcs:enable
        $total = $qTotal + $lTotal;
        $done = $qDone + $lDone;

        // percent: gerundet für die Anzeige; exact: ungerundet für den Vergleich mit der Grenze („weniger als 20 %“).
        $exact = $total > 0 ? $done * 100 / $total : 0.0;

        return ['percent' => round($exact, 1), 'exact' => $exact, 'done' => $done, 'total' => $total];
    }

    /** Grenze erreicht? Vergleich mit dem ungerundeten Wert: 19,95 % ist „weniger als 20 %“. @param array{percent:float,exact?:float} $measured */
    public static function limitReached(array $measured, float $max): bool
    {
        return (float) ($measured['exact'] ?? $measured['percent']) >= $max;
    }

    /**
     * Garantie-Status einer Bestellung.
     *
     * @param array{id:int,user_id:int,status:string,date_paid:?string,refunded:bool,items:list<array{product_id:int,variation_id:int}>} $order
     * @return array{applies:bool,eligible:bool,deadline:?string,progress:float,max_progress:float,requested:?array<string,mixed>,reason:string}
     */
    public static function status(array $order, ?array $requested = null, ?string $now = null): array
    {
        $now ??= EntitlementService::now();
        $repo = new ProductAccessRepository($GLOBALS['wpdb']);
        $policy = null;
        $courses = [];
        $features = [];
        $open = 0; // Garantie-Positionen, die nicht (vollständig) erstattet sind
        $guaranteed = false;
        foreach ($order['items'] as $item) {
            $rows = $repo->forItem($item['product_id'], $item['variation_id']);
            $own = self::policy($item['product_id']);
            if ($own === null || $rows === []) {
                continue;
            }
            $guaranteed = true;
            // Teilerstattung: eine erstattete Position (z. B. nur das Video-Upgrade) beendet nicht die Garantie der übrigen.
            if (! empty($item['refunded'])) {
                continue;
            }
            $open++;
            // Mehrere Produkte: die strengste Regel gilt (kürzeste Frist, niedrigste Grenze).
            $policy = $policy === null ? $own : ['days' => min($policy['days'], $own['days']), 'max_progress' => min($policy['max_progress'], $own['max_progress'])];
            foreach ($rows as $row) {
                $courses[] = (string) $row->course_code;
                $features[(string) $row->feature_code] = true;
            }
        }
        // Gezählt wird nur, was gekauft wurde: Video-Upgrade = Lektionen, Theorie = Fragen.
        $all = isset($features[ProductAccessRepository::FEATURE_ALL]);
        $countQuestions = $all || array_intersect_key($features, array_flip([\Bootspruefung\Learning\Learning\Services\AccessService::QUESTION_BANK, \Bootspruefung\Learning\Learning\Services\AccessService::EXAM])) !== [];
        $countLessons = $all || isset($features[\Bootspruefung\Learning\Learning\Services\AccessService::VIDEO]);
        $refunded = $order['refunded'] && ($open === 0 || ! array_key_exists('refunded', $order['items'][0] ?? []));
        if ($policy === null && $guaranteed) {
            return ['applies' => true, 'eligible' => false, 'deadline' => null, 'progress' => 0.0, 'max_progress' => 0.0, 'requested' => $requested, 'reason' => 'refunded'];
        }
        $base = ['applies' => $policy !== null, 'eligible' => false, 'deadline' => null, 'progress' => 0.0, 'max_progress' => $policy['max_progress'] ?? 0.0, 'requested' => $requested, 'reason' => ''];
        if ($policy === null) {
            return ['reason' => 'no_policy'] + $base;
        }
        if ($order['date_paid'] === null || ! in_array($order['status'], ['processing', 'completed'], true)) {
            return ['reason' => $refunded || $order['status'] === 'refunded' ? 'refunded' : 'not_paid'] + $base;
        }
        $deadline = gmdate('Y-m-d H:i:s', strtotime($order['date_paid'].' UTC') + $policy['days'] * DAY_IN_SECONDS);
        $measured = self::progress($order['user_id'], $courses, $order['date_paid'], $countQuestions, $countLessons || ! $countQuestions);
        $progress = $measured['percent'];
        $base = ['deadline' => $deadline, 'progress' => $progress] + $base;
        if ($refunded) {
            return ['reason' => 'refunded'] + $base;
        }
        if ($requested !== null) {
            return ['reason' => 'requested'] + $base;
        }
        if (strtotime($now.' UTC') > strtotime($deadline.' UTC')) {
            return ['reason' => 'expired'] + $base;
        }
        if (self::limitReached($measured, (float) $policy['max_progress'])) {
            return ['reason' => 'too_much_progress'] + $base;
        }

        return ['eligible' => true, 'reason' => 'eligible'] + $base;
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'eligible' => __('Geld zurück möglich', 'bootspruefung-learning'),
            'requested' => __('Erstattung angefragt – wird bearbeitet', 'bootspruefung-learning'),
            'expired' => __('Frist abgelaufen', 'bootspruefung-learning'),
            'too_much_progress' => __('Lernstand über der Grenze', 'bootspruefung-learning'),
            'refunded' => __('Erstattet', 'bootspruefung-learning'),
            'not_paid' => __('Noch nicht bezahlt', 'bootspruefung-learning'),
            default => __('Keine Garantie für diese Bestellung', 'bootspruefung-learning'),
        };
    }

    /** Anfrage protokollieren (Prüfung der Berechtigung durch den Aufrufer). */
    public static function recordRequest(int $orderId, int $userId, array $status): array
    {
        $request = ['at' => EntitlementService::now(), 'user_id' => $userId, 'progress' => $status['progress'], 'deadline' => $status['deadline']];
        AuditLog::record('money_back_requested', 'order', (string) $orderId, null, $request);

        return $request;
    }
}
