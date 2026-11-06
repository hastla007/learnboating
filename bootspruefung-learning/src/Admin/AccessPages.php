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

    public function register(): void
    {
        add_action('admin_post_bp_access_grant', [$this, 'handleGrant']);
        add_action('admin_post_bp_access_change', [$this, 'handleChange']);
        add_action('admin_post_bp_free_rule', [$this, 'handleFreeRule']);
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
        $this->selectField('source', ['' => __('alle Quellen', 'bootspruefung-learning'), 'woocommerce' => 'WooCommerce', 'manual' => __('manuell', 'bootspruefung-learning')], $filter['source']);
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
            __('Manuelle Entscheidung', 'bootspruefung-learning') => isset($meta['manual']) ? esc_html(sprintf('%s – %s (%s)', Labels::status((string) $meta['manual']['status']), (string) $meta['manual']['reason'], Ui::date((string) $meta['manual']['at']))) : '–',
        ]);

        echo '<h2>'.esc_html__('Ändern', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_access_change');
        echo '<input type="hidden" name="action" value="bp_access_change"><input type="hidden" name="id" value="'.(int) $row->id.'">';
        echo '<p><label>'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').'<br><input type="text" name="reason" required class="regular-text"></label></p>';
        echo '<p><label>'.esc_html__('Neues Enddatum', 'bootspruefung-learning').' <input type="date" name="valid_until"></label> <label><input type="checkbox" name="unlimited" value="1"> '.esc_html__('dauerhaft', 'bootspruefung-learning').'</label> ';
        echo '<button class="button" name="op" value="extend">'.esc_html__('Enddatum setzen', 'bootspruefung-learning').'</button></p><p>';
        foreach (['active' => __('Aktivieren', 'bootspruefung-learning'), 'suspended' => __('Aussetzen', 'bootspruefung-learning'), 'revoked' => __('Widerrufen', 'bootspruefung-learning')] as $op => $label) {
            if ($row->status !== $op) {
                printf('<button class="button" name="op" value="%s">%s</button> ', esc_attr($op), esc_html($label));
            }
        }
        if (isset($meta['manual']) && $row->source === EntitlementService::SOURCE_WOO) {
            echo '<button class="button" name="op" value="release">'.esc_html__('Manuelle Entscheidung aufheben (Shop-Status gilt)', 'bootspruefung-learning').'</button>';
        }
        echo '</p></form>';
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
        echo '<tr><th><label for="bp-grant-course">'.esc_html__('Kurs', 'bootspruefung-learning').'</label></th><td><select id="bp-grant-course" name="course">';
        foreach ($courses as $code => $name) {
            printf('<option value="%s">%s</option>', esc_attr($code), esc_html($name));
        }
        echo '</select></td></tr><tr><th>'.esc_html__('Umfang', 'bootspruefung-learning').'</th><td>';
        foreach (ProductAccessRepository::features() as $feature) {
            printf('<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="features[]" value="%s"> %s</label>', esc_attr($feature), esc_html(Labels::feature($feature)));
        }
        echo '</td></tr><tr><th><label for="bp-grant-until">'.esc_html__('Gültig bis', 'bootspruefung-learning').'</label></th><td><input id="bp-grant-until" type="date" name="valid_until"> <span class="description">'.esc_html__('leer = dauerhaft', 'bootspruefung-learning').'</span></td></tr>';
        echo '<tr><th><label for="bp-grant-reason">'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').'</label></th><td><input id="bp-grant-reason" type="text" name="reason" required class="regular-text"></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Zugang freigeben', 'bootspruefung-learning'));
        echo '</form>';
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
            $ids = $this->service()->grantManual((int) $user->ID, $course, $features, self::endOfDay(sanitize_text_field(wp_unslash($_POST['valid_until'] ?? ''))), sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
            Ui::flash(sprintf(__('%d Zugang/Zugänge freigegeben.', 'bootspruefung-learning'), count($ids)));
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
                'extend' => $service->extend($id, ! empty($_POST['unlimited']) ? null : (self::endOfDay(sanitize_text_field(wp_unslash($_POST['valid_until'] ?? ''))) ?? throw new \InvalidArgumentException(__('Bitte ein Enddatum wählen oder „dauerhaft“ ankreuzen.', 'bootspruefung-learning'))), $reason),
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
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Kostenlose Bereiche', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        echo '<p>'.esc_html__('Diese Funktionen stehen allen angemeldeten Nutzern ohne Kauf zur Verfügung (Kap. 7.2). Alles andere erfordert einen Zugang, sobald „Zugang ohne Shop“ auf „Nur kostenlose Regeln und Freischaltungen“ steht.', 'bootspruefung-learning').'</p>';
        $this->shopNotice();
        $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bp_access_rule WHERE access_type = 'free' AND module_code IS NULL ORDER BY course_code, feature_code");
        $rows = [];
        foreach ($rules as $rule) {
            $delete = '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.wp_nonce_field('bp_free_rule', '_wpnonce', true, false)
                .'<input type="hidden" name="action" value="bp_free_rule"><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="'.(int) $rule->id.'">'
                .'<button class="button-link-delete">'.esc_html__('entfernen', 'bootspruefung-learning').'</button></form>';
            $rows[] = [esc_html(Labels::course($rule->course_code)), esc_html(Labels::feature($rule->feature_code)), esc_html(Ui::date($rule->valid_from)), esc_html($rule->valid_until ? Ui::date($rule->valid_until) : __('unbegrenzt', 'bootspruefung-learning')), $delete];
        }
        Ui::table([__('Kurs', 'bootspruefung-learning'), __('Funktion', 'bootspruefung-learning'), __('ab', 'bootspruefung-learning'), __('bis', 'bootspruefung-learning'), ''], $rows, __('Keine kostenlosen Bereiche festgelegt.', 'bootspruefung-learning'));

        $courses = Labels::courses();
        if ($courses !== []) {
            echo '<h2>'.esc_html__('Kostenlosen Bereich hinzufügen', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bp_free_rule');
            echo '<input type="hidden" name="action" value="bp_free_rule"><input type="hidden" name="op" value="add"><p>';
            $this->selectField('course', $courses, '');
            $features = [];
            foreach (\Bootspruefung\Learning\Learning\Services\AccessService::FEATURES as $f) {
                $features[$f] = Labels::feature($f);
            }
            $this->selectField('feature', $features, '');
            echo ' <label>'.esc_html__('bis', 'bootspruefung-learning').' <input type="date" name="valid_until"></label> ';
            echo '<input type="text" name="reason" required placeholder="'.esc_attr__('Begründung', 'bootspruefung-learning').'"> ';
            submit_button(__('Hinzufügen', 'bootspruefung-learning'), 'primary', '', false);
            echo '</p></form>';
        }
        echo '</div>';
    }

    public function handleFreeRule(): void
    {
        $this->guard('bp_free_rule');
        global $wpdb;
        $table = $wpdb->prefix.'bp_access_rule';
        $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        if ($op === 'delete') {
            $id = absint($_POST['id'] ?? 0);
            $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
            if ($rule !== null) {
                $wpdb->delete($table, ['id' => $id]);
                AuditLog::record('free_rule_deleted', 'access_rule', (string) $id, null, ['course' => $rule->course_code, 'feature' => $rule->feature_code]);
                Ui::flash(__('Kostenloser Bereich entfernt.', 'bootspruefung-learning'));
            }
        } else {
            $course = sanitize_text_field(wp_unslash($_POST['course'] ?? ''));
            $feature = sanitize_key(wp_unslash($_POST['feature'] ?? ''));
            $reason = trim(sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
            if (! isset(Labels::courses()[$course]) || ! in_array($feature, \Bootspruefung\Learning\Learning\Services\AccessService::FEATURES, true) || $reason === '') {
                Ui::flash(__('Bitte Kurs, Funktion und Begründung angeben.', 'bootspruefung-learning'), 'error');
            } else {
                $now = current_time('mysql', true);
                $wpdb->insert($table, ['course_code' => $course, 'feature_code' => $feature, 'access_type' => 'free', 'valid_from' => $now,
                    'valid_until' => self::endOfDay(sanitize_text_field(wp_unslash($_POST['valid_until'] ?? ''))), 'created_at' => $now, 'updated_at' => $now]);
                AuditLog::record('free_rule_added', 'access_rule', (string) $wpdb->insert_id, $reason, ['course' => $course, 'feature' => $feature]);
                Ui::flash(__('Kostenloser Bereich hinzugefügt.', 'bootspruefung-learning'));
            }
        }
        wp_safe_redirect(Ui::url(self::FREE_SLUG));
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
        $colors = ['active' => '#00a32a', 'pending' => '#2271b1', 'suspended' => '#dba617', 'revoked' => '#d63638', 'expired' => '#787c82'];

        return sprintf('<span class="bp-badge" style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;color:#fff;background:%s">%s</span>', esc_attr($colors[$status] ?? '#50575e'), esc_html(Labels::status($status)));
    }
}
