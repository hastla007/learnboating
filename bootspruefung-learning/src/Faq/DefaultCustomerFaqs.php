<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Startinhalte der Kunden-FAQ (Datenstand 2, 11.11.2026): je Schein eine Kategorie mit denselben
 * Start-Unterkategorien (SBF See zusätzlich „Navigationsaufgaben“, SRC und UBI „Funktraining“) und ein
 * Startpaket von 16 FAQs: 6 gemeinsame FAQs, die mehreren Scheinen zugeordnet sind (je eine Kategorie pro
 * Schein, z. B. „Lernen im Kurs“ aller 7 Scheine), und 10 FAQs nur für einen Schein (Prüfungssimulation mit
 * eigenen Grenzen, Navigationsaufgaben, Funktraining SRC bzw. UBI). Jeder Schein sieht wie in Datenstand 1
 * 6 FAQs. Alle FAQs werden als ENTWURF angelegt – der Betreiber prüft und gibt frei.
 *
 * Datenstand 1 hatte die gemeinsamen Fragen je Schein kopiert (42 FAQs). Die Nummern dieser Kopien stehen in
 * „replaces“ (Reihenfolge wie „placements“); CustomerFaqImporter::mergeCopies() führt unbearbeitete Kopien auf
 * bestehenden Installationen zur gemeinsamen FAQ zusammen. Diese Nummern werden nie neu vergeben.
 *
 * Nur Aussagen, die im Code oder in den Kursdaten belegt sind; keine Preise, keine Angebote. Belegstellen:
 *
 * [B1] Smarttrainer, „gefestigt“: src/Learning/Services/LearningService.php (Klassenkommentar: „sicher heute“ =
 *      3 richtige Antworten in Folge, gefestigt = eine weitere richtige nach CONSOLIDATE_GAP_SECONDS = 20 h;
 *      Wiederholung nach REVIEW_INTERVAL_DAYS; Reihenfolge: neue → falsche → fällige Wiederholungen;
 *      STREAK_GAP_SECONDS = 300; applyProgress: falsche Antwort setzt die Serie auf 0; spaced(): andere Fragen
 *      dazwischen zählen) und src/Frontend/Strings.php (smart_explain, mode_smart_desc).
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
 * und Ergänzungskursen). Nummern (ref): 1–99 gemeinsame FAQs, je Schein ein eigener Hunderterbereich. „since“
 * = Datenstand, in dem die FAQ dazukam: Beim Update legt der Import nur FAQs eines neueren Datenstands an
 * (vom Betreiber gelöschte ältere kommen nicht zurück). Ändert sich ein Text: CustomerFaqImporter::VERSION erhöhen,
 * unveränderte FAQs zieht der Import dann nach (updateDefaults).
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
     * Scheine mit ihren Start-Unterkategorien (Reihenfolge = bp_cfaq_order).
     *
     * @return list<array{key:string,name:string,slug:string,subcategories:list<array{name:string,slug:string}>}>
     */
    public static function scheine(): array
    {
        $out = [];
        foreach (self::SCHEINE as $key => [$name, $prefix]) {
            $subs = [self::LEARN, self::EXAM];
            if ($key === 'SBF-SEE') {
                $subs[] = self::NAVIGATION;
            }
            if (in_array($key, ['SRC', 'UBI'], true)) {
                $subs[] = self::RADIO;
            }
            $subs[] = self::REGISTER;
            $subs[] = self::TECH;
            // Slug unabhängig von der Sprache der Website (ä → ae usw.).
            $subcategories = array_map(static fn (string $sub): array => [
                'name' => $sub,
                'slug' => $prefix.'-'.sanitize_title(strtr($sub, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'])),
            ], $subs);
            $out[] = ['key' => $key, 'name' => $name, 'slug' => 'kunden-'.$prefix, 'subcategories' => $subcategories];
        }

        return $out;
    }

    /**
     * Start-FAQs: placements = [Schein-Schlüssel, Unterkategorie] (eine gemeinsame FAQ hat mehrere), order =
     * Reihenfolge in der Unterkategorie (menu_order), replaces = Nummern der Kopien aus Datenstand 1 (gleiche
     * Reihenfolge wie placements), since = Datenstand, in dem die FAQ dazukam.
     *
     * @return list<array{ref:int,since:string,question:string,answer:string,note:?string,order:int,placements:list<array{0:string,1:string}>,replaces:list<int>}>
     */
    public static function faqs(): array
    {
        $all = array_keys(self::SCHEINE);
        $base = static fn (string $key): int => self::SCHEINE[$key][2];
        $faq = static fn (int $ref, array $keys, string $sub, int $order, string $question, string $answer, ?string $note = null, array $replaces = []): array => [
            'ref' => $ref, 'since' => '1', 'question' => $question, 'answer' => $answer, 'note' => $note ?? self::NOTE, 'order' => $order,
            'placements' => array_map(static fn (string $key): array => [$key, $sub], $keys), 'replaces' => $replaces,
        ];
        // Nummern der Kopien aus Datenstand 1 je Schein (Basis + laufende Nummer im Schein).
        $copies = static fn (array $offsets): array => array_map(static fn (string $key): int => $base($key) + $offsets[$key], array_keys($offsets));
        $out = [];

        // Gemeinsame FAQs (wortgleich für alle betroffenen Scheine) [B1, B6, B7, B8, B3, B9].
        $out[] = $faq(1, $all, self::LEARN, 1,
            'Wann gilt eine Frage im Smarttrainer als gefestigt?',
            "Hast du eine Frage **dreimal hintereinander richtig** beantwortet, ist sie „sicher heute“. Gefestigt ist sie, wenn du sie an einem anderen Tag (frühestens 20 Stunden später) noch einmal richtig beantwortest. Eine falsche Antwort setzt die Serie wieder auf null.\n\n"
            ."Damit nicht das Kurzzeitgedächtnis zählt, wird eine erneute richtige Antwort auf dieselbe Frage erst gewertet, wenn seit deiner letzten Antwort mindestens fünf Minuten vergangen sind oder du zwischendurch andere Fragen beantwortet hast.\n\n"
            .'Der Smarttrainer wählt die nächste Frage für dich: zuerst neue Fragen, danach falsch beantwortete, fällige Wiederholungen und Fragen aus Themen, die dir noch schwerfallen. Gefestigte Fragen kommen nach einigen Tagen zur Wiederholung.',
            null,
            $copies(array_fill_keys($all, 1))
        );
        $out[] = $faq(2, $all, self::REGISTER, 1,
            'Wie melde ich mich zur Prüfung an?',
            "Zur Prüfung meldest du dich selbst an – die Prüfung nimmt ein Prüfungsausschuss des Deutschen Segler-Verbands (DSV) oder des Deutschen Motoryachtverbands (DMYV) ab. Die Prüfungsgebühr zahlst du direkt an den Prüfungsausschuss bzw. Verband, nicht über diese Lernplattform.\n\n"
            ."Im Bereich **„Prüfung anmelden“** deines Kurses findest du eine Anleitung mit Antrag, Unterlagen und Fristen sowie alle Prüfungsausschüsse, filterbar nach Ort, PLZ oder Name. Die Prüfung selbst legst du vor Ort ab, nicht online.\n\n"
            .'Gebühren, Fristen und Abläufe dort sind Richtwerte; maßgeblich sind die Angaben deines Prüfungsausschusses.',
            'Startpaket (Entwurf): nur veröffentlichen, wenn unter Bootsprüfung › Einstellungen die Prüfungsanmeldung eingeschaltet ist (Standard: aus) – sonst gibt es den Bereich „Prüfung anmelden“ im Kurs nicht.',
            $copies(['SBF-SEE' => 4, 'SBF-BIN-MOTOR' => 3, 'SBF-BIN-SEGEL' => 3, 'SRC' => 4, 'UBI' => 4, 'SKN' => 4, 'FKN' => 4])
        );
        $out[] = $faq(3, $all, self::TECH, 1,
            'Was passiert, wenn mein Zugang endet?',
            "Endet dein Zugang zum Kurs bald, zeigt dir der Lernbereich einen Hinweis mit dem Enddatum und einem Link zum Verlängern – er führt zu dem Angebot, das du gekauft hast. Zusätzlich erinnern wir dich vorher per E-Mail; die Erinnerung kannst du jederzeit über den Link in der E-Mail oder in deinem Konto unter **„Mein Lernen“** abbestellen.\n\n"
            .'Dein Lernstand bleibt erhalten – verlängerst du später, lernst du dort weiter, wo du aufgehört hast.',
            'Startpaket (Entwurf): Die E-Mail-Erinnerung ist unter Bootsprüfung › Einstellungen abschaltbar (Standard: ein, 14 Tage vorher). Ist sie aus, den Satz zur E-Mail streichen.',
            $copies(['SBF-SEE' => 5, 'SBF-BIN-MOTOR' => 4, 'SBF-BIN-SEGEL' => 4, 'SRC' => 5, 'UBI' => 5, 'SKN' => 5, 'FKN' => 5])
        );
        $out[] = $faq(4, $all, self::TECH, 2,
            'Kann ich mein Geld zurückbekommen, wenn der Kurs nicht zu mir passt?',
            "Für viele Produkte geben wir dir zusätzlich zu deinen gesetzlichen Rechten freiwillig eine Geld-zurück-Garantie. Ob sie für deinen Kauf gilt und bis wann, siehst du in deinem Konto unter **„Mein Lernen“** – dort forderst du sie auch an.\n\n"
            ."Voraussetzung ist, dass du seit dem Zahlungseingang erst wenig gelernt hast. Gezählt werden die beantworteten Prüfungsfragen und die abgeschlossenen Video-Lektionen der gekauften Kurse.\n\n"
            .'Alle Bedingungen: {{link:/geld-zurueck-garantie-bootsfuehrerschein/|Geld-zurück-Garantie}} und {{agb}}.',
            'Startpaket (Entwurf): Gilt nur für Produkte mit eingetragener Garantie (WooCommerce-Produkt › Geld-zurück). Gruppenmitglieder sehen diese FAQ ebenfalls; für Gruppenpakete gilt die Garantie nicht.',
            $copies(['SBF-SEE' => 6, 'SBF-BIN-MOTOR' => 5, 'SBF-BIN-SEGEL' => 5, 'SRC' => 6, 'UBI' => 6, 'SKN' => 6, 'FKN' => 6])
        );
        $out[] = $faq(5, ['SKN', 'FKN'], self::EXAM, 2,
            'Wie werden offene Fragen gewertet?',
            "Bei offenen Fragen gibt es keine Antworten zur Auswahl. Überlege dir zuerst deine Antwort, zeige dann die Musterantwort und schätze ehrlich ein, ob du sie gewusst hättest.\n\n"
            .'Deine Einschätzung zählt im Smarttrainer und in der Prüfungssimulation wie eine richtige oder falsche Antwort – je ehrlicher, desto aussagekräftiger dein Lernstand.',
            null,
            $copies(['SKN' => 3, 'FKN' => 3])
        );
        $out[] = $faq(6, ['SBF-BIN-MOTOR', 'SBF-BIN-SEGEL'], self::TECH, 3,
            'Wo finde ich Unterlagen zum Herunterladen?',
            'Unter **„Downloads“** in deinem Kurs findest du die PDF-Dateien, die wir für deinen Kurs bereitstellen. Dateien zu einem Lernbereich stehen zusätzlich oben auf der passenden Lernseite. Herunterladen kannst du sie mit deinem Kurszugang.',
            'Startpaket (Entwurf): Gemeinsame FAQ für SBF Binnen und SBF Binnen unter Segel. Nur veröffentlichen, wenn für diese Scheine Dateien in der Mediathek hinterlegt sind (Lernzugang erforderlich: Kurs · Downloads); fehlen sie für einen der Scheine, dessen Kategorie in dieser FAQ abwählen.',
            $copies(['SBF-BIN-MOTOR' => 6, 'SBF-BIN-SEGEL' => 6])
        );

        // Nur für einen Schein: Prüfungssimulation (eigene Fragenzahl, Zeit und Grenzen) [B2].
        foreach ($all as $key) {
            [$question, $answer] = self::exam($key);
            $out[] = $faq($base($key) + 2, [$key], self::EXAM, 1, $question, $answer);
        }
        // Navigationsaufgaben (SBF See) [B4] und Funktraining (SRC, UBI) [B5].
        $out[] = $faq(103, ['SBF-SEE'], self::NAVIGATION, 1,
            'Wie übe ich die Navigationsaufgaben?',
            "Im Bereich **„Navigationsaufgaben“** löst du die amtlichen Aufgaben Teilaufgabe für Teilaufgabe mit Seekarte und Kursdreieck. Du arbeitest mit der Übungskarte D49 des BSH: Ist die digitale Karte freigeschaltet, öffnest du sie direkt in der Aufgabe, sonst nimmst du die gedruckte Übungskarte.\n\n"
            ."Trägst du deine Lösung ein und deckst das amtliche Ergebnis auf, prüfen wir bei Teilaufgaben mit hinterlegten Lösungswerten deine Werte – zum Beispiel Kurs, Distanz, Position oder Uhrzeit – automatisch mit Toleranz. Das ist eine Hilfe: Ob deine Lösung stimmt, entscheidest du mit deiner Selbsteinschätzung.\n\n"
            .'In der Prüfung brauchst du in der Navigationsaufgabe mindestens 7 von 9 Punkten. Nach deiner Selbsteinschätzung zeigt dir der Trainer deinen Punktestand: Jede richtig gelöste Teilaufgabe zählt einen Punkt, „teilweise“ zählt nicht. Die Prüfungssimulation bewertet die Navigationsaufgabe nicht mit – übe sie deshalb hier.'
        );
        $out[] = $faq(403, ['SRC'], self::RADIO, 1,
            'Was übe ich im Funktraining?',
            "Im Funktraining bereitest du dich auf die Teile der Prüfung neben dem Fragebogen vor:\n\n"
            ."- **Seefunktexte:** Lass dir einen Text vorlesen, schreib mit und übersetze – Deutsch → Englisch oder Englisch → Deutsch. Die Auswertung zeigt, wie viel Prozent der Wörter du getroffen hast und was fehlt.\n"
            ."- **Vokabeln und Buchstabiertafel:** Eine Vokabel gilt als gelernt, wenn du sie dreimal in Folge gewusst hast.\n"
            ."- **Funkpraxis:** Lage lesen, Meldung eintragen, Auswertung gegen die Prüfpunkte, danach die Musterlösung.\n"
            ."- **Gerätesimulation:** Bedienung eines UKW-Funkgeräts. Ob du die Testversion oder die Vollversion nutzt, steht auf der Kachel im Kurs."
        );
        $out[] = $faq(503, ['UBI'], self::RADIO, 1,
            'Was übe ich im Funktraining?',
            "Im Funktraining bereitest du dich auf die Teile der Prüfung neben dem Fragebogen vor:\n\n"
            ."- **UBI-Aufnahme:** Lass dir eine Meldung vorlesen und schreib sie wortgenau mit.\n"
            ."- **UBI-Abgabe:** Lies eine Meldung vor und buchstabiere die markierten Wörter mit der internationalen Buchstabiertafel.\n"
            ."- **Vokabeln und Buchstabiertafel:** Eine Vokabel gilt als gelernt, wenn du sie dreimal in Folge gewusst hast.\n"
            ."- **Funkpraxis:** Lage lesen, Meldung mit Verkehrskreis eintragen, Auswertung gegen die Prüfpunkte, danach die Musterlösung.\n"
            ."- **Gerätesimulation:** Bedienung eines UKW-Funkgeräts. Ob du die Testversion oder die Vollversion nutzt, steht auf der Kachel im Kurs."
        );

        return $out;
    }

    /** Prüfungssimulation je Schein [B2]. @return array{0:string,1:string} */
    private static function exam(string $key): array
    {
        $common = "Während der Prüfung siehst du keine Lösungen. Fragen, die du dir vor dem Abgeben noch einmal ansehen willst, kannst du markieren; läuft die Zeit ab, wird automatisch abgegeben. Brauchst du eine Pause, kannst du die Prüfung auch abbrechen – sie wird dann nicht gewertet und erscheint nicht in deinen Ergebnissen. In der Auswertung siehst du deine Fehler und kannst sie anschließend gezielt wiederholen.\n\n"
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
        return count(self::faqs());
    }

    /** Name eines Scheins (Startkategorie) oder der Schlüssel selbst. */
    public static function scheinName(string $key): string
    {
        return self::SCHEINE[$key][0] ?? $key;
    }
}
