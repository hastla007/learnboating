<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bug-Review (App-Rolle darf in tenant schreiben/löschen): die initiale
     * Migration (2024_02_01_000070) hat der App-Rolle pauschal SELECT/
     * INSERT/UPDATE/DELETE auf alle zu dem Zeitpunkt existierenden Tabellen
     * erteilt, darunter auch "tenant" -- die spätere Härtungsmigration
     * 2024_02_01_000457 hat das für 24 andere, ausschließlich von
     * Migrationen/Seedern/Superadmin-Bereich beschriebene Tabellen bereits
     * auf SELECT zurückgenommen, "tenant" dabei aber übersehen. "tenant"
     * selbst trägt zudem keine RLS-Policy (es IST die Mandantengrenze), war
     * also ausschließlich durch das GRANT selbst geschützt -- ein über die
     * eingeschränkte App-Rolle laufender Bug oder eine SQL-Injection hätte
     * jeden Mandanten anlegen, verändern oder löschen können, Letzteres mit
     * Kaskade über rund 25 Fremdschlüssel (fast jede mandantengebundene
     * Tabelle referenziert tenant.id mit ON DELETE CASCADE).
     *
     * Tenant::create()/->update()/->delete() laufen ausschließlich im
     * Superadmin-Bereich (Superadmin\TenantController), dessen Routengruppe
     * bereits komplett über die Owner-Verbindung läuft (siehe
     * UseAdminDatabaseConnection-Middleware) -- die App-Rolle selbst nutzt
     * dieses Schreibrecht nirgends (geprüft per Codesuche nach
     * Tenant::create()/->update()/->delete() außerhalb von
     * Superadmin-Controllern, Migrationen und Seedern).
     */
    public function up(): void
    {
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON tenant FROM {$appRole}");
        }
    }

    public function down(): void
    {
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON tenant TO {$appRole}");
        }
    }
};
