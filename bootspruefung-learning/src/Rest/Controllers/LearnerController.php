<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest\Controllers;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Exams\Services\ExamService;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\CollectionService;
use Bootspruefung\Learning\Learning\Services\LearningService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST für Lernende (Kap. 6, 8): Kurse, Lernmodus, Merkliste, Prüfung.
 * Nutzer-ID kommt ausschließlich aus der angemeldeten WP-Sitzung (Cookie +
 * X-WP-Nonce), nie aus dem Payload.
 */
final class LearnerController
{
    private ContentRepository $content;
    private AccessService $access;
    private LearningService $learning;
    private ExamService $exams;
    private CollectionService $collections;

    public function __construct()
    {
        $this->content = new ContentRepository();
        $this->access = new AccessService();
        $presenter = new QuestionPresenter($this->content);
        $this->learning = new LearningService($this->content, $presenter, $this->access);
        $this->exams = new ExamService($this->content, $presenter, $this->access);
        $this->collections = new CollectionService($this->learning, $this->content);
    }

    public function registerRoutes(): void
    {
        $ns = RestRegistrar::NAMESPACE;
        $auth = static fn (): bool|WP_Error => is_user_logged_in() ? true : new WP_Error('bp_login_required', __('Bitte anmelden.', 'bootspruefung-learning'), ['status' => 401]);
        $code = ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9-]+$'];
        $id = ['type' => 'integer', 'required' => true, 'minimum' => 1];

        register_rest_route($ns, '/me/courses', ['methods' => WP_REST_Server::READABLE, 'callback' => self::wrap([$this, 'courses']), 'permission_callback' => $auth]);
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)', ['methods' => WP_REST_Server::READABLE, 'callback' => self::wrap([$this, 'course']), 'permission_callback' => $auth, 'args' => ['code' => $code]]);
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/next-question', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => self::wrap([$this, 'next']),
            'permission_callback' => $auth,
            'args' => [
                'code' => $code,
                'module' => ['type' => 'integer', 'minimum' => 1],
                'topic' => ['type' => 'string', 'maxLength' => 190],
                'collection' => ['type' => 'integer', 'minimum' => 1],
                'exam' => ['type' => 'integer', 'minimum' => 1],
                'mode' => ['type' => 'string', 'enum' => LearningService::MODES, 'default' => 'smart'],
            ],
        ]);
        register_rest_route($ns, '/answers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => self::wrap([$this, 'answer']),
            'permission_callback' => $auth,
            'args' => [
                'token' => ['type' => 'string', 'required' => true, 'maxLength' => 2000],
                'answer_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [], 'maxItems' => 26],
                'known' => ['type' => ['boolean', 'null'], 'default' => null],
                'idempotency_key' => ['type' => 'string', 'required' => true, 'pattern' => '^[0-9a-f-]{36}$'],
                'response_ms' => ['type' => ['integer', 'null'], 'minimum' => 0, 'default' => null],
            ],
        ]);
        register_rest_route($ns, '/answers/reveal', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => self::wrap([$this, 'reveal']),
            'permission_callback' => $auth,
            'args' => ['token' => ['type' => 'string', 'required' => true, 'maxLength' => 2000]],
        ]);
        register_rest_route($ns, '/favorites/(?P<question>\d+)', [
            ['methods' => 'PUT', 'callback' => self::wrap(fn (WP_REST_Request $r) => $this->favorite($r, true)), 'permission_callback' => $auth, 'args' => ['question' => $id]],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => self::wrap(fn (WP_REST_Request $r) => $this->favorite($r, false)), 'permission_callback' => $auth, 'args' => ['question' => $id]],
        ]);

        // Favoriten & Fehler: Übersicht, Sammlungen, Einsortieren
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/saved', ['methods' => WP_REST_Server::READABLE, 'callback' => self::wrap([$this, 'saved']), 'permission_callback' => $auth, 'args' => ['code' => $code]]);
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/saved/(?P<question>\d+)', [
            'methods' => 'PUT',
            'callback' => self::wrap([$this, 'assign']),
            'permission_callback' => $auth,
            'args' => ['code' => $code, 'question' => $id, 'favorite' => ['type' => 'boolean', 'required' => true], 'collections' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [], 'maxItems' => CollectionService::MAX_COLLECTIONS]],
        ]);
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/collections', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => self::wrap([$this, 'collectionCreate']),
            'permission_callback' => $auth,
            'args' => ['code' => $code, 'name' => ['type' => 'string', 'required' => true, 'maxLength' => 200]],
        ]);
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/collections/(?P<id>\d+)', [
            ['methods' => 'PUT', 'callback' => self::wrap([$this, 'collectionRename']), 'permission_callback' => $auth, 'args' => ['code' => $code, 'id' => $id, 'name' => ['type' => 'string', 'required' => true, 'maxLength' => 200]]],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => self::wrap([$this, 'collectionDelete']), 'permission_callback' => $auth, 'args' => ['code' => $code, 'id' => $id]],
        ]);

        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/exam', ['methods' => WP_REST_Server::READABLE, 'callback' => self::wrap([$this, 'examOverview']), 'permission_callback' => $auth, 'args' => ['code' => $code]]);
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/exam-sessions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => self::wrap([$this, 'examStart']),
            'permission_callback' => $auth,
            'args' => ['code' => $code, 'paper_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'default' => null]],
        ]);
        register_rest_route($ns, '/exam-sessions/(?P<id>\d+)', ['methods' => WP_REST_Server::READABLE, 'callback' => self::wrap([$this, 'examShow']), 'permission_callback' => $auth, 'args' => ['id' => $id]]);
        register_rest_route($ns, '/exam-sessions/(?P<id>\d+)/answers/(?P<position>\d+)', [
            'methods' => 'PUT',
            'callback' => self::wrap([$this, 'examAnswer']),
            'permission_callback' => $auth,
            'args' => ['id' => $id, 'position' => $id, 'answer_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [], 'maxItems' => 26], 'known' => ['type' => ['boolean', 'null'], 'default' => null]],
        ]);
        register_rest_route($ns, '/exam-sessions/(?P<id>\d+)/answers/(?P<position>\d+)/reveal', ['methods' => WP_REST_Server::CREATABLE, 'callback' => self::wrap([$this, 'examReveal']), 'permission_callback' => $auth, 'args' => ['id' => $id, 'position' => $id]]);
        register_rest_route($ns, '/exam-sessions/(?P<id>\d+)/submit', ['methods' => WP_REST_Server::CREATABLE, 'callback' => self::wrap([$this, 'examSubmit']), 'permission_callback' => $auth, 'args' => ['id' => $id]]);
    }

    public function courses(): array
    {
        $user = get_current_user_id();
        $out = [];
        foreach ($this->content->courses() as $course) {
            // Zugriff einmal je Kurs ermitteln (features/limits daraus statt zweimal scopes()).
            $scopes = $this->access->scopes($user, $course->code);
            $features = self::features($scopes);
            // Kostenloser Teilumfang (Schnupper-Paket): Fragen nur begrenzt, kein Vollzugang.
            $trial = $features[AccessService::QUESTION_BANK] && isset(self::limits($scopes)[AccessService::QUESTION_BANK]);
            $out[] = [
                'code' => $course->code,
                'trial' => $trial,
                'slug' => ContentRepository::slug($course->code),
                'name' => $course->name,
                'type' => $course->course_type,
                'features' => $features,
                // Nur die Gesamtsumme (ohne Module/Themen wie in courseProgress()).
                'progress' => $features[AccessService::QUESTION_BANK] ? $this->learning->courseTotal($user, $course) : null,
                'shop' => in_array(true, $features, true) && ! $trial ? [] : \Bootspruefung\Learning\Commerce\WooCommerce\Integration::offers($course->code, [AccessService::QUESTION_BANK => false]),
            ];
        }

        return $out;
    }

    public function course(WP_REST_Request $request): array
    {
        $course = $this->courseOr404((string) $request['code']);
        $user = get_current_user_id();
        $scopes = $this->access->scopes($user, $course->code);
        $features = self::features($scopes);

        return [
            'code' => $course->code,
            'slug' => ContentRepository::slug($course->code),
            'name' => $course->name,
            'type' => $course->course_type,
            'features' => $features,
            'progress' => $features[AccessService::QUESTION_BANK] ? $this->learning->courseProgress($user, $course) : null,
            'favorites' => $features[AccessService::QUESTION_BANK] ? $this->learning->courseFavoriteCount($user, $course) : 0,
            'exam' => $features[AccessService::EXAM] ? $this->exams->overview($user, $course, (bool) $request['ergebnisse']) : null,
            'trainers' => (new \Bootspruefung\Learning\Rest\Controllers\TrainerController())->summary($user, $course),
            // Produkte für gesperrte Features und solche im kostenlosen Teilumfang.
            'shop' => $this->shopOffers($user, $course, $scopes),
            'limits' => self::limits($scopes),
            'downloads' => \Bootspruefung\Learning\Gate\DownloadService::items($user, $course->code),
            'files' => \Bootspruefung\Learning\Gate\SectionFiles::forCourse($user, $course->code),
            // Seekarten für den Navigationstrainer (url null = noch nicht lizenziert/hochgeladen).
            'charts' => \Bootspruefung\Learning\Gate\Charts::forCourse($user, (string) $course->code),
            // Prüfungsanmeldung (Anleitung) nur, wenn freigeschaltet und eine Anleitung für den Kurs existiert.
            'exam_guide' => \Bootspruefung\Learning\ExamGuide\ExamGuideService::enabled() && ($pages = (new \Bootspruefung\Learning\ExamGuide\ExamGuideService())->pagesFor((string) $course->code)) !== []
                ? ['tabs' => array_map(static fn ($p) => $p['guide']['tab'], $pages)] : null,
        ];
    }

    public function next(WP_REST_Request $request): array
    {
        $course = $this->courseOr404((string) $request['code']);
        $user = get_current_user_id();
        if (! $this->access->can($user, $course->code, AccessService::QUESTION_BANK)) {
            throw new AccessDenied(__('Für diesen Kurs ist der Fragenkatalog nicht freigeschaltet.', 'bootspruefung-learning'));
        }
        $only = $request['collection'] ? $this->collections->questionIds($user, $course, (int) $request['collection']) : null;
        // Fehler einer abgegebenen Prüfung gezielt wiederholen (Knopf auf der Ergebnisseite).
        if ($request['exam']) {
            $only = $this->exams->wrongQuestionIds($user, (int) $request['exam']);
        }
        $question = $this->learning->next($user, $course, $request['module'] ? (int) $request['module'] : null, (string) $request['mode'], $request['topic'] ? (string) $request['topic'] : null, $only);

        return $question === null ? ['done' => true] : ['done' => false, 'question' => $question];
    }

    public function answer(WP_REST_Request $request): array
    {
        return $this->learning->answer(
            get_current_user_id(),
            (string) $request['token'],
            array_map('intval', (array) $request['answer_ids']),
            $request['known'] === null ? null : (bool) $request['known'],
            (string) $request['idempotency_key'],
            $request['response_ms'] === null ? null : (int) $request['response_ms']
        );
    }

    public function reveal(WP_REST_Request $request): array
    {
        return $this->learning->reveal(get_current_user_id(), (string) $request['token']);
    }

    public function favorite(WP_REST_Request $request, bool $on): array
    {
        $this->learning->setFavorite(get_current_user_id(), (int) $request['question'], $on);

        return ['favorite' => $on];
    }

    /** @param array<string,array{full:bool,limit:?int}> $scopes @return array<string,bool> wie AccessService::features() */
    private static function features(array $scopes): array
    {
        return array_map(static fn ($s) => $s['full'] || ($s['limit'] ?? 0) > 0, $scopes);
    }

    /** @param array<string,array{full:bool,limit:?int}> $scopes @return array<string,int> wie AccessService::limits() */
    private static function limits(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $feature => $scope) {
            if (! $scope['full'] && ($scope['limit'] ?? 0) > 0) {
                $out[$feature] = (int) $scope['limit'];
            }
        }

        return $out;
    }

    /**
     * Kaufangebote für gesperrte Funktionen; Theorie-Käufern zuerst das Video-Upgrade.
     * @param array<string,array{full:bool,limit:?int}> $scopes
     * @return array<string,list<array>>
     */
    private function shopOffers(int $user, object $course, array $scopes): array
    {
        $shop = \Bootspruefung\Learning\Commerce\WooCommerce\Integration::offers($course->code, array_map(static fn ($s) => (bool) $s['full'], $scopes));
        if (isset($shop[AccessService::VIDEO]) && ($upgrade = \Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade::offerFor($user, $course->code)) !== null) {
            array_unshift($shop[AccessService::VIDEO], $upgrade);
        }

        return $shop;
    }

    /** Kurs mit freigeschaltetem Fragenkatalog oder 403. */
    private function bankCourse(WP_REST_Request $request): object
    {
        $course = $this->courseOr404((string) $request['code']);
        if (! $this->access->can(get_current_user_id(), $course->code, AccessService::QUESTION_BANK)) {
            throw new AccessDenied(__('Für diesen Kurs ist der Fragenkatalog nicht freigeschaltet.', 'bootspruefung-learning'));
        }

        return $course;
    }

    public function saved(WP_REST_Request $request): array
    {
        return $this->collections->overview(get_current_user_id(), $this->bankCourse($request));
    }

    public function assign(WP_REST_Request $request): array
    {
        return $this->collections->assign(get_current_user_id(), $this->bankCourse($request), (int) $request['question'], (bool) $request['favorite'], array_map('intval', (array) $request['collections']));
    }

    public function collectionCreate(WP_REST_Request $request): array
    {
        return $this->collections->create(get_current_user_id(), $this->bankCourse($request), (string) $request['name']);
    }

    public function collectionRename(WP_REST_Request $request): array
    {
        return $this->collections->rename(get_current_user_id(), $this->bankCourse($request), (int) $request['id'], (string) $request['name']);
    }

    public function collectionDelete(WP_REST_Request $request): array
    {
        $this->collections->delete(get_current_user_id(), $this->bankCourse($request), (int) $request['id']);

        return ['deleted' => true];
    }

    public function examOverview(WP_REST_Request $request): array
    {
        $course = $this->courseOr404((string) $request['code']);
        if (! $this->access->can(get_current_user_id(), $course->code, AccessService::EXAM)) {
            throw new AccessDenied(__('Die Prüfungssimulation ist für diesen Kurs nicht freigeschaltet.', 'bootspruefung-learning'));
        }

        return $this->exams->overview(get_current_user_id(), $course);
    }

    public function examStart(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->courseOr404((string) $request['code']);
        $id = $this->exams->start(get_current_user_id(), $course, $request['paper_id'] ? (int) $request['paper_id'] : null);

        // live = offizielle Simulation; reduced = Fragenpool zu klein, verkürzte Übungsprüfung.
        return new WP_REST_Response(['session_id' => $id] + $this->exams->flags(get_current_user_id(), $id), 201);
    }

    public function examShow(WP_REST_Request $request): array
    {
        return $this->exams->show(get_current_user_id(), (int) $request['id']);
    }

    public function examAnswer(WP_REST_Request $request): array
    {
        $this->exams->saveAnswer(get_current_user_id(), (int) $request['id'], (int) $request['position'], array_map('intval', (array) $request['answer_ids']), $request['known'] === null ? null : (bool) $request['known']);

        return ['saved' => true];
    }

    public function examReveal(WP_REST_Request $request): array
    {
        return ['model_answer' => $this->exams->reveal(get_current_user_id(), (int) $request['id'], (int) $request['position'])];
    }

    public function examSubmit(WP_REST_Request $request): array
    {
        $this->exams->submit(get_current_user_id(), (int) $request['id']);

        return $this->exams->show(get_current_user_id(), (int) $request['id']);
    }

    private function courseOr404(string $code): object
    {
        $course = $this->content->courseByCode(strtoupper($code));
        if ($course === null) {
            throw new NotFound(__('Kurs nicht gefunden.', 'bootspruefung-learning'));
        }

        return $course;
    }

    /** Wandelt fachliche Fehler in REST-Fehler um und verhindert Caching. */
    public static function wrap(callable $callback): callable
    {
        return static function (WP_REST_Request $request) use ($callback) {
            try {
                $result = $callback($request);
                $response = $result instanceof WP_REST_Response ? $result : new WP_REST_Response($result, 200);
                $response->header('Cache-Control', 'no-store, private');

                return $response;
            } catch (AccessDenied $e) {
                return new WP_Error('bp_forbidden', $e->getMessage(), ['status' => 403]);
            } catch (NotFound $e) {
                return new WP_Error('bp_not_found', $e->getMessage(), ['status' => 404]);
            } catch (\RuntimeException $e) {
                return new WP_Error('bp_invalid', $e->getMessage(), ['status' => 422]);
            }
        };
    }
}
