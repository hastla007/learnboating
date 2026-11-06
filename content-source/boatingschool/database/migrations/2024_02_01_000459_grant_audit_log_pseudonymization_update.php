<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * DSGVO-Review D2: Sicherheitsreview M7 hatte der App-Rolle UPDATE auf
     * audit_log komplett entzogen (append-only -- kein Feature nutzte es).
     * Die Pseudonymisierung personenbezogener Daten im JSON bei einer
     * Kontolöschung (AccountErasureService) braucht aber genau das, für
     * genau diese zwei Spalten. Ein spaltenbeschränktes GRANT erlaubt nur
     * before_data/after_data zu überschreiben -- action, entity_type,
     * tenant_id, actor_user_id usw. bleiben für die App-Rolle weiterhin
     * unveränderlich, und die bestehende RLS-Policy beschränkt jedes
     * UPDATE ohnehin auf den aktuellen Mandanten.
     */
    public function up(): void
    {
        // Bug-Review M7 (Folgefehler): GRANT ... TO <rolle> schlägt mit einem
        // Postgres-Fehler fehl, wenn die Rolle gar nicht existiert (z. B.
        // migrate:fresh auf einer frisch angelegten DB, bevor die App-Rolle
        // separat provisioniert wurde) -- analog zu 2024_02_01_000210/_000447.
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT UPDATE (before_data, after_data) ON audit_log TO {$appRole}");
        }

        // M7 definierte nur SELECT- und INSERT-Policies für audit_log -- ohne
        // eine eigene UPDATE-Policy würde RLS das Statement sonst still auf
        // 0 betroffene Zeilen filtern (kein Fehler, aber auch keine
        // Pseudonymisierung), statt es sauber auf den eigenen Mandanten zu
        // beschränken.
        DB::statement("CREATE POLICY audit_log_update_isolation ON audit_log FOR UPDATE
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS audit_log_update_isolation ON audit_log');

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("REVOKE UPDATE (before_data, after_data) ON audit_log FROM {$appRole}");
        }
    }
};
