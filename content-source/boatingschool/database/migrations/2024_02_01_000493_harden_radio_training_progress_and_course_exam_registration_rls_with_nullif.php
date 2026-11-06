<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (RLS-Härtung fehlt bei zwei neueren Tabellen): Migration
 * 2024_02_01_000456 (Sicherheitsreview M5) hat jede damals bestehende
 * Policy auf NULLIF(current_setting('app.current_tenant_id', true), '')
 * umgestellt, damit ein leerer GUC-Wert (z. B. nach Weitergabe einer
 * Verbindung durch einen Pooler ohne RESET) wie "nie gesetzt" behandelt
 * wird und die Policy fail-closed keine Zeilen liefert, statt dass
 * ''::uuid eine harte Postgres-Exception (HTTP 500) wirft. Die beiden
 * später angelegten Tabellen radio_training_progress (000479) und
 * course_exam_registration (000480) verwenden noch das ungehärtete
 * current_setting(...)::uuid. Gleiche Semantik wie bisher (USING und
 * WITH CHECK: tenant_id = aktueller Mandant), nur mit NULLIF.
 */
return new class extends Migration
{
    private const TABLES = [
        'radio_training_progress' => 'radio_training_progress_isolation',
        'course_exam_registration' => 'course_exam_registration_isolation',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            DB::statement("CREATE POLICY {$policy} ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            DB::statement("CREATE POLICY {$policy} ON {$table}
                USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
                WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");
        }
    }
};
