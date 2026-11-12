<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Gate\Charts;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Mapper\CourseMapper;
use Bootspruefung\Learning\Mobile\Mapper\MediaMapper;
use Bootspruefung\Learning\Mobile\Mapper\QuestionMapper;
use Bootspruefung\Learning\Trainers\TrainerService;
use Bootspruefung\Learning\Trainers\VideoService;
use WP_REST_Request;
use WP_REST_Response;

/** Videokurs, Navigations- und Praxistrainer über VideoService und TrainerService (wie im Web). */
final class TrainingController extends BaseController
{
    private VideoService $videos;
    private TrainerService $trainers;

    public function __construct()
    {
        parent::__construct();
        $this->videos = new VideoService($this->access, $this->content, $this->presenter);
        $this->trainers = new TrainerService($this->access);
    }

    // ------------------------------------------------------------------ Videos

    public function videoModules(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::VIDEO);
        $overview = $this->videos->course($this->user(), $course);
        $modules = [];
        foreach ($overview['modules'] as $i => $m) {
            $modules[] = [
                'id' => (string) $m['id'],
                'title' => (string) $m['title'],
                'group' => $m['group'],
                'order' => $i + 1,
                'locked' => (bool) $m['locked'],
                'unlock_at' => CourseMapper::iso($m['unlock_at']),
                'lessons' => array_map(fn ($l) => $this->lessonSummary($l, (string) $m['title']), $m['lessons']),
            ];
        }

        return $this->ok($modules, 200, [
            'course_id' => (string) $course->id,
            'lessons' => (int) $overview['lessons'],
            'completed' => (int) $overview['completed'],
            'next_lesson_id' => $overview['next_lesson'] !== null ? (string) $overview['next_lesson'] : null,
        ]);
    }

    public function video(WP_REST_Request $request): WP_REST_Response
    {
        [$course, $lessonId] = $this->lessonCourse((int) $request['lessonId']);
        $this->requireFeature($course, AccessService::VIDEO);
        $l = $this->videos->lesson($this->user(), $course, $lessonId);

        return $this->ok([
            'id' => (string) $l['id'],
            'course_id' => (string) $course->id,
            'title' => (string) $l['title'],
            'module' => (string) $l['module'],
            'description' => null,
            'duration_seconds' => $l['duration'] !== null ? (int) $l['duration'] : 0,
            'media' => MediaMapper::video($l['video_url'], (bool) $l['placeholder'], $l['duration'], (string) $l['title']),
            'completed' => (bool) $l['completed'],
            'position_seconds' => (int) $l['position'],
            'steps' => array_map(static fn ($s) => [
                'id' => (string) $s['number'],
                'order' => (int) $s['number'],
                'title' => (string) $s['title'],
                'description' => null,
                // Schrittbilder sind (noch) nicht hinterlegt – sichtbarer Platzhalter statt leerer Kachel.
                'media' => [MediaMapper::placeholder('image', __('Bild folgt', 'bootspruefung-learning'))],
            ], $l['steps']),
            // Videoquiz: Antworten über POST /courses/{courseId}/attempts mit dem answer_token.
            'quiz' => array_map(static fn ($q) => ['question' => QuestionMapper::question($q), 'answer_token' => (string) $q['token']], $l['quiz']),
            'previous_lesson_id' => $l['previous'] !== null ? (string) $l['previous'] : null,
            'next_lesson_id' => $l['next'] !== null ? (string) $l['next'] : null,
            'next_locked' => (bool) $l['next_locked'],
        ]);
    }

    public function videoProgress(WP_REST_Request $request): WP_REST_Response
    {
        [$course, $lessonId] = $this->lessonCourse((int) $request['lessonId']);
        $this->requireFeature($course, AccessService::VIDEO);
        $position = (int) $request['position_seconds'];
        $completed = $request['completed'] === null ? null : (bool) $request['completed'];
        $r = $this->videos->progress($this->user(), $course, $lessonId, $position, $completed);

        return $this->ok(['lesson_id' => (string) $lessonId, 'position_seconds' => max(0, min($position, 86400)), 'completed' => (bool) $r['completed']]);
    }

    /** @param array<string,mixed> $l @return array<string,mixed> */
    private function lessonSummary(array $l, string $module): array
    {
        return [
            'id' => (string) $l['id'],
            'title' => (string) $l['title'],
            'module' => $module,
            'duration_seconds' => $l['duration'] !== null ? (int) $l['duration'] : 0,
            // Übersicht ohne URL: die Lektion liefert die abspielbare Adresse (nach Freigabeprüfung).
            'media' => $l['placeholder'] ? MediaMapper::placeholder('video', __('Video folgt', 'bootspruefung-learning'), $l['duration']) : ['kind' => 'video', 'available' => true, 'label' => (string) $l['title'], 'url' => null, 'mime_type' => null, 'duration_seconds' => $l['duration'], 'placeholder_reason' => null],
            'completed' => (bool) $l['completed'],
            'position_seconds' => (int) $l['position'],
            'locked' => (bool) $l['locked'],
            'quiz_count' => (int) $l['quiz'],
            'step_count' => (int) $l['steps'],
        ];
    }

    /** @return array{0:object,1:int} Kurs der Lektion */
    private function lessonCourse(int $lessonId): array
    {
        global $wpdb;
        $courseId = $lessonId > 0 ? $wpdb->get_var($wpdb->prepare(
            "SELECT m.course_id FROM {$wpdb->prefix}bp_video_lesson l JOIN {$wpdb->prefix}bp_video_module m ON m.id = l.module_id WHERE l.id = %d",
            $lessonId
        )) : null;
        if ($courseId === null) {
            throw ApiException::notFound(__('Lektion nicht gefunden.', 'bootspruefung-learning'));
        }

        return [$this->courseById((int) $courseId), $lessonId];
    }

    // ------------------------------------------------------------------ Navigation

    public function navigationTasks(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::NAVIGATION);
        $tasks = array_map(static fn ($t) => [
            'id' => (string) $t['id'],
            'number' => (int) $t['number'],
            'title' => sprintf(__('Aufgabe %d', 'bootspruefung-learning'), (int) $t['number']),
            'description' => $t['teaser'] !== null ? CourseMapper::text($t['teaser']) : null,
            'part_count' => (int) $t['questions'],
            'done_count' => (int) $t['done'],
            'locked' => (bool) $t['locked'],
            'correction_notice' => null,
        ], $this->trainers->navigationTasks($this->user(), $course));
        [$page, $pagination] = self::paginate($tasks, $request);

        return $this->ok($page, 200, ['pagination' => $pagination, 'course_id' => (string) $course->id, 'charts' => $this->charts($course)]);
    }

    public function navigationTask(WP_REST_Request $request): WP_REST_Response
    {
        $taskId = (int) $request['taskId'];
        $course = $this->taskCourse($taskId, $request['course_id'] !== null && $request['course_id'] !== '' ? (int) $request['course_id'] : null);
        $this->requireFeature($course, AccessService::NAVIGATION);
        $task = $this->trainers->navigationTask($this->user(), $course, $taskId);
        if (filter_var($request['show_solution'], FILTER_VALIDATE_BOOLEAN)) {
            // Wie „Lösung anzeigen“ im Web: Teilaufgaben ohne eigene Antwort aufdecken.
            foreach ($task['questions'] as $q) {
                if ($q['answer'] === null) {
                    $this->trainers->navigationAnswer($this->user(), $course, (int) $q['id'], null, null);
                }
            }
            $task = $this->trainers->navigationTask($this->user(), $course, $taskId);
        }

        return $this->ok($this->taskPayload($course, $task));
    }

    /** Eigene Antwort speichern und/oder Selbsteinschätzung (correct/partly/wrong); liefert die amtliche Lösung. */
    public function navigationAnswer(WP_REST_Request $request): WP_REST_Response
    {
        $taskId = (int) $request['taskId'];
        $course = $this->taskCourse($taskId, $request['course_id'] !== null && $request['course_id'] !== '' ? (int) $request['course_id'] : null);
        $this->requireFeature($course, AccessService::NAVIGATION);
        $partId = (int) $request['partId'];
        global $wpdb;
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT task_id FROM {$wpdb->prefix}bp_navigation_question WHERE id = %d", $partId)) !== $taskId) {
            throw ApiException::notFound(__('Teilaufgabe nicht gefunden.', 'bootspruefung-learning'));
        }
        $answer = $request['answer'] !== null ? (string) $request['answer'] : null;
        $assessment = $request['self_assessment'] !== null && $request['self_assessment'] !== '' ? (string) $request['self_assessment'] : null;
        $r = $this->trainers->navigationAnswer($this->user(), $course, $partId, $answer, $assessment);

        return $this->ok([
            'part_id' => (string) $partId,
            'solution' => $r['answer'],
            'my_answer' => $r['my_answer'],
            'self_assessment' => $r['self_assessment'],
            'done' => (bool) $r['done'],
            // Seit 1.4.0: automatische Prüfung der eigenen Werte (Hilfe; die Selbsteinschätzung bleibt maßgeblich).
            'auto_check' => (bool) $r['auto_check'],
            'check' => $r['check'],
        ]);
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function taskPayload(object $course, array $task): array
    {
        $parts = array_map(static fn ($q) => [
            'id' => (string) $q['id'],
            'number' => (int) $q['number'],
            'prompt' => (string) $q['text'],
            'media' => [],
            'solution' => $q['answer'],
            'solution_revealed' => $q['answer'] !== null,
            'my_answer' => $q['my_answer'],
            'self_assessment' => $q['self_assessment'],
            'done' => (bool) $q['done'],
            'auto_check' => (bool) $q['auto_check'],
            'check' => $q['check'],
            'chart_entry' => (bool) $q['chart_entry'],
        ], $task['questions']);

        return [
            'id' => (string) $task['id'],
            'course_id' => (string) $course->id,
            'number' => (int) $task['number'],
            'title' => sprintf(__('Aufgabe %d', 'bootspruefung-learning'), (int) $task['number']),
            'description' => CourseMapper::text($task['scenario']),
            'scenario' => (string) $task['scenario'],
            'hint' => $task['hint'],
            'source' => $task['source'],
            'part_count' => count($parts),
            'correction_notice' => null,
            'parts' => $parts,
            'score' => $task['score'],
            'previous_task_id' => $task['previous'] !== null ? (string) $task['previous'] : null,
            'previous_locked' => (bool) $task['previous_locked'],
            'next_task_id' => $task['next'] !== null ? (string) $task['next'] : null,
            'next_locked' => (bool) $task['next_locked'],
            'charts' => $this->charts($course),
        ];
    }

    /** @return list<array<string,mixed>> Seekarten (nur mit vollem Navigationszugang abrufbar) */
    private function charts(object $course): array
    {
        return array_map(static fn ($c) => ['code' => (string) $c['code'], 'title' => (string) $c['title']] + CourseController::fileUrl($c['url'] ?? null), Charts::forCourse($this->user(), (string) $course->code));
    }

    /**
     * Kurs einer Navigationsaufgabe (Aufgaben gehören teils zu mehreren Kursen): angegebener Kurs
     * oder der erste Kurs der Aufgabe mit freigeschaltetem Navigationstrainer.
     */
    private function taskCourse(int $taskId, ?int $courseId): object
    {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT course_id FROM {$wpdb->prefix}bp_navigation_task_course WHERE task_id = %d ORDER BY course_id", $taskId)));
        $courses = array_values(array_filter(array_map(fn ($id) => $this->publishedCourses()[$id] ?? null, $ids)));
        if ($courses === [] || ($courseId !== null && ! in_array($courseId, $ids, true))) {
            throw ApiException::notFound(__('Navigationsaufgabe nicht gefunden.', 'bootspruefung-learning'));
        }
        if ($courseId !== null) {
            return $this->courseById($courseId);
        }
        foreach ($courses as $course) {
            if ($this->access->can($this->user(), (string) $course->code, AccessService::NAVIGATION)) {
                return $course;
            }
        }

        return $courses[0];
    }

    // ------------------------------------------------------------------ Praxis

    public function praxis(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::PRAXIS);
        $p = $this->trainers->praxis($this->user(), $course);
        $categories = array_map(static fn ($cat) => [
            'name' => (string) $cat['name'],
            'known_count' => (int) $cat['known'],
            'cards' => array_map(static fn ($card) => $card['locked'] ? ['id' => (string) $card['id'], 'title' => $card['title'], 'locked' => true] : [
                'id' => (string) $card['id'],
                'title' => $card['title'],
                'locked' => false,
                'reference' => $card['reference'],
                'type' => $card['type'],
                'question' => $card['question'],
                'answer' => $card['answer'],
                'explanation' => $card['explanation'],
                'media' => $card['image'] !== null ? [MediaMapper::image(['url' => (string) $card['image']['url'], 'alt' => (string) $card['image']['alt'], 'caption' => null])] : [],
                'source' => $card['source'],
                'source_url' => $card['source_url'],
                'status' => $card['status'] ?? 'open',
            ], $cat['cards']),
        ], $p['categories']);

        return $this->ok($categories, 200, ['total' => (int) $p['total'], 'known' => (int) $p['known']]);
    }

    public function praxisStatus(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::PRAXIS);
        $this->trainers->praxisStatus($this->user(), $course, (int) $request['cardId'], (string) $request['status']);

        return $this->ok(['card_id' => (string) (int) $request['cardId'], 'status' => (string) $request['status']]);
    }
}
