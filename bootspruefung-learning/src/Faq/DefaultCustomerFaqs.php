<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Startinhalte der Kunden-FAQ (Datenstand 1, 11.11.2026): je Schein eine Kategorie mit denselben
 * Start-Unterkategorien (SBF See zusätzlich „Navigationsaufgaben“, SRC und UBI „Funktraining“) und ein
 * Startpaket von je 6 FAQs. Alle FAQs werden als ENTWURF angelegt – der Betreiber prüft und gibt frei.
 *
 * Nur Aussagen, die im Code oder in den Kursdaten belegt sind; keine Preise, keine Angebote. Belegstellen:
 *
 * [B1] Smarttrainer, „gefestigt“: src/Learning/Services/LearningService.php (Klassenkommentar: gefestigt =
 *      3 richtige Antworten in Folge; STREAK_GAP_SECONDS = 300; updateProgress: falsche Antwort setzt die
 *      Serie auf 0; spaced(): andere Fragen dazwischen zählen) und src/Frontend/Strings.php (smart_explain,
 *      mode_smart_desc: neue, falsche und schwache Fragen).
 * [B2] Prüfungssimulation, Fragen je Teil, Zeit und Bestehensgrenzen: content-source/boatingschool/database/
 *      seeders/ExamRuleSetSeeder.php ($questionCountOverrides, $timeLimitOverrides, $passingRuleOverrides,
 *      $navigationNote) und die importierten Regelwerke (bp_exam_rule/bp_exam_blueprint: SBF-SEE 7+23, 3600 s,
 *      5/7 und 18/23; SBF-BIN-MOTOR 7+23, 2700 s; SBF-BIN-SEGEL 7+23+7, 3600 s; SRC 24, 1800 s, 19/24;
 *      UBI 22, 3600 s, 17/22; SKN 18, 3600 s, 26/36 Punkte binär = 13/18; FKN 15, 1800 s, 24/30 = 12/15).
 *      Je Teil getrennt bewertet: ExamService (parts). Ablauf: Strings.php exam_start_text, hub_exam_intro,
 *      exam_flag_hint, expired_note, learn_exam_heading; LearnerController::next (Parameter exam = Fehler einer
 *      abgegebenen Prüfung wiederholen). Feste Prüfungsbögen: bp_exam_paper (SBF See, SBF Binnen, Segel, SRC,
 *      UBI, FKN; für SKN keine) und Strings.php hub_exam_meta.
 * [B3] Offene Fragen (SKN, FKN): question_type open_text in den Kursdaten; Strings.php open_exam_explain;
 *      LearningService::answer ($known = Selbsteinschätzung); ExamRuleSetSeeder (offene Fragen zählen über die
 *      Selbsteinschätzung).
 * [B4] Navigationsaufgaben: Strings.php nav_intro (Seekarte, Kursdreieck, 7 von 9 Punkten), nav_check_*
 *      (automatische Prüfung der Werte, Toleranz, Selbsteinschätzung maßgeblich), nav_chart_pending;
 *      src/Trainers/NavigationCheck.php; src/Gate/Charts.php (Übungskarte D49 des BSH, erst nach Lizenz digital).
 * [B5] Funktraining: src/Trainers/RadioService.php (SRC-Seefunktexte mitschreiben/übersetzen, UBI-Aufnahme und
 *      -Abgabe, Vokabeln gelernt nach 3 × gewusst = VOCAB_STREAK_TO_LEARN), RadioPracticeService.php
 *      (Funkpraxis, Auswertung gegen Prüfpunkte, Musterlösung; UBI: Verkehrskreis statt DSC), DeviceSimService.php
 *      und Strings.php radio_*, device_*, sim_free_short/device_tile_full (Testversion bzw. Vollversion auf der
 *      Kachel); Prüfungsteile SRC: content-source/boatingschool/database/data/pruefung_buchen.php (exam_parts).
 * [B6] Prüfungsanmeldung: content-source/boatingschool/database/data/pruefung_buchen.php ($faq['online'],
 *      $faq['fee'], providers DSV/DMYV), src/ExamGuide/GuideCorrections.php („nicht über diese Lernplattform“,
 *      Richtwert), Strings.php guide_teaser, guide_book_text, guide_committee_search, guide_disclaimer;
 *      ExamGuideService::enabled() (Einstellung, Standard aus → Redaktionshinweis).
 * [B7] Ablauf des Zugangs: src/Commerce/ExpiryReminder.php (Erinnerungsmail vor Ablauf, Standard ein,
 *      14 Tage), Strings.php expiry_notice („Dein Lernstand bleibt erhalten.“), expiry_renew.
 * [B8] Geld-zurück-Garantie: src/Commerce/MoneyBack.php (Frist nach Zahlung, Anteil gelernter Inhalte:
 *      beantwortete Fragen und abgeschlossene Video-Lektionen seit Zahlung; zusätzlich zum Widerrufsrecht),
 *      src/Commerce/WooCommerce/MoneyBackWoo.php (Anfrage unter „Mein Lernen“), Bedingungen wie öffentliche FAQ 22.
 * [B9] Downloads: src/Gate/DownloadService.php (PDF-Dateien je Kurs, voller Zugang) und SectionFiles.php
 *      (Dateien auf der passenden Lernseite).
 *
 * Aufbau wie DefaultFaqs: Antworten mit Leerzeile = Absätze, „- “ = Liste, **fett**; {{agb}} und
 * {{link:/pfad/|Text}} ersetzt der Abruf. Keine kursbezogenen Links (eine Schein-FAQ erscheint auch in Kombi-
 * und Ergänzungskursen). Nummern (ref): je Schein ein eigener Hunderterbereich.
 */
final class DefaultCustomerFaqs
{
    public const LEARN = 'Lernen im Kurs';
    public const EXAM = 'Prüfungssimulation';
    public const REGISTER = 'Prüfung anmelden und ablegen';
    public const TECH = 'Technik und Zugang';
    public const NAVIGATION = 'Navigationsaufgaben';
    public const RADIO = 'Funktraining';

    /** Schein => [Name, Slug-Präfix, Nummernbasis]. */
    private const SCHEINE = [
        'SBF-SEE' => ['SBF See', 'sbf-see', 100],
        'SBF-BIN-MOTOR' => ['SBF Binnen', 'sbf-binnen', 200],
        'SBF-BIN-SEGEL' => ['SBF Binnen unter Segel', 'sbf-binnen-segel', 300],
        'SRC' => ['SRC', 'src', 400],
        'UBI' => ['UBI', 'ubi', 500],
        'SKN' => ['SKN', 'skn', 600],
        'FKN' => ['FKN', 'fkn', 700],
    ];

    private const NOTE = 'Startpaket (Entwurf): Aussage gegen den aktuellen Kursstand prüfen, dann veröffentlichen.';

    /**
     * @return list<array{key:string,name:string,slug:string,subcategories:list<array{name:string,slug:string,faqs:list<array{ref:int,question:string,answer:string,note:?string}>}>}>
     */
    public static function scheine(): array
    {
        $out = [];
        foreach (self::SCHEINE as $key => [$name, $prefix, $base]) {
            $faqs = self::faqsFor($key, $name);
            $subs = [self::LEARN, self::EXAM];
            if ($key === 'SBF-SEE') {
                $subs[] = self::NAVIGATION;
            }
            if (in_array($key, ['SRC', 'UBI'], true)) {
                $subs[] = self::RADIO;
            }
            $subs[] = self::REGISTER;
            $subs[] = self::TECH;
            $n = 0;
            $subcategories = [];
            foreach ($subs as $sub) {
                $items = [];
                foreach ($faqs[$sub] ?? [] as $faq) {
                    $items[] = ['ref' => $base + (++$n), 'question' => $faq[0], 'answer' => $faq[1], 'note' => $faq[2] ?? self::NOTE];
                }
                // Slug unabhängig von der Sprache der Website (ä → ae usw.).
                $subcategories[] = ['name' => $sub, 'slug' => $prefix.'-'.sanitize_title(strtr($sub, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'])), 'faqs' => $items];
            }
            $out[] = ['key' => $key, 'name' => $name, 'slug' => 'kunden-'.$prefix, 'subcategories' => $subcategories];
        }

        return $out;
    }

    /** @return array<string,list<array{0:string,1:string,2?:string}>> Unterkategorie => [Frage, Antwort, Hinweis?] */
    private static function faqsFor(string $key, string $name): array
    {
        $faqs = [
            self::LEARN => [[
                'Wann gilt eine Frage im Smarttrainer als gefestigt?',
                "Eine Frage gilt als gefestigt, wenn du sie **dreimal hintereinander richtig** beantwortet hast. Eine falsche Antwort setzt die Serie wieder auf null.\n\n"
                ."Damit nicht das Kurzzeitgedächtnis zählt, wird eine erneute richtige Antwort auf dieselbe Frage erst gewertet, wenn seit deiner letzten Antwort mindestens fünf Minuten vergangen sind oder du zwischendurch andere Fragen beantwortet hast.\n\n"
                .'Der Smarttrainer wählt die nächste Frage für dich: zuerst neue, dann falsch beantwortete Fragen und Fragen aus Themen, die dir noch schwerfallen.',
            ]],
            self::EXAM => [self::exam($key)],
            self::REGISTER => [[
                'Wie melde ich mich zur Prüfung an?',
                "Zur Prüfung meldest du dich selbst an – die Prüfung nimmt ein Prüfungsausschuss des Deutschen Segler-Verbands (DSV) oder des Deutschen Motoryachtverbands (DMYV) ab. Die Prüfungsgebühr zahlst du direkt an den Prüfungsausschuss bzw. Verband, nicht über diese Lernplattform.\n\n"
                ."Im Bereich **„Prüfung anmelden“** deines Kurses findest du eine Anleitung mit Antrag, Unterlagen und Fristen sowie alle Prüfungsausschüsse, filterbar nach Ort, PLZ oder Name. Die Prüfung selbst legst du vor Ort ab, nicht online.\n\n"
                .'Gebühren, Fristen und Abläufe dort sind Richtwerte; maßgeblich sind die Angaben deines Prüfungsausschusses.',
                'Startpaket (Entwurf): nur veröffentlichen, wenn unter Bootsprüfung › Einstellungen die Prüfungsanmeldung eingeschaltet ist (Standard: aus) – sonst gibt es den Bereich „Prüfung anmelden“ im Kurs nicht.',
            ]],
            self::TECH => [
                [
                    'Was passiert, wenn mein Zugang endet?',
                    "Endet dein Zugang zum Kurs bald, zeigt dir der Lernbereich einen Hinweis mit dem Enddatum und einem Link zum Verlängern. Zusätzlich erinnern wir dich vorher per E-Mail.\n\n"
                    .'Dein Lernstand bleibt erhalten – verlängerst du später, lernst du dort weiter, wo du aufgehört hast.',
                    'Startpaket (Entwurf): Die E-Mail-Erinnerung ist unter Bootsprüfung › Einstellungen abschaltbar (Standard: ein, 14 Tage vorher). Ist sie aus, den Satz zur E-Mail streichen.',
                ],
                [
                    'Kann ich mein Geld zurückbekommen, wenn der Kurs nicht zu mir passt?',
                    "Für viele Produkte geben wir dir zusätzlich zu deinen gesetzlichen Rechten freiwillig eine Geld-zurück-Garantie. Ob sie für deinen Kauf gilt und bis wann, siehst du in deinem Konto unter **„Mein Lernen“** – dort forderst du sie auch an.\n\n"
                    ."Voraussetzung ist, dass du seit dem Zahlungseingang erst wenig gelernt hast. Gezählt werden die beantworteten Prüfungsfragen und die abgeschlossenen Video-Lektionen der gekauften Kurse.\n\n"
                    .'Alle Bedingungen: {{link:/geld-zurueck-garantie-bootsfuehrerschein/|Geld-zurück-Garantie}} und {{agb}}.',
                    'Startpaket (Entwurf): Gilt nur für Produkte mit eingetragener Garantie (WooCommerce-Produkt › Geld-zurück). Gruppenmitglieder sehen diese FAQ ebenfalls; für Gruppenpakete gilt die Garantie nicht.',
                ],
            ],
        ];
        if ($key === 'SBF-SEE') {
            $faqs[self::NAVIGATION] = [[
                'Wie übe ich die Navigationsaufgaben?',
                "Im Bereich **„Navigationsaufgaben“** löst du die amtlichen Aufgaben Teilaufgabe für Teilaufgabe mit Seekarte und Kursdreieck. Du arbeitest mit der Übungskarte D49 des BSH: Ist die digitale Karte freigeschaltet, öffnest du sie direkt in der Aufgabe, sonst nimmst du die gedruckte Übungskarte.\n\n"
                ."Trägst du deine Lösung ein und deckst das amtliche Ergebnis auf, prüfen wir bei Teilaufgaben mit hinterlegten Lösungswerten deine Werte – zum Beispiel Kurs, Distanz, Position oder Uhrzeit – automatisch mit Toleranz. Das ist eine Hilfe: Ob deine Lösung stimmt, entscheidest du mit deiner Selbsteinschätzung.\n\n"
                .'In der Prüfung brauchst du in der Navigationsaufgabe mindestens 7 von 9 Punkten. Die Prüfungssimulation bewertet die Navigationsaufgabe nicht mit – übe sie deshalb hier.',
            ]];
        }
        if ($key === 'SRC') {
            $faqs[self::RADIO] = [[
                'Was übe ich im Funktraining?',
                "Im Funktraining bereitest du dich auf die Teile der Prüfung neben dem Fragebogen vor:\n\n"
                ."- **Seefunktexte:** Lass dir einen Text vorlesen, schreib mit und übersetze – Deutsch → Englisch oder Englisch → Deutsch. Die Auswertung zeigt, wie viel Prozent der Wörter du getroffen hast und was fehlt.\n"
                ."- **Vokabeln und Buchstabiertafel:** Eine Vokabel gilt als gelernt, wenn du sie dreimal in Folge gewusst hast.\n"
                ."- **Funkpraxis:** Lage lesen, Meldung eintragen, Auswertung gegen die Prüfpunkte, danach die Musterlösung.\n"
                ."- **Gerätesimulation:** Bedienung eines UKW-Funkgeräts. Ob du die Testversion oder die Vollversion nutzt, steht auf der Kachel im Kurs.",
            ]];
        }
        if ($key === 'UBI') {
            $faqs[self::RADIO] = [[
                'Was übe ich im Funktraining?',
                "Im Funktraining bereitest du dich auf die Teile der Prüfung neben dem Fragebogen vor:\n\n"
                ."- **UBI-Aufnahme:** Lass dir eine Meldung vorlesen und schreib sie wortgenau mit.\n"
                ."- **UBI-Abgabe:** Lies eine Meldung vor und buchstabiere die markierten Wörter mit der internationalen Buchstabiertafel.\n"
                ."- **Vokabeln und Buchstabiertafel:** Eine Vokabel gilt als gelernt, wenn du sie dreimal in Folge gewusst hast.\n"
                ."- **Funkpraxis:** Lage lesen, Meldung mit Verkehrskreis eintragen, Auswertung gegen die Prüfpunkte, danach die Musterlösung.\n"
                ."- **Gerätesimulation:** Bedienung eines UKW-Funkgeräts. Ob du die Testversion oder die Vollversion nutzt, steht auf der Kachel im Kurs.",
            ]];
        }
        if (in_array($key, ['SKN', 'FKN'], true)) {
            $faqs[self::EXAM][] = [
                'Wie werden offene Fragen gewertet?',
                "Bei offenen Fragen gibt es keine Antworten zur Auswahl. Überlege dir zuerst deine Antwort, zeige dann die Musterantwort und schätze ehrlich ein, ob du sie gewusst hättest.\n\n"
                .'Deine Einschätzung zählt im Smarttrainer und in der Prüfungssimulation wie eine richtige oder falsche Antwort – je ehrlicher, desto aussagekräftiger dein Lernstand.',
            ];
        }
        if (in_array($key, ['SBF-BIN-MOTOR', 'SBF-BIN-SEGEL'], true)) {
            $faqs[self::TECH][] = [
                'Wo finde ich Unterlagen zum Herunterladen?',
                'Unter **„Downloads“** in deinem Kurs findest du die PDF-Dateien, die wir für deinen Kurs bereitstellen. Dateien zu einem Lernbereich stehen zusätzlich oben auf der passenden Lernseite. Herunterladen kannst du sie mit deinem Kurszugang.',
                'Startpaket (Entwurf): nur veröffentlichen, wenn für diesen Schein Dateien in der Mediathek hinterlegt sind (Lernzugang erforderlich: Kurs · Downloads).',
            ];
        }

        return $faqs;
    }

    /** Prüfungssimulation je Schein [B2]. @return array{0:string,1:string} */
    private static function exam(string $key): array
    {
        $common = "Während der Prüfung siehst du keine Lösungen. Fragen, die du dir vor dem Abgeben noch einmal ansehen willst, kannst du markieren; läuft die Zeit ab, wird automatisch abgegeben. In der Auswertung siehst du deine Fehler und kannst sie anschließend gezielt wiederholen.\n\n"
            .'Im Kombi- oder Ergänzungskurs gilt das Regelwerk deines Kurses – Fragenzahl und Bestehensgrenzen stehen auf der Startseite der Prüfungssimulation.';
        $papers = ' Zusätzlich kannst du die festen Prüfungsbögen einzeln üben.';
        $text = match ($key) {
            'SBF-SEE' => 'Die Zufallsprüfung stellt dir die Fragen wie im amtlichen Fragebogen zusammen: **7 Basisfragen und 23 spezifische Fragen See**, mit 60 Minuten Zeit. Bestanden hast du, wenn du in **jedem Teil** die Grenze erreichst: mindestens 5 von 7 Basisfragen und 18 von 23 Fragen See. Zur amtlichen Prüfung gehört zusätzlich die Navigationsaufgabe; sie wird in der Simulation nicht bewertet.'.$papers,
            'SBF-BIN-MOTOR' => 'Die Zufallsprüfung stellt dir die Fragen wie im amtlichen Fragebogen zusammen: **7 Basisfragen und 23 spezifische Fragen Binnen**, mit 45 Minuten Zeit. Bestanden hast du, wenn du in **jedem Teil** die Grenze erreichst: mindestens 5 von 7 Basisfragen und 18 von 23 Fragen Binnen.'.$papers,
            'SBF-BIN-SEGEL' => 'Die Zufallsprüfung stellt dir die Fragen wie im amtlichen Fragebogen zusammen: **7 Basisfragen, 23 spezifische Fragen Binnen und 7 Fragen Segeln**, mit 60 Minuten Zeit. Bestanden hast du, wenn du in **jedem Teil** die Grenze erreichst: mindestens 5 von 7 Basisfragen, 18 von 23 Fragen Binnen und 5 von 7 Fragen Segeln.'.$papers,
            'SRC' => 'Die Zufallsprüfung stellt dir **24 Fragen** wie im amtlichen Fragebogen zusammen, mit 30 Minuten Zeit. Bestanden hast du mit mindestens 19 richtigen Antworten, also höchstens 5 Fehlern. Den englischen Teil und die Praxis am Funkgerät übst du im Funktraining.'.$papers,
            'UBI' => 'Die Zufallsprüfung stellt dir **22 Fragen** wie im amtlichen Fragebogen zusammen, mit 60 Minuten Zeit. Bestanden hast du mit mindestens 17 richtigen Antworten. Aufnahme, Abgabe und die Praxis am Funkgerät übst du im Funktraining.'.$papers,
            'SKN' => 'Die Zufallsprüfung stellt dir **18 Fragen** aus dem Katalog zusammen, mit 60 Minuten Zeit. Amtlich gibt es 2 Punkte je Frage und bestanden ist ab 26 von 36 Punkten; die Simulation wertet jede Frage als richtig oder falsch, bestanden hast du also mit mindestens 13 richtigen Antworten.',
            'FKN' => 'Die Zufallsprüfung stellt dir **15 Fragen** wie in einem amtlichen Prüfungsbogen zusammen, mit 30 Minuten Zeit. Amtlich gibt es 2 Punkte je vollständig richtiger Antwort und bestanden ist ab 24 von 30 Punkten; die Simulation wertet jede Frage als richtig oder falsch, bestanden hast du also mit mindestens 12 richtigen Antworten.'.$papers,
            default => '',
        };

        return ['Wie läuft die Prüfungssimulation ab und wann habe ich bestanden?', $text."\n\n".$common];
    }

    /** Anzahl der Start-FAQs (Admin-Knopf „Fehlende Start-FAQs importieren“). */
    public static function total(): int
    {
        $n = 0;
        foreach (self::scheine() as $schein) {
            foreach ($schein['subcategories'] as $sub) {
                $n += count($sub['faqs']);
            }
        }

        return $n;
    }
}
