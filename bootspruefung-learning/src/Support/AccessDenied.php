<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Support;

defined('ABSPATH') || exit;

/** Fachliche Zugriffsverweigerung (REST: 403). */
final class AccessDenied extends \RuntimeException
{
}
