<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;

/**
 * Produktfeld „Lernzugang“ (Kap. 7.1/7.6): Kurse × Funktionen, Laufzeit,
 * Verlängerungs- und Erstattungsregel. Variationen erben oder überschreiben.
 */
final class ProductPanel
{
    public function register(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'tab']);
        add_action('woocommerce_product_data_panels', [$this, 'panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save'], 20);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'variationFields'], 20, 3);
        add_action('woocommerce_save_product_variation', [$this, 'saveVariation'], 20, 2);
    }

    /** @param array<string,array<string,mixed>> $tabs */
    public function tab(array $tabs): array
    {
        $tabs['bp_learning_access'] = ['label' => __('Lernzugang', 'bootspruefung-learning'), 'target' => 'bp_learning_access_data', 'class' => [], 'priority' => 75];

        return $tabs;
    }

    public function panel(): void
    {
        global $post;
        $productId = (int) ($post->ID ?? 0);
        echo '<div id="bp_learning_access_data" class="panel woocommerce_options_panel hidden"><div class="options_group" style="padding:0 12px 12px">';
        echo '<p>'.esc_html__('Kurse und Funktionen, die der Kauf dieses Produkts freischaltet. Zugang entsteht erst bei bezahlter Bestellung; Preise und Zahlarten regelt WooCommerce.', 'bootspruefung-learning').'</p>';
        $this->fields('bp_access', $this->repo()->rows($productId, 0));
        $mb = \Bootspruefung\Learning\Commerce\MoneyBack::policy($productId);
        printf(
            '<p><label>%s <input type="number" min="0" step="1" name="bp_access[mb_days]" value="%s" style="width:70px"> %s</label> <label>%s <input type="number" min="0" max="100" step="0.5" name="bp_access[mb_progress]" value="%s" style="width:70px"> %%</label><br><span class="description">%s</span></p>',
            esc_html__('Geld-zurück-Garantie:', 'bootspruefung-learning'),
            esc_attr($mb ? (string) $mb['days'] : ''),
            esc_html__('Tage', 'bootspruefung-learning'),
            esc_html__('solange gelernt unter', 'bootspruefung-learning'),
            esc_attr($mb ? (string) $mb['max_progress'] : ''),
            esc_html__('leer = keine Garantie. Kunden fordern die Erstattung unter „Mein Lernen“ an; du erstattest in der Bestellung.', 'bootspruefung-learning')
        );
        if ($productId > 0 && metadata_exists('post', $productId, \Bootspruefung\Learning\Commerce\ShopCatalog::FIELDS_META)) {
            echo '<p class="description"><strong>'.esc_html__('Produkt aus dem Shop-Katalog:', 'bootspruefung-learning').'</strong> '.esc_html__('Von Hand geänderte Felder (Name, Texte, Kategorien, Sichtbarkeit, Einzelverkauf, Lernzugang, Geld-zurück-Garantie) überschreibt der Katalog-Abgleich nicht mehr. Der reguläre Preis kommt immer von der Seite „Preise“.', 'bootspruefung-learning').'</p>';
        }
        echo '<p class="description">'.esc_html__('Lernprodukte sollten virtuell sein. Sie werden automatisch einzeln verkauft, und der Checkout verlangt ein Kundenkonto.', 'bootspruefung-learning').'</p>';
        echo '</div></div>';
    }

    public function variationFields($loop, $variationData, $variation): void
    {
        $variationId = (int) $variation->ID;
        $rows = $this->repo()->rows((int) $variation->post_parent, $variationId);
        $name = 'bp_var_access['.(int) $loop.']';
        echo '<div class="bp-variation-access" style="clear:both;padding:8px 0"><p><label><input type="checkbox" name="'.esc_attr($name.'[own]').'" value="1" '.checked($rows !== [], true, false).'> '.esc_html__('Eigener Lernzugang für diese Variation (sonst wie Produkt)', 'bootspruefung-learning').'</label></p>';
        $this->fields($name, $rows);
        echo '</div>';
    }

    /** @param list<object> $rows */
    private function fields(string $name, array $rows): void
    {
        $courses = Labels::courses();
        $checked = [];
        foreach ($rows as $row) {
            $checked[$row->course_code.'|'.$row->feature_code] = true;
        }
        $first = $rows[0] ?? null;
        echo '<input type="hidden" name="'.esc_attr($name.'[present]').'" value="1">';
        if ($courses === []) {
            echo '<p><em>'.esc_html__('Noch keine Kurse veröffentlicht – bitte zuerst ein Content-Paket importieren.', 'bootspruefung-learning').'</em></p>';

            return;
        }
        echo '<table class="widefat striped" style="margin:8px 0"><thead><tr><th>'.esc_html__('Kurs', 'bootspruefung-learning').'</th>';
        foreach (ProductAccessRepository::features() as $feature) {
            echo '<th style="font-size:11px">'.esc_html(Labels::feature($feature)).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($courses as $code => $courseName) {
            echo '<tr><th scope="row">'.esc_html($courseName).'</th>';
            foreach (ProductAccessRepository::features() as $feature) {
                printf(
                    '<td><input type="checkbox" name="%s" value="1" aria-label="%s" %s></td>',
                    esc_attr($name.'[scope]['.$code.']['.$feature.']'),
                    esc_attr($courseName.': '.Labels::feature($feature)),
                    checked(isset($checked[$code.'|'.$feature]), true, false)
                );
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        // T101: neue Zuordnungen mit Laufzeit vorbelegen; „ohne Ablauf“ nur ausdrücklich. Gespeicherte Werte bleiben.
        $unlimited = $first !== null && empty($first->duration_days);
        $days = $first !== null && ! empty($first->duration_days) ? (int) $first->duration_days : ($unlimited ? 0 : $this->defaultDays());
        $fieldId = 'bp-duration-'.sanitize_html_class(str_replace(['[', ']'], '-', $name));
        printf(
            '<p><label for="%1$s">%2$s</label> <input type="number" id="%1$s" min="1" step="1" name="%3$s" value="%4$s" style="width:90px"> <label><input type="checkbox" name="%5$s" value="1" %6$s> %7$s</label><br><span class="description">%8$s</span></p>',
            esc_attr($fieldId),
            esc_html__('Laufzeit in Tagen', 'bootspruefung-learning'),
            esc_attr($name.'[duration]'),
            esc_attr($days > 0 ? (string) $days : ''),
            esc_attr($name.'[unlimited]'),
            checked($unlimited, true, false),
            esc_html__('ohne Ablauf (dauerhaft)', 'bootspruefung-learning'),
            esc_html(sprintf(
                /* translators: %d: vorbelegte Laufzeit in Tagen */
                __('Gilt ab Zahlungseingang. Vorbelegt: %d Tage (Laufzeit aus dem Shop-Katalog bzw. 12 Monate). Einen Zugang ohne Ablauf bitte nur bewusst wählen – er erscheint in der Übersicht unter „Produktzuordnungen prüfen“.', 'bootspruefung-learning'),
                $this->defaultDays()
            ))
        );
        $renewal = ['extend' => __('Zweitkauf verlängert (Beginn am Ende des bestehenden Zugangs)', 'bootspruefung-learning'), 'parallel' => __('Zweitkauf läuft parallel ab Kaufdatum', 'bootspruefung-learning')];
        $refund = [
            'revoke_on_full_refund' => __('Widerruf, wenn die Position vollständig erstattet ist (Standard)', 'bootspruefung-learning'),
            'revoke_on_any_refund' => __('Widerruf bei jeder Erstattung der Position', 'bootspruefung-learning'),
            'keep' => __('Zugang bleibt bei Erstattung bestehen', 'bootspruefung-learning'),
        ];
        printf(
            '<p><label>%s <input type="number" min="0" step="1" name="%s" value="%s" style="width:90px"></label> <span class="description">%s</span></p>',
            esc_html__('Gruppenlizenz: Plätze je Stück', 'bootspruefung-learning'),
            esc_attr($name.'[seats]'),
            esc_attr($first && ! empty($first->seats) ? (string) $first->seats : ''),
            esc_html__('leer = Einzelzugang für den Käufer; sonst erhält der Käufer ein Kontingent und lädt Teilnehmende ein (Menge = Anzahl Pakete)', 'bootspruefung-learning')
        );
        $this->select($name.'[renewal]', __('Verlängerung', 'bootspruefung-learning'), $renewal, $first->renewal_mode ?? 'extend');
        $this->select($name.'[refund]', __('Erstattung', 'bootspruefung-learning'), $refund, $first->refund_rule ?? 'revoke_on_full_refund');
    }

    /** @param array<string,string> $options */
    private function select(string $name, string $label, array $options, string $current): void
    {
        echo '<p><label>'.esc_html($label).' <select name="'.esc_attr($name).'">';
        foreach ($options as $value => $text) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($text));
        }
        echo '</select></label></p>';
    }

    public function save($postId): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft woocommerce_meta_nonce vor diesem Hook.
        $input = isset($_POST['bp_access']) && is_array($_POST['bp_access']) ? wp_unslash($_POST['bp_access']) : null;
        if ($input === null || empty($input['present']) || ! current_user_can('edit_product', (int) $postId)) {
            return;
        }
        $this->persist((int) $postId, 0, $input);
        $days = isset($input['mb_days']) && $input['mb_days'] !== '' ? absint($input['mb_days']) : null;
        \Bootspruefung\Learning\Commerce\MoneyBack::setPolicy((int) $postId, $days, isset($input['mb_progress']) ? (float) str_replace(',', '.', (string) $input['mb_progress']) : 0.0);
    }

    public function saveVariation($variationId, $loop): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft die Nonce beim Speichern der Variationen.
        $all = isset($_POST['bp_var_access']) && is_array($_POST['bp_var_access']) ? wp_unslash($_POST['bp_var_access']) : [];
        $input = $all[$loop] ?? null;
        $variation = wc_get_product((int) $variationId);
        if (! is_array($input) || empty($input['present']) || ! $variation || ! current_user_can('edit_product', (int) $variation->get_parent_id())) {
            return;
        }
        if (empty($input['own'])) {
            $input['scope'] = [];
        }
        $this->persist((int) $variation->get_parent_id(), (int) $variationId, $input);
    }

    /** @param array<string,mixed> $input */
    private function persist(int $productId, int $variationId, array $input): void
    {
        $courses = Labels::courses();
        $features = ProductAccessRepository::features();
        $scopes = [];
        foreach ((array) ($input['scope'] ?? []) as $course => $selected) {
            if (! isset($courses[$course]) || ! is_array($selected)) {
                continue;
            }
            foreach (array_keys($selected) as $feature) {
                if (in_array($feature, $features, true)) {
                    $scopes[] = ['course_code' => (string) $course, 'feature_code' => (string) $feature];
                }
            }
        }
        $duration = self::duration($input, $this->defaultDays($productId));
        $seats = isset($input['seats']) && $input['seats'] !== '' ? absint($input['seats']) : null;
        $result = $this->repo()->save($productId, $variationId, $scopes, $duration, sanitize_key((string) ($input['renewal'] ?? '')), sanitize_key((string) ($input['refund'] ?? '')), $seats);
        if ($result['added'] + $result['removed'] > 0) {
            AuditLog::record('product_access_saved', 'product', (string) ($variationId ?: $productId), null, ['product_id' => $productId, 'variation_id' => $variationId, 'scopes' => count($scopes)] + $result);
        }
    }

    /**
     * Laufzeit aus dem Formular (T101): „ohne Ablauf“ angekreuzt = null (dauerhaft); sonst die eingetragenen Tage,
     * leer oder 0 = Vorbelegung. Dauerhaft entsteht so nur ausdrücklich.
     * @param array<string,mixed> $input
     */
    public static function duration(array $input, int $default): ?int
    {
        if (! empty($input['unlimited'])) {
            return null;
        }
        $days = isset($input['duration']) && $input['duration'] !== '' ? absint($input['duration']) : 0;

        return $days > 0 ? $days : max(1, $default);
    }

    /** Vorbelegte Laufzeit: Katalog-Laufzeit der SKU des Produkts, sonst 365 Tage. */
    private function defaultDays(int $productId = 0): int
    {
        if ($productId <= 0) {
            global $post;
            $productId = (int) ($post->ID ?? 0);
        }
        $product = $productId > 0 && function_exists('wc_get_product') ? wc_get_product($productId) : null;

        return \Bootspruefung\Learning\Commerce\DefaultDuration::forSku($product ? (string) $product->get_sku() : '');
    }

    private function repo(): ProductAccessRepository
    {
        global $wpdb;

        return new ProductAccessRepository($wpdb);
    }
}
