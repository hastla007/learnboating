<?php

declare(strict_types=1);

// Seite „Prüfung anmelden“ für guide-flow.mjs freischalten (mit „reset“ wieder deaktivieren).
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim((string) getenv('WP_DIR'), '/').'/wp-load.php';

use Bootspruefung\Learning\Settings\Settings;

$on = ($argv[1] ?? '') !== 'reset';
update_option(Settings::OPTION, array_merge(Settings::all(), ['exam_guide_enabled' => $on, 'default_access' => 'logged_in']));
echo $on ? "Prüfungsanmeldung freigeschaltet.\n" : "Prüfungsanmeldung deaktiviert.\n";
