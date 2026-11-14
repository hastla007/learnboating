<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenant')->nullOnDelete();
            $table->uuid('actor_user_id')->nullable();
            $table->foreign('actor_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->string('action', 120);
            $table->string('entity_type', 120);
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('CREATE INDEX idx_audit_tenant_time ON audit_log(tenant_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
