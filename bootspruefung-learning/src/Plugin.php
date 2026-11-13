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
    public const VERSION = '0.14.0';
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
        // Permalinks: Lernbereich und „Mein Konto“-Endpunkte sofort eintragen. Die Endpunkte hängen an
        // WooCommerce-Filtern, die in der Aktivierungsanfrage (nach init) noch nicht wirkten – ohne diesen Schritt
        // lieferten „mein-lernen“/„meine-daten“ nach einer Reaktivierung 404 (T81).
        \Bootspruefung\Learning\Frontend\Router::addRules();
        self::addAccountEndpoints();
        flush_rewrite_rules(false);
        // Bei der nächsten Anfrage (alle Hooks registriert) zusätzlich neu schreiben.
        delete_option(\Bootspruefung\Learning\Frontend\Router::REWRITE_OPTION);
        delete_option(\Bootspruefung\Learning\Privacy\SelfService::REWRITE_OPTION);
        self::ensureSchedules();
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
        // Starttexte „Tipps & Tricks“ (einmalig).
        \Bootspruefung\Learning\Tips\Tips::registerTypes();
        \Bootspruefung\Learning\Tips\Tips::maybeSeed();
    }

    public static function deactivate(): void
    {
        // Bewusst keine Daten- oder Tabellenlöschung (Kap. 8: sichere Deaktivierung).
        self::clearCron();
        // Merken, damit der Abgleich nach der Reaktivierung verpasste Bestellungen nachzieht.
        \Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::deactivated();
        // Erst die eigenen Regeln abmelden, dann neu schreiben: sonst blieben /bootsscheine/ und die Endpunkte
        // nach der Deaktivierung in den gespeicherten Permalinks (T81).
        \Bootspruefung\Learning\Frontend\Router::removeRules();
        self::removeAccountEndpoints();
        flush_rewrite_rules(false);
        delete_option(\Bootspruefung\Learning\Frontend\Router::REWRITE_OPTION);
        delete_option(\Bootspruefung\Learning\Privacy\SelfService::REWRITE_OPTION);
    }

    /**
     * Alle eigenen Cron-Ereignisse (WP-Cron, auch einmalige und solche mit Argumenten): stündliche Wartung,
     * Ablauf-Erinnerung (mit Fortsetzung), Bestellabgleich, Migration, App-Datenexport, Datenschutz-Export.
     */
    public const CRON_HOOKS = [
        \Bootspruefung\Learning\Infrastructure\Cron::HOURLY,
        \Bootspruefung\Learning\Commerce\ExpiryReminder::HOOK,
        \Bootspruefung\Learning\Commerce\ExpiryReminder::CONTINUE_HOOK,
        \Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::HOOK,
        self::MIGRATE_HOOK,
        \Bootspruefung\Learning\Mobile\Controllers\PrivacyController::GENERATE_HOOK,
        \Bootspruefung\Learning\Privacy\AccountDeletion::EXPORT_HOOK,
    ];

    /** Trägt alle eigenen Cron-Ereignisse aus (Deaktivierung, Deinstallation), auch im Action Scheduler. */
    public static function clearCron(): void
    {
        foreach (self::CRON_HOOKS as $hook) {
            wp_unschedule_hook($hook);
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook);
            }
        }
    }

    /**
     * wpmu_drop_tables: eigene Tabellen der gelöschten Website ergänzen, abhängige Tabellen zuerst (Fremdschlüssel).
     *
     * @param array<int,string> $tables
     * @return array<int,string>
     */
    public static function dropTablesOnSiteDelete(array $tables, int $siteId = 0): array
    {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix($siteId > 0 ? $siteId : null);
        foreach (array_reverse(\Bootspruefung\Learning\Infrastructure\Database\Schema::TABLES) as $table) {
            $tables[] = $prefix.'bp_'.$table;
        }

        return array_values(array_unique($tables));
    }

    /** „Mein Konto“-Endpunkte des Plugins (WooCommerce). @return list<string> */
    public static function accountEndpoints(): array
    {
        return [\Bootspruefung\Learning\Commerce\WooCommerce\Integration::ACCOUNT_ENDPOINT, \Bootspruefung\Learning\Privacy\SelfService::ENDPOINT];
    }

    /** Endpunkte wie WooCommerce eintragen (gleiche Maske), falls WooCommerce aktiv ist. */
    private static function addAccountEndpoints(): void
    {
        if (! function_exists('WC') || ! is_object(WC()->query ?? null)) {
            return;
        }
        $mask = method_exists(WC()->query, 'get_endpoints_mask') ? (int) WC()->query->get_endpoints_mask() : EP_PAGES;
        foreach (self::accountEndpoints() as $endpoint) {
            add_rewrite_endpoint($endpoint, $mask);
        }
    }

    /** Endpunkte für diese Anfrage abmelden (vor dem Neuschreiben bei der Deaktivierung). */
    private static function removeAccountEndpoints(): void
    {
        global $wp_rewrite;
        if ($wp_rewrite instanceof \WP_Rewrite) {
            $wp_rewrite->endpoints = array_values(array_filter((array) $wp_rewrite->endpoints, static fn ($ep) => ! in_array($ep[1] ?? '', self::accountEndpoints(), true)));
        }
        if (function_exists('WC') && is_object(WC()->query ?? null) && is_array(WC()->query->query_vars ?? null)) {
            foreach (self::accountEndpoints() as $endpoint) {
                unset(WC()->query->query_vars[$endpoint]);
            }
        }
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'loadTextDomain']);
        add_action('plugins_loaded', [$this, 'maybeMigrate'], 5);
        add_action(self::MIGRATE_HOOK, static fn () => \Bootspruefung\Learning\Infrastructure\Cron::run('migrate', [self::class, 'cronMigration']));
        add_action('admin_init', [Settings::class, 'register']);
        // Multisite: beim Löschen einer Website auch die eigenen Tabellen löschen (T82).
        add_filter('wpmu_drop_tables', [self::class, 'dropTablesOnSiteDelete'], 10, 2);

        Compatibility::register($this->file);
        add_action('plugins_loaded', [\Bootspruefung\Learning\Commerce\WooCommerce\Integration::class, 'register'], 20);
        // Kursgutscheine: öffentlicher Grant-Vertrag v1 für Gutschein-Plugins (docs/kursgutscheine.md).
        \Bootspruefung\Learning\Commerce\VoucherGrants::register();
        (new Menu($this))->register();
        (new RestRegistrar())->register();
        \Bootspruefung\Learning\Infrastructure\RequestMemo::register();
        // Während einer fälligen Migration schreibende Lern-API-Anfragen mit 503 abweisen (T47).
        \Bootspruefung\Learning\Infrastructure\Maintenance::register();
        \Bootspruefung\Learning\Admin\MailStatus::register(); // Absender angleichen, Testmail (T54)
        \Bootspruefung\Learning\Infrastructure\Monitor::register(); // Überwachung und Zeitzone (T55)
        \Bootspruefung\Learning\Infrastructure\UpdateCheck::register(); // „Auf neue Versionen prüfen“, Standard aus (T117)
        \Bootspruefung\Learning\Admin\SiteHealth::register(); // Website-Zustand, Datenschutz-Textvorschlag (T127)
        (new \Bootspruefung\Learning\Frontend\Router($this))->register();
        \Bootspruefung\Learning\Frontend\ReturnAfterLogin::register();
        add_action(\Bootspruefung\Learning\Infrastructure\Cron::HOURLY, [self::class, 'hourly']);
        // Selbstheilung: fehlt der stündliche bzw. tägliche Lauf (Umzug, Cron-Option verloren), neu einplanen (T80).
        add_action('init', [self::class, 'ensureSchedules'], 30);
        add_action(\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::HOOK, \Bootspruefung\Learning\Infrastructure\Cron::guard('order_reconcile', [\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::class, 'run']));
        add_action(\Bootspruefung\Learning\Commerce\ExpiryReminder::HOOK, [\Bootspruefung\Learning\Commerce\ExpiryReminder::class, 'run']);
        add_action(\Bootspruefung\Learning\Commerce\ExpiryReminder::CONTINUE_HOOK, [\Bootspruefung\Learning\Commerce\ExpiryReminder::class, 'resume'], 10, 2); // Rest eines Stapellaufs (T124)
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
     * Wiederkehrende Läufe einplanen, falls sie fehlen: stündliche Wartung und tägliche Ablauf-Erinnerung.
     *
     * Bei der Aktivierung und bei jeder Anfrage (init) geprüft; wp_next_scheduled() liest nur die ohnehin
     * geladene Option „cron“. So kommen die Läufe nach einem Umzug, einem Datenbank-Restore ohne Cron-Option
     * oder einem verlorenen Cron-Eintrag von selbst zurück.
     */
    public static function ensureSchedules(): void
    {
        if (wp_installing()) {
            return;
        }
        if (! wp_next_scheduled(\Bootspruefung\Learning\Infrastructure\Cron::HOURLY)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', \Bootspruefung\Learning\Infrastructure\Cron::HOURLY);
        }
        \Bootspruefung\Learning\Commerce\ExpiryReminder::schedule();
    }

    /**
     * Stündliche Wartung. Jede Teilaufgabe läuft für sich (Infrastructure\Cron): Ein defekter Datensatz
     * bricht weder die übrigen Aufgaben noch spätere Cron-Ereignisse ab.
     */
    public static function hourly(): void
    {
        global $wpdb;
        $cron = \Bootspruefung\Learning\Infrastructure\Cron::class;
        // Zeitpunkt für die Überwachung (Monitor: Cron läuft? T55).
        update_option(\Bootspruefung\Learning\Infrastructure\Monitor::OPTION_HOURLY, time(), false);
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

    /**
     * Version für eingebundene Dateien: Plugin-Version plus Dateistand. So laden Browser nach jeder Änderung die neue
     * Datei, auch wenn die Plugin-Version (versehentlich) gleich geblieben ist (T48).
     */
    public static function assetVersion(string $file, string $relative): string
    {
        $path = dirname($file).'/'.ltrim($relative, '/');
        $mtime = is_file($path) ? (int) filemtime($path) : 0;

        return self::VERSION.($mtime > 0 ? '.'.$mtime : '');
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
     * einen Timeout. Das gilt auch für admin-ajax.php und admin-post.php (is_admin() ist dort wahr, es sind aber
     * Kundenanfragen, z. B. WooCommerce-Warenkorb per Ajax). Dort wird daher nur ein sofortiger Cron-Lauf
     * geplant; ausgeführt wird im Admin, per Cron oder in der CLI. Nach einem Fehlschlag wartet die nächste
     * Ausführung MIGRATION_BACKOFF ab (sonst wiederholt jede Anfrage den ganzen dbDelta-Lauf), auch der
     * Cron-Lauf; die CLI versucht es immer.
     */
    public function maybeMigrate(): void
    {
        $migrator = $this->migrator();
        $ajax = self::isCustomerAdminRequest();
        if ($migrator->schemaIsNewer() && is_admin() && ! $ajax) {
            $installed = (string) $migrator->installedVersion();
            add_action('admin_notices', static function () use ($installed): void {
                \Bootspruefung\Learning\Admin\Notices::add(['key' => 'schema_newer', 'type' => 'error', 'dismiss' => 'none', 'text' => sprintf(
                    /* translators: 1: installiertes Schema, 2: Plugin-Version, 3: erwartetes Schema */
                    __('Die Datenbank hat Schema %1$s, diese Plugin-Version %2$s erwartet %3$s. Das Plugin wurde ohne Rücksicherung der Datenbank auf eine ältere Version zurückgesetzt. Bitte die neuere Version wieder einspielen oder das Datenbank-Backup von vor dem Update zurückspielen (siehe Release-Regel, „Downgrade“).', 'bootspruefung-learning'),
                    $installed,
                    self::VERSION,
                    Migrator::SCHEMA_VERSION
                )]);
            });
        }
        if (! $migrator->needsMigration()) {
            return;
        }
        $failed = get_option(self::OPTION_MIGRATION_FAILED);
        $cli = PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI);
        if (is_admin() && ! $ajax && is_array($failed)) {
            add_action('admin_notices', [self::class, 'migrationNotice']);
        }
        $action = self::migrationAction($cli, is_admin(), wp_doing_cron(), $failed, time(), $ajax);
        if ($action === 'schedule' || $action === 'wait') {
            self::scheduleMigration($failed);
        }
        if ($action === 'run') {
            self::runMigration($migrator);
        }
    }

    /**
     * Kundenanfrage über den Admin-Bereich: admin-ajax.php (z. B. WooCommerce-Warenkorb, Heartbeat) oder
     * admin-post.php (Formulare aus dem Frontend). is_admin() ist dort wahr, es wartet aber ein Kunde.
     */
    public static function isCustomerAdminRequest(): bool
    {
        if (wp_doing_ajax()) {
            return true;
        }
        $script = (string) ($GLOBALS['pagenow'] ?? '');
        if ($script === '' && isset($_SERVER['SCRIPT_NAME'])) {
            $script = basename((string) $_SERVER['SCRIPT_NAME']);
        }

        return $script === 'admin-post.php';
    }

    /**
     * Anfrage ohne wartenden Besucher: WP-CLI, WP-Cron oder Admin-Seite (nicht admin-ajax.php/admin-post.php).
     * Dort dürfen längere Arbeiten laufen (FAQ-, Kunden-FAQ- und Tipps-Import, T84).
     */
    public static function isBackgroundRequest(): bool
    {
        $cli = PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI);

        return self::backgroundContext($cli, wp_doing_cron(), is_admin(), $cli ? false : self::isCustomerAdminRequest());
    }

    /** Entscheidung zu isBackgroundRequest() ohne globale Zustände (Tests). */
    public static function backgroundContext(bool $cli, bool $cron, bool $admin, bool $customerAdmin): bool
    {
        return $cli || $cron || ($admin && ! $customerAdmin);
    }

    /**
     * Wo läuft eine fällige Migration? run = jetzt (CLI, Admin, Cron), schedule = Besucheranfrage (auch
     * admin-ajax.php/admin-post.php) plant einen sofortigen Cron-Lauf, wait = Backoff nach einem Fehlschlag
     * (gilt auch für Cron; die CLI versucht es immer).
     *
     * @param mixed $failed Option OPTION_MIGRATION_FAILED
     * @param bool  $ajax   Kundenanfrage über admin-ajax.php oder admin-post.php (isCustomerAdminRequest)
     */
    public static function migrationAction(bool $cli, bool $admin, bool $cron, mixed $failed, int $now, bool $ajax = false): string
    {
        if ($cli) {
            return 'run';
        }
        if (self::retryAt($failed, $now) > $now) {
            return 'wait';
        }
        if ($cron) {
            return 'run';
        }

        return $admin && ! $ajax ? 'run' : 'schedule';
    }

    /** Frühester nächster Versuch nach einem Fehlschlag (Unix-Zeit), sonst $now. @param mixed $failed */
    public static function retryAt(mixed $failed, int $now): int
    {
        if (! is_array($failed)) {
            return $now;
        }

        return max($now, (int) ($failed['at'] ?? 0) + self::MIGRATION_BACKOFF);
    }

    /**
     * Cron-Lauf für die Migration planen: sofort bzw. nach einem Fehlschlag erst nach Ablauf des Backoffs.
     *
     * @param mixed $failed Option OPTION_MIGRATION_FAILED
     * @return bool true, wenn der Lauf sofort fällig ist (dann lohnt spawn_cron())
     */
    public static function scheduleMigration(mixed $failed = null): bool
    {
        $now = time();
        $at = self::retryAt($failed ?? get_option(self::OPTION_MIGRATION_FAILED), $now);
        $next = wp_next_scheduled(self::MIGRATE_HOOK);
        if ($next === false) {
            wp_schedule_single_event($at, self::MIGRATE_HOOK);
        }

        return $at <= $now;
    }

    /** Cron-Ereignis MIGRATE_HOOK: beachtet den Backoff nach einem Fehlschlag und plant dann neu. */
    public static function cronMigration(): bool
    {
        $failed = get_option(self::OPTION_MIGRATION_FAILED);
        if (self::migrationAction(false, false, true, $failed, time()) === 'wait') {
            self::scheduleMigration($failed);

            return false;
        }

        return self::runMigration();
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
            $failed = ['at' => time(), 'message' => mb_substr($e->getMessage(), 0, 500)];
            update_option(self::OPTION_MIGRATION_FAILED, $failed, false);
            // Neuer Versuch nach dem Backoff, auch ohne weitere Anfragen.
            self::scheduleMigration($failed);
            // Derselbe Hinweis wie beim nächsten Aufruf (nur einmal registriert, liest den aktuellen Fehler).
            add_action('admin_notices', [self::class, 'migrationNotice']);

            return false;
        }
    }

    /** Hinweis bei fehlgeschlagener Datenbank-Aktualisierung (T120): nur für Administratoren, nur einmal. */
    public static function migrationNotice(): void
    {
        $failed = get_option(self::OPTION_MIGRATION_FAILED);
        if (! is_array($failed) || ! current_user_can('manage_options')) {
            return;
        }
        \Bootspruefung\Learning\Admin\Notices::add(self::migrationNoticeArgs($failed));
    }

    /**
     * Inhalt des Hinweises: was passiert ist, was zu tun ist; die Meldung der Datenbank nur zum Aufklappen.
     * @param array<string,mixed> $failed Option OPTION_MIGRATION_FAILED
     * @return array<string,mixed> Argumente für Notices::add()
     */
    public static function migrationNoticeArgs(array $failed): array
    {
        $message = trim((string) ($failed['message'] ?? ''));
        $at = (int) ($failed['at'] ?? 0);
        $html = '<p>'.esc_html__('Bis dahin warten Speichervorgänge der Lern-App (Lernstand, Prüfungen) und neue Funktionen sind eventuell nicht nutzbar. So gehst du vor:', 'bootspruefung-learning').'</p><ol>'
            .'<li>'.esc_html__('Im Systemstatus unter „Wartung“ prüfen, ob die Aktualisierung inzwischen gelaufen ist.', 'bootspruefung-learning').'</li>'
            /* translators: %s: WP-CLI-Befehl */
            .'<li>'.sprintf(esc_html__('Sonst die Aktualisierung von Hand starten: %s (WP-CLI auf dem Server).', 'bootspruefung-learning'), '<code>wp bootspruefung migrate</code>').'</li>'
            .'<li>'.esc_html__('Schlägt sie wieder fehl, die technischen Angaben unten an den Hoster bzw. die technische Betreuung schicken (häufige Ursachen: fehlende Rechte des Datenbank-Nutzers, voller Speicher, Datenbank ohne InnoDB).', 'bootspruefung-learning').'</li></ol>';
        if ($message !== '') {
            $html .= '<details><summary>'.esc_html__('Technische Angaben', 'bootspruefung-learning').'</summary><p>'
                .($at > 0 ? esc_html(sprintf(__('Zeitpunkt: %s', 'bootspruefung-learning'), wp_date('d.m.Y H:i', $at))).'<br>' : '')
                .'<code>'.esc_html($message).'</code></p></details>';
        }

        return [
            'key' => 'migration_failed',
            'type' => 'error',
            'dismiss' => 'none',
            'title' => __('Datenbank-Aktualisierung fehlgeschlagen.', 'bootspruefung-learning'),
            'text' => __('Das Plugin versucht es in einigen Minuten automatisch erneut.', 'bootspruefung-learning'),
            'html' => $html,
            'buttons' => [[__('Systemstatus öffnen', 'bootspruefung-learning'), admin_url('admin.php?page=bootspruefung-status'), true]],
        ];
    }
}
