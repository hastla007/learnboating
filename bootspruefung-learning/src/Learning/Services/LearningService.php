<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Lernmodus und Smarttrainer (Kap. 6.1, 6.2), fachlich wie die Quelle
 * (SmarttrainerService, MasteryCalculator, LearningService):
 * - Priorität: nie gesehen (1000) → zuletzt falsch (800 + 10 je Fehler, max. 10)
 *   → schwaches Thema (< 60 % richtig, +150) → gefestigt; Gleichstand nach
 *   Modul- und Fragenreihenfolge, also deterministisch;
 * - gefestigt = 3 richtige Antworten in Folge;
 * - Fragen laufender Prüfungen werden im Lernmodus nicht gezeigt.
 */
final class LearningService
{
    public const MODES = ['smart', 'new', 'wrong', 'favorites'];
    private const RATE_LIMIT = 60;

    private \wpdb $db;
    /** @var array<string,array<int,object>> */
    private array $allowedMemo = [];

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

    /**
     * Nächste Frage für den Nutzer oder null, wenn im Modus nichts übrig ist.
     * @return array<string,mixed>|null
     */
    /** @param list<int>|null $onlyIds Fragenauswahl (z. B. eine Sammlung); ersetzt im Modus „favorites“ die Merkliste */
    public function next(int $userId, object $course, ?int $moduleId, string $mode, ?string $topic = null, ?array $onlyIds = null): ?array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException('Unbekannter Lernmodus.');
        }
        $gates = $this->moduleGates($userId, $course);
        $moduleIds = array_keys($gates);
        if ($moduleId !== null) {
            if (! in_array($moduleId, $moduleIds, true)) {
                throw new RuntimeException('Modul gehört nicht zum Kurs.');
            }
            if ($gates[$moduleId]['locked']) {
                throw new AccessDenied(sprintf(__('Dieses Modul wird am %s freigeschaltet.', 'bootspruefung-learning'), wp_date(get_option('date_format'), strtotime($gates[$moduleId]['unlock_at'].' UTC'))));
            }
            $moduleIds = [$moduleId];
        }
        $questions = $this->allowedQuestions($userId, $course, $moduleIds, $gates);
        foreach ($this->runningExamQuestionIds($userId) as $blocked) {
            unset($questions[$blocked]);
        }
        if ($topic !== null && $topic !== '') {
            $topic = trim($topic);
            $questions = array_filter($questions, static fn ($q) => trim((string) ($q->topic ?? '')) === $topic);
        }
        if ($onlyIds !== null) {
            $questions = array_intersect_key($questions, array_flip($onlyIds));
        } elseif ($mode === 'favorites') {
            $favorites = array_flip($this->favoriteIds($userId));
            $questions = array_intersect_key($questions, $favorites);
        }
        if ($questions === []) {
            return null;
        }
        $progress = $this->progressFor($userId, array_keys($questions));
        $topicAccuracy = $this->topicAccuracy($questions, $progress);

        $best = null;
        foreach ($questions as $id => $q) {
            $p = $progress[$id] ?? null;
            if ($mode === 'new' && $p !== null) {
                continue;
            }
            if ($mode === 'wrong' && ! self::lastWrong($p)) {
                continue;
            }
            [$score, $reason] = $this->score($q, $p, $topicAccuracy);
            if ($best === null || $score > $best['score']) {
                $best = ['question' => $q, 'score' => $score, 'reason' => $reason];
            }
        }
        if ($best === null) {
            return null;
        }
        $presented = $this->presenter->present((int) $best['question']->revision_id);
        $token = $this->presenter->token($userId, $presented['revision_id'], $presented['answer_order'], 'learn:'.$course->code);
        unset($presented['answer_order']);

        return $presented + [
            'token' => $token,
            'reason' => $best['reason'],
            'module_id' => (int) $best['question']->module_id,
            'favorite' => in_array($presented['question_id'], $this->favoriteIds($userId), true),
            'progress' => $this->progressPayload($progress[$presented['question_id']] ?? null),
            'remaining' => $mode === 'smart' ? null : $this->remaining($mode, $questions, $progress),
        ];
    }

    /** Musterantwort einer offenen Frage im Lernmodus. */
    public function reveal(int $userId, string $token): array
    {
        $data = $this->presenter->verify($token, $userId);
        $revision = $this->content->revision($data['r']);
        if ($revision === null || $revision->question_type !== 'open_text') {
            throw new RuntimeException('Nur für offene Fragen.');
        }

        return ['model_answer' => $this->presenter->modelAnswer($data['r'])];
    }

    /**
     * Wertet eine Antwort aus, speichert den Versuch und aktualisiert den Fortschritt
     * transaktional. Wiederholte Requests mit demselben Idempotency-Key liefern
     * dasselbe Ergebnis ohne zweite Zählung.
     * @param list<int> $answerIds
     * @return array<string,mixed>
     */
    public function answer(int $userId, string $token, array $answerIds, ?bool $known, string $idempotencyKey, ?int $responseMs): array
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $idempotencyKey)) {
            throw new RuntimeException('Ungültiger Idempotency-Key.');
        }
        $data = $this->presenter->verify($token, $userId);
        [$context, $courseCode] = array_pad(explode(':', $data['c'], 2), 2, '');
        $feature = $context === 'video' ? AccessService::VIDEO : AccessService::QUESTION_BANK;
        if (! in_array($context, ['learn', 'video'], true) || ! $this->access->can($userId, $courseCode, $feature)) {
            throw new AccessDenied('Kein Zugriff auf diesen Kurs.');
        }
        $revision = $this->content->revision($data['r']);
        if ($revision === null) {
            throw new NotFound('Frage nicht gefunden.');
        }
        // Fragen einer laufenden Prüfung nicht nebenbei (Lernmodus/Videoquiz) mit Lösung auswerten.
        if (in_array((int) $revision->question_id, $this->runningExamQuestionIds($userId), true)) {
            throw new RuntimeException('Diese Frage gehört zu deiner laufenden Prüfung. Beende zuerst die Prüfung.');
        }

        // Ein Frage-Token zählt nur einmal: erneutes Einreichen mit neuem Key liefert das erste Ergebnis.
        $tokenKey = 'bp_tok_'.substr(hash('sha256', $token), 0, 40);
        $firstKey = get_transient($tokenKey);
        if (is_string($firstKey) && $firstKey !== $idempotencyKey) {
            $idempotencyKey = $firstKey;
        }

        $existing = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('attempt')} WHERE idempotency_key = %s", $idempotencyKey));
        if ($existing) {
            if ((int) $existing->user_id !== $userId) {
                throw new RuntimeException('Idempotency-Key bereits verwendet.');
            }

            return $this->result($userId, $courseCode, $revision, (bool) $existing->is_correct, $this->attemptAnswerIds((int) $existing->id), true);
        }
        $this->rateLimit($userId);

        $allowed = $data['o'];
        $selected = array_values(array_unique(array_map('intval', $answerIds)));
        if ($revision->question_type !== 'open_text') {
            if ($selected === [] || array_diff($selected, $allowed) !== []) {
                throw new RuntimeException('Bitte eine gültige Antwort auswählen.');
            }
            if ($revision->question_type === 'single_choice' && count($selected) !== 1) {
                throw new RuntimeException('Bitte genau eine Antwort auswählen.');
            }
        } elseif ($known === null) {
            throw new RuntimeException('Bitte angeben, ob die Antwort gewusst wurde.');
        }
        $evaluation = $this->presenter->evaluate((int) $revision->id, $revision->question_type, $selected, $known);
        $correct = $evaluation['correct'];
        $now = current_time('mysql', true);

        $this->db->query('START TRANSACTION');
        try {
            $suppress = $this->db->suppress_errors(true);
            $inserted = $this->db->insert($this->t('attempt'), [
                'user_id' => $userId,
                'question_id' => (int) $revision->question_id,
                'revision_id' => (int) $revision->id,
                'context' => $context,
                'context_ref' => $courseCode,
                'is_correct' => $correct ? 1 : 0,
                'self_assessment' => $revision->question_type === 'open_text' ? ($known ? 'known' : 'unknown') : null,
                'answer_ms' => $responseMs !== null ? max(0, min($responseMs, 3600000)) : null,
                'idempotency_key' => $idempotencyKey,
                'created_at' => $now,
            ]);
            $this->db->suppress_errors($suppress);
            if ($inserted === false) {
                // Paralleler Doppel-Submit: der andere Request hat gewonnen.
                $this->db->query('ROLLBACK');
                $existing = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('attempt')} WHERE idempotency_key = %s AND user_id = %d", $idempotencyKey, $userId));
                if (! $existing) {
                    throw new RuntimeException('Antwort konnte nicht gespeichert werden.');
                }

                return $this->result($userId, $courseCode, $revision, (bool) $existing->is_correct, $this->attemptAnswerIds((int) $existing->id), true);
            }
            $attemptId = (int) $this->db->insert_id;
            foreach ($selected as $position => $answerId) {
                $this->db->insert($this->t('attempt_answer'), ['attempt_id' => $attemptId, 'answer_id' => $answerId, 'position' => $position]);
            }
            $this->applyProgress($userId, (int) $revision->question_id, $correct, $now);
            $this->db->query('COMMIT');
            set_transient($tokenKey, $idempotencyKey, DAY_IN_SECONDS + HOUR_IN_SECONDS);
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        return $this->result($userId, $courseCode, $revision, $correct, $selected, false);
    }

    /** Fortschritt je Modul eines Kurses. @return array<string,mixed> */
    public function courseProgress(int $userId, object $course): array
    {
        // Gebündelt: Freigaben, Limit, Fragen aller Module und Lernstand je einmal laden (statt je Modul).
        $gates = $this->moduleGates($userId, $course);
        $limit = $this->access->limit($userId, $course->code, AccessService::QUESTION_BANK);
        $moduleList = $this->content->modules((int) $course->id);
        $byModule = [];
        foreach ($this->content->questionsForModules(array_map(static fn ($m) => (int) $m->id, $moduleList)) as $row) {
            $byModule[(int) $row->module_id][] = $row;
        }
        $allowedByModule = [];
        $all = [];
        foreach ($moduleList as $module) {
            $id = (int) $module->id;
            $rows = [];
            // Wie allowedQuestions(): ohne gesperrte Module, bei Teilumfang die ersten n Fragen je Modul.
            if ($limit !== 0 && ! ($gates[$id]['locked'] ?? false)) {
                $n = 0;
                foreach ($byModule[$id] ?? [] as $row) {
                    if ($limit !== null && $n >= $limit) {
                        break;
                    }
                    $n++;
                    $rows[(int) $row->question_id] ??= $row;
                }
            }
            $allowedByModule[$id] = $rows;
            $all += $rows;
        }
        $progress = $this->progressFor($userId, array_keys($all));
        $modules = [];
        foreach ($moduleList as $module) {
            $id = (int) $module->id;
            $rows = $allowedByModule[$id];
            $ids = array_keys($rows);
            $catalog = count(array_unique(array_map(static fn ($r) => (int) $r->question_id, $byModule[$id] ?? [])));
            $modules[] = ['id' => $id, 'code' => $module->code, 'name' => $module->name] + self::summaryFrom($ids, $progress)
                + ['available' => count($ids), 'catalog' => $catalog, 'locked' => $gates[$id]['locked'], 'unlock_at' => $gates[$id]['unlock_at'],
                    'topics' => $this->topicSummary($userId, $rows, (string) $module->name, $progress)];
        }

        return ['total' => self::summaryFrom(array_keys($all), $progress), 'modules' => $modules, 'limit' => $limit];
    }

    /**
     * Themen eines Moduls mit Fortschritt (nur wenn das Modul in mindestens zwei Themen gegliedert ist).
     * @param array<int,object> $rows question_id => Zeile
     * @return list<array<string,mixed>>
     */
    private function topicSummary(int $userId, array $rows, string $moduleName, ?array $progress = null): array
    {
        $groups = [];
        foreach ($rows as $id => $row) {
            $topic = trim((string) ($row->topic ?? ''));
            if ($topic === '' || $topic === $moduleName) {
                continue;
            }
            $groups[$topic][] = (int) $id;
        }
        if (count($groups) < 2) {
            return [];
        }
        // Fortschritt einmal je Modul laden, dann je Thema zählen (statt einer Abfrage pro Thema).
        $progress ??= $this->progressFor($userId, array_merge(...array_values($groups)));
        $out = [];
        foreach ($groups as $topic => $ids) {
            $out[] = ['name' => $topic] + self::summaryFrom($ids, $progress);
        }
        usort($out, static fn ($a, $b) => $b['questions'] <=> $a['questions'] ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Freigabezustand der Module (zeitgesteuerte Freigabe, Kap. 7.2).
     *
     * @return array<int,array{code:string,locked:bool,unlock_at:?string}>
     */
    public function moduleGates(int $userId, object $course): array
    {
        $now = current_time('mysql', true);
        $times = $this->access->unlockTimes($userId, $course->code, AccessService::QUESTION_BANK); // eine Abfrage für alle Module
        $out = [];
        foreach ($this->content->modules((int) $course->id) as $module) {
            $at = $times[(string) $module->code] ?? null;
            $out[(int) $module->id] = ['code' => (string) $module->code, 'locked' => $at !== null && $at > $now, 'unlock_at' => $at];
        }

        return $out;
    }

    /**
     * Fragen, die der Nutzer in diesen Modulen lernen darf: ohne gesperrte
     * Module, bei Teilumfang nur die ersten n Fragen je Modul.
     *
     * @param list<int> $moduleIds
     * @param array<int,array{locked:bool}> $gates
     * @return array<int,object> question_id => Zeile
     */
    public function allowedQuestions(int $userId, object $course, array $moduleIds, ?array $gates = null): array
    {
        // Innerhalb einer Anfrage mehrfach gebraucht (Kursdaten, Favoriten, Sammlungen): einmal berechnen.
        $gates ??= $this->moduleGates($userId, $course);
        $limit = $this->access->limit($userId, $course->code, AccessService::QUESTION_BANK);
        $locked = array_keys(array_filter($gates, static fn ($g) => $g['locked']));
        $key = $userId.'|'.$course->code.'|'.implode(',', array_map('intval', $moduleIds)).'|'.var_export($limit, true).'|'.implode(',', $locked);

        return $this->allowedMemo[$key] ??= $this->computeAllowed($userId, $course, $moduleIds, $gates);
    }

    /** @param list<int> $moduleIds @return array<int,object> */
    private function computeAllowed(int $userId, object $course, array $moduleIds, ?array $gates): array
    {
        $gates ??= $this->moduleGates($userId, $course);
        $limit = $this->access->limit($userId, $course->code, AccessService::QUESTION_BANK);
        if ($limit === 0) {
            return [];
        }
        $moduleIds = array_values(array_filter($moduleIds, static fn ($id) => isset($gates[$id]) && ! $gates[$id]['locked']));
        $questions = [];
        $perModule = [];
        foreach ($this->content->questionsForModules($moduleIds) as $row) {
            $m = (int) $row->module_id;
            if ($limit !== null && ($perModule[$m] ?? 0) >= $limit) {
                continue;
            }
            $perModule[$m] = ($perModule[$m] ?? 0) + 1;
            $questions[(int) $row->question_id] ??= $row;
        }

        return $questions;
    }

    /** @param list<int> $questionIds @return array{questions:int,new:int,unsure:int,learning:int,mastered:int,percent:int} */
    public function summary(int $userId, array $questionIds): array
    {
        return self::summaryFrom($questionIds, $this->progressFor($userId, $questionIds));
    }

    /** @param list<int> $questionIds @param array<int,object> $progress @return array{questions:int,new:int,unsure:int,learning:int,mastered:int,percent:int} */
    private static function summaryFrom(array $questionIds, array $progress): array
    {
        $out = ['questions' => count($questionIds), 'new' => 0, 'unsure' => 0, 'learning' => 0, 'mastered' => 0, 'percent' => 0];
        foreach ($questionIds as $id) {
            $out[$progress[$id]->learn_status ?? 'new']++;
        }
        $out['percent'] = $out['questions'] > 0 ? (int) floor($out['mastered'] * 100 / $out['questions']) : 0;

        return $out;
    }

    public function setFavorite(int $userId, int $questionId, bool $on): void
    {
        if ($on) {
            if (! $this->content->isDeliverable($questionId)) {
                throw new NotFound('Frage nicht gefunden.');
            }
            $this->db->query($this->db->prepare(
                "INSERT IGNORE INTO {$this->t('favorite')} (user_id, question_id, context, created_at) VALUES (%d, %d, 'question', %s)",
                $userId, $questionId, current_time('mysql', true)
            ));
        } else {
            $this->db->delete($this->t('favorite'), ['user_id' => $userId, 'question_id' => $questionId, 'context' => 'question']);
            $this->db->query($this->db->prepare(
                "DELETE i FROM {$this->t('favorite_collection_item')} i JOIN {$this->t('favorite_collection')} c ON c.id = i.collection_id WHERE c.user_id = %d AND i.question_id = %d",
                $userId,
                $questionId
            ));
        }
    }

    /** @return list<int> */
    public function favoriteIds(int $userId): array
    {
        return array_map('intval', $this->db->get_col($this->db->prepare("SELECT question_id FROM {$this->t('favorite')} WHERE user_id = %d AND context = 'question'", $userId)));
    }

    // ------------------------------------------------------------------

    /** Wie MasteryCalculator der Quelle; Zeile wird für die Aktualisierung gesperrt. */
    private function applyProgress(int $userId, int $questionId, bool $correct, string $now): void
    {
        $this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->t('progress')} (user_id, question_id) VALUES (%d, %d)", $userId, $questionId));
        $p = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('progress')} WHERE user_id = %d AND question_id = %d FOR UPDATE", $userId, $questionId));
        $attempts = (int) $p->attempts + 1;
        $correctCount = (int) $p->correct_count + ($correct ? 1 : 0);
        $wrongCount = (int) $p->wrong_count + ($correct ? 0 : 1);
        $streak = $correct ? (int) $p->streak + 1 : 0;
        $mastery = round(min(1, 0.4 * ($correctCount / $attempts) + 0.6 * (min($streak, 3) / 3)), 4);
        $status = $streak >= 3 ? 'mastered' : ($streak === 0 ? 'unsure' : 'learning');
        $this->db->update($this->t('progress'), [
            'attempts' => $attempts,
            'correct_count' => $correctCount,
            'wrong_count' => $wrongCount,
            'streak' => $streak,
            'mastery_score' => $mastery,
            'learn_status' => $status,
            'first_answered_at' => $p->first_answered_at ?: $now,
            'last_answered_at' => $now,
        ], ['id' => (int) $p->id]);
    }

    /** @param list<int> $selected @return array<string,mixed> */
    private function result(int $userId, string $courseCode, object $revision, bool $correct, array $selected, bool $repeated): array
    {
        $explain = $this->access->can($userId, $courseCode, AccessService::EXPLANATIONS);
        $p = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('progress')} WHERE user_id = %d AND question_id = %d", $userId, (int) $revision->question_id));
        $correctIds = [];
        foreach ($this->content->answers((int) $revision->id) as $a) {
            if ((int) $a->is_correct === 1) {
                $correctIds[] = (int) $a->id;
            }
        }

        return [
            'correct' => $correct,
            'correct_ids' => $revision->question_type === 'open_text' ? [] : $correctIds,
            'selected_ids' => $selected,
            'model_answer' => $revision->question_type === 'open_text' ? $this->presenter->modelAnswer((int) $revision->id) : null,
            'explanation' => $explain ? ($correct ? $revision->feedback_correct : $revision->feedback_incorrect) : null,
            'explanation_locked' => ! $explain && ($revision->feedback_correct || $revision->feedback_incorrect),
            'progress' => $this->progressPayload($p ?: null),
            'repeated' => $repeated,
        ];
    }

    /** @return array{status:string,streak:int,attempts:int}|null */
    private function progressPayload(?object $p): ?array
    {
        return $p === null ? null : ['status' => (string) $p->learn_status, 'streak' => (int) $p->streak, 'attempts' => (int) $p->attempts];
    }

    /** @return list<int> */
    private function attemptAnswerIds(int $attemptId): array
    {
        return array_map('intval', $this->db->get_col($this->db->prepare("SELECT answer_id FROM {$this->t('attempt_answer')} WHERE attempt_id = %d ORDER BY position", $attemptId)));
    }

    /** @param list<int> $questionIds @return array<int,object> */
    private function progressFor(int $userId, array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t('progress')} WHERE user_id = %d AND question_id IN (".implode(',', array_map('intval', $questionIds)).')',
            $userId
        ));
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->question_id] = $row;
        }

        return $out;
    }

    /** @param array<int,object> $questions @param array<int,object> $progress @return array<string,float> */
    private function topicAccuracy(array $questions, array $progress): array
    {
        $attempts = [];
        $correct = [];
        foreach ($questions as $id => $q) {
            $topic = (string) ($q->topic ?? '—');
            $attempts[$topic] = ($attempts[$topic] ?? 0) + (int) ($progress[$id]->attempts ?? 0);
            $correct[$topic] = ($correct[$topic] ?? 0) + (int) ($progress[$id]->correct_count ?? 0);
        }
        $out = [];
        foreach ($attempts as $topic => $n) {
            $out[$topic] = $n > 0 ? $correct[$topic] / $n : 1.0;
        }

        return $out;
    }

    /** @param array<string,float> $topicAccuracy @return array{0:float,1:string} */
    private function score(object $q, ?object $p, array $topicAccuracy): array
    {
        if ($p === null || (int) $p->attempts === 0) {
            return [1000, __('Noch nie gesehen', 'bootspruefung-learning')];
        }
        if (self::lastWrong($p)) {
            return [800 + min((int) $p->wrong_count, 10) * 10, __('Zuletzt falsch beantwortet', 'bootspruefung-learning')];
        }
        $topic = $q->topic ?? null;
        $base = (1 - (float) $p->mastery_score) * 300;
        if ($topic && ($topicAccuracy[$topic] ?? 1) < 0.6) {
            return [$base + 150, sprintf(__('Schwaches Thema: %s', 'bootspruefung-learning'), $topic)];
        }

        return [$base, __('Gefestigt, niedrige Priorität', 'bootspruefung-learning')];
    }

    /** Gemerkte Fragen im freigeschalteten Umfang des Kurses. */
    public function courseFavoriteCount(int $userId, object $course): int
    {
        $ids = $this->allowedQuestions($userId, $course, array_map(static fn ($m) => (int) $m->id, $this->content->modules((int) $course->id)));

        return count(array_intersect_key($ids, array_flip($this->favoriteIds($userId))));
    }

    /** Zuletzt falsch beantwortete Fragen aus der Auswahl. @param list<int> $questionIds @return list<int> */
    public function wrongIds(int $userId, array $questionIds): array
    {
        $progress = $this->progressFor($userId, $questionIds);

        return array_values(array_filter($questionIds, static fn ($id) => self::lastWrong($progress[$id] ?? null)));
    }

    private static function lastWrong(?object $p): bool
    {
        return $p !== null && (int) $p->attempts > 0 && (int) $p->streak === 0;
    }

    /** @param array<int,object> $questions @param array<int,object> $progress */
    private function remaining(string $mode, array $questions, array $progress): int
    {
        $n = 0;
        foreach (array_keys($questions) as $id) {
            $p = $progress[$id] ?? null;
            $n += match ($mode) {
                'new' => $p === null ? 1 : 0,
                'wrong' => self::lastWrong($p) ? 1 : 0,
                default => 1,
            };
        }

        return $n;
    }

    /** @return list<int> */
    private function runningExamQuestionIds(int $userId): array
    {
        return array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT sq.question_id FROM {$this->t('exam_session')} s JOIN {$this->t('exam_session_question')} sq ON sq.session_id = s.id
             WHERE s.user_id = %d AND s.status = 'running' AND (s.deadline_at IS NULL OR s.deadline_at > %s)",
            $userId,
            current_time('mysql', true)
        )));
    }

    private function rateLimit(int $userId): void
    {
        $key = 'bp_rl_'.$userId.'_'.intdiv(time(), 60);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            throw new RuntimeException('Zu viele Antworten in kurzer Zeit. Bitte kurz warten.');
        }
        set_transient($key, $count + 1, 120);
    }
}
