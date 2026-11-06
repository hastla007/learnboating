<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versandstatus der Willkommensbox-Bestellung (siehe WillkommensboxOrder):
 * die Bootsschule markiert die Bestellung als versendet, optional mit
 * Trackingnummer, sobald sie das Paket losgeschickt hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('willkommensbox_order', function (Blueprint $table) {
            $table->timestampTz('shipped_at')->nullable()->after('note');
            $table->string('tracking_number')->nullable()->after('shipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('willkommensbox_order', function (Blueprint $table) {
            $table->dropColumn(['shipped_at', 'tracking_number']);
        });
    }
};
