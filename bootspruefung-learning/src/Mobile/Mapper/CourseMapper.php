<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Mapper;

use Bootspruefung\Learning\Commerce\CourseRelations;
use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\ExpiryReminder;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\LearningService;

/**
 * Kurse fürs App-Format: Zugangsstatus (aus AccessService und den Zugängen), Fortschritt
 * (LearningService), Fähigkeiten (was der Kurs enthält) und passendstes Shop-Angebot
 * (Integration::offers wie im Web-Lernbereich). Rechte entscheidet ausschließlich AccessService.
 */
final class CourseMapper
{
    /** Fähigkeit (App) → Feature (AccessService). */
    public const CAPABILITIES = [
        'smarttrainer' => AccessService::QUESTION_BANK,
        'exam' => AccessService::EXAM,
        'video' => AccessService::VIDEO,
        'navigation' => AccessService::NAVIGATION,
        'praxis' => AccessService::PRAXIS,
        'funk' => AccessService::RADIO,
    ];

    /** Weitere Features (ohne eigene Fähigkeit) → Schlüssel in der App. */
    private const EXTRA_KEYS = [
        AccessService::EXPLANATIONS => 'explanations',
        AccessService::DEVICE => 'funk_device',
        AccessService::DOWNLOADS => 'downloads',
    ];

    /** @var array<int,list<string>>|null course_id => Fähigkeiten */
    private ?array $capabilities = null;
    /** @var array<int,array<string,array{full:bool,limit:?int}>> */
    private array $scopes = [];
    /** @var array<int,list<object>> user_id => Zugänge */
    private array $entitlements = [];
    /** @var array<int,array<string,array{hidden:bool,recommended_for:list<string>}>> user_id => Kurscode => Beziehung */
    private array $relations = [];
    /** @var array<int,array<string,string>> user_id => Kurscode => Ende des Fragenkatalog-Zugangs (bald) */
    private array $expiring = [];
    /** @var array<string,object>|null Kurscode => veröffentlichter Kurs */
    private ?array $coursesByCode = null;

    public function __construct(
        private readonly AccessService $access,
        private readonly LearningService $learning,
    ) {
    }

    /** @return array<string,array{full:bool,limit:?int}> */
    public function scopes(int $userId, object $course): array
    {
        return $this->scopes[(int) $course->id] ??= $this->access->scopes($userId, (string) $course->code);
    }

    /** Was der Kurs enthält (unabhängig vom Zugang), mit wenigen Abfragen für alle Kurse. @return list<string> */
    public function capabilities(object $course): array
    {
        if ($this->capabilities === null) {
            global $wpdb;
            $t = static fn (string $name): string => $wpdb->prefix.'bp_'.$name;
            $caps = [];
            $add = static function (array $ids, string $cap) use (&$caps): void {
                foreach ($ids as $id) {
                    $caps[(int) $id][$cap] = true;
                }
            };
            $add($wpdb->get_col("SELECT DISTINCT cm.course_id FROM {$t('course_module')} cm JOIN {$t('module_question')} mq ON mq.module_id = cm.module_id"), 'smarttrainer');
            $add($wpdb->get_col("SELECT DISTINCT course_id FROM {$t('exam_rule')} WHERE status <> 'source_archived'"), 'exam');
            $add($wpdb->get_col("SELECT DISTINCT m.course_id FROM {$t('video_module')} m JOIN {$t('video_lesson')} l ON l.module_id = m.id AND l.status <> 'source_archived' WHERE m.status = 'published'"), 'video');
            $add($wpdb->get_col("SELECT DISTINCT tc.course_id FROM {$t('navigation_task_course')} tc JOIN {$t('navigation_task')} nt ON nt.id = tc.task_id WHERE nt.status = 'published'"), 'navigation');
            $add($wpdb->get_col("SELECT DISTINCT course_id FROM {$t('praxis_task')} WHERE status = 'published' AND active = 1"), 'praxis');
            $codes = [];
            foreach (['radio_text', 'radio_vocabulary', 'radio_scenario'] as $table) {
                foreach ($wpdb->get_col("SELECT DISTINCT course_codes FROM {$t($table)} WHERE status = 'published' AND course_codes IS NOT NULL") as $list) {
                    foreach (explode(',', (string) $list) as $code) {
                        $codes[trim($code)] = true;
                    }
                }
            }
            if ($codes !== []) {
                $add($wpdb->get_col("SELECT id FROM {$t('course')} WHERE code IN ('".implode("','", array_map('esc_sql', array_keys($codes)))."')"), 'funk');
            }
            $this->capabilities = [];
            foreach ($caps as $id => $set) {
                // Reihenfolge wie CAPABILITIES, Favoriten gehören zum Smarttrainer.
                $list = array_values(array_filter(array_keys(self::CAPABILITIES), static fn ($c) => isset($set[$c])));
                if (isset($set['smarttrainer'])) {
                    array_splice($list, 1, 0, ['favorites']);
                }
                $this->capabilities[$id] = $list;
            }
        }

        return $this->capabilities[(int) $course->id] ?? [];
    }

    /** @return list<object> */
    private function entitlementRows(int $userId): array
    {
        if (! isset($this->entitlements[$userId])) {
            global $wpdb;
            $this->entitlements[$userId] = $wpdb->get_results($wpdb->prepare("SELECT course_code, feature_code, status, valid_from, valid_until FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d", $userId));
        }

        return $this->entitlements[$userId];
    }

    /**
     * Zugangsstatus des Kurses: active (voller Zugang zu mindestens einem Bereich), limited
     * (nur kostenloser Teilumfang), sonst aus den Zugängen pending/suspended/expired/revoked
     * oder not_granted. Laufzeit aus den gültigen Zugängen (null = unbegrenzt bzw. ohne Kauf).
     * @return array<string,mixed>
     */
    public function access(int $userId, object $course): array
    {
        $scopes = $this->scopes($userId, $course);
        $caps = $this->capabilities($course);
        $features = array_values(array_filter(array_map(static fn ($c) => self::CAPABILITIES[$c] ?? null, $caps)));
        if ($features === []) {
            $features = [AccessService::QUESTION_BANK];
        }
        $full = false;
        $limited = false;
        $limits = [];
        foreach ($features as $feature) {
            $s = $scopes[$feature] ?? ['full' => false, 'limit' => null];
            $full = $full || $s['full'];
            if (! $s['full'] && (int) ($s['limit'] ?? 0) > 0) {
                $limited = true;
                $limits[(string) array_search($feature, self::CAPABILITIES, true)] = (int) $s['limit'];
            }
        }
        $now = current_time('mysql', true);
        $rows = array_filter($this->entitlementRows($userId), static fn ($r) => $r->course_code === $course->code);
        $valid = array_filter($rows, static fn ($r) => $r->status === EntitlementPlanner::ACTIVE && ($r->valid_from === null || $r->valid_from <= $now) && ($r->valid_until === null || $r->valid_until >= $now));
        $statuses = array_map(static fn ($r) => $r->status === EntitlementPlanner::ACTIVE && $r->valid_until !== null && $r->valid_until < $now ? EntitlementPlanner::EXPIRED : (string) $r->status, $rows);

        if ($full) {
            $status = 'active';
        } elseif ($limited) {
            $status = 'limited';
        } else {
            $status = 'not_granted';
            foreach ([EntitlementPlanner::PENDING => 'pending', EntitlementPlanner::SUSPENDED => 'suspended', EntitlementPlanner::EXPIRED => 'expired', EntitlementPlanner::REVOKED => 'revoked'] as $s => $label) {
                if (in_array($s, $statuses, true)) {
                    $status = $label;
                    break;
                }
            }
        }
        $until = null;
        $from = null;
        if ($valid !== []) {
            $ends = array_map(static fn ($r) => $r->valid_until, $valid);
            $until = in_array(null, $ends, true) ? null : max($ends);
            $starts = array_filter(array_map(static fn ($r) => $r->valid_from, $valid));
            $from = $starts === [] ? null : min($starts);
        } elseif (in_array($status, ['expired', 'suspended', 'revoked'], true)) {
            $ends = array_filter(array_map(static fn ($r) => $r->valid_until, $rows));
            $until = $ends === [] ? null : max($ends);
        }

        // Bald endender Zugang zum Fragenkatalog ohne Verlängerung (wie Banner im Web und Erinnerungsmail).
        $expiresAt = ($scopes[AccessService::QUESTION_BANK]['full'] ?? false) ? ($this->expiringFor($userId)[(string) $course->code] ?? null) : null;

        return [
            'status' => $status,
            'valid_from' => self::iso($from),
            'valid_until' => self::iso($until),
            'trial' => $limited && ! $full,
            'purchased' => $valid !== [],
            'limits' => (object) $limits,
            'expires_soon' => $expiresAt !== null,
            'expires_at' => self::iso($expiresAt),
            'renew_url' => $expiresAt !== null ? ExpiryReminder::renewUrl((string) $course->code) : null,
        ];
    }

    /** @return array<string,string> Kurscode => Ende (eine Abfrage je Nutzer) */
    private function expiringFor(int $userId): array
    {
        return $this->expiring[$userId] ??= (ExpiryReminder::expiring($userId)[$userId] ?? []);
    }

    /** @return array<string,object> */
    private function coursesByCode(): array
    {
        if ($this->coursesByCode === null) {
            $this->coursesByCode = [];
            foreach ((new ContentRepository())->courses() as $c) {
                $this->coursesByCode[(string) $c->code] = $c;
            }
        }

        return $this->coursesByCode;
    }

    /**
     * Empfehlung/Ausblenden (CourseRelations, eine Abfrage je Nutzer). Kurse mit vollem Fragenkatalog
     * sind nie ausgeblendet oder empfohlen, Testkurse (kostenloser Teilumfang) nie ausgeblendet. Ausgeblendete Kurse bleiben in der Liste (Deep-Links).
     * @return array{hidden:bool,recommended:bool,recommended_for:list<array{id:string,name:string}>}
     */
    public function relation(int $userId, object $course): array
    {
        $this->relations[$userId] ??= CourseRelations::forUser($userId, array_keys($this->coursesByCode()));
        $rel = $this->relations[$userId][(string) $course->code] ?? ['hidden' => false, 'recommended_for' => []];
        $bank = $this->scopes($userId, $course)[AccessService::QUESTION_BANK] ?? ['full' => false, 'limit' => null];
        if ($bank['full'] ?? false) {
            $rel = ['hidden' => false, 'recommended_for' => []];
        } elseif ((int) ($bank['limit'] ?? 0) > 0) {
            $rel['hidden'] = false; // Testkurse (kostenloser Teilumfang) bleiben sichtbar (Festlegung 10.11.2026).
        }
        $by = $this->coursesByCode();
        $for = [];
        foreach ($rel['recommended_for'] as $code) {
            if (isset($by[$code])) {
                $for[] = ['id' => (string) $by[$code]->id, 'name' => (string) $by[$code]->name];
            }
        }

        return ['hidden' => $rel['hidden'], 'recommended' => $for !== [], 'recommended_for' => $for];
    }

    /** @return array<string,array<string,mixed>> Fähigkeit => Zugriff */
    public function features(int $userId, object $course): array
    {
        $scopes = $this->scopes($userId, $course);
        $out = [];
        foreach ($this->capabilities($course) as $cap) {
            $feature = self::CAPABILITIES[$cap] ?? null;
            if ($feature === null) {
                continue;
            }
            $s = $scopes[$feature] ?? ['full' => false, 'limit' => null];
            $out[$cap] = ['available' => $s['full'] || (int) ($s['limit'] ?? 0) > 0, 'full' => $s['full'], 'limit' => $s['full'] ? null : ((int) ($s['limit'] ?? 0) ?: null)];
        }
        $e = $scopes[AccessService::EXPLANATIONS] ?? ['full' => false, 'limit' => null];
        $out['explanations'] = ['available' => $e['full'] || (int) ($e['limit'] ?? 0) > 0, 'full' => $e['full'], 'limit' => null];

        return $out;
    }

    /** Passendstes Shop-Angebot für den Hauptbereich des Kurses (nur wenn kein Vollzugang besteht). */
    public function webshopUrl(int $userId, object $course, array $access): ?string
    {
        if ($access['status'] === 'active' && ! $access['trial']) {
            return null;
        }
        $caps = $this->capabilities($course);
        $feature = self::CAPABILITIES[$caps[0] ?? 'smarttrainer'] ?? AccessService::QUESTION_BANK;
        $offers = Integration::offers((string) $course->code, [$feature => false]);

        return $offers[$feature][0]['url'] ?? null;
    }

    /** Kaufangebote je gesperrtem Bereich wie im Web (Theorie-Käufern zuerst das Video-Upgrade). @return array<string,list<array<string,string>>> */
    public function offers(int $userId, object $course): array
    {
        $scopes = $this->scopes($userId, $course);
        $shop = Integration::offers((string) $course->code, array_map(static fn ($s) => (bool) $s['full'], $scopes));
        if (isset($shop[AccessService::VIDEO]) && ($upgrade = VideoUpgrade::offerFor($userId, (string) $course->code)) !== null) {
            array_unshift($shop[AccessService::VIDEO], $upgrade);
        }
        $out = [];
        foreach ($shop as $feature => $list) {
            $cap = array_search($feature, self::CAPABILITIES, true);
            $key = $cap !== false ? (string) $cap : (self::EXTRA_KEYS[$feature] ?? (string) $feature);
            $out[$key] = array_map(static fn ($o) => ['name' => (string) $o['name'], 'url' => (string) $o['url'], 'price' => (string) ($o['price'] ?? '')], $list);
        }

        return $out;
    }

    /**
     * Seit 1.11.0 zusätzlich die Stufe „sicher heute“ (secure, secure_percent) und fällige Wiederholungen (due).
     * Abwärtskompatibel: learning zählt „sicher heute“ weiterhin mit (new + unsure + learning + mastered = total).
     * @return array<string,int>
     */
    public static function progressSummary(?array $summary): array
    {
        return [
            'percent' => (int) ($summary['percent'] ?? 0),
            'completed' => (int) ($summary['mastered'] ?? 0),
            'total' => (int) ($summary['questions'] ?? 0),
            'new' => (int) ($summary['new'] ?? 0),
            'unsure' => (int) ($summary['unsure'] ?? 0),
            'learning' => (int) ($summary['learning'] ?? 0) + (int) ($summary['secure'] ?? 0),
            'mastered' => (int) ($summary['mastered'] ?? 0),
            'secure' => (int) ($summary['secure'] ?? 0),
            'secure_percent' => (int) ($summary['secure_percent'] ?? 0),
            'due' => (int) ($summary['due'] ?? 0),
        ];
    }

    /** @return array<string,mixed> CourseSummary */
    public function summary(int $userId, object $course): array
    {
        $access = $this->access($userId, $course);
        $scopes = $this->scopes($userId, $course);
        $bank = $scopes[AccessService::QUESTION_BANK] ?? ['full' => false, 'limit' => null];
        $canBank = $bank['full'] || (int) ($bank['limit'] ?? 0) > 0;

        return [
            'id' => (string) $course->id,
            'code' => (string) $course->code,
            'slug' => strtolower((string) $course->code),
            'name' => (string) $course->name,
            'description' => self::text($course->description ?? null),
            'course_type' => (string) $course->course_type,
            'access' => $access,
            'progress' => self::progressSummary($canBank ? $this->learning->courseTotal($userId, $course) : null),
            'capabilities' => $this->capabilities($course),
            'features' => $this->features($userId, $course),
            'webshop_url' => $this->webshopUrl($userId, $course, $access),
            'relation' => $this->relation($userId, $course),
            // Kunden-FAQ (seit 1.10.0): Anzahl für Kunden des Kurses (voller Fragenkatalog), sonst 0.
            'faq_count' => $bank['full'] ? \Bootspruefung\Learning\Faq\CustomerFaqRepository::count((string) $course->code) : 0,
        ];
    }

    public static function text(mixed $html): ?string
    {
        $text = trim(html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    public static function iso(?string $mysql): ?string
    {
        return $mysql === null || $mysql === '' ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($mysql.' UTC'));
    }
}
