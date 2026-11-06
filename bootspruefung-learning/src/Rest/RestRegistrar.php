<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest;

use Bootspruefung\Learning\Rest\Controllers\ImportController;
use Bootspruefung\Learning\Rest\Controllers\LearnerController;
use Bootspruefung\Learning\Rest\Controllers\StatusController;
use Bootspruefung\Learning\Rest\Controllers\TrainerController;

/** Registriert alle REST-Controller unter /wp-json/bootspruefung/v1/. */
final class RestRegistrar
{
    public const NAMESPACE = 'bootspruefung/v1';

    public function register(): void
    {
        add_action('rest_api_init', static function (): void {
            (new StatusController())->registerRoutes();
            (new ImportController())->registerRoutes();
            (new LearnerController())->registerRoutes();
            (new TrainerController())->registerRoutes();
            (new \Bootspruefung\Learning\Rest\Controllers\GroupController())->registerRoutes();
        });
    }
}
