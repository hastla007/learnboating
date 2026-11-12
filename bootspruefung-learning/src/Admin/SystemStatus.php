<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\Requirements;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;

/** Sammelt die Angaben der Systemstatus-Seite (Kap. 9). */
final class SystemStatus
{
    /**
     * Warnung, wenn WooCommerce die Katalogpreise nicht als Bruttopreise behandelt (sonst null).
     * @param array<string,mixed>|null $woo Teil 'woocommerce' aus collect(); null = aktuell ermitteln
     */
    public static function taxWarning(?array $woo = null): ?string
    {
        $woo ??= [
            'tax_enabled' => function_exists('wc_tax_enabled') ? wc_tax_enabled() : null,
            'prices_include_tax' => function_exists('wc_prices_include_tax') ? wc_prices_include_tax() : null,
        ];
        if (($woo['prices_include_tax'] ?? null) !== false) {
            return null;
        }
        if (empty($woo['tax_enabled']) && self::smallBusiness($woo['price_note'] ?? null)) {
            return null; // Kleinunternehmer: keine Steuer gewollt, der Hinweis am Preis sagt es.
        }

        return ! empty($woo['tax_enabled'])
            ? __('WooCommerce behandelt eingegebene Preise als Nettopreise („Preise inklusive Steuer“ = Nein). Die Katalog- und Preisangaben des Plugins sind Bruttopreise – die MwSt. käme an der Kasse noch hinzu. Bitte unter WooCommerce › Einstellungen › Steuer „Ja, ich gebe Preise inklusive Steuer ein“ wählen.', 'bootspruefung-learning')
            : __('Die Steuerberechnung in WooCommerce ist ausgeschaltet. Die Preise des Plugins sind Bruttopreise; an der Kasse wird keine MwSt. ausgewiesen. Bitte Steuern aktivieren und „Preise inklusive Steuer“ wählen (oder bewusst so lassen, z. B. als Kleinunternehmer).', 'bootspruefung-learning');
    }

    /**
     * Kleinunternehmer-Regelung erkennbar am Hinweis zum Preis (Einstellung „Hinweis zum Preis“, z. B. vom
     * Einrichtungs-Assistenten des Themes: „ohne MwSt. – Kleinunternehmer gem. § 19 UStG“).
     */
    public static function smallBusiness(?string $priceNote = null): bool
    {
        $note = $priceNote ?? Settings::priceNote();

        return (bool) preg_match('/Kleinunternehmer|§\s*19\s*UStG|§\s*6\s*Abs\.\s*1\s*Z\s*27\s*UStG/u', $note);
    }

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
                // Katalog- und Preis-Seite meinen Bruttopreise (inkl. MwSt.).
                'tax_enabled' => function_exists('wc_tax_enabled') ? wc_tax_enabled() : null,
                'prices_include_tax' => function_exists('wc_prices_include_tax') ? wc_prices_include_tax() : null,
            ],
            'php_version' => PHP_VERSION,
            'wp_version' => (string) $wp_version,
        ];
    }

    // ------------------------------------------------------------------
    // Selbsttest Dateischutz (ARC-01, SEC-05): Sind Content-Pakete und geschützte Dateien per
    // Direkt-URL abrufbar? Eigener Abschnitt, unabhängig von collect().
    // ------------------------------------------------------------------

    public const STORAGE_TEST_TRANSIENT = 'bp_learning_storage_selftest';

    /**
     * Legt je privatem Ordner (Pakete, geschützte Dateien) eine harmlose Testdatei an, fragt sie per
     * wp_remote_get() direkt an und löscht sie wieder. Ergebnis 12 Stunden zwischengespeichert
     * (Transient), bei nicht möglicher Anfrage 1 Stunde.
     *
     * Je Ordner: status = private (außerhalb des Webroots, keine URL) | ok (abgewiesen) |
     * exposed (Testdatei abrufbar) | unknown (Anfrage nicht möglich).
     * @return array{checked_at:int,overall:string,dirs:array<string,array{status:string,dir:string,url:?string,code:?int,error:?string}>}
     */
    public static function storageSelfTest(bool $refresh = false): array
    {
        $cached = get_transient(self::STORAGE_TEST_TRANSIENT);
        if (! $refresh && is_array($cached) && isset($cached['overall'], $cached['dirs'])) {
            return $cached;
        }
        $dirs = [
            'packages' => self::probeDir(\Bootspruefung\Learning\Content\Media\MediaStorage::packagesDir()),
            'protected' => self::probeDir(\Bootspruefung\Learning\Content\Media\MediaStorage::protectedDir()),
        ];
        $statuses = array_column($dirs, 'status');
        $overall = in_array('exposed', $statuses, true) ? 'exposed' : (in_array('unknown', $statuses, true) ? 'unknown' : 'ok');
        $result = ['checked_at' => time(), 'overall' => $overall, 'dirs' => $dirs];
        set_transient(self::STORAGE_TEST_TRANSIENT, $result, $overall === 'unknown' ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS);

        return $result;
    }

    /** @return array{status:string,dir:string,url:?string,code:?int,error:?string} */
    private static function probeDir(string $dir): array
    {
        $token = wp_generate_password(32, false, false);
        $file = $dir.'/bp-selftest-'.strtolower(wp_generate_password(12, false, false)).'.txt';
        $url = \Bootspruefung\Learning\Content\Media\MediaStorage::publicUrl($file);
        $out = ['status' => 'private', 'dir' => $dir, 'url' => $url, 'code' => null, 'error' => null];
        if ($url === null) {
            return $out; // außerhalb des Webroots: keine URL, nichts abrufbar
        }
        if (@file_put_contents($file, "Bootsprüfung Selbsttest {$token}\n") === false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return ['status' => 'unknown', 'error' => __('Testdatei konnte nicht angelegt werden.', 'bootspruefung-learning')] + $out;
        }
        try {
            $response = wp_remote_get($url, ['timeout' => 8, 'redirection' => 0, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache']]);
        } finally {
            @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        if (is_wp_error($response)) {
            return ['status' => 'unknown', 'error' => $response->get_error_message()] + $out;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $exposed = $code >= 200 && $code < 300 && str_contains((string) wp_remote_retrieve_body($response), $token);

        return ['status' => $exposed ? 'exposed' : 'ok', 'code' => $code] + $out;
    }

    /** nginx-Regeln für den Upload-Pfad dieser Installation (Pakete/geschützte Dateien sperren, keine Skripte in media/). */
    public static function nginxRule(): string
    {
        $path = rtrim((string) wp_parse_url(\Bootspruefung\Learning\Content\Media\MediaStorage::baseUrl(), PHP_URL_PATH), '/');
        $path = str_replace('.', '\\.', $path);

        return "location ~* ^{$path}/(packages|protected) {\n    deny all;\n}\n"
            ."location ~* ^{$path}/media/.*\\.(php[0-9]?|phtml|phar|html?|svgz?)$ {\n    deny all;\n}";
    }

    /** Abschnitt „Dateischutz“ auf der Systemstatus-Seite. */
    public static function renderStorageSelfTest(): void
    {
        $refresh = isset($_GET['bp_storage_recheck'], $_GET['_wpnonce']) && wp_verify_nonce(sanitize_key((string) wp_unslash($_GET['_wpnonce'])), 'bp_storage_recheck'); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ($refresh) {
            \Bootspruefung\Learning\Content\Media\MediaStorage::relocateAll(true);
        }
        $result = self::storageSelfTest($refresh);
        echo '<h2>'.esc_html__('Dateischutz (Content-Pakete, geschützte Dateien)', 'bootspruefung-learning').'</h2>';
        $layout = \Bootspruefung\Learning\Content\Media\MediaStorage::layout();
        foreach ((array) ($layout['errors'] ?? []) as $error) {
            echo '<div class="notice notice-error inline"><p>'.esc_html((string) $error).'</p></div>';
        }
        $configured = \Bootspruefung\Learning\Content\Media\MediaStorage::configuredPrivateDir();
        if ($configured !== null && \Bootspruefung\Learning\Content\Media\MediaStorage::privateBaseDir() !== $configured) {
            echo '<div class="notice notice-error inline"><p>'.esc_html(sprintf(__('Der private Ordner %s (BOOTSPRUEFUNG_PRIVATE_DIR) ist nicht anleg- bzw. beschreibbar; Pakete und geschützte Dateien liegen deshalb im Upload-Ordner.', 'bootspruefung-learning'), $configured)).'</p></div>';
        }
        if ($result['overall'] === 'exposed') {
            echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('Direkt abrufbar:', 'bootspruefung-learning').'</strong> '.esc_html__('Der Webserver liefert Dateien aus den gesperrten Ordnern aus (er wertet die .htaccess nicht aus, z. B. nginx). Die Ordnernamen enthalten ein Zufallsgeheimnis, wer den Pfad kennt (Logs, Backups), kann aber Pakete samt Lösungen bzw. geschützte PDFs laden. Bitte in der Server-Konfiguration ergänzen (nginx, im server-Block vor der PHP-Regel) und neu laden:', 'bootspruefung-learning').'</p>';
            echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px">'.esc_html(self::nginxRule()).'</pre>';
            echo '<p>'.esc_html__('Alternativ beide Ordner außerhalb des Webroots ablegen: in wp-config.php define( \'BOOTSPRUEFUNG_PRIVATE_DIR\', \'/pfad/außerhalb/des/webroots\' ); – das Plugin verschiebt die Ordner dann selbst.', 'bootspruefung-learning').'</p></div>';
        } elseif ($result['overall'] === 'unknown') {
            echo '<div class="notice notice-warning inline"><p>'.esc_html__('Der Selbsttest konnte die Testdatei nicht anfragen (Anfragen an die eigene Website blockiert?). Bitte manuell prüfen: Eine Datei aus dem Paketordner darf per Browser nicht abrufbar sein. Für nginx:', 'bootspruefung-learning').'</p><pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px">'.esc_html(self::nginxRule()).'</pre></div>';
        } else {
            echo '<div class="notice notice-success inline"><p>'.esc_html__('Geschützt: Content-Pakete und geschützte Dateien sind nicht per Direkt-URL abrufbar.', 'bootspruefung-learning').'</p></div>';
        }
        $labels = [
            'packages' => __('Content-Pakete', 'bootspruefung-learning'),
            'protected' => __('Geschützte Dateien', 'bootspruefung-learning'),
        ];
        $states = [
            'private' => __('außerhalb des Webroots', 'bootspruefung-learning'),
            'ok' => __('gesperrt', 'bootspruefung-learning'),
            'exposed' => __('direkt abrufbar', 'bootspruefung-learning'),
            'unknown' => __('nicht prüfbar', 'bootspruefung-learning'),
        ];
        $rows = [];
        foreach ($result['dirs'] as $kind => $info) {
            $state = $states[$info['status']] ?? $info['status'];
            $detail = $info['code'] !== null ? ' (HTTP '.(int) $info['code'].')' : ($info['error'] ? ' ('.$info['error'].')' : '');
            $rows[] = [esc_html($labels[$kind] ?? $kind), '<code>'.esc_html($info['dir']).'</code>', esc_html($state.$detail)];
        }
        Ui::table([__('Bereich', 'bootspruefung-learning'), __('Ordner', 'bootspruefung-learning'), __('Ergebnis', 'bootspruefung-learning')], $rows);
        if ($configured !== null) {
            echo '<p>'.esc_html(sprintf(__('Datensicherung: Der private Ordner %s liegt außerhalb des Upload-Ordners und muss zusätzlich gesichert werden.', 'bootspruefung-learning'), $configured)).'</p>';
        }
        $recheck = wp_nonce_url(add_query_arg('bp_storage_recheck', '1'), 'bp_storage_recheck');
        printf(
            '<p class="description">%s <a href="%s">%s</a></p>',
            esc_html(sprintf(__('Geprüft: %s (wird 12 Stunden zwischengespeichert).', 'bootspruefung-learning'), wp_date('d.m.Y H:i', (int) $result['checked_at']))),
            esc_url($recheck),
            esc_html__('Erneut prüfen', 'bootspruefung-learning')
        );
    }
}
