<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

use Bootspruefung\Learning\Admin\Notices;
use Bootspruefung\Learning\Commerce\ExpiryReminder;
use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Commerce\ReminderOptOut;
use Bootspruefung\Learning\Content\Import\ImportService;
use Bootspruefung\Learning\Content\Import\ImportSources;
use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Extensions\Tickets;
use Bootspruefung\Learning\Faq\CustomerFaq;
use Bootspruefung\Learning\Faq\Faq;
use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Mobile\AppSettings;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Tips\Tips;

/**
 * Deinstallation (uninstall.php, T82). Daten werden nur gelöscht, wenn „Daten beim Deinstallieren löschen“ in den
 * Einstellungen der jeweiligen Website gesetzt ist (Konzept Kap. 8, Standard: Erhalt).
 *
 * Gelöscht wird genau, was das Plugin anlegt – keine weiten Muster wie „bp_%“, die Daten anderer Plugins treffen
 * könnten. In einer Multisite-Installation je Website; Nutzer-Meta (für alle Websites gemeinsam) nur, wenn keine
 * Website mit Plugin-Daten sie behalten will.
 *
 * Bleiben bewusst: Bestell- und Produktdaten in WooCommerce (Aufbewahrungspflicht), die Seite „Häufige Fragen“
 * (normale Seite des Betreibers), geschützte Kurs-PDFs im gesperrten Ordner (werden sonst öffentlich).
 */
final class Uninstaller
{
    /** Optionen mit festem Namen außerhalb des Präfixes „bp_learning_“. */
    public const OPTIONS = [
        \Bootspruefung\Learning\Admin\MailStatus::OPTION_TEST,          // bp_mail_test (enthält eine E-Mail-Adresse)
        Monitor::OPTION_HOURLY,                                         // bp_cron_last_hourly
        \Bootspruefung\Learning\Privacy\SelfService::REWRITE_OPTION,    // bp_privacy_endpoint_version
    ];

    /** Präfixe eigener Optionen (alle Einstellungen, Stände und Sperren). */
    public const OPTION_PREFIXES = ['bp_learning_', 'bp_dispute_lock_', 'bp_voucher_grant_'];

    /** Präfixe eigener Transients (je Nutzer, Jahr oder Zugangsdaten). */
    public const TRANSIENT_PREFIXES = [
        'bp_learning_',            // Videohosts, Admin-Meldungen (flash_), Selbsttest Speicher
        'bp_money_back_notice_', 'bp_price_errors_', 'bp_price_input_', 'bp_catalog_skipped_', 'bp_privacy_notice_',
        'bp_voucher_notice_', 'bp_protect_notice_', 'bp_oss_totals_', 'bp_paypal_token_', 'bp_webhook_rejected_',
        'bp_dispute_',             // verarbeitete Konflikt-Webhooks (Doppel-Erkennung)
        'bp_rl_',                  // früher Antwort-Begrenzung je Nutzer und Minute (seit T114 Tabelle bp_mobile_rate_limit), Altbestände
    ];

    /** Eigene Transients mit festem Namen. */
    public const TRANSIENTS = ['bp_paypal_verify_calls'];

    /** Eigene Nutzer-Meta. @return list<string> */
    public static function userMetaKeys(): array
    {
        return [AppSettings::META, ExpiryReminder::META, ReminderOptOut::META, Tickets::META, Tickets::LOOKUP_META, Notices::META];
    }

    /** Einstiegspunkt aus uninstall.php. */
    public static function run(): void
    {
        if (! is_multisite()) {
            if (self::uninstallSite()) {
                self::deleteUserMeta();
            }

            return;
        }
        $keep = false;
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                if (self::siteUsesPlugin() && ! self::uninstallSite()) {
                    $keep = true;
                }
            } finally {
                restore_current_blog();
            }
        }
        if (! $keep) {
            self::deleteUserMeta();
        }
    }

    /** Hat die aktuelle Website Daten des Plugins (Schema oder Einstellungen)? */
    public static function siteUsesPlugin(): bool
    {
        return get_option(Migrator::OPTION_VERSION) !== false || get_option(Settings::OPTION) !== false;
    }

    /**
     * Daten der aktuellen Website löschen, falls eingestellt. Cron-Ereignisse werden immer ausgetragen.
     *
     * @return bool true, wenn gelöscht wurde; false, wenn die Website ihre Daten behält
     */
    public static function uninstallSite(): bool
    {
        Plugin::clearCron();
        if (! Settings::get('delete_data_on_uninstall')) {
            return false;
        }
        global $wpdb;
        // Paketordner merken, bevor die Optionen (mit dem Ordnergeheimnis) gelöscht werden.
        $packagesDir = MediaStorage::layout()['packages'] ?? null;
        // Lizenzierte Seekarten (BSH) ganz löschen – sie dürfen nach der Deinstallation nicht öffentlich werden.
        foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bp_chart']) as $chartId) {
            wp_delete_attachment((int) $chartId, true);
        }
        (new Migrator($wpdb))->dropAll();
        Capabilities::removeFromAllRoles();

        // Tipps, FAQ und Kunden-FAQ: Beiträge in jedem Status (auch Papierkorb und Entwurf, samt Meta und Revisionen)
        // und ihre Kategorien (samt Term-Meta). Typen registrieren, damit Taxonomien bekannt sind (Plugin inaktiv).
        // Die Seite „Häufige Fragen“ bleibt als normale Seite des Betreibers erhalten.
        Tips::registerTypes();
        Faq::registerTypes();
        CustomerFaq::registerTypes();
        foreach ([[Tips::POST_TYPE, Tips::TAXONOMY], [Faq::POST_TYPE, Faq::TAXONOMY], [CustomerFaq::POST_TYPE, CustomerFaq::TAXONOMY]] as [$postType, $taxonomy]) {
            self::deletePosts($postType);
            self::deleteTerms($taxonomy);
        }
        if (function_exists('wp_cache_flush_group') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group(Faq::CACHE_GROUP);
            wp_cache_flush_group(CustomerFaq::CACHE_GROUP);
        }

        self::deleteOptions();

        // Merkwerte des Katalog-Abgleichs an Produkten (Preis und Status, T98). Die Produkte selbst bleiben.
        foreach ([ShopCatalog::PRICE_META, ShopCatalog::STATUS_META, ShopCatalog::MANUAL_STATUS_META] as $metaKey) {
            delete_post_meta_by_key($metaKey);
        }

        // Importierte Medien und Pakete löschen. Geschützte Kurs-PDFs bleiben im gesperrten Ordner
        // protected-<geheim>/ (nicht öffentlich abrufbar), damit nichts ungewollt frei wird.
        $dir = MediaStorage::baseDir();
        $remove = [$dir.'/media', $dir.'/packages'];
        if (is_string($packagesDir) && preg_match('#/packages-[A-Za-z0-9_-]+$#', $packagesDir)) {
            $remove[] = $packagesDir;
        }
        // Ablageordner für den Import ohne Hochladen (T96) mit den dort abgelegten Paketen.
        $uploads = wp_upload_dir(null, false);
        $remove[] = rtrim((string) $uploads['basedir'], '/').'/'.ImportSources::DROP_FOLDER;
        foreach ($remove as $sub) {
            if (is_dir($sub)) {
                ImportService::removeDir($sub);
            }
        }

        return true;
    }

    /** Eigene Optionen und Transients der aktuellen Website, nur mit den festen Namen und Präfixen oben. */
    public static function deleteOptions(): void
    {
        global $wpdb;
        $where = [];
        $args = [];
        foreach (self::OPTION_PREFIXES as $prefix) {
            $where[] = 'option_name LIKE %s';
            $args[] = $wpdb->esc_like($prefix).'%';
        }
        foreach (self::TRANSIENT_PREFIXES as $prefix) {
            foreach (['_transient_', '_transient_timeout_'] as $kind) {
                $where[] = 'option_name LIKE %s';
                $args[] = $wpdb->esc_like($kind.$prefix).'%';
            }
        }
        foreach (self::OPTIONS as $name) {
            $where[] = 'option_name = %s';
            $args[] = $name;
        }
        foreach (self::TRANSIENTS as $name) {
            $where[] = 'option_name IN (%s, %s)';
            $args[] = '_transient_'.$name;
            $args[] = '_transient_timeout_'.$name;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bedingungen oben aus Platzhaltern gebaut
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE ".implode(' OR ', $where), ...$args));
        // Mit externem Objekt-Cache liegen Transients und Optionen (auch) dort.
        foreach (self::OPTIONS as $name) {
            wp_cache_delete($name, 'options');
        }
        foreach (self::TRANSIENTS as $name) {
            delete_transient($name);
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /** Nutzer-Meta des Plugins (alle Nutzer). */
    public static function deleteUserMeta(): void
    {
        foreach (self::userMetaKeys() as $key) {
            delete_metadata('user', 0, $key, '', true);
        }
    }

    /** Alle Beiträge eines Typs in jedem Status, endgültig. */
    private static function deletePosts(string $postType): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $postType));
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
    }

    private static function deleteTerms(string $taxonomy): void
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids']);
        foreach (is_array($terms) ? $terms : [] as $termId) {
            wp_delete_term((int) $termId, $taxonomy);
        }
    }
}
