<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Idempotency;
use Bootspruefung\Learning\Mobile\Mapper\CourseMapper;
use Bootspruefung\Learning\Mobile\Mapper\QuestionMapper;
use Bootspruefung\Learning\Mobile\TokenAuth;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Prüfungssimulation ausschließlich über ExamService (Snapshot, Serverzeit, Teilgrenzen,
 * Teilumfang/Drip-Sperren, verkürzte Übungsprüfung „reduced“, Auswertung).
 * Fragen einer laufenden Prüfung werden über ihre Position (1…n) adressiert – wie im Web,
 * das während der Prüfung keine Fragen-IDs ausliefert.
 */
final class ExamController extends BaseController
{
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::EXAM);
        $o = $this->exams->overview($user, $course, true);
        $options = [];
        if ($o['available'] && $o['rule'] !== null) {
            $last = null;
            foreach ($o['history'] as $h) {
                if ($h['paper_id'] === null) {
                    $last = $h;
                    break;
                }
            }
            $options[] = [
                'id' => 'simulation',
                'kind' => 'rule_set',
                'name' => __('Prüfungssimulation (zufällige Fragen)', 'bootspruefung-learning'),
                'paper_number' => null,
                'question_count' => (int) $o['rule']['questions'],
                'duration_seconds' => (int) $o['rule']['time_limit_seconds'] > 0 ? (int) $o['rule']['time_limit_seconds'] : null,
                'pass_rule' => self::passRule((string) $o['rule']['passing'], $o['rule']['note'], $o['rule']['passing_parts'] ?? null),
                // Zufallsprüfung: Verteilung nur bekannt, wenn alle Fragen der Module denselben Typ haben; sonst null.
                'question_types' => isset($o['rule']['types']) ? (object) $o['rule']['types'] : null,
                'startable' => ! $o['simulation_locked'],
                'locked' => (bool) $o['simulation_locked'],
                'lock_reason' => $o['simulation_locked'] ? 'trial' : null,
                'unlock_at' => null,
                'running_exam_id' => $o['running_simulation'] !== null ? (string) $o['running_simulation'] : null,
                'last_result' => $last !== null ? self::lastResult($last['session_id'], 'evaluated', $last['score'], $last['max'], $last['passed'], $last['submitted_at']) : null,
            ];
        }
        foreach ($o['papers'] as $p) {
            $last = $p['last'];
            $options[] = [
                'id' => (string) $p['id'],
                'kind' => 'fixed_paper',
                'name' => sprintf(__('Prüfungsbogen %d', 'bootspruefung-learning'), (int) $p['number']),
                'paper_number' => (int) $p['number'],
                'question_count' => (int) $p['questions'],
                'duration_seconds' => $p['rule'] === null && $o['rule'] !== null && (int) $o['rule']['time_limit_seconds'] > 0 ? (int) $o['rule']['time_limit_seconds'] : null,
                'pass_rule' => self::passRule((string) ($p['rule'] ?? ($o['rule']['passing'] ?? '')), $o['rule']['note'] ?? null, $p['passing'] ?? null),
                'question_types' => (object) ($p['types'] ?? []),
                'startable' => ! $p['locked'] && $o['rule'] !== null,
                'locked' => (bool) $p['locked'],
                'lock_reason' => $p['lock_reason'],
                'unlock_at' => CourseMapper::iso($p['unlock_at']),
                'running_exam_id' => $last !== null && $last['status'] === 'running' ? (string) $last['session_id'] : null,
                'last_result' => $last !== null && $last['status'] === 'evaluated' ? self::lastResult($last['session_id'], 'evaluated', $last['score'], $last['max'], $last['passed'], null) : null,
            ];
        }

        return $this->ok($options, 200, [
            'available' => (bool) $o['available'],
            // live = verifiziertes, gültiges Regelwerk („offizielle“ Simulation), sonst Übungsmodus.
            'live' => (bool) $o['live'],
            'rule_set_version' => $o['rule']['version'] ?? null,
            'limit' => $o['limit'],
            'history' => array_map(static fn ($h) => self::lastResult($h['session_id'], 'evaluated', $h['score'], $h['max'], $h['passed'], $h['submitted_at']) + ['paper_id' => $h['paper_id'] !== null ? (string) $h['paper_id'] : null, 'paper_number' => $h['paper_number'] ?? null], (array) ($o['results'] ?? $o['history'])),
        ]);
    }

    public function start(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::EXAM);
        $paper = $request['paper_id'];
        $paperId = $paper === null || $paper === '' || $paper === 'simulation' ? null : (ctype_digit((string) $paper) ? (int) $paper : -1);
        if ($paperId === -1) {
            throw ApiException::validation(['paper_id' => [__('Unbekannter Prüfungsbogen.', 'bootspruefung-learning')]]);
        }
        [$status, $body] = Idempotency::run($user, 'exam-start:'.$course->id, $this->idempotencyKey($request, false), ['paper_id' => $paperId], function () use ($user, $course, $paperId): array {
            $id = $this->exams->start($user, $course, $paperId);

            return [201, ['data' => $this->session($id)], 'exam:'.$id];
        });

        return new WP_REST_Response($body, $status);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->ok($this->session((int) $request['examId']));
    }

    public function answer(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['examId'];
        $position = (int) $request['questionId'];
        $this->requireRunning($id);
        $selected = QuestionMapper::ids($request['selected_answer_ids']);
        $known = $request['known'] === null ? null : (bool) $request['known'];
        // Gleiche Serverlogik wie im Web: offline gepufferte Antworten bis zur Kulanz nach Fristende, idempotent,
        // ein älterer Stand überschreibt keinen neueren (applied = false).
        $state = $this->exams->saveAnswer($this->user(), $id, $position, $selected, $known, $request['age_ms'] === null ? null : (int) $request['age_ms'], $request['answered_at'] === null ? null : (string) $request['answered_at']);
        // Seit 1.3.0: marked_for_review (Konzept) = Markierung „zur Überprüfung“ (wie PUT …/flag).
        if ($request['marked_for_review'] !== null) {
            $this->exams->setFlag($this->user(), $id, $position, (bool) $request['marked_for_review']);
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT given_answer, self_assessed_known, answered_at, flagged FROM {$wpdb->prefix}bp_exam_session_question WHERE session_id = %d AND position = %d", $id, $position));
        $given = $row ? (json_decode((string) $row->given_answer, true) ?: []) : [];

        return $this->ok([
            'saved' => true,
            'applied' => (bool) $state['applied'],
            'question_id' => (string) $position,
            'selected_answer_ids' => array_map('strval', (array) ($given['answer_ids'] ?? [])),
            'known' => $row && $row->self_assessed_known !== null ? (bool) $row->self_assessed_known : null,
            'answered' => $row && $row->answered_at !== null,
            'marked_for_review' => $row && (bool) $row->flagged,
            'flagged' => $row && (bool) $row->flagged,
            'saved_at' => $row ? TokenAuth::iso($row->answered_at) : null,
        ]);
    }

    /**
     * Frage zur Überprüfung markieren bzw. Markierung entfernen (nur laufende Prüfung).
     * Adressierung wie bei answer(): Position 1…n. Ändert die Antwort nicht.
     */
    public function flag(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['examId'];
        $position = (int) $request['questionId'];
        $this->requireRunning($id);
        $flagged = (bool) $request['flagged'];
        $count = $this->exams->setFlag($this->user(), $id, $position, $flagged);

        return $this->ok(['question_id' => (string) $position, 'flagged' => $flagged, 'flagged_count' => $count]);
    }

    /** Musterantwort einer offenen Frage freigeben (danach Selbsteinschätzung möglich). */
    public function reveal(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['examId'];
        $this->requireRunning($id);

        return $this->ok(['question_id' => (string) (int) $request['questionId'], 'model_answer' => $this->exams->reveal($this->user(), $id, (int) $request['questionId'])]);
    }

    public function submit(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['examId'];
        $this->exams->submit($this->user(), $id);
        $view = $this->exams->show($this->user(), $id);

        return $this->ok(self::resultPayload($id, $view));
    }

    /** Laufende Prüfung ohne Wertung abbrechen (seit 1.11.0); eine abgelaufene wird stattdessen gewertet. */
    public function abort(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['examId'];
        $status = $this->exams->abort($this->user(), $id);
        $view = $this->exams->show($this->user(), $id);
        if ($status === 'aborted') {
            return $this->ok(['exam_id' => (string) $id, 'status' => 'aborted', 'aborted_at' => TokenAuth::iso($view['aborted_at'] ?? null), 'result' => null]);
        }

        return $this->ok(['exam_id' => (string) $id, 'status' => (string) $view['status'], 'aborted_at' => null, 'result' => self::resultPayload($id, $view)]);
    }

    public function result(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['examId'];
        $view = $this->exams->show($this->user(), $id);
        if ($view['status'] === 'aborted') {
            throw self::abortedError();
        }
        if ($view['status'] === 'running') {
            throw new ApiException('EXAM_NOT_SUBMITTED', __('Diese Prüfung läuft noch. Gib sie ab, um das Ergebnis zu sehen.', 'bootspruefung-learning'), 409);
        }

        return $this->ok(self::resultPayload($id, $view));
    }

    // ------------------------------------------------------------------

    /** Laufende Prüfung oder 404 (fremd/unbekannt), 409 (abgegeben), 410 (Zeit abgelaufen). */
    private function requireRunning(int $id): void
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, result FROM {$wpdb->prefix}bp_exam_session WHERE id = %d AND user_id = %d", $id, $this->user()));
        if (! $row) {
            throw ApiException::notFound(__('Prüfung nicht gefunden.', 'bootspruefung-learning'));
        }
        if ($row->status === 'aborted') {
            throw self::abortedError();
        }
        if ($row->status !== 'running') {
            $result = json_decode((string) $row->result, true) ?: [];
            if (! empty($result['expired'])) {
                throw new ApiException('EXAM_EXPIRED', __('Die Prüfungszeit ist abgelaufen. Deine bis dahin gespeicherten Antworten wurden gewertet.', 'bootspruefung-learning'), 410);
            }
            throw new ApiException('EXAM_ALREADY_SUBMITTED', __('Diese Prüfung ist bereits abgegeben.', 'bootspruefung-learning'), 409);
        }
    }

    private static function abortedError(): ApiException
    {
        return new ApiException('EXAM_ABORTED', __('Diese Prüfung wurde abgebrochen und nicht gewertet.', 'bootspruefung-learning'), 409);
    }

    /** @return array<string,mixed> ExamSession */
    private function session(int $id): array
    {
        $view = $this->exams->show($this->user(), $id); // prüft Eigentümer, wertet abgelaufene aus
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT course_id, paper_id, started_at, deadline_at FROM {$wpdb->prefix}bp_exam_session WHERE id = %d AND user_id = %d", $id, $this->user()));
        $base = [
            'exam_id' => (string) $id,
            'status' => (string) $view['status'],
            'course_id' => (string) $row->course_id,
            'course' => $view['course'],
            'mode' => (string) $view['mode'],
            'paper_id' => $row->paper_id !== null ? (string) $row->paper_id : null,
            'paper_number' => $view['paper_number'],
            'live' => (bool) $view['live'],
            'reduced' => (bool) $view['reduced'],
            'started_at' => TokenAuth::iso($row->started_at),
            'expires_at' => TokenAuth::iso($row->deadline_at),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            'time_limit_seconds' => (int) $view['rule']['time_limit_seconds'] > 0 ? (int) $view['rule']['time_limit_seconds'] : null,
            'pass_rule' => ['description' => (string) $view['rule']['passing'], 'note' => $view['rule']['note']],
        ];
        if ($view['status'] === 'aborted') {
            return $base + ['question_count' => 0, 'flagged_count' => 0, 'remaining_seconds' => 0, 'questions' => [], 'answers' => [], 'result' => null, 'aborted_at' => TokenAuth::iso($view['aborted_at'] ?? null)];
        }
        if ($view['status'] !== 'running') {
            $result = self::resultPayload($id, $view);

            return $base + ['question_count' => $result['question_count'], 'flagged_count' => $result['flagged_count'], 'remaining_seconds' => 0, 'questions' => [], 'answers' => [], 'result' => $result];
        }
        $questions = [];
        $answers = [];
        $flagged = 0;
        foreach ($view['questions'] as $q) {
            $pos = (string) $q['position'];
            $isFlagged = (bool) ($q['flagged'] ?? false);
            $flagged += $isFlagged ? 1 : 0;
            $questions[] = ['position' => (int) $q['position'], 'model_answer' => $q['model_answer'], 'flagged' => $isFlagged] + QuestionMapper::question($q + ['question_id' => $q['position']], $pos);
            $answers[] = [
                'question_id' => $pos,
                'selected_answer_ids' => array_map('strval', $q['selected_ids']),
                'known' => $q['known'],
                'answered' => (bool) $q['answered'],
                'flagged' => $isFlagged,
                'marked_for_review' => $isFlagged,
            ];
        }

        return $base + [
            'question_count' => count($questions),
            'flagged_count' => $flagged,
            'remaining_seconds' => $view['remaining_seconds'],
            'questions' => $questions,
            'answers' => $answers,
            'result' => null,
        ];
    }

    /** @param array<string,mixed> $view ExamService::show() einer ausgewerteten Prüfung @return array<string,mixed> ExamResult */
    private static function resultPayload(int $id, array $view): array
    {
        $r = $view['result'] ?? [];
        $review = [];
        $wrong = [];
        $flagged = 0;
        foreach ((array) ($r['questions'] ?? []) as $q) {
            if (! $q['correct']) {
                $wrong[] = (string) $q['question_id'];
            }
            $flagged += ! empty($q['flagged']) ? 1 : 0;
            $review[] = [
                'position' => (int) $q['position'],
                'question' => QuestionMapper::question($q),
                'selected_answer_ids' => array_map('strval', $q['selected_ids']),
                'correct_answer_ids' => array_map('strval', $q['correct_ids']),
                'model_answer' => $q['model_answer'],
                'known' => $q['known'],
                'answered' => (bool) $q['answered'],
                'correct' => (bool) $q['correct'],
                'flagged' => (bool) ($q['flagged'] ?? false),
                'explanation' => $q['explanation'],
            ];
        }
        $topics = [];
        foreach ((array) ($r['topics'] ?? []) as $topic => $t) {
            $topics[] = ['topic' => (string) $topic, 'correct_count' => (int) $t['correct'], 'question_count' => (int) $t['total']];
        }
        $max = (int) ($r['max'] ?? 0);

        return [
            'exam_id' => (string) $id,
            'status' => 'evaluated',
            'passed' => (bool) ($r['passed'] ?? false),
            'score' => $r['ratio'] !== null ? (float) $r['ratio'] : ($max > 0 ? round((int) $r['score'] / $max, 4) : 0.0),
            'correct_count' => (int) ($r['score'] ?? 0),
            'question_count' => $max,
            'evaluated_at' => TokenAuth::iso($r['submitted_at'] ?? null),
            'expired' => (bool) ($r['expired'] ?? false),
            'reduced' => (bool) ($r['reduced'] ?? false),
            'live' => (bool) ($view['live'] ?? false),
            'rule_set_version' => null,
            'pass_rule' => ['description' => (string) ($view['rule']['passing'] ?? ''), 'note' => $view['rule']['note'] ?? null],
            'parts' => array_map(static fn ($p) => [
                'label' => (string) $p['label'], 'correct_count' => (int) $p['correct'], 'question_count' => (int) $p['total'],
                'minimum_correct' => (int) $p['min_correct'], 'of' => (int) $p['of'], 'passed' => (bool) $p['passed'], 'empty' => (bool) $p['empty'],
            ], (array) ($r['parts'] ?? [])),
            'by_topic' => $topics,
            'open_text' => ['question_count' => (int) ($r['open_text']['total'] ?? 0), 'correct_count' => (int) ($r['open_text']['correct'] ?? 0)],
            'wrong_question_ids' => $wrong,
            'flagged_count' => $flagged,
            'practice_mistakes' => $wrong !== [] ? ['mode' => 'exam_mistakes', 'exam_id' => (string) $id] : null,
            'review' => $review,
        ];
    }

    /** @return array<string,mixed> */
    private static function lastResult(int $sessionId, string $status, ?int $score, ?int $max, ?bool $passed, ?string $at): array
    {
        return [
            'exam_id' => (string) $sessionId,
            'status' => $status,
            'passed' => (bool) $passed,
            'score' => $max ? round((int) $score / $max, 4) : 0.0,
            'correct_count' => (int) $score,
            'question_count' => (int) $max,
            'evaluated_at' => TokenAuth::iso($at),
        ];
    }

    /**
     * Bestehensregel: Text wie im Web plus strukturierte Grenzen (seit 1.6.0). Mit Prüfungsteilen
     * (z. B. Basis 5/7, See 18/23) steht die Mindestzahl je Teil in parts, sonst gesamt in minimum_correct.
     * @param ?array{parts:list<array{label:string,minimum_correct:int,of:int}>,minimum_correct:?int} $structure
     * @return array<string,mixed>
     */
    private static function passRule(string $description, ?string $note, ?array $structure): array
    {
        return [
            'description' => $description,
            'note' => $note,
            'minimum_correct' => $structure['minimum_correct'] ?? null,
            'minimum_score' => null,
            'parts' => $structure['parts'] ?? [],
        ];
    }
}
