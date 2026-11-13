<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Gruppenlizenzen (z. B. ein Paar oder ein Verein kauft mehrere Plätze): Eine Bestellposition
 * eines Produkts mit „Plätzen“ ergibt ein Kontingent. Die verwaltende Person
 * lädt per Link oder E-Mail ein; jeder belegte Platz wird zu eigenen Zugängen
 * (Quelle "group", Referenz "group:<gruppe>:<mitglied>"). Zahlung, Dispute,
 * Erstattung und Storno wirken über die Gruppe auf alle Plätze.
 */
final class GroupService
{
    /** Einladungen je Gruppe und Tag (mindestens; bei großen Gruppen 2 × Plätze). */
    public const INVITE_LIMIT = 20;
    /** Gültigkeit persönlicher Einladungslinks in Tagen (danach belegt die Einladung keinen Platz mehr). */
    public const INVITE_DAYS = 30;
    /** Tage nach Ablauf einer Einladung, bis ihre E-Mail-Adresse gelöscht wird. */
    public const EXPIRED_RETENTION_DAYS = 30;

    public const SOURCE = 'group';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';
    /** Mitgliedsstatus einer nicht angenommenen, abgelaufenen Einladung. */
    public const INVITE_EXPIRED = 'expired';

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
        // Widerrufen bleibt widerrufen – außer ein automatischer Widerruf (Storno/Erstattung der Bestellung) und die
        // Bestellung ist wieder bezahlt, die Position nicht mehr erstattet (wie EntitlementPlanner, QA-11). Manuelle
        // Widerrufe (Admin, verlorener Zahlungskonflikt) und Erstattungen ohne Positionsbezug leben nicht wieder auf.
        $revive = $group->status === self::REVOKED && $status === EntitlementPlanner::ACTIVE && ! $manual && $order->unallocatedRefund <= 0.009;
        $keep = $status === null || $status === EntitlementPlanner::PENDING || $status === $group->status || ($group->status === self::REVOKED && ! $revive) || ($manual && $status !== EntitlementPlanner::REVOKED);

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
                // Neutraler Name (erscheint in Einladungsmails): Produkt, nicht Bestellnummer/Käufername.
                'name' => (function_exists('wc_get_product') && ($product = wc_get_product($item->productId))) ? mb_substr(wp_specialchars_decode($product->get_name()), 0, 60) : __('Lerngruppe', 'bootspruefung-learning'),
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
            // Aktive Gruppe: auch Zugänge aktiver Mitglieder, die mit der Gruppe widerrufen wurden, wieder freigeben
            // (wiederbelebte Gruppe); entfernte Mitglieder (Platz freigegeben) sind oben ausgeschlossen.
            $this->db->query($this->db->prepare(
                "UPDATE {$this->entitlements} SET status = %s, valid_until = %s, updated_at = %s WHERE reference_key = %s".($group->status === self::ACTIVE ? '' : " AND status NOT IN ('revoked')"),
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
        $budget = $this->inviteBudget($group);
        $inviter = $this->inviterName($group, $actorId);
        $invited = 0;
        $skipped = [];
        foreach (array_unique(array_map('strtolower', array_map('trim', $emails))) as $email) {
            if (! is_email($email)) {
                $skipped[] = $email;
                continue;
            }
            $exists = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->members} m WHERE group_id = %d AND email = %s AND ".self::occupying('m'), $groupId, $email, self::inviteCutoff()));
            if ($exists || $this->freeSeats($group) <= 0 || $invited >= $budget) {
                $skipped[] = $email;
                continue;
            }
            $token = 'i'.bin2hex(random_bytes(16));
            $now = EntitlementService::now();
            $this->db->insert($this->members, ['group_id' => $groupId, 'email' => $email, 'status' => 'invited', 'invite_token' => $token, 'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $invited++;
            $group = $this->find($groupId);
            \Bootspruefung\Learning\Support\Mail::send($email, sprintf(__('Einladung zum Lernzugang: %s', 'bootspruefung-learning'), $group->name), $this->inviteMail($group, $inviter, $token, $now));
        }
        AuditLog::record('group_invited', 'group', (string) $groupId, null, ['count' => $invited, 'skipped' => count($skipped)]);

        return ['invited' => $invited, 'skipped' => $skipped];
    }

    /**
     * Schutz vor Missbrauch als Mail-Relay: höchstens INVITE_LIMIT Einladungen je Gruppe und Tag
     * (bei großen Gruppen 2 × Plätze). @return int heute noch mögliche Einladungen
     */
    public function inviteBudget(object $group): int
    {
        $sentToday = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->members} WHERE group_id = %d AND invited_at >= %s",
            (int) $group->id, gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));

        return max(0, max(self::INVITE_LIMIT, 2 * (int) $group->seats) - $sentToday);
    }

    /**
     * Gruppe und ggf. persönliche Einladung zu einem Beitrittslink (für Vorschauen, z. B. der App).
     * @return array{0:?object,1:?object} [Gruppe, persönliche Einladung]
     */
    public function resolve(string $token): array
    {
        return $this->resolveToken($token);
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
            // Bedingt annehmen: Nur eine gleichzeitige Anfrage gewinnt, abgelaufene/vergebene Einladungen nicht.
            $updated = $this->db->query($this->db->prepare(
                "UPDATE {$this->members} SET user_id = %d, status = 'active', invite_token = NULL, joined_at = %s, updated_at = %s
                 WHERE id = %d AND status = 'invited' AND (invited_at IS NULL OR invited_at >= %s)",
                $userId, $now, $now, (int) $invite->id, self::inviteCutoff()
            ));
            if ((int) $updated !== 1) {
                throw new \InvalidArgumentException(__('Diese Einladung ist abgelaufen oder wurde bereits angenommen.', 'bootspruefung-learning'));
            }
            $memberId = (int) $invite->id;
        } else {
            if ($this->freeSeats($group) <= 0) {
                throw new \InvalidArgumentException(__('Alle Plätze dieser Gruppe sind belegt.', 'bootspruefung-learning'));
            }
            $user = get_userdata($userId);
            $this->db->insert($this->members, ['group_id' => $group->id, 'user_id' => $userId, 'email' => $user ? strtolower($user->user_email) : null, 'status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $memberId = (int) $this->db->insert_id;
            // Gleichzeitige Beitritte: Plätze nach dem Eintragen neu zählen; belegt sind die ältesten Zeilen.
            $before = (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->members} m WHERE group_id = %d AND id < %d AND ".self::occupying('m'),
                $group->id, $memberId, self::inviteCutoff()
            ));
            if ($before >= (int) $group->seats) {
                $this->db->delete($this->members, ['id' => $memberId]);
                throw new \InvalidArgumentException(__('Alle Plätze dieser Gruppe sind belegt.', 'bootspruefung-learning'));
            }
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
        $this->db->update($this->members, self::removedFields($member, $now), ['id' => $memberId]);
        $this->db->query($this->db->prepare(
            "UPDATE {$this->entitlements} SET status = 'revoked', reason = %s, updated_at = %s WHERE reference_key = %s",
            $reason !== '' ? $reason : 'group_member_removed', $now, self::reference($groupId, $memberId)
        ));
        AuditLog::record('group_member_removed', 'group', (string) $groupId, $reason, ['member' => $memberId, 'user_id' => $member->user_id]);
    }

    /**
     * Konto gelöscht (QA-05): Plätze des Kontos in fremden Gruppen freigeben (wie „Platz freigeben“, Zugänge der
     * Plätze widerrufen) und Gruppen, die das Konto gekauft hat, vom Konto lösen. Eigene Gruppen bleiben als Teil
     * der Bestellung bestehen: Mitglieder behalten ihre Plätze bis zum Ende der Laufzeit, offene Einladungen und
     * der Gruppenlink gelten weiter; verwalten kann sie danach nur noch der Betreiber.
     *
     * @return array{released:int,owned:int}
     */
    public function releaseUser(int $userId): array
    {
        if ($userId <= 0) {
            return ['released' => 0, 'owned' => 0];
        }
        $now = EntitlementService::now();
        $members = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->members} WHERE user_id = %d AND status IN ('active', 'invited')", $userId));
        foreach ($members as $member) {
            // Konto gibt es nicht mehr: auch die Adresse entfernen (removedFields() behält sie nur bei bestehendem Konto).
            $this->db->update($this->members, ['email' => null] + self::removedFields($member, $now), ['id' => (int) $member->id]);
            $this->db->query($this->db->prepare(
                "UPDATE {$this->entitlements} SET status = 'revoked', reason = %s, updated_at = %s WHERE reference_key = %s",
                'account_deleted', $now, self::reference((int) $member->group_id, (int) $member->id)
            ));
            AuditLog::record('group_member_removed', 'group', (string) $member->group_id, 'account_deleted', ['member' => (int) $member->id, 'cause' => 'account_deleted']);
        }
        $owned = $this->db->get_col($this->db->prepare("SELECT id FROM {$this->groups} WHERE owner_user_id = %d", $userId));
        if ($owned !== []) {
            $this->db->query($this->db->prepare("UPDATE {$this->groups} SET owner_user_id = 0, updated_at = %s WHERE owner_user_id = %d", $now, $userId));
            foreach ($owned as $groupId) {
                AuditLog::record('group_owner_deleted', 'group', (string) $groupId, 'account_deleted');
            }
        }

        return ['released' => count($members), 'owned' => count($owned)];
    }

    /** Mitglieder über der Platzzahl entfernen (Einladungen zuerst, dann zuletzt Beigetretene). */
    private function trimMembers(int $groupId, int $seats, string $reason): void
    {
        $members = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->members} m WHERE group_id = %d AND ".self::occupying('m')." ORDER BY (status = 'active'), COALESCE(joined_at, invited_at) DESC, id DESC",
            $groupId, self::inviteCutoff()
        ));
        $surplus = count($members) - max(0, $seats);
        $now = EntitlementService::now();
        foreach (array_slice($members, 0, max(0, $surplus)) as $member) {
            $this->db->update($this->members, self::removedFields($member, $now), ['id' => (int) $member->id]);
            $this->db->query($this->db->prepare(
                "UPDATE {$this->entitlements} SET status = 'revoked', reason = %s, updated_at = %s WHERE reference_key = %s",
                'group_seats_reduced', $now, self::reference($groupId, (int) $member->id)
            ));
            AuditLog::record('group_member_removed', 'group', (string) $groupId, $reason, ['member' => (int) $member->id, 'user_id' => $member->user_id, 'cause' => 'seats_reduced']);
        }
    }

    /**
     * Felder für „Platz freigegeben“. Nie angenommene Einladungen (ohne Konto) verlieren
     * sofort ihre E-Mail-Adresse – sie wird für nichts mehr gebraucht.
     *
     * @return array<string,mixed>
     */
    private static function removedFields(object $member, string $now): array
    {
        $fields = ['status' => 'removed', 'invite_token' => null, 'removed_at' => $now, 'updated_at' => $now];
        if ($member->user_id === null || (int) $member->user_id === 0) {
            $fields['email'] = null;
        }

        return $fields;
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
        $name = self::cleanName($name);
        if ($name !== '') {
            $this->db->update($this->groups, ['name' => $name], ['id' => $groupId]);
        }
    }

    /** Namen für Einladungsmails: keine Links/Adressen/Domains, kein HTML, höchstens $max Zeichen. */
    public static function cleanName(string $name, int $max = 60): string
    {
        $name = trim((string) preg_replace('~(https?://|www\.)\S*|\S+@\S+|\S+\.(de|com|net|org|info|io|ru|xyz)\b~iu', '', wp_strip_all_tags($name)));
        // Weitere Domains jeder Endung (z. B. „gewinn.top“, „login-seite.shop“), T49. Großgeschriebene Endungen wie
        // in „St.Peter“ oder „e.V.“ bleiben.
        $name = trim((string) preg_replace('~[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)*\.[a-z]{2,24}\b~u', '', $name));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return trim(mb_substr($name, 0, $max));
    }

    /**
     * Wer in der Einladungsmail als Einladende genannt wird: Lädt Personal in eine fremde
     * Gruppe ein, der Shop; sonst ein selbst gewählter Anzeigename (nicht Login, nicht der
     * lokale Teil der E-Mail-Adresse), ersatzweise Vor- und Nachname, sonst niemand.
     */
    private function inviterName(object $group, int $actorId): string
    {
        if ($actorId !== (int) $group->owner_user_id) {
            // Vom Betreiber gesetzt: Domain im Markennamen (z. B. „Bootsschein.de“) bleibt erlaubt.
            return trim(mb_substr(wp_strip_all_tags(Settings::brand()), 0, 60));
        }
        $actor = get_userdata($actorId);
        if (! $actor) {
            return '';
        }
        $display = trim((string) $actor->display_name);
        $local = strtolower((string) strstr((string) $actor->user_email, '@', true));
        if ($display !== '' && strcasecmp($display, (string) $actor->user_login) !== 0 && strtolower($display) !== $local) {
            $name = self::cleanName($display);
            if ($name !== '') {
                return $name;
            }
        }

        return self::cleanName(trim($actor->first_name.' '.$actor->last_name));
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
        $sql = "SELECT g.*, u.user_email, u.display_name, (SELECT COUNT(*) FROM {$this->members} m WHERE m.group_id = g.id AND ".self::occupying('m').") AS used
                FROM {$this->groups} g LEFT JOIN {$this->db->users} u ON u.ID = g.owner_user_id WHERE {$where} ORDER BY g.id DESC LIMIT %d OFFSET %d";

        return $this->db->get_results($this->db->prepare($sql, ...array_merge([self::inviteCutoff()], $args, [$perPage, ($page - 1) * $perPage])));
    }

    /** @return list<object> */
    public function membersOf(int $groupId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT m.*, u.display_name FROM {$this->members} m LEFT JOIN {$this->db->users} u ON u.ID = m.user_id WHERE m.group_id = %d ORDER BY (m.status = 'removed'), m.id",
            $groupId
        ));
    }

    /**
     * SQL-Bedingung „belegt einen Platz“: aktive Mitglieder und Einladungen, die
     * jünger als INVITE_DAYS sind. Erwartet einen Platzhalter %s für inviteCutoff().
     */
    public static function occupying(string $alias): string
    {
        return "({$alias}.status = 'active' OR ({$alias}.status = 'invited' AND ({$alias}.invited_at IS NULL OR {$alias}.invited_at >= %s)))";
    }

    /** Einladungen vor diesem Zeitpunkt (UTC) sind abgelaufen. */
    public static function inviteCutoff(?int $now = null): string
    {
        return gmdate('Y-m-d H:i:s', ($now ?? time()) - self::INVITE_DAYS * DAY_IN_SECONDS);
    }

    /** Ablaufzeitpunkt (UTC, MySQL-Format) einer Einladung. */
    public static function inviteExpiresAt(string $invitedAt): string
    {
        return gmdate('Y-m-d H:i:s', (int) strtotime($invitedAt.' UTC') + self::INVITE_DAYS * DAY_IN_SECONDS);
    }

    /**
     * Stündlicher Cron (bp_learning_hourly): abgelaufene Einladungen auf „expired“
     * setzen (Link ungültig, kein Platz belegt) und EXPIRED_RETENTION_DAYS Tage
     * nach Ablauf die E-Mail-Adresse der nicht angenommenen Einladung löschen.
     * Entfernte Einladungen ohne Konto verlieren ihre Adresse sofort (Altfälle hier).
     * $groupId beschränkt den Lauf auf eine Gruppe (Tests).
     *
     * @return array{expired:int,anonymized:int}
     */
    public function expireInvites(?int $now = null, ?int $groupId = null): array
    {
        $now ??= time();
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $scope = $groupId !== null ? $this->db->prepare(' AND group_id = %d', $groupId) : '';
        $expired = (int) $this->db->query($this->db->prepare(
            "UPDATE {$this->members} SET status = %s, invite_token = NULL, updated_at = %s WHERE status = 'invited' AND invited_at IS NOT NULL AND invited_at < %s",
            self::INVITE_EXPIRED, $stamp, self::inviteCutoff($now)
        ).$scope);
        $anonymized = (int) $this->db->query($this->db->prepare(
            "UPDATE {$this->members} SET email = NULL, invite_token = NULL, updated_at = %s WHERE user_id IS NULL AND email IS NOT NULL
             AND (status = 'removed' OR (status = %s AND invited_at IS NOT NULL AND invited_at < %s))",
            $stamp, self::INVITE_EXPIRED, gmdate('Y-m-d H:i:s', $now - (self::INVITE_DAYS + self::EXPIRED_RETENTION_DAYS) * DAY_IN_SECONDS)
        ).$scope);
        if ($expired > 0 || $anonymized > 0) {
            AuditLog::record('group_invites_expired', 'group', '0', null, ['expired' => $expired, 'anonymized' => $anonymized]);
        }

        return ['expired' => $expired, 'anonymized' => $anonymized];
    }

    /** Text der Einladungsmail (Art. 14 DSGVO: wer lädt ein, wozu, wie lange, Datenschutzerklärung). */
    private function inviteMail(object $group, string $inviter, string $token, string $invitedAt): string
    {
        $expires = (int) strtotime(self::inviteExpiresAt($invitedAt).' UTC');
        /* translators: 1: date, 2: time */
        $until = sprintf(__('%1$s, %2$s Uhr', 'bootspruefung-learning'), wp_date(get_option('date_format'), $expires), wp_date('H:i', $expires));
        $text = $inviter !== ''
            /* translators: 1: inviting person, 2: group name */
            ? sprintf(__('%1$s hat dich zur Lerngruppe „%2$s“ eingeladen.', 'bootspruefung-learning'), $inviter, $group->name)
            /* translators: %s: group name */
            : sprintf(__('Du wurdest zur Lerngruppe „%s“ eingeladen.', 'bootspruefung-learning'), $group->name);
        /* translators: 1: link, 2: date and time */
        $text .= "\n\n".sprintf(__("Zugang annehmen: %1\$s\nDie Einladung gilt bis %2\$s.\n\nFalls du noch kein Konto hast, kannst du dich dort registrieren.", 'bootspruefung-learning'), self::joinUrl($token), $until);
        /* translators: %d: days */
        $text .= "\n\n".sprintf(__('Deine E-Mail-Adresse wurde uns von der Person angegeben, die die Gruppe verwaltet, und wird nur für diese Einladung verwendet. Nimmst du die Einladung nicht an, löschen wir sie spätestens %d Tage nach Ablauf.', 'bootspruefung-learning'), self::EXPIRED_RETENTION_DAYS);
        $privacy = function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '';
        if ($privacy !== '') {
            /* translators: %s: link */
            $text .= "\n".sprintf(__('Datenschutzerklärung: %s', 'bootspruefung-learning'), $privacy);
        }

        return $text;
    }

    public function freeSeats(object $group): int
    {
        $used = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->members} m WHERE group_id = %d AND ".self::occupying('m'), $group->id, self::inviteCutoff()));

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
            // Persönliche Einladungen gelten INVITE_DAYS Tage.
            $member = $this->db->get_row($this->db->prepare(
                "SELECT * FROM {$this->members} WHERE invite_token = %s AND status = 'invited' AND (invited_at IS NULL OR invited_at >= %s)",
                $token, self::inviteCutoff()
            ));

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
