<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Extensions\Tickets;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Praxis-Tickets des Kontos (seit 1.11.0, GET /me/tickets): zuerst das Ticket-Plugin (Filter
 * bootspruefung_tickets_for_user), sonst die vom Lern-Plugin gespeicherte Liste (Extensions\Tickets).
 * Ohne Ticket-Plugin eine leere Liste. Kein amtlicher Ausbildungsnachweis.
 */
final class TicketController extends BaseController
{
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        return $this->ok(array_map([self::class, 'ticket'], Tickets::forUser($this->user())));
    }

    /** @param array<string,mixed> $t Tickets::normalize() @return array<string,mixed> */
    public static function ticket(array $t): array
    {
        return [
            'id' => (string) $t['id'],
            'course_code' => $t['course_code'] !== '' ? (string) $t['course_code'] : null,
            'title' => (string) $t['title'],
            'units_total' => (int) $t['units_total'],
            'units_done' => (int) $t['units_done'],
            'units_left' => (int) $t['units_left'],
            'status' => (string) $t['status'],
            'date' => $t['date'],
            'location' => $t['location'],
            'reason' => $t['reason'],
        ];
    }
}
