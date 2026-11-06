<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content;

use Bootspruefung\Learning\Content\Repositories\Overrides;

/**
 * Sammelfreigabe aller Inhalte, die noch nicht als licensed/public gelten (Betreiber-
 * Entscheidung vom 06.11.2026: vorläufig freigeben; Seekarten getrennt über Charts).
 * Jede Freigabe ist ein Override mit Begründung (Audit-Log), umkehrbar über die
 * Rechte-Seite, und gilt auch für später importierte Revisionen derselben Inhalte.
 */
final class RightsRelease
{
    public const DEFAULT_REASON = 'Vorläufige Freigabe durch den Betreiber (06.11.2026); Seekarten ausgenommen';

    /** Typ => [Tabelle, Join, Ausdruck für den importierten Status] */
    private const TYPES = [
        'question' => ['question', 'JOIN %squestion_revision r ON r.id = x.current_revision_id', 'r.rights_status'],
        'media' => ['media', '', 'x.rights_status'],
        'navigation_task' => ['navigation_task', '', 'x.rights_status'],
        'praxis_task' => ['praxis_task', '', 'x.rights_status'],
        'video_module' => ['video_module', '', 'x.rights_status'],
        'radio_text' => ['radio_text', '', 'x.rights_status'],
        'radio_vocabulary' => ['radio_vocabulary', '', 'x.rights_status'],
        'radio_scenario' => ['radio_scenario', '', 'x.rights_status'],
    ];

    /**
     * @return array<string,int> Typ => Anzahl (Vorschau oder freigegeben)
     */
    public static function releaseAll(string $reason = self::DEFAULT_REASON, bool $dryRun = false): array
    {
        global $wpdb;
        $p = $wpdb->prefix.'bp_';
        $out = [];
        foreach (self::TYPES as $type => [$table, $join, $imported]) {
            $join = $join !== '' ? sprintf($join, $p) : '';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- feste Tabellen/Ausdrücke
            $ids = array_map('intval', $wpdb->get_col("SELECT x.id FROM {$p}{$table} x {$join} ".Overrides::rightsJoin($type, 'x', 'o')." WHERE COALESCE(o.value, {$imported}) NOT IN ('licensed','public')"));
            $out[$type] = count($ids);
            if (! $dryRun && $ids !== []) {
                Overrides::set($type, $ids, 'rights_status', 'licensed', $reason);
            }
        }

        return $out;
    }
}
