<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Gelöschte Schule macht ihre Coupons plattformweit
 * einlösbar): coupon.tenant_id ist bewusst nullOnDelete() (siehe
 * 2024_02_01_000210, 2024_02_01_000467) -- ein Coupon überlebt die
 * Löschung der ihm zugewiesenen Bootsschule. Für einen bereits EINGELÖSTEN
 * Coupon ist das richtig (redeemed_at/redeemed_tenant_id bleiben als
 * Audit-Trail erhalten, redeemed_at schließt eine erneute Einlösung ohnehin
 * aus). Für einen NOCH NICHT eingelösten Coupon landet das NULL-gesetzte
 * tenant_id aber genau im "unzugeordnet"-Zustand, den coupon_isolation
 * (siehe 2024_02_01_000210) für JEDE Bootsschule sichtbar und einlösbar
 * macht -- eine Schule, die z. B. 50 unverkaufte Rabattcodes hatte und
 * gelöscht wird, "schenkt" diese damit versehentlich der gesamten
 * Plattform.
 *
 * Ein BEFORE-DELETE-Trigger auf tenant löscht die noch nicht eingelösten
 * Coupons der Schule, bevor die eigentliche DELETE-Zeile verarbeitet wird
 * -- die anschließende FK-Kaskade (nullOnDelete) betrifft dadurch nur noch
 * die verbleibenden, bereits eingelösten Coupons, deren Audit-Trail
 * unverändert erhalten bleibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tenant_delete_unredeemed_coupons() RETURNS trigger AS $$
            BEGIN
                DELETE FROM coupon WHERE tenant_id = OLD.id AND redeemed_at IS NULL;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER tenant_delete_unredeemed_coupons BEFORE DELETE ON tenant
            FOR EACH ROW EXECUTE FUNCTION tenant_delete_unredeemed_coupons()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS tenant_delete_unredeemed_coupons ON tenant');
        DB::statement('DROP FUNCTION IF EXISTS tenant_delete_unredeemed_coupons()');
    }
};
