<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Gerätesimulation (Schritt 8b) wie App\Support\Geraetesimulation und
 * GeraetesimulationController der Quelle. Die Bedienung (Zustandsmaschine,
 * Gerätezeichnungen, Lektionen, Prüfung, freies Üben) läuft im unverändert
 * übernommenen JavaScript der Quelle; der Server liefert die Konfiguration,
 * bewertet Funksprüche gegen die Prüfpunkte und speichert Stufen (1 = mit
 * Hilfe, 2 = nur Hinweis, 3 = allein) und Prüfungsergebnisse.
 */
final class DeviceSimService
{
    public const DEFAULT_DEVICE = 'ic-m505';

    /**
     * Kostenlose Testversion (Festlegung 06.11.2026): Gerät bedienen, Kanäle, Sendeleistung,
     * MMSI/Position und ein Beispiel-Routineanruf von Schiff zu Schiff (UBI: Begegnung absprechen).
     * Gesperrt: Not-, Dringlichkeits- und Sicherheitsverkehr, weitere Szenarien, Prüfungsmodus, Freies Üben.
     */
    public const FREE_LESSONS = ['geraet-bedienen', 'hoeren-senden', 'kanaele-anrufkanal', 'sendeleistung', 'mmsi-position-zeit', 'einzelanruf', 'binnen-atis', 'binnen-verkehrskreise', 'binnen-begegnung'];

    private \wpdb $db;

    /** @var array<string,mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly AccessService $access, private readonly RadioPracticeService $practice)
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** @return array<string,mixed> */
    private function data(): array
    {
        if ($this->data === null) {
            $json = $this->db->get_var("SELECT data FROM {$this->t('device')} WHERE source_id = 'geraetesimulation' AND status = 'published'");
            $this->data = $json ? (json_decode((string) $json, true) ?: []) : [];
        }

        return $this->data;
    }

    /** Simulator für diesen Kurs vorhanden (Funkkurs) – mindestens als Testversion. */
    public function available(int $userId, object $course): bool
    {
        return $this->data() !== [] && $this->catalogs($course) !== [] && $userId > 0;
    }

    /** Vollversion freigeschaltet (Kurs mit Simulator, Simulator-Pass, Bundle, Pass). */
    public function premium(int $userId, object $course): bool
    {
        return $this->available($userId, $course) && $this->access->full($userId, $course->code, AccessService::DEVICE); // kein Teilumfang
    }

    /** @return list<string> */
    public function catalogs(object $course): array
    {
        return $this->practice->catalogsFor($course);
    }

    /** Konfiguration für das Geräte-JavaScript (Format wie GeraetesimulationController::index). @return array<string,mixed> */
    public function config(int $userId, object $course): array
    {
        $this->require($userId, $course);
        $data = $this->data();
        $catalogs = $this->catalogs($course);
        $devices = $this->devicesFor($catalogs, true);
        $keys = array_column($devices, 'key');
        $rows = $this->db->get_results($this->db->prepare("SELECT item_source_id, data FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_type = 'geraet'", $userId));
        $progress = array_fill_keys($keys, []);
        $best = [];
        foreach ($rows as $row) {
            [$device, $item] = array_pad(explode(':', $row->item_source_id, 2), 2, '');
            $value = json_decode((string) $row->data, true) ?: [];
            if (! isset($progress[$device])) {
                continue;
            }
            if (str_starts_with($item, 'pruefung-')) {
                $best[substr($item, 9)][$device] = $value['best'] ?? null;
            } else {
                $progress[$device][$item] = (int) ($value['level'] ?? 0);
            }
        }
        $premium = $this->premium($userId, $course);
        $exams = [];
        foreach ($premium ? $this->exams($catalogs) : [] as $series => $exam) {
            $exams[$series] = $exam + ['best' => array_merge(array_fill_keys($keys, null), $best[$series] ?? [])];
        }
        $lessons = $this->lessonsForBrowser($catalogs);
        $nonce = wp_create_nonce('wp_rest');
        $url = static fn (string $path) => add_query_arg('_wpnonce', $nonce, rest_url(RestRegistrar::NAMESPACE.'/courses/'.rawurlencode($course->code).'/device-sim/'.$path));

        return [
            'devices' => $devices,
            'ship' => $data['ship'],
            'addressBook' => $data['address_book'],
            'directory' => $data['directory'],
            'catalogs' => $catalogs,
            // Testversion: nur die freien Lektionen werden ausgeliefert; der Rest nur als Titel für die Übersicht.
            'lessons' => $premium ? $lessons : array_values(array_filter($lessons, static fn ($l) => in_array($l['key'], self::FREE_LESSONS, true))),
            // Leere Objekte als {} (nicht []) ausliefern, wie die Quelle.
            'progress' => array_map(static fn ($p) => (object) $p, $progress),
            'exams' => (object) $exams,
            'speechLang' => 'en-GB',
            'urls' => ['evaluate' => $url('evaluate'), 'progress' => $url('progress'), 'exam' => $url('exam')],
            'tier' => $premium ? 'premium' : 'free',
            'locked' => $premium ? null : $this->lockedSummary($lessons, $catalogs),
            'offers' => $premium ? [] : $this->offers(),
        ];
    }

    /** Was die Vollversion zusätzlich enthält (nur Titel). @param list<array<string,mixed>> $lessons @return array<string,mixed> */
    private function lockedSummary(array $lessons, array $catalogs): array
    {
        $units = [];
        foreach ($lessons as $lesson) {
            if (in_array($lesson['key'], self::FREE_LESSONS, true)) {
                continue;
            }
            $units[$lesson['unit']] ??= ['unit' => $lesson['unit'], 'lessons' => []];
            $units[$lesson['unit']]['lessons'][] = $lesson['title'];
        }
        $series = [];
        $tasks = 0;
        $titles = array_column($lessons, 'title', 'key');
        foreach ($this->exams($catalogs) as $exam) {
            $series[] = ['title' => $exam['title'], 'tasks' => count($exam['lessons']), 'lessons' => array_values(array_map(static fn ($k) => $titles[$k] ?? $k, $exam['lessons']))];
            $tasks += count($exam['lessons']);
        }

        return ['lessons' => array_sum(array_map(static fn ($u) => count($u['lessons']), $units)), 'units' => array_values($units), 'series' => $series, 'tasks' => $tasks];
    }

    /** Simulator-Zugänge aus dem Shop (nur veröffentlichte, kaufbare Produkte). @return list<array<string,mixed>> */
    private function offers(): array
    {
        if (! function_exists('wc_get_product_id_by_sku')) {
            return (array) apply_filters('bootspruefung_sim_offers', []);
        }
        $out = [];
        foreach (\Bootspruefung\Learning\Commerce\ShopCatalog::SIM_PASSES as $sku) {
            $id = (int) wc_get_product_id_by_sku($sku);
            $product = $id ? wc_get_product($id) : null;
            if (! $product || $product->get_status() !== 'publish' || ! $product->is_purchasable()) {
                continue;
            }
            $out[] = [
                'sku' => $sku,
                'name' => wp_specialchars_decode($product->get_name()),
                'price' => html_entity_decode(wp_strip_all_tags(wc_price((float) $product->get_price())), ENT_QUOTES, 'UTF-8'),
                'url' => add_query_arg('add-to-cart', $id, wc_get_checkout_url()),
                'details' => (string) get_permalink($id),
            ];
        }

        return (array) apply_filters('bootspruefung_sim_offers', $out);
    }

    /** @return array{checks:list<array{label:string,ok:bool}>,passed:bool} */
    public function evaluate(int $userId, object $course, string $lessonKey, int $step, string $text): array
    {
        $this->require($userId, $course);
        $lesson = $this->lessonFor($course, $lessonKey);
        $this->requireLesson($userId, $course, $lessonKey);
        $speech = $lesson['steps'][$step]['speech'] ?? null;
        if (! $speech) {
            throw new RuntimeException(__('Dieser Schritt erwartet keinen Funkspruch.', 'bootspruefung-learning'));
        }

        return RadioChecks::checkText($speech['checks'], mb_substr($text, 0, 2000));
    }

    /** @return array{level:int} */
    public function progress(int $userId, object $course, string $lessonKey, int $level, string $device): array
    {
        $this->require($userId, $course);
        $lesson = $this->lessonFor($course, $lessonKey);
        $this->requireLesson($userId, $course, $lessonKey);
        if ($level < 1 || $level > 3 || ! in_array($device, array_column($this->data()['devices'], 'key'), true)) {
            throw new RuntimeException(__('Ungültige Angaben.', 'bootspruefung-learning'));
        }
        if (! $this->lessonAvailable($lesson, $device)) {
            throw new RuntimeException(__('Diese Lektion gibt es auf diesem Gerät nicht.', 'bootspruefung-learning'));
        }
        $key = $device.':'.$lessonKey;
        $current = $this->stored($userId, $key);
        $level = max((int) ($current['level'] ?? 0), $level);
        $this->store($userId, $key, ['level' => $level], $level >= 3);

        return ['level' => $level];
    }

    /** @param list<string> $passed @return array<string,mixed> */
    public function exam(int $userId, object $course, string $series, string $device, array $passed): array
    {
        $this->require($userId, $course);
        if (! $this->premium($userId, $course)) {
            throw new AccessDenied(__('Der Prüfungsmodus gehört zur Vollversion des Funkgerät-Simulators.', 'bootspruefung-learning'));
        }
        $exams = $this->exams($this->catalogs($course));
        if (! isset($exams[$series]) || ! in_array($device, array_column($this->data()['devices'], 'key'), true)) {
            throw new NotFound(__('Prüfungsreihe nicht gefunden.', 'bootspruefung-learning'));
        }
        if (! in_array($exams[$series]['catalog'], $this->deviceCatalogs($device), true)) {
            throw new RuntimeException(__('Diese Prüfung gibt es auf diesem Gerät nicht.', 'bootspruefung-learning'));
        }
        $lessons = $this->examLessons($series, $device);
        $ok = array_values(array_intersect($lessons, array_unique(array_map('strval', $passed))));
        $key = $device.':pruefung-'.$series;
        $current = $this->stored($userId, $key);
        $best = max((int) ($current['best'] ?? 0), count($ok));
        $this->store($userId, $key, ['best' => $best, 'total' => count($lessons), 'last' => $ok], $best === count($lessons));
        $solutions = [];
        foreach ($lessons as $lessonKey) {
            $solutions[$lessonKey] = $this->solutions($lessonKey);
        }

        return ['passed' => count($ok), 'total' => count($lessons), 'best' => $best, 'solutions' => (object) $solutions];
    }

    /** Überblick für die Funk-Seite. @return array<string,int> */
    public function summary(int $userId, object $course): array
    {
        if (! $this->available($userId, $course)) {
            return ['lessons' => 0, 'mastered' => 0];
        }
        $lessons = $this->lessonsForBrowser($this->catalogs($course));
        $premium = $this->premium($userId, $course);
        if (! $premium) {
            $lessons = array_filter($lessons, static fn ($l) => in_array($l['key'], self::FREE_LESSONS, true));
        }
        $levels = $this->db->get_col($this->db->prepare(
            "SELECT data FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_type = 'geraet' AND item_source_id LIKE %s",
            $userId,
            $this->db->esc_like(self::DEFAULT_DEVICE.':').'%'
        ));
        $mastered = count(array_filter($levels, static fn ($d) => ((json_decode((string) $d, true) ?: [])['level'] ?? 0) >= 3));

        return ['lessons' => count($lessons), 'mastered' => $mastered, 'premium' => $premium];
    }

    // ------------------------------------------------------------------ wie Geraetesimulation

    /** @param list<string> $catalogs @return list<array<string,mixed>> */
    private function lessonsForBrowser(array $catalogs): array
    {
        $out = [];
        foreach ($this->data()['lessons'] as $lesson) {
            if (! in_array($lesson['catalog'] ?? 'SRC', $catalogs, true)) {
                continue;
            }
            $lesson['steps'] = array_map(static function (array $step) {
                $step['speech'] = isset($step['speech']);

                return $step;
            }, $lesson['steps']);
            $out[] = $lesson;
        }

        return $out;
    }

    /** @param list<string> $catalogs @return array<string,array<string,mixed>> */
    private function exams(array $catalogs): array
    {
        $data = $this->data();
        $exams = [];
        foreach ($data['exams'] as $series => $lessons) {
            $meta = $data['exam_meta'][$series];
            if (in_array($meta['catalog'], $catalogs, true)) {
                $exams[$series] = ['title' => $meta['title'], 'catalog' => $meta['catalog'], 'lessons' => $lessons, 'start' => $meta['start'] ?? null, 'substitutes' => (object) ($meta['substitutes'] ?? [])];
            }
        }

        return $exams;
    }

    /** @param list<string> $catalogs @return list<array<string,mixed>> */
    private function devicesFor(array $catalogs, bool $withHandhelds): array
    {
        return array_values(array_filter($this->data()['devices'], fn ($d) => array_intersect($catalogs, $this->deviceCatalogs($d['key'])) !== []
            || ($withHandhelds && $catalogs !== [] && ($d['handheld'] ?? false))));
    }

    /** @return list<string> */
    private function deviceCatalogs(string $device): array
    {
        foreach ($this->data()['devices'] as $d) {
            if ($d['key'] === $device) {
                return $d['catalogs'] ?? ['SRC', 'UBI'];
            }
        }

        return ['SRC', 'UBI'];
    }

    /** @return list<string> */
    private function lineage(string $device): array
    {
        $byKey = array_column($this->data()['devices'], null, 'key');
        $chain = [];
        for ($key = $device; $key !== null && isset($byKey[$key]) && ! in_array($key, $chain, true); $key = $byKey[$key]['like'] ?? null) {
            $chain[] = $key;
        }

        return $chain;
    }

    /** @param array<string,mixed> $lesson */
    private function lessonAvailable(array $lesson, string $device): bool
    {
        if (! in_array($lesson['catalog'] ?? 'SRC', $this->deviceCatalogs($device), true)) {
            return false;
        }

        return ! isset($lesson['devices']) || array_intersect($this->lineage($device), $lesson['devices']) !== [];
    }

    /** @return list<string> */
    private function examLessons(string $series, string $device): array
    {
        $meta = $this->data()['exam_meta'][$series] ?? [];
        $substitutes = [];
        foreach ($this->lineage($device) as $key) {
            if (isset($meta['substitutes'][$key])) {
                $substitutes = $meta['substitutes'][$key];
                break;
            }
        }

        return array_map(static fn ($l) => $substitutes[$l] ?? $l, $this->data()['exams'][$series]);
    }

    /** @return list<string> */
    private function solutions(string $lessonKey): array
    {
        foreach ($this->data()['lessons'] as $lesson) {
            if ($lesson['key'] === $lessonKey) {
                return array_values(array_map(static fn ($s) => $s['speech']['solution'], array_filter($lesson['steps'], static fn ($s) => isset($s['speech']))));
            }
        }

        return [];
    }

    /** @return array<string,mixed> */
    private function lessonFor(object $course, string $key): array
    {
        foreach ($this->data()['lessons'] ?? [] as $lesson) {
            if ($lesson['key'] === $key && in_array($lesson['catalog'] ?? 'SRC', $this->catalogs($course), true)) {
                return $lesson;
            }
        }
        throw new NotFound(__('Lektion nicht gefunden.', 'bootspruefung-learning'));
    }

    /** @return array<string,mixed> */
    private function stored(int $userId, string $key): array
    {
        $json = $this->db->get_var($this->db->prepare("SELECT data FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_type = 'geraet' AND item_source_id = %s", $userId, $key));

        return $json ? (json_decode((string) $json, true) ?: []) : [];
    }

    /** @param array<string,mixed> $value */
    private function store(int $userId, string $key, array $value, bool $done): void
    {
        $id = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_type = 'geraet' AND item_source_id = %s", $userId, $key));
        $fields = ['data' => wp_json_encode($value), 'done' => $done ? 1 : 0];
        if ($id) {
            $this->db->update($this->t('radio_progress'), $fields, ['id' => (int) $id]);
        } else {
            $this->db->insert($this->t('radio_progress'), array_merge(['user_id' => $userId, 'item_type' => 'geraet', 'item_source_id' => $key], $fields));
        }
    }

    private function requireLesson(int $userId, object $course, string $lessonKey): void
    {
        if (! in_array($lessonKey, self::FREE_LESSONS, true) && ! $this->premium($userId, $course)) {
            throw new AccessDenied(__('Diese Lektion gehört zur Vollversion des Funkgerät-Simulators.', 'bootspruefung-learning'));
        }
    }

    private function require(int $userId, object $course): void
    {
        if (! $this->available($userId, $course)) {
            throw new AccessDenied(__('Die Gerätesimulation ist für deinen Kurs nicht freigeschaltet.', 'bootspruefung-learning'));
        }
    }
}
