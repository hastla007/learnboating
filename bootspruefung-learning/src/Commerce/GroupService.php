<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Frontend\Router;

/**
 * Gruppenlizenzen (z. B. ein Paar oder ein Verein kauft mehrere Plätze): Eine Bestellposition
 * eines Produkts mit „Plätzen“ ergibt ein Kontingent. Die verwaltende Person
 * lädt per Link oder E-Mail ein; jeder belegte Platz wird zu eigenen Zugängen
 * (Quelle "group", Referenz "group:<gruppe>:<mitglied>"). Zahlung, Dispute,
 * Erstattung und Storno wirken über die Gruppe auf alle Plätze.
 */
final class GroupService
{
    public const SOURCE = 'group';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';

    private string $groups;
    private string $members;
    private string $entitlements;

    public function __construct(private readonly \wpdb $db)
    {
        $this->groups = $db->prefix.'bp_learning_group';
        $this->members = $db->prefix.'bp_learning_group_member';
        $this->entitlements = $db->prefix.'bp_entitlement';
    }

    // ------------------------------------------------------------------ Bestellung → Gruppe

    /**
     * Gleicht die Gruppe einer Bestellposition mit dem Bestellstatus ab.
     *
     * @param list<object> $mappings wirksame Produktzuordnung (mit seats)
     * @param array{0:?string,1:string} $target Soll-Status der Bestellung
     */
    /**
     * Schreibfreie Entscheidung für eine Gruppenposition (Vorschau und syncItem).
     *
     * @return array{group:?object,per_unit:int,create:bool,status:?string,reason:string,seats:?int}|null
     */
    public function decide(OrderSnapshot $order, OrderItemSnapshot $item, array $mappings, array $target): ?array
    {
        $group = $this->byItem($item->id);
        $perUnit = ProductAccessRepository::seats($mappings) ?? ($group ? 0 : null);
        if ($perUnit === null) {
            return null;
        }
        [$status, $reason] = $target;
        $refundRule = $mappings[0]->refund_rule ?? 'revoke_on_full_refund';
        if ($status === EntitlementPlanner::ACTIVE && $refundRule !== 'keep'
            && (($refundRule === 'revoke_on_full_refund' && $item->fullyRefunded()) || ($refundRule === 'revoke_on_any_refund' && $item->anyRefund()))) {
            [$status, $reason] = [EntitlementPlanner::REVOKED, 'item_refunded'];
        }
        if ($status === EntitlementPlanner::REVOKED && $reason === 'order_refunded' && $refundRule === 'keep') {
            $status = null;
        }
        $seats = $perUnit > 0 ? $perUnit * max(0, $item->quantity - $item->refundedQuantity) : null;
        if ($group === null) {
            $create = $status === EntitlementPlanner::ACTIVE && $order->userId > 0;

            return ['group' => null, 'per_unit' => $perUnit, 'create' => $create, 'status' => $create ? self::ACTIVE : null, 'reason' => (string) $reason, 'seats' => $seats];
        }
        $decoded = json_decode((string) ($group->reason ?? ''), true);
        $manual = is_array($decoded) && ! empty($decoded['manual']);
        $keep = $status === null || $status === EntitlementPlanner::PENDING || $status === $group->status || $group->status === self::REVOKED || ($manual && $status !== EntitlementPlanner::REVOKED);

        return ['group' => $group, 'per_unit' => $perUnit, 'create' => false, 'status' => $keep ? null : $status, 'reason' => (string) $reason,
            'seats' => $seats !== null && $seats > 0 && $seats !== (int) $group->seats ? $seats : null];
    }

    public function syncItem(OrderSnapshot $order, OrderItemSnapshot $item, array $mappings, array $target): ?object
    {
        $decision = $this->decide($order, $item, $mappings, $target);
        if ($decision === null) {
            return null;
        }
        $group = $decision['group'];
        $perUnit = $decision['per_unit'];
        $status = $decision['status'];
        $reason = $decision['reason'];

        if ($group === null) {
            if (! $decision['create']) {
                return null;
            }
            $now = EntitlementService::now();
            $start = $order->datePaid ?? $now;
            $days = (int) ($mappings[0]->duration_days ?? 0);
            $scopes = array_map(static fn ($m) => ['course' => $m->course_code, 'feature' => $m->feature_code], $mappings);
            $user = get_userdata($order->userId);
            $suppress = $this->db->suppress_errors(true);
            $ok = $this->db->insert($this->groups, [
                'owner_user_id' => $order->userId,
                'name' => sprintf(__('Gruppe aus Bestellung #%d', 'bootspruefung-learning'), $order->id).($user ? ' ('.$user->display_name.')' : ''),
                'scopes' => wp_json_encode($scopes), 'seats' => max(0, $perUnit * max(0, $item->quantity - $item->refundedQuantity)),
                'status' => self::ACTIVE, 'valid_from' => $start, 'valid_until' => $days > 0 ? gmdate('Y-m-d H:i:s', strtotime($start.' UTC') + $days * DAY_IN_SECONDS) : null,
                'order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $item->productId,
                'join_token' => 'g'.bin2hex(random_bytes(16)), 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db->suppress_errors($suppress);
            if (! $ok) {
                return $this->byItem($item->id);
            }
            AuditLog::record('group_created', 'order', (string) $order->id, $reason, ['group' => (int) $this->db->insert_id, 'seats' => $perUnit * $item->quantity]);

            return $this->find((int) $this->db->insert_id);
        }

        // Plätze nach Teilerstattung anpassen; überzählige Plätze werden frei (offene Einladungen zuerst,
        // dann die zuletzt beigetretenen Mitglieder) – bezahlt ist nur die verbleibende Anzahl.
        if ($decision['seats'] !== null) {
            $this->db->update($this->groups, ['seats' => $decision['seats'], 'updated_at' => EntitlementService::now()], ['id' => $group->id]);
            AuditLog::record('group_seats_changed', 'group', (string) $group->id, $reason, ['from' => (int) $group->seats, 'to' => $decision['seats']]);
            $this->trimMembers((int) $group->id, (int) $decision['seats'], $reason);
        }
        if ($order->userId > 0 && (int) $group->owner_user_id !== $order->userId) {
            $this->db->update($this->groups, ['owner_user_id' => $order->userId], ['id' => $group->id]);
        }
        if ($status !== null) {
            $this->setStatus((int) $group->id, $status, $reason);
        }

        return $this->find((int) $group->id);
    }

    public function setStatus(int $groupId, string $status, string $reason, bool $manual = false): void
    {
        $group = $this->find($groupId);
        if ($group === null || ! in_array($status, [self::ACTIVE, self::SUSPENDED, self::REVOKED], true)) {
            return;
        }
        $this->db->update($this->groups, ['status' => $status, 'reason' => $manual ? wp_json_encode(['manual' => $reason]) : $reason, 'updated_at' => EntitlementService::now()], ['id' => $groupId]);
        AuditLog::record('group_'.$status, 'group', (string) $groupId, $reason, ['from' => $group->status, 'manual' => $manual]);
        $this->applyMembers($groupId);
    }

    /** Überträgt Gruppenstatus und Laufzeit auf die Zugänge aller aktiven Mitglieder. */
    public function applyMembers(int $groupId): void
    {
        $group = $this->find($groupId);
        if ($group === null) {
            return;
        }
        foreach ($this->membersOf($groupId) as $member) {
            if ($member->status !== 'active') {
                continue;
            }
            $this->db->query($this->db->prepare(
                "UPDATE {$this->entitlements} SET status = %s, valid_until = %s, updated_at = %s WHERE reference_key = %s AND status NOT IN ('revoked')",
                $group->status === self::ACTIVE ? 'active' : $group->status, $group->valid_until, EntitlementService::now(), self::reference($groupId, (int) $member->id)
            ));
        }
    }

    // ------------------------------------------------------------------ Mitglieder

    /**
     * Einladung per E-Mail (Platz wird reserviert).
     *
     * @param list<string> $emails
     * @return array{invited:int,skipped:list<string>}
     */
    public function invite(int $groupId, int $actorId, array $emails): array
    {
        $group = $this->managed($groupId, $actorId);
        $invited = 0;
        $skipped = [];
        foreach (array_unique(array_map('strtolower', array_map('trim', $emails))) as $email) {
            if (! is_email($email)) {
                $skipped[] = $email;
                continue;
            }
            $exists = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->members} WHERE group_id = %d AND email = %s AND status IN ('invited','active')", $groupId, $email));
            if ($exists || $this->freeSeats($group) <= 0) {
                $skipped[] = $email;
                continue;
            }
            $token = 'i'.bin2hex(random_bytes(16));
            $now = EntitlementService::now();
            $this->db->insert($this->members, ['group_id' => $groupId, 'email' => $email, 'status' => 'invited', 'invite_token' => $token, 'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $invited++;
            $group = $this->find($groupId);
            /* translators: 1: group name, 2: link */
            wp_mail($email, sprintf(__('Einladung zum Lernzugang: %s', 'bootspruefung-learning'), $group->name),
                sprintf(__("Du wurdest zu „%1\$s“ eingeladen.\n\nZugang annehmen: %2\$s\n\nFalls du noch kein Konto hast, kannst du dich dort registrieren.", 'bootspruefung-learning'), $group->name, self::joinUrl($token)));
        }
        AuditLog::record('group_invited', 'group', (string) $groupId, null, ['count' => $invited, 'skipped' => count($skipped)]);

        return ['invited' => $invited, 'skipped' => $skipped];
    }

    /** Infos zum Beitrittslink (Gruppen- oder persönlicher Token). @return array<string,mixed>|null */
    public function describeToken(string $token): ?array
    {
        [$group, $member] = $this->resolveToken($token);
        if ($group === null) {
            return null;
        }
        $owner = get_userdata((int) $group->owner_user_id);

        return [
            'name' => $group->name,
            'owner' => $owner ? $owner->display_name : '',
            'scopes' => array_map(static fn ($s) => ['course' => Labels::course($s['course']), 'feature' => Labels::feature($s['feature'])], self::scopes($group)),
            'free_seats' => $this->freeSeats($group) + ($member !== null ? 1 : 0),
            'status' => $group->status,
            'valid_until' => $group->valid_until,
            'personal' => $member !== null,
        ];
    }

    /** Beitritt mit Gruppen- oder persönlichem Token. */
    public function join(string $token, int $userId): object
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException(__('Bitte zuerst anmelden.', 'bootspruefung-learning'));
        }
        [$group, $invite] = $this->resolveToken($token);
        if ($group === null) {
            throw new \InvalidArgumentException(__('Dieser Einladungslink ist ungültig oder abgelaufen.', 'bootspruefung-learning'));
        }
        if ($group->status !== self::ACTIVE || ($group->valid_until !== null && $group->valid_until < EntitlementService::now())) {
            throw new \InvalidArgumentException(__('Diese Gruppe ist derzeit nicht aktiv.', 'bootspruefung-learning'));
        }
        $existing = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->members} WHERE group_id = %d AND user_id = %d AND status = 'active'", $group->id, $userId));
        if ($existing) {
            return $existing;
        }
        $now = EntitlementService::now();
        if ($invite !== null) {
            $this->db->update($this->members, ['user_id' => $userId, 'status' => 'active', 'invite_token' => null, 'joined_at' => $now, 'updated_at' => $now], ['id' => $invite->id]);
            $memberId = (int) $invite->id;
        } else {
            if ($this->freeSeats($group) <= 0) {
                throw new \InvalidArgumentException(__('Alle Plätze dieser Gruppe sind belegt.', 'bootspruefung-learning'));
            }
            $user = get_userdata($userId);
            $this->db->insert($this->members, ['group_id' => $group->id, 'user_id' => $userId, 'email' => $user ? strtolower($user->user_email) : null, 'status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $memberId = (int) $this->db->insert_id;
        }
        $this->grantMember($group, $memberId, $userId);
        AuditLog::record('group_joined', 'group', (string) $group->id, null, ['member' => $memberId, 'user_id' => $userId]);

        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->members} WHERE id = %d", $memberId));
    }

    /** Platz freigeben: Zugänge des Mitglieds widerrufen. */
    public function removeMember(int $groupId, int $memberId, int $actorId, string $reason): void
    {
        $this->managed($groupId, $actorId);
        $member = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->members} WHERE id = %d AND group_id = %d", $memberId, $groupId));
        if (! $member || $member->status === 'removed') {
            throw new \InvalidArgumentException(__('Mitglied nicht gefunden.', 'bootspruefung-learning'));
        }
        $now = EntitlementService::now();
        $this->db->update($this->members, ['status' => 'removed', 'invite_token' => null, 'removed_at' => $now, 'updated_at' => $now], ['id' => $memberId]);
        $this->db->query($this->db->prepare(
            "UPDATE {$this->entitlements} SET status = 'revoked', reason = %s, updated_at = %s WHERE reference_key = %s",
            $reason !== '' ? $reason : 'group_member_removed', $now, self::reference($groupId, $memberId)
        ));
        AuditLog::record('group_member_removed', 'group', (string) $groupId, $reason, ['member' => $memberId, 'user_id' => $member->user_id]);
    }

    /** Mitglieder über der Platzzahl entfernen (Einladungen zuerst, dann zuletzt Beigetretene). */
    private function trimMembers(int $groupId, int $seats, string $reason): void
    {
        $members = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->members} WHERE group_id = %d AND status IN ('active','invited') ORDER BY (status = 'active'), id DESC",
            $groupId
        ));
        $surplus = count($members) - max(0, $seats);
        $now = EntitlementService::now();
        foreach (array_slice($members, 0, max(0, $surplus)) as $member) {
            $this->db->update($this->members, ['status' => 'removed', 'invite_token' => null, 'removed_at' => $now, 'updated_at' => $now], ['id' => (int) $member->id]);
            $this->db->query($this->db->prepare(
                "UPDATE {$this->entitlements} SET status = 'revoked', reason = %s, updated_at = %s WHERE reference_key = %s",
                'group_seats_reduced', $now, self::reference($groupId, (int) $member->id)
            ));
            AuditLog::record('group_member_removed', 'group', (string) $groupId, $reason, ['member' => (int) $member->id, 'user_id' => $member->user_id, 'cause' => 'seats_reduced']);
        }
    }

    /** Neuer Gruppenlink (alter Link wird ungültig). */
    public function regenerateToken(int $groupId, int $actorId): string
    {
        $this->managed($groupId, $actorId);
        $token = 'g'.bin2hex(random_bytes(16));
        $this->db->update($this->groups, ['join_token' => $token, 'updated_at' => EntitlementService::now()], ['id' => $groupId]);

        return $token;
    }

    public function rename(int $groupId, int $actorId, string $name): void
    {
        $this->managed($groupId, $actorId);
        $name = trim($name);
        if ($name !== '') {
            $this->db->update($this->groups, ['name' => mb_substr($name, 0, 190)], ['id' => $groupId]);
        }
    }

    private function grantMember(object $group, int $memberId, int $userId): void
    {
        $now = EntitlementService::now();
        $reference = self::reference((int) $group->id, $memberId);
        foreach (self::scopes($group) as $scope) {
            $suppress = $this->db->suppress_errors(true);
            $ok = $this->db->insert($this->entitlements, [
                'user_id' => $userId, 'course_code' => $scope['course'], 'feature_code' => $scope['feature'], 'status' => 'active',
                'valid_from' => $now, 'valid_until' => $group->valid_until, 'source' => self::SOURCE, 'reference_key' => $reference,
                'order_id' => $group->order_id, 'product_id' => $group->product_id, 'reason' => 'group_joined', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db->suppress_errors($suppress);
            if (! $ok) {
                // Wieder beigetreten: bestehende Zeile reaktivieren.
                $this->db->query($this->db->prepare(
                    "UPDATE {$this->entitlements} SET user_id = %d, status = 'active', valid_until = %s, updated_at = %s WHERE reference_key = %s AND course_code = %s AND feature_code = %s",
                    $userId, $group->valid_until, $now, $reference, $scope['course'], $scope['feature']
                ));
            }
        }
    }

    // ------------------------------------------------------------------ Abfragen

    public function find(int $id): ?object
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->groups} WHERE id = %d", $id)) ?: null;
    }

    public function byItem(int $itemId): ?object
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->groups} WHERE order_item_id = %d", $itemId)) ?: null;
    }

    /** @return list<object> */
    public function forOwner(int $userId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->groups} WHERE owner_user_id = %d ORDER BY id DESC", $userId));
    }

    /** @return list<object> */
    public function forOrder(int $orderId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->groups} WHERE order_id = %d ORDER BY id", $orderId));
    }

    /** @return list<object> */
    public function all(int $page = 1, int $perPage = 50, string $search = ''): array
    {
        $where = '1=1';
        $args = [];
        if ($search !== '') {
            $like = '%'.$this->db->esc_like($search).'%';
            $where = "(g.name LIKE %s OR u.user_email LIKE %s OR g.order_id = %d)";
            $args = [$like, $like, (int) $search];
        }
        $sql = "SELECT g.*, u.user_email, u.display_name, (SELECT COUNT(*) FROM {$this->members} m WHERE m.group_id = g.id AND m.status IN ('active','invited')) AS used
                FROM {$this->groups} g LEFT JOIN {$this->db->users} u ON u.ID = g.owner_user_id WHERE {$where} ORDER BY g.id DESC LIMIT %d OFFSET %d";

        return $this->db->get_results($this->db->prepare($sql, ...array_merge($args, [$perPage, ($page - 1) * $perPage])));
    }

    /** @return list<object> */
    public function membersOf(int $groupId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT m.*, u.display_name FROM {$this->members} m LEFT JOIN {$this->db->users} u ON u.ID = m.user_id WHERE m.group_id = %d ORDER BY (m.status = 'removed'), m.id",
            $groupId
        ));
    }

    public function freeSeats(object $group): int
    {
        $used = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->members} WHERE group_id = %d AND status IN ('active','invited')", $group->id));

        return max(0, (int) $group->seats - $used);
    }

    /** @return list<array{course:string,feature:string}> */
    public static function scopes(object $group): array
    {
        $scopes = json_decode((string) $group->scopes, true);

        return is_array($scopes) ? $scopes : [];
    }

    public static function reference(int $groupId, int $memberId): string
    {
        return 'group:'.$groupId.':'.$memberId;
    }

    public static function joinUrl(string $token): string
    {
        return Router::url('gruppe/'.$token);
    }

    /** @return array{0:?object,1:?object} [Gruppe, persönliche Einladung] */
    private function resolveToken(string $token): array
    {
        if (! preg_match('/^[gi][0-9a-f]{32}$/', $token)) {
            return [null, null];
        }
        if ($token[0] === 'i') {
            $member = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->members} WHERE invite_token = %s AND status = 'invited'", $token));

            return $member ? [$this->find((int) $member->group_id), $member] : [null, null];
        }

        return [$this->db->get_row($this->db->prepare("SELECT * FROM {$this->groups} WHERE join_token = %s", $token)) ?: null, null];
    }

    /** Verwalten dürfen Besitzer und Admins (manage_bp_entitlements). */
    private function managed(int $groupId, int $actorId): object
    {
        $group = $this->find($groupId);
        if ($group === null || ((int) $group->owner_user_id !== $actorId && ! user_can($actorId, \Bootspruefung\Learning\Security\Capabilities::MANAGE_ENTITLEMENTS))) {
            throw new \InvalidArgumentException(__('Gruppe nicht gefunden.', 'bootspruefung-learning'));
        }

        return $group;
    }
}
