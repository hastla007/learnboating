<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\Requirements;
use Bootspruefung\Learning\Plugin;

/** Sammelt die Angaben der Systemstatus-Seite (Kap. 9). */
final class SystemStatus
{
    /** @return array<string,mixed> */
    public static function collect(): array
    {
        $migrator = Plugin::instance()->migrator();
        global $wp_version;

        return [
            'plugin_version' => Plugin::VERSION,
            'schema_version' => $migrator->installedVersion(),
            'schema_current' => ! $migrator->needsMigration(),
            'expected_schema_version' => Migrator::SCHEMA_VERSION,
            'foreign_keys' => $migrator->foreignKeyCount(),
            'tables' => $migrator->tableStatus(),
            'woocommerce' => Requirements::wooCommerceStatus() + [
                'guest_checkout' => get_option('woocommerce_enable_guest_checkout') === 'yes',
                'learning_products' => count((new \Bootspruefung\Learning\Commerce\ProductAccessRepository($GLOBALS['wpdb']))->productIds()),
            ],
            'php_version' => PHP_VERSION,
            'wp_version' => (string) $wp_version,
        ];
    }
}
