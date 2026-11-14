<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Security\Capabilities;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** POST /bootspruefung/v1/imports/{id}/step – ein Teilschritt eines freigegebenen Imports. */
final class ImportController
{
    public function registerRoutes(): void
    {
        register_rest_route(RestRegistrar::NAMESPACE, '/imports/(?P<id>\d+)/step', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'step'],
            'permission_callback' => static fn (): bool => current_user_can(Capabilities::IMPORT_CONTENT),
            'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);
    }

    public function step(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $step = Plugin::instance()->importService()->runStep((int) $request['id'], 250);
        } catch (RuntimeException $e) {
            return new WP_Error('bp_import_failed', $e->getMessage(), ['status' => 409]);
        }

        return new WP_REST_Response($step, 200);
    }
}
