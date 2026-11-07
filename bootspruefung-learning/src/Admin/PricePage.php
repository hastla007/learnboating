<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Security\Capabilities;

/**
 * Admin „Preise“: alle Katalogpreise (Theorie, Komplett, Video-Upgrade, Kombis, Pässe, Gruppen,
 * Simulator) ohne Code ändern. Eigene Preise überschreiben den festgelegten Katalogpreis;
 * „Katalog abgleichen“ überträgt sie in den Shop.
 */
final class PricePage
{
    public const SLUG = 'bootspruefung-prices';

    private const GROUPS = [
        'sbf' => 'Sportbootführerscheine',
        'funk' => 'Funkzeugnisse',
        'signal' => 'Seenotsignalmittel',
        'addon' => 'Ergänzungskurse',
        'bundle' => 'Pakete & Pässe',
        'groups' => 'Gruppen & Vereine',
        'simulator' => 'Funkgerät-Simulator',
    ];

    public function register(): void
    {
        add_action('admin_post_bp_shop_prices', [$this, 'handle']);
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $products = ShopCatalog::products();
        $bySku = array_column($products, null, 'sku');
        $errors = get_transient('bp_price_errors_'.get_current_user_id());
        delete_transient('bp_price_errors_'.get_current_user_id());
        $errors = is_array($errors) ? $errors : [];
        $woo = function_exists('wc_get_product_id_by_sku');

        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Preise', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        echo '<p style="max-width:900px">'.esc_html__('Hier legst du die Verkaufspreise (brutto, in Euro) fest. Ein leeres Feld oder der Katalogpreis bedeutet: festgelegter Katalogpreis. Shop, Kasse und Angebotskarten der Website zeigen die Preise aus WooCommerce – geänderte Preise gelten dort erst nach „Speichern und Katalog abgleichen“. Ein Angebotspreis (Sale), den du in WooCommerce einträgst, bleibt beim Abgleich erhalten und hat Vorrang. Bereits gekaufte Zugänge bleiben unverändert.', 'bootspruefung-learning').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('bp_shop_prices', '_wpnonce', true, false).'<input type="hidden" name="action" value="bp_shop_prices">';

        // Kurse mit Videokurs-Stufen: Theorie | Komplett | Upgrade nebeneinander.
        echo '<h2>'.esc_html__('Kurse mit Videokurs-Stufen', 'bootspruefung-learning').'</h2>';
        echo '<p class="description">'.esc_html__('Komplett = Kurs mit Videokurs. Das Upgrade kaufen Lernende zur Theorie-Stufe dazu (im Checkout oder später im Lernbereich). Hinweis: Komplett wird nicht automatisch aus Theorie + Upgrade berechnet – bitte beide Preise prüfen.', 'bootspruefung-learning').'</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>'.esc_html__('Kurs', 'bootspruefung-learning').'</th><th>'.esc_html__('Theorie (ohne Video)', 'bootspruefung-learning').'</th><th>'.esc_html__('Komplett mit Videokurs', 'bootspruefung-learning').'</th><th>'.esc_html__('Video-Upgrade', 'bootspruefung-learning').'</th></tr></thead><tbody>';
        $shown = [];
        foreach (array_keys(ShopCatalog::VIDEO_TIERS) as $base) {
            if (! isset($bySku[$base])) {
                continue;
            }
            $theory = (float) $bySku[$base]['price'];
            $komplett = (float) ($bySku[ShopCatalog::komplettSku($base)]['price'] ?? 0);
            $upgrade = (float) ($bySku[ShopCatalog::videoSku($base)]['price'] ?? 0);
            $warn = '';
            if ($komplett > 0 && $komplett <= $theory) {
                $warn = __('Komplett ist nicht teurer als Theorie.', 'bootspruefung-learning');
            } elseif ($komplett > 0 && $upgrade > 0 && $komplett > $theory + $upgrade + 0.001) {
                $warn = __('Komplett ist teurer als Theorie + Upgrade – Theorie mit Upgrade wäre günstiger.', 'bootspruefung-learning');
            }
            echo '<tr><td><strong>'.esc_html($bySku[$base]['name']).'</strong><br><code>'.esc_html($base).'</code>'
                .($warn !== '' ? '<br><span style="color:#996800">⚠ '.esc_html($warn).'</span>' : '').'</td>';
            foreach ([$base, ShopCatalog::komplettSku($base), ShopCatalog::videoSku($base)] as $sku) {
                echo '<td>'.(isset($bySku[$sku]) ? $this->field($bySku[$sku], $errors, $woo) : '–').'</td>';
                $shown[$sku] = true;
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        foreach (self::GROUPS as $group => $label) {
            $rows = array_filter($products, static fn ($p) => ! isset($shown[$p['sku']]) && $p['group'] === $group);
            if ($rows === []) {
                continue;
            }
            echo '<h2>'.esc_html($label).'</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th>'.esc_html__('Produkt', 'bootspruefung-learning').'</th><th>'.esc_html__('Laufzeit', 'bootspruefung-learning').'</th><th>'.esc_html__('Preis', 'bootspruefung-learning').'</th></tr></thead><tbody>';
            foreach ($rows as $p) {
                $shown[$p['sku']] = true;
                $seats = (int) ($p['seats'] ?? 0);
                echo '<tr><td><strong>'.esc_html($p['name']).'</strong><br><code>'.esc_html($p['sku']).'</code>'
                    .($seats > 0 ? ' · '.esc_html(sprintf(_n('%d Platz', '%d Plätze', $seats, 'bootspruefung-learning'), $seats)) : '').'</td>'
                    .'<td>'.esc_html(sprintf(_n('%d Tag', '%d Tage', (int) $p['days'], 'bootspruefung-learning'), (int) $p['days'])).'</td>'
                    .'<td>'.$this->field($p, $errors, $woo).'</td></tr>';
            }
            echo '</tbody></table>';
        }
        // Alles Übrige (neue Gruppen) nicht verschlucken.
        $rest = array_filter($products, static fn ($p) => ! isset($shown[$p['sku']]));
        if ($rest !== []) {
            echo '<h2>'.esc_html__('Weitere Produkte', 'bootspruefung-learning').'</h2><table class="widefat striped" style="max-width:1100px"><tbody>';
            foreach ($rest as $p) {
                echo '<tr><td><strong>'.esc_html($p['name']).'</strong><br><code>'.esc_html($p['sku']).'</code></td><td>'.$this->field($p, $errors, $woo).'</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<p class="submit">';
        echo '<button class="button button-primary" name="op" value="'.($woo ? 'apply' : 'save').'">'.esc_html($woo ? __('Speichern und Katalog abgleichen', 'bootspruefung-learning') : __('Preise speichern', 'bootspruefung-learning')).'</button> ';
        if ($woo) {
            echo '<button class="button" name="op" value="save">'.esc_html__('Nur speichern', 'bootspruefung-learning').'</button> ';
            echo '<label><input type="checkbox" name="publish" value="1"> '.esc_html__('Produkte dabei veröffentlichen (sonst bleiben neue Produkte Entwurf)', 'bootspruefung-learning').'</label>';
        }
        echo '</p></form>';

        if (ShopCatalog::ownPrices() !== []) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\''.esc_js(__('Alle eigenen Preise entfernen und wieder die Katalogpreise verwenden?', 'bootspruefung-learning')).'\')">'
                .wp_nonce_field('bp_shop_prices', '_wpnonce', true, false).'<input type="hidden" name="action" value="bp_shop_prices">'
                .'<button class="button-link-delete" name="op" value="reset">'.esc_html__('Alle eigenen Preise zurücksetzen', 'bootspruefung-learning').'</button></form>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $p @param array<string,string> $errors */
    private function field(array $p, array $errors, bool $woo): string
    {
        $sku = (string) $p['sku'];
        $own = $p['price'] !== $p['catalog_price'];
        $id = 'bp-price-'.sanitize_html_class($sku);
        $html = '<label class="screen-reader-text" for="'.esc_attr($id).'">'.esc_html(sprintf(__('Preis %s', 'bootspruefung-learning'), $p['name'])).'</label>'
            .'<input type="text" inputmode="decimal" size="8" id="'.esc_attr($id).'" name="prices['.esc_attr($sku).']" value="'.esc_attr(self::de((string) $p['price'])).'"'
            .(isset($errors[$sku]) ? ' aria-invalid="true" style="border-color:#d63638"' : '').'> €';
        $notes = [];
        if (isset($errors[$sku])) {
            $notes[] = '<span style="color:#d63638">'.esc_html($errors[$sku]).'</span>';
        }
        $notes[] = $own
            ? '<strong>'.esc_html__('eigener Preis', 'bootspruefung-learning').'</strong> · '.esc_html(sprintf(__('Katalog: %s €', 'bootspruefung-learning'), self::de((string) $p['catalog_price'])))
            : esc_html__('Katalogpreis', 'bootspruefung-learning');
        if ($woo && ($pid = (int) wc_get_product_id_by_sku($sku)) && ($product = wc_get_product($pid))) {
            $shop = (string) $product->get_regular_price();
            if ($shop !== '' && number_format((float) $shop, 2, '.', '') !== $p['price']) {
                $notes[] = '<span style="color:#996800">'.esc_html(sprintf(__('im Shop noch %s € – bitte abgleichen', 'bootspruefung-learning'), self::de($shop))).'</span>';
            }
            if ($product->is_on_sale('edit')) {
                $notes[] = '<span style="color:#996800">'.esc_html(sprintf(__('Angebotspreis im Shop aktiv: %s €', 'bootspruefung-learning'), self::de((string) $product->get_sale_price('edit')))).'</span>';
            }
        } elseif ($woo) {
            $notes[] = esc_html__('noch nicht im Shop', 'bootspruefung-learning');
        }

        return $html.'<br><small>'.implode('<br>', $notes).'</small>';
    }

    private static function de(string $price): string
    {
        return number_format((float) $price, 2, ',', '');
    }

    public function handle(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_shop_prices');
        $op = sanitize_key(wp_unslash($_POST['op'] ?? 'save'));
        if ($op === 'reset') {
            ShopCatalog::saveOwnPrices([]);
            Ui::flash(__('Eigene Preise entfernt – es gelten wieder die Katalogpreise. Zum Übertragen in den Shop „Katalog abgleichen“.', 'bootspruefung-learning'));
            wp_safe_redirect(Ui::url(self::SLUG));
            exit;
        }
        $input = [];
        foreach ((array) wp_unslash($_POST['prices'] ?? []) as $sku => $value) {
            if (is_scalar($value)) {
                $input[sanitize_text_field((string) $sku)] = sanitize_text_field((string) $value);
            }
        }
        // Ungültige Eingaben: bisheriger Preis bleibt, Fehler werden am Feld angezeigt.
        $result = ShopCatalog::saveOwnPrices($input);
        $count = count(ShopCatalog::ownPrices());
        $message = sprintf(_n('Preise gespeichert: %d eigener Preis.', 'Preise gespeichert: %d eigene Preise.', $count, 'bootspruefung-learning'), $count);
        $type = 'success';
        if ($result['errors'] !== []) {
            set_transient('bp_price_errors_'.get_current_user_id(), $result['errors'], 300);
            $message = sprintf(_n('%d Preis ist ungültig und wurde nicht übernommen (Format z. B. 29,90).', '%d Preise sind ungültig und wurden nicht übernommen (Format z. B. 29,90).', count($result['errors']), 'bootspruefung-learning'), count($result['errors'])).' '.$message;
            $type = 'error';
        }
        if ($op === 'apply') {
            try {
                $rows = ShopCatalog::applyProducts(false, ! empty($_POST['publish']));
                $changed = count(array_filter($rows, static fn ($r) => $r['action'] !== 'unverändert'));
                $message .= ' '.sprintf(__('Katalog abgeglichen: %1$d Produkte, %2$d geändert.', 'bootspruefung-learning'), count($rows), $changed);
            } catch (\RuntimeException $e) {
                $message .= ' '.$e->getMessage();
                $type = 'error';
            }
        }
        Ui::flash($message, $type);
        wp_safe_redirect(Ui::url(self::SLUG));
        exit;
    }
}
