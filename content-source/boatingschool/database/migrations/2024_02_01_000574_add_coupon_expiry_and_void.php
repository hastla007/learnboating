<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon-Codes mit optionalem Ablaufdatum und Sperre: eine verlorene oder
 * weitergegebene Charge lässt sich ungültig machen, solange sie nicht
 * eingelöst ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon', function (Blueprint $table) {
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coupon', fn (Blueprint $table) => $table->dropColumn(['expires_at', 'voided_at', 'voided_by_user_id']));
    }
};
