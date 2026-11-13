<?php

declare(strict_types=1);

namespace Bootspruefung\Learning;

use Bootspruefung\Learning\Admin\Menu;
use Bootspruefung\Learning\Cli\Commands;
use Bootspruefung\Learning\Commerce\WooCommerce\Compatibility;
use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\Requirements;
use Bootspruefung\Learning\Privacy\PersonalData;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;

/** Einstiegspunkt: registriert alle Hooks. */
final class Plugin
{
    public const VERSION = '0.13.0';
    public const TEXT_DOMAIN = 'bootspruefung-learning';

    /**
     * Vertragsversion der öffentlichen PHP-API für Theme und Add-ons (bootspruefung_learning_contract()).
     * Nur bei inkompatiblen Änderungen erhöhen (Klasse/Methode entfernt, Katalogschlüssel umbenannt).
     */
    public const API_CONTRACT = 1;

    private static ?self $instance = null;

    private function __construct(private readonly string $file)
    {
    }

    public static function boot(string $file): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file);
            self::$instance->registerHooks();
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('Plugin ist noch nicht gestartet.');
        }

        return self::$instance;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function migrator(): Migrator
    {
        global $wpdb;

        return new Migrator($wpdb);
    }

    public function importService(): \Bootspruefung\Learning\Content\Import\ImportService
    {
        global $wpdb;

        return new \Bootspruefung\Learning\Content\Import\ImportService($wpdb, dirname($this->file).'/schema/v1');
    }

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(esc_html__('Netzwerkweite Aktivierung wird nicht unterstützt. Bitte das Plugin pro Website aktivieren.', 'bootspruefung-learning'));
        }
        $problems = Requirements::problems();
        if ($problems !== []) {
            wp_die(esc_html(implode(' ', $problems)));
        }
        Capabilities::grantToAdministrator();
        \Bootspruefung\Learning\Frontend\Router::addRules();
        flush_rewrite_rules(false);
        if (! wp_next_scheduled('bp_learning_hourly')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'bp_learning_hourly');
        }
        \Bootspruefung\Learning\Commerce\ExpiryReminder::schedule();
        \Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::activated();
        global $wpdb;
        try {
            (new Migrator($wpdb))->migrate();
        } catch (\RuntimeException $e) {
            wp_die(esc_html($e->getMessage()));
        }
        // Standard-FAQs anlegen, solange es noch keine FAQ gibt (danach wie Migrationen per Datenstand).
        \Bootspruefung\Learning\Faq\Faq::registerTypes();
        \Bootspruefung\Learning\Faq\FaqImporter::maybeImport();
        // Kunden-FAQ: Scheine, Unterkategorien und Startpaket (Entwürfe), solange es keine Kunden-FAQ gibt.
        \Bootspruefung\Learning\Faq\CustomerFaq::registerTypes();
        \Bootspruefung\Learning\Faq\CustomerFaqImporter::maybeImport();
    }

    public static function deactivate(): void
    {
        // Bewusst keine Daten- oder Tabellenlöschung (Kap. 8: sichere Deaktivierung).
        wp_clear_scheduled_hook('bp_learning_hourly');
        wp_clear_scheduled_hook(\Bootspruefung\Learning\Commerce\ExpiryReminder::HOOK);
        wp_clear_scheduled_hook(\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::HOOK);
        // Merken, damit der Abgleich nach der Reaktivierung verpasste Bestellungen nachzieht.
        \Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::deactivated();
        flush_rewrite_rules(false);
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'loadTextDomain']);
        add_action('plugins_loaded', [$this, 'maybeMigrate'], 5);
        add_action(self::MIGRATE_HOOK, static fn () => \Bootspruefung\Learning\Infrastructure\Cron::run('migrate', [self::class, 'runMigration']));
        add_action('admin_init', [Settings::class, 'register']);

        Compatibility::register($this->file);
        add_action('plugins_loaded', [\Bootspruefung\Learning\Commerce\WooCommerce\Integration::class, 'register'], 20);
        // Kursgutscheine: öffentlicher Grant-Vertrag v1 für Gutschein-Plugins (docs/kursgutscheine.md).
        \Bootspruefung\Learning\Commerce\VoucherGrants::register();
        (new Menu($this))->register();
        (new RestRegistrar())->register();
        \Bootspruefung\Learning\Infrastructure\RequestMemo::register();
        // Während einer fälligen Migration schreibende Lern-API-Anfragen mit 503 abweisen (T47).
        \Bootspruefung\Learning\Infrastructure\Maintenance::register();
        (new \Bootspruefung\Learning\Frontend\Router($this))->register();
        \Bootspruefung\Learning\Frontend\ReturnAfterLogin::register();
        add_action(\Bootspruefung\Learning\Infrastructure\Cron::HOURLY, [self::class, 'hourly']);
        add_action(\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::HOOK, \Bootspruefung\Learning\Infrastructure\Cron::guard('order_reconcile', [\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::class, 'run']));
        add_action(\Bootspruefung\Learning\Commerce\ExpiryReminder::HOOK, [\Bootspruefung\Learning\Commerce\ExpiryReminder::class, 'run']);
        (new \Bootspruefung\Learning\Commerce\ReminderOptOut())->register(); // Widerspruch gegen die Ablauf-Erinnerung
        PersonalData::register();
        // Gegenstellen für Gutschein- und Ticket-Plugins (docs/schnittstellen-erweiterungen.md).
        \Bootspruefung\Learning\Extensions\Extensions::register();
        \Bootspruefung\Learning\Security\Hardening::register();
        // Videos auf fremden Servern: Theme Kurswasser zeigt dann die Banner-Kategorie „Externe Medien“ (T23).
        add_filter('kurswasser_consent_external_hosts', static fn ($hosts) => array_merge((array) $hosts, \Bootspruefung\Learning\Trainers\VideoService::externalHosts()));
        (new \Bootspruefung\Learning\Gate\ContentGate())->register();
        (new \Bootspruefung\Learning\Gate\ProtectedFiles())->register();
        (new \Bootspruefung\Learning\Gate\Charts())->register();
        (new \Bootspruefung\Learning\Tips\Tips())->register();
        (new \Bootspruefung\Learning\Faq\Faq())->register();
        (new \Bootspruefung\Learning\Faq\CustomerFaq())->register();
        (new \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks())->register();

        if (defined('WP_CLI') && WP_CLI) {
            Commands::register();
        }
    }

    /**
     * Stündliche Wartung. Jede Teilaufgabe läuft für sich (Infrastructure\Cron): Ein defekter Datensatz
     * bricht weder die übrigen Aufgaben noch spätere Cron-Ereignisse ab.
     */
    public static function hourly(): void
    {
        global $wpdb;
        $cron = \Bootspruefung\Learning\Infrastructure\Cron::class;
        $cron::run('exam_expire', static function (): void {
            $content = new \Bootspruefung\Learning\Content\Repositories\ContentRepository();
            $access = new \Bootspruefung\Learning\Learning\Services\AccessService();
            (new \Bootspruefung\Learning\Exams\Services\ExamService($content, new \Bootspruefung\Learning\Learning\Services\QuestionPresenter($content), $access))->expireRunning();
        });
        $cron::run('entitlement_expire', static fn () => (new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb))->expireDue());
        $cron::run('group_invites_expire', static fn () => (new \Bootspruefung\Learning\Commerce\GroupService($wpdb))->expireInvites());
        // Nach einem Update ohne erneute Aktivierung den täglichen Cron nachträglich planen.
        $cron::run('expiry_reminder_schedule', [\Bootspruefung\Learning\Commerce\ExpiryReminder::class, 'schedule']);
        // Verpasste Bestellereignisse nachziehen (z. B. Plugin zeitweise inaktiv, Fehler im Hook).
        $cron::run('order_reconcile', [\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::class, 'run']);
        // Alte Paketkopien und Import-Protokolle aufräumen.
        $cron::run('import_retention', [\Bootspruefung\Learning\Content\Import\Retention::class, 'run']);
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename($this->file)).'/languages');
    }

    /** Einmaliger Wartungslauf für eine fällige Schema-Migration (aus einer Besucheranfrage angestoßen). */
    public const MIGRATE_HOOK = 'bp_learning_migrate';

    /** Letzter Fehlschlag der Migration [at, message]; bis BACKOFF danach kein neuer Versuch außerhalb der CLI. */
    public const OPTION_MIGRATION_FAILED = 'bp_learning_migration_failed';

    public const MIGRATION_BACKOFF = 15 * MINUTE_IN_SECONDS;

    /**
     * Update-Migration nach Plugin-Update ohne erneute Aktivierung.
     *
     * Nicht in Besucheranfragen: Fremdschlüssel und Indizes auf großen Tabellen dauern, der Besucher bekäme
     * einen Timeout. Im Frontend wird daher nur ein sofortiger Cron-Lauf geplant; ausgeführt wird im Admin,
     * per Cron oder in der CLI. Nach einem Fehlschlag wartet die nächste Ausführung MIGRATION_BACKOFF ab
     * (sonst wiederholt jede Anfrage den ganzen dbDelta-Lauf), die CLI versucht es immer.
     */
    public function maybeMigrate(): void
    {
        $migrator = $this->migrator();
        if (! $migrator->needsMigration()) {
            return;
        }
        $failed = get_option(self::OPTION_MIGRATION_FAILED);
        $cli = PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI);
        if (is_admin() && is_array($failed)) {
            add_action('admin_notices', static function () use ($failed): void {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(sprintf(
                    /* translators: %s: Fehlermeldung */
                    __('Bootsprüfung Lernplattform: Die Datenbank-Aktualisierung ist fehlgeschlagen und wird in einigen Minuten erneut versucht. Fehler: %s', 'bootspruefung-learning'),
                    (string) ($failed['message'] ?? '')
                )));
            });
        }
        $action = self::migrationAction($cli, is_admin(), wp_doing_cron(), $failed, time());
        if ($action === 'schedule' && ! wp_next_scheduled(self::MIGRATE_HOOK)) {
            wp_schedule_single_event(time(), self::MIGRATE_HOOK);
        }
        if ($action === 'run') {
            self::runMigration($migrator);
        }
    }

    /**
     * Wo läuft eine fällige Migration? run = jetzt (CLI, Admin, Cron), schedule = Besucheranfrage plant
     * einen sofortigen Cron-Lauf, wait = Backoff nach einem Fehlschlag (CLI versucht es immer).
     *
     * @param mixed $failed Option OPTION_MIGRATION_FAILED
     */
    public static function migrationAction(bool $cli, bool $admin, bool $cron, mixed $failed, int $now): string
    {
        if ($cli) {
            return 'run';
        }
        if (is_array($failed) && $now - (int) ($failed['at'] ?? 0) < self::MIGRATION_BACKOFF) {
            return 'wait';
        }

        return $admin || $cron ? 'run' : 'schedule';
    }

    /** Führt die Migration aus und merkt sich einen Fehlschlag (Backoff, Hinweis im Admin). */
    public static function runMigration(?Migrator $migrator = null): bool
    {
        global $wpdb;
        $migrator ??= new Migrator($wpdb);
        if (! $migrator->needsMigration()) {
            delete_option(self::OPTION_MIGRATION_FAILED);

            return true;
        }
        try {
            if ($migrator->migrate()) {
                delete_option(self::OPTION_MIGRATION_FAILED);

                return true;
            }

            return false; // anderer Lauf hält die Sperre
        } catch (\RuntimeException $e) {
            update_option(self::OPTION_MIGRATION_FAILED, ['at' => time(), 'message' => mb_substr($e->getMessage(), 0, 500)], false);
            add_action('admin_notices', static function () use ($e): void {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($e->getMessage()));
            });

            return false;
        }
    }
}
