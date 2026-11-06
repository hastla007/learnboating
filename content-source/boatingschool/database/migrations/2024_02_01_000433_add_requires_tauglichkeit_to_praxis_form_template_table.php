<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schaltet pro Praxisformular (= faktisch pro Kurs, siehe PraxisFormTemplate)
 * einen Hinweis für den Lerner frei, dass er für die Prüfung eine ärztliche
 * Tauglichkeitsbescheinigung benötigt (siehe praxis-pruefung/index.blade.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('praxis_form_template', function (Blueprint $table) {
            $table->boolean('requires_tauglichkeit')->default(false)->after('min_progress_percent');
        });
    }

    public function down(): void
    {
        Schema::table('praxis_form_template', function (Blueprint $table) {
            $table->dropColumn('requires_tauglichkeit');
        });
    }
};
