<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RLS-Konzept laut Technikpaket: Die Anwendung setzt nach erfolgreicher
     * Tenant-Auflösung pro Request `SET app.current_tenant_id = '<uuid>'`
     * (siehe TenantContext middleware). Ein UI-Filter allein gilt nicht als
     * Schutz -- die Absicherung erfolgt zusätzlich auf DB-Ebene.
     */
    private array $tables = [
        'tenant_branding',
        'tenant_user',
        'course_definition',
        'entitlement',
        'attempt',
        'progress',
        'favorite',
        'exam_session',
        'audit_log',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        }

        $strict = ['tenant_branding', 'tenant_user', 'entitlement', 'attempt', 'progress', 'favorite', 'exam_session'];
        foreach ($strict as $table) {
            DB::statement("CREATE POLICY {$table}_isolation ON {$table}
                USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
                WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");
        }

        DB::statement("CREATE POLICY course_definition_isolation ON course_definition
            USING (tenant_id IS NULL OR tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id IS NULL OR tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        DB::statement("CREATE POLICY audit_log_isolation ON audit_log
            USING (tenant_id IS NULL OR tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id IS NULL OR tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        // Least-Privilege-Laufzeitrolle: Der Webprozess verbindet sich als
        // boatingschool_app (siehe config/database.php "pgsql"). Diese Rolle
        // ist NICHT Tabelleneigentümer, daher greifen die RLS-Policies für sie
        // ohne weiteres FORCE. Migrations und Seeder laufen bewusst über die
        // Owner-Verbindung "pgsql_admin" und umgehen RLS absichtlich.
        // Die Rolle selbst wird von Infra/Ops vorab angelegt (CREATE ROLE
        // erfordert CREATEROLE, das die Migrations-Rolle bewusst nicht hat);
        // hier werden ihr nur die laufenden Rechte auf die Tabellen erteilt.
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT CONNECT ON DATABASE '.DB::getDatabaseName().' TO '.$appRole);
            DB::statement("GRANT USAGE ON SCHEMA public TO {$appRole}");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$appRole}");
            DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$appRole}");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
