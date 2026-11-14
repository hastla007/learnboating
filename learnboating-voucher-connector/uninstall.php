<?php
/**
 * Deinstallation des Kursgutschein-Connectors: löscht offene Wiederholungen (Option lbvc_pending), den
 * Zwischenspeicher lbvc_broken_targets und die Cron-Ereignisse lbvc_retry und lbvc_queue_edit, in einer Multisite-Installation je
 * Website. Bleiben: die Zuordnung Gutschein-Produkt → Kurs (Produkt-Meta _lbvc_target, für eine erneute
 * Installation), Gutscheine und Verlauf (gehören Voucher Captain) und freigeschaltete Zugänge (Lernplattform).
 *
 * @package LearnBoating\VoucherConnector
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$lbvcUninstall = static function (): void {
    delete_option('lbvc_pending');
    delete_transient('lbvc_broken_targets');
    wp_unschedule_hook('lbvc_retry');
    wp_unschedule_hook('lbvc_queue_edit');
};

if (function_exists('is_multisite') && is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $lbvcSite) {
        switch_to_blog((int) $lbvcSite);
        $lbvcUninstall();
        restore_current_blog();
    }
} else {
    $lbvcUninstall();
}
