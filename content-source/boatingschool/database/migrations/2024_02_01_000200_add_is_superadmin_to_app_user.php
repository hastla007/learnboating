<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plattformweite Rolle, unabhängig von jeder Bootsschule (im Unterschied
 * zu tenant_user.role, die immer an einen konkreten Mandanten gebunden
 * ist). Ein Superadmin verwaltet die gesamte Plattform, nicht nur eine
 * Bootsschule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->boolean('is_superadmin')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->dropColumn('is_superadmin');
        });
    }
};
