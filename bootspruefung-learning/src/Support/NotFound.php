<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Support;

defined('ABSPATH') || exit;

/** Objekt existiert nicht oder gehört nicht zum Nutzer (REST: 404). */
final class NotFound extends \RuntimeException
{
}
