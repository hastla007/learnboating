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
    private const SKIPPED_TRANSIENT = 'bp_catalog_skipped_';

    /** @var array<string,string> fehlerhafte Eingaben aus dem letzten Speichern */
    private array $rawInput = [];

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
        // Fehlerhafte Eingaben wieder im Feld zeigen, damit sie korrigiert werden können.
        $rawInput = get_transient('bp_price_input_'.get_current_user_id());
        delete_transient('bp_price_input_'.get_current_user_id());
        $this->rawInput = is_array($rawInput) ? $rawInput : [];
        $skippedReport = get_transient(self::SKIPPED_TRANSIENT.get_current_user_id());
        delete_transient(self::SKIPPED_TRANSIENT.get_current_user_id());
        $woo = function_exists('wc_get_product_id_by_sku');

        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Preise', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        if ($woo && ($taxWarning = SystemStatus::taxWarning()) !== null) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($taxWarning).'</p></div>';
        }
        if (is_array($skippedReport) && $skippedReport !== []) {
            echo '<div class="notice notice-info inline"><p>'.esc_html__('Beim letzten Abgleich nicht überschrieben, weil im Shop von Hand geändert:', 'bootspruefung-learning').'</p><ul style="list-style:disc;margin-left:2em">';
            foreach ($skippedReport as $sku => $fields) {
                echo '<li><code>'.esc_html((string) $sku).'</code>: '.esc_html(implode(', ', array_map([ShopCatalog::class, 'fieldLabel'], (array) $fields))).'</li>';
            }
            echo '</ul><p class="description">'.esc_html__('Um wieder den Katalogstand zu übernehmen, das Feld im Produkt auf den Katalogwert zurücksetzen; danach gleicht der Katalog es wieder ab. Preise: unter dem Preisfeld „beim Abgleich trotzdem … setzen“ anhaken. Zurückgestellte Entwürfe: „trotzdem veröffentlichen“ anhaken.', 'bootspruefung-learning').'</p></div>';
        }
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
            echo '<label><input type="checkbox" name="publish" value="1"> '.esc_html__('Produkte dabei veröffentlichen (neue und vom Katalog angelegte Entwürfe; von Hand zurückgestellte Entwürfe und deaktivierte Kurse bleiben Entwurf)', 'bootspruefung-learning').'</label>';
            if (ShopCatalog::saleConflicts() !== []) {
                echo '<br><label><input type="checkbox" name="drop_sales" value="1"> '.esc_html__('Solche Angebotspreise entfernen (regulärer Preis nicht höher als der Angebotspreis)', 'bootspruefung-learning').'</label>';
            }
        }
        echo '</p></form>';

        if (ShopCatalog::ownPrices() !== []) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
                .wp_nonce_field('bp_shop_prices', '_wpnonce', true, false).'<input type="hidden" name="action" value="bp_shop_prices">'
                .'<button class="button-link-delete" name="op" value="reset" data-bp-confirm="'.esc_attr__('Alle eigenen Preise entfernen und wieder die Katalogpreise verwenden?', 'bootspruefung-learning').'">'.esc_html__('Alle eigenen Preise zurücksetzen', 'bootspruefung-learning').'</button></form>';
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
            .'<input type="text" inputmode="decimal" size="8" id="'.esc_attr($id).'" name="prices['.esc_attr($sku).']" value="'.esc_attr(isset($errors[$sku], $this->rawInput[$sku]) ? (string) $this->rawInput[$sku] : self::de((string) $p['price'])).'"'
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
            if (ShopCatalog::priceChangedByHand($product, $p)) {
                // T98: von Hand im Shop geändert – Abgleich überschreibt nur nach ausdrücklicher Wahl.
                $notes[] = '<span style="color:#996800">'.esc_html(sprintf(__('im Shop von Hand auf %s € geändert – wird beim Abgleich nicht überschrieben', 'bootspruefung-learning'), self::de($shop))).'</span>'
                    .'<br><label><input type="checkbox" name="overwrite_prices[]" value="'.esc_attr($sku).'"> '.esc_html(sprintf(__('beim Abgleich trotzdem auf %s € setzen', 'bootspruefung-learning'), self::de((string) $p['price']))).'</label>';
            } elseif ($shop !== '' && number_format((float) $shop, 2, '.', '') !== $p['price']) {
                $notes[] = '<span style="color:#996800">'.esc_html(sprintf(__('im Shop noch %s € – bitte abgleichen', 'bootspruefung-learning'), self::de($shop))).'</span>';
            }
            if ($product->get_status() !== 'publish' && ShopCatalog::heldBack($product)) {
                $notes[] = '<span>'.esc_html__('Entwurf, von Hand zurückgestellt – „veröffentlichen“ lässt ihn Entwurf', 'bootspruefung-learning').'</span>'
                    .'<br><label><input type="checkbox" name="publish_held[]" value="'.esc_attr($sku).'"> '.esc_html__('beim Veröffentlichen trotzdem veröffentlichen', 'bootspruefung-learning').'</label>';
            }
            $sale = (string) $product->get_sale_price('edit');
            if ($product->is_on_sale('edit')) {
                $notes[] = '<span style="color:#996800">'.esc_html(sprintf(__('Angebotspreis im Shop aktiv: %s €', 'bootspruefung-learning'), self::de($sale))).'</span>';
            }
            if ($sale !== '' && (float) $p['price'] <= (float) $sale) {
                $notes[] = '<span style="color:#d63638">'.esc_html(sprintf(__('Preis %1$s € ist nicht höher als der Angebotspreis %2$s € – WooCommerce entfernt den Angebotspreis beim Abgleich. Angebotspreis im Produkt anpassen oder das Entfernen beim Abgleich bestätigen.', 'bootspruefung-learning'), self::de((string) $p['price']), self::de($sale))).'</span>';
            }
        } elseif ($woo) {
            $notes[] = esc_html__('noch nicht im Shop', 'bootspruefung-learning');
        }

        return $html.'<br><small>'.implode('<br>', $notes).'</small>';
    }

    /**
     * Hinweistext zu Feldern, die der Abgleich wegen Handänderung übersprungen hat; merkt die
     * Einzelheiten für die Preis-Seite vor.
     * @param list<array{sku:string,skipped?:list<string>}> $rows
     */
    public static function skippedMessage(array $rows): string
    {
        $skipped = array_filter(array_map(static fn ($r) => $r['skipped'] ?? [], array_column($rows, null, 'sku')));
        if ($skipped === []) {
            return '';
        }
        set_transient(self::SKIPPED_TRANSIENT.get_current_user_id(), $skipped, 600);

        return ' '.sprintf(_n('Bei %d Produkt wurden von Hand geänderte Felder nicht überschrieben (Einzelheiten unter „Preise“).', 'Bei %d Produkten wurden von Hand geänderte Felder nicht überschrieben (Einzelheiten unter „Preise“).', count($skipped), 'bootspruefung-learning'), count($skipped));
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
            set_transient('bp_price_input_'.get_current_user_id(), array_intersect_key($input, $result['errors']), 300);
            $message = sprintf(_n('%d Preis ist ungültig und wurde nicht übernommen (Format z. B. 29,90).', '%d Preise sind ungültig und wurden nicht übernommen (Format z. B. 29,90).', count($result['errors']), 'bootspruefung-learning'), count($result['errors'])).' '.$message;
            $type = 'error';
        }
        $conflicts = ShopCatalog::saleConflicts();
        if ($op === 'apply' && $conflicts !== [] && empty($_POST['drop_sales'])) {
            // WooCommerce löscht beim Speichern einen Angebotspreis, der nicht unter dem regulären Preis liegt.
            $message .= ' '.sprintf(__('Katalog NICHT abgeglichen: Bei %s wäre der neue reguläre Preis nicht höher als der Angebotspreis im Shop – WooCommerce würde den Angebotspreis beim Abgleich entfernen. Bitte Angebotspreis im Produkt anpassen oder den Abgleich mit „Solche Angebotspreise entfernen“ bestätigen.', 'bootspruefung-learning'), implode(', ', array_keys($conflicts)));
            $type = 'warning';
            $op = 'save';
        }
        if ($op === 'apply') {
            try {
                $pick = static fn (string $key): array => array_values(array_filter(array_map('sanitize_text_field', array_map('strval', (array) wp_unslash($_POST[$key] ?? [])))));
                $rows = ShopCatalog::applyProducts(false, ! empty($_POST['publish']), ['overwrite_prices' => $pick('overwrite_prices'), 'publish_held' => $pick('publish_held')]);
                $changed = count(array_filter($rows, static fn ($r) => $r['action'] !== 'unverändert'));
                $message .= ' '.sprintf(__('Katalog abgeglichen: %1$d Produkte, %2$d geändert.', 'bootspruefung-learning'), count($rows), $changed);
                $message .= self::skippedMessage($rows);
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
