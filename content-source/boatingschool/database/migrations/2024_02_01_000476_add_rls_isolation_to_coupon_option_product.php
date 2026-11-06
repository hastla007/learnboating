<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Coupon-Nebentabelle ohne DB-Lock pro Bootsschule): coupon
 * selbst trägt seit 2024_02_01_000210 die RLS-Policy coupon_isolation, die
 * eine einer Bootsschule zugeordnete Zeile (tenant_id gesetzt) für jede
 * andere Bootsschule unsichtbar macht. coupon_option_product (die n:n-
 * Zuordnung der gebündelten Zusatzprodukte, siehe 2024_02_01_000438) hat
 * selbst keine tenant_id und damit bislang auch KEINE eigene Policy -- der
 * Schutz bestand bisher ausschließlich indirekt über sorgfältige JOINs auf
 * coupon in den Controllern/Services. Ein Query ohne diesen JOIN (App-
 * Rolle, siehe 2024_02_01_000457) hätte die Produktbündel-Konfiguration
 * JEDER Bootsschule lesen können, nicht nur der des aktuellen Mandanten --
 * exakt dieselbe Lücke, die exam_session_question dort bereits für den
 * Prüfungs-Kontext behoben hat. Da die Tabelle keine eigene tenant_id
 * führt, verweist die Policy stattdessen auf die bereits vorhandene
 * Mandantengrenze der referenzierten coupon-Zeile.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE coupon_option_product ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY coupon_option_product_isolation ON coupon_option_product
            USING (coupon_id IN (
                SELECT id FROM coupon
                WHERE tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
            ))
            WITH CHECK (coupon_id IN (
                SELECT id FROM coupon
                WHERE tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
            ))");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS coupon_option_product_isolation ON coupon_option_product');
        DB::statement('ALTER TABLE coupon_option_product DISABLE ROW LEVEL SECURITY');
    }
};
