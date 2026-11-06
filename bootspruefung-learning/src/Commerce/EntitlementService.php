<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Audit\AuditLog;

/**
 * Schreibt Zugänge (bp_entitlement, Kap. 7.3). Schlüssel je Bestellposition:
 * reference_key = "wc_item:<id>" + Kurs + Feature, damit wiederholte
 * Zahlungsereignisse keine Doppelzugänge erzeugen. Manuelle Zugänge
 * erhalten eine eigene Referenz ("manual:<uuid>").
 */
final class EntitlementService
{
    public const SOURCE_WOO = 'woocommerce';
    public const SOURCE_MANUAL = 'manual';

    private string $table;

    public function __construct(private readonly \wpdb $db)
    {
        $this->table = $db->prefix.'bp_entitlement';
    }

    public static function itemReference(int $itemId): string
    {
        return 'wc_item:'.$itemId;
    }

    /** @return list<object> */
    public function forOrder(int $orderId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table} WHERE source = %s AND order_id = %d ORDER BY order_item_id, course_code, feature_code", self::SOURCE_WOO, $orderId));
    }

    /** @return list<object> */
    public function forItem(int $itemId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table} WHERE reference_key = %s ORDER BY course_code, feature_code", self::itemReference($itemId)));
    }

    /** @return list<object> */
    public function forUser(int $userId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY course_code, feature_code, id", $userId));
    }

    /** @return list<int> */
    public function orderIds(?string $since = null): array
    {
        $sql = "SELECT DISTINCT order_id FROM {$this->table} WHERE source = %s AND order_id IS NOT NULL".($since !== null ? ' AND created_at >= %s' : '').' ORDER BY order_id DESC';

        return array_map('intval', $this->db->get_col($since !== null ? $this->db->prepare($sql, self::SOURCE_WOO, $since) : $this->db->prepare($sql, self::SOURCE_WOO)));
    }

    public function find(int $id): ?object
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)) ?: null;
    }

    /**
     * Wendet einen Plan an. Jede Änderung wird protokolliert (ohne Zahlungsdaten).
     *
     * @return int Anzahl angewendeter Änderungen
     */
    public function apply(Plan $plan, string $source = 'order_sync'): int
    {
        $applied = 0;
        foreach ($plan->changes as $change) {
            $done = match ($change['type']) {
                'create' => $this->create($plan->orderId, $change),
                'status' => $this->transition((int) $change['id'], (string) $change['to'], (string) $change['reason'], $change),
                'user' => (bool) $this->db->update($this->table, ['user_id' => $change['user_id']], ['id' => $change['id']]),
                default => false,
            };
            if ($done) {
                $applied++;
                AuditLog::record('entitlement_'.$change['type'], 'order', (string) $plan->orderId, $change['reason'] ?? null, array_intersect_key($change, array_flip(['item_id', 'course', 'feature', 'from', 'to', 'user_id', 'from_user'])) + ['via' => $source]);
            }
        }
        if ($applied > 0) {
            do_action('bootspruefung_entitlements_changed', $plan->orderId, $plan);
        }

        return $applied;
    }

    /** @param array<string,mixed> $c */
    private function create(int $orderId, array $c): bool
    {
        $now = self::now();
        $data = [
            'user_id' => $c['user_id'], 'course_code' => $c['course'], 'feature_code' => $c['feature'], 'status' => $c['to'],
            'source' => self::SOURCE_WOO, 'reference_key' => self::itemReference((int) $c['item_id']),
            'order_id' => $orderId, 'order_item_id' => $c['item_id'], 'product_id' => $c['product_id'] ?: null, 'variation_id' => $c['variation_id'] ?: null,
            'sku_snapshot' => $c['sku'] !== '' ? substr((string) $c['sku'], 0, 100) : null,
            'idempotency_key' => substr(hash('sha256', $orderId.'|'.$c['item_id'].'|'.$c['course'].'|'.$c['feature']), 0, 64),
            'meta' => wp_json_encode(['duration_days' => $c['duration_days'], 'renewal_mode' => $c['renewal_mode'], 'refund_rule' => $c['refund_rule']]),
            'reason' => $c['reason'], 'created_at' => $now, 'updated_at' => $now,
        ];
        $suppress = $this->db->suppress_errors(true);
        $ok = $this->db->insert($this->table, $data);
        $this->db->suppress_errors($suppress);
        if (! $ok) {
            return false; // gleichzeitig angelegt (eindeutiger Schlüssel) – nichts doppelt.
        }
        if ($c['to'] === EntitlementPlanner::ACTIVE) {
            $this->setValidity((int) $this->db->insert_id, $c['date_paid'] ?? null);
        }

        return true;
    }

    /** @param array<string,mixed> $c */
    private function transition(int $id, string $to, string $reason, array $c = []): bool
    {
        $row = $this->find($id);
        if ($row === null || $row->status === $to) {
            return false;
        }
        $this->db->update($this->table, ['status' => $to, 'reason' => $reason, 'updated_at' => self::now()], ['id' => $id]);
        if ($to === EntitlementPlanner::ACTIVE && $row->valid_from === null) {
            $this->setValidity($id, $c['date_paid'] ?? null);
        }

        return true;
    }

    /**
     * Gültigkeit bei erster Freischaltung: ab Zahldatum, Dauer aus der Produktregel.
     * renewal_mode "extend": Beginn = spätestes Ende eines bestehenden befristeten Zugangs.
     */
    private function setValidity(int $id, ?string $datePaid): void
    {
        $row = $this->find($id);
        $meta = EntitlementPlanner::meta($row);
        $start = $datePaid ?? self::now();
        $until = null;
        $days = isset($meta['duration_days']) ? (int) $meta['duration_days'] : 0;
        if ($days > 0) {
            if (($meta['renewal_mode'] ?? 'extend') === 'extend') {
                $latest = $this->db->get_var($this->db->prepare(
                    "SELECT MAX(valid_until) FROM {$this->table} WHERE user_id = %d AND course_code = %s AND feature_code = %s AND status = 'active' AND id <> %d AND valid_until IS NOT NULL",
                    $row->user_id, $row->course_code, $row->feature_code, $id
                ));
                if ($latest !== null && $latest > $start) {
                    $start = $latest;
                }
            }
            $until = gmdate('Y-m-d H:i:s', strtotime($start.' UTC') + $days * DAY_IN_SECONDS);
        }
        $this->db->update($this->table, ['valid_from' => $start, 'valid_until' => $until], ['id' => $id]);
    }

    // ------------------------------------------------------------ manuelle Aktionen (Kap. 7.6)

    /**
     * Manueller Zugang mit Pflichtbegründung und optionalem Enddatum.
     *
     * @param list<string> $features
     * @return list<int> neue IDs
     */
    public function grantManual(int $userId, string $courseCode, array $features, ?string $validUntil, string $reason): array
    {
        $reason = self::requireReason($reason);
        if (get_userdata($userId) === false) {
            throw new \InvalidArgumentException(__('Benutzer nicht gefunden.', 'bootspruefung-learning'));
        }
        $ids = [];
        $reference = 'manual:'.wp_generate_uuid4();
        $now = self::now();
        foreach (array_unique($features) as $feature) {
            $this->db->insert($this->table, [
                'user_id' => $userId, 'course_code' => $courseCode, 'feature_code' => $feature, 'status' => EntitlementPlanner::ACTIVE,
                'valid_from' => $now, 'valid_until' => $validUntil, 'source' => self::SOURCE_MANUAL, 'reference_key' => $reference,
                'granted_by' => get_current_user_id() ?: null, 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids[] = (int) $this->db->insert_id;
            AuditLog::record('entitlement_manual_grant', 'user', (string) $userId, $reason, ['course' => $courseCode, 'feature' => $feature, 'valid_until' => $validUntil]);
        }

        return $ids;
    }

    /** Enddatum setzen/verlängern (null = dauerhaft). */
    public function extend(int $id, ?string $validUntil, string $reason): void
    {
        $reason = self::requireReason($reason);
        $row = $this->mustFind($id);
        $this->db->update($this->table, ['valid_until' => $validUntil, 'updated_at' => self::now()], ['id' => $id]);
        if ($row->status === EntitlementPlanner::EXPIRED && ($validUntil === null || $validUntil > self::now())) {
            $this->db->update($this->table, ['status' => EntitlementPlanner::ACTIVE], ['id' => $id]);
        }
        AuditLog::record('entitlement_extended', 'entitlement', (string) $id, $reason, ['from' => $row->valid_until, 'to' => $validUntil]);
    }

    /**
     * Manueller Statuswechsel. Bei Shop-Zugängen bleibt die Entscheidung als
     * Sperre gespeichert, bis sie aufgehoben wird ("release").
     */
    public function setStatusManual(int $id, string $to, string $reason): void
    {
        $reason = self::requireReason($reason);
        if (! in_array($to, [EntitlementPlanner::ACTIVE, EntitlementPlanner::SUSPENDED, EntitlementPlanner::REVOKED], true)) {
            throw new \InvalidArgumentException('Ungültiger Status.');
        }
        $row = $this->mustFind($id);
        $meta = EntitlementPlanner::meta($row);
        $meta['manual'] = ['status' => $to, 'by' => get_current_user_id(), 'at' => self::now(), 'reason' => $reason];
        $this->db->update($this->table, ['status' => $to, 'reason' => $reason, 'meta' => wp_json_encode($meta), 'updated_at' => self::now()], ['id' => $id]);
        if ($to === EntitlementPlanner::ACTIVE && $row->valid_from === null) {
            $this->setValidity($id, null);
        }
        AuditLog::record('entitlement_manual_'.$to, 'entitlement', (string) $id, $reason, ['from' => $row->status, 'order_id' => $row->order_id]);
    }

    /** Hebt eine manuelle Entscheidung auf; der Shop-Status gilt wieder (Neuabgleich durch Aufrufer). */
    public function releaseManual(int $id, string $reason): void
    {
        $reason = self::requireReason($reason);
        $row = $this->mustFind($id);
        $meta = EntitlementPlanner::meta($row);
        unset($meta['manual']);
        $this->db->update($this->table, ['meta' => wp_json_encode($meta), 'updated_at' => self::now()], ['id' => $id]);
        // Manuell suspendiert/widerrufen → zurück auf "pending", der Abgleich setzt den Shop-Stand.
        if ($row->status !== EntitlementPlanner::ACTIVE && $row->source === self::SOURCE_WOO) {
            $this->db->update($this->table, ['status' => EntitlementPlanner::PENDING], ['id' => $id]);
        }
        AuditLog::record('entitlement_manual_released', 'entitlement', (string) $id, $reason, ['order_id' => $row->order_id]);
    }

    /** Markiert abgelaufene Zugänge (stündlicher Cron). @return int Anzahl */
    public function expireDue(): int
    {
        return (int) $this->db->query($this->db->prepare(
            "UPDATE {$this->table} SET status = 'expired', updated_at = %s WHERE status = 'active' AND valid_until IS NOT NULL AND valid_until < %s",
            self::now(), self::now()
        ));
    }

    /**
     * Suche für die Adminliste.
     *
     * @param array{search?:string,course?:string,status?:string,source?:string,order?:int} $filter
     * @return array{rows:list<object>,total:int}
     */
    public function search(array $filter, int $page = 1, int $perPage = 50): array
    {
        $users = $this->db->users;
        $where = ['1=1'];
        $args = [];
        if (($filter['search'] ?? '') !== '') {
            $like = '%'.$this->db->esc_like($filter['search']).'%';
            $where[] = '(u.user_email LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s OR e.sku_snapshot LIKE %s OR e.user_id = %d)';
            array_push($args, $like, $like, $like, $like, (int) $filter['search']);
        }
        foreach (['course' => 'e.course_code', 'status' => 'e.status', 'source' => 'e.source'] as $key => $col) {
            if (($filter[$key] ?? '') !== '') {
                $where[] = "{$col} = %s";
                $args[] = $filter[$key];
            }
        }
        if (! empty($filter['order'])) {
            $where[] = 'e.order_id = %d';
            $args[] = (int) $filter['order'];
        }
        $sqlWhere = implode(' AND ', $where);
        $from = "{$this->table} e LEFT JOIN {$users} u ON u.ID = e.user_id";
        $count = "SELECT COUNT(*) FROM {$from} WHERE {$sqlWhere}";
        $list = "SELECT e.*, u.user_email, u.display_name FROM {$from} WHERE {$sqlWhere} ORDER BY e.id DESC LIMIT %d OFFSET %d";

        return [
            'total' => (int) $this->db->get_var($args === [] ? $count : $this->db->prepare($count, ...$args)),
            'rows' => $this->db->get_results($this->db->prepare($list, ...array_merge($args, [$perPage, max(0, ($page - 1) * $perPage)]))),
        ];
    }

    /**
     * Zusammenfassung je Kurs/Feature für "Mein Lernen": ein Eintrag je Zugang,
     * auch bei mehreren Bestellungen (Kap. 7.6 Doppelkäufe).
     *
     * @return list<array{course:string,feature:string,status:string,valid_until:?string}>
     */
    public function summaryForUser(int $userId): array
    {
        $now = self::now();
        $out = [];
        $rank = [EntitlementPlanner::ACTIVE => 5, EntitlementPlanner::PENDING => 4, EntitlementPlanner::SUSPENDED => 3, EntitlementPlanner::EXPIRED => 2, EntitlementPlanner::REVOKED => 1];
        foreach ($this->forUser($userId) as $row) {
            $status = $row->status === EntitlementPlanner::ACTIVE && $row->valid_until !== null && $row->valid_until < $now ? EntitlementPlanner::EXPIRED : $row->status;
            $key = $row->course_code.'|'.$row->feature_code;
            $cur = $out[$key] ?? null;
            if ($cur === null || $rank[$status] > $rank[$cur['status']]) {
                $out[$key] = ['course' => $row->course_code, 'feature' => $row->feature_code, 'status' => $status, 'valid_until' => $row->valid_until];
            } elseif ($status === $cur['status'] && $cur['valid_until'] !== null) {
                // Gleicher Status: spätestes Ende; dauerhaft (NULL) gewinnt.
                $out[$key]['valid_until'] = $row->valid_until === null ? null : max($cur['valid_until'], $row->valid_until);
            }
        }

        return array_values($out);
    }

    private function mustFind(int $id): object
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new \InvalidArgumentException(__('Zugang nicht gefunden.', 'bootspruefung-learning'));
        }

        return $row;
    }

    private static function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException(__('Bitte eine Begründung angeben.', 'bootspruefung-learning'));
        }

        return $reason;
    }

    public static function now(): string
    {
        return current_time('mysql', true);
    }
}
