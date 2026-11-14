<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * app_user ist die stabile Identität laut schema.sql. Passwort/Remember-Token
     * kommen als Platzhalter dazu, bis die Auth-Provider-Entscheidung (Sprint 0
     * Gate) getroffen ist.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement("CREATE TYPE user_status AS ENUM ('invited','active','suspended','closed')");

        Schema::create('app_user', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('external_identity')->nullable()->unique();
            $table->string('email', 320)->unique();
            $table->string('display_name')->nullable();
            $table->string('locale', 16)->default('de-DE');
            $table->string('password');
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement("ALTER TABLE app_user ADD COLUMN status user_status NOT NULL DEFAULT 'invited'");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('app_user');
        DB::statement('DROP TYPE IF EXISTS user_status');
    }
};
