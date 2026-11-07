<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Navigations- und Praxistrainer (Kap. 4.6, 4.7, 6.4):
 * - Navigation: Szenario → Teilaufgabe → eigene Antwort → amtliches Ergebnis →
 *   Selbsteinschätzung; bewusst getrennt vom Prüfungsmodus (Lösung sofort einsehbar).
 * - Praxis: Karten nach Kategorie, Musterantwort aufdecken, „kann ich“ / „noch üben“.
 * Ausgeliefert wird nur, was nicht archiviert und rechtlich freigegeben ist.
 */
final class TrainerService
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

    // ------------------------------------------------------------------ Navigation

    /** @return list<array<string,mixed>> */
    public function navigationTasks(int $userId, object $course): array
    {
        $this->require($userId, $course, AccessService::NAVIGATION);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT t.id, t.task_number, t.hint, LEFT(t.scenario_text, 220) AS teaser,
                    COUNT(nq.id) AS questions, SUM(COALESCE(np.done, 0)) AS done
             FROM {$this->t('navigation_task_course')} tc JOIN {$this->t('navigation_task')} t ON t.id = tc.task_id
             ".Overrides::rightsJoin('navigation_task', 't', 'o')."
             JOIN {$this->t('navigation_question')} nq ON nq.task_id = t.id AND nq.status <> 'source_archived'
             LEFT JOIN {$this->t('navigation_progress')} np ON np.navigation_question_id = nq.id AND np.user_id = %d
             WHERE tc.course_id = %d AND t.status = 'published' AND COALESCE(o.value, t.rights_status) NOT IN (".Overrides::blockingList().')
             GROUP BY t.id ORDER BY t.sort_order, t.task_number',
            $userId,
            $course->id
        ));

        $limit = $this->access->limit($userId, $course->code, AccessService::NAVIGATION);
        $out = [];
        foreach ($rows as $i => $r) {
            $locked = $limit !== null && $i >= $limit;
            $out[] = ['id' => (int) $r->id, 'number' => (int) $r->task_number, 'teaser' => $locked ? null : $r->teaser, 'questions' => (int) $r->questions, 'done' => (int) $r->done, 'locked' => $locked];
        }

        return $out;
    }

    /** Teilumfang: nur die ersten n Aufgaben (Reihenfolge wie Liste). */
    private function requireNavigationTask(int $userId, object $course, int $taskId): void
    {
        if ($this->access->limit($userId, $course->code, AccessService::NAVIGATION) === null) {
            return;
        }
        foreach ($this->navigationTasks($userId, $course) as $task) {
            if ($task['id'] === $taskId) {
                if ($task['locked']) {
                    throw new AccessDenied(__('Diese Aufgabe ist im kostenlosen Umfang nicht enthalten.', 'bootspruefung-learning'));
                }

                return;
            }
        }
    }

    /** @return array<string,mixed> */
    public function navigationTask(int $userId, object $course, int $taskId): array
    {
        $this->require($userId, $course, AccessService::NAVIGATION);
        $this->requireNavigationTask($userId, $course, $taskId);
        $task = $this->db->get_row($this->db->prepare(
            "SELECT t.* FROM {$this->t('navigation_task')} t JOIN {$this->t('navigation_task_course')} tc ON tc.task_id = t.id AND tc.course_id = %d
             ".Overrides::rightsJoin('navigation_task', 't', 'o')."
             WHERE t.id = %d AND t.status = 'published' AND COALESCE(o.value, t.rights_status) NOT IN (".Overrides::blockingList().')',
            $course->id,
            $taskId
        ));
        if (! $task) {
            throw new NotFound(__('Navigationsaufgabe nicht gefunden.', 'bootspruefung-learning'));
        }
        $questions = $this->db->get_results($this->db->prepare(
            "SELECT nq.id, nq.question_number, nq.question_text, nq.answer_text, np.answer_text AS my_answer, np.self_assessment, np.done
             FROM {$this->t('navigation_question')} nq LEFT JOIN {$this->t('navigation_progress')} np ON np.navigation_question_id = nq.id AND np.user_id = %d
             WHERE nq.task_id = %d AND nq.status <> 'source_archived' ORDER BY nq.sort_order",
            $userId,
            $taskId
        ));
        $ids = array_map(static fn ($r) => (int) $r->id, $this->db->get_results($this->db->prepare(
            "SELECT t.id FROM {$this->t('navigation_task_course')} tc JOIN {$this->t('navigation_task')} t ON t.id = tc.task_id WHERE tc.course_id = %d AND t.status = 'published' ORDER BY t.sort_order",
            $course->id
        )));
        $pos = array_search($taskId, $ids, true);

        return [
            'id' => (int) $task->id,
            'number' => (int) $task->task_number,
            'scenario' => $task->scenario_text,
            'hint' => $task->hint,
            'source' => $task->source_text,
            'previous' => $pos > 0 ? $ids[$pos - 1] : null,
            'next' => $pos !== false && isset($ids[$pos + 1]) ? $ids[$pos + 1] : null,
            'questions' => array_map(static fn ($q) => [
                'id' => (int) $q->id,
                'number' => (int) $q->question_number,
                'text' => $q->question_text,
                // Lösung erst nach eigener Antwort oder ausdrücklichem Aufdecken.
                'answer' => $q->done !== null ? $q->answer_text : null,
                'my_answer' => $q->my_answer,
                'self_assessment' => $q->self_assessment,
                'done' => (bool) $q->done,
            ], $questions),
        ];
    }

    /** Speichert Antwort/Selbsteinschätzung und liefert das amtliche Ergebnis. @return array<string,mixed> */
    public function navigationAnswer(int $userId, object $course, int $questionId, ?string $answer, ?string $assessment): array
    {
        $this->require($userId, $course, AccessService::NAVIGATION);
        $q = $this->db->get_row($this->db->prepare(
            "SELECT nq.* FROM {$this->t('navigation_question')} nq JOIN {$this->t('navigation_task_course')} tc ON tc.task_id = nq.task_id AND tc.course_id = %d WHERE nq.id = %d AND nq.status <> 'source_archived'",
            $course->id,
            $questionId
        ));
        if (! $q) {
            throw new NotFound(__('Teilaufgabe nicht gefunden.', 'bootspruefung-learning'));
        }
        $this->requireNavigationTask($userId, $course, (int) $q->task_id);
        if ($assessment !== null && ! in_array($assessment, ['correct', 'partly', 'wrong'], true)) {
            throw new RuntimeException(__('Ungültige Selbsteinschätzung.', 'bootspruefung-learning'));
        }
        // NULL als SQL-Literal (prepare('%s', null) ergäbe '' und würde gespeicherte Werte überschreiben).
        $answerSql = $answer === null ? 'NULL' : $this->db->prepare('%s', mb_substr($answer, 0, 5000));
        $assessmentSql = $assessment === null ? 'NULL' : $this->db->prepare('%s', $assessment);
        // Werte oben einzeln vorbereitet, IDs als int – kein zweites prepare() über Nutzertext (wegen „%“).
        $this->db->query(
            "INSERT INTO {$this->t('navigation_progress')} (user_id, navigation_question_id, answer_text, self_assessment, done) VALUES (".(int) $userId.', '.(int) $questionId.", {$answerSql}, {$assessmentSql}, ".($assessment !== null ? 1 : 0).')
             ON DUPLICATE KEY UPDATE answer_text = COALESCE(VALUES(answer_text), answer_text), self_assessment = COALESCE(VALUES(self_assessment), self_assessment), done = GREATEST(done, VALUES(done))'
        );

        return ['answer' => $q->answer_text, 'self_assessment' => $assessment, 'done' => $assessment !== null];
    }

    // ------------------------------------------------------------------ Praxis

    /** @return array<string,mixed> */
    public function praxis(int $userId, object $course): array
    {
        $this->require($userId, $course, AccessService::PRAXIS);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT p.id, p.category, p.subcategory, p.exam_reference, p.task_type, p.question, p.answer, p.explanation, p.source_text, p.source_url,
                    m.storage_key, m.alt_text, COALESCE(om.value, m.rights_status) AS media_rights, pp.status AS progress
             FROM {$this->t('praxis_task')} p ".Overrides::rightsJoin('praxis_task', 'p', 'o')."
             LEFT JOIN {$this->t('media')} m ON m.id = p.media_id ".Overrides::rightsJoin('media', 'm', 'om')."
             LEFT JOIN {$this->t('praxis_progress')} pp ON pp.praxis_task_id = p.id AND pp.user_id = %d
             WHERE p.course_id = %d AND p.status = 'published' AND p.active = 1 AND COALESCE(o.value, p.rights_status) NOT IN (".Overrides::blockingList().')
             ORDER BY p.sort_order',
            $userId,
            $course->id
        ));
        $categories = [];
        $limit = $this->access->limit($userId, $course->code, AccessService::PRAXIS);
        foreach ($rows as $i => $r) {
            $categories[$r->category] ??= ['name' => $r->category, 'cards' => [], 'known' => 0];
            if ($limit !== null && $i >= $limit) {
                // Teilumfang: Karte nur als gesperrter Titel.
                $categories[$r->category]['cards'][] = ['id' => (int) $r->id, 'title' => $r->subcategory, 'locked' => true];
                continue;
            }
            $image = $r->storage_key && ! in_array($r->media_rights, Overrides::BLOCKING_RIGHTS, true) ? ['url' => MediaStorage::url($r->storage_key), 'alt' => $r->alt_text ?: (string) $r->subcategory] : null;
            $categories[$r->category]['cards'][] = [
                'id' => (int) $r->id,
                'title' => $r->subcategory,
                'reference' => $r->exam_reference,
                'type' => $r->task_type,
                'question' => $r->question,
                'answer' => $r->answer,
                'explanation' => $r->explanation,
                'image' => $image,
                'source' => $r->source_text,
                'source_url' => $r->source_url,
                'status' => $r->progress,
                'locked' => false,
            ];
            $categories[$r->category]['known'] += $r->progress === 'known' ? 1 : 0;
        }

        return ['categories' => array_values($categories), 'total' => count($rows), 'known' => array_sum(array_column($categories, 'known'))];
    }

    public function praxisStatus(int $userId, object $course, int $taskId, string $status): void
    {
        $this->require($userId, $course, AccessService::PRAXIS);
        if (! in_array($status, ['known', 'practice', 'open'], true)) {
            throw new RuntimeException(__('Ungültiger Status.', 'bootspruefung-learning'));
        }
        $exists = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t('praxis_task')} WHERE id = %d AND course_id = %d AND status = 'published'", $taskId, $course->id));
        if (! $exists) {
            throw new NotFound(__('Praxiskarte nicht gefunden.', 'bootspruefung-learning'));
        }
        if ($this->access->limit($userId, $course->code, AccessService::PRAXIS) !== null) {
            foreach ($this->praxis($userId, $course)['categories'] as $cat) {
                foreach ($cat['cards'] as $card) {
                    if ($card['id'] === $taskId && $card['locked']) {
                        throw new AccessDenied(__('Diese Karte ist im kostenlosen Umfang nicht enthalten.', 'bootspruefung-learning'));
                    }
                }
            }
        }
        $this->db->query($this->db->prepare(
            "INSERT INTO {$this->t('praxis_progress')} (user_id, praxis_task_id, status) VALUES (%d, %d, %s) ON DUPLICATE KEY UPDATE status = VALUES(status)",
            $userId,
            $taskId,
            $status
        ));
    }

    // ------------------------------------------------------------------

    /** Überblick für die Kursseite. @return array<string,mixed> */
    public function summary(int $userId, object $course): array
    {
        $nav = $this->access->can($userId, $course->code, AccessService::NAVIGATION) ? $this->navigationTasks($userId, $course) : [];
        $praxis = $this->access->can($userId, $course->code, AccessService::PRAXIS) ? $this->praxis($userId, $course) : ['total' => 0, 'known' => 0];

        return [
            'navigation' => ['tasks' => count($nav), 'questions' => array_sum(array_column($nav, 'questions')), 'done' => array_sum(array_column($nav, 'done'))],
            'praxis' => ['cards' => $praxis['total'], 'known' => $praxis['known']],
        ];
    }

    private function require(int $userId, object $course, string $feature): void
    {
        if (! $this->access->can($userId, $course->code, $feature)) {
            throw new AccessDenied(__('Dieser Trainer ist für deinen Kurs nicht freigeschaltet.', 'bootspruefung-learning'));
        }
    }
}
