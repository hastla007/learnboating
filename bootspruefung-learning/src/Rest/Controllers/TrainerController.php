<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest\Controllers;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Support\NotFound;
use Bootspruefung\Learning\Trainers\DeviceSimService;
use Bootspruefung\Learning\Trainers\RadioPracticeService;
use Bootspruefung\Learning\Trainers\RadioService;
use Bootspruefung\Learning\Trainers\TrainerService;
use Bootspruefung\Learning\Trainers\VideoService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** REST für die Spezialtrainer (Kap. 6.4): Navigation, Praxis, Video, Funk. */
final class TrainerController
{
    private ContentRepository $content;
    private AccessService $access;
    private TrainerService $trainers;
    private VideoService $videos;
    private RadioService $radio;
    private RadioPracticeService $practice;
    private DeviceSimService $device;

    public function __construct()
    {
        $this->content = new ContentRepository();
        $this->access = new AccessService();
        $this->trainers = new TrainerService($this->access);
        $this->videos = new VideoService($this->access, $this->content, new QuestionPresenter($this->content));
        $this->radio = new RadioService($this->access);
        $this->practice = new RadioPracticeService($this->access);
        $this->device = new DeviceSimService($this->access, $this->practice);
    }

    public function registerRoutes(): void
    {
        $ns = RestRegistrar::NAMESPACE;
        $base = '/courses/(?P<code>[A-Za-z0-9-]+)';
        $auth = static fn (): bool|WP_Error => is_user_logged_in() ? true : new WP_Error('bp_login_required', __('Bitte anmelden.', 'bootspruefung-learning'), ['status' => 401]);
        $w = static fn (callable $c) => LearnerController::wrap($c);
        $code = ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9-]+$'];
        $id = ['type' => 'integer', 'required' => true, 'minimum' => 1];
        $key = ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9-]+$', 'maxLength' => 64];
        $text = ['type' => ['string', 'null'], 'maxLength' => 5000, 'default' => null];
        $route = static function (string $path, string $method, callable $cb, array $args = []) use ($ns, $base, $auth, $w, $code): void {
            register_rest_route($ns, $base.$path, ['methods' => $method, 'callback' => $w($cb), 'permission_callback' => $auth, 'args' => ['code' => $code] + $args]);
        };

        $route('/navigation', WP_REST_Server::READABLE, fn ($r) => $this->trainers->navigationTasks(get_current_user_id(), $this->course($r)));
        $route('/navigation/(?P<task>\d+)', WP_REST_Server::READABLE, fn ($r) => $this->trainers->navigationTask(get_current_user_id(), $this->course($r), (int) $r['task']), ['task' => $id]);
        $route('/navigation-questions/(?P<question>\d+)', WP_REST_Server::CREATABLE, fn ($r) => $this->trainers->navigationAnswer(get_current_user_id(), $this->course($r), (int) $r['question'], $r['answer'], $r['self_assessment']), [
            'question' => $id, 'answer' => $text, 'self_assessment' => ['type' => ['string', 'null'], 'enum' => ['correct', 'partly', 'wrong', null], 'default' => null],
        ]);
        $route('/praxis', WP_REST_Server::READABLE, fn ($r) => $this->trainers->praxis(get_current_user_id(), $this->course($r)));
        $route('/praxis/(?P<task>\d+)', 'PUT', function ($r) {
            $this->trainers->praxisStatus(get_current_user_id(), $this->course($r), (int) $r['task'], (string) $r['status']);

            return ['status' => $r['status']];
        }, ['task' => $id, 'status' => ['type' => 'string', 'required' => true, 'enum' => ['known', 'practice', 'open']]]);
        $route('/videos', WP_REST_Server::READABLE, fn ($r) => $this->videos->course(get_current_user_id(), $this->course($r)));
        $route('/videos/(?P<lesson>\d+)', WP_REST_Server::READABLE, fn ($r) => $this->videos->lesson(get_current_user_id(), $this->course($r), (int) $r['lesson']), ['lesson' => $id]);
        $route('/videos/(?P<lesson>\d+)/progress', 'PUT', fn ($r) => $this->videos->progress(get_current_user_id(), $this->course($r), (int) $r['lesson'], (int) $r['position'], $r['completed']), [
            'lesson' => $id, 'position' => ['type' => 'integer', 'minimum' => 0, 'default' => 0], 'completed' => ['type' => ['boolean', 'null'], 'default' => null],
        ]);
        $route('/radio', WP_REST_Server::READABLE, fn ($r) => $this->radio->overview(get_current_user_id(), $this->course($r)));
        $route('/radio/texts/(?P<key>[A-Za-z0-9-]+)', WP_REST_Server::READABLE, fn ($r) => $this->radio->text(get_current_user_id(), $this->course($r), (string) $r['key']), ['key' => $key]);
        $route('/radio/texts/(?P<key>[A-Za-z0-9-]+)/evaluate', WP_REST_Server::CREATABLE, fn ($r) => $this->radio->evaluate(get_current_user_id(), $this->course($r), (string) $r['key'], (string) $r['direction'], (string) $r['transcript'], (string) $r['translation']), [
            'key' => $key, 'direction' => ['type' => 'string', 'enum' => ['de-en', 'en-de', ''], 'default' => ''], 'transcript' => ['type' => 'string', 'maxLength' => 5000, 'default' => ''], 'translation' => ['type' => 'string', 'maxLength' => 5000, 'default' => ''],
        ]);
        $route('/radio/texts/(?P<key>[A-Za-z0-9-]+)/done', 'PUT', function ($r) {
            $this->radio->setDone(get_current_user_id(), $this->course($r), (string) $r['key'], (bool) $r['done']);

            return ['done' => (bool) $r['done']];
        }, ['key' => $key, 'done' => ['type' => 'boolean', 'required' => true]]);
        $route('/radio/vocabulary', WP_REST_Server::READABLE, fn ($r) => $this->radio->vocabulary(get_current_user_id(), $this->course($r), (string) $r['catalog'], $r['category']), [
            'catalog' => ['type' => 'string', 'enum' => ['SRC', 'UBI'], 'required' => true], 'category' => ['type' => ['string', 'null'], 'maxLength' => 191, 'default' => null],
        ]);
        $this->registerRadioPracticeAndDevice($route, $key);
        $route('/radio/vocabulary/(?P<key>[A-Za-z0-9-]+)', WP_REST_Server::CREATABLE, fn ($r) => $this->radio->vocabularyAnswer(get_current_user_id(), $this->course($r), (string) $r['key'], (bool) $r['known']), [
            'key' => $key, 'known' => ['type' => 'boolean', 'required' => true],
        ]);
    }

    private function registerRadioPracticeAndDevice(callable $route, array $key): void
    {
        $route('/radio/practice', WP_REST_Server::READABLE, fn ($r) => $this->practice->overview(get_current_user_id(), $this->course($r)));
        $route('/radio/samples', WP_REST_Server::READABLE, fn ($r) => $this->practice->samples(get_current_user_id(), $this->course($r)));
        $route('/radio/spelling', WP_REST_Server::READABLE, fn ($r) => $this->radio->spelling(get_current_user_id(), $this->course($r)));
        $route('/radio/practice/(?P<key>[A-Za-z0-9-]+)', WP_REST_Server::READABLE, fn ($r) => $this->practice->show(get_current_user_id(), $this->course($r), (string) $r['key']), ['key' => $key]);
        $route('/radio/practice/(?P<key>[A-Za-z0-9-]+)/evaluate', WP_REST_Server::CREATABLE, fn ($r) => $this->practice->evaluate(get_current_user_id(), $this->course($r), (string) $r['key'], array_map('strval', array_filter((array) $r['answers'], 'is_scalar'))), [
            'key' => $key, 'answers' => ['type' => 'object', 'required' => true],
        ]);
        $route('/radio/practice/(?P<key>[A-Za-z0-9-]+)/done', 'PUT', function ($r) {
            $this->practice->setDone(get_current_user_id(), $this->course($r), (string) $r['key'], (bool) $r['done']);

            return ['done' => (bool) $r['done']];
        }, ['key' => $key, 'done' => ['type' => 'boolean', 'required' => true]]);

        // Gerätesimulation: Format der Laravel-Routen (funk.device.*), damit das Original-JS unverändert läuft.
        $device = ['type' => 'string', 'pattern' => '^[a-z0-9-]+$', 'default' => DeviceSimService::DEFAULT_DEVICE];
        $route('/device-sim', WP_REST_Server::READABLE, fn ($r) => $this->device->config(get_current_user_id(), $this->course($r)));
        $route('/device-sim/evaluate', WP_REST_Server::CREATABLE, fn ($r) => $this->device->evaluate(get_current_user_id(), $this->course($r), (string) $r['lesson'], (int) $r['step'], (string) $r['text']), [
            'lesson' => ['type' => 'string', 'required' => true, 'maxLength' => 100], 'step' => ['type' => 'integer', 'required' => true, 'minimum' => 0, 'maximum' => 50], 'text' => ['type' => 'string', 'required' => true, 'maxLength' => 2000],
        ]);
        $route('/device-sim/progress', WP_REST_Server::CREATABLE, fn ($r) => $this->device->progress(get_current_user_id(), $this->course($r), (string) $r['lesson'], (int) $r['level'], (string) $r['device']), [
            'lesson' => ['type' => 'string', 'required' => true, 'maxLength' => 100], 'level' => ['type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 3], 'device' => $device,
        ]);
        $route('/device-sim/exam', WP_REST_Server::CREATABLE, fn ($r) => $this->device->exam(get_current_user_id(), $this->course($r), (string) $r['series'], (string) $r['device'], (array) $r['passed']), [
            'series' => ['type' => 'string', 'required' => true, 'maxLength' => 50], 'device' => $device, 'passed' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => [], 'maxItems' => 100],
        ]);
    }

    /** Überblick aller Trainer für die Kursseite. @return array<string,mixed> */
    public function summary(int $userId, object $course): array
    {
        $out = $this->trainers->summary($userId, $course);
        if ($this->access->can($userId, $course->code, AccessService::VIDEO)) {
            $v = $this->videos->course($userId, $course);
            $out['video'] = ['lessons' => $v['lessons'], 'completed' => $v['completed']];
        } else {
            $out['video'] = ['lessons' => 0, 'completed' => 0];
        }
        $out['radio'] = $this->radio->summary($userId, $course) + ['practice' => $this->practice->count($userId, $course), 'device' => $this->device->summary($userId, $course)['lessons']];

        return $out;
    }

    private function course(WP_REST_Request $request): object
    {
        $course = $this->content->courseByCode(strtoupper((string) $request['code']));
        if ($course === null) {
            throw new NotFound(__('Kurs nicht gefunden.', 'bootspruefung-learning'));
        }

        return $course;
    }
}
