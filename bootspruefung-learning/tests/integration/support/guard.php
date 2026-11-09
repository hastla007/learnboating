<?php

declare(strict_types=1);

/**
 * Schutz: Die Integrationstests legen Daten an, löschen Produkte/Optionen und deinstallieren teils das
 * Plugin. Sie dürfen nur gegen eine Test-Datenbank laufen (Name enthält „test“, wie im CI `wp_test`),
 * niemals gegen Staging oder Live. Bewusste Ausnahme: BP_ALLOW_DESTRUCTIVE_TESTS=1.
 */
if (getenv('BP_ALLOW_DESTRUCTIVE_TESTS') !== '1' && (! defined('DB_NAME') || stripos((string) DB_NAME, 'test') === false)) {
    fwrite(STDERR, 'Abbruch: Integrationstests nur gegen eine Test-Datenbank (DB_NAME „'.(defined('DB_NAME') ? DB_NAME : '?')."“ enthält nicht „test“). Ausnahme: BP_ALLOW_DESTRUCTIVE_TESTS=1.\n");
    exit(2);
}
