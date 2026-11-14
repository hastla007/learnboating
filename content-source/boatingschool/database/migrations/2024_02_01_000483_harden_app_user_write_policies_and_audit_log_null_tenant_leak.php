<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Datenbank-Absicherung, drei zusätzliche Härtungslücken, heute
 * nicht ausnutzbar): systematischer Abgleich aller RLS-Policy-Migrationen
 * gegen das etablierte Muster (USING + WITH CHECK, beide mit derselben,
 * tatsächlich einschränkenden Bedingung -- siehe z. B. entitlement_isolation,
 * tenant_user_isolation) deckte drei Stellen auf, die davon abweichen. Keine
 * davon ist über den aktuellen Anwendungscode ausnutzbar (der schreibt/liest
 * an allen betroffenen Stellen bereits korrekt mandantengebunden) -- es sind
 * zusätzliche Verteidigungslinien gegen einen HYPOTHETISCHEN künftigen
 * Query-Bug, analog zum Grundgedanken von Migration 2024_02_01_000070
 * ("ein UI-Filter allein gilt nicht als Schutz").
 *
 * (1) Rechte für Nutzer-Updates: app_user_update_unrestricted hatte
 *     `WITH CHECK (true)` -- die Zeile, die eine UPDATE-Anweisung berühren
 *     darf, ist zwar (siehe unten) bereits durch die SELECT-Policy
 *     eingeschränkt, das Ergebnis des Updates selbst aber nicht: ein Query-
 *     Bug ohne einschränkendes WHERE könnte auf jeder sichtbaren Zeile JEDE
 *     Spalte auf einen beliebigen Wert setzen. WITH CHECK bekommt jetzt
 *     dieselbe Bedingung wie app_user_select_visibility (siehe 000468):
 *     kein echter Mandantenkontext, eigene Zeile (current_user_id), oder
 *     Mitgliedschaft beim aktuellen Mandanten.
 *
 * (2) Protokollzeilen ohne Schule: audit_log_select_isolation (siehe 000457)
 *     ließ zusätzlich zur eigenen Zeile auch jede Zeile mit tenant_id IS
 *     NULL durch -- anders als bei course_definition/coupon (echter,
 *     ABSICHTLICH plattformweit geteilter Content) ist eine audit_log-Zeile
 *     ohne Schule aber kein für jeden Mandanten bestimmter Datensatz,
 *     sondern eine verwaiste Zeile (Schule inzwischen gelöscht, siehe
 *     audit_log.tenant_id -> nullOnDelete() in 2024_02_01_000060) oder eine
 *     potenzielle künftige plattformweite Protokollzeile. Kein lesender
 *     Zugriff über die normale App-Verbindung (siehe
 *     AdminDashboardController::index(), $recentActivity) braucht diese
 *     Zeilen -- sie filtern ohnehin immer explizit nach der eigenen
 *     tenant_id. Die Policy verlangt jetzt durchgängig eine echte
 *     Übereinstimmung.
 *
 * (3) Zwei Regeln ohne die übliche Härtung: app_user_update_unrestricted
 *     (USING true) und app_user_delete_unrestricted (USING true) hatten --
 *     anders als jede andere Policy in diesem Schema -- gar keine
 *     Bedingung, sondern buchstäblich `true`. Für DELETE ist das schon
 *     heute PRAKTISCH redundant: Postgres verlangt für UPDATE/DELETE
 *     zusätzlich zur eigenen Policy auch Sichtbarkeit über die SELECT-
 *     Policy der Tabelle (siehe AccountErasureService::leaveTenant(), Zeile
 *     zu "M7 (Folgefehler)", die genau dieses Verhalten bereits dokumentiert
 *     und sich bewusst darauf verlässt) -- die Einschränkung war also schon
 *     immer wirksam, nur nicht EXPLIZIT in der Policy selbst sichtbar,
 *     sondern nur als Nebeneffekt der SELECT-Policy. Beide Policies bekommen
 *     jetzt dieselbe Bedingung explizit in die eigene USING-Klausel -- für
 *     DELETE rein deklarativ (ändert nichts am tatsächlichen Verhalten,
 *     macht die Einschränkung aber unabhängig von diesem Nebeneffekt), für
 *     UPDATE als neue, zusätzliche Einschränkung (siehe (1) oben).
 *
 *     app_user_insert_unrestricted (WITH CHECK true) bleibt bewusst
 *     unverändert: ParticipantController::store() legt einen neuen Nutzer
 *     bei gesetztem Mandantenkontext an, BEVOR die zugehörige
 *     tenant_user-Mitgliedschaft existiert (die im selben Request erst
 *     danach eingefügt wird) -- dieselbe Bedingung würde hier fälschlich
 *     greifen und jede Teilnehmer-Einladung mit "permission denied"
 *     blockieren. Für INSERT existiert keine sinnvolle, gleichwertig enge
 *     Bedingung, die beide legitimen Fälle (Registrierung ohne
 *     Mandantenkontext, Einladung MIT Mandantenkontext aber ohne
 *     Mitgliedschaft) weiterhin zulässt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $visibilityCondition = <<<'SQL'
            (
                NULLIF(current_setting('app.current_tenant_id', true), '') IS NULL
                OR NULLIF(current_setting('app.current_tenant_id', true), '')::uuid = '00000000-0000-0000-0000-000000000000'::uuid
                OR (
                    NULLIF(current_setting('app.current_user_id', true), '') IS NOT NULL
                    AND id = NULLIF(current_setting('app.current_user_id', true), '')::uuid
                )
                OR EXISTS (
                    SELECT 1 FROM tenant_user
                    WHERE tenant_user.user_id = app_user.id
                    AND tenant_user.tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                )
            )
        SQL;

        DB::statement('DROP POLICY audit_log_select_isolation ON audit_log');
        DB::statement("CREATE POLICY audit_log_select_isolation ON audit_log FOR SELECT
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        DB::statement('DROP POLICY app_user_update_unrestricted ON app_user');
        DB::statement("CREATE POLICY app_user_update_isolation ON app_user
            FOR UPDATE
            USING ({$visibilityCondition})
            WITH CHECK ({$visibilityCondition})");

        DB::statement('DROP POLICY app_user_delete_unrestricted ON app_user');
        DB::statement("CREATE POLICY app_user_delete_isolation ON app_user
            FOR DELETE
            USING ({$visibilityCondition})");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS app_user_delete_isolation ON app_user');
        DB::statement('CREATE POLICY app_user_delete_unrestricted ON app_user FOR DELETE USING (true)');

        DB::statement('DROP POLICY IF EXISTS app_user_update_isolation ON app_user');
        DB::statement('CREATE POLICY app_user_update_unrestricted ON app_user FOR UPDATE USING (true) WITH CHECK (true)');

        DB::statement('DROP POLICY IF EXISTS audit_log_select_isolation ON audit_log');
        DB::statement("CREATE POLICY audit_log_select_isolation ON audit_log FOR SELECT
            USING (tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");
    }
};
