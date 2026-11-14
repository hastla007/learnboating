<?php
/**
 * Deinstallation des Affiliate-Connectors: löscht seine Einstellungen (Option lbac_settings), in einer
 * Multisite-Installation je Website. Der Connector legt keine Transients, Cron-Ereignisse oder Tabellen an;
 * Provisionen und Partnerdaten gehören WP Affiliate Captain und bleiben.
 *
 * @package LearnBoating\AffiliateConnector
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$lbacUninstall = static function (): void {
    delete_option('lbac_settings');
};

if (function_exists('is_multisite') && is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $lbacSite) {
        switch_to_blog((int) $lbacSite);
        $lbacUninstall();
        restore_current_blog();
    }
} else {
    $lbacUninstall();
}
