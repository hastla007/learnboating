<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Rest\Controllers;

use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Rest\RestRegistrar;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Gruppenlizenzen: Beitritt und Verwaltung durch die besitzende Person. */
final class GroupController
{
    private GroupService $groups;

    public function __construct()
    {
        global $wpdb;
        $this->groups = new GroupService($wpdb);
    }

    public function registerRoutes(): void
    {
        $ns = RestRegistrar::NAMESPACE;
        $auth = static fn (): bool|WP_Error => is_user_logged_in() ? true : new WP_Error('bp_login_required', __('Bitte anmelden.', 'bootspruefung-learning'), ['status' => 401]);
        $token = ['type' => 'string', 'pattern' => '^[gi][0-9a-f]{32}$', 'required' => true];
        $id = ['type' => 'integer', 'minimum' => 1, 'required' => true];
        register_rest_route($ns, '/groups/join/(?P<token>[gi][0-9a-f]{32})', ['methods' => WP_REST_Server::READABLE, 'callback' => $this->wrap([$this, 'describe']), 'permission_callback' => $auth, 'args' => ['token' => $token]]);
        register_rest_route($ns, '/groups/join', ['methods' => WP_REST_Server::CREATABLE, 'callback' => $this->wrap([$this, 'join']), 'permission_callback' => $auth, 'args' => ['token' => $token]]);
        register_rest_route($ns, '/me/groups', ['methods' => WP_REST_Server::READABLE, 'callback' => $this->wrap([$this, 'mine']), 'permission_callback' => $auth]);
        register_rest_route($ns, '/me/groups/(?P<id>\d+)', ['methods' => 'PUT', 'callback' => $this->wrap([$this, 'rename']), 'permission_callback' => $auth, 'args' => ['id' => $id, 'name' => ['type' => 'string', 'required' => true, 'maxLength' => 190]]]);
        register_rest_route($ns, '/me/groups/(?P<id>\d+)/invite', ['methods' => WP_REST_Server::CREATABLE, 'callback' => $this->wrap([$this, 'invite']), 'permission_callback' => $auth, 'args' => ['id' => $id, 'emails' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 200, 'required' => true]]]);
        register_rest_route($ns, '/me/groups/(?P<id>\d+)/token', ['methods' => WP_REST_Server::CREATABLE, 'callback' => $this->wrap([$this, 'token']), 'permission_callback' => $auth, 'args' => ['id' => $id]]);
        register_rest_route($ns, '/me/groups/(?P<id>\d+)/members/(?P<member>\d+)', ['methods' => WP_REST_Server::DELETABLE, 'callback' => $this->wrap([$this, 'remove']), 'permission_callback' => $auth, 'args' => ['id' => $id, 'member' => $id, 'reason' => ['type' => 'string', 'default' => '']]]);
    }

    /** @return array<string,mixed> */
    public function describe(WP_REST_Request $request): array
    {
        $info = $this->groups->describeToken((string) $request['token']);
        if ($info === null) {
            throw new \InvalidArgumentException(__('Dieser Einladungslink ist ungültig oder abgelaufen.', 'bootspruefung-learning'));
        }

        return $info;
    }

    /** @return array<string,mixed> */
    public function join(WP_REST_Request $request): array
    {
        $member = $this->groups->join((string) $request['token'], get_current_user_id());

        return ['joined' => true, 'group_id' => (int) $member->group_id];
    }

    /** @return list<array<string,mixed>> */
    public function mine(): array
    {
        $out = [];
        foreach ($this->groups->forOwner(get_current_user_id()) as $group) {
            $out[] = $this->payload($group);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function rename(WP_REST_Request $request): array
    {
        $this->groups->rename((int) $request['id'], get_current_user_id(), (string) $request['name']);

        return $this->payload($this->owned((int) $request['id']));
    }

    /** @return array<string,mixed> */
    public function invite(WP_REST_Request $request): array
    {
        $result = $this->groups->invite((int) $request['id'], get_current_user_id(), array_map('strval', (array) $request['emails']));

        return $result + ['group' => $this->payload($this->owned((int) $request['id']))];
    }

    /** @return array<string,mixed> */
    public function token(WP_REST_Request $request): array
    {
        $this->groups->regenerateToken((int) $request['id'], get_current_user_id());

        return $this->payload($this->owned((int) $request['id']));
    }

    /** @return array<string,mixed> */
    public function remove(WP_REST_Request $request): array
    {
        $this->groups->removeMember((int) $request['id'], (int) $request['member'], get_current_user_id(), (string) $request['reason']);

        return $this->payload($this->owned((int) $request['id']));
    }

    private function owned(int $id): object
    {
        $group = $this->groups->find($id);
        if ($group === null || (int) $group->owner_user_id !== get_current_user_id()) {
            throw new \InvalidArgumentException(__('Gruppe nicht gefunden.', 'bootspruefung-learning'));
        }

        return $group;
    }

    /** @return array<string,mixed> */
    private function payload(object $group): array
    {
        $members = [];
        $cutoff = GroupService::inviteCutoff();
        foreach ($this->groups->membersOf((int) $group->id) as $m) {
            if ($m->status === 'removed') {
                continue;
            }
            // Abgelaufen, auch wenn der stündliche Cron die Zeile noch nicht umgestellt hat.
            $status = $m->status === 'invited' && $m->invited_at !== null && $m->invited_at < $cutoff ? GroupService::INVITE_EXPIRED : $m->status;
            $name = $m->display_name ?: null;
            if ($status === GroupService::INVITE_EXPIRED && $name === null && ($m->email === null || $m->email === '')) {
                continue; // anonymisierte Einladung: nichts mehr anzuzeigen
            }
            $members[] = ['id' => (int) $m->id, 'name' => $name, 'email' => $m->email, 'status' => $status, 'joined_at' => $m->joined_at, 'invited_at' => $m->invited_at];
        }

        return [
            'id' => (int) $group->id,
            'name' => $group->name,
            'status' => $group->status,
            'seats' => (int) $group->seats,
            'free_seats' => $this->groups->freeSeats($group),
            'valid_until' => $group->valid_until,
            'scopes' => array_map(static fn ($s) => ['course' => Labels::course($s['course']), 'feature' => Labels::feature($s['feature'])], GroupService::scopes($group)),
            'join_url' => $group->status === GroupService::ACTIVE ? GroupService::joinUrl((string) $group->join_token) : null,
            'members' => $members,
        ];
    }

    private function wrap(callable $callback): callable
    {
        $inner = LearnerController::wrap($callback);

        return static function (WP_REST_Request $request) use ($inner) {
            try {
                return $inner($request);
            } catch (\InvalidArgumentException $e) {
                return new WP_Error('bp_invalid', $e->getMessage(), ['status' => 422]);
            }
        };
    }
}
