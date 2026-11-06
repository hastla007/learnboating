<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest\Controllers;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Practice\PracticeRegistrationService;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Support\NotFound;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Praxis-Anmeldung (ohne Prüfungsbuchung) und Profil der Lernenden. */
final class PracticeController
{
    private PracticeRegistrationService $service;
    private ContentRepository $content;

    public function __construct()
    {
        $this->content = new ContentRepository();
        $this->service = new PracticeRegistrationService($this->content);
    }

    public function registerRoutes(): void
    {
        $ns = RestRegistrar::NAMESPACE;
        $auth = static fn (): bool|WP_Error => is_user_logged_in() ? true : new WP_Error('bp_login_required', __('Bitte anmelden.', 'bootspruefung-learning'), ['status' => 401]);
        $code = ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]+$', 'required' => true];
        register_rest_route($ns, '/courses/(?P<code>[A-Za-z0-9-]+)/praxis-anmeldung', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => LearnerController::wrap([$this, 'show']), 'permission_callback' => $auth, 'args' => ['code' => $code]],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => LearnerController::wrap([$this, 'submit']), 'permission_callback' => $auth, 'args' => ['code' => $code, 'data' => ['type' => 'object', 'required' => true]]],
        ]);
        register_rest_route($ns, '/me/profile', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => LearnerController::wrap([$this, 'profile']), 'permission_callback' => $auth],
            ['methods' => 'PUT', 'callback' => LearnerController::wrap([$this, 'saveProfile']), 'permission_callback' => $auth],
        ]);
    }

    /** @return array<string,mixed> */
    public function show(WP_REST_Request $request): array
    {
        return $this->service->state(get_current_user_id(), $this->course((string) $request['code']));
    }

    /** @return array<string,mixed> */
    public function submit(WP_REST_Request $request): array
    {
        $course = $this->course((string) $request['code']);
        $this->service->submit(get_current_user_id(), $course, (array) $request['data']);

        return $this->service->state(get_current_user_id(), $course);
    }

    /** @return array<string,mixed> */
    public function profile(): array
    {
        return ['profile' => $this->service->profile(get_current_user_id()), 'complete' => $this->service->profileComplete(get_current_user_id())];
    }

    /** @return array<string,mixed> */
    public function saveProfile(WP_REST_Request $request): array
    {
        $this->service->saveProfile(get_current_user_id(), (array) $request->get_json_params());

        return $this->profile();
    }

    private function course(string $code): object
    {
        if (! PracticeRegistrationService::enabled()) {
            throw new NotFound(__('Die Praxis-Anmeldung ist noch nicht freigeschaltet.', 'bootspruefung-learning'));
        }
        $course = $this->content->courseByCode(strtoupper($code));
        if ($course === null) {
            throw new NotFound(__('Kurs nicht gefunden.', 'bootspruefung-learning'));
        }

        return $course;
    }
}
