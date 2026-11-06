<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sicherheitsreview S3: Adresse und Geburtsdatum standen bisher direkt auf
 * app_user -- EINE einzige, kontoweite Zeile, unabhängig davon, bei wie
 * vielen Bootsschulen das Konto Mitglied ist (tenant_user ist eine
 * n:m-Beziehung, siehe PersonalDataExportService/AccountErasureService:
 * "jede Bootsschule ist ein eigener datenschutzrechtlich Verantwortlicher").
 * Ist ein Nutzer gleichzeitig Mitglied bei Schule A und Schule B, sah und
 * änderte Schule A damit dieselbe Adresse/dasselbe Geburtsdatum, die Schule
 * B für ihre eigene Prüfungsanmeldung verwendet (PraxisPruefungController::
 * hasCompleteRegistrationProfile()) -- unabhängig davon, ob der Nutzer bei
 * beiden Schulen überhaupt dieselben Angaben hinterlegen wollte oder sollte.
 *
 * Die Felder ziehen deshalb auf tenant_user um (je Mitgliedschaft eine
 * eigene Kopie) -- Vorname/Nachname/E-Mail/Telefon bleiben bewusst auf
 * app_user, da sie eine Konto-Identität beschreiben, keine
 * bootsschulspezifische Anmeldedaten. Die Rückwärts-Migration (down())
 * kopiert nur die ERSTE gefundene Mitgliedschaft je Nutzer zurück, da eine
 * echte Zusammenführung mehrerer, inzwischen möglicherweise unterschiedlich
 * befüllter Kopien nicht verlustfrei rückgängig gemacht werden kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->string('street')->nullable()->after('status');
            $table->string('postal_code', 20)->nullable()->after('street');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('country', 60)->nullable()->after('city');
            $table->date('birthdate')->nullable()->after('country');
        });

        // Übergangs-Backfill: die bisher einzige, kontoweite Kopie wird auf
        // JEDE bestehende Mitgliedschaft dieses Nutzers kopiert -- der
        // einzige verlustfreie Ausgangspunkt, da es vorher keine
        // Information darüber gab, welche Bootsschule die Angabe zuletzt
        // (oder überhaupt) gepflegt hat. Für die ganz überwiegende Mehrheit
        // (ein Nutzer mit genau einer Mitgliedschaft) ist das exakt
        // äquivalent zum bisherigen Zustand; bei mehreren Mitgliedschaften
        // trennen sich die Kopien erst ab jetzt, mit der nächsten Änderung
        // bei irgendeiner der beteiligten Schulen.
        DB::statement(<<<'SQL'
            UPDATE tenant_user
            SET street = app_user.street,
                postal_code = app_user.postal_code,
                city = app_user.city,
                country = app_user.country,
                birthdate = app_user.birthdate
            FROM app_user
            WHERE app_user.id = tenant_user.user_id
        SQL);

        Schema::table('app_user', function (Blueprint $table) {
            $table->dropColumn(['street', 'postal_code', 'city', 'country', 'birthdate']);
        });
    }

    public function down(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->string('street')->nullable()->after('phone');
            $table->string('postal_code', 20)->nullable()->after('street');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('country', 60)->nullable()->after('city');
            $table->date('birthdate')->nullable()->after('country');
        });

        DB::statement(<<<'SQL'
            UPDATE app_user
            SET street = first_membership.street,
                postal_code = first_membership.postal_code,
                city = first_membership.city,
                country = first_membership.country,
                birthdate = first_membership.birthdate
            FROM (
                SELECT DISTINCT ON (user_id) user_id, street, postal_code, city, country, birthdate
                FROM tenant_user
                ORDER BY user_id, created_at ASC
            ) AS first_membership
            WHERE first_membership.user_id = app_user.id
        SQL);

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropColumn(['street', 'postal_code', 'city', 'country', 'birthdate']);
        });
    }
};
