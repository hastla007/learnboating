<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jede Bootsschule entscheidet unabhängig voneinander, über welche Kanäle
 * (Mail, Telefon, WhatsApp) sie für Schüler im Kontakt-Widget erreichbar
 * ist -- Telefonnummer/Support-E-Mail können hinterlegt sein, ohne dass der
 * jeweilige Kanal den Schülern angezeigt wird. WhatsApp hatte diesen
 * Umschalter (whatsapp_enabled) bereits; email_support_enabled und
 * phone_support_enabled ergänzen ihn für die anderen beiden Kanäle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->boolean('email_support_enabled')->default(false)->after('support_email');
            $table->boolean('phone_support_enabled')->default(false)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn(['email_support_enabled', 'phone_support_enabled']);
        });
    }
};
