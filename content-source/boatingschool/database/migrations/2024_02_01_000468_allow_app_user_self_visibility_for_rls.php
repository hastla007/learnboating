<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sicherheitsreview R3-1 (Folgefehler): app_user_select_visibility
 * (Migration 000461) macht eine Zeile nur sichtbar, wenn kein echter
 * Tenant-Kontext gesetzt ist ODER der Nutzer bereits tenant_user der
 * aktuell aufgelösten Bootsschule ist -- ein bereits eingeloggter Nutzer,
 * der noch KEIN Mitglied dieser Bootsschule ist (z. B. beim Aufruf eines
 * Einladungs-Links auf deren Subdomain), verschwindet dadurch vor sich
 * selbst: EnsureAccountIsActive/Auth::user() lädt ihn über genau diese
 * RLS-beschränkte Verbindung neu, sobald der echte Tenant-Kontext steht,
 * findet die eigene Zeile nicht mehr und behandelt ihn wie nicht
 * eingeloggt. Durch einen Testfall bestätigt (siehe
 * InvitationAcceptRlsVisibilityTest).
 *
 * ResolveTenant::setCurrentUserIdForRls() setzt app.current_user_id
 * zusätzlich aus dem rohen (signierten/verschlüsselten, nicht
 * clientseitig fälschbaren) Session-Wert -- diese Policy lässt eine Zeile
 * zusätzlich sichtbar, wenn ihre id damit übereinstimmt: ein Nutzer sieht
 * damit immer sich selbst, unabhängig von jeder Mitgliedschaft.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP POLICY IF EXISTS app_user_select_visibility ON app_user');

        DB::statement("CREATE POLICY app_user_select_visibility ON app_user
            FOR SELECT
            USING (
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
            )");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS app_user_select_visibility ON app_user');

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
    }
};
