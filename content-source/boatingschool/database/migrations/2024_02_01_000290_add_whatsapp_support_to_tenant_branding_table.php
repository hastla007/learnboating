<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Click-to-Chat"-WhatsApp-Support: jede Bootsschule kann optional eine
 * WhatsApp-Nummer und einen Begrüßungstext hinterlegen, aus denen ein
 * wa.me-Link generiert wird -- keine WhatsApp-Business-API nötig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('website');
            $table->string('whatsapp_phone', 20)->nullable()->after('whatsapp_enabled');
            $table->string('whatsapp_greeting', 500)->nullable()->after('whatsapp_phone');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_enabled', 'whatsapp_phone', 'whatsapp_greeting']);
        });
    }
};
