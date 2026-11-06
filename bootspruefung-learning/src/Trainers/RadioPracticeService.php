<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Funkpraxis (Schritt 8a) wie FunkTrainingController::praxis* der Quelle:
 * Lage lesen, Rang/DSC-Bedienung/Funkgerät/Meldung (UBI: Verkehrskreis statt DSC)
 * eintragen oder einsprechen, serverseitig gegen die Prüfpunkte auswerten,
 * danach Musterlösung je Feld. Fortschrittsschlüssel wie die Quelle (SRC-01 …).
 */
final class RadioPracticeService
{
    private \wpdb $db;

    public function __construct(private readonly AccessService $access)
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** @return array<string,mixed> */
    public function overview(int $userId, object $course): array
    {
        $this->require($userId, $course);
        $rows = $this->rows($course);
        $done = $this->progress($userId, array_column($rows, 'source_id'));
        $catalogs = [];
        foreach ($rows as $r) {
            $catalogs[$r->catalog] ??= ['catalog' => $r->catalog, 'tasks' => []];
            $catalogs[$r->catalog]['tasks'][] = [
                'id' => $r->source_id,
                'number' => (int) $r->number,
                'kind' => $r->message_kind,
                'teaser' => mb_substr((string) $r->task_text, 0, 140),
                'done' => (bool) ($done[$r->source_id]->done ?? false),
                'evaluated' => isset($done[$r->source_id]) && $done[$r->source_id]->data !== null,
            ];
        }

        return ['catalogs' => array_values($catalogs)];
    }

    /** @return array<string,mixed> */
    public function show(int $userId, object $course, string $key): array
    {
        $this->require($userId, $course);
        $row = $this->row($course, $key);
        $p = $this->progress($userId, [$key])[$key] ?? null;
        $siblings = array_column(array_filter($this->rows($course), static fn ($r) => $r->catalog === $row->catalog), 'source_id');
        $pos = array_search($key, $siblings, true);
        $fields = json_decode((string) $row->checkpoints, true) ?: [];

        return [
            'id' => $row->source_id,
            'catalog' => $row->catalog,
            'number' => (int) $row->number,
            'kind' => $row->message_kind,
            'situation_de' => $row->task_text,
            'situation_en' => $row->scenario !== '' ? $row->scenario : null,
            'fields' => array_map(static fn ($f) => ['name' => $f['name'], 'label' => $f['label'], 'placeholder' => $f['placeholder'], 'multiline' => $f['multiline']], $fields),
            'answers' => $p && $p->data ? (json_decode((string) $p->data, true) ?: []) : [],
            'done' => (bool) ($p->done ?? false),
            'previous' => $pos > 0 ? $siblings[$pos - 1] : null,
            'next' => $pos !== false && isset($siblings[$pos + 1]) ? $siblings[$pos + 1] : null,
        ];
    }

    /**
     * @param array<string,string> $answers
     * @return array<string,mixed>
     */
    public function evaluate(int $userId, object $course, string $key, array $answers): array
    {
        $this->require($userId, $course);
        $row = $this->row($course, $key);
        $fields = json_decode((string) $row->checkpoints, true) ?: [];
        $names = array_column($fields, 'name');
        $answers = array_map(static fn ($v) => mb_substr((string) $v, 0, 5000), array_intersect_key($answers, array_flip($names)));
        if (trim(implode('', $answers)) === '') {
            throw new RuntimeException(__('Trag zuerst etwas ein, dann kannst du auswerten.', 'bootspruefung-learning'));
        }
        $result = [];
        $passed = 0;
        $total = 0;
        foreach ($fields as $field) {
            $checks = RadioChecks::run($field['checks'], (string) ($answers[$field['name']] ?? ''));
            $passed += count(array_filter($checks, static fn ($c) => $c['ok']));
            $total += count($checks);
            $result[] = ['name' => $field['name'], 'label' => $field['label'], 'checks' => $checks, 'solution' => $field['solution']];
        }
        $this->save($userId, $key, ['data' => wp_json_encode($answers)]);

        return ['fields' => $result, 'passed' => $passed, 'total' => $total];
    }

    public function setDone(int $userId, object $course, string $key, bool $done): void
    {
        $this->require($userId, $course);
        $this->row($course, $key);
        $this->save($userId, $key, ['done' => $done ? 1 : 0]);
    }

    /** @return list<string> Funkkataloge, die zum Kurs gehören (wie FunkTraining::catalogsFor). */
    public function catalogsFor(object $course): array
    {
        return array_values(array_intersect(['SRC', 'UBI'], array_column($this->rows($course), 'catalog')));
    }

    public function count(int $userId, object $course): int
    {
        return $this->access->can($userId, $course->code, AccessService::RADIO) ? count($this->rows($course)) : 0;
    }

    /** @return list<object> */
    private function rows(object $course): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT x.* FROM {$this->t('radio_scenario')} x ".Overrides::rightsJoin('radio_scenario', 'x', 'o')."
             WHERE x.status = 'published' AND FIND_IN_SET(%s, x.course_codes) AND COALESCE(o.value, x.rights_status) NOT IN (".Overrides::blockingList().')
             ORDER BY x.catalog, x.sort_order',
            $course->code
        ));
    }

    private function row(object $course, string $key): object
    {
        foreach ($this->rows($course) as $row) {
            if ($row->source_id === $key) {
                return $row;
            }
        }
        throw new NotFound(__('Funkpraxis-Aufgabe nicht gefunden.', 'bootspruefung-learning'));
    }

    /** @param list<string> $keys @return array<string,object> */
    private function progress(int $userId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_type = 'praxis' AND item_source_id IN (".implode(',', array_fill(0, count($keys), '%s')).')',
            array_merge([$userId], $keys)
        ));
        $out = [];
        foreach ($rows as $row) {
            $out[$row->item_source_id] = $row;
        }

        return $out;
    }

    /** @param array<string,mixed> $fields */
    private function save(int $userId, string $key, array $fields): void
    {
        $existing = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_type = 'praxis' AND item_source_id = %s", $userId, $key));
        if ($existing) {
            $this->db->update($this->t('radio_progress'), $fields, ['id' => (int) $existing]);
        } else {
            $this->db->insert($this->t('radio_progress'), array_merge(['user_id' => $userId, 'item_type' => 'praxis', 'item_source_id' => $key], $fields));
        }
    }

    private function require(int $userId, object $course): void
    {
        if (! $this->access->can($userId, $course->code, AccessService::RADIO)) {
            throw new AccessDenied(__('Das Funktraining ist für deinen Kurs nicht freigeschaltet.', 'bootspruefung-learning'));
        }
    }
}
