<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erklärendes Feedback je Frage, das nach dem Beantworten unter der
 * bestehenden "Richtig!"/"Leider falsch."-Meldung angezeigt wird (siehe
 * ImportContentFeedback und learning/question.blade.php). Reines
 * Zusatzmaterial zur bestehenden Revision, keine inhaltliche Änderung der
 * Frage/Antworten -- ein Backfill setzt es deshalb direkt auf der
 * bestehenden Revision statt eine neue zu erzeugen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->text('feedback_correct')->nullable()->after('question_text');
            $table->text('feedback_incorrect')->nullable()->after('feedback_correct');
        });
    }

    public function down(): void
    {
        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->dropColumn(['feedback_correct', 'feedback_incorrect']);
        });
    }
};
