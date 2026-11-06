<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review B21: der bestehende Unique-Index liegt auf (tenant_id, code)
 * -- für globale Kurse (tenant_id IS NULL) verhindert das keine zwei Zeilen
 * mit demselben Code, weil Postgres NULLs in einem Unique-Index paarweise
 * als verschieden behandelt. CourseEditorController::store() validiert die
 * Eindeutigkeit bereits auf Anwendungsebene (Rule::unique(...)->whereNull
 * ('tenant_id')), das schützt aber nicht vor einem Wettlauf zwischen zwei
 * gleichzeitigen Anfragen oder einem direkten DB-Zugriff (Migration/
 * Seeder). Dieser partielle Index schließt genau die Lücke, ohne die
 * bestehende Mehrfachverwendung desselben Codes über verschiedene
 * Bootsschulen hinweg (tenant_id NOT NULL) einzuschränken.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bug-Review R4-5: CREATE UNIQUE INDEX scheitert hart, sobald bereits
        // zwei globale Kurse (tenant_id IS NULL) denselben Code tragen --
        // genau die Lücke, die dieser Index erst schließt, kann auf
        // Produktionsdaten also schon vor dieser Migration zugeschlagen
        // haben. Statt die Migration daran scheitern zu lassen, wird der
        // Code jeder ZWEITEN (und weiteren) Dopplung umbenannt -- die älteste
        // Zeile pro Code bleibt unverändert (das ist die, auf die bestehende
        // Verweise wie existingCourse()/Rule::unique() aller Wahrscheinlichkeit
        // nach schon zeigen), keine Zeile wird gelöscht oder verliert ihre id
        // (Entitlement/ExamRuleSet-Fremdschlüssel bleiben intakt).
        DB::statement(<<<'SQL'
            WITH duplicates AS (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY code
                    ORDER BY created_at ASC, id ASC
                ) AS rn
                FROM course_definition
                WHERE tenant_id IS NULL
            )
            UPDATE course_definition
            SET code = LEFT(course_definition.code, 50) || '-DUP-' || course_definition.id
            FROM duplicates
            WHERE course_definition.id = duplicates.id AND duplicates.rn > 1
        SQL);

        DB::statement('CREATE UNIQUE INDEX course_definition_global_code_unique ON course_definition (code) WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS course_definition_global_code_unique');
    }
};
