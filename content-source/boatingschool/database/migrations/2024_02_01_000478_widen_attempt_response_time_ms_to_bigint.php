<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Fehlerseite bei Antwort auf eine länger als 25 Tage offene
 * Frage): LearningController::storeAttempt() berechnet response_time_ms
 * serverseitig aus der Differenz zur eigenen Uhr bei Eingang (siehe
 * Bug-Review B19) -- attempt.response_time_ms war dafür bisher als
 * "integer" (4 Byte, max. 2.147.483.647) angelegt. 2.147.483.647 ms
 * entsprechen rund 24 Tagen, 20 Stunden; eine Frage, die länger offen
 * blieb, erzeugte dadurch einen Wert über dem Spaltenlimit -- der INSERT
 * scheiterte mit einer unabgefangenen "integer out of range"-Exception
 * (HTTP 500) statt die Antwort normal zu speichern. "bigint" deckt den
 * tatsächlich möglichen Wertebereich ab, ohne die Sinnhaftigkeit der
 * Kennzahl selbst zu verändern.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE attempt ALTER COLUMN response_time_ms TYPE bigint');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attempt ALTER COLUMN response_time_ms TYPE integer');
    }
};
