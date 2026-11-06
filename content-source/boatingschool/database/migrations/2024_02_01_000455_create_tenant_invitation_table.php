<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sicherheitsreview H2 (Account Pre-Hijacking): trifft eine Einladung
 * (ParticipantController::invite) auf eine bereits existierende
 * E-Mail-Adresse, wird die Mitgliedschaft NICHT sofort angelegt -- sonst
 * könnte ein Angreifer die Adresse vorab bei irgendeiner Bootsschule
 * registrieren und später automatisch Mitglied der einladenden Schule
 * werden. Stattdessen entsteht hier ein Eintrag mit signiertem Link; erst
 * wer sich mit genau dieser E-Mail-Adresse einloggt und den Link öffnet
 * (InvitationAcceptController), wird tatsächlich Mitglied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitation', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->string('email', 320);
            $table->string('role', 30)->default('learner');
            $table->uuid('invited_by_user_id')->nullable();
            $table->foreign('invited_by_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('CREATE INDEX idx_tenant_invitation_email ON tenant_invitation(email)');

        DB::statement('ALTER TABLE tenant_invitation ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_invitation_isolation ON tenant_invitation
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_invitation TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitation');
    }
};
