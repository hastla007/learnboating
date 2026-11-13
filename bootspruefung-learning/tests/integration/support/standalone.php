<?php

declare(strict_types=1);

/**
 * Eigenständiger Lauf eines Integrationstests (To-do T60): unabhängig davon, welche Tests vorher liefen.
 *
 * - Plugin nicht aktiv (z. B. ohne smoke-test.php): aktivieren und den Test in einem neuen PHP-Prozess erneut
 *   starten, damit alle Hooks (REST-Routen, Rollen, Cron) wie bei einem normalen Aufruf registriert sind.
 * - Fällige Datenbank-Migration nachholen.
 * - Plugin-Einstellungen: Stand vorher merken und am Ende (auch bei einem Abbruch) zurückschreiben. Der Test
 *   beginnt mit den Standardwerten; was andere Tests hinterlassen (z. B. „nur mit Freischaltung“), wirkt nicht.
 *
 * Einbinden nach guard.php: require __DIR__.'/support/standalone.php';
 */

require_once ABSPATH.'wp-admin/includes/plugin.php';

$bpStandalonePlugin = 'bootspruefung-learning/bootspruefung-learning.php';
if (! is_plugin_active($bpStandalonePlugin)) {
    $activated = activate_plugin($bpStandalonePlugin);
    if (is_wp_error($activated)) {
        fwrite(STDERR, 'Plugin ließ sich nicht aktivieren: '.$activated->get_error_message()."\n");
        exit(1);
    }
    echo "Plugin aktiviert (eigenständiger Lauf) – Test startet neu.\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg((string) $_SERVER['argv'][0]), $code);
    exit((int) $code);
}

$bpStandaloneMigrator = \Bootspruefung\Learning\Plugin::instance()->migrator();
if ($bpStandaloneMigrator->needsMigration()) {
    $bpStandaloneMigrator->migrate();
    echo "Datenbank-Migration nachgeholt.\n";
}

$bpStandaloneSettings = get_option(\Bootspruefung\Learning\Settings\Settings::OPTION, false);
delete_option(\Bootspruefung\Learning\Settings\Settings::OPTION);
register_shutdown_function(static function () use ($bpStandaloneSettings): void {
    $bpStandaloneSettings === false
        ? delete_option(\Bootspruefung\Learning\Settings\Settings::OPTION)
        : update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, $bpStandaloneSettings);
});
