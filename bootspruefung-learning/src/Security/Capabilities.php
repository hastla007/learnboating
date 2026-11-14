<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Security;

defined('ABSPATH') || exit;

/** Eigene Capabilities (Konzept Kap. 8); Administrator erhält sie bei Aktivierung. */
final class Capabilities
{
    public const MANAGE_CONTENT = 'manage_bp_content';
    public const IMPORT_CONTENT = 'import_bp_content';
    public const MANAGE_ENTITLEMENTS = 'manage_bp_entitlements';

    public const ALL = [self::MANAGE_CONTENT, self::IMPORT_CONTENT, self::MANAGE_ENTITLEMENTS];

    public static function grantToAdministrator(): void
    {
        $role = get_role('administrator');
        if ($role === null) {
            return;
        }
        foreach (self::ALL as $cap) {
            $role->add_cap($cap);
        }
    }

    /** Entfernt die Capabilities von allen Rollen (Deinstallation). */
    public static function removeFromAllRoles(): void
    {
        foreach (wp_roles()->role_objects as $role) {
            foreach (self::ALL as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
}
