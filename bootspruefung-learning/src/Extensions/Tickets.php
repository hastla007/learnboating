<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Extensions;

/**
 * Gegenstelle für ein Ticket-Plugin (Praxis-Termine, docs/schnittstellen-erweiterungen.md).
 *
 * Das Ticket-Plugin meldet Ausgabe, Entwertung und Storno; das Lern-Plugin führt je Konto eine Liste in der
 * Nutzer-Meta _bp_tickets (id, Kurs, Titel, Einheiten gesamt/übrig, Status issued/redeemed/cancelled, Datum,
 * Ort, Grund bei Storno) und zeigt sie im Kurs-Hub und in der App (GET /me/tickets). Gespeichert werden keine
 * personenbezogenen Daten Dritter: Die Kennung des Scanners (Mitarbeiter, Gerät) wird nicht übernommen.
 * Zum Wiederfinden eines Tickets ohne Nutzer-ID (Entwertung, Storno) dient je Ticket ein Eintrag _bp_ticket_id.
 *
 * Die Anzeige ist kein amtlicher Ausbildungsnachweis.
 */
final class Tickets
{
    public const META = '_bp_tickets';
    public const LOOKUP_META = '_bp_ticket_id';
    public const STATUSES = ['issued', 'redeemed', 'cancelled'];
    /** Höchstzahl gespeicherter Tickets je Konto (älteste fallen heraus). */
    public const MAX = 100;

    public static function register(): void
    {
        add_action('bootspruefung_ticket_issued', [self::class, 'issued'], 10, 4);
        add_action('bootspruefung_ticket_redeemed', [self::class, 'redeemed'], 10, 3);
        add_action('bootspruefung_ticket_cancelled', [self::class, 'cancelled'], 10, 2);
    }

    /** @param mixed $args course_code, title, units, date, location */
    public static function issued($ticketId, $orderId = 0, $userId = 0, $args = []): void
    {
        $id = self::id($ticketId);
        $userId = (int) $userId;
        if ($id === '' || $userId <= 0 || ! get_userdata($userId)) {
            return;
        }
        $args = is_array($args) ? $args : [];
        $list = self::stored($userId);
        $units = max(1, min(999, (int) ($args['units'] ?? 1)));
        $old = $list[$id] ?? null;
        $done = $old !== null ? max(0, (int) $old['units_total'] - (int) $old['units_left']) : 0;
        $list[$id] = [
            'id' => $id,
            'order_id' => max(0, (int) $orderId) ?: null,
            'course_code' => self::course($args['course_code'] ?? ''),
            'title' => self::text($args['title'] ?? '', 200),
            'units_total' => $units,
            'units_left' => max(0, $units - $done),
            'status' => $old !== null && $old['status'] === 'cancelled' ? 'cancelled' : ($units - $done <= 0 ? 'redeemed' : 'issued'),
            'date' => self::text($args['date'] ?? '', 40) ?: null,
            'location' => self::text($args['location'] ?? '', 200) ?: null,
            'reason' => $old['reason'] ?? null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        self::save($userId, $list);
        $known = array_map('strval', (array) get_user_meta($userId, self::LOOKUP_META, false));
        if (! in_array($id, $known, true)) {
            add_user_meta($userId, self::LOOKUP_META, $id);
        }
    }

    /** Entwertung (ganz oder eine Einheit): Kennung des Scanners wird nicht gespeichert. */
    public static function redeemed($ticketId, $unitsLeft = 0, $scannerId = null): void
    {
        self::change(self::id($ticketId), static function (array $t) use ($unitsLeft): array {
            $t['units_left'] = max(0, min((int) $t['units_total'], (int) $unitsLeft));
            if ($t['status'] !== 'cancelled') {
                $t['status'] = $t['units_left'] === 0 ? 'redeemed' : 'issued';
            }

            return $t;
        });
    }

    public static function cancelled($ticketId, $reason = ''): void
    {
        self::change(self::id($ticketId), static function (array $t) use ($reason): array {
            $t['status'] = 'cancelled';
            $t['reason'] = self::text($reason, 200) ?: null;

            return $t;
        });
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $fn */
    private static function change(string $id, callable $fn): void
    {
        if ($id === '') {
            return;
        }
        foreach (get_users(['meta_key' => self::LOOKUP_META, 'meta_value' => $id, 'fields' => 'ID', 'number' => 5]) as $userId) {
            $list = self::stored((int) $userId);
            if (! isset($list[$id])) {
                continue;
            }
            $list[$id] = ['updated_at' => gmdate('Y-m-d H:i:s')] + $fn($list[$id]);
            self::save((int) $userId, $list);
        }
    }

    /**
     * Tickets eines Kontos: zuerst das Ticket-Plugin (Filter bootspruefung_tickets_for_user), sonst die gespeicherte Liste.
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        /**
         * Aktuelle Tickets eines Kontos aus dem Ticket-Plugin (null = gespeicherte Liste des Lern-Plugins nutzen).
         *
         * @param null|list<array> $tickets je Ticket: id, course_code, title, units_total, units_left, status, date, location, reason
         * @param int              $userId
         */
        $external = apply_filters('bootspruefung_tickets_for_user', null, $userId);
        $list = is_array($external) ? $external : self::stored($userId);
        $out = [];
        foreach ($list as $raw) {
            if (is_array($raw) && ($t = self::normalize($raw)) !== null) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> Tickets eines Kurses (Kurs-Hub). */
    public static function forCourse(int $userId, string $courseCode): array
    {
        $course = self::course($courseCode);

        return array_values(array_filter(self::forUser($userId), static fn ($t) => $t['course_code'] === $course));
    }

    /** @return array<string,array<string,mixed>> */
    public static function stored(int $userId): array
    {
        wp_cache_delete($userId, 'user_meta');
        $raw = get_user_meta($userId, self::META, true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $t) {
            if (is_array($t) && isset($t['id']) && ($n = self::normalize($t)) !== null) {
                $out[$n['id']] = $n + ['order_id' => isset($t['order_id']) ? ((int) $t['order_id'] ?: null) : null, 'updated_at' => (string) ($t['updated_at'] ?? '')];
            }
        }

        return $out;
    }

    /** @param array<string,array<string,mixed>> $list */
    private static function save(int $userId, array $list): void
    {
        if (count($list) > self::MAX) {
            $list = array_slice($list, -self::MAX, null, true);
        }
        update_user_meta($userId, self::META, array_values($list));
    }

    /**
     * Einheitliche Form (auch für Daten aus dem Filter).
     *
     * @param array<string,mixed> $t
     * @return array{id:string,course_code:string,title:string,units_total:int,units_left:int,units_done:int,status:string,date:?string,location:?string,reason:?string}|null
     */
    public static function normalize(array $t): ?array
    {
        $id = self::id($t['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $total = max(1, (int) ($t['units_total'] ?? $t['units'] ?? 1));
        $left = max(0, min($total, (int) ($t['units_left'] ?? $total)));
        $status = in_array($t['status'] ?? '', self::STATUSES, true) ? (string) $t['status'] : 'issued';

        return [
            'id' => $id,
            'course_code' => self::course($t['course_code'] ?? ''),
            'title' => self::text($t['title'] ?? '', 200),
            'units_total' => $total,
            'units_left' => $left,
            'units_done' => $total - $left,
            'status' => $status,
            'date' => self::text($t['date'] ?? '', 40) ?: null,
            'location' => self::text($t['location'] ?? '', 200) ?: null,
            'reason' => $status === 'cancelled' ? (self::text($t['reason'] ?? '', 200) ?: null) : null,
        ];
    }

    /** Datenschutz-Export (Art. 15/20 DSGVO). @return list<array<string,mixed>> */
    public static function exportItems(int $userId, string $group, string $label): array
    {
        $out = [];
        foreach (self::stored($userId) as $t) {
            $out[] = [
                'group_id' => $group,
                'group_label' => $label,
                'item_id' => 'bp-ticket-'.md5($t['id']),
                'data' => array_map(static fn ($k, $v) => ['name' => $k, 'value' => $v === null ? '' : (string) $v], array_keys($t), $t),
            ];
        }

        return $out;
    }

    /** Datenschutz-Löschung: Liste und Suchschlüssel entfernen (die Tickets selbst führt das Ticket-Plugin). */
    public static function erase(int $userId): bool
    {
        $had = get_user_meta($userId, self::META, true) !== '' || get_user_meta($userId, self::LOOKUP_META, false) !== [];
        delete_user_meta($userId, self::META);
        delete_user_meta($userId, self::LOOKUP_META);

        return $had;
    }

    private static function id(mixed $value): string
    {
        $id = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $id) === 1 ? $id : '';
    }

    private static function course(mixed $value): string
    {
        $code = is_scalar($value) ? strtoupper(trim((string) $value)) : '';

        return preg_match('/^[A-Z0-9-]{1,40}$/', $code) === 1 ? $code : '';
    }

    private static function text(mixed $value, int $max): string
    {
        return is_scalar($value) ? mb_substr(sanitize_text_field((string) $value), 0, $max) : '';
    }
}
