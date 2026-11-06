<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Adminmenü (Kap. 9). In dieser Version sind Dashboard/Systemstatus und
 * Einstellungen umgesetzt; die übrigen Bereiche folgen mit Importer,
 * Content-Browser und WooCommerce-Adapter (Kap. 11, Schritte 4–9).
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
    }

    public function addPages(): void
    {
        add_menu_page(
            __('Bootsprüfung', 'bootspruefung-learning'),
            __('Bootsprüfung', 'bootspruefung-learning'),
            Capabilities::MANAGE_CONTENT,
            self::SLUG,
            [$this, 'renderStatus'],
            'dashicons-sos',
            56
        );
        add_submenu_page(self::SLUG, __('Systemstatus', 'bootspruefung-learning'), __('Systemstatus', 'bootspruefung-learning'), Capabilities::MANAGE_CONTENT, self::SLUG, [$this, 'renderStatus']);
        add_submenu_page(self::SLUG, __('Einstellungen', 'bootspruefung-learning'), __('Einstellungen', 'bootspruefung-learning'), 'manage_options', self::SLUG.'-settings', [$this, 'renderSettings']);
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
        printf('<tr><th scope="row">%s</th><td><select name="%s[radio_voice_output]">', esc_html__('Sprachausgabe Funktrainer', 'bootspruefung-learning'), esc_attr(Settings::OPTION));
        foreach (['browser' => __('Browserstimme', 'bootspruefung-learning'), 'recording' => __('Lizenzierte Aufnahmen', 'bootspruefung-learning'), 'off' => __('Aus', 'bootspruefung-learning')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($settings['radio_voice_output'], $value, false), esc_html($label));
        }
        echo '</select></td></tr></tbody></table>';
        submit_button();
        echo '</form></div>';
    }
}
