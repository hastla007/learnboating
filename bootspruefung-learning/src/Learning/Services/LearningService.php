<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Lernmodus und Smarttrainer (Kap. 6.1, 6.2), ursprünglich wie die Quelle
 * (SmarttrainerService, MasteryCalculator, LearningService), seit Schema 1.14.0 mit verteilter
 * Wiederholung (Expertenrunde 3, FACH-01/FACH-02):
 * - Stufen (learn_status): neu → unsicher (zuletzt falsch) → in Bearbeitung (1–2 richtige in Folge)
 *   → „sicher heute“ (secure, SECURE_STREAK = 3 richtige in Folge) → gefestigt (mastered: eine weitere
 *   richtige Antwort frühestens CONSOLIDATE_GAP_SECONDS nach „sicher heute“, also an einem anderen Tag);
 * - eine richtige Antwort zählt für die Serie bis „sicher heute“ nur, wenn seit der letzten Antwort auf
 *   diese Frage mindestens STREAK_GAP_SECONDS vergangen sind oder inzwischen andere Fragen beantwortet
 *   wurden (kein Kurzzeitgedächtnis, keine doppelte Zählung aus zwei Tabs); ab „sicher heute“ zählt sie
 *   nur, wenn die Wiederholung fällig ist (due_at);
 * - gefestigte Fragen kommen nach wachsendem Abstand wieder (REVIEW_INTERVAL_DAYS: 7, 21, dann 60 Tage);
 *   eine falsche Antwort setzt jede Stufe auf „unsicher“ zurück;
 * - Priorität im Smarttrainer: zuletzt falsch und seit REPEAT_WRONG_AFTER anderen Antworten (bzw.
 *   REPEAT_AFTER_SECONDS) nicht mehr gesehen (1100 + 10 je Fehler) → fällige Wiederholung (1050)
 *   → in Bearbeitung und seit REPEAT_LEARNING_AFTER Antworten nicht gesehen (1020) → nie gesehen (1000)
 *   → zuletzt falsch, noch zu frisch (800 + 10 je Fehler) → schwaches Thema (< 60 % richtig, +150)
 *   → übrige nach Lernstand; Gleichstand nach Modul- und Fragenreihenfolge, also deterministisch;
 * - Bestand aus der Zeit vor 1.14.0: „gefestigt“ bleibt gefestigt (kein rückwirkender Verlust); solche
 *   Fragen haben kein due_at und gelten 7 Tage nach der letzten Antwort als fällig;
 * - die zuletzt beantworteten Fragen (RECENT_EXCLUDE) kommen nicht sofort wieder, solange
 *   andere Fragen im Modus verfügbar sind;
 * - Fragen laufender Prüfungen werden im Lernmodus nicht gezeigt.
 */
final class LearningService
{
    public const MODES = ['smart', 'new', 'wrong', 'favorites'];
    private const RATE_LIMIT = 60;
    /** Mindestabstand (Sekunden), damit eine erneute richtige Antwort die Serie erhöht. */
    public const STREAK_GAP_SECONDS = 300;
    /** So viele zuletzt beantwortete Fragen nicht sofort wieder anbieten. */
    public const RECENT_EXCLUDE = 3;
    /** Richtige Antworten in Folge für „sicher heute“. */
    public const SECURE_STREAK = 3;
    /** Mindestabstand (Sekunden) von „sicher heute“ bis zur Antwort, die „gefestigt“ macht (anderer Tag). */
    public const CONSOLIDATE_GAP_SECONDS = 20 * HOUR_IN_SECONDS;
    /** Abstände (Tage) der Wiederholungen gefestigter Fragen; danach bleibt es beim letzten Wert. */
    public const REVIEW_INTERVAL_DAYS = [7, 21, 60];
    /** Falsch beantwortete Fragen nach so vielen anderen Antworten wieder vorlegen … */
    public const REPEAT_WRONG_AFTER = 4;
    /** … Fragen in Bearbeitung (1–2 richtige in Folge) nach so vielen … */
    public const REPEAT_LEARNING_AFTER = 8;
    /** … bzw. in jedem Fall, wenn die letzte Antwort so lange (Sekunden) zurückliegt. */
    public const REPEAT_AFTER_SECONDS = 600;
    /** Laufende Prüfungen ohne Zeitlimit sperren ihre Fragen nur so lange (Sekunden). */
    public const OPEN_EXAM_BLOCK_SECONDS = DAY_IN_SECONDS;

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
        // Abstand: die zuletzt beantworteten Fragen nur, wenn sonst nichts im Modus übrig ist.
        $steps = $this->stepsSinceLastAnswer($userId, array_keys($questions));
        $recent = array_flip(array_slice(array_keys($steps), 0, self::RECENT_EXCLUDE));
        $now = time();

        $best = null;
        $fallback = null;
        foreach ($questions as $id => $q) {
            $p = $progress[$id] ?? null;
            if ($mode === 'new' && $p !== null) {
                continue;
            }
            if ($mode === 'wrong' && ! self::lastWrong($p)) {
                continue;
            }
            [$score, $reason] = $this->score($q, $p, $topicAccuracy, $steps[$id] ?? null, $now);
            if (isset($recent[$id])) {
                if ($fallback === null || $score > $fallback['score']) {
                    $fallback = ['question' => $q, 'score' => $score, 'reason' => $reason];
                }

                continue;
            }
            if ($best === null || $score > $best['score']) {
                $best = ['question' => $q, 'score' => $score, 'reason' => $reason];
            }
        }
        $best ??= $fallback;
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
        [$data, , , $revision] = $this->tokenContext($userId, $token);
        if ($revision->question_type !== 'open_text') {
            throw new RuntimeException('Nur für offene Fragen.');
        }

        return ['model_answer' => $this->presenter->modelAnswer($data['r'])];
    }

    /**
     * Prüft ein Frage-Token vollständig: Signatur/Konto/Alter, Kontext, aktueller Zugriff auf
     * den Kurs (z. B. nach Erstattung) und ob die Frage noch ausgeliefert werden darf
     * (Rechte, Status, Gültigkeit).
     * @return array{0:array<string,mixed>,1:string,2:string,3:object} [Token-Daten, Kontext, Kurscode, Revision]
     */
    private function tokenContext(int $userId, string $token): array
    {
        $data = $this->presenter->verify($token, $userId);
        [$context, $courseCode] = array_pad(explode(':', (string) $data['c'], 2), 2, '');
        $feature = $context === 'video' ? AccessService::VIDEO : AccessService::QUESTION_BANK;
        if (! in_array($context, ['learn', 'video'], true) || ! $this->access->can($userId, $courseCode, $feature)) {
            throw new AccessDenied('Kein Zugriff auf diesen Kurs.');
        }
        $revision = $this->content->revision((int) $data['r']);
        if ($revision === null || ! $this->content->isDeliverable((int) $revision->question_id)) {
            throw new NotFound('Diese Frage ist derzeit nicht verfügbar.');
        }

        return [$data, $context, $courseCode, $revision];
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
        [$data, $context, $courseCode, $revision] = $this->tokenContext($userId, $token);
        // Fragen einer laufenden Prüfung nicht nebenbei (Lernmodus/Videoquiz) mit Lösung auswerten.
        if (in_array((int) $revision->question_id, $this->runningExamQuestionIds($userId), true)) {
            throw new RuntimeException('Diese Frage gehört zu deiner laufenden Prüfung. Beende zuerst die Prüfung.');
        }

        // Ein Frage-Token zählt nur einmal: der Idempotenzschlüssel wird aus dem Token abgeleitet, erneutes
        // Einreichen (auch mit anderem Client-Key) liefert das erste Ergebnis – ohne Zwischenspeicher in wp_options.
        $h = hash('sha256', 'bp-token|'.$token);
        $idempotencyKey = substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);

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
            $counted = $this->applyProgress($userId, (int) $revision->question_id, $correct, $now);
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        return $this->result($userId, $courseCode, $revision, $correct, $selected, false) + ['streak_counted' => $counted];
    }

    /** Gesamtfortschritt eines Kurses (wie courseProgress()['total'], ohne Module/Themen). @return array<string,int> */
    public function courseTotal(int $userId, object $course): array
    {
        $ids = array_keys($this->allowedQuestions($userId, $course, array_map(static fn ($m) => (int) $m->id, $this->content->modules((int) $course->id))));

        return self::summaryFrom($ids, $this->progressFor($userId, $ids));
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

    /**
     * @param list<int> $questionIds
     * @return array{questions:int,new:int,unsure:int,learning:int,secure:int,mastered:int,percent:int,secure_percent:int,due:int}
     */
    public function summary(int $userId, array $questionIds): array
    {
        return self::summaryFrom($questionIds, $this->progressFor($userId, $questionIds));
    }

    /**
     * Zählung je Stufe. percent = Anteil gefestigt (wie bisher); secure = „sicher heute“ (noch nicht
     * gefestigt), secure_percent dessen Anteil; due = sichere bzw. gefestigte Fragen, deren Wiederholung fällig ist.
     * @param list<int> $questionIds @param array<int,object> $progress
     * @return array{questions:int,new:int,unsure:int,learning:int,secure:int,mastered:int,percent:int,secure_percent:int,due:int}
     */
    private static function summaryFrom(array $questionIds, array $progress): array
    {
        $out = ['questions' => count($questionIds), 'new' => 0, 'unsure' => 0, 'learning' => 0, 'secure' => 0, 'mastered' => 0, 'percent' => 0, 'secure_percent' => 0, 'due' => 0];
        $now = time();
        foreach ($questionIds as $id) {
            $p = $progress[$id] ?? null;
            $status = (string) ($p->learn_status ?? 'new');
            $out[in_array($status, ['unsure', 'learning', 'secure', 'mastered'], true) ? $status : 'new']++;
            if ($p !== null && self::isDue($p, $now)) {
                $out['due']++;
            }
        }
        $out['percent'] = $out['questions'] > 0 ? (int) floor($out['mastered'] * 100 / $out['questions']) : 0;
        $out['secure_percent'] = $out['questions'] > 0 ? (int) floor($out['secure'] * 100 / $out['questions']) : 0;

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

    /**
     * Wie MasteryCalculator der Quelle, erweitert um die Stufen „sicher heute“ und „gefestigt“ mit
     * Fälligkeit (siehe Klassenkommentar); Zeile wird für die Aktualisierung gesperrt.
     * Eine richtige Antwort erhöht die Serie nur mit Abstand bzw. wenn die Wiederholung fällig ist;
     * sonst bleiben Serie, Stufe und Fälligkeit unverändert (falsch setzt alles auf „unsicher“ zurück).
     * @return bool|null null bei falscher Antwort, sonst ob die Serie erhöht wurde
     */
    private function applyProgress(int $userId, int $questionId, bool $correct, string $now): ?bool
    {
        $this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->t('progress')} (user_id, question_id) VALUES (%d, %d)", $userId, $questionId));
        $p = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('progress')} WHERE user_id = %d AND question_id = %d FOR UPDATE", $userId, $questionId));
        $attempts = (int) $p->attempts + 1;
        $correctCount = (int) $p->correct_count + ($correct ? 1 : 0);
        $wrongCount = (int) $p->wrong_count + ($correct ? 0 : 1);
        $previous = (string) $p->learn_status;
        $nowTs = (int) strtotime($now.' UTC');
        $dueAt = $p->due_at ?? null;
        if (! $correct) {
            $counted = null;
            $streak = 0;
            $status = 'unsure';
            $dueAt = null;
        } elseif ($previous === 'secure' || $previous === 'mastered') {
            // Ab „sicher heute“ zählt nur die fällige Wiederholung (anderer Tag bzw. wachsender Abstand).
            $counted = self::isDue($p, $nowTs);
            $streak = (int) $p->streak + ($counted ? 1 : 0);
            $status = $counted ? 'mastered' : $previous;
            if ($counted) {
                $dueAt = gmdate('Y-m-d H:i:s', $nowTs + self::reviewInterval($streak));
            }
        } else {
            $counted = $this->spaced($userId, $questionId, $p->last_answered_at, $now);
            $streak = (int) $p->streak + ($counted ? 1 : 0);
            $status = $streak >= self::SECURE_STREAK ? 'secure' : 'learning';
            $dueAt = $status === 'secure' ? gmdate('Y-m-d H:i:s', $nowTs + self::CONSOLIDATE_GAP_SECONDS) : null;
        }
        $mastery = round(min(1, 0.4 * ($correctCount / $attempts) + 0.6 * (min($streak, 3) / 3)), 4);
        $this->db->update($this->t('progress'), [
            'attempts' => $attempts,
            'correct_count' => $correctCount,
            'wrong_count' => $wrongCount,
            'streak' => $streak,
            'mastery_score' => $mastery,
            'learn_status' => $status,
            'first_answered_at' => $p->first_answered_at ?: $now,
            'last_answered_at' => $now,
            'due_at' => $dueAt,
        ], ['id' => (int) $p->id]);

        return $counted;
    }

    /** Abstand bis zur nächsten Wiederholung einer gefestigten Frage mit dieser Serie (Sekunden). */
    private static function reviewInterval(int $streak): int
    {
        $days = self::REVIEW_INTERVAL_DAYS;
        $index = max(0, min(count($days) - 1, $streak - self::SECURE_STREAK - 1));

        return $days[$index] * DAY_IN_SECONDS;
    }

    /**
     * Fälligkeit der nächsten Wiederholung (UTC, „Y-m-d H:i:s“) ab „sicher heute“, sonst null. Ohne due_at
     * (Bestand vor 1.14.0): „sicher heute“ CONSOLIDATE_GAP_SECONDS, gefestigt 7 Tage nach der letzten Antwort.
     */
    public static function dueAt(?object $p): ?string
    {
        $status = (string) ($p->learn_status ?? '');
        if ($p === null || ($status !== 'secure' && $status !== 'mastered')) {
            return null;
        }
        if (! empty($p->due_at)) {
            return (string) $p->due_at;
        }
        $last = ! empty($p->last_answered_at) ? (int) strtotime($p->last_answered_at.' UTC') : time();

        return gmdate('Y-m-d H:i:s', $last + ($status === 'secure' ? self::CONSOLIDATE_GAP_SECONDS : self::REVIEW_INTERVAL_DAYS[0] * DAY_IN_SECONDS));
    }

    private static function isDue(object $p, int $now): bool
    {
        $due = self::dueAt($p);

        return $due !== null && (int) strtotime($due.' UTC') <= $now;
    }

    /**
     * Zählt eine richtige Antwort für die Serie? Ja beim ersten Mal, nach STREAK_GAP_SECONDS
     * oder wenn seit der letzten Antwort auf diese Frage andere Fragen beantwortet wurden.
     * Läuft in der Transaktion mit gesperrter Fortschrittszeile (zweiter Tab wartet und sieht
     * danach die frische last_answered_at).
     */
    private function spaced(int $userId, int $questionId, ?string $lastAnsweredAt, string $now): bool
    {
        if ($lastAnsweredAt === null || $lastAnsweredAt === '') {
            return true;
        }
        if (strtotime($now.' UTC') - strtotime($lastAnsweredAt.' UTC') >= self::STREAK_GAP_SECONDS) {
            return true;
        }

        return (bool) $this->db->get_var($this->db->prepare(
            "SELECT 1 FROM {$this->t('attempt')} WHERE user_id = %d AND created_at >= %s AND question_id <> %d LIMIT 1",
            $userId,
            $lastAnsweredAt,
            $questionId
        ));
    }

    /**
     * Wie viele andere Antworten (alle Lernbereiche) seit der letzten Antwort auf die Fragen der Auswahl
     * gegeben wurden – nur für Fragen unter den letzten 100 Antworten der letzten 2 Stunden, die zuletzt
     * beantwortete zuerst; fehlende Fragen liegen länger zurück.
     * @param list<int> $questionIds
     * @return array<int,int> question_id => Anzahl anderer Antworten seitdem
     */
    private function stepsSinceLastAnswer(int $userId, array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }
        $in = array_flip($questionIds);
        $out = [];
        foreach ($this->db->get_col($this->db->prepare(
            "SELECT question_id FROM {$this->t('attempt')} WHERE user_id = %d AND created_at >= %s ORDER BY created_at DESC, id DESC LIMIT 100",
            $userId,
            gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS)
        )) as $index => $id) {
            $id = (int) $id;
            if (isset($in[$id]) && ! isset($out[$id])) {
                $out[$id] = $index;
            }
        }

        return $out;
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

    /** @return array{status:string,streak:int,attempts:int,due_at:?string}|null due_at: nächste Wiederholung (UTC) ab „sicher heute“ */
    private function progressPayload(?object $p): ?array
    {
        return $p === null ? null : ['status' => (string) $p->learn_status, 'streak' => (int) $p->streak, 'attempts' => (int) $p->attempts, 'due_at' => self::dueAt($p)];
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

    /**
     * @param array<string,float> $topicAccuracy
     * @param int|null $steps andere Antworten seit der letzten Antwort auf diese Frage (null = länger her)
     * @return array{0:float,1:string}
     */
    private function score(object $q, ?object $p, array $topicAccuracy, ?int $steps = null, ?int $now = null): array
    {
        $now ??= time();
        if ($p === null || (int) $p->attempts === 0) {
            return [1000, __('Noch nie gesehen', 'bootspruefung-learning')];
        }
        $since = ! empty($p->last_answered_at) ? $now - (int) strtotime($p->last_answered_at.' UTC') : PHP_INT_MAX;
        $ripe = static fn (int $after): bool => $steps === null || $steps >= $after || $since >= self::REPEAT_AFTER_SECONDS;
        if (self::lastWrong($p)) {
            // Fehler nach wenigen anderen Fragen wiederholen – vor neuen Fragen (FACH-01).
            return [($ripe(self::REPEAT_WRONG_AFTER) ? 1100 : 800) + min((int) $p->wrong_count, 10) * 10, __('Zuletzt falsch beantwortet', 'bootspruefung-learning')];
        }
        $status = (string) $p->learn_status;
        if (self::isDue($p, $now)) {
            return [1050, $status === 'secure'
                ? __('Heute sicher – Wiederholung an einem neuen Tag', 'bootspruefung-learning')
                : __('Wiederholung fällig', 'bootspruefung-learning')];
        }
        if ($status === 'learning' && $ripe(self::REPEAT_LEARNING_AFTER)) {
            return [1020, __('Wiederholung zur Festigung', 'bootspruefung-learning')];
        }
        $base = (1 - (float) $p->mastery_score) * 300;
        if ($status === 'secure' || $status === 'mastered') {
            // Noch nicht fällig: nur, wenn sonst nichts übrig ist (z. B. alles sicher, Modus „Favoriten“).
            return [$base * 0.1, $status === 'mastered'
                ? __('Gefestigt, niedrige Priorität', 'bootspruefung-learning')
                : __('Heute sicher, niedrige Priorität', 'bootspruefung-learning')];
        }
        $topic = $q->topic ?? null;
        if ($topic && ($topicAccuracy[$topic] ?? 1) < 0.6) {
            return [$base + 150, sprintf(__('Schwaches Thema: %s', 'bootspruefung-learning'), $topic)];
        }

        return [$base, __('Wiederholung zur Festigung', 'bootspruefung-learning')];
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
        return self::blockedByRunningExam($userId);
    }

    /**
     * Fragen laufender Prüfungen (für Lernmodus und Videoquiz gesperrt). Sitzungen mit Frist
     * nur bis zum Fristende, Sitzungen ohne Zeitlimit nur OPEN_EXAM_BLOCK_SECONDS nach dem Start –
     * eine vergessene Übungsprüfung sperrt ihre Fragen nicht dauerhaft.
     * @return list<int>
     */
    public static function blockedByRunningExam(int $userId): array
    {
        global $wpdb;
        $now = time();

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT sq.question_id FROM {$wpdb->prefix}bp_exam_session s JOIN {$wpdb->prefix}bp_exam_session_question sq ON sq.session_id = s.id
             WHERE s.user_id = %d AND s.status = 'running'
               AND ((s.deadline_at IS NOT NULL AND s.deadline_at > %s) OR (s.deadline_at IS NULL AND s.started_at > %s))",
            $userId,
            // Bis zum Ende der Kulanz für nachgesendete Prüfungsantworten gesperrt (nicht nachschlagen und nachreichen).
            gmdate('Y-m-d H:i:s', $now - \Bootspruefung\Learning\Exams\Services\ExamService::LATE_TOLERANCE_SECONDS),
            gmdate('Y-m-d H:i:s', $now - self::OPEN_EXAM_BLOCK_SECONDS)
        )));
    }

    private function rateLimit(int $userId): void
    {
        $key = 'bp_rl_'.$userId.'_'.intdiv(time(), 60);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            throw new \Bootspruefung\Learning\Support\DomainException(\Bootspruefung\Learning\Support\DomainException::ANSWER_RATE_LIMITED, 'Zu viele Antworten in kurzer Zeit. Bitte kurz warten.');
        }
        set_transient($key, $count + 1, 120);
    }
}
