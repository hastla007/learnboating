<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sicherheitsreview M7: app_user hatte bislang kein RLS -- ursprünglich
 * ausgelassen, weil die Tabelle global ist (ein Nutzer kann Mitglied
 * mehrerer Bootsschulen sein, hat also kein eigenes tenant_id) und einige
 * Identitäts-Abläufe (Login, Registrierung, Passwort-Reset) absichtlich
 * plattformweit nach einer E-Mail-Adresse suchen müssen, bevor überhaupt
 * ein Mandant bekannt ist. Seit app_user aber auch Geburtsdatum und
 * Adresse enthält (siehe 000170/000434), ist die fehlende DB-seitige
 * Absicherung ein echtes Leck: jede Query der App-Rolle sah bisher JEDEN
 * Nutzer JEDER Bootsschule, unabhängig vom aktuell aufgelösten Mandanten --
 * eine fehlende Tenant-Prüfung im Anwendungscode (die einzige bisherige
 * Schutzschicht) hätte das sofort offengelegt.
 *
 * Nur SELECT wird eingeschränkt (siehe READ-Policy unten). INSERT/UPDATE/
 * DELETE bleiben bewusst uneingeschränkt -- die Schreibpfade (Registrierung,
 * Einladung, Profil, Kontolöschung über AccountErasureService) sind schon
 * durch Anwendungslogik abgesichert, und mindestens ein Fall
 * (AccountErasureService::deleteAccountEntirely() löscht die Zeile, NACHDEM
 * die letzte Mitgliedschaft bereits entfernt wurde, bei weiterhin gesetztem
 * Tenant-Kontext) würde durch eine analog eingeschränkte Schreib-Policy
 * kaputtgehen.
 *
 * Die Lese-Policy lässt eine Zeile sichtbar, wenn:
 *   (a) kein echter Tenant-Kontext gesetzt ist (Login/Registrierung/
 *       Passwort-Reset laufen VOR jeder Mandantenauflösung -- siehe dazu
 *       auch die ResolveTenant-Änderung in dieser Migration begleitend,
 *       die /forgot-password und /reset-password zusätzlich zu /login und
 *       /logout als mandantenunabhängig markiert), ODER
 *   (b) die Zeile über tenant_user mit dem aktuellen Mandanten verknüpft
 *       ist.
 * Die zwei verbleibenden Stellen, die plattformweit per E-Mail suchen
 * MÜSSEN, während ein Mandant bereits gesetzt ist (ParticipantController::
 * invite()'s Bestandskonto-Erkennung, RegisteredUserController::store()'s
 * Duplikat-Erkennung), wurden im selben Zug explizit auf die RLS-freie
 * Owner-Verbindung "pgsql_admin" umgestellt (begleitende Code-Änderung,
 * nicht Teil dieser Migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app_user ENABLE ROW LEVEL SECURITY');

        // NULLIF(..., ''): current_setting(..., true) liefert nur dann NULL,
        // wenn die GUC auf dieser Verbindung noch nie gesetzt wurde -- ein
        // zurückgesetzter Wert (RESET bzw. Ende eines SET LOCAL) landet
        // stattdessen auf einem leeren String, und ''::uuid wirft eine harte
        // Exception. Ohne das NULLIF würde außerdem schon der BLOSSE
        // Versuch, einen leeren String zu casten, die Policy zum Absturz
        // bringen, selbst wenn ein früherer OR-Zweig schon "wahr" ist --
        // Postgres garantiert bei OR-Verknüpfungen mit Unterabfragen (hier
        // das EXISTS) keine Auswertung in Schreibreihenfolge (siehe Migration
        // 000456, die genau dieses Muster für die anderen RLS-Policies
        // einführt). Direkt NULLIF-geschützt geschrieben statt es wie dort
        // nachträglich zu patchen.
        DB::statement("CREATE POLICY app_user_select_visibility ON app_user
            FOR SELECT
            USING (
                NULLIF(current_setting('app.current_tenant_id', true), '') IS NULL
                OR NULLIF(current_setting('app.current_tenant_id', true), '')::uuid = '00000000-0000-0000-0000-000000000000'::uuid
                OR EXISTS (
                    SELECT 1 FROM tenant_user
                    WHERE tenant_user.user_id = app_user.id
                    AND tenant_user.tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                )
            )");

        DB::statement('CREATE POLICY app_user_insert_unrestricted ON app_user FOR INSERT WITH CHECK (true)');
        DB::statement('CREATE POLICY app_user_update_unrestricted ON app_user FOR UPDATE USING (true) WITH CHECK (true)');
        DB::statement('CREATE POLICY app_user_delete_unrestricted ON app_user FOR DELETE USING (true)');
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS app_user_select_visibility ON app_user');
        DB::statement('DROP POLICY IF EXISTS app_user_insert_unrestricted ON app_user');
        DB::statement('DROP POLICY IF EXISTS app_user_update_unrestricted ON app_user');
        DB::statement('DROP POLICY IF EXISTS app_user_delete_unrestricted ON app_user');
        DB::statement('ALTER TABLE app_user DISABLE ROW LEVEL SECURITY');
    }
};
