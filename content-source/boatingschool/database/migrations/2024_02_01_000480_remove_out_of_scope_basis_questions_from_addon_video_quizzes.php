<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Ergänzungskurs stellt Basisfragen außerhalb des Prüfungs-
 * umfangs): AddBinToSeeVideoCourseSeeder/AddSeeToBinVideoCourseSeeder sind
 * create-once (Guard über $addon->videoModules()->exists()) -- die Korrektur
 * der Seeder-Logik (SBF_BASIS-Fragen beim Spiegeln ausschließen) erreicht
 * eine bereits gesäte Installation dadurch nicht erneut. Diese Migration
 * entfernt die bereits gespiegelten video_lesson_question-Zeilen für die
 * beiden Ergänzungskurse, deren Frage zum Modul SBF_BASIS gehört.
 */
return new class extends Migration
{
    private const ADDON_COURSE_CODES = ['ADD-BIN-TO-SEE', 'ADD-SEE-TO-BIN'];

    public function up(): void
    {
        DB::table('video_lesson_question')
            ->whereIn('video_lesson_id', function ($query) {
                $query->select('video_lesson.id')
                    ->from('video_lesson')
                    ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
                    ->join('course_definition', 'course_definition.id', '=', 'video_module.course_id')
                    ->whereIn('course_definition.code', self::ADDON_COURSE_CODES)
                    ->whereNull('course_definition.tenant_id');
            })
            ->whereIn('question_id', function ($query) {
                $query->select('module_content.question_id')
                    ->from('module_content')
                    ->join('module', 'module.id', '=', 'module_content.module_id')
                    ->where('module.code', 'SBF_BASIS');
            })
            ->delete();
    }

    public function down(): void
    {
        // Datenkorrektur, keine strukturelle Änderung -- nicht umkehrbar
        // (die entfernten Zuordnungen lassen sich nicht rekonstruieren,
        // ein erneuter Seeder-Lauf wäre der richtige Weg zurück zu Testdaten).
    }
};
