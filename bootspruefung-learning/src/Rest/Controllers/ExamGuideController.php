<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\ExamGuide\ExamGuideService;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Support\NotFound;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Seite „Prüfung anmelden“ (Anleitung und Prüfungsausschüsse). */
final class ExamGuideController
{
    public function registerRoutes(): void
    {
        $auth = static fn (): bool|WP_Error => is_user_logged_in() ? true : new WP_Error('bp_login_required', __('Bitte anmelden.', 'bootspruefung-learning'), ['status' => 401]);
        register_rest_route(RestRegistrar::NAMESPACE, '/courses/(?P<code>[A-Za-z0-9-]+)/pruefung-anmelden', [
            'methods' => WP_REST_Server::READABLE, 'callback' => LearnerController::wrap([$this, 'show']), 'permission_callback' => $auth,
            'args' => ['code' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]+$', 'required' => true]],
        ]);
    }

    /** @return array<string,mixed> */
    public function show(WP_REST_Request $request): array
    {
        $course = (new ContentRepository())->courseByCode(strtoupper((string) $request['code']));
        if (! ExamGuideService::enabled() || $course === null) {
            throw new NotFound(__('Diese Seite ist nicht verfügbar.', 'bootspruefung-learning'));
        }
        $payload = (new ExamGuideService())->payload((string) $course->code);
        if ($payload['tabs'] === []) {
            throw new NotFound(__('Für diesen Kurs gibt es keine Anleitung zur Prüfungsanmeldung.', 'bootspruefung-learning'));
        }

        return $payload;
    }
}
