<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Audit;

defined('ABSPATH') || exit;

/** Audit-Log für Import-, Freigabe- und Zugangsaktionen (Kap. 10). Keine Zahlungsdaten. */
final class AuditLog
{
    /** @param array<string,mixed> $context */
    public static function record(string $action, ?string $objectType = null, ?string $objectId = null, ?string $reason = null, array $context = []): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'bp_audit_log', [
            'actor_user_id' => get_current_user_id() ?: null,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'reason' => $reason,
            'context' => $context === [] ? null : wp_json_encode($context),
            'created_at' => current_time('mysql', true),
        // Feste Formate: WordPress führt „object_id“ in $wpdb->field_types als Zahl (%d) – ohne Angabe würden
        // Text-IDs (Einlöse-Schlüssel, Einstellungsnamen, Hashes) als 0 gespeichert.
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s']);
    }
}
