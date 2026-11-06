<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sicherheitsreview M7 (Folgefehler): coupon_isolation erlaubt bewusst
 * tenant_id IS NULL -- sowohl für SELECT (ein noch unzugeordneter, frei
 * einlösbarer Coupon muss für JEDE Bootsschule sichtbar sein, siehe
 * Admin\CouponController::import()) als auch für UPDATE, weil
 * CouponRedemptionService beim Einlösen eines solchen Coupons tenant_id
 * absichtlich NULL lässt (nur redeemed_* wird gesetzt).
 *
 * Postgres' USING/WITH CHECK auf einer UPDATE-Policy sieht aber nur die
 * ALTE bzw. NEUE Zeile für sich, nie beide zugleich -- die Policy allein
 * kann deshalb nicht verhindern, dass ein UPDATE einen Coupon, der
 * bereits einer Bootsschule gehört (tenant_id = eigene UUID), zurück in
 * den unzugeordneten, plattformweit einlösbaren Pool "entlässt"
 * (tenant_id = NULL). Kein heutiger Code-Pfad tut das (geprüft:
 * Admin\CouponController::import() setzt tenant_id ausschließlich von
 * NULL auf die eigene UUID, nie umgekehrt), aber tenant_id ist
 * $fillable auf dem Coupon-Model -- ein künftiger Mass-Assignment-Bug
 * (z. B. ein Controller, der ungefiltert $request->all() durchreicht)
 * könnte genau das auslösen und einen fremden, bereits bezahlten Coupon
 * für jede beliebige Bootsschule einlösbar machen. Ein Trigger prüft den
 * alten gegen den neuen Wert und schließt diese eine, konkrete
 * Übergangsrichtung, ohne die beiden legitimen Abläufe (Claim:
 * NULL -> eigene UUID; Einlösen eines freien Codes: NULL bleibt NULL) zu
 * beeinträchtigen.
 *
 * coupon.tenant_id ist zusätzlich bewusst nullOnDelete() (ein Coupon
 * überlebt die Löschung der ihm zugewiesenen Bootsschule, statt mit ihr
 * zu verschwinden) -- diese FK-Kaskade löst intern GENAU dasselbe UPDATE
 * aus wie der zu verhindernde Mass-Assignment-Fall. Die EXISTS-Prüfung
 * unten unterscheidet beide Fälle: läuft die Bootsschule noch (ein
 * App-Bug versucht, tenant_id trotzdem zu nullen), wird blockiert; ist
 * sie bereits gelöscht (die FK-Kaskade selbst löst das UPDATE aus,
 * innerhalb derselben Transaktion NACH dem DELETE auf "tenant"), lässt
 * der Trigger es durch.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION coupon_prevent_tenant_release() RETURNS trigger AS $$
            BEGIN
                IF OLD.tenant_id IS NOT NULL AND NEW.tenant_id IS NULL
                   AND EXISTS (SELECT 1 FROM tenant WHERE id = OLD.tenant_id) THEN
                    RAISE EXCEPTION 'coupon.tenant_id kann nicht auf NULL zurückgesetzt werden, solange die zugewiesene Bootsschule noch existiert.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER coupon_prevent_tenant_release BEFORE UPDATE ON coupon
            FOR EACH ROW EXECUTE FUNCTION coupon_prevent_tenant_release()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS coupon_prevent_tenant_release ON coupon');
        DB::statement('DROP FUNCTION IF EXISTS coupon_prevent_tenant_release()');
    }
};
