<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Superadmin: anpassbare Mailtexte (Betreff, Einleitung, Schluss) je Mailart, siehe App\Support\MailTemplates. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('platform_setting', 'mail_templates')) {
            Schema::table('platform_setting', fn (Blueprint $table) => $table->jsonb('mail_templates')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('platform_setting', fn (Blueprint $table) => $table->dropColumn('mail_templates'));
    }
};
