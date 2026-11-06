<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gerätesimulation Funk: Lernstand je Gerät und Lektion in
 * radio_training_progress mit item_type "geraet" und item_key
 * "<gerät>:<lektion>" (z. B. "ic-m505:notalarm-senden"); answers hält die
 * beste geschaffte Stufe (1 mit Hilfe, 2 nur Hinweis, 3 allein).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE radio_training_progress DROP CONSTRAINT radio_training_progress_item_type_check');
        DB::statement("ALTER TABLE radio_training_progress ADD CONSTRAINT radio_training_progress_item_type_check CHECK (item_type IN ('praxis', 'text', 'vocab', 'geraet'))");
    }

    public function down(): void
    {
        DB::table('radio_training_progress')->where('item_type', 'geraet')->delete();
        DB::statement('ALTER TABLE radio_training_progress DROP CONSTRAINT radio_training_progress_item_type_check');
        DB::statement("ALTER TABLE radio_training_progress ADD CONSTRAINT radio_training_progress_item_type_check CHECK (item_type IN ('praxis', 'text', 'vocab'))");
    }
};
