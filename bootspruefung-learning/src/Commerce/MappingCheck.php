<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

/**
 * Prüft Produktzuordnungen und Shop-Zustände für die Admin-Übersicht (Kap. 9):
 * unbekannte Kurse, gelöschte/unveröffentlichte/nicht kaufbare Produkte,
 * nicht virtuelle Lernprodukte, uneinheitliche Regeln, Bestellungen ohne Konto,
 * überbuchte Gruppen.
 */
final class MappingCheck
{
    /**
     * @return list<array{product_id:int,variation_id:int,problem:string,severity:string,deleted:bool}>
     */
    public static function issues(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bp_product_access WHERE active = 1 ORDER BY product_id, variation_id");
        $courses = Labels::courses();
        $woo = function_exists('wc_get_product');
        $out = [];
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row->product_id.'|'.(int) $row->variation_id][] = $row;
        }
        foreach ($byProduct as $key => $group) {
            [$productId, $variationId] = array_map('intval', explode('|', $key));
            $add = static function (string $problem, string $severity = 'error', bool $deleted = false) use (&$out, $productId, $variationId): void {
                $out[] = ['product_id' => $productId, 'variation_id' => $variationId, 'problem' => $problem, 'severity' => $severity, 'deleted' => $deleted];
            };
            foreach ($group as $row) {
                if (! isset($courses[$row->course_code])) {
                    $add(sprintf(__('Kurs „%s“ ist nicht (mehr) veröffentlicht – Käufe schalten nichts Sichtbares frei.', 'bootspruefung-learning'), $row->course_code));
                }
            }
            $signatures = array_unique(array_map(static fn ($r) => $r->duration_days.'|'.$r->renewal_mode.'|'.$r->refund_rule.'|'.$r->seats, $group));
            if (count($signatures) > 1) {
                $add(__('Uneinheitliche Dauer/Verlängerung/Erstattung/Plätze innerhalb der Zuordnung – bitte im Produkt neu speichern.', 'bootspruefung-learning'), 'warning');
            }
            // T101: Zugänge ohne Ablauf nur bewusst – in der Liste als Hinweis zeigen (nicht bei gelöschten Produkten).
            $unlimited = static function () use ($group, $add): void {
                if (array_filter($group, static fn ($r) => empty($r->duration_days)) !== []) {
                    $add(__('Laufzeit „ohne Ablauf“: Käufe schalten dauerhaft frei. Bitte prüfen, ob das gewollt ist (sonst im Produkt unter „Lernzugang“ eine Laufzeit eintragen).', 'bootspruefung-learning'), 'warning');
                }
            };
            if (! $woo) {
                $unlimited();
                continue;
            }
            $product = wc_get_product($variationId ?: $productId);
            $parent = $variationId ? wc_get_product($productId) : $product;
            if (self::isOrphan($productId, $variationId)) {
                $add(__('Produkt gelöscht – bestehende Zugänge bleiben, die Zuordnung kann entfernt werden.', 'bootspruefung-learning'), 'warning', true);
                continue;
            }
            $unlimited();
            if ($parent->get_status() !== 'publish') {
                $add(sprintf(__('Produkt ist nicht veröffentlicht (Status: %s).', 'bootspruefung-learning'), $parent->get_status()), 'warning');
            }
            if (! $product->is_purchasable()) {
                $add(__('Produkt ist nicht kaufbar (kein Preis oder deaktiviert).', 'bootspruefung-learning'));
            }
            if (! $product->is_virtual()) {
                $add(__('Lernprodukt ist nicht als virtuell markiert – Checkout verlangt Versanddaten.', 'bootspruefung-learning'), 'warning');
            }
        }

        return $out;
    }

    /** Zuordnung gehört zu einem gelöschten (oder im Papierkorb liegenden) Produkt bzw. einer gelöschten Variation. */
    public static function isOrphan(int $productId, int $variationId): bool
    {
        if (! function_exists('wc_get_product')) {
            return false;
        }
        $product = wc_get_product($variationId ?: $productId);
        $parent = $variationId ? wc_get_product($productId) : $product;

        return ! $product || ! $parent || $parent->get_status() === 'trash';
    }

    /**
     * Entfernt die Zugangszuordnung eines gelöschten Produkts (bestehende Zugänge bleiben).
     * @return int entfernte Zeilen
     */
    public static function removeOrphan(int $productId, int $variationId): int
    {
        if (! self::isOrphan($productId, $variationId)) {
            throw new \RuntimeException(__('Das Produkt existiert noch – die Zuordnung bitte im Produkt bearbeiten.', 'bootspruefung-learning'));
        }
        global $wpdb;
        $n = (int) $wpdb->delete($wpdb->prefix.'bp_product_access', ['product_id' => $productId, 'variation_id' => $variationId]);
        \Bootspruefung\Learning\Audit\AuditLog::record('product_access_orphan_removed', 'product', (string) ($variationId ?: $productId), null, ['product_id' => $productId, 'variation_id' => $variationId, 'rows' => $n]);

        return $n;
    }

    /** @return array{orders_without_account:int,overbooked_groups:int,mapping_errors:int,mapping_warnings:int} */
    public static function summary(): array
    {
        global $wpdb;
        $issues = self::issues();
        $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);

        return [
            'mapping_errors' => count(array_filter($issues, static fn ($i) => $i['severity'] === 'error')),
            'mapping_warnings' => count(array_filter($issues, static fn ($i) => $i['severity'] !== 'error')),
            // Bezahlte Bestellungen ohne Kundenkonto (letzte 30 Tage), für die noch kein Zugang existiert.
            'orders_without_account' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT a.object_id) FROM {$wpdb->prefix}bp_audit_log a WHERE a.action = 'order_without_account' AND a.created_at >= %s
                 AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}bp_entitlement e WHERE e.order_id = a.object_id)
                 AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}bp_learning_group g WHERE g.order_id = a.object_id)",
                $since
            )),
            // Gleiche Belegungsregel wie GroupService (abgelaufene Einladungen belegen keinen Platz).
            'overbooked_groups' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bp_learning_group g WHERE g.status = 'active'
                 AND (SELECT COUNT(*) FROM {$wpdb->prefix}bp_learning_group_member m WHERE m.group_id = g.id AND ".GroupService::occupying('m').') > g.seats',
                GroupService::inviteCutoff()
            )),
        ];
    }

    public static function editUrl(int $productId): string
    {
        return admin_url('post.php?post='.$productId.'&action=edit');
    }
}
