<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * DSGVO-Review D2: drei Fremdschlüssel auf app_user hatten kein
     * ON DELETE-Verhalten (Postgres-Standard: RESTRICT) -- das Löschen
     * eines Nutzers, der z. B. je eine Fragenrevision reviewt oder ein
     * Regelwerk verifiziert hat, brach mit einem harten 500er ab, statt
     * den historischen Datensatz (Review/Verifizierung/Terminvorschlag)
     * zu behalten und nur den Personenbezug zu kappen -- analog zu
     * coupon.created_by_user_id/redeemed_by_user_id, audit_log.actor_user_id
     * und tenant_invitation.invited_by_user_id, die das bereits so machen.
     */
    private array $foreignKeys = [
        ['table' => 'content_question_revision', 'constraint' => 'content_question_revision_reviewed_by_foreign', 'column' => 'reviewed_by'],
        ['table' => 'exam_rule_set', 'constraint' => 'exam_rule_set_verified_by_foreign', 'column' => 'verified_by'],
        ['table' => 'praxis_exam_appointment', 'constraint' => 'praxis_exam_appointment_proposed_by_user_id_foreign', 'column' => 'proposed_by_user_id'],
    ];

    public function up(): void
    {
        // proposed_by_user_id war NOT NULL -- muss nullable sein, damit
        // ON DELETE SET NULL überhaupt greifen kann.
        DB::statement('ALTER TABLE praxis_exam_appointment ALTER COLUMN proposed_by_user_id DROP NOT NULL');

        foreach ($this->foreignKeys as $fk) {
            DB::statement("ALTER TABLE {$fk['table']} DROP CONSTRAINT {$fk['constraint']}");
            DB::statement("ALTER TABLE {$fk['table']} ADD CONSTRAINT {$fk['constraint']} FOREIGN KEY ({$fk['column']}) REFERENCES app_user(id) ON DELETE SET NULL");
        }
    }

    public function down(): void
    {
        foreach ($this->foreignKeys as $fk) {
            DB::statement("ALTER TABLE {$fk['table']} DROP CONSTRAINT {$fk['constraint']}");
            DB::statement("ALTER TABLE {$fk['table']} ADD CONSTRAINT {$fk['constraint']} FOREIGN KEY ({$fk['column']}) REFERENCES app_user(id)");
        }

        DB::statement('ALTER TABLE praxis_exam_appointment ALTER COLUMN proposed_by_user_id SET NOT NULL');
    }
};
