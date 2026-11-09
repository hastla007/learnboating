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
    public const VERSION = '0.12.1';
    public const TEXT_DOMAIN = 'bootspruefung-learning';

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
        global $wpdb;
        try {
            (new Migrator($wpdb))->migrate();
        } catch (\RuntimeException $e) {
            wp_die(esc_html($e->getMessage()));
        }
    }

    public static function deactivate(): void
    {
        // Bewusst keine Daten- oder Tabellenlöschung (Kap. 8: sichere Deaktivierung).
        wp_clear_scheduled_hook('bp_learning_hourly');
        flush_rewrite_rules(false);
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'loadTextDomain']);
        add_action('plugins_loaded', [$this, 'maybeMigrate'], 5);
        add_action('admin_init', [Settings::class, 'register']);

        Compatibility::register($this->file);
        add_action('plugins_loaded', [\Bootspruefung\Learning\Commerce\WooCommerce\Integration::class, 'register'], 20);
        (new Menu($this))->register();
        (new RestRegistrar())->register();
        (new \Bootspruefung\Learning\Frontend\Router($this))->register();
        \Bootspruefung\Learning\Frontend\ReturnAfterLogin::register();
        add_action('bp_learning_hourly', static function (): void {
            $content = new \Bootspruefung\Learning\Content\Repositories\ContentRepository();
            $access = new \Bootspruefung\Learning\Learning\Services\AccessService();
            (new \Bootspruefung\Learning\Exams\Services\ExamService($content, new \Bootspruefung\Learning\Learning\Services\QuestionPresenter($content), $access))->expireRunning();
            global $wpdb;
            (new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb))->expireDue();
            (new \Bootspruefung\Learning\Commerce\GroupService($wpdb))->expireInvites();
        });
        PersonalData::register();
        \Bootspruefung\Learning\Security\Hardening::register();
        (new \Bootspruefung\Learning\Gate\ContentGate())->register();
        (new \Bootspruefung\Learning\Gate\ProtectedFiles())->register();
        (new \Bootspruefung\Learning\Gate\Charts())->register();
        (new \Bootspruefung\Learning\Tips\Tips())->register();
        (new \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks())->register();

        if (defined('WP_CLI') && WP_CLI) {
            Commands::register();
        }
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename($this->file)).'/languages');
    }

    /** Update-Migration nach Plugin-Update ohne erneute Aktivierung. */
    public function maybeMigrate(): void
    {
        $migrator = $this->migrator();
        if (! $migrator->needsMigration()) {
            return;
        }
        try {
            $migrator->migrate();
        } catch (\RuntimeException $e) {
            add_action('admin_notices', static function () use ($e): void {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($e->getMessage()));
            });
        }
    }
}
