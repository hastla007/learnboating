<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Adminmenü (Kap. 9). Reihenfolge (T104): Übersicht, Inhalte (Inhaltspakete, Kurse, Fragen, Medien, Regelwerke,
 * Rechte, Prüfungsanmeldung, FAQ, Tipps), Zugänge, Kostenlose Bereiche, Gruppenlizenzen, Mitgliederrabatte, Preise,
 * Protokoll, Einstellungen (mit Bereich „Sicherheit“), Mobile App, Systemstatus.
 */
final class Menu
{
    public const SLUG = 'bootspruefung';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPages']);
        add_action('admin_menu', [self::class, 'sortSubmenu'], 999);
        add_action('admin_post_bp_security_save', [$this, 'handleSecurity']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_footer', [$this, 'confirmScript']);
        (new ImportPage())->register();
        (new ContentPages())->register();
        (new AccessPages())->register();
        (new PricePage())->register();
        (new ExamGuidePages())->register();
        (new FaqAdmin())->register();
        (new CustomerFaqAdmin())->register();
        (new GoLiveNotice())->register();
        add_action('admin_notices', [\Bootspruefung\Learning\Infrastructure\Requirements::class, 'adminNotice']);
    }

    public function addPages(): void
    {
        $content = new ContentPages();
        $import = new ImportPage();
        $access = new AccessPages();
        $read = Capabilities::MANAGE_CONTENT;
        add_menu_page(__('Bootsprüfung', 'bootspruefung-learning'), __('Bootsprüfung', 'bootspruefung-learning'), $read, self::SLUG, [$content, 'renderOverview'], 'dashicons-sos', 56);
        $pages = [
            [self::SLUG, __('Übersicht', 'bootspruefung-learning'), $read, [$content, 'renderOverview']],
            [ImportPage::SLUG, __('Inhaltspakete', 'bootspruefung-learning'), Capabilities::IMPORT_CONTENT, [$import, 'render']],
            [self::SLUG.'-courses', __('Kurse & Module', 'bootspruefung-learning'), $read, [$content, 'renderCourses']],
            [self::SLUG.'-questions', __('Fragen', 'bootspruefung-learning'), $read, [$content, 'renderQuestions']],
            [self::SLUG.'-media', __('Medien', 'bootspruefung-learning'), $read, [$content, 'renderMedia']],
            [self::SLUG.'-rules', __('Prüfungsregeln', 'bootspruefung-learning'), $read, [$content, 'renderRules']],
            [self::SLUG.'-rights', __('Rechte', 'bootspruefung-learning'), $read, [$content, 'renderRights']],
            [AccessPages::SLUG, __('Zugänge', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'render']],
            [AccessPages::GROUP_SLUG, __('Gruppenlizenzen', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'renderGroups']],
            [ExamGuidePages::SLUG, __('Prüfungsanmeldung', 'bootspruefung-learning'), Capabilities::MANAGE_CONTENT, [new ExamGuidePages(), 'render']],
            // FAQ: eigener Beitragstyp (Liste, Bearbeiten im Block-Editor) und Kategorien (Rubriken › Unterkategorien).
            [FaqAdmin::LIST_URL, __('FAQ', 'bootspruefung-learning'), Capabilities::MANAGE_CONTENT, null],
            [FaqAdmin::TERMS_URL, __('FAQ-Kategorien', 'bootspruefung-learning'), Capabilities::MANAGE_CONTENT, null],
            // Kunden-FAQ: nur für Kunden eines Kurses (Kurs-Hub, App), Scheine › Unterkategorien.
            [CustomerFaqAdmin::LIST_URL, __('Kunden-FAQs', 'bootspruefung-learning'), Capabilities::MANAGE_CONTENT, null],
            [CustomerFaqAdmin::TERMS_URL, __('Kunden-FAQ-Kategorien', 'bootspruefung-learning'), Capabilities::MANAGE_CONTENT, null],
            [AccessPages::FREE_SLUG, __('Kostenlose Bereiche', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'renderFree']],
            [PricePage::SLUG, __('Preise', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [new PricePage(), 'render']],
            [AuditPage::SLUG, __('Protokoll', 'bootspruefung-learning'), AuditPage::CAPABILITY, [new AuditPage(), 'render']],
            [self::SLUG.'-status', __('Systemstatus', 'bootspruefung-learning'), $read, [$this, 'renderStatus']],
            [self::SLUG.'-settings', __('Einstellungen', 'bootspruefung-learning'), 'manage_options', [$this, 'renderSettings']],
        ];
        if (\Bootspruefung\Learning\Commerce\WooCommerce\Integration::active()) {
            array_splice($pages, 8, 0, [[AccessPages::DISCOUNT_SLUG, __('Mitgliederrabatte', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'renderDiscounts']]]);
        }
        foreach ($pages as [$slug, $title, $cap, $callback]) {
            add_submenu_page(self::SLUG, $title, $title, $cap, $slug, $callback);
        }
    }

    /** Reihenfolge der Untermenüs; Seiten anderer Teile (z. B. Mobile App, Tipps) werden eingeordnet. */
    public const ORDER = [
        self::SLUG, ImportPage::SLUG, self::SLUG.'-courses', self::SLUG.'-questions', self::SLUG.'-media', self::SLUG.'-rules', self::SLUG.'-rights',
        ExamGuidePages::SLUG, FaqAdmin::LIST_URL, FaqAdmin::TERMS_URL, CustomerFaqAdmin::LIST_URL, CustomerFaqAdmin::TERMS_URL, 'edit.php?post_type='.\Bootspruefung\Learning\Tips\Tips::POST_TYPE,
        AccessPages::SLUG, AccessPages::FREE_SLUG, AccessPages::GROUP_SLUG, AccessPages::DISCOUNT_SLUG, PricePage::SLUG,
        AuditPage::SLUG, self::SLUG.'-settings', 'bootspruefung-mobile', self::SLUG.'-status',
    ];

    /** Untermenü nach ORDER sortieren (T104); Unbekanntes bleibt in seiner Reihenfolge vor „Protokoll“. */
    public static function sortSubmenu(): void
    {
        global $submenu;
        if (empty($submenu[self::SLUG]) || ! is_array($submenu[self::SLUG])) {
            return;
        }
        $submenu[self::SLUG] = self::sortEntries($submenu[self::SLUG]);
    }

    /**
     * @param array<int,array<int,string>> $entries WordPress-Untermenü (Index 2 = Slug)
     * @return list<array<int,string>>
     */
    public static function sortEntries(array $entries): array
    {
        $rank = array_flip(self::ORDER);
        $unknown = $rank[AuditPage::SLUG] - 0.5;
        $keyed = [];
        foreach (array_values($entries) as $i => $entry) {
            $keyed[] = [$rank[(string) ($entry[2] ?? '')] ?? $unknown, $i, $entry];
        }
        usort($keyed, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn ($k) => $k[2], $keyed);
    }

    /** Rückfrage vor endgültigen Aktionen (Löschen, Widerrufen, Verwerfen) auf den Bootsprüfung-Seiten. */
    public function confirmScript(): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if (! str_starts_with($page, 'bootspruefung')) {
            return;
        }
        $default = esc_js(__('Wirklich ausführen? Das lässt sich nicht rückgängig machen.', 'bootspruefung-learning'));
        echo "<script>document.addEventListener('submit',function(e){var b=e.submitter;if(!b){return;}var m=b.getAttribute('data-bp-confirm')||((b.classList.contains('button-link-delete')||b.classList.contains('delete'))?'{$default}':null);if(m&&!window.confirm(m)){e.preventDefault();return;}var h=m&&b.form?b.form.querySelector('input[name=bp_confirmed]'):null;if(h){h.value='1';}},true);</script>"; // phpcs:ignore
    }

    public function enqueue(string $hook): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if (str_starts_with($page, 'bootspruefung')) {
            wp_enqueue_style('bp-admin', plugins_url('assets/admin/admin.css', $this->plugin->file()), [], Plugin::assetVersion($this->plugin->file(), 'assets/admin/admin.css'));
        }
        if (! str_contains($hook, ImportPage::SLUG)) {
            return;
        }
        wp_enqueue_script('bp-admin-import', plugins_url('assets/admin/import.js', $this->plugin->file()), [], Plugin::assetVersion($this->plugin->file(), 'assets/admin/import.js'), true);
        wp_localize_script('bp-admin-import', 'bpImport', [
            'root' => esc_url_raw(rest_url('bootspruefung/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'phases' => array_keys(\Bootspruefung\Learning\Content\Import\ImportService::PHASES),
            'runningText' => __('Import läuft …', 'bootspruefung-learning'),
            'doneText' => __('Import abgeschlossen.', 'bootspruefung-learning'),
        ]);
    }

    public function renderStatus(): void
    {
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $status = SystemStatus::collect();
        $woo = $status['woocommerce'];
        $yes = __('ja', 'bootspruefung-learning');
        $no = __('nein', 'bootspruefung-learning');

        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Systemstatus', 'bootspruefung-learning').'</h1>';
        echo '<table class="widefat striped" style="max-width:720px"><tbody>';
        $rows = [
            __('Plugin-Version', 'bootspruefung-learning') => $status['plugin_version'],
            __('Datenbank-Schema', 'bootspruefung-learning') => sprintf('%s (%s: %s)', $status['schema_version'] ?? '–', __('erwartet', 'bootspruefung-learning'), $status['expected_schema_version']),
            __('Fremdschlüssel', 'bootspruefung-learning') => (string) $status['foreign_keys'],
            __('WooCommerce aktiv', 'bootspruefung-learning') => $woo['active'] ? $yes.' ('.$woo['version'].')' : $no,
            __('WooCommerce-Version unterstützt', 'bootspruefung-learning') => $woo['active'] ? ($woo['supported'] ? $yes : $no) : '–',
            __('HPOS aktiv', 'bootspruefung-learning') => $woo['hpos'] === null ? '–' : ($woo['hpos'] ? $yes : $no),
            'PHP' => $status['php_version'],
            'WordPress' => $status['wp_version'],
        ];
        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }
        echo '</tbody></table>';
        if (($proxy = \Bootspruefung\Learning\Mobile\RateLimiter::proxyWarning()) !== null) {
            echo '<div class="notice notice-warning inline"><p><strong>'.esc_html__('Client-IP:', 'bootspruefung-learning').'</strong> '.esc_html($proxy).'</p></div>';
        }

        if ($woo['active']) {
            $this->renderShopStatus($woo);
            \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks::renderStatus(); // T64/T65
        }
        $this->renderBackupHint();
        SystemStatus::renderMaintenance();
        \Bootspruefung\Learning\Commerce\WooCommerce\OssMonitor::renderStatus(); // T53
        MailStatus::render(); // T54
        SystemStatus::renderStorageSelfTest();
        echo '<h2>'.esc_html__('Tabellen', 'bootspruefung-learning').'</h2>';
        echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>'.esc_html__('Tabelle', 'bootspruefung-learning').'</th><th>'.esc_html__('Speicherart', 'bootspruefung-learning').'</th><th>'.esc_html__('Zeilen (ca.)', 'bootspruefung-learning').'</th></tr></thead><tbody>';
        foreach ($status['tables'] as $table => $info) {
            printf(
                '<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                esc_html('bp_'.$table),
                esc_html($info['exists'] ? (string) $info['engine'] : __('fehlt', 'bootspruefung-learning')),
                esc_html($info['rows'] === null ? '–' : (string) $info['rows'])
            );
        }
        echo '</tbody></table></div>';
    }

    /** Datensicherung erfolgt über ein Backup-Plugin oder den Hoster – hier steht, was dazugehören muss. */
    private function renderBackupHint(): void
    {
        global $wpdb;
        $uploads = wp_upload_dir(null, false);
        $dir = trailingslashit((string) $uploads['basedir']).'bootspruefung/';
        echo '<h2>'.esc_html__('Datensicherung', 'bootspruefung-learning').'</h2>';
        echo '<p>'.esc_html__('Das Plugin hat keine eigene Sicherung. Bitte ein WordPress-Backup-Plugin oder die Sicherung des Hosters nutzen und darauf achten, dass Folgendes enthalten ist:', 'bootspruefung-learning').'</p><ul style="list-style:disc;margin-left:2em">';
        printf('<li>%s <code>%s</code></li>', esc_html__('alle Datenbanktabellen mit Präfix', 'bootspruefung-learning'), esc_html($wpdb->prefix.'bp_'));
        printf('<li>%s <code>%s</code>%s</li>', esc_html__('der Ordner', 'bootspruefung-learning'), esc_html($dir), esc_html__(' – Inhaltspakete, Medien und geschützte Dateien (protected-…); manche Sicherungs-Plugins schließen Unterordner oder große Dateien standardmäßig aus', 'bootspruefung-learning'));
        printf('<li>%s <code>bp_learning_*</code> %s</li>', esc_html__('die Optionen', 'bootspruefung-learning'), esc_html__('in der Tabelle wp_options (Einstellungen, aktives Inhaltspaket, Rabatte, Protokoll der Zahlungskonflikte)', 'bootspruefung-learning'));
        printf('<li>%s</li>', esc_html__('WooCommerce-Bestellungen und Kundenkonten (Zugänge verweisen auf Bestellpositionen und Nutzer-IDs)', 'bootspruefung-learning'));
        if (($private = \Bootspruefung\Learning\Content\Media\MediaStorage::configuredPrivateDir()) !== null) {
            printf('<li>%s <code>%s</code> %s</li>', esc_html__('der private Ordner', 'bootspruefung-learning'), esc_html($private), esc_html__('(BOOTSPRUEFUNG_PRIVATE_DIR, liegt außerhalb des Upload-Ordners)', 'bootspruefung-learning'));
        }
        printf('<li>%s</li>', esc_html__('wp-config.php (Datenbank-Zugang, Sicherheitsschlüssel für Abmeldelinks und Anmeldungen, BOOTSPRUEFUNG_*-Konstanten)', 'bootspruefung-learning'));
        echo '</ul><p>'.esc_html__('Nach einer Wiederherstellung prüft der Befehl wp bootspruefung backup-check (ändert nichts) dasselbe wie diese Liste:', 'bootspruefung-learning').'</p>';
        $labels = ['ok' => __('ok', 'bootspruefung-learning'), 'warn' => __('Warnung', 'bootspruefung-learning'), 'fail' => __('Fehler', 'bootspruefung-learning')];
        $colors = ['ok' => '#008a20', 'warn' => '#996800', 'fail' => '#b32d2e'];
        $rows = [];
        foreach (\Bootspruefung\Learning\Infrastructure\BackupCheck::run() as $r) {
            $rows[] = [esc_html($r['check']), '<strong style="color:'.esc_attr($colors[$r['status']] ?? '#000').'">'.esc_html($labels[$r['status']] ?? $r['status']).'</strong>', esc_html($r['detail'])];
        }
        Ui::table([__('Prüfung nach Wiederherstellung', 'bootspruefung-learning'), __('Stand', 'bootspruefung-learning'), __('Details', 'bootspruefung-learning')], $rows);
    }

    public function renderSettings(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $settings = Settings::all();
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Einstellungen', 'bootspruefung-learning').'</h1>';
        // Untermenü-Seiten zeigen die „Gespeichert“-Meldung nicht von selbst.
        if (! empty($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Einstellungen gespeichert.', 'bootspruefung-learning').'</p></div>';
        }
        settings_errors('bp_learning');
        // T104: Bereiche statt einer langen Liste; die Option-Schlüssel bleiben gleich.
        echo '<p>'.esc_html__('Bereiche:', 'bootspruefung-learning').' <a href="#bp-set-access">'.esc_html__('Zugang und Erinnerungen', 'bootspruefung-learning').'</a> · <a href="#bp-set-display">'.esc_html__('Darstellung und Lernen', 'bootspruefung-learning').'</a> · <a href="#bp-set-privacy">'.esc_html__('Datenschutz und Daten', 'bootspruefung-learning').'</a> · <a href="#bp-set-security">'.esc_html__('Sicherheit', 'bootspruefung-learning').'</a> · <a href="#bp-set-disputes">'.esc_html__('Zahlungskonflikte', 'bootspruefung-learning').'</a></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('bp_learning');
        echo '<h2 id="bp-set-access">'.esc_html__('Zugang und Erinnerungen', 'bootspruefung-learning').'</h2><table class="form-table"><tbody>';
        printf('<tr><th scope="row"><label for="bp-default-access">%s</label></th><td><select id="bp-default-access" name="%s[default_access]">', esc_html__('Zugang ohne Shop', 'bootspruefung-learning'), esc_attr(Settings::OPTION));
        foreach (['logged_in' => __('Alle angemeldeten Nutzer dürfen lernen und prüfen', 'bootspruefung-learning'), 'entitled_only' => __('Nur kostenlose Regeln und Freischaltungen', 'bootspruefung-learning')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($settings['default_access'], $value, false), esc_html($label));
        }
        echo '</select><p class="description">'.esc_html__('„Alle angemeldeten Nutzer“ ist für die Zeit vor dem Shopstart gedacht. Sobald Lernprodukte in WooCommerce verkauft werden, auf „Nur kostenlose Regeln und Freischaltungen“ umstellen – erst dann sperren Käufe, Erstattungen und Aussetzungen tatsächlich.', 'bootspruefung-learning').'</p></td></tr>';
        $this->renderReminderRows($settings);
        echo '</tbody></table><h2 id="bp-set-display">'.esc_html__('Darstellung und Lernen', 'bootspruefung-learning').'</h2><table class="form-table"><tbody>';
        printf('<tr><th scope="row"><label for="bp-video-captions">%s</label></th><td><select id="bp-video-captions" name="%s[video_captions]">', esc_html__('Untertitel der Lernvideos', 'bootspruefung-learning'), esc_attr(Settings::OPTION));
        foreach (['off' => __('Keine', 'bootspruefung-learning'), 'vtt' => __('WebVTT-Datei neben dem Video (gleicher Name, Endung .vtt)', 'bootspruefung-learning')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($settings['video_captions'] ?? 'off', $value, false), esc_html($label));
        }
        echo '</select><p class="description">'.esc_html__('Zum Beispiel lektion-01.mp4 und lektion-01.vtt im selben Ordner. Erst einschalten, wenn zu jedem Video eine Untertitel-Datei vorliegt. Bei einem fremden Video-Server muss dieser CORS für die Website erlauben.', 'bootspruefung-learning').'</p></td></tr>';
        printf('<tr><th scope="row"><label for="bp-radio-voice">%s</label></th><td><select id="bp-radio-voice" name="%s[radio_voice_output]">', esc_html__('Sprachausgabe Funktrainer', 'bootspruefung-learning'), esc_attr(Settings::OPTION));
        foreach (['browser' => __('Browserstimme', 'bootspruefung-learning'), 'recording' => __('Lizenzierte Aufnahmen', 'bootspruefung-learning'), 'off' => __('Aus', 'bootspruefung-learning')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($settings['radio_voice_output'], $value, false), esc_html($label));
        }
        echo '</select></td></tr>';
        printf(
            '<tr><th scope="row"><label for="bp-brand-name">%s</label></th><td><input type="text" class="regular-text" id="bp-brand-name" name="%s[brand_name]" value="%s" placeholder="%s" maxlength="60"><p class="description">%s</p></td></tr>',
            esc_html__('Markenname', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            esc_attr(Settings::brandIsSet() ? (string) Settings::get('brand_name') : ''),
            esc_attr(Settings::brand()),
            esc_html__('Erscheint als Titel des Lernbereichs, im Seitentitel, in den Produkttexten und in der App. Leer = Name der Website (Einstellungen › Allgemein › Titel der Website). Logo und Favicon: Design › Website-Editor bzw. Customizer › Website-Icon. Achtung: Die App-Kennung folgt dem Markennamen, solange unter „Mobile App“ keine eigene Kennung eingetragen ist – vor dem App-Start festlegen und danach nicht mehr ändern.', 'bootspruefung-learning')
        );
        printf(
            '<tr><th scope="row"><label for="bp-price-note">%s</label></th><td><input type="text" class="regular-text" id="bp-price-note" name="%s[price_note]" value="%s" maxlength="80"><p class="description">%s</p></td></tr>',
            esc_html__('Hinweis zum Preis', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            esc_attr(Settings::priceNote()),
            esc_html__('Steht an allen Preisen, in der Kasse und in den Bestellmails, z. B. „inkl. MwSt.“ oder als Kleinunternehmer „gem. § 19 UStG ohne MwSt.“. Leer = kein Hinweis.', 'bootspruefung-learning')
        );
        printf(
            '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[exam_guide_enabled]" value="1" %s> %s</label><p class="description">%s</p></td></tr>',
            esc_html__('Prüfungsanmeldung', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            checked(! empty($settings['exam_guide_enabled']), true, false),
            esc_html__('Für Lernende freischalten', 'bootspruefung-learning'),
            esc_html__('Seite „Prüfung anmelden“ je Kurs: Anleitung, Gebühren, Unterlagen, FAQ und eine Liste von Prüfungsausschüssen (nicht vollständig, Hinweis für die Lernenden). Die Lernenden melden sich selbst beim Prüfungsausschuss an. Derzeit vorbereitet, aber deaktiviert.', 'bootspruefung-learning')
        );
        echo '</tbody></table><h2 id="bp-set-privacy">'.esc_html__('Datenschutz und Daten', 'bootspruefung-learning').'</h2><table class="form-table"><tbody>';
        printf(
            '<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s[privacy_requests_auto]" value="0"><label><input type="checkbox" name="%2$s[privacy_requests_auto]" value="1" %3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
            esc_html__('Datenschutz-Anträge', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            checked(! empty($settings['privacy_requests_auto']), true, false),
            esc_html__('Bestätigte Anträge automatisch ausführen', 'bootspruefung-learning'),
            esc_html__('Sobald jemand die Löschung bestätigt (Knopf „Konto jetzt löschen“ nach dem Link in der Mail): Datenexport erzeugen und den Download-Link mailen bzw. Lerndaten und Kundenkonto löschen. Konten mit Verwaltungsrechten werden nie automatisch gelöscht. Aus: Bestätigte Anträge erledigst du unter Werkzeuge → Personenbezogene Daten exportieren/löschen; das Konto bleibt dann bestehen.', 'bootspruefung-learning')
            .' '.esc_html(\Bootspruefung\Learning\Privacy\AccountDeletion::anonymizesOrders()
                /* translators: %s: aufbewahrte Felder */
                ? sprintf(__('Bestellungen werden anonymisiert (WooCommerce › Einstellungen › Konten & Datenschutz: „Persönliche Daten aus Bestellungen entfernen“ ist an); Nummer, Datum, Produkte, Beträge und für die Steuer %s bleiben.', 'bootspruefung-learning'), 'Firma, Land, Bundesland, Transaktionsnummer')
                : __('Bestellungen bleiben unverändert aufbewahrt (WooCommerce › Einstellungen › Konten & Datenschutz: „Persönliche Daten aus Bestellungen entfernen“ ist aus).', 'bootspruefung-learning'))
        );
        printf(
            '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[delete_data_on_uninstall]" value="1" %s> %s</label></td></tr>',
            esc_html__('Deinstallation', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            checked($settings['delete_data_on_uninstall'], true, false),
            esc_html__('Beim Löschen des Plugins alle Tabellen, Lernstände, Zugänge, Einstellungen, Tipps und importierten Medien entfernen (Seekarten werden gelöscht, geschützte Kurs-PDFs bleiben gesperrt unter uploads/bootspruefung/)', 'bootspruefung-learning')
        );
        echo '</tbody></table>';
        submit_button();
        echo '</form>';
        $this->renderSecurity();
        echo '<div id="bp-set-disputes"></div>';
        if (isset($_GET['saved']) && $_GET['saved'] === 'disputes') { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success inline"><p>'.esc_html__('Konflikt-Einstellungen gespeichert.', 'bootspruefung-learning').'</p></div>';
        }
        \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks::renderSettings();
        echo '</div>';
    }

    /** @param array<string,mixed> $settings */
    private function renderReminderRows(array $settings): void
    {
        printf(
            '<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s[expiry_reminder_enabled]" value="0"><label><input type="checkbox" name="%2$s[expiry_reminder_enabled]" value="1" %3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
            esc_html__('Ablauf-Erinnerung per E-Mail', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            checked(! empty($settings['expiry_reminder_enabled']), true, false),
            esc_html__('Lernende vor dem Ende ihres Zugangs erinnern', 'bootspruefung-learning'),
            esc_html__('Eine E-Mail je Kurs und Ablaufdatum (mehrere Kurse in einer Mail), mit Link zum Verlängern. Keine Erinnerung, wenn schon eine Verlängerung oder ein unbefristeter Zugang besteht. Im Lernbereich erscheint zusätzlich ein Hinweis. Wirkt nur, wenn „Zugang ohne Shop“ auf „Nur kostenlose Regeln und Freischaltungen“ steht.', 'bootspruefung-learning')
        );
        printf(
            '<tr><th scope="row"><label for="bp-expiry-days">%s</label></th><td><input type="number" class="small-text" id="bp-expiry-days" name="%s[expiry_reminder_days]" value="%d" min="1" max="90"> %s</td></tr>',
            esc_html__('Tage vorher', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            (int) $settings['expiry_reminder_days'],
            esc_html__('Tage vor dem Ablauf', 'bootspruefung-learning')
        );
    }

    /**
     * Bereich „Sicherheit“ (T104): vertrauenswürdige Proxys für die Anmelde-Grenzen (Option bp_learning_mobile,
     * Schlüssel trusted_proxies – unverändert, früher unter „Mobile App“).
     */
    private function renderSecurity(): void
    {
        $proxies = (string) \Bootspruefung\Learning\Mobile\MobileSettings::all()['trusted_proxies'];
        echo '<h2 id="bp-set-security">'.esc_html__('Sicherheit', 'bootspruefung-learning').'</h2>';
        if (isset($_GET['saved']) && $_GET['saved'] === 'security') { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success inline"><p>'.esc_html__('Sicherheitseinstellungen gespeichert.', 'bootspruefung-learning').'</p></div>';
        }
        echo '<p>'.esc_html__('Website und App begrenzen Anmeldeversuche, Passwort-Links und Einlöseversuche je Konto und je IP-Adresse. Damit das je Besucher zählt, muss das Plugin die echte IP-Adresse kennen.', 'bootspruefung-learning').'</p>';
        if (($proxyWarning = \Bootspruefung\Learning\Mobile\RateLimiter::proxyWarning()) !== null) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($proxyWarning).'</p></div>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_security_save');
        echo '<input type="hidden" name="action" value="bp_security_save"><table class="form-table"><tbody>';
        printf(
            '<tr><th scope="row"><label for="bp-trusted-proxies">%s</label></th><td><textarea id="bp-trusted-proxies" class="large-text code" rows="3" name="trusted_proxies">%s</textarea><p class="description">%s</p></td></tr>',
            esc_html__('Vertrauenswürdige Proxys', 'bootspruefung-learning'),
            esc_textarea($proxies),
            esc_html__('IP-Adressen oder Netze (CIDR) der Reverse-Proxys/Load-Balancer/CDNs direkt vor WordPress, deren X-Forwarded-For für die Anmelde-Grenzen gilt. Leer (Standard) = keinem Proxy vertrauen, es zählt nur die direkte Gegenstelle (REMOTE_ADDR) – auch Loopback und private Netze gelten nicht automatisch. Hinter einem Proxy (z. B. Docker mit Caddy/nginx davor) dessen Adresse bzw. Netz eintragen, sonst teilen sich alle Kunden die Grenzen der Proxy-IP.', 'bootspruefung-learning')
        );
        echo '</tbody></table>';
        submit_button(__('Sicherheitseinstellungen speichern', 'bootspruefung-learning'), 'primary', 'submit', true);
        echo '</form>';
    }

    /** Speichert nur die vertrauenswürdigen Proxys; übrige App-Einstellungen bleiben unverändert. */
    public function handleSecurity(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_security_save');
        self::saveTrustedProxies((string) wp_unslash($_POST['trusted_proxies'] ?? ''));
        wp_safe_redirect(add_query_arg(['page' => self::SLUG.'-settings', 'saved' => 'security'], admin_url('admin.php')).'#bp-set-security');
        exit;
    }

    /** @return int Anzahl gespeicherter Einträge */
    public static function saveTrustedProxies(string $value): int
    {
        $ms = \Bootspruefung\Learning\Mobile\MobileSettings::class;
        $ms::save(array_merge($ms::all(), ['trusted_proxies' => $value]));
        $count = count($ms::trustedProxies());
        \Bootspruefung\Learning\Audit\AuditLog::record('security_settings_saved', 'settings', $ms::OPTION, null, ['count' => $count]);

        return $count;
    }

    /** @param array<string,mixed> $woo */
    private function renderShopStatus(array $woo): void
    {
        echo '<h2>'.esc_html__('Shop und Zahlungsarten', 'bootspruefung-learning').'</h2>';
        $warnings = [];
        if (! empty($woo['guest_checkout'])) {
            $warnings[] = __('Gastbestellungen sind erlaubt. Für Warenkörbe mit Lernprodukten verlangt das Plugin trotzdem ein Kundenkonto.', 'bootspruefung-learning');
        }
        if (($taxWarning = SystemStatus::taxWarning($woo)) !== null) {
            $warnings[] = $taxWarning;
        }
        if (Settings::get('default_access') === 'logged_in') {
            $warnings[] = __('„Zugang ohne Shop“ steht auf „Alle angemeldeten Nutzer“ – Käufe schalten zwar frei, gesperrt wird aber noch nichts.', 'bootspruefung-learning');
        }
        foreach ($warnings as $w) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($w).'</p></div>';
        }
        // T73: Grundeinstellungen nur prüfen, nichts ändern.
        echo '<h3>'.esc_html__('Grundeinstellungen', 'bootspruefung-learning').'</h3>';
        $basics = [];
        foreach (SystemStatus::shopBasics() as $b) {
            $basics[] = [esc_html($b['label']), $b['ok'] ? '<span style="color:#008a20">'.esc_html__('ok', 'bootspruefung-learning').'</span>' : '<strong style="color:#b32d2e">'.esc_html__('prüfen', 'bootspruefung-learning').'</strong>', esc_html($b['detail'])];
        }
        Ui::table([__('Punkt', 'bootspruefung-learning'), __('Stand', 'bootspruefung-learning'), __('Details', 'bootspruefung-learning')], $basics);
        echo '<h3>'.esc_html__('Zahlungsarten', 'bootspruefung-learning').'</h3>';
        $rows = [];
        foreach (\Bootspruefung\Learning\Commerce\WooCommerce\Gateways::enabled() as $g) {
            $p = $g['profile'];
            $rows[] = [
                esc_html($g['title'].' ('.$g['id'].')'),
                esc_html($p['payment'] ?? __('Allgemein: Freischaltung bei bezahlter Bestellung (is_paid); „In Wartestellung“ vor Zahlung = wartet auf Zahlung.', 'bootspruefung-learning')),
                esc_html($p['dispute'] ?? __('Nicht gesondert bekannt: bezahlte Bestellung auf „In Wartestellung“ setzt aus; sonst manuell aussetzen.', 'bootspruefung-learning')),
            ];
        }
        Ui::table([__('Zahlungsart', 'bootspruefung-learning'), __('Freischaltung', 'bootspruefung-learning'), __('Rückbuchung/Konflikt', 'bootspruefung-learning')], $rows, __('Keine Zahlungsart aktiviert.', 'bootspruefung-learning'));
        printf('<p>%s: %d</p>', esc_html__('Produkte mit Lernzugang', 'bootspruefung-learning'), (int) $woo['learning_products']);
    }
}
