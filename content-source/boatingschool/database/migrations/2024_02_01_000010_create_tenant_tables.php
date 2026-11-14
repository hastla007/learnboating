<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE tenant_status AS ENUM ('trial','active','suspended','closed')");
        DB::statement("CREATE TYPE tenant_role AS ENUM ('owner','admin','instructor','staff','learner','support')");
        DB::statement("CREATE TYPE content_status AS ENUM ('draft','review','approved','published','deprecated')");
        DB::statement("CREATE TYPE rights_status AS ENUM ('unknown','review','licensed','public','restricted')");

        Schema::create('tenant', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('slug', 120)->unique();
            $table->string('name');
            $table->string('default_locale', 16)->default('de-DE');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenant ADD COLUMN status tenant_status NOT NULL DEFAULT 'trial'");

        Schema::create('media_asset', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('asset_key', 160)->unique();
            $table->string('media_type', 40);
            $table->text('storage_path');
            $table->string('mime_type', 120)->nullable();
            $table->text('alt_text')->nullable();
            $table->text('source')->nullable();
            $table->string('rights_owner')->nullable();
            $table->text('license_reference')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE media_asset ADD COLUMN rights_status rights_status NOT NULL DEFAULT 'unknown'");
        DB::statement("ALTER TABLE media_asset ADD COLUMN status content_status NOT NULL DEFAULT 'draft'");

        Schema::create('tenant_branding', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('logo_asset_id')->nullable();
            $table->foreign('logo_asset_id')->references('id')->on('media_asset');
            $table->uuid('favicon_asset_id')->nullable();
            $table->foreign('favicon_asset_id')->references('id')->on('media_asset');
            $table->string('primary_color', 16)->nullable();
            $table->string('secondary_color', 16)->nullable();
            $table->string('support_email', 320)->nullable();
            $table->string('legal_name')->nullable();
            $table->text('imprint_url')->nullable();
            $table->text('privacy_url')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['tenant_id', 'user_id']);
        });
        DB::statement("ALTER TABLE tenant_user ADD COLUMN role tenant_role NOT NULL DEFAULT 'learner'");
        DB::statement("ALTER TABLE tenant_user ADD COLUMN status user_status NOT NULL DEFAULT 'active'");
        DB::statement('CREATE INDEX idx_tenant_user_user ON tenant_user(user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenant_branding');
        Schema::dropIfExists('media_asset');
        Schema::dropIfExists('tenant');
        DB::statement('DROP TYPE IF EXISTS rights_status');
        DB::statement('DROP TYPE IF EXISTS content_status');
        DB::statement('DROP TYPE IF EXISTS tenant_role');
        DB::statement('DROP TYPE IF EXISTS tenant_status');
    }
};
