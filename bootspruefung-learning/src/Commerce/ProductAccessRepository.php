<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Produktzuordnung „Lernzugang“ (Kap. 7.1, 7.6): Woo-Produkt/Variation → Kurs + Feature.
 * Eine Variation mit eigenen Zeilen überschreibt das Produkt, sonst erbt sie.
 * Preise oder Zahlungsdaten werden hier nie gespeichert.
 */
final class ProductAccessRepository
{
    public const FEATURE_ALL = 'all';
    public const RENEWAL_MODES = ['extend', 'parallel'];
    public const REFUND_RULES = ['revoke_on_full_refund', 'revoke_on_any_refund', 'keep'];

    private string $table;

    public function __construct(private readonly \wpdb $db)
    {
        $this->table = $db->prefix.'bp_product_access';
    }

    /** @return list<string> */
    public static function features(): array
    {
        return array_merge([self::FEATURE_ALL], AccessService::FEATURES);
    }

    /**
     * Wirksame, aktive Zuordnungen einer Bestellposition.
     *
     * @return list<object>
     */
    public function forItem(int $productId, int $variationId): array
    {
        if ($variationId > 0) {
            $own = $this->rows($productId, $variationId, true);
            if ($own !== []) {
                return $own;
            }
        }

        return $this->rows($productId, 0, true);
    }

    /** @return list<object> */
    public function rows(int $productId, int $variationId, bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE product_id = %d AND variation_id = %d".($activeOnly ? ' AND active = 1' : '').' ORDER BY course_code, feature_code';

        return $this->db->get_results($this->db->prepare($sql, $productId, $variationId));
    }

    public function hasAccess(int $productId, int $variationId = 0): bool
    {
        return $this->forItem($productId, $variationId) !== [];
    }

    /**
     * Ersetzt die Zuordnung eines Produkts bzw. einer Variation.
     *
     * @param list<array{course_code:string,feature_code:string}> $scopes
     * @return array{added:int,removed:int}
     */
    public function save(int $productId, int $variationId, array $scopes, ?int $durationDays, string $renewalMode, string $refundRule): array
    {
        $renewalMode = in_array($renewalMode, self::RENEWAL_MODES, true) ? $renewalMode : 'extend';
        $refundRule = in_array($refundRule, self::REFUND_RULES, true) ? $refundRule : 'revoke_on_full_refund';
        $durationDays = $durationDays !== null && $durationDays > 0 ? $durationDays : null;
        $before = [];
        foreach ($this->rows($productId, $variationId) as $row) {
            $before[$row->course_code.'|'.$row->feature_code] = $row;
        }
        $wanted = [];
        foreach ($scopes as $scope) {
            $wanted[$scope['course_code'].'|'.$scope['feature_code']] = $scope;
        }
        $added = 0;
        foreach ($wanted as $key => $scope) {
            $data = ['duration_days' => $durationDays, 'renewal_mode' => $renewalMode, 'refund_rule' => $refundRule, 'active' => 1];
            if (isset($before[$key])) {
                $this->db->update($this->table, $data, ['id' => $before[$key]->id]);
            } else {
                $this->db->insert($this->table, $data + ['product_id' => $productId, 'variation_id' => $variationId, 'course_code' => $scope['course_code'], 'feature_code' => $scope['feature_code']]);
                $added++;
            }
        }
        $removed = 0;
        foreach (array_diff_key($before, $wanted) as $row) {
            // Bestehende Zugänge tragen ihren Umfang selbst; die Zuordnung kann gelöscht werden.
            $this->db->delete($this->table, ['id' => $row->id]);
            $removed++;
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /** @return list<int> Produkt-IDs (Eltern) mit Lernzugang */
    public function productIds(): array
    {
        return array_map('intval', $this->db->get_col("SELECT DISTINCT product_id FROM {$this->table} WHERE active = 1"));
    }

    /**
     * Produkte, die einen Kurs/Feature freischalten (für Sperrhinweise).
     *
     * @return list<array{product_id:int,variation_id:int}>
     */
    public function grantingProducts(string $courseCode, string $feature): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT DISTINCT product_id, variation_id FROM {$this->table} WHERE active = 1 AND course_code = %s AND feature_code IN (%s, %s) ORDER BY product_id",
            $courseCode, $feature, self::FEATURE_ALL
        ));

        return array_map(static fn ($r) => ['product_id' => (int) $r->product_id, 'variation_id' => (int) $r->variation_id], $rows);
    }
}
