<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Idempotency;
use Bootspruefung\Learning\Mobile\Mapper\CourseMapper;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Mobile\TokenAuth;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Gruppenlizenzen verwalten (wie „Meine Gruppen“ im Web, Rest\Controllers\GroupController) über
 * denselben GroupService: Liste/Detail, Einladen per E-Mail (gleiche Prüfung, Tageslimit und
 * Einladungsmail), Platz freigeben, neuer Beitrittslink. Berechtigt ist wie im Web-Lernbereich nur
 * die besitzende Person (GroupService::forOwner); fremde Gruppen → 404 (keine Auskunft, ob es sie gibt).
 * Mitglieder sehen fremde Gruppen auch im Web nicht – es gibt keine Mitgliedsansicht.
 *
 * Beitrittsvorschau zu einem Einladungslink (wie GET bootspruefung/v1/groups/join/{token}):
 * GroupService::describeToken. Beigetreten wird über POST /coupons/redeem (GroupService::join).
 */
final class GroupController extends BaseController
{
    private GroupService $groups;

    public function __construct()
    {
        parent::__construct();
        global $wpdb;
        $this->groups = new GroupService($wpdb);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $items = array_map([$this, 'payload'], $this->groups->forOwner($this->user()));

        return $this->ok($items, 200, [
            'groups' => count($items),
            'seats_total' => array_sum(array_map(static fn ($g) => $g['seats']['total'], $items)),
            'seats_used' => array_sum(array_map(static fn ($g) => $g['seats']['used'], $items)),
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->ok($this->payload($this->owned($request)));
    }

    /** Einladen per E-Mail: GroupService::invite (überspringt ungültige/doppelte Adressen, volle Gruppe, Tageslimit). */
    public function invite(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $group = $this->owned($request);
        $emails = [];
        foreach (array_merge(is_array($request['emails']) ? $request['emails'] : [], [(string) ($request['email'] ?? '')]) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && ! in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
        if ($emails === []) {
            throw ApiException::validation(['emails' => [__('Bitte gib mindestens eine E-Mail-Adresse ein.', 'bootspruefung-learning')]]);
        }
        if (count($emails) > 200) {
            throw ApiException::validation(['emails' => [__('Bitte lade höchstens 200 Personen auf einmal ein.', 'bootspruefung-learning')]]);
        }
        $this->requireActive($group);
        $groupId = (int) $group->id;
        $payload = ['emails' => $emails];
        [$status, $body] = Idempotency::run($user, 'group-invite:'.$groupId, $this->idempotencyKey($request, false), $payload, function () use ($user, $groupId, $emails): array {
            // Erst hier zählen: Wiederholungen mit gleichem Key kommen gar nicht bis hierher.
            RateLimiter::hit('group-invite|'.$user, 20, HOUR_IN_SECONDS, __('Zu viele Einladungen in kurzer Zeit. Bitte warte eine Stunde und versuche es dann erneut.', 'bootspruefung-learning'));
            $result = $this->groups->invite($groupId, $user, $emails);
            $group = $this->groups->find($groupId);

            return [200, ['data' => ['invited' => (int) $result['invited'], 'skipped' => array_values(array_map('strval', $result['skipped'])), 'group' => $this->payload($group)]], 'group:'.$groupId];
        });

        return new WP_REST_Response($body, $status);
    }

    /** Platz freigeben (GroupService::removeMember: Zugänge des Mitglieds widerrufen bzw. Einladung zurückziehen). */
    public function removeMember(WP_REST_Request $request): WP_REST_Response
    {
        $group = $this->owned($request);
        $memberId = (int) $request['memberId'];
        $member = null;
        foreach ($this->groups->membersOf((int) $group->id) as $m) {
            if ((int) $m->id === $memberId && $m->status !== 'removed') {
                $member = $m;
            }
        }
        if ($member === null) {
            throw ApiException::notFound(__('Mitglied nicht gefunden.', 'bootspruefung-learning'));
        }
        $this->groups->removeMember((int) $group->id, $memberId, $this->user(), trim((string) ($request['reason'] ?? '')));

        return $this->ok($this->payload($this->groups->find((int) $group->id)));
    }

    /** Neuer Beitrittslink (GroupService::regenerateToken; der alte Link wird sofort ungültig). */
    public function regenerateJoinCode(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $group = $this->owned($request);
        $this->requireActive($group);
        $groupId = (int) $group->id;
        [$status, $body] = Idempotency::run($user, 'group-join-code:'.$groupId, $this->idempotencyKey($request, false), ['group' => $groupId], function () use ($user, $groupId): array {
            $this->groups->regenerateToken($groupId, $user);

            return [200, ['data' => $this->payload($this->groups->find($groupId))], 'group:'.$groupId];
        });

        return new WP_REST_Response($body, $status);
    }

    /** Vorschau eines Einladungslinks vor dem Beitritt (wie im Web „Gruppe beitreten“). */
    public function joinPreview(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        RateLimiter::hit('group-preview|'.$user, 60, HOUR_IN_SECONDS);
        $token = self::token((string) $request['token']);
        $info = $token !== null ? $this->groups->describeToken($token) : null;
        [$group] = $token !== null ? $this->groups->resolve($token) : [null];
        if ($info === null || $group === null) {
            throw new ApiException('COUPON_INVALID', __('Dieser Einladungslink ist ungültig oder abgelaufen.', 'bootspruefung-learning'), 422);
        }
        $expired = $group->valid_until !== null && $group->valid_until < EntitlementService::now();
        $member = (bool) $this->groupsMemberRow((int) $group->id);

        return $this->ok([
            'name' => (string) $info['name'],
            'owner_name' => $info['owner'] !== '' ? (string) $info['owner'] : null,
            'personal' => (bool) $info['personal'],
            'status' => (string) $info['status'],
            'valid_until' => TokenAuth::iso($info['valid_until']),
            'free_seats' => (int) $info['free_seats'],
            'already_member' => $member,
            'joinable' => $member || ($info['status'] === GroupService::ACTIVE && ! $expired && (int) $info['free_seats'] > 0),
            'scopes' => $this->scopes($group),
        ]);
    }

    // ------------------------------------------------------------------

    /** Ganzer Link …/gruppe/<token>/ oder nur der Code (wie /coupons/redeem). */
    public static function token(string $raw): ?string
    {
        $raw = trim($raw);
        $code = preg_match('#/gruppe/([gi][0-9a-f]{32})/?#i', $raw, $m) === 1 ? strtolower($m[1]) : strtolower($raw);

        return preg_match('/^[gi][0-9a-f]{32}$/', $code) === 1 ? $code : null;
    }

    /** Eigene aktive Mitgliedschaft in der Gruppe (Beitritt wäre eine Wiederholung). */
    private function groupsMemberRow(int $groupId): ?object
    {
        foreach ($this->groups->membersOf($groupId) as $m) {
            if ($m->status === 'active' && (int) $m->user_id === $this->user()) {
                return $m;
            }
        }

        return null;
    }

    /** Eigene Gruppe (besitzende Person, wie im Web) oder 404. */
    private function owned(WP_REST_Request $request): object
    {
        $id = (int) $request['groupId'];
        $group = $id > 0 ? $this->groups->find($id) : null;
        if ($group === null || (int) $group->owner_user_id !== $this->user()) {
            throw ApiException::notFound(__('Gruppe nicht gefunden.', 'bootspruefung-learning'));
        }

        return $group;
    }

    /** Einladen und neuer Link nur bei aktiver Gruppe (im Web nur dann angeboten). */
    private function requireActive(object $group): void
    {
        if ($group->status !== GroupService::ACTIVE) {
            throw new ApiException('GROUP_NOT_ACTIVE', __('Diese Gruppe ist derzeit nicht aktiv. Einladen und neue Links sind erst wieder möglich, wenn sie aktiv ist.', 'bootspruefung-learning'), 409, ['details' => ['group_id' => (string) $group->id, 'status' => (string) $group->status]]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function scopes(object $group): array
    {
        $out = [];
        foreach (GroupService::scopes($group) as $scope) {
            $course = null;
            foreach ($this->publishedCourses() as $c) {
                if ((string) $c->code === (string) ($scope['course'] ?? '')) {
                    $course = $c;
                }
            }
            $feature = (string) ($scope['feature'] ?? '');
            $cap = array_search($feature, CourseMapper::CAPABILITIES, true);
            $out[] = [
                'course_id' => $course ? (string) $course->id : null,
                'course_code' => (string) ($scope['course'] ?? ''),
                'course_name' => Labels::course((string) ($scope['course'] ?? '')),
                'feature' => $feature,
                'capability' => $cap !== false ? $cap : null,
                'feature_label' => Labels::feature($feature),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> wie im Web (GroupController::payload), mit Platzzahlen, Ablauf je Einladung und Beitrittscode */
    private function payload(object $group): array
    {
        $cutoff = GroupService::inviteCutoff();
        $members = [];
        $used = 0;
        foreach ($this->groups->membersOf((int) $group->id) as $m) {
            if ($m->status === 'removed') {
                continue;
            }
            // Abgelaufen, auch wenn der stündliche Cron die Zeile noch nicht umgestellt hat (wie im Web).
            $status = $m->status === 'invited' && $m->invited_at !== null && $m->invited_at < $cutoff ? GroupService::INVITE_EXPIRED : (string) $m->status;
            $name = $m->display_name ?: null;
            if ($status === GroupService::INVITE_EXPIRED && $name === null && ($m->email === null || $m->email === '')) {
                continue; // anonymisierte Einladung: nichts mehr anzuzeigen
            }
            if ($status === 'active' || $status === 'invited') {
                $used++;
            }
            $members[] = [
                'id' => (string) $m->id,
                'user_id' => $m->user_id !== null && (int) $m->user_id > 0 ? (string) $m->user_id : null,
                'name' => $name !== null ? (string) $name : null,
                'email' => $m->email !== null && $m->email !== '' ? (string) $m->email : null,
                'status' => $status,
                'is_me' => $m->user_id !== null && (int) $m->user_id === $this->user(),
                'joined_at' => TokenAuth::iso($m->joined_at),
                'invited_at' => TokenAuth::iso($m->invited_at),
                'invite_expires_at' => in_array($status, ['invited', GroupService::INVITE_EXPIRED], true) && $m->invited_at !== null ? TokenAuth::iso(GroupService::inviteExpiresAt((string) $m->invited_at)) : null,
                // Im Web gibt es „Platz freigeben“ nur für Mitglieder und offene Einladungen.
                'removable' => $status === 'active' || $status === 'invited',
            ];
        }
        $active = $group->status === GroupService::ACTIVE;
        $free = $this->groups->freeSeats($group);

        return [
            'id' => (string) $group->id,
            'name' => (string) $group->name,
            'status' => (string) $group->status,
            'valid_until' => TokenAuth::iso($group->valid_until),
            // belegt = aktive Mitglieder + offene, nicht abgelaufene Einladungen (wie GroupService::occupying)
            'seats' => ['total' => (int) $group->seats, 'used' => $used, 'free' => $free],
            'scopes' => $this->scopes($group),
            'join_code' => $active ? (string) $group->join_token : null,
            'join_url' => $active ? GroupService::joinUrl((string) $group->join_token) : null,
            'invites_remaining_today' => $active ? $this->groups->inviteBudget($group) : 0,
            'invite_valid_days' => GroupService::INVITE_DAYS,
            'members' => $members,
        ];
    }
}
