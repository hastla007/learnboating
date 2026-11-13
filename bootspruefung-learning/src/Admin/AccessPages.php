<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Security\Capabilities;

/**
 * Adminbereiche „Zugänge“ (Suche, manuelle Freigabe/Verlängerung/Aussetzung/
 * Widerruf mit Begründung, Kap. 7.6) und „Kostenlose Bereiche“ (bp_access_rule, Kap. 7.2).
 */
final class AccessPages
{
    public const SLUG = 'bootspruefung-access';
    public const FREE_SLUG = 'bootspruefung-free';
    public const GROUP_SLUG = 'bootspruefung-groups';
    public const DISCOUNT_SLUG = 'bootspruefung-discounts';

    public function register(): void
    {
        add_action('admin_post_bp_access_grant', [$this, 'handleGrant']);
        add_action('admin_post_bp_access_change', [$this, 'handleChange']);
        add_action('admin_post_bp_free_rule', [$this, 'handleFreeRule']);
        add_action('admin_post_bp_group_admin', [$this, 'handleGroupAdmin']);
        add_action('admin_post_bp_member_discount', [$this, 'handleDiscount']);
        add_action('admin_post_bp_shop_catalog', [$this, 'handleCatalog']);
    }

    private function service(): EntitlementService
    {
        global $wpdb;

        return new EntitlementService($wpdb);
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        // phpcs:disable WordPress.Security.NonceVerification -- reine Filteranzeige
        $filter = [
            'search' => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'course' => sanitize_text_field(wp_unslash($_GET['course'] ?? '')),
            'status' => sanitize_key(wp_unslash($_GET['status'] ?? '')),
            'source' => sanitize_key(wp_unslash($_GET['source'] ?? '')),
            'order' => absint($_GET['order'] ?? 0),
        ];
        $page = max(1, absint($_GET['paged'] ?? 1));
        $detail = absint($_GET['entitlement'] ?? 0);
        // phpcs:enable
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Zugänge', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        $this->shopNotice();
        if ($detail > 0) {
            $this->renderDetail($detail);
            echo '</div>';

            return;
        }

        echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">';
        printf('<input type="search" name="s" value="%s" placeholder="%s"> ', esc_attr($filter['search']), esc_attr__('E-Mail, Name, Benutzer-ID, SKU', 'bootspruefung-learning'));
        $this->selectField('course', ['' => __('alle Kurse', 'bootspruefung-learning')] + Labels::courses(), $filter['course']);
        $statuses = ['' => __('alle Status', 'bootspruefung-learning')];
        foreach (EntitlementPlanner::STATUSES as $s) {
            $statuses[$s] = Labels::status($s);
        }
        $this->selectField('status', $statuses, $filter['status']);
        $this->selectField('source', ['' => __('alle Quellen', 'bootspruefung-learning'), 'woocommerce' => 'WooCommerce', 'manual' => __('manuell', 'bootspruefung-learning'), 'group' => __('Gruppe', 'bootspruefung-learning'), EntitlementService::SOURCE_VOUCHER => __('Kursgutschein', 'bootspruefung-learning')], $filter['source']);
        if ($filter['order'] > 0) {
            printf('<input type="hidden" name="order" value="%d"> <span>%s</span> ', $filter['order'], esc_html(sprintf(__('Bestellung #%d', 'bootspruefung-learning'), $filter['order'])));
        }
        submit_button(__('Filtern', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</form>';

        $result = $this->service()->search($filter, $page);
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                '<a href="'.esc_url(Ui::url(self::SLUG, ['entitlement' => $row->id])).'">#'.(int) $row->id.'</a>',
                esc_html(($row->display_name ?: '–').' <'.($row->user_email ?: '#'.$row->user_id).'>'),
                esc_html(Labels::course($row->course_code)),
                esc_html(Labels::feature($row->feature_code)),
                self::badge($row->status).(isset(EntitlementPlanner::meta($row)['manual']) ? ' <em>'.esc_html__('manuell', 'bootspruefung-learning').'</em>' : ''),
                esc_html(Labels::date($row->valid_until)),
                $this->sourceCell($row),
            ];
        }
        Ui::table(['ID', __('Nutzer', 'bootspruefung-learning'), __('Kurs', 'bootspruefung-learning'), __('Umfang', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('Gültig bis', 'bootspruefung-learning'), __('Quelle', 'bootspruefung-learning')], $rows, __('Keine Zugänge gefunden.', 'bootspruefung-learning'));
        Ui::pager($page, $page * 50 < $result['total'], static fn (int $p) => Ui::url(self::SLUG, array_filter($filter) + ['paged' => $p]));

        $this->renderGrantForm();
        echo '</div>';
    }

    private function shopNotice(): void
    {
        if (\Bootspruefung\Learning\Settings\Settings::get('default_access') === 'logged_in') {
            echo '<div class="notice notice-warning inline"><p>'.esc_html__('Einstellung „Zugang ohne Shop“ steht auf „Alle angemeldeten Nutzer“: Zugänge werden protokolliert, sperren aber noch nichts. Sobald der Shop live ist, unter Einstellungen auf „Nur kostenlose Regeln und Freischaltungen“ umstellen.', 'bootspruefung-learning').'</p></div>';
        }
    }

    private function sourceCell(object $row): string
    {
        if ($row->source === EntitlementService::SOURCE_WOO && $row->order_id) {
            $url = function_exists('wc_get_order') && ($order = wc_get_order((int) $row->order_id)) ? $order->get_edit_order_url() : '';

            return $url !== '' ? '<a href="'.esc_url($url).'">'.esc_html(sprintf(__('Bestellung #%d', 'bootspruefung-learning'), $row->order_id)).'</a>' : esc_html(sprintf(__('Bestellung #%d', 'bootspruefung-learning'), $row->order_id));
        }

        if ($row->source === \Bootspruefung\Learning\Commerce\GroupService::SOURCE && preg_match('/^group:(\d+):/', (string) $row->reference_key, $m)) {
            return '<a href="'.esc_url(Ui::url(self::GROUP_SLUG, ['group' => (int) $m[1]])).'">'.esc_html(sprintf(__('Gruppe #%d', 'bootspruefung-learning'), (int) $m[1])).'</a>';
        }

        if ($row->source === EntitlementService::SOURCE_VOUCHER) {
            $meta = EntitlementPlanner::meta($row);
            $label = sprintf(__('Kursgutschein #%s', 'bootspruefung-learning'), isset($meta['voucher_id']) ? (string) (int) $meta['voucher_id'] : '–');
            /** Link zum Gutschein im Gutschein-Plugin (vom Connector gesetzt), sonst nur Text. */
            $url = (string) apply_filters('bootspruefung_voucher_admin_url', '', $meta, $row);

            return $url !== '' ? '<a href="'.esc_url($url).'">'.esc_html($label).'</a>' : esc_html($label);
        }

        return esc_html__('manuell', 'bootspruefung-learning').($row->granted_by ? ' ('.esc_html((string) (get_userdata((int) $row->granted_by)->display_name ?? '#'.$row->granted_by)).')' : '');
    }

    private function renderDetail(int $id): void
    {
        $row = $this->service()->find($id);
        if ($row === null) {
            echo '<p>'.esc_html__('Zugang nicht gefunden.', 'bootspruefung-learning').'</p>';

            return;
        }
        $user = get_userdata((int) $row->user_id);
        $meta = EntitlementPlanner::meta($row);
        echo '<p><a href="'.esc_url(Ui::url(self::SLUG)).'">&larr; '.esc_html__('alle Zugänge', 'bootspruefung-learning').'</a></p>';
        Ui::keyValue([
            __('Nutzer', 'bootspruefung-learning') => esc_html($user ? $user->display_name.' <'.$user->user_email.'>' : '#'.$row->user_id),
            __('Kurs', 'bootspruefung-learning') => esc_html(Labels::course($row->course_code)),
            __('Umfang', 'bootspruefung-learning') => esc_html(Labels::feature($row->feature_code)),
            __('Status', 'bootspruefung-learning') => self::badge($row->status),
            __('Gültig ab', 'bootspruefung-learning') => esc_html(Ui::date($row->valid_from)),
            __('Gültig bis', 'bootspruefung-learning') => esc_html(Labels::date($row->valid_until)),
            __('Quelle', 'bootspruefung-learning') => $this->sourceCell($row),
            __('Produkt/SKU', 'bootspruefung-learning') => esc_html(trim(($row->product_id ? '#'.$row->product_id.($row->variation_id ? '/'.$row->variation_id : '') : '–').' '.($row->sku_snapshot ?? ''))),
            __('Letzter Grund', 'bootspruefung-learning') => esc_html((string) $row->reason),
            // Supportansicht Kursgutschein: Gutschein, Einlöse-ID, Zeitpunkt und Herkunft der Freischaltung.
            __('Gutschein', 'bootspruefung-learning') => $row->source === EntitlementService::SOURCE_VOUCHER ? esc_html(trim((isset($meta['voucher_id']) ? '#'.(int) $meta['voucher_id'] : '–').' '.(string) ($meta['grant_source'] ?? ''))) : '–',
            __('Einlöse-ID', 'bootspruefung-learning') => $row->source === EntitlementService::SOURCE_VOUCHER ? '<code>'.esc_html((string) ($meta['redemption_key'] ?? '')).'</code>' : '–',
            __('Eingelöst am', 'bootspruefung-learning') => $row->source === EntitlementService::SOURCE_VOUCHER ? esc_html(Ui::date((string) ($meta['redeemed_at'] ?? $row->created_at))) : '–',
            __('Herkunft', 'bootspruefung-learning') => $row->source === EntitlementService::SOURCE_VOUCHER ? esc_html((string) ($meta['source_reference'] ?? '')) : '–',
            __('Manuelle Entscheidung', 'bootspruefung-learning') => isset($meta['manual']) ? esc_html(sprintf('%s – %s (%s)', Labels::status((string) $meta['manual']['status']), (string) $meta['manual']['reason'], Ui::date((string) $meta['manual']['at']))) : '–',
        ]);

        echo '<h2>'.esc_html__('Ändern', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_access_change');
        echo '<input type="hidden" name="action" value="bp_access_change"><input type="hidden" name="id" value="'.(int) $row->id.'">';
        echo '<p><label>'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').'<br><input type="text" name="reason" required class="regular-text"></label></p>';
        echo '<p><label>'.esc_html__('Neues Enddatum', 'bootspruefung-learning').' <input type="date" name="valid_until"></label> <label><input type="checkbox" name="unlimited" value="1"> '.esc_html__('ohne Ablauf (dauerhaft)', 'bootspruefung-learning').'</label> ';
        echo '<button class="button" name="op" value="extend">'.esc_html__('Enddatum setzen', 'bootspruefung-learning').'</button></p><p>';
        foreach (['active' => __('Aktivieren', 'bootspruefung-learning'), 'suspended' => __('Aussetzen', 'bootspruefung-learning'), 'revoked' => __('Widerrufen', 'bootspruefung-learning')] as $op => $label) {
            if ($row->status !== $op) {
                printf('<button class="button" name="op" value="%s"%s>%s</button> ', esc_attr($op), $op === 'revoked' ? ' data-bp-confirm="'.esc_attr__('Zugang wirklich widerrufen?', 'bootspruefung-learning').'"' : '', esc_html($label));
            }
        }
        if (isset($meta['manual']) && $row->source === EntitlementService::SOURCE_WOO) {
            echo '<button class="button" name="op" value="release">'.esc_html__('Manuelle Entscheidung aufheben (Shop-Status gilt)', 'bootspruefung-learning').'</button>';
        }
        echo '</p></form>';
        // T102: Verlauf dieses Zugangs aus dem Protokoll.
        echo '<h2>'.esc_html__('Verlauf', 'bootspruefung-learning').'</h2>';
        AuditPage::historyBox(AuditPage::forEntitlement($row), 'entitlement', (int) $row->id);
    }

    private function renderGrantForm(): void
    {
        $courses = Labels::courses();
        echo '<h2>'.esc_html__('Zugang manuell freigeben', 'bootspruefung-learning').'</h2>';
        if ($courses === []) {
            echo '<p>'.esc_html__('Noch keine Kurse veröffentlicht.', 'bootspruefung-learning').'</p>';

            return;
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_access_grant');
        echo '<input type="hidden" name="action" value="bp_access_grant"><table class="form-table"><tbody>';
        echo '<tr><th><label for="bp-grant-user">'.esc_html__('Nutzer (E-Mail, Login oder ID)', 'bootspruefung-learning').'</label></th><td><input id="bp-grant-user" type="text" name="user" required class="regular-text"></td></tr>';
        // T101: Laufzeit je Kurs aus dem Shop-Katalog (sonst 182 Tage) vorbelegen; „ohne Ablauf“ nur ausdrücklich.
        $days = \Bootspruefung\Learning\Commerce\DefaultDuration::forCourses(array_keys($courses));
        echo '<tr><th><label for="bp-grant-course">'.esc_html__('Kurs', 'bootspruefung-learning').'</label></th><td><select id="bp-grant-course" name="course">';
        foreach ($courses as $code => $name) {
            printf('<option value="%s" data-bp-until="%s">%s</option>', esc_attr($code), esc_attr(self::defaultUntil($days[$code])), esc_html($name));
        }
        echo '</select></td></tr><tr><th>'.esc_html__('Umfang', 'bootspruefung-learning').'</th><td>';
        foreach (ProductAccessRepository::features() as $feature) {
            printf('<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="features[]" value="%s"> %s</label>', esc_attr($feature), esc_html(Labels::feature($feature)));
        }
        $firstDays = (int) reset($days);
        echo '</td></tr><tr><th><label for="bp-grant-until">'.esc_html__('Gültig bis', 'bootspruefung-learning').'</label></th><td><input id="bp-grant-until" type="date" name="valid_until" value="'.esc_attr(self::defaultUntil($firstDays)).'"> ';
        echo '<label><input type="checkbox" name="unlimited" value="1"> '.esc_html__('ohne Ablauf (dauerhaft)', 'bootspruefung-learning').'</label>';
        echo '<p class="description">'.esc_html__('Vorbelegt mit der Laufzeit des Kurses im Shop (Vollkurse 12 Monate, SKN/FKN und Ergänzungen 6 Monate). Ohne Ablauf nur bewusst wählen.', 'bootspruefung-learning').'</p></td></tr>';
        echo '<tr><th><label for="bp-grant-reason">'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').'</label></th><td><input id="bp-grant-reason" type="text" name="reason" required class="regular-text"></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Zugang freigeben', 'bootspruefung-learning'));
        echo '</form>';
        // Kurswechsel: Enddatum auf die Laufzeit des Kurses setzen, solange es nicht von Hand geändert wurde (ohne JS bleibt die Vorbelegung des ersten Kurses).
        echo "<script>(function(){var c=document.getElementById('bp-grant-course'),u=document.getElementById('bp-grant-until');if(!c||!u){return;}var auto=u.value;u.addEventListener('input',function(){auto=null;});c.addEventListener('change',function(){var o=c.options[c.selectedIndex];if(auto!==null&&o){u.value=o.getAttribute('data-bp-until')||u.value;auto=u.value;}});})();</script>"; // phpcs:ignore
    }

    /** Vorbelegtes Enddatum (Y-m-d, Ortszeit) für eine Laufzeit in Tagen ab heute. */
    public static function defaultUntil(int $days): string
    {
        return wp_date('Y-m-d', time() + max(1, $days) * DAY_IN_SECONDS);
    }

    public function handleGrant(): void
    {
        $this->guard('bp_access_grant');
        $user = $this->findUser(sanitize_text_field(wp_unslash($_POST['user'] ?? '')));
        $features = array_values(array_intersect(array_map('sanitize_key', (array) wp_unslash($_POST['features'] ?? [])), ProductAccessRepository::features()));
        $course = sanitize_text_field(wp_unslash($_POST['course'] ?? ''));
        try {
            if ($user === null) {
                throw new \InvalidArgumentException(__('Benutzer nicht gefunden.', 'bootspruefung-learning'));
            }
            if ($features === [] || ! isset(Labels::courses()[$course])) {
                throw new \InvalidArgumentException(__('Bitte Kurs und mindestens eine Funktion wählen.', 'bootspruefung-learning'));
            }
            $until = self::endOfDay(sanitize_text_field(wp_unslash($_POST['valid_until'] ?? '')));
            if (empty($_POST['unlimited']) && $until === null) {
                // T101: „dauerhaft“ nur ausdrücklich – ein leeres Datum ergibt keinen unbefristeten Zugang mehr.
                throw new \InvalidArgumentException(__('Bitte ein Enddatum wählen oder „ohne Ablauf“ ankreuzen.', 'bootspruefung-learning'));
            }
            $ids = $this->service()->grantManual((int) $user->ID, $course, $features, empty($_POST['unlimited']) ? $until : null, sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
            Ui::flash(sprintf(_n('%d Zugang freigegeben.', '%d Zugänge freigegeben.', count($ids), 'bootspruefung-learning'), count($ids)));
        } catch (\InvalidArgumentException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect(Ui::url(self::SLUG));
        exit;
    }

    public function handleChange(): void
    {
        $this->guard('bp_access_change');
        $id = absint($_POST['id'] ?? 0);
        $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
        $service = $this->service();
        try {
            $row = $service->find($id);
            match ($op) {
                'extend' => $service->extend($id, ! empty($_POST['unlimited']) ? null : (self::endOfDay(sanitize_text_field(wp_unslash($_POST['valid_until'] ?? ''))) ?? throw new \InvalidArgumentException(__('Bitte ein Enddatum wählen oder „ohne Ablauf“ ankreuzen.', 'bootspruefung-learning'))), $reason),
                'active', 'suspended', 'revoked' => $service->setStatusManual($id, $op, $reason),
                'release' => $this->release($id, $reason, $row),
                default => throw new \InvalidArgumentException('Unbekannte Aktion.'),
            };
            Ui::flash(__('Zugang geändert.', 'bootspruefung-learning'));
        } catch (\InvalidArgumentException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect(Ui::url(self::SLUG, ['entitlement' => $id]));
        exit;
    }

    private function release(int $id, string $reason, ?object $row): void
    {
        $this->service()->releaseManual($id, $reason);
        if ($row !== null && $row->order_id && Integration::active()) {
            Integration::sync()->sync((int) $row->order_id, 'manual_release');
        }
    }

    // ------------------------------------------------------------------ Kostenlose Bereiche

    public function renderFree(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        global $wpdb;
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Kostenlose Bereiche und Freigaben', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        echo '<p>'.esc_html__('Kostenlose Bereiche gelten für alle angemeldeten Nutzer ohne Kauf (Kap. 7.2) – ganz oder als Teilumfang. Zeitgesteuerte Freigaben geben ein Modul bzw. Videokapitel erst n Tage nach Beginn des Zugangs frei.', 'bootspruefung-learning').'</p>';
        $this->shopNotice();
        $this->catalogBox();
        $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bp_access_rule WHERE access_type IN ('free','free_limited','drip') ORDER BY access_type, course_code, feature_code, module_code");
        $modules = $this->moduleOptions();
        $rows = [];
        foreach ($rules as $rule) {
            $delete = '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.wp_nonce_field('bp_free_rule', '_wpnonce', true, false)
                .'<input type="hidden" name="action" value="bp_free_rule"><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="'.(int) $rule->id.'">'
                .'<button class="button-link-delete">'.esc_html__('entfernen', 'bootspruefung-learning').'</button></form>';
            $scope = match ($rule->access_type) {
                'free' => __('ganz kostenlos', 'bootspruefung-learning'),
                'free_limited' => self::limitLabel((string) $rule->feature_code, (int) $rule->limit_value),
                default => sprintf(__('%1$s: %2$d Tage nach Beginn', 'bootspruefung-learning'), $modules[$rule->course_code.'|'.$rule->feature_code.'|'.$rule->module_code] ?? $rule->module_code, (int) $rule->limit_value),
            };
            $rows[] = [esc_html(Labels::course($rule->course_code)), esc_html(Labels::feature($rule->feature_code)), esc_html($scope), esc_html($rule->valid_until ? Ui::date($rule->valid_until) : __('unbegrenzt', 'bootspruefung-learning')), $delete];
        }
        Ui::table([__('Kurs', 'bootspruefung-learning'), __('Funktion', 'bootspruefung-learning'), __('Umfang', 'bootspruefung-learning'), __('bis', 'bootspruefung-learning'), ''], $rows, __('Keine Regeln festgelegt.', 'bootspruefung-learning'));

        $courses = Labels::courses();
        if ($courses === []) {
            echo '</div>';

            return;
        }
        $features = [];
        foreach (\Bootspruefung\Learning\Learning\Services\AccessService::FEATURES as $f) {
            $features[$f] = Labels::feature($f);
        }
        echo '<h2>'.esc_html__('Kostenlosen Bereich hinzufügen', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_free_rule');
        echo '<input type="hidden" name="action" value="bp_free_rule"><input type="hidden" name="op" value="add"><p>';
        $this->selectField('course', $courses, '');
        $this->selectField('feature', $features, '');
        echo ' <label>'.esc_html__('Teilumfang', 'bootspruefung-learning').' <input type="number" min="1" name="limit" style="width:70px"></label> ';
        echo '<span class="description">'.esc_html__('leer = ganz; sonst: Fragen je Modul, Prüfungsbögen bzw. erste Lektionen/Aufgaben/Übungen', 'bootspruefung-learning').'</span><br>';
        echo '<label>'.esc_html__('bis', 'bootspruefung-learning').' <input type="date" name="valid_until"></label> ';
        echo '<input type="text" name="reason" required placeholder="'.esc_attr__('Begründung', 'bootspruefung-learning').'"> ';
        submit_button(__('Hinzufügen', 'bootspruefung-learning'), 'primary', '', false);
        echo '</p></form>';

        echo '<h2>'.esc_html__('Zeitgesteuerte Freigabe hinzufügen', 'bootspruefung-learning').'</h2>';
        if ($modules === []) {
            echo '<p>'.esc_html__('Keine Module vorhanden.', 'bootspruefung-learning').'</p></div>';

            return;
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_free_rule');
        echo '<input type="hidden" name="action" value="bp_free_rule"><input type="hidden" name="op" value="drip"><p><select name="target">';
        foreach ($modules as $value => $label) {
            [$course] = explode('|', $value);
            printf('<option value="%s">%s</option>', esc_attr($value), esc_html(Labels::course($course).' › '.$label));
        }
        echo '</select> <label>'.esc_html__('freigeben nach', 'bootspruefung-learning').' <input type="number" min="1" name="days" required style="width:70px"> '.esc_html__('Tagen', 'bootspruefung-learning').'</label> ';
        echo '<input type="text" name="reason" required placeholder="'.esc_attr__('Begründung', 'bootspruefung-learning').'"> ';
        submit_button(__('Hinzufügen', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</p><p class="description">'.esc_html__('Beginn = frühester aktiver Zugang zu Kurs und Funktion; ohne Kauf (kostenlos) das Registrierungsdatum.', 'bootspruefung-learning').'</p></form></div>';
    }

    /** @return array<string,string> "Kurs|Feature|Modulcode" => Bezeichnung */
    private function moduleOptions(): array
    {
        global $wpdb;
        $content = new \Bootspruefung\Learning\Content\Repositories\ContentRepository();
        $out = [];
        foreach ($content->courses() as $course) {
            foreach ($content->modules((int) $course->id) as $m) {
                $out[$course->code.'|question_bank|'.$m->code] = __('Fragen', 'bootspruefung-learning').': '.$m->name;
            }
            foreach ($wpdb->get_results($wpdb->prepare("SELECT source_id, title FROM {$wpdb->prefix}bp_video_module WHERE course_id = %d AND status = 'published' ORDER BY sort_order", $course->id)) as $v) {
                $out[$course->code.'|video_course|'.$v->source_id] = __('Video', 'bootspruefung-learning').': '.$v->title;
            }
        }

        return $out;
    }

    private static function limitLabel(string $feature, int $n): string
    {
        return match ($feature) {
            'question_bank' => sprintf(__('erste %d Fragen je Modul', 'bootspruefung-learning'), $n),
            'exam_simulation' => sprintf(__('erste %d Prüfungsbögen (ohne Zufallsprüfung)', 'bootspruefung-learning'), $n),
            'radio_training' => sprintf(__('erste %d Übungen je Übungsart', 'bootspruefung-learning'), $n),
            default => sprintf(__('erste %d Einträge', 'bootspruefung-learning'), $n),
        };
    }

    /** Knöpfe für Schnupper-Paket und Produktkatalog (Festlegung 06.11.2026). */
    private function catalogBox(): void
    {
        $woo = function_exists('wc_get_product_id_by_sku');
        echo '<div class="card" style="max-width:900px"><h2>'.esc_html__('Festgelegter Katalog', 'bootspruefung-learning').'</h2><p>'
            .esc_html__('Schnupper-Paket je Kurs: 10 Fragen pro Modul, 1 Prüfungsbogen, erste Video-Lektion, erste Navigations- und Praxisaufgabe. Produktkatalog: alle Produkte mit Preisen (änderbar unter „Preise“), Laufzeit (12 bzw. 6 Monate, Pässe 30/90 Tage) und Geld-zurück-Garantie (14 Tage, unter 20 % gelernt). Mit Produktbildern, Kategorien und Texten aus den Kursinhalten (von Hand geänderte Texte/Bilder bleiben erhalten). Wiederholbar.', 'bootspruefung-learning').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('bp_shop_catalog', '_wpnonce', true, false).'<input type="hidden" name="action" value="bp_shop_catalog">';
        echo '<button class="button" name="op" value="free">'.esc_html__('Schnupper-Paket anlegen', 'bootspruefung-learning').'</button> ';
        if ($woo) {
            echo '<button class="button" name="op" value="products">'.esc_html__('Produktkatalog anlegen/abgleichen', 'bootspruefung-learning').'</button> ';
            echo '<label><input type="checkbox" name="publish" value="1"> '.esc_html__('Produkte veröffentlichen (sonst Entwurf)', 'bootspruefung-learning').'</label>';
        }
        echo '</form></div>';
    }

    public function handleCatalog(): void
    {
        $this->guard('bp_shop_catalog');
        $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        try {
            if ($op === 'products' && ($conflicts = \Bootspruefung\Learning\Commerce\ShopCatalog::saleConflicts()) !== []) {
                // WooCommerce würde diese Angebotspreise beim Abgleich entfernen.
                Ui::flash(sprintf(__('Katalog nicht abgeglichen: Bei %s ist der reguläre Preis nicht höher als der Angebotspreis im Shop. Bitte unter „Preise“ abgleichen (dort lässt sich das Entfernen bestätigen) oder den Angebotspreis anpassen.', 'bootspruefung-learning'), implode(', ', array_keys($conflicts))), 'warning');
            } elseif ($op === 'products') {
                $publish = ! empty($_POST['publish']);
                $rows = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts(false, $publish);
                $new = count(array_filter($rows, static fn ($r) => $r['action'] === 'neu'));
                Ui::flash(sprintf(__('Produktkatalog abgeglichen: %1$d Produkte, davon %2$d neu – %3$s.', 'bootspruefung-learning'), count($rows), $new, $publish ? __('veröffentlicht', 'bootspruefung-learning') : __('neue als Entwurf', 'bootspruefung-learning')).PricePage::skippedMessage($rows));
            } else {
                $r = \Bootspruefung\Learning\Commerce\ShopCatalog::applyFreeTier();
                Ui::flash(sprintf(__('Schnupper-Paket: %d Regel(n) angelegt.', 'bootspruefung-learning'), $r['added']));
            }
        } catch (\RuntimeException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect(Ui::url(self::FREE_SLUG));
        exit;
    }

    public function handleFreeRule(): void
    {
        $this->guard('bp_free_rule');
        global $wpdb;
        $table = $wpdb->prefix.'bp_access_rule';
        $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        $reason = trim(sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
        $now = current_time('mysql', true);
        if ($op === 'delete') {
            $id = absint($_POST['id'] ?? 0);
            $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
            if ($rule !== null) {
                $wpdb->delete($table, ['id' => $id]);
                AuditLog::record('free_rule_deleted', 'access_rule', (string) $id, null, ['course' => $rule->course_code, 'feature' => $rule->feature_code, 'type' => $rule->access_type, 'module' => $rule->module_code]);
                Ui::flash(__('Regel entfernt.', 'bootspruefung-learning'));
            }
        } elseif ($op === 'drip') {
            $target = sanitize_text_field(wp_unslash($_POST['target'] ?? ''));
            $days = absint($_POST['days'] ?? 0);
            if (! isset($this->moduleOptions()[$target]) || $days < 1 || $reason === '') {
                Ui::flash(__('Bitte Modul, Tage und Begründung angeben.', 'bootspruefung-learning'), 'error');
            } else {
                [$course, $feature, $module] = explode('|', $target, 3);
                $wpdb->delete($table, ['course_code' => $course, 'feature_code' => $feature, 'module_code' => $module, 'access_type' => 'drip']);
                $wpdb->insert($table, ['course_code' => $course, 'feature_code' => $feature, 'module_code' => $module, 'access_type' => 'drip', 'limit_type' => 'days_after_start', 'limit_value' => $days, 'created_at' => $now, 'updated_at' => $now]);
                AuditLog::record('drip_rule_added', 'access_rule', (string) $wpdb->insert_id, $reason, ['course' => $course, 'feature' => $feature, 'module' => $module, 'days' => $days]);
                Ui::flash(__('Zeitgesteuerte Freigabe hinzugefügt.', 'bootspruefung-learning'));
            }
        } else {
            $course = sanitize_text_field(wp_unslash($_POST['course'] ?? ''));
            $feature = sanitize_key(wp_unslash($_POST['feature'] ?? ''));
            $limit = isset($_POST['limit']) && $_POST['limit'] !== '' ? absint($_POST['limit']) : null;
            $limitType = \Bootspruefung\Learning\Learning\Services\AccessService::LIMIT_TYPES[$feature] ?? null;
            if (! isset(Labels::courses()[$course]) || ! in_array($feature, \Bootspruefung\Learning\Learning\Services\AccessService::FEATURES, true) || $reason === '') {
                Ui::flash(__('Bitte Kurs, Funktion und Begründung angeben.', 'bootspruefung-learning'), 'error');
            } elseif ($limit !== null && ($limit < 1 || $limitType === null)) {
                Ui::flash(__('Für diese Funktion gibt es keinen Teilumfang.', 'bootspruefung-learning'), 'error');
            } else {
                $wpdb->insert($table, ['course_code' => $course, 'feature_code' => $feature, 'access_type' => $limit === null ? 'free' : 'free_limited',
                    'limit_type' => $limit === null ? null : $limitType, 'limit_value' => $limit, 'valid_from' => $now,
                    'valid_until' => self::endOfDay(sanitize_text_field(wp_unslash($_POST['valid_until'] ?? ''))), 'created_at' => $now, 'updated_at' => $now]);
                AuditLog::record('free_rule_added', 'access_rule', (string) $wpdb->insert_id, $reason, ['course' => $course, 'feature' => $feature, 'limit' => $limit]);
                Ui::flash(__('Kostenloser Bereich hinzugefügt.', 'bootspruefung-learning'));
            }
        }
        wp_safe_redirect(Ui::url(self::FREE_SLUG));
        exit;
    }

    // ------------------------------------------------------------------ Gruppenlizenzen

    public function renderGroups(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        global $wpdb;
        $service = new \Bootspruefung\Learning\Commerce\GroupService($wpdb);
        // phpcs:disable WordPress.Security.NonceVerification -- Anzeige
        $id = absint($_GET['group'] ?? 0);
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $page = max(1, absint($_GET['paged'] ?? 1));
        // phpcs:enable
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Gruppenlizenzen', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        $group = $id ? $service->find($id) : null;
        if ($group !== null) {
            $owner = get_userdata((int) $group->owner_user_id);
            echo '<p><a href="'.esc_url(Ui::url(self::GROUP_SLUG)).'">&larr; '.esc_html__('alle Gruppen', 'bootspruefung-learning').'</a></p>';
            Ui::keyValue([
                __('Name', 'bootspruefung-learning') => esc_html($group->name),
                __('Verwaltet von', 'bootspruefung-learning') => esc_html($owner ? $owner->display_name.' <'.$owner->user_email.'>' : '#'.$group->owner_user_id),
                __('Status', 'bootspruefung-learning') => self::badge($group->status),
                __('Plätze', 'bootspruefung-learning') => esc_html(sprintf(__('%1$d von %2$d belegt', 'bootspruefung-learning'), (int) $group->seats - $service->freeSeats($group), (int) $group->seats)),
                __('Umfang', 'bootspruefung-learning') => esc_html(implode(', ', array_map(static fn ($s) => Labels::course($s['course']).' · '.Labels::feature($s['feature']), \Bootspruefung\Learning\Commerce\GroupService::scopes($group)))),
                __('Gültig bis', 'bootspruefung-learning') => esc_html(Labels::date($group->valid_until)),
                __('Bestellung', 'bootspruefung-learning') => $group->order_id ? '<a href="'.esc_url(Ui::url(self::SLUG, ['order' => $group->order_id])).'">#'.(int) $group->order_id.'</a>' : '–',
                __('Einladungslink', 'bootspruefung-learning') => '<code>'.esc_html(\Bootspruefung\Learning\Commerce\GroupService::joinUrl((string) $group->join_token)).'</code>',
            ]);
            $rows = [];
            foreach ($service->membersOf((int) $group->id) as $m) {
                $remove = $m->status === 'removed' ? '' : '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.wp_nonce_field('bp_group_admin', '_wpnonce', true, false)
                    .'<input type="hidden" name="action" value="bp_group_admin"><input type="hidden" name="op" value="remove"><input type="hidden" name="group" value="'.(int) $group->id.'"><input type="hidden" name="member" value="'.(int) $m->id.'">'
                    .'<input type="text" name="reason" required placeholder="'.esc_attr__('Begründung', 'bootspruefung-learning').'"> <button class="button-link-delete">'.esc_html__('Platz freigeben', 'bootspruefung-learning').'</button></form>';
                $rows[] = [esc_html(($m->display_name ?: '–').($m->email ? ' <'.$m->email.'>' : '')), esc_html(['active' => __('aktiv', 'bootspruefung-learning'), 'invited' => __('eingeladen', 'bootspruefung-learning'), 'expired' => __('Einladung abgelaufen', 'bootspruefung-learning'), 'removed' => __('entfernt', 'bootspruefung-learning')][$m->status] ?? $m->status), esc_html(Ui::date($m->joined_at ?: $m->invited_at)), $remove];
            }
            // T102: Verlauf der Gruppe aus dem Protokoll.
            AuditPage::historyBox(AuditPage::forGroup((int) $group->id), 'group', (int) $group->id);
            echo '<h2>'.esc_html__('Teilnehmende', 'bootspruefung-learning').'</h2>';
            Ui::table([__('Person', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('seit', 'bootspruefung-learning'), ''], $rows, __('Noch niemand eingeladen.', 'bootspruefung-learning'));
            echo '<h2>'.esc_html__('Gruppe ändern', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bp_group_admin');
            echo '<input type="hidden" name="action" value="bp_group_admin"><input type="hidden" name="op" value="status"><input type="hidden" name="group" value="'.(int) $group->id.'">';
            echo '<p><input type="text" name="reason" required class="regular-text" placeholder="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'"></p><p>';
            foreach (['active' => __('Aktivieren', 'bootspruefung-learning'), 'suspended' => __('Aussetzen', 'bootspruefung-learning'), 'revoked' => __('Beenden (alle Plätze widerrufen)', 'bootspruefung-learning')] as $status => $label) {
                if ($group->status !== $status && $group->status !== 'revoked') {
                    printf('<button class="button" name="status" value="%s"%s>%s</button> ', esc_attr($status), $status === 'revoked' ? ' data-bp-confirm="'.esc_attr__('Gruppe wirklich beenden? Alle Plätze werden widerrufen.', 'bootspruefung-learning').'"' : '', esc_html($label));
                }
            }
            echo '</p></form></div>';

            return;
        }
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::GROUP_SLUG).'"><input type="search" name="s" value="'.esc_attr($search).'" placeholder="'.esc_attr__('Name, E-Mail, Bestellnummer', 'bootspruefung-learning').'"> ';
        submit_button(__('Suchen', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</form>';
        $rows = [];
        foreach ($service->all($page, 50, $search) as $g) {
            $rows[] = ['<a href="'.esc_url(Ui::url(self::GROUP_SLUG, ['group' => $g->id])).'">'.esc_html($g->name).'</a>', esc_html(($g->display_name ?: '').' <'.($g->user_email ?: '#'.$g->owner_user_id).'>'),
                self::badge($g->status), esc_html((int) $g->used.' / '.(int) $g->seats), esc_html(Labels::date($g->valid_until)), $g->order_id ? '#'.(int) $g->order_id : '–'];
        }
        Ui::table([__('Gruppe', 'bootspruefung-learning'), __('Verwaltet von', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('Plätze', 'bootspruefung-learning'), __('Gültig bis', 'bootspruefung-learning'), __('Bestellung', 'bootspruefung-learning')], $rows, __('Keine Gruppenlizenzen.', 'bootspruefung-learning'));
        echo '<p class="description">'.esc_html__('Gruppenlizenzen entstehen aus Produkten mit „Plätzen je Stück“ (Reiter Lernzugang). Die verwaltende Person lädt unter /bootsscheine/gruppen/ ein.', 'bootspruefung-learning').'</p></div>';
    }

    public function handleGroupAdmin(): void
    {
        $this->guard('bp_group_admin');
        global $wpdb;
        $service = new \Bootspruefung\Learning\Commerce\GroupService($wpdb);
        $groupId = absint($_POST['group'] ?? 0);
        $reason = trim(sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
        try {
            if ($reason === '') {
                throw new \InvalidArgumentException(__('Bitte eine Begründung angeben.', 'bootspruefung-learning'));
            }
            if (sanitize_key(wp_unslash($_POST['op'] ?? '')) === 'remove') {
                $service->removeMember($groupId, absint($_POST['member'] ?? 0), get_current_user_id(), $reason);
            } else {
                $service->setStatus($groupId, sanitize_key(wp_unslash($_POST['status'] ?? '')), $reason, true);
            }
            Ui::flash(__('Gruppe geändert.', 'bootspruefung-learning'));
        } catch (\InvalidArgumentException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect(Ui::url(self::GROUP_SLUG, ['group' => $groupId]));
        exit;
    }

    // ------------------------------------------------------------------ Mitgliederrabatte

    public function renderDiscounts(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $md = \Bootspruefung\Learning\Commerce\WooCommerce\MemberDiscounts::class;
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Mitgliederrabatte', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        echo '<p>'.esc_html__('Nutzer mit aktivem Lernzugang (Kauf, manuelle Freigabe oder Gruppenplatz) erhalten im Shop einen Rabatt. Gilt mehr als eine Regel, zählt die höchste. Kostenlose Bereiche zählen nicht.', 'bootspruefung-learning').'</p>';
        $rows = [];
        foreach ($md::rules() as $rule) {
            $delete = '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.wp_nonce_field('bp_member_discount', '_wpnonce', true, false)
                .'<input type="hidden" name="action" value="bp_member_discount"><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="'.esc_attr($rule['id']).'">'
                .'<button class="button-link-delete">'.esc_html__('entfernen', 'bootspruefung-learning').'</button></form>';
            $rows[] = [esc_html($md::courseLabel($rule['course'])), esc_html(wc_format_localized_decimal($rule['percent']).' %'), esc_html($md::appliesLabel($rule['applies'])), esc_html($rule['label']), $delete];
        }
        Ui::table([__('Zugang zu', 'bootspruefung-learning'), __('Rabatt', 'bootspruefung-learning'), __('gilt für', 'bootspruefung-learning'), __('Bezeichnung', 'bootspruefung-learning'), ''], $rows, __('Keine Mitgliederrabatte festgelegt.', 'bootspruefung-learning'));
        echo '<h2>'.esc_html__('Rabatt hinzufügen', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_member_discount');
        echo '<input type="hidden" name="action" value="bp_member_discount"><input type="hidden" name="op" value="add"><p>';
        $this->selectField('course', ['' => __('beliebiger Kurs', 'bootspruefung-learning')] + Labels::courses(), '');
        echo '<label><input type="number" name="percent" min="0.5" max="100" step="0.5" required style="width:80px"> %</label> ';
        $applies = [];
        foreach ($md::APPLIES as $a) {
            $applies[$a] = $md::appliesLabel($a);
        }
        $this->selectField('applies', $applies, 'other');
        echo '<input type="text" name="label" placeholder="'.esc_attr__('Bezeichnung, z. B. Schülerrabatt', 'bootspruefung-learning').'"> ';
        submit_button(__('Hinzufügen', 'bootspruefung-learning'), 'primary', '', false);
        echo '</p></form></div>';
    }

    public function handleDiscount(): void
    {
        $this->guard('bp_member_discount');
        $md = \Bootspruefung\Learning\Commerce\WooCommerce\MemberDiscounts::class;
        try {
            if (sanitize_key(wp_unslash($_POST['op'] ?? '')) === 'delete') {
                $md::remove(sanitize_text_field(wp_unslash($_POST['id'] ?? '')));
                AuditLog::record('member_discount_deleted', 'settings', null);
            } else {
                $course = sanitize_text_field(wp_unslash($_POST['course'] ?? ''));
                if ($course !== '' && ! isset(Labels::courses()[$course])) {
                    throw new \InvalidArgumentException(__('Unbekannter Kurs.', 'bootspruefung-learning'));
                }
                $percent = (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['percent'] ?? '0')));
                $md::add($course, $percent, sanitize_key(wp_unslash($_POST['applies'] ?? '')), sanitize_text_field(wp_unslash($_POST['label'] ?? '')));
                AuditLog::record('member_discount_added', 'settings', null, null, ['course' => $course, 'percent' => $percent]);
            }
            Ui::flash(__('Mitgliederrabatte gespeichert.', 'bootspruefung-learning'));
        } catch (\InvalidArgumentException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect(Ui::url(self::DISCOUNT_SLUG));
        exit;
    }

    // ------------------------------------------------------------------ Helfer

    private function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    private function findUser(string $needle): ?\WP_User
    {
        if ($needle === '') {
            return null;
        }
        $user = ctype_digit($needle) ? get_user_by('id', (int) $needle) : (is_email($needle) ? get_user_by('email', $needle) : get_user_by('login', $needle));

        return $user instanceof \WP_User ? $user : null;
    }

    /** Datum (Y-m-d, Ortszeit) → Tagesende in UTC; leer = null. */
    public static function endOfDay(string $date): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return get_gmt_from_date($date.' 23:59:59');
    }

    /** @param array<string,string> $options */
    private function selectField(string $name, array $options, string $current): void
    {
        echo '<select name="'.esc_attr($name).'">';
        foreach ($options as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr((string) $value), selected($current, (string) $value, false), esc_html($label));
        }
        echo '</select> ';
    }

    private static function badge(string $status): string
    {
        $colors = ['invited' => '#2271b1', 'active' => '#00a32a', 'pending' => '#2271b1', 'suspended' => '#dba617', 'revoked' => '#d63638', 'expired' => '#787c82'];

        return sprintf('<span class="bp-badge" style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;color:#fff;background:%s">%s</span>', esc_attr($colors[$status] ?? '#50575e'), esc_html(Labels::status($status)));
    }
}
