<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Admin\SystemStatus;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Security\Capabilities;
use WP_REST_Response;
use WP_REST_Server;

/** GET /bootspruefung/v1/status – Systemstatus für Administration und Monitoring. */
final class StatusController
{
    public function registerRoutes(): void
    {
        register_rest_route(RestRegistrar::NAMESPACE, '/status', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE_CONTENT),
                'args' => [],
            ],
            'schema' => [$this, 'schema'],
        ]);
    }

    public function show(): WP_REST_Response
    {
        return new WP_REST_Response(SystemStatus::collect(), 200);
    }

    /** @return array<string,mixed> */
    public function schema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'bp-status',
            'type' => 'object',
            'properties' => [
                'plugin_version' => ['type' => 'string'],
                'schema_version' => ['type' => ['string', 'null']],
                'schema_current' => ['type' => 'boolean'],
                'foreign_keys' => ['type' => 'integer'],
                'tables' => ['type' => 'object'],
                'woocommerce' => ['type' => 'object'],
                'php_version' => ['type' => 'string'],
                'wp_version' => ['type' => 'string'],
            ],
        ];
    }
}
