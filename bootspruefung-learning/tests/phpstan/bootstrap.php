<?php

declare(strict_types=1);

/*
 * Nur für PHPStan (phpstan.neon.dist): Konstanten, die Plugin, Theme und Add-ons zur Laufzeit mit define()
 * setzen. Wird nie von WordPress geladen und ist nicht im Auslieferungs-ZIP (tests/).
 */

define('BP_LEARNING_FILE', dirname(__DIR__, 2).'/bootspruefung-learning.php');
define('BP_LEARNING_DIR', dirname(__DIR__, 2));
define('BP_LEARNING_VERSION', '0.0.0');
define('LBAC_VERSION', '0.0.0');
define('LBAC_FILE', '');
define('LBVC_VERSION', '0.0.0');
define('LBVC_FILE', '');
