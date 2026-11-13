<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Infrastructure\Cron;
use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\Monitor;
use Bootspruefung\Learning\Infrastructure\UpdateCheck;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Privacy\PersonalData;
use Bootspruefung\Learning\Settings\Settings;

/**
 * WordPress-Werkzeuge › Website-Zustand und Datenschutz-Leitfaden (To-do T127).
 *
 * - debug_information: Abschnitt „Bootsprüfung“ (Versionen, Schema, Migration, Wartungslauf, Speicher-Selbsttest,
 *   Zugang ohne Shop, WooCommerce, Versionsprüfung). Keine Geheimnisse und keine Pfade: Die privaten Ordner tragen
 *   ein Zufallsgeheimnis im Namen, deshalb nur „außerhalb des Webroots ja/nein“.
 * - site_status_tests (direct): „Migration fehlgeschlagen/fällig“ und „Speicher öffentlich erreichbar“. Ohne
 *   Anfragen nach außen: Der Speicher-Test liest nur das zwischengespeicherte Ergebnis des Selbsttests im
 *   Systemstatus (bzw. erkennt Ordner außerhalb des Webroots ohne Anfrage).
 * - wp_add_privacy_policy_content: kurzer Vorschlagstext, was das Plugin speichert (aus dem Code belegt).
 */
final class SiteHealth
{
    public const SECTION = 'bootspruefung-learning';

    public static function register(): void
    {
        add_filter('debug_information', [self::class, 'debugInformation']);
        add_filter('site_status_tests', [self::class, 'tests']);
        add_action('admin_init', [self::class, 'privacyPolicyContent']);
    }

    /**
     * @param array<string,mixed> $info
     * @return array<string,mixed>
     */
    public static function debugInformation(array $info): array
    {
        $info[self::SECTION] = [
            'label' => __('Bootsprüfung', 'bootspruefung-learning'),
            'description' => __('Lern-Plugin „Bootsprüfung Lernplattform“: Versionen, Datenbank, Wartung und Dateischutz. Details unter Bootsprüfung › Systemstatus.', 'bootspruefung-learning'),
            'fields' => self::fields(),
        ];

        return $info;
    }

    /** @return array<string,array{label:string,value:string,debug?:string}> */
    public static function fields(): array
    {
        global $wpdb;
        $migrator = new Migrator($wpdb);
        $yes = __('ja', 'bootspruefung-learning');
        $no = __('nein', 'bootspruefung-learning');
        $time = static fn (int $ts): string => $ts > 0 ? wp_date('d.m.Y H:i', $ts) : '–';
        $failed = get_option(Plugin::OPTION_MIGRATION_FAILED);
        $hourly = (int) wp_next_scheduled(Cron::HOURLY);
        $lastHourly = (int) get_option(Monitor::OPTION_HOURLY, 0);
        $storage = self::storageState();
        $woo = \Bootspruefung\Learning\Infrastructure\Requirements::wooCommerceStatus();
        $access = (string) Settings::get('default_access');
        $update = UpdateCheck::state();

        $fields = [
            'version' => ['label' => __('Plugin-Version', 'bootspruefung-learning'), 'value' => Plugin::VERSION],
            'api_contract' => ['label' => __('Vertrag für Theme und Add-ons', 'bootspruefung-learning'), 'value' => (string) Plugin::API_CONTRACT],
            'schema' => [
                'label' => __('Datenbank-Schema', 'bootspruefung-learning'),
                'value' => sprintf('%s (%s: %s)', $migrator->installedVersion() ?? '–', __('erwartet', 'bootspruefung-learning'), Migrator::SCHEMA_VERSION),
            ],
            'migration' => [
                'label' => __('Datenbank-Migration', 'bootspruefung-learning'),
                'value' => $migrator->needsMigration() ? __('fällig', 'bootspruefung-learning') : __('aktuell', 'bootspruefung-learning'),
                'debug' => $migrator->needsMigration() ? 'pending' : 'current',
            ],
            'migration_failed' => [
                'label' => __('Letzter Migrationsfehler', 'bootspruefung-learning'),
                'value' => is_array($failed) ? $time((int) ($failed['at'] ?? 0)) : '–',
            ],
            'cron_next' => [
                'label' => __('Nächster Wartungslauf (stündlich)', 'bootspruefung-learning'),
                'value' => $hourly > 0 ? $time($hourly) : __('nicht geplant', 'bootspruefung-learning'),
            ],
            'cron_last' => ['label' => __('Letzter stündlicher Lauf', 'bootspruefung-learning'), 'value' => $time($lastHourly)],
            'cron_errors' => ['label' => __('Fehler der Wartungsläufe (gespeichert)', 'bootspruefung-learning'), 'value' => (string) count(Cron::errors())],
            'wp_cron_disabled' => ['label' => 'DISABLE_WP_CRON', 'value' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? $yes : $no],
            'storage' => [
                'label' => __('Speicher-Selbsttest (Pakete, geschützte Dateien)', 'bootspruefung-learning'),
                'value' => $storage['label'].($storage['checked_at'] > 0 ? ' ('.$time($storage['checked_at']).')' : ''),
                'debug' => $storage['status'],
            ],
            'private_dir' => [
                'label' => __('Privater Ordner außerhalb des Webroots', 'bootspruefung-learning'),
                'value' => MediaStorage::configuredPrivateDir() !== null ? $yes : $no,
            ],
            'default_access' => [
                'label' => __('Zugang ohne Shop', 'bootspruefung-learning'),
                'value' => $access === 'entitled_only'
                    ? __('Nur kostenlose Regeln und Freischaltungen', 'bootspruefung-learning')
                    : __('Alle angemeldeten Nutzer dürfen lernen und prüfen', 'bootspruefung-learning'),
                'debug' => $access,
            ],
            'woocommerce' => [
                'label' => 'WooCommerce',
                'value' => $woo['active'] ? $woo['version'].' · '.__('unterstützt', 'bootspruefung-learning').': '.($woo['supported'] ? $yes : $no).' · HPOS: '.($woo['hpos'] === null ? '–' : ($woo['hpos'] ? $yes : $no)) : __('nicht aktiv', 'bootspruefung-learning'),
            ],
            'update_check' => [
                'label' => __('Auf neue Versionen prüfen', 'bootspruefung-learning'),
                'value' => UpdateCheck::enabled()
                    ? $yes.($update['latest'] !== null ? ' · '.__('neueste', 'bootspruefung-learning').': '.$update['latest'] : '').($update['checked_at'] > 0 ? ' ('.$time($update['checked_at']).')' : '')
                    : $no,
            ],
        ];

        return $fields;
    }

    /**
     * Stand des Speicher-Selbsttests ohne neue Anfrage: zwischengespeichertes Ergebnis aus dem Systemstatus, sonst
     * „außerhalb des Webroots“ (keine URL) oder „noch nicht geprüft“.
     *
     * @return array{status:string,label:string,checked_at:int}
     */
    public static function storageState(): array
    {
        $labels = [
            'ok' => __('gesperrt', 'bootspruefung-learning'),
            'exposed' => __('direkt abrufbar', 'bootspruefung-learning'),
            'unknown' => __('nicht prüfbar', 'bootspruefung-learning'),
            'private' => __('außerhalb des Webroots', 'bootspruefung-learning'),
            'unchecked' => __('noch nicht geprüft', 'bootspruefung-learning'),
        ];
        $cached = get_transient(SystemStatus::STORAGE_TEST_TRANSIENT);
        if (is_array($cached) && isset($cached['overall']) && isset($labels[(string) $cached['overall']])) {
            $status = (string) $cached['overall'];

            return ['status' => $status, 'label' => $labels[$status], 'checked_at' => (int) ($cached['checked_at'] ?? 0)];
        }
        $private = true;
        foreach (['packages', 'protected'] as $kind) {
            if (MediaStorage::publicUrl(MediaStorage::targetDir($kind).'/x') !== null) {
                $private = false;
            }
        }
        $status = $private ? 'private' : 'unchecked';

        return ['status' => $status, 'label' => $labels[$status], 'checked_at' => 0];
    }

    /**
     * @param array<string,mixed> $tests
     * @return array<string,mixed>
     */
    public static function tests(array $tests): array
    {
        $tests['direct']['bootspruefung_migration'] = ['label' => __('Bootsprüfung: Datenbank-Migration', 'bootspruefung-learning'), 'test' => [self::class, 'testMigration']];
        $tests['direct']['bootspruefung_storage'] = ['label' => __('Bootsprüfung: Inhaltspakete und geschützte Dateien', 'bootspruefung-learning'), 'test' => [self::class, 'testStorage']];

        return $tests;
    }

    /** @return array<string,mixed> */
    public static function testMigration(): array
    {
        global $wpdb;
        $migrator = new Migrator($wpdb);
        $failed = get_option(Plugin::OPTION_MIGRATION_FAILED);
        if ($migrator->needsMigration() && is_array($failed)) {
            return self::result('bootspruefung_migration', 'critical', __('Die Datenbank-Migration der Bootsprüfung ist fehlgeschlagen', 'bootspruefung-learning'), sprintf(
                /* translators: 1: Zeitpunkt, 2: Fehlermeldung */
                __('Letzter Versuch: %1$s. Fehler: %2$s. Bis die Migration gelaufen ist, beantwortet die Lern-API schreibende Anfragen mit „Wartung“. Sie wird automatisch erneut versucht; sofort: wp bootspruefung migrate.', 'bootspruefung-learning'),
                wp_date('d.m.Y H:i', (int) ($failed['at'] ?? 0)),
                mb_substr((string) ($failed['message'] ?? ''), 0, 300)
            ));
        }
        if ($migrator->needsMigration()) {
            return self::result('bootspruefung_migration', 'recommended', __('Eine Datenbank-Migration der Bootsprüfung steht aus', 'bootspruefung-learning'), sprintf(
                /* translators: 1: installiertes Schema, 2: erwartetes Schema */
                __('Installiert: Schema %1$s, erwartet: %2$s. Die Migration läuft beim nächsten Aufruf des Admins bzw. per Cron; sofort: wp bootspruefung migrate.', 'bootspruefung-learning'),
                $migrator->installedVersion() ?? '–',
                Migrator::SCHEMA_VERSION
            ));
        }

        return self::result('bootspruefung_migration', 'good', __('Die Datenbank der Bootsprüfung ist aktuell', 'bootspruefung-learning'), sprintf(
            __('Schema %s, keine Migration fällig.', 'bootspruefung-learning'),
            (string) ($migrator->installedVersion() ?? Migrator::SCHEMA_VERSION)
        ));
    }

    /** @return array<string,mixed> */
    public static function testStorage(): array
    {
        $state = self::storageState();

        return match ($state['status']) {
            'exposed' => self::result('bootspruefung_storage', 'critical', __('Inhaltspakete bzw. geschützte Dateien der Bootsprüfung sind öffentlich erreichbar', 'bootspruefung-learning'),
                __('Der Webserver liefert Dateien aus den gesperrten Ordnern aus (z. B. nginx ohne .htaccess). Wer den Pfad kennt, kann Pakete samt Lösungen bzw. geschützte PDFs laden. Die passende Server-Regel steht im Systemstatus; alternativ BOOTSPRUEFUNG_PRIVATE_DIR außerhalb des Webroots setzen.', 'bootspruefung-learning')),
            'unknown' => self::result('bootspruefung_storage', 'recommended', __('Der Dateischutz der Bootsprüfung konnte nicht geprüft werden', 'bootspruefung-learning'),
                __('Der Selbsttest im Systemstatus konnte die Testdatei nicht anfragen. Bitte manuell prüfen, dass eine Datei aus dem Paketordner im Browser nicht abrufbar ist.', 'bootspruefung-learning')),
            'unchecked' => self::result('bootspruefung_storage', 'recommended', __('Der Dateischutz der Bootsprüfung wurde noch nicht geprüft', 'bootspruefung-learning'),
                __('Der Selbsttest läuft beim Öffnen von Bootsprüfung › Systemstatus (Abschnitt „Dateischutz“). Dieser Test hier fragt selbst nichts an.', 'bootspruefung-learning')),
            default => self::result('bootspruefung_storage', 'good', __('Inhaltspakete und geschützte Dateien der Bootsprüfung sind nicht öffentlich erreichbar', 'bootspruefung-learning'),
                $state['status'] === 'private'
                    ? __('Beide Ordner liegen außerhalb des Webroots.', 'bootspruefung-learning')
                    : __('Der letzte Selbsttest im Systemstatus wurde abgewiesen.', 'bootspruefung-learning')),
        };
    }

    /** @return array<string,mixed> */
    private static function result(string $test, string $status, string $label, string $description): array
    {
        $link = admin_url('admin.php?page='.Menu::SLUG.'-status');

        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => __('Bootsprüfung', 'bootspruefung-learning'), 'color' => $status === 'good' ? 'blue' : ($status === 'critical' ? 'red' : 'orange')],
            'description' => '<p>'.esc_html($description).'</p>',
            'actions' => '<p><a href="'.esc_url($link).'">'.esc_html__('Bootsprüfung › Systemstatus öffnen', 'bootspruefung-learning').'</a></p>',
            'test' => $test,
        ];
    }

    /** Vorschlag für die Datenschutzerklärung (Einstellungen › Datenschutz › Leitfaden). */
    public static function privacyPolicyContent(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content(__('Bootsprüfung Lernplattform', 'bootspruefung-learning'), self::privacyPolicyText());
        }
    }

    /** Vorschlagstext (HTML). Inhalte aus dem Code belegt: Tabellen, Fristen und Einstellungen des Plugins. */
    public static function privacyPolicyText(): string
    {
        $days = (int) Settings::get('expiry_reminder_days');
        $p = static fn (string $text): string => '<p>'.esc_html($text).'</p>';
        $html = '<p class="privacy-policy-tutorial">'.esc_html__('Vorschlag des Plugins „Bootsprüfung Lernplattform“: Er beschreibt, was das Plugin speichert. Bitte an deine Einstellungen anpassen und prüfen lassen; der Text ist keine Rechtsberatung. Kauf, Kasse und Kundenkonto beschreibt WooCommerce in einem eigenen Vorschlag.', 'bootspruefung-learning').'</p>';
        $html .= '<strong class="privacy-policy-tutorial">'.esc_html__('Vorschlag für den Text:', 'bootspruefung-learning').'</strong>';
        $html .= '<h3>'.esc_html__('Lernstand', 'bootspruefung-learning').'</h3>';
        $html .= $p(__('Wenn du mit deinem Kundenkonto lernst, speichern wir deine Antworten auf Übungs- und Prüfungsfragen, deinen Fortschritt je Frage und Thema, deine Prüfungssimulationen mit Ergebnis, deine Merkliste und Favoriten sowie deinen Fortschritt in den Trainern (Navigation, Praxis, Videokurs, Funk). Wir brauchen diese Daten, um dir deinen Lernstand anzuzeigen und Fragen zur Wiederholung vorzuschlagen. Außerdem speichern wir, welche Kurse du freigeschaltet hast (Kauf, Gutschein, Gruppenlizenz oder Freigabe) und bis wann.', 'bootspruefung-learning'));
        $html .= '<h3>'.esc_html__('App-Sitzungen und Anmeldung', 'bootspruefung-learning').'</h3>';
        $html .= $p(sprintf(
            /* translators: %d: Tage */
            __('Meldest du dich in der App an, speichern wir je Gerät eine Sitzung mit Gerätename, Plattform, App-Version und den Zeitpunkten der Anmeldung und der letzten Nutzung. Die Anmeldeschlüssel speichern wir nur als Prüfwert. Eine Sitzung endet spätestens nach %d Tagen; du kannst Sitzungen in der App jederzeit abmelden.', 'bootspruefung-learning'),
            (int) (\Bootspruefung\Learning\Mobile\TokenAuth::SESSION_MAX_TTL / DAY_IN_SECONDS)
        ));
        $html .= $p(sprintf(
            /* translators: %d: Tage */
            __('Zum Schutz vor dem Erraten von Passwörtern zählen wir fehlgeschlagene Anmeldungen kurzzeitig (IP-Adresse nur als Prüfwert). Nach einer erfolgreichen Anmeldung setzen wir das Cookie „bp_known_device“ (%d Tage), damit dein Gerät bei einer Sperre durch fremde Fehlversuche weiter angemeldet werden kann.', 'bootspruefung-learning'),
            (int) (\Bootspruefung\Learning\Security\KnownDevice::LIFETIME / DAY_IN_SECONDS)
        ));
        $html .= '<h3>'.esc_html__('Protokoll', 'bootspruefung-learning').'</h3>';
        $html .= $p(sprintf(
            /* translators: %d: Jahre */
            __('Vorgänge zu Zugängen, Käufen, Gruppen und Datenschutz-Anträgen protokollieren wir mit deiner Kundennummer, um sie nachweisen zu können. Zahlungsdaten und IP-Adressen stehen nicht im Protokoll. Einträge werden nach %d Jahren gelöscht.', 'bootspruefung-learning'),
            PersonalData::AUDIT_RETENTION_YEARS
        ));
        if (! empty(Settings::get('expiry_reminder_enabled'))) {
            $html .= '<h3>'.esc_html__('Erinnerung vor Ablauf', 'bootspruefung-learning').'</h3>';
            $html .= $p(sprintf(
                /* translators: %d: Tage */
                __('Läuft ein gekaufter Zugang ab, erinnern wir dich etwa %d Tage vorher einmal per E-Mail, mit Link zum Verlängern. Wir speichern, wann wir erinnert haben. Du kannst jederzeit widersprechen – über den Link in der E-Mail oder in deinem Kundenkonto; dann speichern wir den Widerspruch mit Zeitpunkt und schicken keine Erinnerung mehr.', 'bootspruefung-learning'),
                max(1, $days)
            ));
        }
        $html .= '<h3>'.esc_html__('Auskunft und Löschung', 'bootspruefung-learning').'</h3>';
        $html .= $p(__('Mit dem Datenexport erhältst du deinen Lernstand. Bei einer Löschung entfernen wir deinen Lernstand vollständig. Angaben zu gekauften Zugängen bleiben, soweit Aufbewahrungspflichten zur Bestellung bestehen.', 'bootspruefung-learning'));

        return $html;
    }
}
