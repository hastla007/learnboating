<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfungsausschüsse (siehe DMYV-Liste der Prüfungszentren/-ausschüsse):
 * fest definierte, bundesweite Adressen -- plattformweit (kein tenant_id,
 * analog zu course_definition mit tenant_id IS NULL), von der Bootsschule
 * im Orte-Tab optional einem Praxisort zugeordnet (siehe
 * tenant_course_location.pruefungsausschuss_id). Eine Kommission kann
 * mehrere Adressen haben (z. B. Geschäftsstelle und separate
 * Postanschrift für Funkscheine), daher ist jede Zeile eine eigene
 * Adresse, unterschieden über "standorttyp".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pruefungsausschuss', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('kommission');
            $table->string('standorttyp')->nullable();
            $table->string('kontaktperson')->nullable();
            $table->string('strasse')->nullable();
            $table->string('plz')->nullable();
            $table->string('stadt')->nullable();
            $table->string('telefon')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('sprechzeiten')->nullable();
            $table->text('hinweis')->nullable();
            $table->string('quelle')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->uuid('pruefungsausschuss_id')->nullable()->after('theorie_lng');
            $table->foreign('pruefungsausschuss_id')->references('id')->on('pruefungsausschuss')->nullOnDelete();
        });

        // Nur lesend für die Laufzeitrolle -- die Liste ist fest vorgegeben
        // und wird ausschließlich über Migrations/Seeder (Owner-Verbindung)
        // gepflegt, nie von der Bootsschule selbst.
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT ON pruefungsausschuss TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->dropForeign(['pruefungsausschuss_id']);
            $table->dropColumn('pruefungsausschuss_id');
        });

        Schema::dropIfExists('pruefungsausschuss');
    }
};
