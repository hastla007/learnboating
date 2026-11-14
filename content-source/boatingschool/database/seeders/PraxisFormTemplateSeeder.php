<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisFormTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo-/Startbestückung der Praxisformulare (siehe PraxisFormTemplate):
 * jeder Kurs bekommt genau ein zugewiesenes Formular (Exklusivität),
 * mehrere verwandte Kurse teilen sich dabei dasselbe Formular. Felder und
 * Schwellen sind redaktionelle Startwerte, im Superadmin jederzeit
 * änderbar.
 */
class PraxisFormTemplateSeeder extends Seeder
{
    private const PRAXIS_FIELDS = [
        [
            'key' => 'ort',
            'label' => 'Ort',
            'type' => 'location',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'wunschdatum',
            'label' => 'Wunschdatum',
            'type' => 'date',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'geburtsort',
            'label' => 'Geburtsort',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'nationalitaet',
            'label' => 'Nationalität',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => 'z. B. Deutsch, Österreichisch',
        ],
        [
            'key' => 'telefonnummer',
            'label' => 'Telefonnummer',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'geschlecht',
            'label' => 'Geschlecht',
            'type' => 'radio',
            'required' => true,
            'options' => ['Männlich', 'Weiblich', 'Divers'],
            'help_text' => null,
        ],
        [
            'key' => 'tauglichkeit_vorhanden',
            'label' => 'Ich habe bereits die Tauglichkeit',
            'type' => 'checkbox',
            'required' => false,
            'options' => [],
            'help_text' => 'Du brauchst für die Prüfung eine ärztliche Tauglichkeitsbescheinigung. Plane dafür ein paar Tage Vorlauf ein.',
        ],
        [
            'key' => 'vorlaeufiger_fuehrerschein',
            'label' => 'Ich benötige zusätzlich einen vorläufigen Führerschein für 24,90 €',
            'type' => 'checkbox',
            'required' => false,
            'options' => [],
            'help_text' => null,
        ],
    ];

    private const THEORIE_FIELDS = [
        [
            'key' => 'ort',
            'label' => 'Ort',
            'type' => 'location',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'wunschtermin',
            'label' => 'Wunschtermin',
            'type' => 'date',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'geburtsort',
            'label' => 'Geburtsort',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'nationalitaet',
            'label' => 'Nationalität',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => 'z. B. Deutsch, Österreichisch',
        ],
        [
            'key' => 'telefonnummer',
            'label' => 'Telefonnummer',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
    ];

    public function run(): void
    {
        // Praxis & Prüfung (Motorboot/Segeln/Ergänzung) erfordert eine
        // ärztliche Tauglichkeitsbescheinigung -- die reinen
        // Funk-/Pyroschein-Prüfungen (THEORIE_FIELDS) nicht.
        $this->template('Praxis & Prüfung Motorboot', 50, self::PRAXIS_FIELDS, ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN'], requiresTauglichkeit: true);
        $this->template('Praxis & Prüfung Segeln', 50, self::PRAXIS_FIELDS, ['SBF-BIN-SEGEL', 'ADD-BIN-SEGEL'], requiresTauglichkeit: true);
        $this->template('Praxis & Prüfung Ergänzungskurs', 50, self::PRAXIS_FIELDS, ['ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE'], requiresTauglichkeit: true);
        $this->template('Anmeldung Funkzeugnis-Prüfung', 80, self::THEORIE_FIELDS, ['SRC', 'UBI', 'SRC-UBI', 'ADD-UBI-TO-SRC']);
        $this->template('Anmeldung Pyroschein-Prüfung', 70, self::THEORIE_FIELDS, ['FKN', 'SKN', 'ADD-FKN-TO-SKN']);
    }

    /**
     * Bug-Review (Hoch, Folgefehler von CourseSeeder): dieser Seeder läuft
     * in Produktion erneut, sobald ein neues Formular im Code dazukommt --
     * updateOrCreate() überschrieb dabei bisher BEI JEDEM Lauf
     * min_progress_percent/requires_tauglichkeit/fields/course_ids/active
     * JEDES bereits existierenden Formulars mit genau diesen Startwerten,
     * unabhängig davon, was ein Superadmin über
     * PraxisFormTemplateController::update() seitdem geändert hatte.
     * firstOrCreate() lässt ein bereits existierendes Formular unberührt
     * und erzeugt nur wirklich NEUE Formulare mit den Startwerten.
     *
     * @param  string[]  $courseCodes
     */
    private function template(string $title, int $minProgressPercent, array $fields, array $courseCodes, bool $requiresTauglichkeit = false): void
    {
        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->whereIn('code', $courseCodes)->pluck('id')->values()->all();

        if (empty($courseIds)) {
            return;
        }

        // Bug-Review (Seeder dupliziert umbenannte Formulare): firstOrCreate()
        // erkennt ein bestehendes Formular nur an seinem Titel -- hatte ein
        // Superadmin es seitdem umbenannt, legte der nächste db:seed beim
        // Deploy ein zweites, aktives Formular mit dem alten Titel für
        // dieselben Kurse an (zwei aktive Formulare je Kurs, entgegen der
        // Exklusivität aus dem Klassenkommentar). Ist einem der Kurse schon
        // IRGENDEIN Formular zugewiesen -- egal unter welchem Titel und auch
        // ein bewusst deaktiviertes --, gilt dieser Kurs als bereits
        // eingerichtet und bleibt unberührt. Ein wirklich neuer Kurs bzw. ein
        // neues Formular im Code wird weiterhin angelegt.
        $alreadyAssigned = PraxisFormTemplate::query()->get(['id', 'course_ids'])
            ->contains(fn (PraxisFormTemplate $template) => array_intersect($template->course_ids ?? [], $courseIds) !== []);

        // Bug-Review (gelöschtes Formular kommt zurück): löscht ein
        // Superadmin ein Formular, ist danach keinem Kurs mehr eines
        // zugewiesen -- der nächste db:seed legte es wieder an. seed_marker
        // (siehe SeemannschaftVideoSeeder) merkt sich dauerhaft, dass dieses
        // Formular schon einmal angelegt wurde.
        $marker = 'PraxisFormTemplateSeeder:'.$title;

        if ($alreadyAssigned || DB::table('seed_marker')->where('key', $marker)->exists()) {
            DB::table('seed_marker')->insertOrIgnore(['key' => $marker, 'seeded_at' => now()]);

            return;
        }

        DB::table('seed_marker')->insertOrIgnore(['key' => $marker, 'seeded_at' => now()]);

        PraxisFormTemplate::firstOrCreate(
            ['title' => $title],
            [
                'min_progress_percent' => $minProgressPercent,
                'requires_tauglichkeit' => $requiresTauglichkeit,
                'fields' => $fields,
                'course_ids' => $courseIds,
                'active' => true,
            ]
        );
    }
}
