<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Repositories;

use Bootspruefung\Learning\Content\Import\EntityImporter;
use Bootspruefung\Learning\Content\Media\MediaStorage;

/**
 * Lesezugriff auf Content mit den Auslieferungsregeln (Kap. 4.10, 6):
 * nur veröffentlichte, gültige, nicht archivierte Inhalte mit effektivem
 * Rechte-Status außerhalb von unknown/restricted.
 */
final class ContentRepository
{
    private \wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** @return list<object> veröffentlichte Kurse in Sortierung */
    public function courses(): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t('course')} WHERE status = 'published' AND source_system = %s ORDER BY sort_order, id",
            EntityImporter::SOURCE_SYSTEM
        ));
    }

    public function courseByCode(string $code): ?object
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('course')} WHERE code = %s AND status = 'published'", $code));

        return $row ?: null;
    }

    public static function slug(string $courseCode): string
    {
        return strtolower($courseCode);
    }

    public function courseBySlug(string $slug): ?object
    {
        return $this->courseByCode(strtoupper(sanitize_title($slug)));
    }

    /** @return list<object> Module des Kurses (id, code, name, sort_order) */
    public function modules(int $courseId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT m.id, m.code, m.name, cm.sort_order FROM {$this->t('course_module')} cm JOIN {$this->t('module')} m ON m.id = cm.module_id
             WHERE cm.course_id = %d AND m.status <> %s ORDER BY cm.sort_order, m.id",
            $courseId,
            EntityImporter::ARCHIVED
        ));
    }

    /** WHERE-Bedingung für auslieferbare Fragen (Aliase q, r, o). */
    public function deliverableQuestionSql(): string
    {
        $today = current_time('Y-m-d');

        return $this->db->prepare(
            " q.active = 1 AND q.status = 'published' AND r.editorial_status = 'published'
              AND r.question_type IN ('single_choice','multi_choice','open_text')
              AND COALESCE(o.value, r.rights_status) NOT IN (".Overrides::blockingList().")
              AND (r.valid_from IS NULL OR r.valid_from <= %s) AND (r.valid_to IS NULL OR r.valid_to >= %s)
              AND (r.image_required = 0 OR EXISTS (
                  SELECT 1 FROM {$this->t('content_media')} icm JOIN {$this->t('media')} im ON im.id = icm.media_id
                  LEFT JOIN {$this->t('override')} io ON io.object_type = 'media' AND io.object_id = im.id AND io.field = 'rights_status'
                  WHERE icm.object_type = 'question_revision' AND icm.object_id = r.id AND im.status <> 'source_archived'
                    AND COALESCE(io.value, im.rights_status) NOT IN (".Overrides::blockingList().'))) ',
            $today,
            $today
        );
    }

    private function questionFrom(): string
    {
        return " FROM {$this->t('question')} q JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id ".Overrides::rightsJoin('question', 'q', 'o');
    }

    /**
     * Auslieferbare Fragen der angegebenen Module mit Metadaten für den Smarttrainer.
     * @param list<int> $moduleIds
     * @return list<object> (question_id, revision_id, module_id, topic, sort)
     */
    public function questionsForModules(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $moduleIds));

        return $this->db->get_results(
            "SELECT q.id AS question_id, r.id AS revision_id, mq.module_id, COALESCE(NULLIF(r.smart_category, ''), r.topic) AS topic, r.question_type
             FROM {$this->t('module_question')} mq JOIN {$this->t('question')} q ON q.id = mq.question_id
             JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id ".Overrides::rightsJoin('question', 'q', 'o')."
             WHERE mq.module_id IN ({$in}) AND ".$this->deliverableQuestionSql().'
             ORDER BY FIELD(mq.module_id, '.$in.'), COALESCE(mq.sort_order, 2147483647), q.id'
        );
    }

    public function isDeliverable(int $questionId): bool
    {
        return (bool) $this->db->get_var($this->db->prepare('SELECT 1'.$this->questionFrom().' WHERE q.id = %d AND '.$this->deliverableQuestionSql(), $questionId));
    }

    public function revision(int $revisionId): ?object
    {
        $row = $this->db->get_row($this->db->prepare("SELECT r.*, q.source_id, q.official_number, q.content_role FROM {$this->t('question_revision')} r JOIN {$this->t('question')} q ON q.id = r.question_id WHERE r.id = %d", $revisionId));

        return $row ?: null;
    }

    /** @return list<object> Antworten einer Revision in Quellreihenfolge */
    public function answers(int $revisionId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT id, answer_key, answer_text, is_correct, sort_order FROM {$this->t('answer')} WHERE revision_id = %d ORDER BY sort_order, id", $revisionId));
    }

    /** @return list<array{url:string,alt:string,caption:?string}> auslieferbare Bilder einer Revision */
    public function media(int $revisionId): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT m.id, m.storage_key, m.alt_text, COALESCE(cm.caption_override, m.caption) AS caption, COALESCE(cm.alt_override, m.alt_text) AS alt, COALESCE(o.value, m.rights_status) AS rights
             FROM {$this->t('content_media')} cm JOIN {$this->t('media')} m ON m.id = cm.media_id ".Overrides::rightsJoin('media', 'm', 'o')."
             WHERE cm.object_type = 'question_revision' AND cm.object_id = %d AND m.status <> %s ORDER BY cm.sort_order",
            $revisionId,
            EntityImporter::ARCHIVED
        ));
        $out = [];
        foreach ($rows as $row) {
            if (in_array($row->rights, Overrides::BLOCKING_RIGHTS, true) || ! $row->storage_key) {
                continue;
            }
            $out[] = ['url' => MediaStorage::url($row->storage_key), 'alt' => (string) ($row->alt ?: __('Abbildung zur Frage', 'bootspruefung-learning')), 'caption' => $row->caption];
        }

        return $out;
    }

    /** Hat die Revision Pflichtbilder, die gesperrt sind oder fehlen? Dann wird sie nicht ausgeliefert. */
    public function missingRequiredImage(object $revision): bool
    {
        return (int) $revision->image_required === 1 && $this->media((int) $revision->id) === [];
    }

    /**
     * Aktuell gültiges Regelwerk eines Kurses (published + verifiziert + gültig) oder,
     * falls keins, das neueste veröffentlichte/Entwurfs-Regelwerk als Übungsmodus.
     * @return array{rule:?object,live:bool}
     */
    public function examRule(int $courseId): array
    {
        $rules = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t('exam_rule')} WHERE course_id = %d AND status <> %s ORDER BY id DESC",
            $courseId,
            EntityImporter::ARCHIVED
        ));
        $overrides = Overrides::forType('exam_rule');
        $today = current_time('Y-m-d');
        $fallback = null;
        foreach ($rules as $rule) {
            $o = $overrides[(int) $rule->id] ?? [];
            $rule->verified = array_key_exists('verified', $o) ? (int) $o['verified'] : (int) $rule->verified;
            $rule->valid_from = array_key_exists('valid_from', $o) ? $o['valid_from'] : $rule->valid_from;
            $rule->valid_until = array_key_exists('valid_until', $o) ? $o['valid_until'] : $rule->valid_until;
            $rule->passing = json_decode((string) $rule->passing_rule, true) ?: ['min_correct_ratio' => 0.75];
            $valid = ($rule->valid_from === null || $rule->valid_from <= $today) && ($rule->valid_until === null || $rule->valid_until >= $today);
            if ($rule->status === 'published' && $rule->verified === 1 && $valid) {
                return ['rule' => $rule, 'live' => true];
            }
            $fallback ??= $rule;
        }

        return ['rule' => $fallback, 'live' => false];
    }

    /** @return list<object> Blueprints (module_id, code, name, question_count) */
    public function blueprints(int $ruleId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT b.module_id, m.code, m.name, b.question_count FROM {$this->t('exam_blueprint')} b JOIN {$this->t('module')} m ON m.id = b.module_id WHERE b.rule_id = %d ORDER BY b.id",
            $ruleId
        ));
    }

    /** @return list<object> Bögen eines Kurses */
    public function papers(int $courseId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT p.id, p.paper_number, p.sort_order, COUNT(pq.id) AS question_count FROM {$this->t('exam_paper')} p
             LEFT JOIN {$this->t('exam_paper_question')} pq ON pq.paper_id = p.id
             WHERE p.course_id = %d AND p.status <> %s GROUP BY p.id ORDER BY p.sort_order, p.paper_number",
            $courseId,
            EntityImporter::ARCHIVED
        ));
    }

    /** @return list<object> (question_id, revision_id, position) – nur auslieferbare Fragen */
    public function paperQuestions(int $paperId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT q.id AS question_id, r.id AS revision_id, pq.position FROM {$this->t('exam_paper_question')} pq
             JOIN {$this->t('question')} q ON q.id = pq.question_id JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id ".Overrides::rightsJoin('question', 'q', 'o').'
             WHERE pq.paper_id = %d AND '.$this->deliverableQuestionSql().' ORDER BY pq.position',
            $paperId
        ));
    }

    public function paperQuestionCount(int $paperId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t('exam_paper_question')} WHERE paper_id = %d", $paperId));
    }

    /** @return array<int,list<int>> module_id => question_ids (für Teilergebnisse) */
    public function moduleQuestionIds(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }
        $rows = $this->db->get_results("SELECT module_id, question_id FROM {$this->t('module_question')} WHERE module_id IN (".implode(',', array_map('intval', $moduleIds)).')');
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->module_id][] = (int) $row->question_id;
        }

        return $out;
    }
}
