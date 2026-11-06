<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Review (Admin): Teams-/Zoom-Links sind oft länger als 255 Zeichen; die
 * Validierung erlaubt 500, die Spalte war varchar(255) -- ein Terminvorschlag
 * mit langem Link scheiterte mit einem Datenbankfehler.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE praxis_exam_appointment ALTER COLUMN online_meeting_url TYPE varchar(500)');
    }

    public function down(): void
    {
        // Bewusst nicht zurück auf 255: längere Links würden abgeschnitten bzw. das Zurückrollen scheitern.
    }
};
