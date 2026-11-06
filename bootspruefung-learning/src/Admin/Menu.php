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
        (new ImportPage())->register();
        (new ContentPages())->register();
        (new AccessPages())->register();
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
            [AccessPages::FREE_SLUG, __('Kostenlose Bereiche', 'bootspruefung-learning'), Capabilities::MANAGE_ENTITLEMENTS, [$access, 'renderFree']],
            [self::SLUG.'-status', __('Systemstatus', 'bootspruefung-learning'), $read, [$this, 'renderStatus']],
            [self::SLUG.'-settings', __('Einstellungen', 'bootspruefung-learning'), 'manage_options', [$this, 'renderSettings']],
        ];
        foreach ($pages as [$slug, $title, $cap, $callback]) {
            add_submenu_page(self::SLUG, $title, $title, $cap, $slug, $callback);
        }
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

    public function renderSettings(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $settings = Settings::all();
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Einstellungen', 'bootspruefung-learning').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('bp_learning');
        echo '<table class="form-table"><tbody>';
        printf(
            '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[delete_data_on_uninstall]" value="1" %s> %s</label></td></tr>',
            esc_html__('Deinstallation', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            checked($settings['delete_data_on_uninstall'], true, false),
            esc_html__('Beim Löschen des Plugins alle Tabellen, Lernstände und Zugänge entfernen', 'bootspruefung-learning')
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
        echo '</select></td></tr></tbody></table>';
        submit_button();
        echo '</form></div>';
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
