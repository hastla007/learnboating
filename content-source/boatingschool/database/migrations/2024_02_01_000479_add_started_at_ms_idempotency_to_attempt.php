<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug-Review X11 (Doppelklick auf "Antworten" zählt doppelt, Frage zu
 * schnell "gefestigt"): LearningService::recordAttempt() legt pro Request
 * bedingungslos einen neuen Attempt an und wendet MasteryCalculator::
 * apply() auf die Progress-Zeile an -- der bereits bestehende Doppelklick-
 * Schutz (Bug-Review N2, Progress::insertOrIgnore() + lockForUpdate())
 * verhindert zwar den HTTP 500 aus dem Primary-Key-Konflikt beim allerersten
 * Attempt zu einer Frage, lässt aber BEIDE nahezu gleichzeitigen Requests
 * ihren eigenen Attempt anlegen und current_streak/mastery_score jeweils
 * EIN ZWEITES MAL erhöhen -- ein Doppelklick auf eine richtige Antwort
 * zählte dadurch als zwei richtige Antworten in Folge, wodurch "gefestigt"
 * (current_streak >= 3) nach nur zwei tatsächlichen Klicks erreicht wurde.
 *
 * started_at_ms kommt unverändert vom Server (LearningController::show(),
 * 'startedAt' => now()->valueOf()) und wird bei jedem Laden der Frage neu
 * vergeben -- ein echter Doppelklick sendet denselben Wert zweimal, ein
 * bewusst erneut beantworteter Durchlauf (neuer GET) bekommt einen neuen.
 * Die Spalte ist bigint (wie response_time_ms seit Bug-Review X8 bereits
 * ist) statt integer, da Millisekunden seit 1970 schon jetzt über
 * 1,7 * 10^12 liegen und damit den 32-Bit-Bereich sprengen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempt', function (Blueprint $table) {
            $table->bigInteger('started_at_ms')->nullable();
        });

        // NULL-Werte gelten in Postgres als paarweise verschieden -- ein
        // Attempt ohne started_at_ms (ältere Zeilen, Clients ohne dieses
        // Feld) bleibt dadurch unbeschränkt, nur ein tatsächlich doppelt
        // gesendeter (tenant, user, revision, started_at_ms)-Wert kollidiert.
        DB::statement('CREATE UNIQUE INDEX attempt_started_at_ms_idempotency_unique ON attempt (tenant_id, user_id, revision_id, started_at_ms)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempt_started_at_ms_idempotency_unique');

        Schema::table('attempt', function (Blueprint $table) {
            $table->dropColumn('started_at_ms');
        });
    }
};
