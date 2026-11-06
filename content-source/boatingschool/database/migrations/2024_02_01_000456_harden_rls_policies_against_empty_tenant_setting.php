<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sicherheitsreview M5: current_setting('app.current_tenant_id', true)
     * liefert nur NULL, wenn die GUC auf der Verbindung nie gesetzt wurde.
     * Sie kann aber auch auf einen leeren String stehen (z. B. ein Pooler,
     * der eine Verbindung zwischen zwei Clients weitergibt, ohne dass
     * zwischendurch RESET lief) -- ''::uuid wirft dann eine harte Postgres-
     * Exception statt die Policy einfach greifen zu lassen. NULLIF fängt
     * das ab, sodass ein leerer Wert wie "nie gesetzt" behandelt wird und
     * die Policy (fail-closed) keine Zeilen zurückgibt, statt einen 500er
     * zu produzieren.
     *
     * Betrifft keine aktive Schwachstelle in der aktuellen Verbindungsart
     * (jeder Request setzt die GUC frisch, siehe TenantContext::clear());
     * die eigentliche Absicherung gegen Transaction-Pooling-Pooler ist
     * organisatorisch (README: PgBouncer o. ä. nur im Session-Modus
     * betreiben). Diese Migration ist eine zusätzliche, risikofreie
     * Verteidigungslinie auf DB-Ebene.
     */
    public function up(): void
    {
        // "NOT LIKE '%NULLIF(...%'" macht den Lauf idempotent: eine Policy,
        // die schon gehärtet ist (z. B. nach einem erneuten Aufruf ohne
        // vorherigen echten Rollback), wird nicht ein zweites Mal verschachtelt.
        $policies = DB::select(<<<'SQL'
            SELECT
                p.polname AS policy_name,
                c.relname AS table_name,
                pg_get_expr(p.polqual, p.polrelid) AS using_expr,
                pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
            FROM pg_policy p
            JOIN pg_class c ON c.oid = p.polrelid
            WHERE (
                pg_get_expr(p.polqual, p.polrelid) LIKE '%current_setting(''app.current_tenant_id''%'
                OR pg_get_expr(p.polwithcheck, p.polrelid) LIKE '%current_setting(''app.current_tenant_id''%'
            )
            AND pg_get_expr(p.polqual, p.polrelid) NOT LIKE '%NULLIF(current_setting(''app.current_tenant_id''%'
            AND (pg_get_expr(p.polwithcheck, p.polrelid) IS NULL OR pg_get_expr(p.polwithcheck, p.polrelid) NOT LIKE '%NULLIF(current_setting(''app.current_tenant_id''%')
            ORDER BY c.relname
        SQL);

        $needle = "current_setting('app.current_tenant_id'::text, true)";
        $replacement = "NULLIF(current_setting('app.current_tenant_id'::text, true), '')";

        foreach ($policies as $policy) {
            $using = str_replace($needle, $replacement, $policy->using_expr);
            $check = $policy->check_expr !== null
                ? str_replace($needle, $replacement, $policy->check_expr)
                : null;

            DB::statement("DROP POLICY {$policy->policy_name} ON {$policy->table_name}");

            $sql = "CREATE POLICY {$policy->policy_name} ON {$policy->table_name} USING ({$using})";
            if ($check !== null) {
                $sql .= " WITH CHECK ({$check})";
            }

            DB::statement($sql);
        }
    }

    public function down(): void
    {
        $policies = DB::select(<<<'SQL'
            SELECT
                p.polname AS policy_name,
                c.relname AS table_name,
                pg_get_expr(p.polqual, p.polrelid) AS using_expr,
                pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
            FROM pg_policy p
            JOIN pg_class c ON c.oid = p.polrelid
            WHERE pg_get_expr(p.polqual, p.polrelid) LIKE '%NULLIF(current_setting(''app.current_tenant_id''%'
               OR pg_get_expr(p.polwithcheck, p.polrelid) LIKE '%NULLIF(current_setting(''app.current_tenant_id''%'
            ORDER BY c.relname
        SQL);

        // Postgres rendert das zweite NULLIF-Argument beim Zurücklesen immer
        // mit explizitem Cast (''::text), nie als bloßes '' -- die Suche
        // muss exakt so aussehen wie pg_get_expr es tatsächlich ausgibt.
        $needle = "NULLIF(current_setting('app.current_tenant_id'::text, true), ''::text)";
        $replacement = "current_setting('app.current_tenant_id'::text, true)";

        foreach ($policies as $policy) {
            $using = str_replace($needle, $replacement, $policy->using_expr);
            $check = $policy->check_expr !== null
                ? str_replace($needle, $replacement, $policy->check_expr)
                : null;

            DB::statement("DROP POLICY {$policy->policy_name} ON {$policy->table_name}");

            $sql = "CREATE POLICY {$policy->policy_name} ON {$policy->table_name} USING ({$using})";
            if ($check !== null) {
                $sql .= " WITH CHECK ({$check})";
            }

            DB::statement($sql);
        }
    }
};
