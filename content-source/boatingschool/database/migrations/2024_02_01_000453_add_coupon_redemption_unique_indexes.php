<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sicherheitsreview H1 (Coupon-Doppeleinlösung): die eigentliche Sperre
 * sitzt jetzt in CouponRedemptionService::redeem() (SELECT ... FOR UPDATE
 * auf die Coupon-Zeile) und Admin\CouponController::import() (atomares
 * UPDATE ... WHERE tenant_id IS NULL) -- diese beiden Unique-Indizes sind
 * zusätzliche Verteidigung auf Datenbankebene, falls ein künftiger
 * Code-Pfad die Sperre umgeht: pro Coupon-Code darf höchstens ein
 * Entitlement bzw. (je gebündeltem Produkt) ein ProductPurchase entstehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bug-Review R4-G (Folgefehler R4-5): identisches Muster wie bei den
        // in R4-5 bereits entschärften Unique-Indizes (siehe Migrationen
        // 000460/000462/000466) -- CREATE UNIQUE INDEX scheitert hart,
        // sobald auf Produktionsdaten (z. B. durch einen schon vor
        // Sicherheitsreview H1 behobenen Race) zwei Entitlement- bzw.
        // Product-Purchase-Zeilen denselben Coupon-Code als
        // source_reference tragen. Jede doppelte Zeile bleibt unverändert
        // bestehen -- ein Nutzer verliert dadurch keinen bereits gewährten
        // Zugang -- nur ihre source_reference wird umbenannt, damit der
        // Index entstehen kann. Welche Zeile pro Code die "erste" bleibt,
        // ist für den gewährten Zugang irrelevant; nur die Nachverfolgbarkeit
        // über den Coupon-Code geht für die umbenannte Dopplung verloren.
        DB::statement(<<<'SQL'
            WITH duplicates AS (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY source_type, source_reference
                    ORDER BY id ASC
                ) AS rn
                FROM entitlement
                WHERE source_type IN ('coupon', 'coupon_renewal') AND source_reference IS NOT NULL
            )
            UPDATE entitlement
            SET source_reference = LEFT(entitlement.source_reference, 50) || '-DUP-' || entitlement.id
            FROM duplicates
            WHERE entitlement.id = duplicates.id AND duplicates.rn > 1
        SQL);

        DB::statement("CREATE UNIQUE INDEX entitlement_coupon_source_unique ON entitlement (source_type, source_reference)
            WHERE source_type IN ('coupon', 'coupon_renewal') AND source_reference IS NOT NULL");

        DB::statement(<<<'SQL'
            WITH duplicates AS (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY source_type, source_reference, product_id
                    ORDER BY id ASC
                ) AS rn
                FROM product_purchase
                WHERE source_type = 'coupon' AND source_reference IS NOT NULL
            )
            UPDATE product_purchase
            SET source_reference = LEFT(product_purchase.source_reference, 50) || '-DUP-' || product_purchase.id
            FROM duplicates
            WHERE product_purchase.id = duplicates.id AND duplicates.rn > 1
        SQL);

        DB::statement("CREATE UNIQUE INDEX product_purchase_coupon_source_unique ON product_purchase (source_type, source_reference, product_id)
            WHERE source_type = 'coupon' AND source_reference IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entitlement_coupon_source_unique');
        DB::statement('DROP INDEX IF EXISTS product_purchase_coupon_source_unique');
    }
};
