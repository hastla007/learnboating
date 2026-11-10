<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Exams\Services;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Prüfungssimulation (Kap. 4.5, 6.3), fachlich wie die Quelle
 * (ExamController, ExamEvaluationService, ExamRuleSet::partResults):
 * - Start speichert einen Snapshot (Regelwerk, Fragen, Revisionen, Antwortreihenfolge);
 * - zufällige Simulation nach Blueprint oder fester Bogen; ein laufender Versuch
 *   desselben Bogens wird fortgesetzt statt dupliziert;
 * - keine Lösungen während der Prüfung; offene Fragen erst nach „Musterantwort
 *   anzeigen“ selbst einschätzbar;
 * - Zeit und Bewertung serverseitig; Antworten nach Fristende zählen nicht;
 * - Bestehen = Gesamtquote und alle Teilgrenzen (z. B. Basis 5/7, See 18/23); die Zuordnung
 *   Frage → Teil wird beim Start im Snapshot festgehalten (spätere Modul-Änderungen ändern
 *   keine Ergebnisse); ein Teil ohne Fragen wird nicht gewertet;
 * - feste Bögen und Zufallsprüfung respektieren die zeitgesteuerte Freigabe (Drip) der Module;
 * - reicht der Fragenpool nicht für den Blueprint, läuft die Zufallsprüfung verkürzt als
 *   Übungsmodus (reduced) statt still als „offizielle“ Simulation.
 * Ohne verifiziertes, gültiges Regelwerk läuft die Simulation als Übungsmodus.
 */
final class ExamService
{
    private const GRACE_SECONDS = 5;

    private \wpdb $db;

    /** @var array<string,array{0:array<string,int>,1:array<int,list<int>>}> Modul-IDs und Fragen je Teil-Modulcodes (je Anfrage) */
    private array $partModules = [];

    public function __construct(
        private readonly ContentRepository $content,
        private readonly QuestionPresenter $presenter,
        private readonly AccessService $access,
    ) {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** Übersicht für die Kursseite. @return array<string,mixed> */
    /** @param bool $withResults alle ausgewerteten Versuche mitliefern (nur Prüfungsseite) */
    public function overview(int $userId, object $course, bool $withResults = false): array
    {
        $this->partModules = []; // Zwischenspeicher gilt je Vorgang
        $this->expireRunning($userId);
        ['rule' => $rule, 'live' => $live] = $this->content->examRule((int) $course->id);
        $papers = [];
        $limit = $this->access->limit($userId, $course->code, AccessService::EXAM);
        // Kombis: je Bogen die geltenden Teile und Zeit (See-Bogen 60 Min., Binnen-Bogen 35 Min.) direkt auf der Kachel.
        $perPaper = $rule !== null && array_filter((array) ($rule->passing['parts'] ?? []), static fn ($p) => isset($p['seconds'])) !== [];
        $paperList = $this->content->papers((int) $course->id);
        // Gebündelt statt je Bogen: Fragen aller Bögen, Drip-Sperren und letzte Versuche mit je einer Abfrage.
        $paperQuestions = $this->paperQuestionIds(array_map(static fn ($p) => (int) $p->id, $paperList));
        $unlockAt = $this->paperUnlockTimes($userId, $course, $paperQuestions);
        $lastByPaper = [];
        foreach ($this->db->get_results($this->db->prepare(
            "SELECT id, paper_id, status, score, max_score, passed FROM {$this->t('exam_session')} WHERE user_id = %d AND course_id = %d AND paper_id IS NOT NULL ORDER BY (status = 'running') DESC, id DESC",
            $userId,
            $course->id
        )) as $row) {
            $lastByPaper[(int) $row->paper_id] ??= $row;
        }
        foreach ($paperList as $index => $paper) {
            $paperRule = null;
            if ($perPaper) {
                [$pp, $pt] = $this->applicableParts((array) $rule->passing, $paperQuestions[(int) $paper->id] ?? [], (int) $rule->time_limit_seconds);
                $paperRule = $this->passingText($pp, (int) $paper->question_count, $pt);
            }
            $last = $lastByPaper[(int) $paper->id] ?? null;
            $trialLocked = $limit !== null && $index >= $limit;
            $dripAt = $unlockAt[(int) $paper->id] ?? null;
            $papers[] = [
                'id' => (int) $paper->id,
                'number' => (int) $paper->paper_number,
                'questions' => (int) $paper->question_count,
                'rule' => $paperRule,
                // gesperrt: kostenloser Teilumfang (lock_reason „trial“) oder Module noch nicht freigegeben („drip“, ab unlock_at).
                'locked' => $trialLocked || $dripAt !== null,
                'lock_reason' => $trialLocked ? 'trial' : ($dripAt !== null ? 'drip' : null),
                'unlock_at' => $dripAt,
                'last' => $last ? ['session_id' => (int) $last->id, 'status' => $last->status, 'score' => $last->score === null ? null : (int) $last->score, 'max' => $last->max_score === null ? null : (int) $last->max_score, 'passed' => $last->passed === null ? null : (bool) $last->passed] : null,
            ];
        }
        $running = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t('exam_session')} WHERE user_id = %d AND course_id = %d AND paper_id IS NULL AND status = 'running' ORDER BY id DESC LIMIT 1",
            $userId,
            $course->id
        ));
        $history = $this->db->get_results($this->db->prepare(
            "SELECT id, paper_id, mode, score, max_score, passed, submitted_at FROM {$this->t('exam_session')} WHERE user_id = %d AND course_id = %d AND status = 'evaluated' ORDER BY id DESC LIMIT 10",
            $userId,
            $course->id
        ));

        return [
            'available' => $rule !== null,
            'live' => $live,
            'rule' => $rule ? $this->ruleSummary($rule) : null,
            'running_simulation' => $running ? (int) $running : null,
            // Im kostenlosen Teilumfang nur die ersten n festen Bögen, keine Zufallsprüfung.
            'limit' => $limit,
            'simulation_locked' => $limit !== null,
            'papers' => $papers,
            'history' => array_map(static fn ($h) => ['session_id' => (int) $h->id, 'paper_id' => $h->paper_id ? (int) $h->paper_id : null, 'score' => (int) $h->score, 'max' => (int) $h->max_score, 'passed' => (bool) $h->passed, 'submitted_at' => $h->submitted_at], $history),
            // Alle ausgewerteten Versuche (für Prüfungsverlauf und Filter), neueste zuerst.
            'results' => ! $withResults ? null : array_map(static fn ($h) => ['session_id' => (int) $h->id, 'paper_id' => $h->paper_id ? (int) $h->paper_id : null, 'paper_number' => $h->paper_number !== null ? (int) $h->paper_number : null, 'score' => (int) $h->score, 'max' => (int) $h->max_score, 'passed' => (bool) $h->passed, 'submitted_at' => $h->submitted_at], $this->db->get_results($this->db->prepare(
                "SELECT s.id, s.paper_id, p.paper_number, s.score, s.max_score, s.passed, s.submitted_at FROM {$this->t('exam_session')} s LEFT JOIN {$this->t('exam_paper')} p ON p.id = s.paper_id WHERE s.user_id = %d AND s.course_id = %d AND s.status = 'evaluated' ORDER BY s.id DESC LIMIT 500",
                $userId,
                $course->id
            ))),
        ];
    }

    /** Startet (oder setzt fort) eine Simulation bzw. einen Bogen. */
    public function start(int $userId, object $course, ?int $paperId): int
    {
        $paperId = $paperId ?: null; // 0 = Zufallsprüfung
        if (! $this->access->can($userId, $course->code, AccessService::EXAM)) {
            throw new AccessDenied('Kein Zugriff auf die Prüfungssimulation dieses Kurses.');
        }
        $limit = $this->access->limit($userId, $course->code, AccessService::EXAM);
        if ($limit !== null) {
            $allowed = array_slice(array_map(static fn ($p) => (int) $p->id, $this->content->papers((int) $course->id)), 0, $limit);
            if (! $paperId || ! in_array($paperId, $allowed, true)) {
                throw new AccessDenied(__('Im kostenlosen Umfang sind nur die ersten Prüfungsbögen enthalten.', 'bootspruefung-learning'));
            }
        }
        $this->expireRunning($userId);
        $this->partModules = [];
        ['rule' => $rule, 'live' => $live] = $this->content->examRule((int) $course->id);
        if ($rule === null) {
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_NOT_STARTABLE, 'Für diesen Kurs ist kein Prüfungsregelwerk vorhanden.');
        }

        // Doppelklick/paralleler Start: Prüfen und Anlegen unter einer Sperre je Nutzer (sonst zwei laufende Sitzungen).
        $lock = 'bp_exam_start_'.$userId;
        // GET_LOCK liefert 1 (erhalten), 0 (Zeitüberschreitung) oder NULL (Fehler) – nur mit Sperre weiter.
        if ((string) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== '1') {
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_START_BUSY, __('Die Prüfung wird gerade gestartet. Bitte versuche es in einem Moment erneut.', 'bootspruefung-learning'));
        }
        try {
        $running = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t('exam_session')} WHERE user_id = %d AND course_id = %d AND status = 'running' AND ".($paperId ? 'paper_id = %d' : 'paper_id IS NULL').' ORDER BY id DESC LIMIT 1',
            ...array_values(array_filter([$userId, (int) $course->id, $paperId], static fn ($v) => $v !== null))
        ));
        if ($running) {
            return (int) $running;
        }

        $modules = [];
        $reduced = false;
        if ($paperId) {
            $paper = null;
            foreach ($this->content->papers((int) $course->id) as $p) {
                if ((int) $p->id === $paperId) {
                    $paper = $p;
                }
            }
            if ($paper === null) {
                throw new NotFound('Prüfungsbogen gehört nicht zu diesem Kurs.');
            }
            $rows = $this->content->paperQuestions($paperId);
            if (count($rows) !== $this->content->paperQuestionCount($paperId)) {
                throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_NOT_STARTABLE, 'Dieser Bogen enthält derzeit nicht freigegebene Fragen und kann nicht gestartet werden.');
            }
            $picked = array_map(static fn ($r) => ['question_id' => (int) $r->question_id, 'revision_id' => (int) $r->revision_id], $rows);
            // Zeitgesteuerte Freigabe (Drip) gilt auch für feste Bögen: sonst wären gesperrte Module über den Bogen vorab sichtbar.
            $at = $this->paperUnlockTimes($userId, $course, [$paperId => array_column($picked, 'question_id')])[$paperId] ?? null;
            if ($at !== null) {
                throw new AccessDenied(sprintf(__('Dieser Prüfungsbogen wird am %s freigeschaltet, wenn alle seine Module freigegeben sind.', 'bootspruefung-learning'), wp_date(get_option('date_format'), strtotime($at.' UTC'))));
            }
        } else {
            $picked = [];
            $taken = [];
            $blueprints = $this->content->blueprints((int) $rule->id);
            // Zeitgesteuert gesperrte Module (Drip) nicht über die Zufallsprüfung vorab zeigen.
            $nowUtc = current_time('mysql', true);
            $times = $this->access->unlockTimes($userId, $course->code, AccessService::QUESTION_BANK);
            foreach ($blueprints as $bp) {
                $at = $times[(string) $bp->code] ?? null;
                if ($at !== null && $at > $nowUtc) {
                    throw new AccessDenied(__('Die Zufallsprüfung ist verfügbar, sobald alle Module freigegeben sind.', 'bootspruefung-learning'));
                }
            }
            foreach ($blueprints as $bp) {
                $pool = [];
                foreach ($this->content->questionsForModules([(int) $bp->module_id]) as $row) {
                    if (! isset($taken[(int) $row->question_id])) {
                        $pool[(int) $row->question_id] = ['question_id' => (int) $row->question_id, 'revision_id' => (int) $row->revision_id];
                    }
                }
                $keys = array_keys($pool);
                // Zu kleiner Pool: Prüfung läuft verkürzt und nur als Übung (nicht „offiziell“).
                if (count($keys) < (int) $bp->question_count) {
                    $reduced = true;
                }
                QuestionPresenter::shuffle($keys);
                foreach (array_slice($keys, 0, (int) $bp->question_count) as $id) {
                    $picked[] = $pool[$id];
                    $taken[$id] = true;
                }
                $modules[] = ['id' => (int) $bp->module_id, 'code' => $bp->code, 'count' => (int) $bp->question_count];
            }
        }
        if ($picked === []) {
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_NOT_STARTABLE, 'Für diese Prüfung sind derzeit keine Fragen freigegeben.');
        }

        // Kombis (Festlegung 07.11.2026): Teilgrenzen nur für die Teile, deren Modul im Bogen vorkommt;
        // Gesamtgrenze und Zeit ergeben sich aus diesen Teilen (z. B. See-Bogen 60 Min., Binnen-Bogen 35 Min.).
        [$passing, $timeLimit] = $this->applicableParts((array) $rule->passing, array_column($picked, 'question_id'), (int) $rule->time_limit_seconds);
        $now = time();
        $snapshot = [
            'rule_id' => (int) $rule->id,
            'rule_source_id' => $rule->source_id,
            'version' => $rule->version,
            'live' => $live && ! $reduced,
            'reduced' => $reduced,
            'time_limit_seconds' => $timeLimit,
            'passing' => $passing,
            // Frage → Teil (Modulcode) zum Startzeitpunkt; die Auswertung nutzt nur diese Zuordnung.
            'question_parts' => (object) $this->assignParts($passing, array_column($picked, 'question_id')),
            'course_code' => $course->code,
            'blueprints' => $modules,
            'paper_number' => $paperId ? (int) $paper->paper_number : null,
        ];
        $this->db->query('START TRANSACTION');
        try {
            $this->db->insert($this->t('exam_session'), [
                'user_id' => $userId,
                'course_id' => (int) $course->id,
                'rule_id' => (int) $rule->id,
                'paper_id' => $paperId,
                'mode' => $paperId ? 'paper' : 'simulation',
                'rule_snapshot' => wp_json_encode($snapshot),
                'status' => 'running',
                'start_token' => wp_generate_uuid4(),
                'started_at' => gmdate('Y-m-d H:i:s', $now),
                'deadline_at' => $timeLimit > 0 ? gmdate('Y-m-d H:i:s', $now + $timeLimit) : null,
            ]);
            $sessionId = (int) $this->db->insert_id;
            if ($sessionId === 0) {
                throw new RuntimeException('Prüfung konnte nicht gestartet werden.');
            }
            $this->presenter->preload(array_column($picked, 'revision_id'));
            foreach ($picked as $i => $q) {
                $presented = $this->presenter->present($q['revision_id']);
                $this->db->insert($this->t('exam_session_question'), [
                    'session_id' => $sessionId,
                    'position' => $i + 1,
                    'question_id' => $q['question_id'],
                    'revision_id' => $q['revision_id'],
                    'answer_order' => wp_json_encode($presented['answer_order']),
                ]);
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        return $sessionId;
        } finally {
            $this->db->query($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Kennzeichen einer (eben gestarteten) Sitzung für die Start-Antwort.
     * @return array{live:bool,reduced:bool}
     */
    public function flags(int $userId, int $sessionId): array
    {
        $snapshot = json_decode((string) $this->session($userId, $sessionId)->rule_snapshot, true) ?: [];

        return ['live' => (bool) ($snapshot['live'] ?? false), 'reduced' => (bool) ($snapshot['reduced'] ?? false)];
    }

    /** Zustand einer Sitzung; abgelaufene werden dabei bewertet. @return array<string,mixed> */
    public function show(int $userId, int $sessionId): array
    {
        $session = $this->session($userId, $sessionId);
        if ($session->status === 'running' && $this->expired($session)) {
            $this->evaluate($sessionId, true);
            $session = $this->session($userId, $sessionId);
        }
        $snapshot = json_decode((string) $session->rule_snapshot, true);
        $course = $this->db->get_row($this->db->prepare("SELECT code, name FROM {$this->t('course')} WHERE id = %d", $session->course_id));
        $questions = $this->questions($sessionId);
        $base = [
            'id' => (int) $session->id,
            'status' => $session->status,
            'mode' => $session->mode,
            'live' => (bool) $snapshot['live'],
            // Verkürzte Zufallsprüfung (Fragenpool kleiner als der Blueprint): nur Übung.
            'reduced' => (bool) ($snapshot['reduced'] ?? false),
            'course' => ['code' => $course->code, 'name' => $course->name],
            'paper_number' => $snapshot['paper_number'],
            'rule' => [
                'time_limit_seconds' => $snapshot['time_limit_seconds'],
                'passing' => $this->passingText($snapshot['passing'], count($questions), (int) $snapshot['time_limit_seconds']),
                'note' => $snapshot['passing']['note'] ?? null,
            ],
        ];

        if ($session->status === 'running') {
            $this->presenter->preload(array_map(static fn ($q) => (int) $q->revision_id, $questions));
            $items = [];
            foreach ($questions as $sq) {
                $presented = $this->presenter->present((int) $sq->revision_id, json_decode((string) $sq->answer_order, true) ?: []);
                $given = json_decode((string) $sq->given_answer, true) ?: [];
                $items[] = [
                    'position' => (int) $sq->position,
                    'type' => $presented['type'],
                    'text' => $presented['text'],
                    'official_number' => $presented['official_number'],
                    'media' => $presented['media'],
                    'answers' => $presented['answers'],
                    'selected_ids' => array_map('intval', $given['answer_ids'] ?? []),
                    'known' => $sq->self_assessed_known === null ? null : (bool) $sq->self_assessed_known,
                    'model_answer' => $sq->revealed_at ? $this->presenter->modelAnswer((int) $sq->revision_id) : null,
                    'answered' => $sq->answered_at !== null,
                    'flagged' => (bool) ($sq->flagged ?? false),
                ];
            }

            return $base + [
                'remaining_seconds' => $session->deadline_at ? max(0, strtotime($session->deadline_at.' UTC') - time()) : null,
                'questions' => $items,
            ];
        }

        return $base + ['result' => $this->resultView($session, $questions)];
    }

    /** @param list<int> $answerIds */
    public function saveAnswer(int $userId, int $sessionId, int $position, array $answerIds, ?bool $known): void
    {
        $session = $this->runningSession($userId, $sessionId);
        $sq = $this->sessionQuestion($sessionId, $position);
        $revision = $this->presenter->revision((int) $sq->revision_id);
        $data = ['answered_at' => current_time('mysql', true)];
        if ($revision->question_type === 'open_text') {
            if ($known === null) {
                throw new RuntimeException('Bitte Selbsteinschätzung angeben.');
            }
            if ($sq->revealed_at === null) {
                throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_ANSWER_LOCKED, 'Bitte zuerst die Musterantwort anzeigen.');
            }
            $data['self_assessed_known'] = $known ? 1 : 0;
        } else {
            $allowed = json_decode((string) $sq->answer_order, true) ?: [];
            $selected = array_values(array_unique(array_map('intval', $answerIds)));
            if ($selected === [] && $revision->question_type === 'multi_choice') {
                // Alle Kästchen abgehakt: Antwort zurücknehmen (wieder unbeantwortet).
                $data = ['answered_at' => null, 'given_answer' => null];
                $selected = null;
            } elseif ($selected === [] || array_diff($selected, $allowed) !== []) {
                throw new RuntimeException('Bitte eine gültige Antwort auswählen.');
            }
            if ($selected !== null && $revision->question_type === 'single_choice' && count($selected) !== 1) {
                throw new RuntimeException('Bitte genau eine Antwort auswählen.');
            }
            if ($selected !== null) {
                $data['given_answer'] = wp_json_encode(['answer_ids' => $selected]);
            }
        }
        // Nur speichern, solange die Sitzung (gesperrt) noch läuft – nicht zwischen Auswertung und Ergebnis.
        $this->db->query('START TRANSACTION');
        $status = $this->db->get_var($this->db->prepare("SELECT status FROM {$this->t('exam_session')} WHERE id = %d FOR UPDATE", $sessionId));
        if ($status !== 'running') {
            $this->db->query('ROLLBACK');
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_FINISHED, 'Die Prüfung ist bereits beendet.');
        }
        $this->db->update($this->t('exam_session_question'), $data, ['id' => (int) $sq->id]);
        $this->db->query('COMMIT');
    }

    /**
     * Frage einer laufenden Prüfung „zur Überprüfung markieren“ (bzw. Markierung entfernen).
     * Ändert weder Antwort noch Bewertung; nach Abgabe/Fristende nicht mehr möglich.
     * @return int Anzahl markierter Fragen der Sitzung
     */
    public function setFlag(int $userId, int $sessionId, int $position, bool $flagged): int
    {
        $this->runningSession($userId, $sessionId);
        $sq = $this->sessionQuestion($sessionId, $position);
        $this->db->query('START TRANSACTION');
        $status = $this->db->get_var($this->db->prepare("SELECT status FROM {$this->t('exam_session')} WHERE id = %d FOR UPDATE", $sessionId));
        if ($status !== 'running') {
            $this->db->query('ROLLBACK');
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_FINISHED, 'Die Prüfung ist bereits beendet.');
        }
        $this->db->update($this->t('exam_session_question'), ['flagged' => $flagged ? 1 : 0], ['id' => (int) $sq->id]);
        $this->db->query('COMMIT');

        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t('exam_session_question')} WHERE session_id = %d AND flagged = 1", $sessionId));
    }

    /** Musterantwort einer offenen Frage freigeben (Voraussetzung für die Selbsteinschätzung). */
    public function reveal(int $userId, int $sessionId, int $position): string
    {
        $this->runningSession($userId, $sessionId);
        $sq = $this->sessionQuestion($sessionId, $position);
        $revision = $this->presenter->revision((int) $sq->revision_id);
        if ($revision->question_type !== 'open_text') {
            throw new RuntimeException('Nur für offene Fragen.');
        }
        if ($sq->revealed_at === null) {
            $this->db->update($this->t('exam_session_question'), ['revealed_at' => current_time('mysql', true)], ['id' => (int) $sq->id]);
        }

        return (string) $this->presenter->modelAnswer((int) $sq->revision_id);
    }

    public function submit(int $userId, int $sessionId): void
    {
        $session = $this->session($userId, $sessionId);
        if ($session->status !== 'running') {
            return;
        }
        // Hinweis „Zeit abgelaufen“ ab dem Fristende (die Kulanzzeit gilt nur für das Werten später Antworten).
        $this->evaluate($sessionId, $session->deadline_at !== null && time() >= strtotime($session->deadline_at.' UTC'));
    }

    /** Falsch beantwortete Fragen einer eigenen, ausgewerteten Prüfung. @return list<int> */
    public function wrongQuestionIds(int $userId, int $sessionId): array
    {
        $session = $this->session($userId, $sessionId);
        if ($session->status !== 'evaluated') {
            return [];
        }

        return array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT question_id FROM {$this->t('exam_session_question')} WHERE session_id = %d AND is_correct = 0 ORDER BY position",
            $sessionId
        )));
    }

    /** Schließt abgelaufene Sitzungen (Cron und vor jedem Zugriff). */
    public function expireRunning(?int $userId = null): int
    {
        $sql = "SELECT id FROM {$this->t('exam_session')} WHERE status = 'running' AND deadline_at IS NOT NULL AND deadline_at < %s";
        $args = [gmdate('Y-m-d H:i:s', time() - self::GRACE_SECONDS)];
        if ($userId !== null) {
            $sql .= ' AND user_id = %d';
            $args[] = $userId;
        }
        $ids = $this->db->get_col($this->db->prepare($sql, $args));
        foreach ($ids as $id) {
            $this->evaluate((int) $id, true);
        }

        return count($ids);
    }

    // ------------------------------------------------------------------

    private function evaluate(int $sessionId, bool $expired): void
    {
        $this->db->query('START TRANSACTION');
        try {
            $session = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('exam_session')} WHERE id = %d FOR UPDATE", $sessionId));
            if (! $session || $session->status !== 'running') {
                $this->db->query('COMMIT');

                return;
            }
            $snapshot = json_decode((string) $session->rule_snapshot, true);
            $this->partModules = [];
            $deadline = $session->deadline_at ? strtotime($session->deadline_at.' UTC') + self::GRACE_SECONDS : null;
            $questions = $this->questions($sessionId);
            $this->presenter->preload(array_map(static fn ($q) => (int) $q->revision_id, $questions));
            $correctCount = 0;
            foreach ($questions as $sq) {
                $withinTime = $deadline === null || $sq->answered_at === null || strtotime($sq->answered_at.' UTC') <= $deadline;
                $revision = $this->presenter->revision((int) $sq->revision_id);
                $given = json_decode((string) $sq->given_answer, true) ?: [];
                $result = $this->presenter->evaluate(
                    (int) $sq->revision_id,
                    $revision->question_type,
                    $withinTime ? array_map('intval', $given['answer_ids'] ?? []) : [],
                    $withinTime && $sq->self_assessed_known !== null ? (bool) $sq->self_assessed_known : null
                );
                $isCorrect = $withinTime && $sq->answered_at !== null && $result['correct'];
                $this->db->update($this->t('exam_session_question'), ['is_correct' => $isCorrect ? 1 : 0], ['id' => (int) $sq->id]);
                $sq->is_correct = $isCorrect ? 1 : 0;
                $correctCount += $isCorrect ? 1 : 0;
            }
            $total = max(count($questions), 1);
            $parts = $this->partResults($snapshot['passing'], $questions, isset($snapshot['question_parts']) ? (array) $snapshot['question_parts'] : null);
            $minRatio = (float) ($snapshot['passing']['min_correct_ratio'] ?? 0.75);
            $passed = $correctCount / $total >= $minRatio - 1e-9 && array_reduce($parts, static fn ($ok, $p) => $ok && $p['passed'], true);
            $this->db->update($this->t('exam_session'), [
                'status' => 'evaluated',
                'submitted_at' => current_time('mysql', true),
                'score' => $correctCount,
                'max_score' => count($questions),
                'passed' => $passed ? 1 : 0,
                'result' => wp_json_encode(['parts' => $parts, 'expired' => $expired, 'ratio' => round($correctCount / $total, 4)]),
            ], ['id' => $sessionId]);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Teilergebnisse je Regelwerksteil wie ExamRuleSet::partResults der Quelle:
     * Fragen werden in Teil-Reihenfolge ihrem ersten passenden Modul zugeordnet. Seit dem
     * Snapshot-Feld question_parts gilt die Zuordnung vom Prüfungsstart; ältere Sitzungen
     * ohne dieses Feld werden wie bisher mit der aktuellen Modulzuordnung ausgewertet.
     * Ein Teil ohne Fragen wird nicht gewertet (bestanden, empty = true) statt die Prüfung
     * automatisch scheitern zu lassen; die Gesamtquote gilt weiterhin.
     * @param array<string,mixed> $passing
     * @param list<object> $questions
     * @param array<int|string,?string>|null $assignment question_id => Modulcode aus dem Snapshot
     * @return list<array<string,mixed>>
     */
    private function partResults(array $passing, array $questions, ?array $assignment = null): array
    {
        $parts = $passing['parts'] ?? [];
        if ($parts === []) {
            return [];
        }
        $assignment ??= $this->assignParts($passing, array_map(static fn ($q) => (int) $q->question_id, $questions));
        $results = [];
        foreach ($parts as $part) {
            $total = 0;
            $correct = 0;
            foreach ($questions as $sq) {
                if (($assignment[(int) $sq->question_id] ?? null) !== $part['module']) {
                    continue;
                }
                $total++;
                $correct += (int) $sq->is_correct;
            }
            $results[] = [
                'label' => $part['label'],
                'correct' => $correct,
                'total' => $total,
                'min_correct' => $part['min_correct'],
                'of' => $part['of'],
                'empty' => $total === 0,
                'passed' => $total === 0 || $correct / $total >= $part['min_correct'] / $part['of'] - 1e-9,
            ];
        }

        return $results;
    }

    /**
     * Ordnet jede Frage dem ersten Teil (in Teil-Reihenfolge) zu, dessen Modul sie enthält.
     * @param array<string,mixed> $passing
     * @param list<int> $questionIds
     * @return array<int,string> question_id => Modulcode (Fragen ohne Teil fehlen)
     */
    private function assignParts(array $passing, array $questionIds): array
    {
        $parts = $passing['parts'] ?? [];
        if ($parts === []) {
            return [];
        }
        [$moduleIds, $byModule] = $this->partModuleIndex(array_column($parts, 'module'));
        $picked = array_flip(array_map('intval', $questionIds));
        $out = [];
        foreach ($parts as $part) {
            foreach ($byModule[$moduleIds[$part['module']] ?? 0] ?? [] as $q) {
                if (isset($picked[$q]) && ! isset($out[$q])) {
                    $out[$q] = (string) $part['module'];
                }
            }
        }

        return $out;
    }

    /**
     * Modul-IDs und Fragen der Teil-Module (je Anfrage einmal geladen).
     * @param list<string> $codes
     * @return array{0:array<string,int>,1:array<int,list<int>>}
     */
    private function partModuleIndex(array $codes): array
    {
        $key = implode('|', $codes);
        if (! isset($this->partModules[$key])) {
            $moduleIds = [];
            if ($codes !== []) {
                foreach ($this->db->get_results("SELECT id, code FROM {$this->t('module')} WHERE code IN ('".implode("','", array_map('esc_sql', $codes))."')") as $m) {
                    $moduleIds[$m->code] = (int) $m->id;
                }
            }
            $this->partModules[$key] = [$moduleIds, $this->content->moduleQuestionIds(array_values($moduleIds))];
        }

        return $this->partModules[$key];
    }

    /**
     * Auslieferbare Fragen je Bogen mit einer Abfrage (wie ContentRepository::paperQuestions).
     * @param list<int> $paperIds
     * @return array<int,list<int>> paper_id => question_ids in Bogenreihenfolge
     */
    private function paperQuestionIds(array $paperIds): array
    {
        if ($paperIds === []) {
            return [];
        }
        $rows = $this->db->get_results(
            "SELECT pq.paper_id, q.id AS question_id FROM {$this->t('exam_paper_question')} pq
             JOIN {$this->t('question')} q ON q.id = pq.question_id JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id ".Overrides::rightsJoin('question', 'q', 'o').'
             WHERE pq.paper_id IN ('.implode(',', array_map('intval', $paperIds)).') AND '.$this->content->deliverableQuestionSql().' ORDER BY pq.paper_id, pq.position'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->paper_id][] = (int) $row->question_id;
        }

        return $out;
    }

    /**
     * Zeitgesteuerte Freigabe (Drip) je Bogen: Ein Bogen ist gesperrt, solange eine seiner Fragen
     * nur in noch gesperrten Modulen des Kurses liegt (wie im Lernmodus: eine Frage ist frei,
     * sobald eines ihrer Module frei ist). Freigabe = spätester dieser Zeitpunkte.
     * @param array<int,list<int>> $paperQuestions paper_id => question_ids
     * @return array<int,string> paper_id => Freigabezeitpunkt (UTC), nur gesperrte Bögen
     */
    private function paperUnlockTimes(int $userId, object $course, array $paperQuestions): array
    {
        $times = $this->access->unlockTimes($userId, (string) $course->code, AccessService::QUESTION_BANK);
        $now = current_time('mysql', true);
        if (array_filter($times, static fn ($at) => $at > $now) === [] || $paperQuestions === []) {
            return [];
        }
        $codes = [];
        foreach ($this->content->modules((int) $course->id) as $m) {
            $codes[(int) $m->id] = (string) $m->code;
        }
        $allIds = array_values(array_unique(array_merge(...array_values($paperQuestions))));
        if ($codes === [] || $allIds === []) {
            return [];
        }
        $questionModules = [];
        foreach ($this->db->get_results(
            "SELECT question_id, module_id FROM {$this->t('module_question')} WHERE module_id IN (".implode(',', array_keys($codes)).') AND question_id IN ('.implode(',', array_map('intval', $allIds)).')'
        ) as $row) {
            $questionModules[(int) $row->question_id][] = $codes[(int) $row->module_id];
        }
        $out = [];
        foreach ($paperQuestions as $paperId => $ids) {
            $paperAt = null;
            foreach ($ids as $id) {
                $earliest = null;
                foreach ($questionModules[$id] ?? [] as $code) {
                    $at = $times[$code] ?? null;
                    if ($at === null || $at <= $now) {
                        continue 2; // mindestens ein Modul frei → Frage frei
                    }
                    $earliest = $earliest === null ? $at : min($earliest, $at);
                }
                if ($earliest !== null) {
                    $paperAt = $paperAt === null ? $earliest : max($paperAt, $earliest);
                }
            }
            if ($paperAt !== null) {
                $out[(int) $paperId] = $paperAt;
            }
        }

        return $out;
    }

    /** @param list<object> $questions @return array<string,mixed> */
    private function resultView(object $session, array $questions): array
    {
        $result = json_decode((string) $session->result, true) ?: [];
        $course = $this->db->get_row($this->db->prepare("SELECT code FROM {$this->t('course')} WHERE id = %d", $session->course_id));
        $explain = $this->access->can((int) $session->user_id, $course->code, AccessService::EXPLANATIONS);
        $items = [];
        $topics = [];
        $openText = ['total' => 0, 'correct' => 0];
        // Fragen ohne Thema (z. B. SKN): unter dem Modulnamen zusammenfassen statt „—“.
        $moduleOf = [];
        $moduleNames = [];
        foreach ($this->content->modules((int) $session->course_id) as $m) {
            $moduleNames[(int) $m->id] = (string) $m->name;
        }
        // Nur die Module der Prüfungsfragen laden (erstes Kursmodul in Kursreihenfolge), nicht den ganzen Katalog.
        $questionIds = array_map(static fn ($q) => (int) $q->question_id, $questions);
        if ($questionIds !== [] && $moduleNames !== []) {
            foreach ($this->db->get_results($this->db->prepare(
                "SELECT mq.question_id, mq.module_id FROM {$this->t('module_question')} mq JOIN {$this->t('course_module')} cm ON cm.module_id = mq.module_id AND cm.course_id = %d
                 WHERE mq.question_id IN (".implode(',', $questionIds).') AND mq.module_id IN ('.implode(',', array_keys($moduleNames)).') ORDER BY cm.sort_order, mq.module_id',
                $session->course_id
            )) as $row) {
                $moduleOf[(int) $row->question_id] ??= $moduleNames[(int) $row->module_id] ?? '';
            }
        }
        $this->presenter->preload(array_map(static fn ($q) => (int) $q->revision_id, $questions));
        foreach ($questions as $sq) {
            $presented = $this->presenter->present((int) $sq->revision_id, json_decode((string) $sq->answer_order, true) ?: []);
            $revision = $this->presenter->revision((int) $sq->revision_id);
            $given = json_decode((string) $sq->given_answer, true) ?: [];
            $correctIds = [];
            foreach ($this->presenter->answers((int) $sq->revision_id) as $a) {
                if ((int) $a->is_correct === 1) {
                    $correctIds[] = (int) $a->id;
                }
            }
            $topic = trim((string) $presented['topic']) ?: (($moduleOf[(int) $sq->question_id] ?? '') ?: __('Weitere Fragen', 'bootspruefung-learning'));
            $topics[$topic]['total'] = ($topics[$topic]['total'] ?? 0) + 1;
            $topics[$topic]['correct'] = ($topics[$topic]['correct'] ?? 0) + (int) $sq->is_correct;
            if ($presented['type'] === 'open_text') {
                $openText['total']++;
                $openText['correct'] += (int) $sq->is_correct;
            }
            $items[] = [
                'position' => (int) $sq->position,
                'question_id' => (int) $sq->question_id,
                'type' => $presented['type'],
                'text' => $presented['text'],
                'media' => $presented['media'],
                'answers' => $presented['answers'],
                'selected_ids' => array_map('intval', $given['answer_ids'] ?? []),
                'correct_ids' => $presented['type'] === 'open_text' ? [] : $correctIds,
                'model_answer' => $presented['type'] === 'open_text' ? $this->presenter->modelAnswer((int) $sq->revision_id) : null,
                'known' => $sq->self_assessed_known === null ? null : (bool) $sq->self_assessed_known,
                'answered' => $sq->answered_at !== null,
                'flagged' => (bool) ($sq->flagged ?? false),
                'correct' => (bool) $sq->is_correct,
                'explanation' => $explain ? ((bool) $sq->is_correct ? $revision->feedback_correct : $revision->feedback_incorrect) : null,
            ];
        }
        ksort($topics);

        return [
            'score' => (int) $session->score,
            'max' => (int) $session->max_score,
            'ratio' => $result['ratio'] ?? null,
            'passed' => (bool) $session->passed,
            'expired' => (bool) ($result['expired'] ?? false),
            'reduced' => (bool) ((json_decode((string) $session->rule_snapshot, true) ?: [])['reduced'] ?? false),
            'parts' => $result['parts'] ?? [],
            'topics' => $topics,
            'open_text' => $openText,
            'questions' => $items,
            'submitted_at' => $session->submitted_at,
        ];
    }

    /** @return array<string,mixed> */
    private function ruleSummary(object $rule): array
    {
        $count = array_sum(array_map(static fn ($b) => (int) $b->question_count, $this->content->blueprints((int) $rule->id)));

        return [
            'version' => $rule->version,
            'time_limit_seconds' => (int) $rule->time_limit_seconds,
            'questions' => $count,
            'passing' => $this->passingText($rule->passing, $count, (int) $rule->time_limit_seconds),
            'note' => $rule->passing['note'] ?? null,
        ];
    }

    /**
     * Teile eines Regelwerks, die für diese Fragen gelten (Modul kommt vor). Mit Zeitangabe je Teil
     * (seconds) ergibt sich die Prüfungszeit aus den geltenden Teilen; die Gesamtgrenze aus deren Summe.
     * Ohne Teile oder wenn alle Teile gelten: Regelwerk unverändert.
     * @param array<string,mixed> $passing
     * @param list<int> $questionIds
     * @return array{0:array<string,mixed>,1:int}
     */
    private function applicableParts(array $passing, array $questionIds, int $timeLimit): array
    {
        $parts = $passing['parts'] ?? [];
        if ($parts === []) {
            return [$passing, $timeLimit];
        }
        [$moduleIds, $byModule] = $this->partModuleIndex(array_column($parts, 'module'));
        $picked = array_flip(array_map('intval', $questionIds));
        $applicable = array_values(array_filter($parts, static function ($p) use ($moduleIds, $byModule, $picked): bool {
            foreach ($byModule[$moduleIds[$p['module']] ?? 0] ?? [] as $q) {
                if (isset($picked[$q])) {
                    return true;
                }
            }

            return false;
        }));
        $withSeconds = static fn (array $list): bool => $list !== [] && count(array_filter($list, static fn ($p) => (int) ($p['seconds'] ?? 0) > 0)) === count($list);
        if ($applicable === [] || count($applicable) === count($parts)) {
            return [$passing, $withSeconds($parts) ? array_sum(array_map(static fn ($p) => (int) $p['seconds'], $parts)) : $timeLimit];
        }
        $passing['parts'] = $applicable;
        $passing['min_correct_ratio'] = array_sum(array_column($applicable, 'min_correct')) / max(1, array_sum(array_column($applicable, 'of')));

        return [$passing, $withSeconds($applicable) ? array_sum(array_map(static fn ($p) => (int) $p['seconds'], $applicable)) : $timeLimit];
    }

    /** Wie ExamRuleSet::passingSummary der Quelle. @param array<string,mixed> $passing */
    private function passingText(array $passing, int $total, int $timeLimit): string
    {
        $parts = $passing['parts'] ?? [];
        $summary = $parts !== []
            ? implode(' · ', array_map(static fn ($p) => "{$p['label']} {$p['min_correct']}/{$p['of']}", $parts))
            : sprintf(__('Bestanden ab %1$d von %2$d richtigen Antworten', 'bootspruefung-learning'), (int) ceil(((float) ($passing['min_correct_ratio'] ?? 0.75)) * $total - 1e-9), $total);

        return $timeLimit > 0 ? $summary.' · '.sprintf(__('%d Minuten', 'bootspruefung-learning'), intdiv($timeLimit, 60)) : $summary;
    }

    private function expired(object $session): bool
    {
        return $session->deadline_at !== null && time() > strtotime($session->deadline_at.' UTC') + self::GRACE_SECONDS;
    }

    private function session(int $userId, int $sessionId): object
    {
        $session = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('exam_session')} WHERE id = %d", $sessionId));
        if (! $session || (int) $session->user_id !== $userId) {
            throw new NotFound('Prüfung nicht gefunden.');
        }

        return $session;
    }

    private function runningSession(int $userId, int $sessionId): object
    {
        $session = $this->session($userId, $sessionId);
        if ($session->status !== 'running') {
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_FINISHED, 'Die Prüfung ist bereits abgeschlossen.');
        }
        // Zugang inzwischen beendet (Erstattung, Rückbuchung, Ablauf)? Dann nicht weiter bearbeiten.
        $code = (string) (json_decode((string) $session->rule_snapshot, true)['course_code'] ?? '');
        if ($code !== '' && ! $this->access->can($userId, $code, AccessService::EXAM)) {
            throw new AccessDenied('Kein Zugriff mehr auf die Prüfungssimulation dieses Kurses.');
        }
        if ($this->expired($session)) {
            $this->evaluate($sessionId, true);
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::EXAM_EXPIRED, 'Die Prüfungszeit ist abgelaufen.');
        }

        return $session;
    }

    private function sessionQuestion(int $sessionId, int $position): object
    {
        $sq = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('exam_session_question')} WHERE session_id = %d AND position = %d", $sessionId, $position));
        if (! $sq) {
            throw new NotFound('Frage nicht gefunden.');
        }

        return $sq;
    }

    /** @return list<object> */
    private function questions(int $sessionId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t('exam_session_question')} WHERE session_id = %d ORDER BY position", $sessionId));
    }
}
