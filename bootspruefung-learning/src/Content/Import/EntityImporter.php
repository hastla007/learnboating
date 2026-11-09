<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Import;

use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Package\Package;
use RuntimeException;
use wpdb;

/**
 * Schreibt Datensätze eines Pakets in die Plugin-Tabellen (Konzept Kap. 3.3):
 * - Erkennung über source_system + source_id, Änderung über den Datensatz-Hash;
 * - Fragen: neuer Hash → neue Revision, die alte bleibt (deprecated) für Versuche erhalten;
 * - Zuordnungen werden differenziell abgeglichen statt neu angelegt;
 * - in der Quelle entfernte Datensätze werden source_archived, nie gelöscht.
 * Im Dry-Run wird nur die Aktion ermittelt, nichts geschrieben.
 */
final class EntityImporter
{
    public const SOURCE_SYSTEM = 'boatingschool';
    public const ARCHIVED = 'source_archived';

    /** Tabellen mit Quellspalten, die archiviert werden können: Paket-Entität => Tabelle. */
    public const ARCHIVABLE = [
        'courses' => 'course',
        'modules' => 'module',
        'questions' => 'question',
        'media' => 'media',
        'exam_rules' => 'exam_rule',
        'exam_papers' => 'exam_paper',
        'navigation_tasks' => 'navigation_task',
        'praxis_tasks' => 'praxis_task',
        'video_modules' => 'video_module',
        'radio_texts' => 'radio_text',
        'radio_vocabulary' => 'radio_vocabulary',
        'radio_practice' => 'radio_scenario',
        'device_simulation' => 'device',
        'exam_committees' => 'exam_committee',
        'exam_guides' => 'exam_guide',
    ];

    /** @var array<string,array<string,int>> Tabelle => source_id/code => id */
    private array $ids = [];

    private string $now;

    public function __construct(
        private readonly wpdb $db,
        private readonly Package $package,
        private readonly string $packageId,
        private readonly bool $dryRun,
    ) {
        $this->now = current_time('mysql', true);
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /**
     * Ermittelt bzw. führt die Aktion für einen Datensatz aus.
     * @param array<string,mixed> $record
     * @return string create|update|unchanged
     */
    public function import(string $entity, array $record): string
    {
        return match ($entity) {
            'courses' => $this->course($record),
            'modules' => $this->module($record),
            'media' => $this->media($record),
            'questions' => $this->question($record),
            'module_links' => $this->moduleLinks($record),
            'course_links' => $this->courseLinks($record),
            'exam_rules' => $this->examRule($record),
            'exam_papers' => $this->examPaper($record),
            'navigation_tasks' => $this->navigationTask($record),
            'praxis_tasks' => $this->praxisTask($record),
            'video_modules' => $this->videoModule($record),
            'radio_texts' => $this->radioText($record),
            'radio_vocabulary' => $this->radioVocabulary($record),
            'radio_practice' => $this->radioPractice($record),
            'device_simulation' => $this->deviceSimulation($record),
            'exam_committees' => $this->examCommittee($record),
            'exam_guides' => $this->examGuide($record),
            default => throw new RuntimeException(sprintf(__('Unbekannte Entität %s', 'bootspruefung-learning'), $entity)),
        };
    }

    /**
     * Archiviert Datensätze, die im Paket fehlen.
     * @return array<string,list<string>> Entität => archivierte source_ids
     */
    public function archiveMissing(): array
    {
        $archived = [];
        foreach (self::ARCHIVABLE as $entity => $table) {
            $present = array_column($this->package->entities($entity), 'source_id');
            $archived[$entity] = $this->archiveTable($table, $present);
        }
        $navQuestions = [];
        foreach ($this->package->entities('navigation_tasks') as $task) {
            foreach ($task['questions'] as $q) {
                $navQuestions[] = $q['source_id'];
            }
        }
        $archived['navigation_questions'] = $this->archiveTable('navigation_question', $navQuestions);
        $lessons = [];
        foreach ($this->package->entities('video_modules') as $module) {
            foreach ($module['lessons'] as $lesson) {
                $lessons[] = $lesson['source_id'];
            }
        }
        $archived['video_lessons'] = $this->archiveTable('video_lesson', $lessons);

        return array_filter($archived);
    }

    /** @param list<string> $present @return list<string> */
    private function archiveTable(string $table, array $present): array
    {
        $existing = $this->db->get_results($this->db->prepare(
            "SELECT id, source_id FROM {$this->t($table)} WHERE source_system = %s AND status <> %s",
            self::SOURCE_SYSTEM,
            self::ARCHIVED
        ));
        $presentSet = array_flip($present);
        $gone = [];
        foreach ($existing as $row) {
            if (! isset($presentSet[$row->source_id])) {
                $gone[(int) $row->id] = $row->source_id;
            }
        }
        if ($gone !== [] && ! $this->dryRun) {
            foreach (array_chunk(array_keys($gone), 500) as $chunk) {
                $this->db->query($this->db->prepare(
                    "UPDATE {$this->t($table)} SET status = %s WHERE id IN (".implode(',', array_fill(0, count($chunk), '%d')).')',
                    array_merge([self::ARCHIVED], $chunk)
                ));
            }
        }

        return array_values($gone);
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed> $r */
    private function course(array $r): string
    {
        return $this->upsert('course', $r, [
            'code' => $r['code'],
            'name' => $r['name'],
            'course_type' => $r['course_type'],
            'language' => $r['language'],
            'sort_order' => $r['sort_order'],
            'status' => $r['status'],
        ]);
    }

    /** @param array<string,mixed> $r */
    private function module(array $r): string
    {
        return $this->upsert('module', $r, [
            'code' => $r['code'],
            'name' => $r['name'],
            'sort_order' => $r['sort_order'],
            'status' => $r['status'],
        ]);
    }

    /** @param array<string,mixed> $r */
    private function media(array $r): string
    {
        $action = $this->action('media', $r);
        $fields = [
            'package_path' => $r['path'],
            'media_type' => $r['media_type'],
            'mime_type' => $r['mime_type'],
            'size' => $r['size'],
            'sha256' => $r['sha256'],
            'alt_text' => $r['alt_text'],
            'caption' => $r['caption'],
            'description' => $r['description'],
            'source_text' => $r['source'],
            'source_url' => $r['source_url'],
            'rights_owner' => $r['rights_owner'],
            'license_reference' => $r['license_reference'],
            'rights_status' => $r['rights_status'],
            'vector_source' => $r['vector_source'],
            'status' => 'published',
        ];
        if ($this->dryRun) {
            return $action;
        }
        $existing = $this->find('media', $r['source_id']);
        if ($action === 'unchanged') {
            if ($existing && $existing->storage_key && MediaStorage::exists($existing->storage_key)) {
                return $action;
            }
            $action = 'update';
        }
        $fields['storage_key'] = MediaStorage::store($this->package->path($r['path']), $r['path'], $r['sha256']);

        return $this->upsert('media', $r, $fields, $action);
    }

    /** @param array<string,mixed> $r */
    private function question(array $r): string
    {
        $action = $this->action('question', $r);
        if ($this->dryRun || $action === 'unchanged') {
            return $action;
        }
        $rev = $r['revision'];
        $existing = $this->find('question', $r['source_id']);
        $questionFields = [
            'official_number' => $r['official_number'],
            'content_role' => $r['content_role'],
            'language' => $r['language'],
            'active' => $r['active'] ? 1 : 0,
            'source_module' => $r['source_module'],
            'status' => $rev['editorial_status'],
        ];

        if ($existing === null) {
            $this->insert('question', array_merge($this->sourceFields($r), $questionFields));
            $questionId = (int) $this->db->insert_id;
            $this->ids['question'][$r['source_id']] = $questionId;
            $revisionNo = 1;
            $current = null;
        } else {
            $questionId = (int) $existing->id;
            $current = $existing->current_revision_id ? $this->db->get_row($this->db->prepare(
                "SELECT id, source_hash, editorial_status FROM {$this->t('question_revision')} WHERE id = %d",
                $existing->current_revision_id
            )) : null;
            $revisionNo = 1 + (int) $this->db->get_var($this->db->prepare(
                "SELECT COALESCE(MAX(revision_no), 0) FROM {$this->t('question_revision')} WHERE question_id = %d",
                $questionId
            ));
        }

        // Gab es diesen Stand schon einmal (Rollback oder Rückkehr zu einer früheren
        // Fassung), wird diese Revision wieder aktiv statt eine neue anzulegen.
        $previous = $existing === null ? null : $this->db->get_row($this->db->prepare(
            "SELECT id, editorial_status FROM {$this->t('question_revision')} WHERE question_id = %d AND source_hash = %s ORDER BY revision_no DESC LIMIT 1",
            $questionId,
            $r['hash']
        ));
        if ($current !== null && $current->source_hash === $r['hash']) {
            // Gleicher Inhalt (z. B. reaktivierte archivierte Frage): keine neue Revision.
            $revisionId = (int) $current->id;
        } elseif ($previous !== null) {
            $revisionId = (int) $previous->id;
            $this->db->update($this->t('question_revision'), ['editorial_status' => $rev['editorial_status']], ['id' => $revisionId]);
            if ($current !== null && $current->editorial_status === 'published') {
                $this->db->update($this->t('question_revision'), ['editorial_status' => 'deprecated'], ['id' => (int) $current->id]);
            }
        } else {
            $this->insert('question_revision', [
                'question_id' => $questionId,
                'revision_no' => $revisionNo,
                'source_hash' => $r['hash'],
                'package_id' => $this->packageId,
                'question_type' => $rev['question_type'],
                'question_text' => $rev['question_text'],
                'topic' => $rev['topic'],
                'subtopic' => $rev['subtopic'],
                'competency' => $rev['competency'],
                'smart_category' => $rev['smart_category'],
                'scope' => $rev['scope'],
                'image_required' => $rev['image_required'] ? 1 : 0,
                'feedback_correct' => $rev['feedback_correct'],
                'feedback_incorrect' => $rev['feedback_incorrect'],
                'source_catalog' => $rev['source']['catalog'],
                'source_version' => $rev['source']['version'],
                'source_question_id' => $rev['source']['question_id'],
                'source_page' => $rev['source']['page'],
                'editorial_status' => $rev['editorial_status'],
                'rights_status' => $rev['rights_status'],
                'rights_owner' => $rev['rights_owner'],
                'license_reference' => $rev['license_reference'],
                'valid_from' => $rev['valid_from'],
                'valid_to' => $rev['valid_to'],
                'created_at' => $this->now,
            ]);
            $revisionId = (int) $this->db->insert_id;
            foreach ($rev['answers'] as $answer) {
                $this->insert('answer', [
                    'revision_id' => $revisionId,
                    'answer_key' => $answer['answer_key'],
                    'answer_text' => $answer['answer_text'],
                    'is_correct' => $answer['is_correct'] ? 1 : 0,
                    'sort_order' => $answer['sort_order'],
                ]);
            }
            foreach ($rev['media'] as $link) {
                $this->insert('content_media', [
                    'object_type' => 'question_revision',
                    'object_id' => $revisionId,
                    'media_id' => $this->idOf('media', $link['media_source_id']),
                    'role' => $link['role'],
                    'sort_order' => $link['sort_order'],
                ]);
            }
            if ($current !== null && $current->editorial_status === 'published') {
                $this->db->update($this->t('question_revision'), ['editorial_status' => 'deprecated'], ['id' => (int) $current->id]);
            }
        }

        $this->db->update($this->t('question'), array_merge($questionFields, [
            'current_revision_id' => $revisionId,
            'source_hash' => $r['hash'],
            'package_id' => $this->packageId,
            'imported_at' => $this->now,
        ]), ['id' => $questionId]);

        return $action;
    }

    /** @param array<string,mixed> $r */
    private function moduleLinks(array $r): string
    {
        $moduleId = $this->idOf('module', $r['source_id']);
        $desired = [];
        foreach ($r['questions'] as $link) {
            $desired[$this->idOf('question', $link['question_source_id'])] = ['sort_order' => $link['sort_order'], 'required' => $link['required'] ? 1 : 0];
        }

        return $this->syncLinks('module_question', 'module_id', $moduleId, 'question_id', $desired);
    }

    /** @param array<string,mixed> $r */
    private function courseLinks(array $r): string
    {
        $courseId = $this->idOf('course', $r['source_id']);
        $desired = [];
        foreach ($r['modules'] as $link) {
            $desired[$this->idOf('module', $link['module_code'])] = ['sort_order' => $link['sort_order'], 'required' => $link['required'] ? 1 : 0];
        }

        return $this->syncLinks('course_module', 'course_id', $courseId, 'module_id', $desired);
    }

    /** @param array<string,mixed> $r */
    private function examRule(array $r): string
    {
        $action = $this->upsert('exam_rule', $r, [
            'course_id' => $this->dryRun ? 0 : $this->idOf('course', $r['course_code']),
            'version' => $r['version'],
            'valid_from' => $r['valid_from'],
            'valid_until' => $r['valid_until'],
            'time_limit_seconds' => $r['time_limit_seconds'],
            'passing_rule' => wp_json_encode($r['passing_rule']),
            'verified' => $r['verified'] ? 1 : 0,
            'status' => $r['status'],
        ]);
        if (! $this->dryRun && $action !== 'unchanged') {
            $ruleId = $this->idOf('exam_rule', $r['source_id']);
            $desired = [];
            foreach ($r['blueprints'] as $bp) {
                $desired[$this->idOf('module', $bp['module_code'])] = ['question_count' => $bp['question_count']];
            }
            $this->syncLinks('exam_blueprint', 'rule_id', $ruleId, 'module_id', $desired);
        }

        return $action;
    }

    /** @param array<string,mixed> $r */
    private function examPaper(array $r): string
    {
        $action = $this->upsert('exam_paper', $r, [
            'course_id' => $this->dryRun ? 0 : $this->idOf('course', $r['course_code']),
            'paper_number' => $r['paper_number'],
            'sort_order' => $r['sort_order'],
            'copied_from' => $r['copied_from'],
            'status' => 'published',
        ]);
        if (! $this->dryRun && $action !== 'unchanged') {
            // Bogenfragen werden von nichts referenziert (Sitzungen speichern einen Snapshot).
            $paperId = $this->idOf('exam_paper', $r['source_id']);
            $this->db->delete($this->t('exam_paper_question'), ['paper_id' => $paperId]);
            foreach ($r['questions'] as $q) {
                $this->insert('exam_paper_question', [
                    'paper_id' => $paperId,
                    'question_id' => $this->idOf('question', $q['question_source_id']),
                    'position' => $q['position'],
                ]);
            }
        }

        return $action;
    }

    /** @param array<string,mixed> $r */
    private function navigationTask(array $r): string
    {
        $action = $this->upsert('navigation_task', $r, [
            'task_number' => $r['task_number'],
            'scenario_text' => $r['scenario_text'],
            'hint' => $r['hint'],
            'sort_order' => $r['sort_order'],
            'source_text' => $r['source'],
            'source_version' => $r['source_version'],
            'rights_status' => $r['rights_status'],
            'status' => $r['status'],
        ]);
        if ($this->dryRun || $action === 'unchanged') {
            return $action;
        }
        $taskId = $this->idOf('navigation_task', $r['source_id']);
        $desired = [];
        foreach ($r['course_codes'] as $i => $code) {
            $desired[$this->idOf('course', $code)] = ['sort_order' => $r['sort_order']];
        }
        $this->syncLinks('navigation_task_course', 'task_id', $taskId, 'course_id', $desired);
        foreach ($r['questions'] as $q) {
            // Teilaufgaben behalten ihre ID (Fortschritt verweist darauf).
            $this->upsert('navigation_question', $q + ['hash' => 'sha256:'.hash('sha256', (string) wp_json_encode($q))], [
                'task_id' => $taskId,
                'question_number' => $q['question_number'],
                'question_text' => $q['question_text'],
                'answer_text' => $q['answer_text'],
                'sort_order' => $q['sort_order'],
                'status' => $r['status'],
            ]);
        }

        return $action;
    }

    /** @param array<string,mixed> $r */
    private function praxisTask(array $r): string
    {
        return $this->upsert('praxis_task', $r, [
            'course_id' => $this->dryRun ? 0 : $this->idOf('course', $r['course_code']),
            'category' => $r['category'],
            'subcategory' => $r['subcategory'],
            'exam_reference' => $r['exam_reference'],
            'task_type' => $r['task_type'],
            'question' => $r['question'],
            'answer' => $r['answer'],
            'explanation' => $r['explanation'],
            'media_id' => $r['media_source_id'] === null || $this->dryRun ? null : $this->idOf('media', $r['media_source_id']),
            'source_text' => $r['source'],
            'source_url' => $r['source_url'],
            'sort_order' => $r['sort_order'],
            'active' => $r['active'] ? 1 : 0,
            'rights_status' => $r['rights_status'],
            'copied_from' => $r['copied_from'],
            'status' => 'published',
        ]);
    }

    /** @param array<string,mixed> $r */
    private function videoModule(array $r): string
    {
        $action = $this->upsert('video_module', $r, [
            'course_id' => $this->dryRun ? 0 : $this->idOf('course', $r['course_code']),
            'title' => $r['title'],
            'group_label' => $r['group_label'],
            'sort_order' => $r['sort_order'],
            'rights_status' => $r['rights_status'],
            'status' => $r['status'],
        ]);
        if ($this->dryRun || $action === 'unchanged') {
            return $action;
        }
        $moduleId = $this->idOf('video_module', $r['source_id']);
        foreach ($r['lessons'] as $lesson) {
            // Lektionen behalten ihre ID (Sehfortschritt verweist darauf).
            $this->upsert('video_lesson', $lesson + ['hash' => 'sha256:'.hash('sha256', (string) wp_json_encode($lesson))], [
                'module_id' => $moduleId,
                'title' => $lesson['title'],
                'video_url' => $lesson['video_url'],
                'provider' => $lesson['provider'],
                'duration_seconds' => $lesson['duration_seconds'],
                'is_placeholder' => $lesson['is_placeholder'] ? 1 : 0,
                'sort_order' => $lesson['sort_order'],
                'status' => $r['status'],
            ]);
            $lessonId = $this->idOf('video_lesson', $lesson['source_id']);
            $this->db->delete($this->t('video_lesson_step'), ['lesson_id' => $lessonId]);
            foreach ($lesson['steps'] as $step) {
                $this->insert('video_lesson_step', [
                    'source_system' => self::SOURCE_SYSTEM,
                    'source_id' => $step['source_id'],
                    'lesson_id' => $lessonId,
                    'title' => $step['title'],
                    'media_id' => $step['media_source_id'] === null ? null : $this->idOf('media', $step['media_source_id']),
                    'sort_order' => $step['sort_order'],
                    'status' => 'published',
                ]);
            }
            $desired = [];
            foreach ($lesson['questions'] as $q) {
                $desired[$this->idOf('question', $q['question_source_id'])] = ['sort_order' => $q['sort_order']];
            }
            $this->syncLinks('video_lesson_question', 'lesson_id', $lessonId, 'question_id', $desired);
        }

        return $action;
    }

    /** @param array<string,mixed> $r */
    private function radioText(array $r): string
    {
        return $this->upsert('radio_text', $r, [
            'catalog' => $r['catalog'],
            'exercise_type' => $r['exercise_type'],
            'number' => $r['number'],
            'course_codes' => implode(',', $r['course_codes']),
            'source_text' => $r['source'] === null ? null : mb_substr($r['source'], 0, 255),
            'direction' => $r['direction'],
            'text_de' => $r['text_de'],
            'text_en' => $r['text_en'],
            'segments' => $r['lines'] === null ? null : wp_json_encode($r['lines']),
            'sort_order' => $r['sort_order'],
            'rights_status' => $r['rights_status'],
            'status' => 'published',
        ]);
    }

    /** @param array<string,mixed> $r */
    private function radioPractice(array $r): string
    {
        $message = '';
        foreach ($r['fields'] as $field) {
            if ($field['name'] === 'message') {
                $message = $field['solution'];
            }
        }

        return $this->upsert('radio_scenario', $r, [
            'catalog' => $r['catalog'],
            'number' => $r['number'],
            'course_codes' => implode(',', $r['course_codes']),
            'message_kind' => $r['kind'],
            'scenario' => (string) ($r['situation_en'] ?? ''),
            'task_text' => $r['situation_de'],
            'model_message' => $message,
            'checkpoints' => wp_json_encode($r['fields']),
            'evaluation_rule' => 'checks',
            'language' => 'de-DE',
            'rights_status' => $r['rights_status'],
            'sort_order' => $r['sort_order'],
            'status' => 'published',
        ]);
    }

    /** @param array<string,mixed> $r */
    private function deviceSimulation(array $r): string
    {
        return $this->upsert('device', $r, [
            'model' => 'geraetesimulation',
            'catalogs' => 'SRC,UBI',
            'data' => wp_json_encode(array_diff_key($r, ['hash' => 1])),
            'status' => 'published',
        ]);
    }

    /**
     * Prüfungsausschuss. Lokal geänderte Einträge (Admin) überschreibt ein
     * Reimport nicht – wie der Seeder der Quelle (firstOrCreate).
     *
     * @param array<string,mixed> $r
     */
    private function examCommittee(array $r): string
    {
        if ($this->locallyModified('exam_committee', $r['source_id'])) {
            return 'unchanged';
        }

        return $this->upsert('exam_committee', $r, [
            'commission' => $r['commission'], 'location_type' => $r['location_type'], 'contact_person' => $r['contact_person'],
            'street' => $r['street'], 'postal_code' => $r['postal_code'], 'city' => $r['city'], 'phone' => $r['phone'], 'fax' => $r['fax'],
            'email' => $r['email'], 'website' => $r['website'], 'office_hours' => $r['office_hours'], 'note' => $r['note'],
            'source_url' => $r['source_url'], 'course_codes' => implode(',', $r['course_codes']), 'sort_order' => $r['sort_order'],
            'rights_status' => $r['rights_status'], 'status' => 'published',
        ]);
    }

    /**
     * Anleitung zur Prüfungsanmeldung (Seite „Prüfung buchen“ der Quelle) – Inhalt als JSON.
     *
     * @param array<string,mixed> $r
     */
    private function examGuide(array $r): string
    {
        return $this->upsert('exam_guide', $r, [
            'tab' => $r['tab'], 'title' => $r['title'],
            'data' => wp_json_encode(array_diff_key($r, ['hash' => 1, 'source_id' => 1, 'rights_status' => 1, 'source_file' => 1])),
            'course_codes' => implode(',', array_map(static fn ($c) => $c['course_code'], $r['courses'])),
            'sort_order' => $r['sort_order'], 'rights_status' => $r['rights_status'], 'status' => 'published',
        ]);
    }

    private function locallyModified(string $table, string $sourceId): bool
    {
        $row = $this->find($table, $sourceId);

        return $row !== null && ! empty($row->locally_modified) && $row->status !== self::ARCHIVED;
    }

    /** @param array<string,mixed> $r */
    private function radioVocabulary(array $r): string
    {
        return $this->upsert('radio_vocabulary', $r, [
            'catalog' => $r['catalog'],
            'category' => $r['category'],
            'term_de' => $r['term_de'],
            'term_en' => $r['term_en'],
            'hint' => $r['hint'],
            'course_codes' => implode(',', $r['course_codes']),
            'sort_order' => $r['sort_order'],
            'rights_status' => $r['rights_status'],
            'status' => 'published',
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $fields
     */
    private function upsert(string $table, array $record, array $fields, ?string $action = null): string
    {
        $action ??= $this->action($table, $record);
        if ($this->dryRun || $action === 'unchanged') {
            return $action;
        }
        $existing = $this->find($table, $record['source_id']);
        $data = array_merge($fields, [
            'source_hash' => $record['hash'],
            'package_id' => $this->packageId,
            'imported_at' => $this->now,
        ]);
        if ($existing === null) {
            $this->insert($table, array_merge($this->sourceFields($record), $data));
            $this->ids[$table][$record['source_id']] = (int) $this->db->insert_id;
        } else {
            if ($this->db->update($this->t($table), $data, ['id' => (int) $existing->id]) === false) {
                throw new RuntimeException(sprintf(__('Aktualisierung %1$s %2$s fehlgeschlagen: %3$s', 'bootspruefung-learning'), $table, $record['source_id'], $this->db->last_error));
            }
        }

        return $action;
    }

    /** @param array<string,mixed> $record */
    private function action(string $table, array $record): string
    {
        $existing = $this->find($table, $record['source_id']);
        if ($existing === null) {
            return 'create';
        }
        if ($existing->source_hash === $record['hash'] && $existing->status !== self::ARCHIVED) {
            return 'unchanged';
        }

        return 'update';
    }

    private function find(string $table, string $sourceId): ?object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->t($table)} WHERE source_system = %s AND source_id = %s",
            self::SOURCE_SYSTEM,
            $sourceId
        ));

        return $row ?: null;
    }

    private function idOf(string $table, string $key): int
    {
        if (! isset($this->ids[$table][$key])) {
            $column = in_array($table, ['course', 'module'], true) ? 'code' : 'source_id';
            $id = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->t($table)} WHERE {$column} = %s".($column === 'source_id' ? ' AND source_system = %s' : ''),
                ...($column === 'source_id' ? [$key, self::SOURCE_SYSTEM] : [$key])
            ));
            if ($id === null) {
                throw new RuntimeException(sprintf(__('Verweis nicht auflösbar: %1$s %2$s', 'bootspruefung-learning'), $table, $key));
            }
            $this->ids[$table][$key] = (int) $id;
        }

        return $this->ids[$table][$key];
    }

    /**
     * Gleicht Zuordnungen ab: fehlende anlegen, überzählige löschen, Zusatzfelder aktualisieren.
     * @param array<int,array<string,mixed>> $desired childId => Zusatzfelder
     */
    private function syncLinks(string $table, string $parentColumn, int $parentId, string $childColumn, array $desired): string
    {
        if ($this->dryRun) {
            return 'unchanged';
        }
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t($table)} WHERE {$parentColumn} = %d", $parentId), ARRAY_A);
        $existing = [];
        foreach ($rows as $row) {
            $existing[(int) $row[$childColumn]] = $row;
        }
        $changed = false;
        foreach ($desired as $childId => $extra) {
            if (! isset($existing[$childId])) {
                $this->insert($table, array_merge([$parentColumn => $parentId, $childColumn => $childId], $extra));
                $changed = true;
                continue;
            }
            $diff = [];
            foreach ($extra as $key => $value) {
                if ((string) $existing[$childId][$key] !== (string) $value || ($value === null) !== ($existing[$childId][$key] === null)) {
                    $diff[$key] = $value;
                }
            }
            if ($diff !== []) {
                $this->db->update($this->t($table), $diff, ['id' => (int) $existing[$childId]['id']]);
                $changed = true;
            }
        }
        foreach (array_diff_key($existing, $desired) as $row) {
            $this->db->delete($this->t($table), ['id' => (int) $row['id']]);
            $changed = true;
        }

        return $changed ? 'update' : 'unchanged';
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function sourceFields(array $record): array
    {
        return ['source_system' => self::SOURCE_SYSTEM, 'source_id' => $record['source_id']];
    }

    /** @param array<string,mixed> $data */
    private function insert(string $table, array $data): void
    {
        if ($this->db->insert($this->t($table), $data) === false) {
            throw new RuntimeException(sprintf(__('Einfügen in %1$s fehlgeschlagen: %2$s', 'bootspruefung-learning'), $table, $this->db->last_error));
        }
    }
}
