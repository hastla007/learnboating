<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Idempotency;
use Bootspruefung\Learning\Mobile\Mapper\QuestionMapper;
use Bootspruefung\Learning\Mobile\TokenAuth;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Smarttrainer und Lernmodi, Antworten, Favoriten und Sammlungen – über LearningService und
 * CollectionService, also mit denselben Tabellen, Regeln (Priorität, Serie, Abstand, Teilumfang,
 * zeitgesteuerte Freigabe, Sperre laufender Prüfungsfragen) und demselben Lernstand wie das Web.
 */
final class LearningController extends BaseController
{
    /** App-Modus → Web-Modus (LearningService::MODES). */
    /** @var array<int,string>|null */
    private ?array $collectionDates = null;

    private const MODES = [
        'smarttrainer' => 'smart', 'new' => 'new', 'wrong' => 'wrong', 'favorites' => 'favorites',
        'topic' => 'smart', 'module' => 'smart', 'collection' => 'smart', 'exam_mistakes' => 'smart', 'question' => 'smart',
    ];

    public function next(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $mode = (string) ($request['mode'] ?: 'smarttrainer');
        $questionParam = $request['question_id'] !== null && (string) $request['question_id'] !== '' ? (string) $request['question_id'] : null;
        if ($mode === 'question') {
            return $this->questionPayload($this->specificQuestion($user, $course, $questionParam), $mode);
        }
        if ($questionParam !== null) {
            throw ApiException::validation(['question_id' => [__('question_id gilt nur zusammen mit mode=question.', 'bootspruefung-learning')]]);
        }
        $moduleId = $request['module_id'] !== null && $request['module_id'] !== '' ? (int) $request['module_id'] : null;
        $topic = $request['topic'] !== null && trim((string) $request['topic']) !== '' ? trim((string) $request['topic']) : null;
        if ($mode === 'topic' && $topic === null) {
            throw ApiException::validation(['topic' => [__('Bitte wähle ein Thema.', 'bootspruefung-learning')]]);
        }
        if ($mode === 'module' && $moduleId === null) {
            throw ApiException::validation(['module_id' => [__('Bitte wähle ein Modul.', 'bootspruefung-learning')]]);
        }
        if ($moduleId !== null && ! in_array($moduleId, array_map(static fn ($m) => (int) $m->id, $this->content->modules((int) $course->id)), true)) {
            throw ApiException::notFound(__('Modul nicht gefunden.', 'bootspruefung-learning'));
        }
        $only = null;
        if ($mode === 'collection') {
            $collectionId = (int) $request['collection_id'];
            $this->ownCollection($course, $collectionId);
            $only = $this->collections->questionIds($user, $course, $collectionId);
        } elseif ($mode === 'exam_mistakes') {
            $examId = (int) $request['exam_id'];
            if ($examId <= 0) {
                throw ApiException::validation(['exam_id' => [__('Bitte gib die Prüfung an.', 'bootspruefung-learning')]]);
            }
            $only = $this->exams->wrongQuestionIds($user, $examId);
        }
        $q = $this->learning->next($user, $course, $moduleId, self::MODES[$mode] ?? 'smart', $topic, $only);
        if ($q === null) {
            return $this->noContent();
        }

        return $this->questionPayload($q, $mode);
    }

    /** @param array<string,mixed> $q Ergebnis von LearningService::next */
    private function questionPayload(array $q, string $mode): WP_REST_Response
    {
        return $this->ok([
            'question' => QuestionMapper::question($q),
            'answer_token' => (string) $q['token'],
            'answer_token_expires_in' => \Bootspruefung\Learning\Learning\Services\QuestionPresenter::TTL,
            'mode' => $mode,
            'selection_reason' => $q['reason'] ?? null,
            'favorite' => (bool) $q['favorite'],
            'progress' => QuestionMapper::progress($q['progress'] ?? null),
            'remaining' => $q['remaining'] ?? null,
        ]);
    }

    /**
     * Eine bestimmte Frage gezielt üben (seit 1.7.0, mode=question): dieselbe Auslieferung wie der
     * Smarttrainer – LearningService::next mit der Fragenauswahl [question_id] (wie Sammlungen) –,
     * also derselbe Freigabeumfang (Teilumfang je Modul, zeitgesteuerte Freigabe, Sperre laufender
     * Prüfungsfragen) und ein normales answer_token (Antwort zählt in denselben Lernstand).
     * Liefert der Service nichts, wird nur der Grund ermittelt: nicht im Kurs → 404, sonst 403 CONTENT_LOCKED.
     * @return array<string,mixed>
     */
    private function specificQuestion(int $user, object $course, ?string $raw): array
    {
        if ($raw === null) {
            throw ApiException::validation(['question_id' => [__('Bitte gib die Frage an (question_id).', 'bootspruefung-learning')]]);
        }
        $questionId = ctype_digit($raw) ? (int) $raw : 0;
        $gates = $this->learning->moduleGates($user, $course);
        $rows = $questionId > 0 ? array_values(array_filter($this->content->questionsForModules(array_keys($gates)), static fn ($row) => (int) $row->question_id === $questionId)) : [];
        if ($rows === []) {
            throw ApiException::notFound(__('Diese Frage gehört nicht zu diesem Kurs oder ist derzeit nicht verfügbar.', 'bootspruefung-learning'));
        }
        $q = $this->learning->next($user, $course, null, 'smart', null, [$questionId]);
        if ($q !== null) {
            return $q;
        }
        $details = ['course_id' => (string) $course->id, 'question_id' => (string) $questionId];
        $open = array_values(array_filter($rows, static fn ($row) => ! ($gates[(int) $row->module_id]['locked'] ?? false)));
        if ($open === []) {
            $unlock = min(array_map(static fn ($row) => (string) $gates[(int) $row->module_id]['unlock_at'], $rows));
            throw new ApiException('CONTENT_LOCKED', sprintf(__('Dieses Modul wird am %s freigeschaltet.', 'bootspruefung-learning'), wp_date(get_option('date_format'), strtotime($unlock.' UTC'))), 403, ['details' => $details + [
                'reason' => 'scheduled_release', 'module_id' => (string) $rows[0]->module_id, 'unlock_at' => TokenAuth::iso($unlock),
            ]]);
        }
        $allowed = $this->learning->allowedQuestions($user, $course, array_keys($gates), $gates);
        if (! isset($allowed[$questionId])) {
            $offers = $this->courses->offers($user, $course);
            throw new ApiException('CONTENT_LOCKED', __('Diese Frage gehört nicht zum freigeschalteten Umfang deines Zugangs.', 'bootspruefung-learning'), 403, ['details' => $details + [
                'reason' => 'partial_access', 'module_id' => (string) $open[0]->module_id, 'webshop_url' => $offers['smarttrainer'][0]['url'] ?? null,
            ]]);
        }
        throw new ApiException('CONTENT_LOCKED', __('Diese Frage gehört zu deiner laufenden Prüfung. Gib die Prüfung zuerst ab.', 'bootspruefung-learning'), 403, ['details' => $details + ['reason' => 'running_exam']]);
    }

    /** Musterantwort einer offenen Frage (vor der Selbsteinschätzung „gewusst/nicht gewusst“). */
    public function reveal(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $token = (string) $request['answer_token'];
        $this->tokenCourse($course, $token);

        return $this->ok($this->learning->reveal($this->user(), $token));
    }

    public function attempt(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $token = (string) $request['answer_token'];
        $data = $this->tokenCourse($course, $token);
        if ($request['revision_id'] !== null && (string) $request['revision_id'] !== '' && (int) $request['revision_id'] !== (int) $data['r']) {
            throw ApiException::validation(['revision_id' => [__('Die Antwort passt nicht zur ausgelieferten Frage. Bitte lade die Frage neu.', 'bootspruefung-learning')]]);
        }
        $revision = $this->content->revision((int) $data['r']);
        if ($revision === null) {
            throw ApiException::notFound(__('Diese Frage ist derzeit nicht verfügbar.', 'bootspruefung-learning'));
        }
        if ($request['question_id'] !== null && (string) $request['question_id'] !== '' && (int) $request['question_id'] !== (int) $revision->question_id) {
            throw ApiException::validation(['question_id' => [__('Die Antwort passt nicht zur ausgelieferten Frage. Bitte lade die Frage neu.', 'bootspruefung-learning')]]);
        }
        $key = (string) $this->idempotencyKey($request, true);
        $selected = QuestionMapper::ids($request['selected_answer_ids']);
        $known = $request['known'] === null ? null : (bool) $request['known'];
        $responseMs = $request['response_ms'] === null ? null : (int) $request['response_ms'];
        $payload = ['token' => hash('sha256', $token), 'selected' => $selected, 'known' => $known];

        [$status, $body] = Idempotency::run($user, 'attempt:'.$course->id, $key, $payload, function () use ($user, $token, $selected, $known, $key, $responseMs, $revision): array {
            $result = $this->learning->answer($user, $token, $selected, $known, $key, $responseMs);
            global $wpdb;
            $attemptId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}bp_attempt WHERE user_id = %d AND revision_id = %d ORDER BY id DESC LIMIT 1",
                $user, (int) $revision->id
            ));

            return [$result['repeated'] ? 200 : 201, ['data' => QuestionMapper::answerResult($result, $attemptId, (int) $revision->question_id, (int) $revision->id)], 'attempt:'.$attemptId];
        });

        return new WP_REST_Response($body, $status);
    }

    public function attemptShow(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $id = (int) $request['attemptId'];
        global $wpdb;
        $row = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bp_attempt WHERE id = %d AND user_id = %d AND context_ref = %s", $id, $user, $course->code)) : null;
        if (! $row) {
            throw ApiException::notFound(__('Antwort nicht gefunden.', 'bootspruefung-learning'));
        }
        $stored = Idempotency::byResource($user, 'attempt:'.$id);
        if (is_array($stored) && isset($stored['data'])) {
            return $this->ok($stored['data']);
        }
        // Nach Ablauf der gespeicherten Rückmeldung: Kerndaten des Versuchs.
        return $this->ok([
            'attempt_id' => (string) $row->id,
            'question_id' => (string) $row->question_id,
            'revision_id' => (string) $row->revision_id,
            'correct' => (bool) $row->is_correct,
            'selected_answer_ids' => array_map('strval', $wpdb->get_col($wpdb->prepare("SELECT answer_id FROM {$wpdb->prefix}bp_attempt_answer WHERE attempt_id = %d ORDER BY position", $id))),
            'created_at' => \Bootspruefung\Learning\Mobile\TokenAuth::iso($row->created_at),
        ]);
    }

    // ------------------------------------------------------------------ Favoriten & Sammlungen

    /**
     * Favoriten, sortiert nach Speicherzeitpunkt (`saved_at` = bp_favorite.created_at) bzw. –
     * mit collection_id – nach Einsortierung in die Sammlung (`assigned_at` =
     * bp_favorite_collection_item.created_at); bei gleichem Zeitpunkt nach Frage-ID.
     */
    public function favorites(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $filter = $request['collection_id'] !== null && $request['collection_id'] !== '' ? (int) $request['collection_id'] : null;
        $sort = (string) ($request['sort'] ?: 'saved_at');
        $order = (string) ($request['order'] ?: 'desc');
        if ($sort === 'assigned_at' && $filter === null) {
            throw ApiException::validation(['sort' => [__('Nach assigned_at kann nur zusammen mit collection_id sortiert werden.', 'bootspruefung-learning')]]);
        }
        if ($filter !== null) {
            $this->ownCollection($course, $filter);
        }
        $overview = $this->collections->overview($this->user(), $course);
        $saved = $this->favoriteDates();
        $assigned = $filter !== null ? $this->assignmentDates($filter) : [];
        $items = [];
        foreach ($overview['items'] as $item) {
            $qid = (int) $item['question_id'];
            if (! $item['favorite'] || ($filter !== null && ! in_array($filter, $item['collections'], true))) {
                continue;
            }
            $payload = $this->favoritePayload($item, $saved);
            if ($filter !== null) {
                $payload['assigned_at'] = self::stamp($assigned[$qid] ?? null, $saved[$qid] ?? null);
            }
            $items[] = $payload;
        }
        $dir = $order === 'asc' ? 1 : -1;
        usort($items, static fn ($a, $b) => $dir * ((strcmp($a[$sort], $b[$sort]) ?: ((int) $a['question_id'] <=> (int) $b['question_id']))));

        return $this->ok($items, 200, ['sort' => $sort, 'order' => $order, 'collection_id' => $filter !== null ? (string) $filter : null, 'collections' => array_map([$this, 'collectionPayload'], $overview['collections'])]);
    }

    /** Zuletzt falsch beantwortete Fragen („Favoriten & Fehler“ im Web). */
    public function mistakes(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $saved = $this->favoriteDates();
        $items = [];
        foreach ($this->collections->overview($this->user(), $course)['items'] as $item) {
            if ($item['wrong']) {
                $items[] = $this->favoritePayload($item, $saved);
            }
        }

        return $this->ok($items);
    }

    public function favoriteAdd(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $questionId = (int) $request['questionId'];
        $current = $this->questionCollections($course, $questionId);
        if (is_array($request['collection_ids'])) {
            $target = QuestionMapper::ids($request['collection_ids']);
        } elseif ($request['collection_id'] !== null && $request['collection_id'] !== '') {
            $target = array_values(array_unique(array_merge($current, [(int) $request['collection_id']])));
        } else {
            $target = $current;
        }
        $own = array_map(static fn ($c) => $c['id'], $this->collections->collections($user, $course));
        if (array_diff($target, $own) !== []) {
            throw ApiException::notFound(__('Sammlung nicht gefunden.', 'bootspruefung-learning'));
        }
        if (! isset($this->learning->allowedQuestions($user, $course, array_map(static fn ($m) => (int) $m->id, $this->content->modules((int) $course->id)))[$questionId])) {
            throw ApiException::notFound(__('Diese Frage gehört nicht zum freigeschalteten Umfang des Kurses.', 'bootspruefung-learning'));
        }
        $result = $this->collections->assign($user, $course, $questionId, true, $target);
        $savedAt = self::stamp($this->favoriteDates()[$questionId] ?? null);

        return $this->ok([
            'question_id' => (string) $questionId,
            'context' => 'smart_learning',
            'collection_id' => isset($result['collections'][0]) ? (string) $result['collections'][0] : null,
            'collection_ids' => array_map('strval', $result['collections']),
            'saved_at' => $savedAt,
            'created_at' => $savedAt,
        ]);
    }

    public function favoriteRemove(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        // Wie im Web: Favorit gilt kontoweit; entfernt die Frage auch aus allen Sammlungen.
        $this->learning->setFavorite($this->user(), (int) $request['questionId'], false);

        return $this->noContent();
    }

    public function collectionsList(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);

        return $this->ok(array_map([$this, 'collectionPayload'], $this->collections->collections($this->user(), $course)));
    }

    public function collectionCreate(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $name = (string) $request['name'];
        [$status, $body] = Idempotency::run($user, 'collection:'.$course->id, $this->idempotencyKey($request, false), ['name' => $name], function () use ($user, $course, $name): array {
            $created = $this->collections->create($user, $course, $name);

            return [201, ['data' => $this->collectionPayload($created)], 'collection:'.$created['id']];
        });

        return new WP_REST_Response($body, $status);
    }

    public function collectionRename(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $id = (int) $request['collectionId'];
        $this->ownCollection($course, $id);
        $this->collections->rename($this->user(), $course, $id, (string) $request['name']);
        foreach ($this->collections->collections($this->user(), $course) as $c) {
            if ($c['id'] === $id) {
                return $this->ok($this->collectionPayload($c));
            }
        }
        throw ApiException::notFound(__('Sammlung nicht gefunden.', 'bootspruefung-learning'));
    }

    public function collectionDelete(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $id = (int) $request['collectionId'];
        $this->ownCollection($course, $id);
        $this->collections->delete($this->user(), $course, $id);

        return $this->noContent();
    }

    // ------------------------------------------------------------------

    /**
     * Frage-Token gehört zu diesem Kurs (Lernmodus „learn:CODE“ oder Videoquiz „video:CODE“)
     * und der Bereich ist (noch) freigeschaltet. Signatur, Konto und Alter prüft der Presenter.
     * @return array<string,mixed>
     */
    private function tokenCourse(object $course, string $token): array
    {
        if ($token === '') {
            throw ApiException::validation(['answer_token' => [__('Bitte sende das answer_token der Frage mit.', 'bootspruefung-learning')]]);
        }
        $data = $this->presenter->verify($token, $this->user());
        [$context, $code] = array_pad(explode(':', (string) $data['c'], 2), 2, '');
        if ($code !== (string) $course->code || ! in_array($context, ['learn', 'video'], true)) {
            throw ApiException::validation(['answer_token' => [__('Diese Frage gehört zu einem anderen Kurs.', 'bootspruefung-learning')]]);
        }
        $this->requireFeature($course, $context === 'video' ? AccessService::VIDEO : AccessService::QUESTION_BANK);

        return $data;
    }

    private function ownCollection(object $course, int $id): void
    {
        foreach ($this->collections->collections($this->user(), $course) as $c) {
            if ($c['id'] === $id) {
                return;
            }
        }
        throw ApiException::notFound(__('Sammlung nicht gefunden.', 'bootspruefung-learning'));
    }

    /** @return list<int> eigene Sammlungen dieses Kurses, in denen die Frage liegt */
    private function questionCollections(object $course, int $questionId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT i.collection_id FROM {$wpdb->prefix}bp_favorite_collection_item i JOIN {$wpdb->prefix}bp_favorite_collection c ON c.id = i.collection_id
             WHERE c.user_id = %d AND c.course_id = %d AND i.question_id = %d",
            $this->user(), (int) $course->id, $questionId
        )));
    }

    /** @return array<int,string> question_id => bp_favorite.created_at (UTC, wie gespeichert) */
    private function favoriteDates(): array
    {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT question_id, created_at FROM {$wpdb->prefix}bp_favorite WHERE user_id = %d AND context = 'question'", $this->user())) as $row) {
            $out[(int) $row->question_id] = (string) $row->created_at;
        }

        return $out;
    }

    /** @return array<int,string> question_id => bp_favorite_collection_item.created_at einer eigenen Sammlung */
    private function assignmentDates(int $collectionId): array
    {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT i.question_id, i.created_at FROM {$wpdb->prefix}bp_favorite_collection_item i JOIN {$wpdb->prefix}bp_favorite_collection c ON c.id = i.collection_id WHERE c.user_id = %d AND i.collection_id = %d",
            $this->user(),
            $collectionId
        )) as $row) {
            $out[(int) $row->question_id] = (string) $row->created_at;
        }

        return $out;
    }

    /**
     * Zeitstempel (RFC 3339, UTC), nie null: Die Spalten sind NOT NULL DEFAULT CURRENT_TIMESTAMP
     * und werden beim Speichern gesetzt. Fehlt ein Wert dennoch oder ist er ungültig (z. B.
     * Nulldatum aus einem Import ohne Strict-Mode), gilt der Ersatzwert, sonst 1970-01-01T00:00:00Z
     * (sortiert ans Ende bzw. bei asc an den Anfang).
     */
    public static function stamp(?string $mysql, ?string $fallback = null): string
    {
        foreach ([$mysql, $fallback] as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ! str_starts_with($value, '0000-00-00') && ($ts = strtotime($value.' UTC')) !== false && $ts > 0) {
                return (string) TokenAuth::iso($value);
            }
        }

        return '1970-01-01T00:00:00Z';
    }

    /** @param array<string,mixed> $item @param array<int,string> $saved @return array<string,mixed> */
    private function favoritePayload(array $item, array $saved): array
    {
        $qid = (int) $item['question_id'];

        return [
            'question_id' => (string) $item['question_id'],
            'context' => 'smart_learning',
            'favorite' => (bool) $item['favorite'],
            'wrong' => (bool) $item['wrong'],
            'collection_id' => isset($item['collections'][0]) ? (string) $item['collections'][0] : null,
            'collection_ids' => array_map('strval', $item['collections']),
            'official_number' => $item['number'],
            'text' => (string) $item['text'],
            'module' => (string) $item['module'],
            'topic' => $item['topic'] !== '' ? (string) $item['topic'] : null,
            // Favoriten: immer gesetzt; Fehlerliste: null, wenn die Frage kein Favorit ist.
            'saved_at' => $item['favorite'] ? self::stamp($saved[$qid] ?? null) : null,
            // Veraltet (= saved_at), bleibt für bestehende App-Versionen.
            'created_at' => $item['favorite'] ? self::stamp($saved[$qid] ?? null) : null,
        ];
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    public function collectionPayload(array $c): array
    {
        $id = (int) $c['id'];
        if ($this->collectionDates === null || ! array_key_exists($id, $this->collectionDates)) {
            global $wpdb;
            $this->collectionDates = [];
            foreach ($wpdb->get_results($wpdb->prepare("SELECT id, created_at FROM {$wpdb->prefix}bp_favorite_collection WHERE user_id = %d", $this->user())) as $row) {
                $this->collectionDates[(int) $row->id] = (string) $row->created_at;
            }
        }

        return [
            'id' => (string) $id,
            'name' => (string) $c['name'],
            'favorite_count' => (int) ($c['count'] ?? 0),
            'created_at' => \Bootspruefung\Learning\Mobile\TokenAuth::iso($this->collectionDates[$id] ?? null),
        ];
    }
}
