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

    /**
     * WooCommerce-Grundeinstellungen (To-do T73): Sprache, deutsche WooCommerce-Sprachdateien, Bankverbindung bei
     * aktiver Überweisung, vollständige Shop-Adresse, E-Mail-Format der Kundenmails, Absender. Nur Prüfung – ändert
     * nichts; die Hinweise sagen, wo es einzustellen ist.
     *
     * @param string|null $locale  Sprache der Website (Standard: get_locale())
     * @param string|null $langDir Sprachordner (Standard: WP_LANG_DIR)
     * @return list<array{key:string,ok:bool,label:string,detail:string}>
     */
    public static function shopBasics(?string $locale = null, ?string $langDir = null): array
    {
        $locale ??= get_locale();
        $langDir ??= WP_LANG_DIR;
        $rows = [];
        $add = static function (string $key, bool $ok, string $label, string $detail) use (&$rows): void {
            $rows[] = ['key' => $key, 'ok' => $ok, 'label' => $label, 'detail' => $detail];
        };

        $german = str_starts_with($locale, 'de_');
        $add('language', $german, __('Sprache der Website', 'bootspruefung-learning'), $german ? $locale
            : sprintf(__('%s – unter Einstellungen › Allgemein › Sprache der Website „Deutsch“ wählen (oder Design › Kurswasser einrichten › Shop).', 'bootspruefung-learning'), $locale === '' ? 'en_US' : $locale));
        $woo = 'woocommerce-'.($german ? $locale : 'de_DE');
        $wooLang = is_file($langDir.'/plugins/'.$woo.'.mo') || is_file($langDir.'/plugins/'.$woo.'.l10n.php');
        $add('woocommerce_language', $wooLang, __('WooCommerce-Sprachdateien', 'bootspruefung-learning'), $wooLang
            ? 'languages/plugins/'.$woo.'.mo'
            : sprintf(__('languages/plugins/%s.mo fehlt – Kasse, Bestellmails und Rechnungstexte von WooCommerce bleiben englisch. Unter Dashboard › Aktualisierungen „Übersetzungen aktualisieren“.', 'bootspruefung-learning'), $woo));

        $bacs = get_option('woocommerce_bacs_settings', []);
        if (is_array($bacs) && ($bacs['enabled'] ?? 'no') === 'yes') {
            $ibans = [];
            foreach ((array) get_option('woocommerce_bacs_accounts', []) as $account) {
                $iban = strtoupper((string) preg_replace('/\s+/', '', (string) (is_array($account) ? ($account['iban'] ?? '') : '')));
                if ($iban !== '') {
                    $ibans[] = $iban;
                }
            }
            $invalid = array_values(array_filter($ibans, static fn (string $iban): bool => ! self::ibanValid($iban)));
            $where = __('WooCommerce › Einstellungen › Zahlungen › Direkte Banküberweisung › Kontodetails', 'bootspruefung-learning');
            $add('bacs_account', $ibans !== [] && $invalid === [], __('Bankverbindung für Überweisung', 'bootspruefung-learning'), match (true) {
                $ibans === [] => sprintf(__('Überweisung ist aktiv, aber keine Bankverbindung mit IBAN eingetragen – Kunden bekommen keine Kontodaten. %s', 'bootspruefung-learning'), $where),
                $invalid !== [] => sprintf(__('IBAN ungültig (Prüfziffer): %1$s – bitte prüfen. %2$s', 'bootspruefung-learning'), implode(', ', array_map([self::class, 'maskIban'], $invalid)), $where),
                default => implode(', ', array_map([self::class, 'maskIban'], $ibans)),
            });
        }

        $fields = [
            'woocommerce_store_address' => __('Straße', 'bootspruefung-learning'),
            'woocommerce_store_postcode' => __('PLZ', 'bootspruefung-learning'),
            'woocommerce_store_city' => __('Ort', 'bootspruefung-learning'),
        ];
        $missing = [];
        foreach ($fields as $option => $name) {
            if (trim((string) get_option($option, '')) === '') {
                $missing[] = $name;
            }
        }
        $country = (string) get_option('woocommerce_default_country', '');
        if ($country === '' || $country === 'US:CA') {
            $missing[] = __('Land (steht noch auf der WooCommerce-Vorgabe USA – Kalifornien)', 'bootspruefung-learning');
        }
        $address = trim(implode(', ', array_filter([(string) get_option('woocommerce_store_address', ''), trim((string) get_option('woocommerce_store_postcode', '').' '.(string) get_option('woocommerce_store_city', '')), $country])));
        $add('store_address', $missing === [], __('Shop-Adresse', 'bootspruefung-learning'), $missing === [] ? $address
            : sprintf(__('Fehlt: %s. Steht im Impressum der Mails und ist Grundlage für Steuer und Rechnungen. WooCommerce › Einstellungen › Allgemein › Shop-Adresse.', 'bootspruefung-learning'), implode(', ', $missing)));

        $plain = [];
        if (function_exists('WC') && WC()->mailer()) {
            foreach (WC()->mailer()->get_emails() as $email) {
                if (! $email instanceof \WC_Email || ! $email->is_customer_email()) {
                    continue;
                }
                $settings = get_option('woocommerce_'.$email->id.'_settings', []);
                $type = is_array($settings) && ! empty($settings['email_type']) ? (string) $settings['email_type'] : 'html';
                if ($type === 'plain' || ! class_exists(\DOMDocument::class)) {
                    $plain[] = $email->get_title();
                }
            }
        }
        $add('email_type', $plain === [], __('E-Mail-Format der Kundenmails', 'bootspruefung-learning'), $plain === []
            ? __('HTML bzw. HTML und Text', 'bootspruefung-learning')
            : sprintf(__('Nur Text bei: %s. Rechtstexte und Garantie-Erklärung sind dann schlecht lesbar. WooCommerce › Einstellungen › E-Mails › jeweilige Mail › E-Mail-Typ „HTML“ oder „Multipart“.', 'bootspruefung-learning'), implode(', ', $plain)));

        $from = MailStatus::shopAddress();
        $fromName = trim((string) get_option('woocommerce_email_from_name', ''));
        $add('email_from', $from !== '' && $fromName !== '', __('Absender der Shop-Mails', 'bootspruefung-learning'), $from !== '' && $fromName !== ''
            ? $fromName.' <'.$from.'>'
            : __('Name oder Adresse fehlt (WooCommerce › Einstellungen › E-Mails › Absender). Details im Abschnitt „E-Mail-Versand“.', 'bootspruefung-learning'));

        return $rows;
    }

    /** IBAN-Prüfziffer (ISO 13616, Modulo 97). */
    public static function ibanValid(string $iban): bool
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $rest = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rest = (int) ($rest.$chunk) % 97;
        }

        return $rest === 1;
    }

    /** IBAN gekürzt anzeigen (Land, Prüfziffer, letzte vier Stellen). */
    public static function maskIban(string $iban): string
    {
        return strlen($iban) > 8 ? substr($iban, 0, 4).' … '.substr($iban, -4) : $iban;
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
            // Zahlungskonflikte (T64): nur ja/nein und Umgebung, keine Zugangsdaten.
            'disputes' => [
                'paypal_signature_verified' => ($paypal = \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks::paypalStatus())['verified'],
                'paypal_mode' => $paypal['mode'],
                'paypal_last_verification' => $paypal['last']['result'] ?? null,
                'generic_webhook' => \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks::settings()['generic_secret'] !== '',
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
            // Ablageordner für den Import per SFTP (T108) – und der frühere, falls er noch nicht leer ist.
            'import' => self::probeDir(\Bootspruefung\Learning\Content\Import\ImportSources::dropDir()),
        ];
        $legacy = \Bootspruefung\Learning\Content\Import\ImportSources::legacyDropDir();
        if (is_dir($legacy) && ! is_link($legacy)) {
            $dirs['import_legacy'] = self::probeDir($legacy);
        }
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

    /**
     * Abschnitt „Wartung“ (T47): Datenbank-Migration, stündlicher Wartungslauf, Bestellabgleich (letzter Lauf,
     * Rückstand, Wiederholungen), Fehlerprotokoll und Client-IP hinter einem Proxy.
     */
    public static function renderMaintenance(): void
    {
        global $wpdb;
        $migrator = new \Bootspruefung\Learning\Infrastructure\Database\Migrator($wpdb);
        $time = static fn (int $ts): string => $ts > 0 ? wp_date('d.m.Y H:i', $ts) : '–';
        $failed = get_option(\Bootspruefung\Learning\Plugin::OPTION_MIGRATION_FAILED);
        $hourly = (int) wp_next_scheduled(\Bootspruefung\Learning\Infrastructure\Cron::HOURLY);
        $last = get_option(\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::OPTION_LAST_RUN);
        $last = is_array($last) ? $last : [];
        $retry = \Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::retryList();
        $cursor = get_option(\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::OPTION_CURSOR);
        $rows = [
            __('Datenbank-Migration', 'bootspruefung-learning') => $migrator->needsMigration()
                ? __('fällig – schreibende Anfragen der Lern-API warten (503), bis sie gelaufen ist', 'bootspruefung-learning')
                : __('aktuell', 'bootspruefung-learning'),
            __('Letzter Migrationsfehler', 'bootspruefung-learning') => is_array($failed) ? $time((int) ($failed['at'] ?? 0)).' – '.(string) ($failed['message'] ?? '') : '–',
            __('Nächster Wartungslauf (stündlich)', 'bootspruefung-learning') => $hourly > 0 ? $time($hourly).(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? ' · '.__('WP-Cron bei Besuchen aus (System-Cron nötig)', 'bootspruefung-learning') : '') : __('nicht geplant', 'bootspruefung-learning'),
            __('Bestellabgleich: letzter Lauf', 'bootspruefung-learning') => $last === [] ? '–' : sprintf(
                /* translators: 1: Zeitpunkt, 2: geprüft, 3: geändert, 4: Fehler */
                __('%1$s · %2$d geprüft, %3$d geändert, %4$d Fehler', 'bootspruefung-learning'),
                $time((int) ($last['at'] ?? 0)), (int) ($last['checked'] ?? 0), (int) ($last['changed'] ?? 0), (int) ($last['failed'] ?? 0)
            ),
            __('Bestellabgleich: Rückstand', 'bootspruefung-learning') => ! empty($last['more']) ? __('ja – der nächste Lauf macht weiter', 'bootspruefung-learning') : __('nein', 'bootspruefung-learning'),
            __('Bestellabgleich: Stand bis', 'bootspruefung-learning') => is_array($cursor) ? $time((int) ($cursor['time'] ?? 0)) : '–',
            __('Bestellungen zur Wiederholung', 'bootspruefung-learning') => $retry === [] ? '0' : count($retry).' ('.implode(', ', array_map(static fn ($id) => '#'.$id, array_slice(array_keys($retry), 0, 10))).')',
            __('Letzter stündlicher Lauf', 'bootspruefung-learning') => ($lastHourly = (int) get_option(\Bootspruefung\Learning\Infrastructure\Monitor::OPTION_HOURLY, 0)) > 0
                ? $time($lastHourly).(time() - $lastHourly > \Bootspruefung\Learning\Infrastructure\Monitor::CRON_MAX_AGE ? ' · '.__('länger als 3 Stunden her – Cron prüfen', 'bootspruefung-learning') : '')
                : __('noch keiner', 'bootspruefung-learning'),
            __('Überwachung', 'bootspruefung-learning') => rest_url('bootspruefung/v1/monitor').(defined('BOOTSPRUEFUNG_MONITOR_KEY') ? '?key=…' : '').' · '.__('200 = ok, 503 = Problem', 'bootspruefung-learning'),
            __('Zeitzone', 'bootspruefung-learning') => wp_timezone_string().(\Bootspruefung\Learning\Infrastructure\Monitor::timezoneOk() ? '' : ' · '.__('keine Ortszeit – unter Einstellungen › Allgemein „Berlin“ wählen', 'bootspruefung-learning')),
            __('Wartungs- und Fehlerseite', 'bootspruefung-learning') => sprintf(
                /* translators: 1: maintenance.php status, 2: php-error.php status */
                __('maintenance.php: %1$s · php-error.php: %2$s', 'bootspruefung-learning'),
                is_file(WP_CONTENT_DIR.'/maintenance.php') ? __('vorhanden', 'bootspruefung-learning') : __('fehlt (Vorlage im Plugin-Ordner dropins/)', 'bootspruefung-learning'),
                is_file(WP_CONTENT_DIR.'/php-error.php') ? __('vorhanden', 'bootspruefung-learning') : __('fehlt (Vorlage im Plugin-Ordner dropins/)', 'bootspruefung-learning')
            ),
            __('Client-IP dieser Anfrage', 'bootspruefung-learning') => \Bootspruefung\Learning\Mobile\RateLimiter::clientIp().(\Bootspruefung\Learning\Mobile\RateLimiter::trustedProxies() !== [] ? ' · '.__('über eingetragenen Proxy', 'bootspruefung-learning') : ''),
        ];
        echo '<h2>'.esc_html__('Wartung', 'bootspruefung-learning').'</h2><table class="widefat striped" style="max-width:720px"><tbody>';
        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }
        echo '</tbody></table>';
        $errors = array_slice(\Bootspruefung\Learning\Infrastructure\Cron::errors(), 0, 5);
        if ($errors !== []) {
            echo '<h3>'.esc_html__('Letzte Fehler der Wartungsläufe', 'bootspruefung-learning').'</h3><ul>';
            foreach ($errors as $e) {
                printf('<li><code>%s</code> %s%s – %s</li>', esc_html($e['at'].' UTC'), esc_html($e['task']), $e['item'] !== null ? ' ('.esc_html((string) $e['item']).')' : '', esc_html($e['message']));
            }
            echo '</ul>';
        }
    }

    /**
     * nginx-Regeln für den Upload-Pfad dieser Installation: Pakete (samt Ablageordner packages-…/import/) und
     * geschützte Dateien sperren, den früheren Ablageordner bootspruefung-import/ ebenso (T108), keine Skripte in media/.
     */
    public static function nginxRule(): string
    {
        $path = rtrim((string) wp_parse_url(\Bootspruefung\Learning\Content\Media\MediaStorage::baseUrl(), PHP_URL_PATH), '/');
        $path = str_replace('.', '\\.', $path);

        return "location ~* ^{$path}/(packages|protected) {\n    deny all;\n}\n"
            ."location ~* ^{$path}-import/ {\n    deny all;\n}\n"
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
        echo '<h2>'.esc_html__('Dateischutz (Inhaltspakete, geschützte Dateien)', 'bootspruefung-learning').'</h2>';
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
            echo '<div class="notice notice-success inline"><p>'.esc_html__('Geschützt: Inhaltspakete und geschützte Dateien sind nicht über eine direkte Adresse abrufbar.', 'bootspruefung-learning').'</p></div>';
        }
        $labels = [
            'packages' => __('Inhaltspakete', 'bootspruefung-learning'),
            'protected' => __('Geschützte Dateien', 'bootspruefung-learning'),
            'import' => __('Ablageordner für den Import', 'bootspruefung-learning'),
            'import_legacy' => __('Früherer Ablageordner (noch nicht leer)', 'bootspruefung-learning'),
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
