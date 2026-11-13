<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Infrastructure\RequestMemo;
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
    public const DOWNLOADS = 'downloads';

    public const FEATURES = [self::QUESTION_BANK, self::EXPLANATIONS, self::EXAM, self::NAVIGATION, self::PRAXIS, self::VIDEO, self::RADIO, self::DEVICE, self::DOWNLOADS];

    /** Limit-Typen je Feature für `free_limited` (Kap. 7.2). */
    public const LIMIT_TYPES = [
        self::QUESTION_BANK => 'questions_per_module',
        self::EXAM => 'exam_papers',
        self::NAVIGATION => 'first_items',
        self::PRAXIS => 'first_items',
        self::VIDEO => 'first_items',
        self::RADIO => 'first_items',
        self::DOWNLOADS => 'first_items',
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

    /**
     * Zugriff für alle Features eines Kurses mit zwei Abfragen (statt drei je Feature) –
     * gleiche Regeln wie scope().
     * @return array<string,array{full:bool,limit:?int}>
     */
    public function scopes(int $userId, string $courseCode): array
    {
        if ($userId <= 0) {
            return array_fill_keys(self::FEATURES, ['full' => false, 'limit' => null]);
        }
        global $wpdb;
        $now = current_time('mysql', true);
        $free = [];
        $limits = [];
        $courseRules = $this->courseRules($courseCode);
        $rows = $courseRules !== null ? self::groupFreeRules($courseRules, $now) : $wpdb->get_results($wpdb->prepare(
            "SELECT feature_code, access_type, MAX(limit_value) AS limit_value FROM {$wpdb->prefix}bp_access_rule
             WHERE course_code = %s AND module_code IS NULL AND access_type IN ('free', 'free_limited')
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s) GROUP BY feature_code, access_type",
            $courseCode, $now, $now
        ));
        foreach ($rows as $r) {
            if ($r->access_type === 'free') {
                $free[(string) $r->feature_code] = true;
            } elseif ($r->limit_value !== null) {
                $limits[(string) $r->feature_code] = max(0, (int) $r->limit_value);
            }
        }
        $entitled = array_flip($this->entitledFeatures($userId, $courseCode, null));
        $default = Settings::get('default_access') === 'logged_in';
        $out = [];
        foreach (self::FEATURES as $feature) {
            $full = isset($free[$feature]) || isset($entitled[$feature]) || isset($entitled['all']) || $default;
            $full = (bool) apply_filters('bootspruefung_can_access', $full, $userId, $courseCode, $feature);
            $out[$feature] = ['full' => $full, 'limit' => $full ? null : ($limits[$feature] ?? null)];
        }

        return $out;
    }

    /** @return array<string,bool> Feature => erlaubt (voll oder Teilumfang) */
    public function features(int $userId, string $courseCode): array
    {
        return array_map(static fn ($s) => $s['full'] || ($s['limit'] ?? 0) > 0, $this->scopes($userId, $courseCode));
    }

    /** @return array<string,int> Feature => Limit, nur für Features im Teilumfang */
    public function limits(int $userId, string $courseCode): array
    {
        $out = [];
        foreach ($this->scopes($userId, $courseCode) as $feature => $scope) {
            if (! $scope['full'] && ($scope['limit'] ?? 0) > 0) {
                $out[$feature] = (int) $scope['limit'];
            }
        }

        return $out;
    }

    /**
     * Kaufstatus des Fragenkatalogs je Kurs (eine Abfrage je Nutzer) für Hinweise in Lernbereich,
     * App und Warenkorb: „Zahlung offen“, „Abgelaufen – verlängern“, „Gesperrt“. Rechte entscheiden
     * weiter scope()/scopes(); der kostenlose Teilumfang bleibt davon unberührt.
     *
     * @param callable(int):bool|null $orderOpen prüft, ob die Bestellung eines offenen Zugangs noch auf Zahlung
     *                                         wartet (z. B. WooCommerce-Status); sonst zählt der Zugang nicht als offen
     * @return array<string,array<string,mixed>> Kurscode => siehe stateFromRows()
     */
    public function purchaseStates(int $userId, ?string $now = null, ?callable $orderOpen = null): array
    {
        if ($userId <= 0) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT course_code, feature_code, status, valid_from, valid_until, order_id, product_id, sku_snapshot, source, meta
             FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d AND feature_code IN (%s, %s, 'all') ORDER BY id",
            $userId, self::QUESTION_BANK, self::VIDEO
        ));
        $by = [];
        $open = [];
        foreach ($rows as $row) {
            if ($row->status === 'pending' && $orderOpen !== null) {
                $id = (int) $row->order_id;
                if (! ($open[$id] ??= $id > 0 && $orderOpen($id))) {
                    continue;
                }
            }
            $by[(string) $row->course_code][] = $row;
        }
        $now ??= current_time('mysql', true);
        $out = [];
        foreach ($by as $code => $list) {
            $state = self::stateFromRows($list, $now);
            if ($state !== null) {
                $out[$code] = $state;
            }
        }

        return $out;
    }

    /**
     * Kaufstatus eines Kurses aus seinen Zugängen (ohne Datenbank).
     *
     * Reihenfolge: active (gültiger Zugang, Ende = spätestes Ende inkl. angeschlossener Verlängerung,
     * null = unbefristet) vor pending (Bestellung wartet auf Zahlung) vor suspended (gesperrt, z. B.
     * Zahlungsstreit/Rückbuchung, solange die Laufzeit nicht vorbei ist) vor expired (Laufzeit vorbei).
     * Erstattete/stornierte Zugänge (revoked) ergeben keinen Status. Offene Bestellungen stehen
     * zusätzlich in `pending_orders` (auch neben einem aktiven Zugang, z. B. Verlängerung per Überweisung).
     *
     * @param list<object> $rows Zugänge eines Kurses (feature_code question_bank, video_course oder all)
     * @return array{status:string,valid_until:?string,order_id:?int,product_id:?int,sku:?string,source:?string,duration_days:?int,pending_orders:list<int>,video:bool}|null
     */
    public static function stateFromRows(array $rows, string $now): ?array
    {
        $bank = array_values(array_filter($rows, static fn ($r) => in_array((string) $r->feature_code, [self::QUESTION_BANK, 'all'], true)));
        $isActive = static fn ($r): bool => $r->status === 'active' && ($r->valid_until === null || $r->valid_until >= $now);
        $current = array_filter($bank, static fn ($r) => $isActive($r) && ($r->valid_from === null || $r->valid_from <= $now));
        $pending = array_values(array_filter($bank, static fn ($r) => $r->status === 'pending'));
        $suspended = array_values(array_filter($bank, static fn ($r) => $r->status === 'suspended' && ($r->valid_until === null || $r->valid_until >= $now)));
        $ended = array_values(array_filter($bank, static fn ($r) => ($r->status === 'active' || $r->status === 'expired') && $r->valid_until !== null && $r->valid_until < $now));
        $pendingOrders = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) $r->order_id, $pending))));
        $latest = static function (array $list): object {
            usort($list, static fn ($a, $b) => strcmp((string) $b->valid_until, (string) $a->valid_until));

            return $list[0];
        };
        $days = static function (object $row): ?int {
            $meta = json_decode((string) ($row->meta ?? ''), true);
            $d = is_array($meta) && isset($meta['duration_days']) ? (int) $meta['duration_days'] : 0;

            return $d > 0 ? $d : null;
        };
        if ($current !== []) {
            $status = 'active';
            $valid = array_filter($bank, $isActive); // inkl. angeschlossener Verlängerung (beginnt später)
            $ends = array_map(static fn ($r) => $r->valid_until, $valid);
            $until = in_array(null, $ends, true) ? null : (string) max($ends);
            $ref = $until === null ? array_values($valid)[0] : $latest(array_values($valid));
        } elseif ($pending !== []) {
            $status = 'pending';
            $ref = $pending[count($pending) - 1]; // jüngste offene Bestellung
            $until = null;
        } elseif ($suspended !== []) {
            $status = 'suspended';
            $ref = $suspended[count($suspended) - 1];
            $until = $ref->valid_until;
        } elseif ($ended !== []) {
            $status = 'expired';
            $ref = $latest($ended);
            $until = (string) $ref->valid_until;
        } else {
            return null;
        }
        // Videokurs gehörte zum Zugang (Komplett-Stufe, „alle Funktionen“ oder Video-Upgrade), z. B. für „Verlängern“.
        $video = (string) $ref->feature_code === 'all' || array_filter($rows, static fn ($r) => (string) $r->feature_code === self::VIDEO
            && in_array((string) $r->status, ['active', 'expired', 'pending', 'suspended'], true)
            && (($ref->order_id !== null && (int) $r->order_id === (int) $ref->order_id) || ($r->valid_until !== null && $ref->valid_until !== null && $r->valid_until >= $ref->valid_until))) !== [];

        return [
            'status' => $status,
            'valid_until' => $until,
            'order_id' => $ref->order_id !== null ? (int) $ref->order_id : null,
            'product_id' => $ref->product_id !== null ? (int) $ref->product_id : null,
            'sku' => $ref->sku_snapshot !== null ? (string) $ref->sku_snapshot : null,
            'source' => isset($ref->source) ? (string) $ref->source : null,
            'duration_days' => $days($ref),
            'pending_orders' => $pendingOrders,
            'video' => $video,
        ];
    }

    /**
     * Freigabezeitpunkte aller Module/Kapitel eines Kurses mit einer Abfrage (plus Zugangsbeginn, falls nötig).
     * @return array<string,string> module_code => Zeitpunkt (UTC); fehlende = sofort frei
     */
    public function unlockTimes(int $userId, string $courseCode, string $feature): array
    {
        global $wpdb;
        $days = [];
        $courseRules = $this->courseRules($courseCode);
        $rows = $courseRules !== null ? self::groupDripRules($courseRules, $feature) : $wpdb->get_results($wpdb->prepare(
            "SELECT module_code, MAX(limit_value) AS days FROM {$wpdb->prefix}bp_access_rule WHERE course_code = %s AND feature_code = %s AND access_type = 'drip' AND module_code IS NOT NULL GROUP BY module_code",
            $courseCode, $feature
        ));
        foreach ($rows as $r) {
            if ((int) $r->days > 0) {
                $days[(string) $r->module_code] = (int) $r->days;
            }
        }
        if ($days === []) {
            return [];
        }
        $start = strtotime($this->accessStart($userId, $courseCode, $feature).' UTC');

        return array_map(static fn ($d) => gmdate('Y-m-d H:i:s', $start + $d * DAY_IN_SECONDS), $days);
    }

    public function unlockAt(int $userId, string $courseCode, string $feature, string $moduleCode): ?string
    {
        global $wpdb;
        $courseRules = $this->courseRules($courseCode);
        if ($courseRules !== null) {
            $grouped = self::groupDripRules($courseRules, $feature);
            $match = array_values(array_filter($grouped, static fn ($r) => (string) $r->module_code === $moduleCode));
            $days = $match === [] ? null : $match[0]->days;
        } else {
            $days = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(limit_value) FROM {$wpdb->prefix}bp_access_rule WHERE course_code = %s AND feature_code = %s AND module_code = %s AND access_type = 'drip'",
                $courseCode, $feature, $moduleCode
            ));
        }
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
        return RequestMemo::get('access_start:'.$userId.'|'.$courseCode.'|'.$feature, fn (): string => $this->computeAccessStart($userId, $courseCode, $feature));
    }

    private function computeAccessStart(int $userId, string $courseCode, string $feature): string
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
        $courseRules = $this->courseRules($courseCode);
        if ($courseRules !== null) {
            foreach (self::groupFreeRules($courseRules, $now) as $r) {
                if ($r->feature_code === $feature && $r->access_type === 'free') {
                    return true;
                }
            }

            return false;
        }

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
        $courseRules = $this->courseRules($courseCode);
        if ($courseRules !== null) {
            foreach (self::groupFreeRules($courseRules, $now) as $r) {
                if ($r->feature_code === $feature && $r->access_type === 'free_limited') {
                    return $r->limit_value === null ? null : max(0, (int) $r->limit_value);
                }
            }

            return null;
        }
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(limit_value) FROM {$wpdb->prefix}bp_access_rule WHERE course_code = %s AND feature_code = %s AND module_code IS NULL AND access_type = 'free_limited'
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s)",
            $courseCode, $feature, $now, $now
        ));

        return $value === null ? null : max(0, (int) $value);
    }

    private function entitled(int $userId, string $courseCode, string $feature): bool
    {
        return $this->entitledFeatures($userId, $courseCode, $feature) !== [];
    }

    /**
     * Aktive, gültige Features des Nutzers in einem Kurs (inkl. 'all'); mit $feature nur dieses + 'all'.
     * Upgrades gelten nur zusammen mit dem Basiskurs: Video-Upgrade mit der Theorie desselben Kurses,
     * Simulator-Upgrade aus dem Checkout mit einem Funkkurs. Endet/erstattet der Basiskurs, ruht das Upgrade.
     * @return list<string>
     */
    private function entitledFeatures(int $userId, string $courseCode, ?string $feature): array
    {
        if (RequestMemo::active()) {
            // Lesende REST-Anfrage: alle Kurse des Nutzers mit einer Abfrage (gleiche Bedingungen wie unten).
            $all = RequestMemo::get('entitled:'.$userId, fn (): array => $this->entitledByCourse($userId));
            $features = $all[$courseCode] ?? [];

            return $feature === null ? $features : array_values(array_intersect($features, [$feature, 'all']));
        }
        global $wpdb;
        $now = current_time('mysql', true);
        $t = $wpdb->prefix.'bp_entitlement';
        $sim = implode(',', array_map(static fn ($c) => "'".esc_sql($c)."'", \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_COURSES));
        $video = '%'.$wpdb->esc_like(\Bootspruefung\Learning\Commerce\ShopCatalog::VIDEO_SUFFIX);
        $featureSql = $feature === null ? '' : $wpdb->prepare(" AND e.feature_code IN (%s, 'all')", $feature);

        return array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT e.feature_code FROM {$t} e WHERE e.user_id = %d AND e.course_code = %s{$featureSql} AND e.status = 'active'
             AND (e.valid_from IS NULL OR e.valid_from <= %s) AND (e.valid_until IS NULL OR e.valid_until >= %s)
             AND (e.sku_snapshot IS NULL OR (e.sku_snapshot NOT LIKE %s AND e.sku_snapshot <> %s) OR EXISTS (
                SELECT 1 FROM {$t} th WHERE th.user_id = e.user_id AND th.feature_code IN (%s, 'all') AND th.status = 'active'
                AND (th.valid_from IS NULL OR th.valid_from <= %s) AND (th.valid_until IS NULL OR th.valid_until >= %s)
                AND (th.course_code = e.course_code OR (e.sku_snapshot = %s AND th.course_code IN ({$sim}))) AND (th.sku_snapshot IS NULL OR th.sku_snapshot NOT LIKE %s)))",
            $userId, $courseCode, $now, $now,
            $video, \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_UPGRADE,
            self::QUESTION_BANK, $now, $now, \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_UPGRADE, $video
        )));
    }

    /**
     * Aktive, gültige Features je Kurs (wie entitledFeatures() ohne Kursfilter).
     * @return array<string,list<string>>
     */
    private function entitledByCourse(int $userId): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $t = $wpdb->prefix.'bp_entitlement';
        $sim = implode(',', array_map(static fn ($c) => "'".esc_sql($c)."'", \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_COURSES));
        $video = '%'.$wpdb->esc_like(\Bootspruefung\Learning\Commerce\ShopCatalog::VIDEO_SUFFIX);
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT e.course_code, e.feature_code FROM {$t} e WHERE e.user_id = %d AND e.status = 'active'
             AND (e.valid_from IS NULL OR e.valid_from <= %s) AND (e.valid_until IS NULL OR e.valid_until >= %s)
             AND (e.sku_snapshot IS NULL OR (e.sku_snapshot NOT LIKE %s AND e.sku_snapshot <> %s) OR EXISTS (
                SELECT 1 FROM {$t} th WHERE th.user_id = e.user_id AND th.feature_code IN (%s, 'all') AND th.status = 'active'
                AND (th.valid_from IS NULL OR th.valid_from <= %s) AND (th.valid_until IS NULL OR th.valid_until >= %s)
                AND (th.course_code = e.course_code OR (e.sku_snapshot = %s AND th.course_code IN ({$sim}))) AND (th.sku_snapshot IS NULL OR th.sku_snapshot NOT LIKE %s)))",
            $userId, $now, $now,
            $video, \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_UPGRADE,
            self::QUESTION_BANK, $now, $now, \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_UPGRADE, $video
        )) as $row) {
            $out[(string) $row->course_code][] = (string) $row->feature_code;
        }

        return $out;
    }

    /**
     * Zugangsregeln eines Kurses aus dem Anfrage-Zwischenspeicher (alle Regeln mit einer Abfrage);
     * null außerhalb einer lesenden REST-Anfrage – dann fragen die Methoden wie bisher einzeln.
     * @return list<object>|null
     */
    private function courseRules(string $courseCode): ?array
    {
        if (! RequestMemo::active()) {
            return null;
        }
        $all = RequestMemo::get('access_rules', static function (): array {
            global $wpdb;
            $out = [];
            foreach ($wpdb->get_results("SELECT course_code, feature_code, module_code, access_type, limit_value, valid_from, valid_until FROM {$wpdb->prefix}bp_access_rule") as $r) {
                $out[(string) $r->course_code][] = $r;
            }

            return $out;
        });

        return $all[$courseCode] ?? [];
    }

    /**
     * Wie „SELECT feature_code, access_type, MAX(limit_value) … module_code IS NULL, free/free_limited, gültig … GROUP BY“.
     * @param list<object> $rules
     * @return list<object>
     */
    private static function groupFreeRules(array $rules, string $now): array
    {
        $groups = [];
        $limits = [];
        foreach ($rules as $r) {
            if ($r->module_code !== null || ! in_array($r->access_type, ['free', 'free_limited'], true)
                || ($r->valid_from !== null && $r->valid_from > $now) || ($r->valid_until !== null && $r->valid_until < $now)) {
                continue;
            }
            $key = $r->feature_code.'|'.$r->access_type;
            $groups[$key] ??= (object) ['feature_code' => (string) $r->feature_code, 'access_type' => (string) $r->access_type, 'limit_value' => null];
            if ($r->limit_value !== null) {
                $limits[$key] = max($limits[$key] ?? (int) $r->limit_value, (int) $r->limit_value);
                $groups[$key]->limit_value = $limits[$key];
            }
        }

        return array_values($groups);
    }

    /**
     * Wie „SELECT module_code, MAX(limit_value) AS days … drip, module_code IS NOT NULL … GROUP BY module_code“.
     * @param list<object> $rules
     * @return list<object>
     */
    private static function groupDripRules(array $rules, string $feature): array
    {
        $groups = [];
        $maxDays = [];
        foreach ($rules as $r) {
            if ($r->module_code === null || $r->access_type !== 'drip' || $r->feature_code !== $feature) {
                continue;
            }
            $key = (string) $r->module_code;
            $groups[$key] ??= (object) ['module_code' => $key, 'days' => null];
            if ($r->limit_value !== null) {
                $maxDays[$key] = max($maxDays[$key] ?? (int) $r->limit_value, (int) $r->limit_value);
                $groups[$key]->days = $maxDays[$key];
            }
        }

        return array_values($groups);
    }
}
