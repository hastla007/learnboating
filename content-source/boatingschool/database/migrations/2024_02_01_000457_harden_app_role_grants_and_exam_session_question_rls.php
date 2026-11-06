<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sicherheitsreview M7 (Defense in Depth): die initiale Migration
     * (2024_02_01_000070) hat der App-Rolle pauschal SELECT/INSERT/UPDATE/
     * DELETE auf alle zu dem Zeitpunkt existierenden Tabellen erteilt --
     * darunter auch plattformweiten, mandantenunabhängigen Content
     * (Fragenkatalog, Module, Produkte, Plattform-Einstellungen usw.), den
     * ausschließlich Migrationen/Seeder und der Superadmin-Bereich
     * schreiben (letzterer läuft bereits komplett über die Owner-Verbindung,
     * siehe UseAdminDatabaseConnection-Middleware auf der "superadmin"-
     * Routengruppe). Die App-Rolle selbst nutzt dieses Schreibrecht nirgends
     * -- geprüft per Codesuche nach ::create()/->update()/->delete() auf den
     * betroffenen Models außerhalb von Superadmin-Controllern, Migrationen
     * und Seedern. Entzug auf SELECT folgt exakt dem Muster, das
     * "pruefungsausschuss" (2024_02_01_000447) schon hat.
     */
    private array $globalContentTables = [
        'addon_template', 'content_answer', 'content_question', 'content_question_revision',
        'coupon_option_product', 'course_definition', 'course_module', 'exam_blueprint',
        'exam_paper', 'exam_paper_question', 'exam_rule_set', 'module', 'module_content',
        'navigation_question', 'navigation_task', 'platform_setting', 'praxis_form_template',
        'praxis_task', 'product', 'question_media', 'tip', 'tip_category',
        'video_lesson', 'video_lesson_question', 'video_lesson_step', 'video_module',
    ];

    public function up(): void
    {
        // Bug-Review M7 (Folgefehler): REVOKE ... FROM <rolle> schlägt mit
        // einem Postgres-Fehler fehl, wenn die Rolle gar nicht existiert
        // (z. B. migrate:fresh auf einer frisch angelegten DB, bevor die
        // App-Rolle separat provisioniert wurde) -- anders als die GRANTs
        // in anderen Migrationen (siehe 2024_02_01_000210, _000447) prüfte
        // diese Migration das bisher nirgends.
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            foreach ($this->globalContentTables as $table) {
                DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM {$appRole}");
            }

            // coupon: INSERT/DELETE nutzt die App-Rolle nie (neue Codes legt
            // ausschließlich der Superadmin an, siehe Superadmin\CouponController,
            // über die Owner-Verbindung). UPDATE bleibt nötig für den
            // Übernahme-Claim (Admin\CouponController::import, tenant_id NULL ->
            // eigene) und das Einlösen (CouponRedemptionService, setzt nur
            // redeemed_*-Spalten, nie tenant_id) -- die bestehende USING-Klausel
            // der Policy deckt beide Fälle bereits korrekt ab.
            DB::statement("REVOKE INSERT, DELETE ON coupon FROM {$appRole}");

            // audit_log: UPDATE/DELETE nutzt die App-Rolle nirgends (Audit-Log
            // ist append-only). Die bestehende Policy ließ zusätzlich auch
            // INSERT mit tenant_id IS NULL durch; AuditLog::record() wird aus
            // mandantengebundenem Code heraus aber immer mit einer echten
            // tenant_id aufgerufen -- eine platttformweite (tenant_id NULL)
            // Zeile legt, falls künftig gewünscht, nur der Superadmin-Bereich
            // über die Owner-Verbindung an, die ohnehin RLS umgeht.
            DB::statement("REVOKE UPDATE, DELETE ON audit_log FROM {$appRole}");

            // migrations: Migrationen laufen laut README ausschließlich über
            // --database=pgsql_admin; die App-Rolle hat dafür keinen
            // legitimen Anwendungsfall.
            DB::statement("REVOKE ALL ON migrations FROM {$appRole}");
        }

        DB::statement('DROP POLICY audit_log_isolation ON audit_log');
        DB::statement("CREATE POLICY audit_log_select_isolation ON audit_log FOR SELECT
            USING (tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");
        DB::statement("CREATE POLICY audit_log_insert_isolation ON audit_log FOR INSERT
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        // exam_session_question hatte bislang keine eigene tenant_id und
        // damit auch keine RLS-Policy -- Schutz bestand nur indirekt über
        // die Policy auf exam_session (per exam_session_id-FK) und
        // sorgfältige Controller-Queries. Ein Query-Bug ohne JOIN/WHERE auf
        // exam_session hätte Prüfungsantworten anderer Mandanten lesen oder
        // überschreiben können.
        DB::statement('ALTER TABLE exam_session_question ADD COLUMN tenant_id uuid');
        DB::statement('UPDATE exam_session_question esq SET tenant_id = es.tenant_id FROM exam_session es WHERE es.id = esq.exam_session_id');
        DB::statement('ALTER TABLE exam_session_question ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE exam_session_question ADD CONSTRAINT exam_session_question_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE');
        DB::statement('CREATE INDEX idx_exam_session_question_tenant ON exam_session_question(tenant_id)');
        DB::statement('ALTER TABLE exam_session_question ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY exam_session_question_isolation ON exam_session_question
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS exam_session_question_isolation ON exam_session_question');
        DB::statement('ALTER TABLE exam_session_question DISABLE ROW LEVEL SECURITY');
        DB::statement('DROP INDEX IF EXISTS idx_exam_session_question_tenant');
        DB::statement('ALTER TABLE exam_session_question DROP CONSTRAINT IF EXISTS exam_session_question_tenant_id_fkey');
        DB::statement('ALTER TABLE exam_session_question DROP COLUMN IF EXISTS tenant_id');

        DB::statement('DROP POLICY IF EXISTS audit_log_select_isolation ON audit_log');
        DB::statement('DROP POLICY IF EXISTS audit_log_insert_isolation ON audit_log');
        DB::statement("CREATE POLICY audit_log_isolation ON audit_log
            USING (tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        // Bug-Review M7 (Folgefehler): siehe Kommentar in up().
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("REVOKE ALL ON migrations FROM {$appRole}");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON migrations TO {$appRole}");

            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON audit_log TO {$appRole}");

            DB::statement("GRANT SELECT, INSERT, DELETE ON coupon TO {$appRole}");

            foreach ($this->globalContentTables as $table) {
                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO {$appRole}");
            }
        }
    }
};
