<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Commerce\Labels;

/**
 * Protokoll (Tabelle bp_audit_log) im Admin lesen (T102): Filter nach Zeitraum, Ereignis, Nutzer und
 * Zugang/Gruppe/Bestellung, seitenweise, Details als Klartext statt JSON. Nur für Administratoren.
 * Dazu „Verlauf“ je Zugang und je Gruppe (AccessPages). Zeigt keine E-Mail-Adressen; Personen nur als
 * Anzeigename (wer gehandelt hat) bzw. Nutzer-Nummer.
 */
final class AuditPage
{
    public const SLUG = 'bootspruefung-audit';

    /** Nur Administratoren: das Protokoll enthält Vorgänge aller Bereiche (Importe, Datenschutz, Einstellungen). */
    public const CAPABILITY = 'manage_options';

    public const PER_PAGE = 50;

    /** Bereiche für den Filter „Ereignis“: Schlüssel => [Bezeichnung, SQL-LIKE-Muster der Aktion …] */
    private static function areas(): array
    {

        return [
            'entitlement' => [__('Zugänge (alle Ereignisse)', 'bootspruefung-learning'), 'entitlement\_%', 'expiry\_reminder\_%'],
            'group' => [__('Gruppenlizenzen (alle Ereignisse)', 'bootspruefung-learning'), 'group\_%'],
            'shop' => [__('Shop, Bestellungen, Garantie', 'bootspruefung-learning'), 'order\_%', 'money\_back\_%', 'product\_access\_%', 'shop\_%', 'member\_discount\_%', 'voucher\_%', 'free\_tier\_%'],
            'dispute' => [__('Zahlungskonflikte', 'bootspruefung-learning'), 'dispute\_%'],
            'content' => [__('Inhalte, Rechte und Regelwerke', 'bootspruefung-learning'), 'override\_%', 'chart\_%', 'file\_%', 'free\_rule\_%', 'drip\_rule\_%', 'exam\_committee\_%'],
            'import' => [__('Importe', 'bootspruefung-learning'), 'import\_%'],
            'privacy' => [__('Datenschutz und Konten', 'bootspruefung-learning'), 'privacy\_%', 'personal\_data\_%', 'account\_%', 'entitlements\_closed\_%'],
            'settings' => [__('Einstellungen und Livegang', 'bootspruefung-learning'), 'settings\_%', 'mobile\_%', 'security\_%', 'golive\_%', 'shop\_live\_%'],
        ];
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- reine Filteranzeige
        $filter = self::filterFromRequest(wp_unslash($_GET));
        $page = max(1, absint($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Bootsprüfung – Protokoll', 'bootspruefung-learning').'</h1>';
        echo '<p class="description">'.esc_html__('Wer hat wann was geändert: Zugänge, Gruppen, Bestellungen, Inhalte, Importe, Datenschutz und Einstellungen. Einträge werden nach 4 Jahren gelöscht. Zahlungsdaten und IP-Adressen stehen nicht im Protokoll.', 'bootspruefung-learning').'</p>';
        $this->renderFilter($filter);
        if ($filter['error'] !== '') {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($filter['error']).'</p></div>';
        }
        $result = self::query($filter, $page, self::PER_PAGE);
        /* translators: %d: Anzahl Einträge */
        echo '<p>'.esc_html(sprintf(_n('%d Eintrag', '%d Einträge', $result['total'], 'bootspruefung-learning'), $result['total'])).'</p>';
        self::table($result['rows'], true);
        $args = array_filter(['from' => $filter['from'], 'to' => $filter['to'], 'event' => $filter['event'], 'user' => $filter['user_input'], 'object' => $filter['object'], 'object_id' => $filter['object_id'] ?: '']);
        Ui::pager($page, $page * self::PER_PAGE < $result['total'], static fn (int $p) => Ui::url(self::SLUG, $args + ['paged' => $p]));
        echo '</div>';
    }

    /** @param array<string,int|string> $filter siehe filterFromRequest() */
    private function renderFilter(array $filter): void
    {
        echo '<form method="get" class="bp-filter" style="margin:12px 0"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">';
        printf('<label for="bp-audit-from">%s</label> <input type="date" id="bp-audit-from" name="from" value="%s"> ', esc_html__('von', 'bootspruefung-learning'), esc_attr($filter['from']));
        printf('<label for="bp-audit-to">%s</label> <input type="date" id="bp-audit-to" name="to" value="%s"> ', esc_html__('bis', 'bootspruefung-learning'), esc_attr($filter['to']));
        echo '<label for="bp-audit-event">'.esc_html__('Ereignis', 'bootspruefung-learning').'</label> <select id="bp-audit-event" name="event"><option value="">'.esc_html__('alle Ereignisse', 'bootspruefung-learning').'</option><optgroup label="'.esc_attr__('Bereiche', 'bootspruefung-learning').'">';
        foreach (self::areas() as $key => $area) {
            printf('<option value="%s" %s>%s</option>', esc_attr('area:'.$key), selected($filter['event'], 'area:'.$key, false), esc_html($area[0]));
        }
        echo '</optgroup><optgroup label="'.esc_attr__('Einzelne Ereignisse', 'bootspruefung-learning').'">';
        global $wpdb;
        $actions = $wpdb->get_col("SELECT DISTINCT action FROM {$wpdb->prefix}bp_audit_log ORDER BY action LIMIT 300");
        $options = [];
        foreach ($actions as $action) {
            $options[(string) $action] = self::actionLabel((string) $action);
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($options as $action => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr('action:'.$action), selected($filter['event'], 'action:'.$action, false), esc_html($label));
        }
        echo '</optgroup></select> ';
        printf('<label for="bp-audit-user">%s</label> <input type="text" id="bp-audit-user" name="user" value="%s" placeholder="%s" size="18"> ', esc_html__('Nutzer', 'bootspruefung-learning'), esc_attr($filter['user_input']), esc_attr__('Nummer, E-Mail oder Login', 'bootspruefung-learning'));
        echo '<label for="bp-audit-object">'.esc_html__('Bezug', 'bootspruefung-learning').'</label> <select id="bp-audit-object" name="object">';
        foreach (['' => __('beliebig', 'bootspruefung-learning'), 'entitlement' => __('Zugang Nr.', 'bootspruefung-learning'), 'group' => __('Gruppe Nr.', 'bootspruefung-learning'), 'order' => __('Bestellung Nr.', 'bootspruefung-learning')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($filter['object'], $value, false), esc_html($label));
        }
        printf('</select> <input type="number" min="1" name="object_id" value="%s" aria-label="%s" style="width:100px"> ', esc_attr($filter['object_id'] ? (string) $filter['object_id'] : ''), esc_attr__('Nummer des Bezugs', 'bootspruefung-learning'));
        submit_button(__('Filtern', 'bootspruefung-learning'), 'secondary', '', false);
        echo ' <a href="'.esc_url(Ui::url(self::SLUG)).'">'.esc_html__('zurücksetzen', 'bootspruefung-learning').'</a></form>';
    }

    /**
     * Filter aus der Anfrage (bereits ohne Slashes).
     * @param array<string,mixed> $in
     * @return array{from:string,to:string,event:string,user_input:string,user_id:int,object:string,object_id:int,error:string}
     */
    public static function filterFromRequest(array $in): array
    {
        $date = static fn ($v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1 ? (string) $v : '';
        $event = sanitize_text_field((string) ($in['event'] ?? ''));
        if (! preg_match('/^(area|action):[a-z0-9_]{1,64}$/', $event) || (str_starts_with($event, 'area:') && ! isset(self::areas()[substr($event, 5)]))) {
            $event = '';
        }
        $object = sanitize_key((string) ($in['object'] ?? ''));
        $userInput = trim(sanitize_text_field((string) ($in['user'] ?? '')));
        $userId = 0;
        $error = '';
        if ($userInput !== '') {
            $user = ctype_digit($userInput) ? get_user_by('id', (int) $userInput) : (is_email($userInput) ? get_user_by('email', $userInput) : get_user_by('login', $userInput));
            // Gelöschte Konten: Nummer trotzdem suchen (Einträge bleiben 4 Jahre).
            $userId = $user instanceof \WP_User ? (int) $user->ID : (ctype_digit($userInput) ? (int) $userInput : 0);
            if ($userId === 0) {
                $error = __('Kein Nutzer mit dieser E-Mail-Adresse bzw. diesem Login gefunden.', 'bootspruefung-learning');
            }
        }

        return [
            'from' => $date($in['from'] ?? ''),
            'to' => $date($in['to'] ?? ''),
            'event' => $event,
            'user_input' => $userInput,
            'user_id' => $userId,
            'object' => in_array($object, ['entitlement', 'group', 'order'], true) ? $object : '',
            'object_id' => absint($in['object_id'] ?? 0),
            'error' => $error,
        ];
    }

    /**
     * @param array<string,mixed> $filter siehe filterFromRequest()
     * @return array{rows:list<object>,total:int}
     */
    public static function query(array $filter, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        global $wpdb;
        $where = ['1=1'];
        $args = [];
        if (($filter['from'] ?? '') !== '') {
            $where[] = 'created_at >= %s';
            $args[] = get_gmt_from_date($filter['from'].' 00:00:00');
        }
        if (($filter['to'] ?? '') !== '') {
            $where[] = 'created_at <= %s';
            $args[] = get_gmt_from_date($filter['to'].' 23:59:59');
        }
        $event = (string) ($filter['event'] ?? '');
        if (str_starts_with($event, 'action:')) {
            $where[] = 'action = %s';
            $args[] = substr($event, 7);
        } elseif (str_starts_with($event, 'area:') && isset(self::areas()[substr($event, 5)])) {
            $patterns = array_slice(self::areas()[substr($event, 5)], 1);
            $where[] = '('.implode(' OR ', array_fill(0, count($patterns), 'action LIKE %s')).')';
            array_push($args, ...$patterns);
        }
        if (($filter['user_input'] ?? '') !== '' && (int) ($filter['user_id'] ?? 0) === 0) {
            return ['rows' => [], 'total' => 0];
        }
        if ((int) ($filter['user_id'] ?? 0) > 0) {
            $uid = (int) $filter['user_id'];
            [$sql, $more] = self::contextIdLike(['user_id', 'from_user'], $uid);
            // Auch Einträge zu den Zugängen der Person (Änderungen durch das Team oder automatisch).
            $where[] = "(actor_user_id = %d OR (object_type = 'user' AND object_id = %s) OR {$sql}"
                ." OR (object_type = 'entitlement' AND object_id IN (SELECT CAST(e.id AS CHAR) FROM {$wpdb->prefix}bp_entitlement e WHERE e.user_id = %d)))";
            array_push($args, $uid, (string) $uid, ...$more);
            $args[] = $uid;
        }
        $objectId = (int) ($filter['object_id'] ?? 0);
        $object = (string) ($filter['object'] ?? '');
        if ($objectId > 0 && $object !== '') {
            if ($object === 'entitlement') {
                $ids = array_map(static fn ($r) => (int) $r->id, self::forEntitlementId($objectId, 1000));
                if ($ids === []) {
                    return ['rows' => [], 'total' => 0];
                }
                $where[] = 'id IN ('.implode(',', $ids).')';
            } elseif ($object === 'group') {
                [$sql, $more] = self::contextIdLike(['group'], $objectId);
                $where[] = "((object_type = 'group' AND object_id = %s) OR (action = 'group_created' AND {$sql}))";
                array_push($args, (string) $objectId, ...$more);
            } else {
                [$sql, $more] = self::contextIdLike(['order_id'], $objectId);
                $where[] = "((object_type = 'order' AND object_id = %s) OR {$sql})";
                array_push($args, (string) $objectId, ...$more);
            }
        }
        $table = $wpdb->prefix.'bp_audit_log';
        $whereSql = implode(' AND ', $where);
        $prepare = static fn (string $sql, array $a): string => $a === [] ? $sql : $wpdb->prepare($sql, $a);
        $total = (int) $wpdb->get_var($prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $args));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $wpdb->get_results($prepare("SELECT * FROM {$table} WHERE {$whereSql} ORDER BY id DESC LIMIT ".(int) $perPage.' OFFSET '.(int) $offset, $args));

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    /**
     * LIKE-Bedingung für eine Zahl in einem JSON-Schlüssel des Kontexts ("key":5, "key":"5").
     * @param list<string> $keys
     * @return array{0:string,1:list<string>}
     */
    private static function contextIdLike(array $keys, int $id): array
    {
        global $wpdb;
        $parts = [];
        $args = [];
        foreach ($keys as $key) {
            foreach (['"'.$key.'":'.$id.',', '"'.$key.'":'.$id.'}', '"'.$key.'":"'.$id.'"'] as $needle) {
                $parts[] = 'context LIKE %s';
                $args[] = '%'.$wpdb->esc_like($needle).'%';
            }
        }

        return ['('.implode(' OR ', $parts).')', $args];
    }

    /** @return list<object> Protokolleinträge zu einem Zugang (neueste zuerst) */
    public static function forEntitlementId(int $id, int $limit = 50): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bp_entitlement WHERE id = %d", $id));

        return $row ? self::forEntitlement($row, $limit) : [];
    }

    /**
     * Verlauf eines Zugangs: eigene Einträge (Änderungen, Verlängerung) und die passenden Einträge der Bestellung,
     * der manuellen Freischaltung bzw. des Gutscheins und der Gruppe (gleicher Kurs, gleiche Funktion bzw. Person).
     * @return list<object>
     */
    public static function forEntitlement(object $ent, int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix.'bp_audit_log';
        $conds = ["(object_type = 'entitlement' AND object_id = %s)"];
        $args = [(string) $ent->id];
        if (! empty($ent->order_id)) {
            $conds[] = "(object_type = 'order' AND object_id = %s)";
            $args[] = (string) $ent->order_id;
        }
        $conds[] = "(object_type = 'user' AND object_id = %s AND action IN ('entitlement_manual_grant', 'entitlement_voucher_grant'))";
        $args[] = (string) $ent->user_id;
        $group = preg_match('/^group:(\d+):/', (string) ($ent->reference_key ?? ''), $m) ? (int) $m[1] : 0;
        if ($group > 0) {
            $conds[] = "(object_type = 'group' AND object_id = %s)";
            $args[] = (string) $group;
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE ".implode(' OR ', $conds).' ORDER BY id DESC LIMIT 1000', $args)) ?: [];
        $scope = $ent->course_code.'/'.$ent->feature_code;
        $out = [];
        foreach ($rows as $r) {
            $c = self::context($r);
            $match = match ((string) $r->object_type) {
                'entitlement' => true,
                'order' => str_starts_with((string) $r->action, 'entitlement_') || str_starts_with((string) $r->action, 'dispute_') || str_starts_with((string) $r->action, 'money_back_')
                    ? (! isset($c['item_id']) || (int) $c['item_id'] === (int) $ent->order_item_id) && (! isset($c['course']) || $c['course'] === $ent->course_code) && (! isset($c['feature']) || $c['feature'] === $ent->feature_code)
                    : false,
                'user' => $r->action === 'entitlement_manual_grant'
                    ? ($c['course'] ?? '') === $ent->course_code && ($c['feature'] ?? '') === $ent->feature_code && abs(strtotime((string) $r->created_at.' UTC') - strtotime((string) $ent->created_at.' UTC')) <= 120
                    : in_array($scope, array_map('strval', (array) ($c['scopes'] ?? [])), true),
                'group' => ! isset($c['user_id']) || (int) $c['user_id'] === (int) $ent->user_id,
                default => false,
            };
            if ($match) {
                $out[] = $r;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /** @return list<object> Verlauf einer Gruppenlizenz (neueste zuerst) */
    public static function forGroup(int $groupId, int $limit = 50): array
    {
        $result = self::query(['object' => 'group', 'object_id' => $groupId], 1, $limit);

        return $result['rows'];
    }

    /** Aufklappbarer „Verlauf“ (Zugang, Gruppe) mit Link zum vollständigen Protokoll für Administratoren. */
    public static function historyBox(array $rows, string $object, int $id): void
    {
        echo '<details class="bp-history"><summary><strong>'.esc_html(sprintf(
            /* translators: %d: Anzahl Einträge */
            _n('Verlauf (%d Eintrag)', 'Verlauf (%d Einträge)', count($rows), 'bootspruefung-learning'),
            count($rows)
        )).'</strong></summary>';
        self::table($rows, false);
        if (current_user_can(self::CAPABILITY)) {
            echo '<p><a href="'.esc_url(Ui::url(self::SLUG, ['object' => $object, 'object_id' => $id])).'">'.esc_html__('Im Protokoll öffnen', 'bootspruefung-learning').'</a></p>';
        }
        echo '</details>';
    }

    /** @param list<object> $rows */
    public static function table(array $rows, bool $withObject): void
    {
        $out = [];
        // T124: handelnde Personen vorab in einer Abfrage laden (actorLabel ohne Abfrage je Zeile).
        AccessPages::primeUsers(array_map(static fn ($r) => (int) $r->actor_user_id, $rows));
        foreach ($rows as $r) {
            $cells = [
                esc_html(Ui::date((string) $r->created_at)),
                esc_html(self::actorLabel($r->actor_user_id !== null ? (int) $r->actor_user_id : null)),
                esc_html(self::actionLabel((string) $r->action)),
            ];
            if ($withObject) {
                $cells[] = self::objectLink((string) $r->object_type, (string) $r->object_id);
            }
            $cells[] = esc_html(self::reasonLabel($r->reason !== null ? (string) $r->reason : ''));
            $cells[] = esc_html(self::details($r));
            $out[] = $cells;
        }
        $headers = [__('Zeit', 'bootspruefung-learning'), __('Wer', 'bootspruefung-learning'), __('Ereignis', 'bootspruefung-learning')];
        if ($withObject) {
            $headers[] = __('Bezug', 'bootspruefung-learning');
        }
        array_push($headers, __('Begründung', 'bootspruefung-learning'), __('Details', 'bootspruefung-learning'));
        Ui::table($headers, $out, __('Keine Einträge.', 'bootspruefung-learning'));
    }

    public static function actorLabel(?int $userId): string
    {
        if ($userId === null || $userId === 0) {
            return __('System (automatisch)', 'bootspruefung-learning');
        }
        // Gelöschte Konten fragt get_userdata() sonst je Zeile erneut ab.
        static $missing = [];
        $user = isset($missing[$userId]) ? false : get_userdata($userId);
        if (! $user) {
            $missing[$userId] = true;
        }

        // Nur der Anzeigename, keine E-Mail-Adresse.
        return $user ? $user->display_name : sprintf(__('Nutzer %d (gelöscht)', 'bootspruefung-learning'), $userId);
    }

    /** Bezug als Text bzw. Link (Zugang, Gruppe, Bestellung) – escaptes HTML. */
    public static function objectLink(string $type, string $id): string
    {
        $num = ctype_digit($id) ? (int) $id : 0;
        switch ($type) {
            case 'entitlement':
                return $num > 0 ? '<a href="'.esc_url(Ui::url(AccessPages::SLUG, ['entitlement' => $num])).'">'.esc_html(sprintf(__('Zugang %d', 'bootspruefung-learning'), $num)).'</a>' : '–';
            case 'group':
                return $num > 0 ? '<a href="'.esc_url(Ui::url(AccessPages::GROUP_SLUG, ['group' => $num])).'">'.esc_html(sprintf(__('Gruppe %d', 'bootspruefung-learning'), $num)).'</a>' : esc_html__('alle Gruppen', 'bootspruefung-learning');
            case 'order':
                return $num > 0 ? '<a href="'.esc_url(Ui::url(AccessPages::SLUG, ['order' => $num])).'">'.esc_html(sprintf(__('Bestellung %d', 'bootspruefung-learning'), $num)).'</a>' : '–';
            case 'user':
                // Gelöschte Konten stehen nur als Prüfwert im Protokoll – nicht anzeigen.
                return esc_html($num > 0 ? sprintf(__('Nutzer %d', 'bootspruefung-learning'), $num) : __('Nutzer (gelöscht)', 'bootspruefung-learning'));
        }
        $labels = [
            'import_batch' => __('Import %s', 'bootspruefung-learning'), 'access_rule' => __('Regel %s', 'bootspruefung-learning'), 'product' => __('Produkt %s', 'bootspruefung-learning'),
            'attachment' => __('Datei %s', 'bootspruefung-learning'), 'chart' => __('Seekarte %s', 'bootspruefung-learning'), 'user_request' => __('Datenschutz-Antrag %s', 'bootspruefung-learning'),
            'exam_committee' => __('Prüfungsausschuss %s', 'bootspruefung-learning'), 'webhook' => __('Benachrichtigung von %s', 'bootspruefung-learning'), 'grant' => __('Gutschein-Freischaltung %s', 'bootspruefung-learning'),
            'settings' => __('Einstellungen', 'bootspruefung-learning'),
        ];
        if ($type === '' && $id === '') {
            return '–';
        }
        if ($type === 'settings' && ($item = GoLiveChecklist::label($id)) !== null) {
            /* translators: %s: Punkt der Livegang-Checkliste */
            return esc_html(sprintf(__('Livegang-Checkliste: %s', 'bootspruefung-learning'), $item));
        }
        if (isset($labels[$type])) {
            return esc_html(sprintf($labels[$type], wp_html_excerpt($id, 40, '…')));
        }
        $entity = Ui::entityLabel($type);
        $count = $id === '' ? 0 : count(explode(',', $id));

        /* translators: 1: Inhaltsart, 2: Anzahl */
        return esc_html($count > 1 ? sprintf(__('%1$s (%2$d Einträge)', 'bootspruefung-learning'), $entity, $count) : trim($entity.' '.$id));
    }

    public static function actionLabel(string $action): string
    {
        $labels = [
            // Zugänge
            'entitlement_create' => __('Zugang angelegt (Bestellung)', 'bootspruefung-learning'),
            'entitlement_status' => __('Zugangsstatus geändert (Bestellung)', 'bootspruefung-learning'),
            'entitlement_user' => __('Zugang auf anderes Kundenkonto umgestellt', 'bootspruefung-learning'),
            'entitlement_manual_grant' => __('Zugang manuell freigeschaltet', 'bootspruefung-learning'),
            'entitlement_manual_active' => __('Zugang manuell aktiviert', 'bootspruefung-learning'),
            'entitlement_manual_suspended' => __('Zugang manuell ausgesetzt', 'bootspruefung-learning'),
            'entitlement_manual_revoked' => __('Zugang manuell widerrufen', 'bootspruefung-learning'),
            'entitlement_manual_released' => __('Manuelle Entscheidung aufgehoben (Shop-Status gilt)', 'bootspruefung-learning'),
            'entitlement_extended' => __('Enddatum geändert', 'bootspruefung-learning'),
            'entitlement_restacked' => __('Verlängerung neu angeschlossen', 'bootspruefung-learning'),
            'entitlement_voucher_grant' => __('Zugang per Kursgutschein freigeschaltet', 'bootspruefung-learning'),
            'entitlement_voucher_revoke' => __('Gutschein-Freischaltung widerrufen', 'bootspruefung-learning'),
            'entitlements_closed_account_deleted' => __('Zugänge eines gelöschten Kontos beendet', 'bootspruefung-learning'),
            'expiry_reminder_optout' => __('Ablauf-Erinnerung abbestellt', 'bootspruefung-learning'),
            'expiry_reminder_optin' => __('Ablauf-Erinnerung wieder bestellt', 'bootspruefung-learning'),
            // Gruppen
            'group_created' => __('Gruppenlizenz angelegt', 'bootspruefung-learning'),
            'group_active' => __('Gruppenlizenz aktiviert', 'bootspruefung-learning'),
            'group_suspended' => __('Gruppenlizenz ausgesetzt', 'bootspruefung-learning'),
            'group_revoked' => __('Gruppenlizenz beendet', 'bootspruefung-learning'),
            'group_expired' => __('Gruppenlizenz abgelaufen', 'bootspruefung-learning'),
            'group_pending' => __('Gruppenlizenz wartet auf Zahlung', 'bootspruefung-learning'),
            'group_seats_changed' => __('Anzahl der Plätze geändert', 'bootspruefung-learning'),
            'group_invited' => __('Personen eingeladen', 'bootspruefung-learning'),
            'group_joined' => __('Person beigetreten', 'bootspruefung-learning'),
            'group_member_removed' => __('Person entfernt (Platz frei)', 'bootspruefung-learning'),
            'group_owner_deleted' => __('Konto der verwaltenden Person gelöscht', 'bootspruefung-learning'),
            'group_invites_expired' => __('Abgelaufene Einladungen aufgeräumt', 'bootspruefung-learning'),
            // Shop
            'order_without_account' => __('Bezahlte Bestellung ohne Kundenkonto', 'bootspruefung-learning'),
            'order_reconcile' => __('Lernzugang der Bestellung abgeglichen', 'bootspruefung-learning'),
            'money_back_requested' => __('Geld-zurück-Garantie angefragt', 'bootspruefung-learning'),
            'product_access_saved' => __('Lernzugang eines Produkts gespeichert', 'bootspruefung-learning'),
            'product_access_orphan_removed' => __('Zuordnung eines gelöschten Produkts entfernt', 'bootspruefung-learning'),
            'shop_prices_changed' => __('Preise geändert', 'bootspruefung-learning'),
            'shop_catalog_applied' => __('Produktkatalog abgeglichen', 'bootspruefung-learning'),
            'free_tier_applied' => __('Schnupper-Paket angelegt', 'bootspruefung-learning'),
            'member_discount_added' => __('Mitgliederrabatt hinzugefügt', 'bootspruefung-learning'),
            'member_discount_deleted' => __('Mitgliederrabatt entfernt', 'bootspruefung-learning'),
            'voucher_redeemed' => __('Kursgutschein eingelöst', 'bootspruefung-learning'),
            'voucher_redeem_failed' => __('Kursgutschein: Einlösung fehlgeschlagen', 'bootspruefung-learning'),
            'voucher_redeem_skipped' => __('Kursgutschein: Einlösung übersprungen', 'bootspruefung-learning'),
            // Zahlungskonflikte
            'dispute_opened' => __('Zahlungskonflikt eröffnet (Zugang ausgesetzt)', 'bootspruefung-learning'),
            'dispute_won' => __('Zahlungskonflikt gewonnen (Zugang wieder frei)', 'bootspruefung-learning'),
            'dispute_lost' => __('Zahlungskonflikt verloren (Zugang bleibt gesperrt)', 'bootspruefung-learning'),
            'dispute_ignored' => __('Zahlungskonflikt: Meldung nicht angewendet (Fall schon abgeschlossen)', 'bootspruefung-learning'),
            'dispute_unmatched' => __('Zahlungskonflikt: keine passende Bestellung', 'bootspruefung-learning'),
            'dispute_webhook_rejected' => __('Zahlungskonflikt: Meldung abgewiesen', 'bootspruefung-learning'),
            'dispute_settings_saved' => __('Einstellungen Zahlungskonflikte gespeichert', 'bootspruefung-learning'),
            // Inhalte
            'override_rights_status' => __('Rechte-Status lokal festgelegt', 'bootspruefung-learning'),
            'override_verified' => __('Regelwerk: Prüfung lokal festgelegt', 'bootspruefung-learning'),
            'override_status' => __('Regelwerk: Veröffentlichung lokal festgelegt', 'bootspruefung-learning'),
            'override_valid_from' => __('Regelwerk: Gültig ab lokal festgelegt', 'bootspruefung-learning'),
            'override_valid_until' => __('Regelwerk: Gültig bis lokal festgelegt', 'bootspruefung-learning'),
            'free_rule_added' => __('Kostenlosen Bereich hinzugefügt', 'bootspruefung-learning'),
            'free_rule_deleted' => __('Regel für kostenlosen Bereich entfernt', 'bootspruefung-learning'),
            'drip_rule_added' => __('Zeitversetzte Freischaltung hinzugefügt', 'bootspruefung-learning'),
            'chart_uploaded' => __('Seekarte hochgeladen', 'bootspruefung-learning'),
            'chart_removed' => __('Seekarte gelöscht', 'bootspruefung-learning'),
            'chart_licensed' => __('BSH-Lizenz bestätigt', 'bootspruefung-learning'),
            'chart_unlicensed' => __('BSH-Lizenz zurückgezogen', 'bootspruefung-learning'),
            'file_protected' => __('Datei geschützt', 'bootspruefung-learning'),
            'file_unprotected' => __('Dateischutz aufgehoben', 'bootspruefung-learning'),
            'file_protect_failed' => __('Datei konnte nicht geschützt werden', 'bootspruefung-learning'),
            'exam_committee_created' => __('Prüfungsausschuss angelegt', 'bootspruefung-learning'),
            'exam_committee_saved' => __('Prüfungsausschuss geändert', 'bootspruefung-learning'),
            'exam_committee_deleted' => __('Prüfungsausschuss gelöscht', 'bootspruefung-learning'),
            // Importe
            'import_uploaded' => __('Inhaltspaket hochgeladen', 'bootspruefung-learning'),
            'import_validated' => __('Inhaltspaket geprüft', 'bootspruefung-learning'),
            'import_validation_failed' => __('Inhaltspaket fehlerhaft', 'bootspruefung-learning'),
            'import_approved' => __('Import freigegeben', 'bootspruefung-learning'),
            'import_started' => __('Import gestartet', 'bootspruefung-learning'),
            'import_published' => __('Import veröffentlicht', 'bootspruefung-learning'),
            'import_rolled_back' => __('Früheres Inhaltspaket wiederhergestellt', 'bootspruefung-learning'),
            'import_rollback_requested' => __('Wiederherstellung angefordert', 'bootspruefung-learning'),
            'import_discarded' => __('Import verworfen', 'bootspruefung-learning'),
            'import_retention' => __('Alte Importe aufgeräumt', 'bootspruefung-learning'),
            // Datenschutz und Konten
            'privacy_export_sent' => __('Datenexport verschickt', 'bootspruefung-learning'),
            'privacy_export_failed' => __('Datenexport fehlgeschlagen', 'bootspruefung-learning'),
            'account_deleted' => __('Kundenkonto gelöscht', 'bootspruefung-learning'),
            'account_removed_from_site' => __('Kundenkonto von dieser Website entfernt (Multisite)', 'bootspruefung-learning'),
            'account_deletion_failed' => __('Kontolöschung fehlgeschlagen', 'bootspruefung-learning'),
            'account_deletion_skipped' => __('Kontolöschung übersprungen', 'bootspruefung-learning'),
            'personal_data_erased' => __('Lerndaten einer Person gelöscht (Datenschutz)', 'bootspruefung-learning'),
            // Einstellungen und Livegang
            'settings_default_access' => __('Einstellung „Zugang ohne Shop“ geändert', 'bootspruefung-learning'),
            'mobile_settings_saved' => __('Einstellungen der App gespeichert', 'bootspruefung-learning'),
            'mobile_sessions_revoked' => __('App-Sitzungen eines Kunden beendet', 'bootspruefung-learning'),
            'security_settings_saved' => __('Sicherheitseinstellungen gespeichert', 'bootspruefung-learning'),
            'golive_checklist_done' => __('Livegang-Checkliste: Punkt abgehakt', 'bootspruefung-learning'),
            'golive_checklist_undone' => __('Livegang-Checkliste: Haken zurückgenommen', 'bootspruefung-learning'),
            'shop_live_confirmed' => __('Shop trotz offener Punkte sichtbar gemacht', 'bootspruefung-learning'),
        ];
        if (isset($labels[$action])) {
            return $labels[$action];
        }

        /* translators: %s: interner Name des Ereignisses */
        return sprintf(__('Sonstiges Ereignis (%s)', 'bootspruefung-learning'), $action);
    }

    public static function reasonLabel(string $reason): string
    {
        $known = [
            'item_removed' => __('Position aus der Bestellung entfernt', 'bootspruefung-learning'),
            'account_deleted' => __('Konto gelöscht', 'bootspruefung-learning'),
            'privacy_request' => __('Datenschutz-Antrag', 'bootspruefung-learning'),
            'protected_account' => __('Konto mit Verwaltungsrechten (wird nie automatisch gelöscht)', 'bootspruefung-learning'),
            'eraser_failed' => __('Löschen der Daten fehlgeschlagen', 'bootspruefung-learning'),
            'wp_delete_user' => __('Konto konnte nicht gelöscht werden', 'bootspruefung-learning'),
            'group_member_removed' => __('Person aus der Gruppe entfernt', 'bootspruefung-learning'),
            'group_seats_reduced' => __('Plätze der Gruppe verringert', 'bootspruefung-learning'),
        ];
        $reason = trim($reason);
        if ($reason === '') {
            return '–';
        }
        // Begründungen aus Formularen sind Text; manuelle Gruppenentscheidungen stehen als {"manual":"…"}.
        $json = str_starts_with($reason, '{') ? json_decode($reason, true) : null;
        if (is_array($json) && isset($json['manual']) && is_string($json['manual'])) {
            return $json['manual'];
        }

        return $known[$reason] ?? $reason;
    }

    /** @return array<string,mixed> */
    private static function context(object $row): array
    {
        $c = $row->context !== null && $row->context !== '' ? json_decode((string) $row->context, true) : null;

        return is_array($c) ? $c : [];
    }

    /** Details eines Eintrags als lesbarer Text (kein JSON); Text, noch nicht escaped. */
    public static function details(object $row): string
    {
        $c = self::context($row);
        if ($c === []) {
            return '';
        }
        $parts = [];
        if (array_key_exists('from', $c) || array_key_exists('to', $c)) {
            $parts[] = sprintf(
                /* translators: 1: alter Wert, 2: neuer Wert */
                __('von %1$s auf %2$s', 'bootspruefung-learning'),
                self::value('from', $c['from'] ?? null, (string) $row->action),
                self::value('to', $c['to'] ?? null, (string) $row->action)
            );
            unset($c['from'], $c['to']);
        }
        $labels = [
            'course' => __('Kurs', 'bootspruefung-learning'), 'feature' => __('Funktion', 'bootspruefung-learning'), 'scopes' => __('Umfang', 'bootspruefung-learning'), 'user_id' => __('Nutzer', 'bootspruefung-learning'),
            'from_user' => __('vorher Nutzer', 'bootspruefung-learning'), 'item_id' => __('Bestellposition', 'bootspruefung-learning'), 'order_id' => __('Bestellung', 'bootspruefung-learning'),
            'via' => __('Auslöser', 'bootspruefung-learning'), 'value' => __('neuer Wert', 'bootspruefung-learning'), 'count' => __('Anzahl', 'bootspruefung-learning'), 'days' => __('Tage', 'bootspruefung-learning'),
            'limit' => __('Teilumfang', 'bootspruefung-learning'), 'module' => __('Modul', 'bootspruefung-learning'), 'type' => __('Art', 'bootspruefung-learning'), 'package_id' => __('Inhaltspaket', 'bootspruefung-learning'),
            'errors' => __('Fehler', 'bootspruefung-learning'), 'seats' => __('Plätze', 'bootspruefung-learning'), 'member' => __('Teilnahme', 'bootspruefung-learning'), 'group' => __('Gruppe', 'bootspruefung-learning'),
            'percent' => __('Rabatt in %', 'bootspruefung-learning'), 'added' => __('hinzugefügt', 'bootspruefung-learning'), 'removed' => __('entfernt', 'bootspruefung-learning'), 'unchanged' => __('unverändert', 'bootspruefung-learning'),
            'product_id' => __('Produkt', 'bootspruefung-learning'), 'variation_id' => __('Variation', 'bootspruefung-learning'), 'valid_until' => __('gültig bis', 'bootspruefung-learning'),
            'voucher_id' => __('Gutschein', 'bootspruefung-learning'), 'source' => __('Quelle', 'bootspruefung-learning'), 'source_reference' => __('Herkunft', 'bootspruefung-learning'),
            'redeemed_at' => __('eingelöst am', 'bootspruefung-learning'), 'manual' => __('von Hand', 'bootspruefung-learning'), 'cause' => __('Ursache', 'bootspruefung-learning'),
            'invited' => __('eingeladen', 'bootspruefung-learning'), 'skipped' => __('übersprungen', 'bootspruefung-learning'), 'expired' => __('abgelaufen', 'bootspruefung-learning'),
            'anonymized' => __('unkenntlich gemacht', 'bootspruefung-learning'), 'target_batch' => __('Ziel-Import', 'bootspruefung-learning'), 'rollback_of' => __('Wiederherstellung von Import', 'bootspruefung-learning'),
            'actions' => __('Änderungen', 'bootspruefung-learning'), 'aborted' => __('abgebrochen', 'bootspruefung-learning'), 'event' => __('Meldung', 'bootspruefung-learning'), 'event_time' => __('Zeitpunkt', 'bootspruefung-learning'),
            'case_status' => __('Stand des Falls', 'bootspruefung-learning'), 'paypal' => __('PayPal eingerichtet', 'bootspruefung-learning'), 'mode' => __('Umgebung', 'bootspruefung-learning'),
            'rows' => __('Zeilen', 'bootspruefung-learning'), 'deleted' => __('gelöscht', 'bootspruefung-learning'), 'revoked' => __('beendet', 'bootspruefung-learning'), 'scopes_count' => __('Umfang', 'bootspruefung-learning'),
            'redemption_key' => __('Einlöse-Nr.', 'bootspruefung-learning'), 'grant_source' => __('Herkunft', 'bootspruefung-learning'), 'action' => __('Ergebnis', 'bootspruefung-learning'),
            'open' => __('offene Punkte', 'bootspruefung-learning'), 'voucher_order' => __('Gutschein-Bestellung', 'bootspruefung-learning'),
        ];
        // Nicht anzeigen: Prüfwerte gelöschter Konten bzw. Gutscheincodes, Fehlermeldungen (können Adressen enthalten).
        unset($c['user'], $c['messages'], $c['message'], $c['code_hash']);
        foreach ($c as $key => $value) {
            if (in_array($key, ['variation_id', 'rollback_of', 'target_batch'], true) && empty($value)) {
                continue;
            }
            $label = $labels[$key] ?? str_replace('_', ' ', (string) $key);
            $parts[] = $label.': '.self::value((string) $key, $value, (string) $row->action);
        }

        return implode(' · ', $parts);
    }

    /** Ein Kontextwert als Text. */
    private static function value(string $key, mixed $v, string $action): string
    {
        if ($v === null || $v === '') {
            return in_array($key, ['from', 'to', 'valid_until'], true) && ! str_starts_with($action, 'group_') && $action !== 'entitlement_status' && $action !== 'entitlement_manual_released'
                ? __('ohne Ablauf', 'bootspruefung-learning') : '–';
        }
        if (is_bool($v)) {
            return $v ? __('ja', 'bootspruefung-learning') : __('nein', 'bootspruefung-learning');
        }
        if (is_array($v)) {
            if ($key === 'actions') {
                $n = 0;
                array_walk_recursive($v, static function ($x) use (&$n): void {
                    $n += is_numeric($x) ? (int) $x : 0;
                });

                return (string) $n;
            }
            $items = array_map(static fn ($x) => is_scalar($x) ? self::value($key === 'scopes' ? 'scope' : $key, $x, $action) : '…', array_slice($v, 0, 12));

            return implode(', ', $items).(count($v) > 12 ? ', …' : '');
        }
        $s = (string) $v;
        switch ($key) {
            case 'course':
                return Labels::course($s);
            case 'feature':
                return Labels::feature($s);
            case 'scope':
                if (str_contains($s, '/')) {
                    [$course, $feature] = explode('/', $s, 2);

                    return Labels::course($course).' · '.Labels::feature($feature);
                }

                return $s;
            case 'user_id':
            case 'from_user':
                return sprintf(__('Nr. %d', 'bootspruefung-learning'), (int) $s);
            case 'item_id':
            case 'order_id':
            case 'product_id':
            case 'variation_id':
            case 'group':
            case 'member':
            case 'voucher_id':
            case 'target_batch':
            case 'rollback_of':
                return ctype_digit($s) ? sprintf(__('Nr. %d', 'bootspruefung-learning'), (int) $s) : $s;
            case 'manual':
            case 'aborted':
            case 'paypal':
                return in_array($s, ['1', 'true'], true) ? __('ja', 'bootspruefung-learning') : __('nein', 'bootspruefung-learning');
            case 'type':
                return ['free' => __('ganz kostenlos', 'bootspruefung-learning'), 'free_limited' => __('Teilumfang', 'bootspruefung-learning'), 'drip' => __('zeitversetzte Freischaltung', 'bootspruefung-learning')][$s] ?? $s;
            case 'via':
                return [
                    'order_sync' => __('Bestellung geändert', 'bootspruefung-learning'), 'manual_release' => __('manuelle Entscheidung aufgehoben', 'bootspruefung-learning'),
                    'admin_order_action' => __('Bestellaktion im Admin', 'bootspruefung-learning'), 'cli' => __('Befehlszeile', 'bootspruefung-learning'), 'reconcile' => __('Abgleich', 'bootspruefung-learning'),
                    'cron' => __('automatisch', 'bootspruefung-learning'), 'web' => __('Website', 'bootspruefung-learning'), 'app' => __('App', 'bootspruefung-learning'), 'mail' => __('E-Mail-Link', 'bootspruefung-learning'),
                ][$s] ?? $s;
            case 'cause':
                return ['seats_reduced' => __('Plätze verringert', 'bootspruefung-learning'), 'account_deleted' => __('Konto gelöscht', 'bootspruefung-learning')][$s] ?? $s;
            case 'value':
                if ($action === 'override_verified') {
                    return $s === '1' ? __('geprüft (verifiziert)', 'bootspruefung-learning') : __('nicht geprüft', 'bootspruefung-learning');
                }

                return Ui::label($s);
            case 'redemption_key':
                return wp_html_excerpt($s, 12, '…');
            case 'voucher_order':
                return ctype_digit($s) ? sprintf(__('Nr. %d', 'bootspruefung-learning'), (int) $s) : $s;
            case 'mode':
                // PayPal-Umgebung der Zahlungskonflikte
                return ['sandbox' => __('Testumgebung (Sandbox)', 'bootspruefung-learning'), 'live' => __('Echtbetrieb', 'bootspruefung-learning')][$s] ?? $s;
            case 'source':
            case 'grant_source':
                return self::sourceLabel($s);
        }
        if ($action === 'settings_default_access' && in_array($key, ['from', 'to'], true)) {
            return '„'.(Menu::defaultAccessLabels()[$s] ?? $s).'“';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $s) === 1) {
            return Ui::date(substr(str_replace('T', ' ', $s), 0, 19));
        }
        if (in_array($key, ['from', 'to', 'case_status'], true)) {
            $status = Labels::status($s);

            return $status !== $s ? $status : Ui::label($s);
        }

        return $s;
    }

    /** Quelle eines Zugangs bzw. Absender einer Meldung (Zahlungskonflikt, Gutschein) im Klartext. */
    public static function sourceLabel(string $source): string
    {
        $known = [
            'order' => __('Bestellung', 'bootspruefung-learning'), 'manual' => __('manuell', 'bootspruefung-learning'), 'group' => __('Gruppe', 'bootspruefung-learning'),
            'voucher' => __('Kursgutschein', 'bootspruefung-learning'), 'paypal' => 'PayPal', 'klarna' => 'Klarna', 'stripe' => 'Stripe',
        ];
        if (isset($known[$source])) {
            return $known[$source];
        }
        if (str_starts_with($source, 'voucher-') || str_starts_with($source, 'voucher_')) {
            /* translators: %s: Kennung der Gutschein-Herkunft */
            return sprintf(__('Kursgutschein (%s)', 'bootspruefung-learning'), substr($source, 8));
        }

        return $source;
    }
}
