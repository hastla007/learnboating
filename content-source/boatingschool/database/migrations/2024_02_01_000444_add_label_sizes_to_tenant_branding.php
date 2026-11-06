<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welche Papiergrößen die Bootsschule für den Versandlabel-PDF-Download
 * (siehe WillkommensboxLabelController) auf der Teilnehmer-Detailseite
 * angeboten bekommt. A4 ist standardmäßig aktiv (im Büro meist ohnehin
 * vorrätig), A5/A6 (gängige Paketdienst-Labelgröße) muss die Bootsschule
 * bei Bedarf selbst dazuschalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->boolean('label_size_a4')->default(true)->after('willkommensbox_threshold_percent');
            $table->boolean('label_size_a5')->default(false)->after('label_size_a4');
            $table->boolean('label_size_a6')->default(false)->after('label_size_a5');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn(['label_size_a4', 'label_size_a5', 'label_size_a6']);
        });
    }
};
