<?php

namespace Database\Seeders;

use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Database\Seeders\Concerns\SeedsLessonQuizOnce;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz für SBF Binnen (siehe
 * video_lesson_question, VideoLessonController/VideoQuizController):
 * verknüpft ausgewählte, bereits veröffentlichte Fragen aus der zentralen
 * Fragenbasis mit einzelnen Videolektionen, passend zum jeweiligen
 * Lektionsinhalt. Spiegelt den Aufbau von VideoLessonQuestionSeeder
 * (dort für SBF See), nur für den Kurs SBF-BIN-MOTOR.
 */
class BinnenLessonQuestionSeeder extends Seeder
{
    // Bug-Review (Seeder-Idempotenz Videoquiz): einmal geseedete Lektionen
    // (quiz_seeded_at) bleiben unangetastet, siehe SeedsLessonQuizOnce.
    use SeedsLessonQuizOnce;

    /** @var array<string, array<int, string>> Kapitel => Lektion => Fragetexte. */
    private const LESSON_QUESTIONS = [
        'Grundlagen' => [
            'Einführung in die Schifffahrt' => [
                'In welchen Fällen darf weder ein Sportboot geführt noch dessen Kurs oder Geschwindigkeit selbstständig bestimmt werden?',
                'Unter welchen Voraussetzungen darf ein Sportboot mit Elektromotor ohne Fahrerlaubnis geführt werden?',
                'Für welche Sportboote ist der Sportbootführerschein mit dem Geltungsbereich Binnenschifffahrtsstraßen vorgeschrieben?',
                'Auf welchen Gewässern gilt der Sportbootführerschein mit dem Geltungsbereich Binnenschifffahrtsstraßen?',
                'Was beinhaltet die allgemeine Sorgfaltspflicht?',
                'Welche Anforderungen neben der körperlichen und geistigen Tauglichkeit und fachlichen Eignung muss der Führer eines Sportbootes auf Binnenschifffahrtsstraßen erfüllen, wenn die größte Nutzleistung der Antriebsmaschine 11,03 kW bei Verbrennungsmotoren bzw. 7,5 kW bei Elektromotoren Betriebsart S1 (Dauerbetrieb) oder weniger beträgt?',
                'Welche Anforderungen neben der körperlichen und geistigen Tauglichkeit und fachlichen Eignung muss der Führer eines Sportbootes auf dem Rhein erfüllen, wenn die Nutzleistung der Antriebsmaschine mehr als 11,03 kW bei Verbrennungsmotoren bzw. 7,5 kW bei Elektromotoren Betriebsart S1 (Dauerbetrieb) beträgt?',
                'Welche Anforderungen werden an die Person gestellt, mit der der Schiffsführer das Ruder eines Sportbootes mit Antriebsmaschine auf Binnenschifffahrtstraßen besetzen will?',
            ],
            'Rechtliche Bestimmungen' => [
                'Wo findet man die allgemeinen Verkehrsregeln für die Binnenschifffahrtsstraßen und den Rhein?',
                'Wo findet man die allgemeinen Verkehrsregeln für die Mosel und die Donau?',
                'Wo findet man Regeln für den Verkehr von Wassermotorrädern und für das Wasserskilaufen?',
            ],
            'Orientierung auf Wasserstraßen' => [
                'Was versteht man unter "Fahrwasser"?',
                'Was versteht man unter "Fahrrinne"?',
                'In welche Richtung werden bei Flüssen die Uferseiten als rechtes bzw. linkes Ufer bezeichnet?',
                'Was bedeutet "zu Berg" oder "Bergfahrt" auf Flüssen?',
                'Was bedeutet "zu Berg" oder "Bergfahrt" auf Kanälen?',
                'Welche Zeichen begrenzen die Fahrrinne zum rechten Ufer?',
                'Welche Zeichen begrenzen die Fahrrinne zum linken Ufer?',
                'Welche Fahrrinnenseite hat ein Bergfahrer an seiner Steuerbordseite und wie ist diese gekennzeichnet?',
                'Welche Fahrrinnenseite hat ein Talfahrer an seiner Backbordseite?',
            ],
            'Bedeutung von Tonnen' => [
                'Was bedeutet eine rot-grün gestreifte Tonne oder Schwimmstange und was ist zu beachten?',
                'Mit welchen Zeichen werden Hindernisse wie zum Beispiel Buhnen und Kribben an der rechten Seite der Wasserstraße bezeichnet?',
                'Was kennzeichnet eine grün-weiß gestreifte Schwimmstange mit grünem Kegel, Spitze nach oben, oder eine grüne Tonne mit grün-weiß gestreiftem Aufsatz mit grünem Kegel, Spitze nach oben?',
                'Ein Fahrzeug fährt in der Fahrrinne gegen den Strom. Voraus liegt eine grüne Tonne. Auf welcher Fahrrinnenseite befindet sich diese Tonne und an welcher Schiffsseite muss diese Tonne passiert werden?',
                'Welche Funktion haben gelbe Tonnen mit einem Radarreflektor vor Brückenpfeilern?',
            ],
        ],
        'Lichter, Zeichen und Töne' => [
            'Grundlagen der Lichterführung' => [
                'Wann müssen die Lichter von Fahrzeugen geführt oder gezeigt werden?',
                'Wozu dient die Lichterführung?',
                'Was für eine Laterne kann ein Segelfahrzeug von weniger als 20 m Länge anstelle der Seitenlichter und des Hecklichtes führen?',
                'Welche Lichter muss ein Fahrzeug unter Segel, das gleichzeitig mit Maschinenkraft fährt, führen?',
                'Welchen Sichtwinkel und welche Farben haben die vorgeschriebenen Lichter an Bord?',
                'Welches Licht muss ein Kleinfahrzeug ohne Maschinenantrieb mindestens führen?',
            ],
            'Besonderheiten und Sonderregelungen' => [
                ['question' => 'Was bedeutet dieses Sichtzeichen?', 'correct_answer' => 'Schleppfahrzeug an der Spitze eines Schleppverbandes.'],
                ['question' => 'Was bedeutet dieses Sichtzeichen?', 'correct_answer' => 'Fahrzeug eines Schleppverbandes.'],
                ['question' => 'Was bedeuten diese Lichter?', 'correct_answer' => 'Schubverband in Fahrt von vorne.'],
            ],
            'Signalkörper und Lichter bei Nacht' => [
                'Was bedeutet auf einem Fahrzeug ein blaues Licht?',
                'Was bedeuten auf einem Fahrzeug zwei blaue Lichter übereinander?',
                'Was bedeuten auf einem Fahrzeug drei blaue Lichter übereinander?',
            ],
            'Schallsignale' => [
                ['question' => 'Welche Bedeutung hat folgendes Schallsignal?', 'correct_answer' => 'Bleib-weg-Signal, Gefahrenbereich sofort verlassen.'],
                'Was bedeuten vier kurze Töne?',
                'Was bedeuten fünf kurze Töne?',
                ['question' => 'Was bedeutet dieses Schallsignal?', 'correct_answer' => 'Überholen an der Steuerbordseite des Vorausfahrenden.'],
                ['question' => 'Was bedeutet dieses Schallsignal?', 'correct_answer' => 'Überholen an der Backbordseite des Vorausfahrenden.'],
                'Was ist eine Folge sehr kurzer Töne?',
            ],
            // Bug-Review (Quizfragen in falscher Lektion): hier stand nur
            // SBF-BIN-0190 (gekenterte Jolle -- Crew zählen, am Boot
            // festhalten), keine Signalfrage. Jetzt die Not- und
            // Manövrierunfähigkeitszeichen: 0170 (vier kurze Töne, rote
            // Flagge/rotes Licht schwenken), 0210 (wiederholte lange Töne),
            // 0211 (Kreisen einer roten Flagge), 0250/0251 (optisches Zeichen
            // statt vier kurzer Töne bei Tag/Nacht). Bestehende
            // Installationen korrigiert die Migration 2024_02_01_000502.
            'Notfallsignale' => [
                'Welche Schallsignale bzw. Zeichen sind zu geben, wenn das Boot manövrierunfähig geworden ist?',
                'Welches optische Zeichen kann am Tage anstelle von vier kurzen Tönen gegeben werden?',
                'Welches optische Zeichen kann in der Nacht oder bei verminderter Sicht anstelle von vier kurzen Tönen gegeben werden?',
                'Welches Schallsignal ist zu geben, wenn in einer Notsituation Hilfe gebraucht wird?',
                'Welche Bedeutung hat am Tag das Kreisen einer roten Flagge auf einem Wasserfahrzeug?',
            ],
        ],
        'Bootsführung und Bordtechnik' => [
            'Liegen und Fahren' => [
                ['question' => 'Welche Bedeutung haben folgende Tafelzeichen?', 'correct_answer' => 'Festmache- und Liegeverbot.'],
                'Was ist in Kanälen verboten?',
            ],
            'Schleusen' => [
                'Welche Bedeutung haben vor einer Schleuse ein rotes oder ein rotes und ein grünes Licht?',
                'In welcher Reihenfolge fahren Fahrzeuge, die nicht Kleinfahrzeuge sind, und Kleinfahrzeuge, die gemeinsam geschleust werden sollen, in die Schleuse ein?',
            ],
            'Ausweichen' => [
                'Wie weichen zwei Motorboote aus, die sich auf entgegengesetzten Kursen nähern?',
                'Zwei Motorboote nähern sich auf kreuzenden Kursen. Es besteht die Gefahr eines Zusammenstoßes. Wer ist ausweichpflichtig?',
                'Wie ist ein Überholmanöver durchzuführen?',
                'Wann besteht die Gefahr eines Zusammenstoßes?',
                'Wie müssen Ausweichmanöver durchgeführt werden?',
                'Ein Kleinfahrzeug und ein Fahrzeug über 20 m Länge nähern sich auf kreuzenden Kursen. Es besteht die Gefahr eines Zusammenstoßes. Wer ist ausweichpflichtig?',
                'Wie muss sich ein kreuzendes Kleinfahrzeug unter Segel am Wind in der Nähe eines Ufers gegenüber einem anderen Kleinfahrzeug verhalten?',
                'Wer ist ausweichpflichtig bzw. wer ist nicht ausweichpflichtig?',
            ],
            'Umweltschutz' => [
                'Wie kann mitgeholfen werden, die Lebensmöglichkeiten der Pflanzen- und Tierwelt in Gewässern und Feuchtgebieten zu bewahren und zu fördern?',
                'Warum sollte man sich von Schilf- und Röhrichtzonen sowie von dicht bewachsenen Uferzonen möglichst weit fernhalten?',
                'Wo finden Sie Informationen über umweltfreundliche Farben, Lacke und Antifouling-Beschichtungen für Ihr Boot?',
                'Wie müssen Abfälle entsorgt werden?',
                'Was ist beim Neuanstrich des Unterwasserschiffs und bei der Entfernung des alten Anstrichs zu beachten?',
            ],
            'Gefahren von Gas an Bord' => [
                'Wo sollen die Gasbehälter einer Flüssiggasanlage gelagert werden?',
                'Warum sind die Flüssiggase Propan und Butan an Bord besonders gefährlich?',
                'Was ist zu tun, wenn Flüssiggas in das Innere des Bootes gelangt?',
                'Was ist vor Inbetriebnahme einer Flüssiggasanlage zu prüfen?',
                'Was ist zu beachten, wenn eine Flüssiggasanlage außer Betrieb gesetzt wird?',
            ],
            'Wassersport' => [
                ['question' => 'Welche Bedeutung haben folgende Tafelzeichen?', 'correct_answer' => 'Wasserflächen, auf denen mit Wasserski oder Wassermotorrädern gefahren werden darf.'],
                'Wo ist die Geschwindigkeit zu vermindern, um Sog und Wellenschlag zu vermeiden?',
                'Wo darf auf Binnenschifffahrtsstraßen Wasserski gelaufen werden?',
                'Zu welcher Tageszeit und bei welchen Sichtweiten darf auf den erlaubten Gewässerabschnitten Wasserski gelaufen werden?',
                'Wie muss sich der Wasserskiläufer bei der Vorbeifahrt an Fahrzeugen, Schwimmkörpern oder Badenden verhalten?',
                'Unter welchen Voraussetzungen darf außerhalb der ausgewiesenen Strecken/Wasserflächen Wassermotorrad gefahren werden?',
                'Wie hat sich der Führer eines Wassermotorrades außerhalb der ausgewiesenen Strecken/Wasserflächen zu verhalten?',
            ],
        ],
    ];

    public function run(): void
    {
        // Bug-Review R4-8: ComboVideoCourseSeeder legt die SBF-Binnen-Kapitel
        // für den Kombikurs "SBF See & Binnen" (SBF-SEE-BIN) NATIV an --
        // das sind eigene video_module_id/video_lesson_id-Zeilen, keine
        // bloßen Verweise auf den eigenständigen Vollkurs. Dieser Seeder
        // verknüpfte bisher nur die Lektionen des eigenständigen Vollkurses
        // (SBF-BIN-MOTOR, von BinnenVideoCourseSeeder aus GENAU DIESEN
        // Kombikurs-Kapiteln gespiegelt) mit Quizfragen -- der Kombikurs
        // selbst blieb bei seinen eigenen, nativen Binnen-Kapiteln
        // komplett ohne Quiz.
        foreach (['SBF-SEE-BIN', 'SBF-BIN-MOTOR'] as $courseCode) {
            $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->first();

            if (! $course) {
                continue;
            }

            foreach (self::LESSON_QUESTIONS as $chapterTitle => $lessons) {
                $chapter = $course->videoModules()->where('title', $chapterTitle)->first();

                if (! $chapter) {
                    continue;
                }

                foreach ($lessons as $lessonTitle => $questionTexts) {
                    $lesson = $chapter->lessons()->where('title', $lessonTitle)->first();
                    $this->seedQuizOnce($lesson, fn (VideoLesson $lesson) => $this->attachQuestions($lesson, $questionTexts));
                }
            }
        }
    }

    /**
     * @param  array<int, string|array{media: string}|array{question: string, correct_answer: string}>  $questions
     *                                                                                                              Fragetext, oder ['media' => '...'] um bei mehrdeutigem (mehrfach
     *                                                                                                              verwendetem) Fragetext über den Dateinamen des zugehörigen Bildes
     *                                                                                                              zu identifizieren, oder ['question' => ..., 'correct_answer' => ...]
     *                                                                                                              um stattdessen über den Text der richtigen Antwort zu identifizieren
     *                                                                                                              (für bildbasierte Fragen ohne hinterlegtes Bild in der Fragenbasis).
     */
    private function attachQuestions(VideoLesson $lesson, array $questions): void
    {
        foreach ($questions as $sortOrder => $question) {
            $query = ContentQuestionRevision::where('editorial_status', 'published');

            if (is_array($question) && isset($question['media'])) {
                $revision = $query->whereHas('media', fn ($q) => $q->where('storage_path', 'ilike', '%'.$question['media']))->first();
            } elseif (is_array($question) && isset($question['correct_answer'])) {
                $revision = $query->where('question_text', $question['question'])
                    ->whereHas('answers', fn ($q) => $q->where('is_correct', true)->where('answer_text', $question['correct_answer']))
                    ->first();
            } else {
                $revision = $query->where('question_text', $question)->first();
            }

            if ($revision) {
                $lesson->questions()->syncWithoutDetaching([$revision->question_id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
