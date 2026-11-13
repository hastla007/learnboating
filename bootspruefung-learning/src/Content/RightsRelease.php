<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Content\Repositories\Overrides;

/**
 * Sammelfreigabe aller Inhalte mit ungeklärtem Rechte-Status (Betreiber-Entscheidung
 * vom 06.11.2026: vorläufig freigeben; Seekarten getrennt über Charts).
 * Freigegeben wird nur, was importiert „unknown“ ist und noch keinen lokalen
 * Override hat: bewusst gesetzte Entscheidungen (z. B. restricted, review) bleiben
 * unangetastet, ebenso archivierte Inhalte (source_archived).
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
            $ids = array_map('intval', $wpdb->get_col("SELECT x.id FROM {$p}{$table} x {$join} ".Overrides::rightsJoin($type, 'x', 'o')." WHERE o.object_id IS NULL AND {$imported} = 'unknown' AND x.status <> 'source_archived'"));
            $out[$type] = count($ids);
            if (! $dryRun && $ids !== []) {
                Overrides::set($type, $ids, 'rights_status', 'licensed', $reason);
            }
        }

        return $out;
    }

    public const RULES_REASON = 'Vorläufige Freigabe der Prüfungsregelwerke durch den Betreiber (06.11.2026)';

    /**
     * Alle Prüfungsregelwerke live schalten: verifiziert und (bei Entwürfen) veröffentlicht.
     *
     * @return list<array{id:int,course:string,changes:list<string>}>
     */
    public static function releaseExamRules(string $reason = self::RULES_REASON, bool $dryRun = false): array
    {
        global $wpdb;
        $p = $wpdb->prefix.'bp_';
        $overrides = Overrides::forType('exam_rule');
        $out = [];
        // Nur das jeweils neueste nicht archivierte Regelwerk je Kurs (wie ContentRepository::examRule).
        $rules = $wpdb->get_results("SELECT r.id, r.status, r.verified, c.code FROM {$p}exam_rule r JOIN {$p}course c ON c.id = r.course_id WHERE r.status <> 'source_archived' AND r.id = (SELECT MAX(r2.id) FROM {$p}exam_rule r2 WHERE r2.course_id = r.course_id AND r2.status <> 'source_archived')");
        foreach ($rules as $rule) {
            $o = $overrides[(int) $rule->id] ?? [];
            $verified = array_key_exists('verified', $o) ? (int) $o['verified'] : (int) $rule->verified;
            $status = array_key_exists('status', $o) ? (string) $o['status'] : (string) $rule->status;
            $changes = [];
            if ($verified !== 1) {
                $changes[] = 'verified';
            }
            if ($status !== 'published') {
                $changes[] = 'published';
            }
            if ($changes === []) {
                continue;
            }
            if (! $dryRun) {
                if (in_array('verified', $changes, true)) {
                    Overrides::set('exam_rule', [(int) $rule->id], 'verified', '1', $reason);
                }
                if (in_array('published', $changes, true)) {
                    Overrides::set('exam_rule', [(int) $rule->id], 'status', 'published', $reason);
                }
            }
            $out[] = ['id' => (int) $rule->id, 'course' => (string) $rule->code, 'changes' => $changes];
        }

        return $out;
    }
}
