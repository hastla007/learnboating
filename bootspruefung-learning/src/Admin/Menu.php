<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Adminmenü (Kap. 9): Übersicht, Content-Pakete, Content-Browser, Zugänge,
 * kostenlose Bereiche, Systemstatus und Einstellungen.
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
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_footer', [$this, 'confirmScript']);
        (new ImportPage())->register();
        (new ContentPages())->register();
        (new AccessPages())->register();
        (new PricePage())->register();
        (new ExamGuidePages())->register();
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
            [ImportPage::SLUG, __('Content-Pakete', 'bootspruefung-learning'), Capabilities::IMPORT_CONTENT, [$import, 'render']],
            [self::SLUG.'-courses', __('Kurse & Module', 'bootspruefung-learning'), $read, [$content, 'renderCourses']],
            [self::SLUG.'-questions', __('Fragen', 'bootspruefung-learning'), $read, [$content, 'renderQuestions']],
            [self::SLUG.'-media', __('Medien', 'bootspruefung-learning'), $read, [$content, 'renderMedia']],
            [self::SLUG.'-rules', __('Prüfungsregeln', 'bootspruefung-learning'), $read, [$content, 'renderRules']],
            [self::SLUG.'-rights', __('Rechte', 'bootspruefung-learning'), $read, [$content, 'renderRights']],
            [AccessPages::SLUG, __('Zugänge', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'render']],
            [AccessPages::GROUP_SLUG, __('Gruppenlizenzen', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'renderGroups']],
            [ExamGuidePages::SLUG, __('Prüfungsanmeldung', 'bootspruefung-learning'), Capabilities::MANAGE_CONTENT, [new ExamGuidePages(), 'render']],
            [AccessPages::FREE_SLUG, __('Kostenlose Bereiche', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'renderFree']],
            [PricePage::SLUG, __('Preise', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [new PricePage(), 'render']],
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

    /** Rückfrage vor endgültigen Aktionen (Löschen, Widerrufen, Verwerfen) auf den Bootsprüfung-Seiten. */
    public function confirmScript(): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if (! str_starts_with($page, 'bootspruefung')) {
            return;
        }
        $default = esc_js(__('Wirklich ausführen? Das lässt sich nicht rückgängig machen.', 'bootspruefung-learning'));
        echo "<script>document.addEventListener('submit',function(e){var b=e.submitter;if(!b){return;}var m=b.getAttribute('data-bp-confirm')||((b.classList.contains('button-link-delete')||b.classList.contains('delete'))?'{$default}':null);if(m&&!window.confirm(m)){e.preventDefault();}},true);</script>"; // phpcs:ignore
    }

    public function enqueue(string $hook): void
    {
        if (! str_contains($hook, ImportPage::SLUG)) {
            return;
        }
        wp_enqueue_script('bp-admin-import', plugins_url('assets/admin/import.js', $this->plugin->file()), [], Plugin::VERSION, true);
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
            __('DB-Schema', 'bootspruefung-learning') => sprintf('%s (%s: %s)', $status['schema_version'] ?? '–', __('erwartet', 'bootspruefung-learning'), $status['expected_schema_version']),
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

        if ($woo['active']) {
            $this->renderShopStatus($woo);
        }
        $this->renderBackupHint();
        echo '<h2>'.esc_html__('Tabellen', 'bootspruefung-learning').'</h2>';
        echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>'.esc_html__('Tabelle', 'bootspruefung-learning').'</th><th>Engine</th><th>'.esc_html__('Zeilen (ca.)', 'bootspruefung-learning').'</th></tr></thead><tbody>';
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
        printf('<li>%s <code>%s</code>%s</li>', esc_html__('der Ordner', 'bootspruefung-learning'), esc_html($dir), esc_html__(' – Content-Pakete, Medien und geschützte Dateien (protected-…); manche Backup-Plugins schließen Unterordner oder große Dateien standardmäßig aus', 'bootspruefung-learning'));
        printf('<li>%s <code>bp_learning_*</code> %s</li>', esc_html__('die Optionen', 'bootspruefung-learning'), esc_html__('in der Tabelle wp_options (Einstellungen, aktives Paket, Rabatte, Dispute-Protokoll)', 'bootspruefung-learning'));
        printf('<li>%s</li>', esc_html__('WooCommerce-Bestellungen und Kundenkonten (Zugänge verweisen auf Bestellpositionen und Nutzer-IDs)', 'bootspruefung-learning'));
        echo '</ul><p>'.esc_html__('Einmal eine Wiederherstellung auf einer Testkopie üben: danach Systemstatus prüfen (Schema, Tabellen), eine geschützte PDF öffnen und einen Lernzugang im Lernbereich testen.', 'bootspruefung-learning').'</p>';
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
        echo '<form method="post" action="options.php">';
        settings_fields('bp_learning');
        echo '<table class="form-table"><tbody>';
        printf(
            '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[delete_data_on_uninstall]" value="1" %s> %s</label></td></tr>',
            esc_html__('Deinstallation', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            checked($settings['delete_data_on_uninstall'], true, false),
            esc_html__('Beim Löschen des Plugins alle Tabellen, Lernstände, Zugänge, Einstellungen, Tipps und importierten Medien entfernen (Seekarten werden gelöscht, geschützte Kurs-PDFs bleiben gesperrt unter uploads/bootspruefung/)', 'bootspruefung-learning')
        );
        printf('<tr><th scope="row">%s</th><td><select name="%s[default_access]">', esc_html__('Zugang ohne Shop', 'bootspruefung-learning'), esc_attr(Settings::OPTION));
        foreach (['logged_in' => __('Alle angemeldeten Nutzer dürfen lernen und prüfen', 'bootspruefung-learning'), 'entitled_only' => __('Nur kostenlose Regeln und Freischaltungen', 'bootspruefung-learning')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($settings['default_access'], $value, false), esc_html($label));
        }
        echo '</select><p class="description">'.esc_html__('„Alle angemeldeten Nutzer“ ist für die Zeit vor dem Shopstart gedacht. Sobald Lernprodukte in WooCommerce verkauft werden, auf „Nur kostenlose Regeln und Freischaltungen“ umstellen – erst dann sperren Käufe, Erstattungen und Aussetzungen tatsächlich.', 'bootspruefung-learning').'</p></td></tr>';
        printf('<tr><th scope="row">%s</th><td><select name="%s[radio_voice_output]">', esc_html__('Sprachausgabe Funktrainer', 'bootspruefung-learning'), esc_attr(Settings::OPTION));
        foreach (['browser' => __('Browserstimme', 'bootspruefung-learning'), 'recording' => __('Lizenzierte Aufnahmen', 'bootspruefung-learning'), 'off' => __('Aus', 'bootspruefung-learning')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($settings['radio_voice_output'], $value, false), esc_html($label));
        }
        echo '</select></td></tr>';
        printf(
            '<tr><th scope="row">%s</th><td><input type="text" class="regular-text" name="%s[brand_name]" value="%s" maxlength="60"><p class="description">%s</p></td></tr>',
            esc_html__('Markenname', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            esc_attr(Settings::brand()),
            esc_html__('Erscheint als Titel des Lernbereichs, im Seitentitel und in den Produkttexten. Platzhalter „XXXX“, bis der Name feststeht.', 'bootspruefung-learning')
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
            esc_html__('Seite „Prüfung anmelden“ je Kurs: Anleitung, Gebühren, Unterlagen, FAQ und alle Prüfungsausschüsse. Die Lernenden melden sich selbst beim Prüfungsausschuss an. Derzeit vorbereitet, aber deaktiviert.', 'bootspruefung-learning')
        );
        echo '</tbody></table>';
        submit_button();
        echo '</form>';
        if (isset($_GET['saved']) && $_GET['saved'] === 'disputes') { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success inline"><p>'.esc_html__('Konflikt-Einstellungen gespeichert.', 'bootspruefung-learning').'</p></div>';
        }
        \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks::renderSettings();
        echo '</div>';
    }

    /** @param array<string,mixed> $woo */
    private function renderShopStatus(array $woo): void
    {
        echo '<h2>'.esc_html__('Shop und Zahlungsarten', 'bootspruefung-learning').'</h2>';
        $warnings = [];
        if (! empty($woo['guest_checkout'])) {
            $warnings[] = __('Gastbestellungen sind erlaubt. Für Warenkörbe mit Lernprodukten verlangt das Plugin trotzdem ein Kundenkonto.', 'bootspruefung-learning');
        }
        if (Settings::get('default_access') === 'logged_in') {
            $warnings[] = __('„Zugang ohne Shop“ steht auf „Alle angemeldeten Nutzer“ – Käufe schalten zwar frei, gesperrt wird aber noch nichts.', 'bootspruefung-learning');
        }
        foreach ($warnings as $w) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($w).'</p></div>';
        }
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
