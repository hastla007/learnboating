<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;

/**
 * Videokurs (Kap. 4.8, 6.4): Kapitel und Lektionen je Kurs, Sehfortschritt
 * (abgeschlossen, letzte Position), Schrittgalerien und Videoquiz mit Fragen
 * der zentralen Fragenbank (ausgewertet über den Lernmodus, Kontext „video“).
 * Platzhalter-Lektionen (is_placeholder) bleiben als Lektionen erhalten und
 * zählen mit, liefern aber keine Video-URL aus: das hinterlegte Platzhalter-
 * video liegt bei Dritten, und der Browser würde dorthin die IP-Adresse der
 * Nutzer übertragen. Quiz und Schrittgalerie bleiben nutzbar.
 */
final class VideoService
{
    private \wpdb $db;

    public function __construct(
        private readonly AccessService $access,
        private readonly ContentRepository $content,
        private readonly QuestionPresenter $presenter,
    ) {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** @return array<string,mixed> */
    public function course(int $userId, object $course): array
    {
        $this->require($userId, $course);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT m.id AS module_id, m.source_id AS module_key, m.title AS module_title, m.group_label, l.id, l.title, l.duration_seconds, l.is_placeholder,
                    COALESCE(vp.completed, 0) AS completed, COALESCE(vp.position_seconds, 0) AS position,
                    (SELECT COUNT(*) FROM {$this->t('video_lesson_question')} vq WHERE vq.lesson_id = l.id) AS quiz,
                    (SELECT COUNT(*) FROM {$this->t('video_lesson_step')} vs WHERE vs.lesson_id = l.id) AS steps
             FROM {$this->t('video_module')} m ".Overrides::rightsJoin('video_module', 'm', 'o')."
             JOIN {$this->t('video_lesson')} l ON l.module_id = m.id AND l.status <> 'source_archived'
             LEFT JOIN {$this->t('video_progress')} vp ON vp.lesson_id = l.id AND vp.user_id = %d
             WHERE m.course_id = %d AND m.status = 'published' AND COALESCE(o.value, m.rights_status) NOT IN (".Overrides::blockingList().')
             ORDER BY m.sort_order, l.sort_order',
            $userId,
            $course->id
        ));
        $modules = [];
        $next = null;
        $limit = $this->access->limit($userId, $course->code, AccessService::VIDEO);
        $now = current_time('mysql', true);
        $index = 0;
        foreach ($rows as $r) {
            if (! isset($modules[(int) $r->module_id])) {
                $at = $this->access->unlockAt($userId, $course->code, AccessService::VIDEO, (string) $r->module_key);
                $modules[(int) $r->module_id] = ['id' => (int) $r->module_id, 'title' => $r->module_title, 'group' => $r->group_label, 'lessons' => [],
                    'locked' => $at !== null && $at > $now, 'unlock_at' => $at];
            }
            $locked = $modules[(int) $r->module_id]['locked'] || ($limit !== null && $index >= $limit);
            $index++;
            $modules[(int) $r->module_id]['lessons'][] = [
                'locked' => $locked,
                'id' => (int) $r->id,
                'title' => $r->title,
                'duration' => $r->duration_seconds === null ? null : (int) $r->duration_seconds,
                'placeholder' => (bool) $r->is_placeholder,
                'completed' => (bool) $r->completed,
                'position' => (int) $r->position,
                'quiz' => (int) $r->quiz,
                'steps' => (int) $r->steps,
            ];
            // Nächste Lektion: erste nicht abgeschlossene außerhalb des Knoten-Kapitels (wie VideoCourseLessons der Quelle).
            if ($next === null && ! $locked && ! $r->completed && $r->module_title !== 'Knoten') {
                $next = (int) $r->id;
            }
        }
        $lessons = array_merge(...array_map(static fn ($m) => $m['lessons'], array_values($modules)) ?: [[]]);

        return [
            'modules' => array_values($modules),
            'lessons' => count($lessons),
            'completed' => count(array_filter($lessons, static fn ($l) => $l['completed'])),
            'next_lesson' => $next,
        ];
    }

    /** @return array<string,mixed> */
    public function lesson(int $userId, object $course, int $lessonId): array
    {
        $this->require($userId, $course);
        $lesson = $this->lessonRow($course, $lessonId);
        $this->requireUnlocked($userId, $course, $lessonId);
        $steps = $this->db->get_results($this->db->prepare("SELECT title, sort_order FROM {$this->t('video_lesson_step')} WHERE lesson_id = %d ORDER BY sort_order", $lessonId));
        $quiz = [];
        // Fragen einer laufenden Prüfung nicht im Videoquiz zeigen (Auswertung würde die Lösung verraten).
        $inExam = array_flip(array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT sq.question_id FROM {$this->t('exam_session')} s JOIN {$this->t('exam_session_question')} sq ON sq.session_id = s.id
             WHERE s.user_id = %d AND s.status = 'running' AND (s.deadline_at IS NULL OR s.deadline_at > %s)",
            $userId, current_time('mysql', true)
        ))));
        foreach ($this->db->get_results($this->db->prepare(
            "SELECT q.id FROM {$this->t('video_lesson_question')} vq JOIN {$this->t('question')} q ON q.id = vq.question_id WHERE vq.lesson_id = %d ORDER BY vq.sort_order",
            $lessonId
        )) as $row) {
            if (isset($inExam[(int) $row->id]) || ! $this->content->isDeliverable((int) $row->id)) {
                continue;
            }
            $revisionId = (int) $this->db->get_var($this->db->prepare("SELECT current_revision_id FROM {$this->t('question')} WHERE id = %d", $row->id));
            $presented = $this->presenter->present($revisionId);
            $presented['token'] = $this->presenter->token($userId, $revisionId, $presented['answer_order'], 'video:'.$course->code);
            unset($presented['answer_order']);
            $quiz[] = $presented;
        }
        $progress = $this->db->get_row($this->db->prepare("SELECT completed, position_seconds FROM {$this->t('video_progress')} WHERE user_id = %d AND lesson_id = %d", $userId, $lessonId));
        $siblings = $this->course($userId, $course);
        $flat = [];
        foreach ($siblings['modules'] as $m) {
            foreach ($m['lessons'] as $l) {
                $flat[] = $l['id'];
            }
        }
        $pos = array_search($lessonId, $flat, true);
        $this->touch($userId, $lessonId);

        return [
            'id' => (int) $lesson->id,
            'title' => $lesson->title,
            'module' => $lesson->module_title,
            'video_url' => $lesson->is_placeholder ? null : ($lesson->video_url ?: null),
            'placeholder' => (bool) $lesson->is_placeholder,
            'duration' => $lesson->duration_seconds === null ? null : (int) $lesson->duration_seconds,
            'completed' => (bool) ($progress->completed ?? false),
            'position' => (int) ($progress->position_seconds ?? 0),
            'steps' => array_map(static fn ($s) => ['title' => $s->title, 'number' => (int) $s->sort_order], $steps),
            'quiz' => $quiz,
            'previous' => $pos > 0 ? $flat[$pos - 1] : null,
            'next' => $pos !== false && isset($flat[$pos + 1]) ? $flat[$pos + 1] : null,
        ];
    }

    public function progress(int $userId, object $course, int $lessonId, int $position, ?bool $completed): array
    {
        $this->require($userId, $course);
        $this->lessonRow($course, $lessonId);
        $this->requireUnlocked($userId, $course, $lessonId);
        $this->db->query($this->db->prepare(
            "INSERT INTO {$this->t('video_progress')} (user_id, lesson_id, completed, position_seconds, last_access_at) VALUES (%d, %d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE position_seconds = VALUES(position_seconds), last_access_at = VALUES(last_access_at)".($completed === null ? '' : ', completed = VALUES(completed)'),
            $userId,
            $lessonId,
            $completed ? 1 : 0,
            max(0, min($position, 86400)),
            current_time('mysql', true)
        ));

        return ['completed' => (bool) $this->db->get_var($this->db->prepare("SELECT completed FROM {$this->t('video_progress')} WHERE user_id = %d AND lesson_id = %d", $userId, $lessonId))];
    }

    private function touch(int $userId, int $lessonId): void
    {
        $this->db->query($this->db->prepare(
            "INSERT INTO {$this->t('video_progress')} (user_id, lesson_id, last_access_at) VALUES (%d, %d, %s) ON DUPLICATE KEY UPDATE last_access_at = VALUES(last_access_at)",
            $userId,
            $lessonId,
            current_time('mysql', true)
        ));
    }

    private function lessonRow(object $course, int $lessonId): object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT l.*, m.title AS module_title FROM {$this->t('video_lesson')} l JOIN {$this->t('video_module')} m ON m.id = l.module_id
             ".Overrides::rightsJoin('video_module', 'm', 'o')."
             WHERE l.id = %d AND m.course_id = %d AND l.status <> 'source_archived' AND m.status = 'published' AND COALESCE(o.value, m.rights_status) NOT IN (".Overrides::blockingList().')',
            $lessonId,
            $course->id
        ));
        if (! $row) {
            throw new NotFound(__('Lektion nicht gefunden.', 'bootspruefung-learning'));
        }

        return $row;
    }

    /** Teilumfang (erste n Lektionen) und zeitgesteuerte Kapitel. */
    private function requireUnlocked(int $userId, object $course, int $lessonId): void
    {
        foreach ($this->course($userId, $course)['modules'] as $module) {
            foreach ($module['lessons'] as $lesson) {
                if ($lesson['id'] === $lessonId && $lesson['locked']) {
                    throw new AccessDenied($module['locked'] && $module['unlock_at']
                        ? sprintf(__('Dieses Kapitel wird am %s freigeschaltet.', 'bootspruefung-learning'), wp_date(get_option('date_format'), strtotime($module['unlock_at'].' UTC')))
                        : __('Diese Lektion ist im kostenlosen Umfang nicht enthalten.', 'bootspruefung-learning'));
                }
            }
        }
    }

    private function require(int $userId, object $course): void
    {
        if (! $this->access->can($userId, $course->code, AccessService::VIDEO)) {
            throw new AccessDenied(__('Der Videokurs ist für deinen Kurs nicht freigeschaltet.', 'bootspruefung-learning'));
        }
    }
}
