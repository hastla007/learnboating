<?php

declare(strict_types=1);

// Hilfsprozess für smoke-test.php: führt die Deinstallation in frischem Kontext aus.
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

$plugin = 'bootspruefung-learning/bootspruefung-learning.php';
deactivate_plugins($plugin);
uninstall_plugin($plugin);
exit(0);
