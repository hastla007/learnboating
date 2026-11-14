<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bug-Review (Login ohne Index auf lower(email)): LoginRequest und
     * PasswordResetLinkController lösen die eingegebene Adresse schon
     * länger case-insensitiv auf (whereRaw('lower(email) = ?', ...)), um
     * z. B. "Name@Example.com" und "name@example.com" als dasselbe Konto
     * zu behandeln -- der bisherige, rein auf email selbst liegende
     * UNIQUE-Index (siehe create_users_table) kann ein solches Prädikat in
     * Postgres aber nicht bedienen, jeder Login- und
     * Passwort-Zurücksetzen-Versuch lief deshalb auf einen vollständigen
     * Tabellen-Scan über app_user.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX idx_app_user_lower_email ON app_user (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_app_user_lower_email');
    }
};
