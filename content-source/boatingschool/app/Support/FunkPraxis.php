<?php

namespace App\Support;

/**
 * Praxis-Prüfungssimulation für SRC und UBI: aus den kompakten
 * Aufgabenbeschreibungen in database/data/funk_praxis_aufgaben.php entstehen
 * hier Situation, Musterlösung und Prüfpunkte je Feld (Rang, DSC-Controller
 * bzw. Verkehrskreis, Funkgerät, Meldung).
 *
 * Die Abläufe folgen den amtlichen Fragenkatalogen, z. B. SRC: DSC-Alarm auf
 * Kanal 70, Notverkehr auf Kanal 16, Bestätigung eines DSC-Notalarms durch
 * Seefunkstellen nur im Sprechfunk auf Kanal 16, Fehlalarm per DSC
 * zurücknehmen und auf Kanal 16 an alle Funkstellen aufheben; UBI: kein
 * DSC, Revierzentralen (Nautische Information) bevorzugt für
 * Rettungsmaßnahmen, 25 W nur dort, Kanal 10 (Schiff-Schiff) mit max. 1 W.
 *
 * Die Auswertung ist regelbasiert (keine KI): jedes Feld hat Prüfpunkte als
 * reguläre Ausdrücke auf dem normalisierten Text (Großbuchstaben, Satzzeichen
 * als Leerzeichen); Rufzeichen und Ziffern werden auch buchstabiert erkannt
 * (Buchstabiertafel, englische und deutsche Zahlwörter).
 */
class FunkPraxis
{
    public const CATALOGS = ['SRC', 'UBI'];

    private const BREMEN_RESCUE = ['name' => 'BREMEN RESCUE RADIO', 'mmsi' => '002111240'];

    private const NATO = [
        'A' => 'ALFA', 'B' => 'BRAVO', 'C' => 'CHARLIE', 'D' => 'DELTA', 'E' => 'ECHO', 'F' => 'FOXTROT', 'G' => 'GOLF',
        'H' => 'HOTEL', 'I' => 'INDIA', 'J' => 'JULIETT', 'K' => 'KILO', 'L' => 'LIMA', 'M' => 'MIKE', 'N' => 'NOVEMBER',
        'O' => 'OSCAR', 'P' => 'PAPA', 'Q' => 'QUEBEC', 'R' => 'ROMEO', 'S' => 'SIERRA', 'T' => 'TANGO', 'U' => 'UNIFORM',
        'V' => 'VICTOR', 'W' => 'WHISKEY', 'X' => 'XRAY', 'Y' => 'YANKEE', 'Z' => 'ZULU',
    ];

    private const DIGITS_EN = ['ZERO', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE'];

    private const DIGITS_DE = ['NULL', 'EINS', 'ZWO', 'DREI', 'VIER', 'FÜNF', 'SECHS', 'SIEBEN', 'ACHT', 'NEUN'];

    /** Zusätzlich erkannte Schreibweisen je Ziffer. */
    private const DIGIT_ALIASES = [1 => ['EIN'], 2 => ['ZWEI'], 4 => ['FOWER'], 5 => ['FUENF', 'FIFE'], 9 => ['NINER']];

    /** SRC: Art der Not -- Situation, Meldung, DSC-Auswahl, Prüfwörter. */
    private const SRC_NATURES = [
        'fire' => ['Feuer an Bord, das sich ausbreitet', 'fire on board, spreading', 'I AM ON FIRE', 'Fire, explosion (Feuer, Explosion)', 'FIRE|FEUER|BRAND'],
        'explosion' => ['Explosion im Motorraum, anschließend Feuer', 'explosion in the engine room followed by fire', 'EXPLOSION IN THE ENGINE ROOM, I AM ON FIRE', 'Fire, explosion (Feuer, Explosion)', 'EXPLOSION|FIRE|FEUER'],
        'flooding' => ['starker Wassereinbruch, die Pumpen kommen nicht mehr nach', 'heavy water ingress, the pumps cannot cope', 'I AM TAKING IN WATER', 'Flooding (Wassereinbruch)', 'WATER|FLOOD|WASSER|LECK|LEAK'],
        'collision' => ['Kollision mit einem treibenden Gegenstand, Leck im Rumpf', 'collision with a floating object, hole in the hull', 'I HAVE COLLIDED WITH A FLOATING OBJECT, I HAVE A LEAK', 'Collision (Kollision)', 'COLLI|KOLLISION|LEAK|LECK'],
        'grounding' => ['auf eine Sandbank aufgelaufen, der Rumpf ist beschädigt, der Seegang nimmt zu', 'run aground on a sandbank, the hull is damaged, the sea is getting rougher', 'I AM AGROUND AND MY HULL IS DAMAGED', 'Grounding (Grundberührung)', 'AGROUND|GROUND|GRUND|AUFGELAUFEN|SANDBANK'],
        'listing' => ['starke Schlagseite, das Boot droht zu kentern', 'heavy list, the vessel is in danger of capsizing', 'I AM LISTING HEAVILY AND IN DANGER OF CAPSIZING', 'Listing, in danger of capsizing (Schlagseite, Kentergefahr)', 'CAPSIZ|LIST|KENTER|SCHLAGSEITE'],
        'sinking' => ['das Boot sinkt', 'the vessel is sinking', 'I AM SINKING', 'Sinking (Sinken)', 'SINK'],
        'abandoning' => ['das Boot sinkt, die Besatzung steigt in die Rettungsinsel', 'the vessel is sinking, the crew is abandoning ship into the liferaft', 'I AM SINKING AND ABANDONING MY VESSEL', 'Abandoning ship (Schiff wird verlassen)', 'ABANDON|VERLASS|RETTUNGSINSEL|LIFERAFT'],
        'mob' => ['eine Person ist über Bord gefallen und nicht mehr in Sicht', 'one person has fallen overboard and is no longer in sight', 'MAN OVERBOARD, ONE PERSON IN THE WATER, NOT IN SIGHT', 'Man overboard (Person über Bord)', 'OVERBOARD|ÜBER BORD|UEBER BORD|\bMOB\b'],
        'adrift' => ['Maschinenausfall, das Boot treibt auf die Küste zu, der Anker hält nicht', 'engine failure, the vessel is drifting towards the coast, the anchor is not holding', 'I AM DISABLED AND DRIFTING TOWARDS THE COAST', 'Disabled and adrift (manövrierunfähig, treibend)', 'DISABLED|ADRIFT|DRIFT|TREIB|MANÖVRIERUNFÄHIG'],
        'medical' => ['ein Crewmitglied hat einen Herzinfarkt erlitten, es besteht Lebensgefahr', 'a crew member has suffered a heart attack, the condition is life-threatening', 'ONE PERSON WITH SUSPECTED HEART ATTACK, I REQUIRE MEDICAL EVACUATION', 'Undesignated distress (nicht näher bezeichnete Notlage)', 'HEART|HERZ|MEDICAL|MEDIZIN|EVACUAT|UNDESIGNATED|NICHT NÄHER'],
    ];

    /** SRC: Dringlichkeitsfälle -- Situation, Meldung, erbetene Hilfe, Prüfwörter. */
    private const SRC_PROBLEMS = [
        'engine' => ['Maschinenausfall, das Boot treibt, es besteht keine unmittelbare Gefahr', 'engine failure, the vessel is drifting, there is no immediate danger', 'I HAVE ENGINE FAILURE AND AM DRIFTING', 'I REQUIRE TOWING ASSISTANCE', 'ENGINE|MASCHINE|MOTOR'],
        'rudder' => ['Ruderbruch, das Boot ist manövrierunfähig, es besteht keine unmittelbare Gefahr', 'broken rudder, the vessel is not under command, there is no immediate danger', 'MY RUDDER IS BROKEN, I AM NOT UNDER COMMAND', 'I REQUIRE TOWING ASSISTANCE', 'RUDDER|RUDER|NOT UNDER COMMAND'],
        'dismasted' => ['Mastbruch, der Motor springt nicht an, es besteht keine unmittelbare Gefahr', 'dismasted, the engine will not start, there is no immediate danger', 'I AM DISMASTED AND MY ENGINE IS NOT WORKING', 'I REQUIRE TOWING ASSISTANCE', 'DISMAST|MAST'],
        'propeller' => ['eine Leine hat sich in der Schraube verfangen, der Motor ist ausgefallen, es besteht keine unmittelbare Gefahr', 'a rope is fouled in the propeller, the engine has stopped, there is no immediate danger', 'MY PROPELLER IS FOULED BY A ROPE, I AM UNABLE TO MANOEUVRE', 'I REQUIRE TOWING ASSISTANCE', 'PROPELLER|SCHRAUBE|ROPE|LEINE'],
        'fuel' => ['kein Treibstoff mehr, das Boot treibt, es besteht keine unmittelbare Gefahr', 'out of fuel, the vessel is drifting, there is no immediate danger', 'I AM OUT OF FUEL AND DRIFTING', 'I REQUIRE TOWING ASSISTANCE', 'FUEL|TREIBSTOFF|DIESEL|KRAFTSTOFF'],
        'injury' => ['ein Crewmitglied hat sich den Arm gebrochen, es besteht keine Lebensgefahr', 'a crew member has a broken arm, the condition is not life-threatening', 'ONE CREW MEMBER HAS A BROKEN ARM, NOT LIFE THREATENING', 'I REQUIRE MEDICAL ASSISTANCE', 'ARM|BROKEN|GEBROCHEN|INJUR|VERLETZ'],
        'advice' => ['ein Crewmitglied hat hohes Fieber und starke Bauchschmerzen, es besteht keine akute Lebensgefahr', 'a crew member has a high fever and severe abdominal pain, there is no immediate danger to life', 'ONE CREW MEMBER HAS HIGH FEVER AND SEVERE ABDOMINAL PAIN', 'I REQUIRE MEDICAL ADVICE', 'FEVER|FIEBER|PAIN|SCHMERZ|MEDICAL'],
        'scald' => ['ein Crewmitglied hat sich die Hand verbrüht, es besteht keine Lebensgefahr', 'a crew member has scalded a hand, the condition is not life-threatening', 'ONE CREW MEMBER HAS A SCALDED HAND, NOT LIFE THREATENING', 'I REQUIRE MEDICAL ASSISTANCE', 'SCALD|BURN|VERBR'],
    ];

    /** SRC: Grund für die Aufhebung einer Dringlichkeitsmeldung. */
    private const SRC_CANCEL_REASONS = [
        'engine' => ['Inzwischen läuft die Maschine wieder', 'In the meantime the engine is running again', 'MY ENGINE IS RUNNING AGAIN'],
        'tow' => ['Inzwischen hat ein Seenotrettungsboot dich in Schlepp genommen', 'In the meantime a rescue boat has taken you in tow', 'I AM NOW UNDER TOW'],
    ];

    /** SRC: Gefahren für eine Sicherheitsmeldung. */
    // Kanäle der Nautischen Information im Binnenfunk (wie channels.js; ohne
    // 21). „25 W“ ist die Sendeleistung, nicht Kanal 25.
    private const NIF_CHANNELS_PATTERN = '(?<!\d)(18|19|20|22|23|24|25|26|27|28|73|78|79|80|81|82|84|85|86)(?!\d)(?! ?W\b| ?WATT)';

    // Übliche Arbeitskanäle für eine Sicherheitsmeldung an alle (Schiff–Schiff).
    private const SAFETY_WORK_CHANNELS = [6, 8, 67, 69, 72, 73, 77];

    private const SRC_HAZARDS = [
        'container' => ['einen treibenden Container', 'a drifting container', 'DRIFTING CONTAINER', 'CONTAINER'],
        'buoy' => ['eine vertriebene, unbeleuchtete Tonne', 'an unlit buoy that has drifted off its position', 'UNLIT BUOY ADRIFT', 'BUOY|TONNE'],
        'tree' => ['einen großen treibenden Baumstamm', 'a large drifting tree trunk', 'LARGE DRIFTING TREE TRUNK', 'TREE|LOG|BAUM|STAMM'],
        'net' => ['ein großes treibendes Fischernetz', 'a large drifting fishing net', 'LARGE DRIFTING FISHING NET', 'NET|NETZ'],
        'wreckage' => ['treibende Wrackteile (Balken und Planken)', 'drifting wreckage (beams and planks)', 'DRIFTING WRECKAGE', 'WRECK|WRACK|BEAM|BALKEN|PLANK'],
        'pallets' => ['treibende Paletten und Holzladung', 'drifting pallets and timber cargo', 'DRIFTING PALLETS AND TIMBER', 'PALLET|PALETTE|TIMBER|HOLZ'],
    ];

    /** SRC: Anlässe für einen Routineanruf Schiff-Schiff. */
    private const SRC_TOPICS = [
        'berth' => ['einen gemeinsamen Liegeplatz in Helgoland absprechen', 'arrange a berth together in Helgoland', 'I WILL ARRIVE IN HELGOLAND AT ABOUT 1700 UTC, CAN I MOOR ALONGSIDE YOU'],
        'weather' => ['nach dem aktuellen Wetter in ihrem Seegebiet fragen', 'ask about the current weather in their area', 'WHAT IS THE WEATHER LIKE IN YOUR AREA'],
        'radio_check' => ['eine Funkprobe (Radio check) machen', 'carry out a radio check', 'RADIO CHECK, HOW DO YOU READ ME'],
        'eta' => ['deine voraussichtliche Ankunftszeit in Kiel mitteilen', 'inform them of your estimated time of arrival in Kiel', 'MY ESTIMATED TIME OF ARRIVAL IN KIEL IS 1600 UTC'],
        'impeller' => ['fragen, ob sie einen Ersatz-Impeller für deinen Motor an Bord hat', 'ask whether they have a spare impeller for your engine on board', 'DO YOU HAVE A SPARE IMPELLER ON BOARD'],
    ];

    /** UBI: Notlagen -- Situation, Meldung, Prüfwörter. */
    private const UBI_NATURES = [
        'fire_engine' => ['Feuer im Maschinenraum, das Boot droht zu sinken', 'FEUER IM MASCHINENRAUM, DAS BOOT DROHT ZU SINKEN', 'FEUER|BRAND'],
        'flooding' => ['Wassereinbruch nach einer Grundberührung, das Boot sinkt', 'WASSEREINBRUCH NACH GRUNDBERÜHRUNG, DAS BOOT SINKT', 'WASSER|LECK|SINK'],
        'mob' => ['eine Person ist über Bord gefallen und treibt ab', 'PERSON ÜBER BORD, DIE PERSON TREIBT AB', 'ÜBER BORD|UEBER BORD|\bMOB\b'],
        'capsize' => ['das Boot hat starke Schlagseite und droht zu kentern', 'STARKE SCHLAGSEITE, DAS BOOT DROHT ZU KENTERN', 'KENTER|SCHLAGSEITE'],
        'injury' => ['ein Crewmitglied ist bewusstlos und lebensgefährlich verletzt', 'EINE PERSON IST BEWUSSTLOS UND LEBENSGEFÄHRLICH VERLETZT', 'VERLETZ|BEWUSSTLOS|LEBENSGEF'],
        'collision' => ['Kollision mit einem Brückenpfeiler, das Boot hat ein Leck und nimmt Wasser über', 'KOLLISION MIT EINEM BRÜCKENPFEILER, LECK, WASSEREINBRUCH', 'KOLLISION|LECK|WASSER'],
        'explosion' => ['Explosion an Bord, es brennt', 'EXPLOSION AN BORD, FEUER', 'EXPLOSION|FEUER|BRAND'],
        'fire_cabin' => ['Feuer in der Kajüte, das sich schnell ausbreitet', 'FEUER IN DER KAJÜTE, DAS FEUER BREITET SICH AUS', 'FEUER|BRAND'],
    ];

    /** UBI: Dringlichkeitsfälle -- Situation, Meldung, erbetene Hilfe, Prüfwörter. */
    private const UBI_PROBLEMS = [
        'engine' => ['Maschinenausfall, du liegst sicher vor Anker, es besteht keine unmittelbare Gefahr', 'MASCHINENAUSFALL, ICH LIEGE VOR ANKER', 'ERBITTE SCHLEPPHILFE', 'MASCHINE|MOTOR'],
        'rudder' => ['Ruderschaden, das Boot ist nur noch eingeschränkt manövrierfähig, es besteht keine unmittelbare Gefahr', 'RUDERSCHADEN, ICH BIN NUR EINGESCHRÄNKT MANÖVRIERFÄHIG', 'ERBITTE SCHLEPPHILFE', 'RUDER'],
        'injury' => ['ein Crewmitglied hat sich am Bein verletzt (nicht lebensbedrohlich) und muss kurzfristig ärztlich versorgt werden', 'EINE PERSON AM BEIN VERLETZT, NICHT LEBENSBEDROHLICH', 'ERBITTE ÄRZTLICHE HILFE AN DER NÄCHSTEN ANLEGESTELLE', 'VERLETZ|BEIN'],
        'propeller' => ['eine Leine hat sich in der Schraube verfangen, du treibst langsam am Ufer entlang, es besteht keine unmittelbare Gefahr', 'LEINE IN DER SCHRAUBE, ICH BIN MANÖVRIERUNFÄHIG', 'ERBITTE SCHLEPPHILFE', 'SCHRAUBE|LEINE|PROPELLER'],
        'fuel' => ['kein Treibstoff mehr, du liegst am Ufer fest, es besteht keine unmittelbare Gefahr', 'KEIN TREIBSTOFF MEHR, ICH LIEGE AM UFER FEST', 'ERBITTE SCHLEPPHILFE ODER TREIBSTOFF', 'TREIBSTOFF|KRAFTSTOFF|DIESEL|BENZIN'],
        'scald' => ['ein Crewmitglied hat sich die Hand verbrüht (nicht lebensbedrohlich) und muss ärztlich versorgt werden', 'EINE PERSON HAT SICH DIE HAND VERBRÜHT, NICHT LEBENSBEDROHLICH', 'ERBITTE ÄRZTLICHE HILFE AN DER NÄCHSTEN ANLEGESTELLE', 'VERBR|HAND'],
    ];

    /** UBI: Gefahren für eine Sicherheitsmeldung. */
    private const UBI_HAZARDS = [
        'tree' => ['einen großen treibenden Baumstamm in der Fahrrinne', 'TREIBENDER BAUMSTAMM IN DER FAHRRINNE', 'BAUM|STAMM'],
        'buoy' => ['eine vertriebene Tonne', 'VERTRIEBENE TONNE', 'TONNE'],
        'pontoon' => ['einen losgerissenen Schwimmsteg, der in der Fahrrinne treibt', 'LOSGERISSENER SCHWIMMSTEG TREIBT IN DER FAHRRINNE', 'STEG|PONTON'],
        'sunken' => ['ein gesunkenes Sportboot, das am rechten Ufer in die Fahrrinne ragt', 'GESUNKENES SPORTBOOT AM RECHTEN UFER, RAGT IN DIE FAHRRINNE', 'GESUNKEN|WRACK|SPORTBOOT'],
        'debris' => ['viel Treibgut (Äste und Bretter) nach dem Hochwasser', 'VIEL TREIBGUT IN DER FAHRRINNE', 'TREIBGUT|ÄSTE|BRETTER'],
        'barrels' => ['mehrere treibende Fässer', 'MEHRERE TREIBENDE FÄSSER', 'FASS|FÄSS'],
    ];

    /**
     * @return array<int, array{key: string, number: int, catalog: string, kind: string, situation_de: string, situation_en: ?string, fields: list<array{name: string, label: string, placeholder: string, multiline: bool, solution: string, checks: list<array{0: string, 1: string, 2: int}>}>}>
     */
    public static function tasks(string $catalog): array
    {
        static $cache = [];

        if (! isset($cache[$catalog])) {
            $specs = (include database_path('data/funk_praxis_aufgaben.php'))[$catalog] ?? [];
            $cache[$catalog] = [];

            foreach (array_values($specs) as $index => $spec) {
                $number = $index + 1;
                $task = $catalog === 'SRC' ? self::buildSrc($spec) : self::buildUbi($spec);
                $cache[$catalog][$number] = ['key' => sprintf('%s-%02d', $catalog, $number), 'number' => $number, 'catalog' => $catalog, 'kind' => $spec['kind']] + $task;
            }
        }

        return $cache[$catalog];
    }

    /**
     * Prüft die Eingaben gegen die Prüfpunkte der Aufgabe.
     *
     * @param  array<string, string|null>  $answers
     * @return array{fields: array<string, list<array{label: string, ok: bool}>>, passed: int, total: int}
     */
    public static function evaluate(array $task, array $answers): array
    {
        $fields = [];
        $passed = 0;
        $total = 0;

        foreach ($task['fields'] as $field) {
            $text = self::normalize((string) ($answers[$field['name']] ?? ''));
            $results = [];

            foreach ($field['checks'] as [$label, $pattern, $min]) {
                $ok = preg_match_all('/'.self::fold($pattern).'/u', $text) >= $min;
                $results[] = ['label' => $label, 'ok' => $ok];
                $passed += $ok ? 1 : 0;
                $total++;
            }

            $fields[$field['name']] = $results;
        }

        return ['fields' => $fields, 'passed' => $passed, 'total' => $total];
    }

    /**
     * Aufgabe zu einer einzelnen Szenario-Beschreibung im Format von
     * database/data/funk_praxis_aufgaben.php -- für die Gerätesimulation, die
     * die Prüfpunkte der Meldung (Feld "message") für Gesprochenes nutzt.
     */
    public static function scenario(string $catalog, array $spec): array
    {
        return ['catalog' => $catalog, 'kind' => $spec['kind']] + ($catalog === 'SRC' ? self::buildSrc($spec) : self::buildUbi($spec));
    }

    /**
     * Prüft einen Text gegen Prüfpunkte [Bezeichnung, regulärer Ausdruck, Mindestanzahl].
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $checks
     * @return array{checks: list<array{label: string, ok: bool}>, passed: bool}
     */
    public static function checkText(array $checks, string $text): array
    {
        $normalized = self::normalize($text);
        $results = array_map(fn ($check) => ['label' => $check[0], 'ok' => preg_match_all('/'.self::fold($check[1]).'/u', $normalized) >= $check[2]], $checks);

        return ['checks' => $results, 'passed' => $results !== [] && ! in_array(false, array_column($results, 'ok'), true)];
    }

    public static function normalize(string $text): string
    {
        $text = self::fold(mb_strtoupper($text));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return ' '.trim($text).' ';
    }

    /**
     * Review: Umlaute als AE/OE/UE (und ß als SS), in Eingabe UND Prüfmuster
     * gleich -- sonst fiel „STRANDLÄUFER“ gegen „STRANDLAEUFER“ durch bzw.
     * umgekehrt.
     */
    private static function fold(string $text): string
    {
        return strtr($text, ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ẞ' => 'SS', 'ß' => 'SS']);
    }

    // --- SRC ---------------------------------------------------------------

    private static function buildSrc(array $s): array
    {
        $own = $s['vessel'];
        $intro = sprintf('Du bist mit der %s %s, Rufzeichen %s, MMSI %s, um %s UTC auf Position %s.', $s['type'] === 'sail' ? 'Segelyacht' : 'Motoryacht', $own, $s['callsign'], $s['mmsi'], $s['time'], self::posText($s['pos']));
        $introEn = sprintf('You are on the %s %s, call sign %s, MMSI %s, at %s UTC in position %s.', $s['type'] === 'sail' ? 'sailing yacht' : 'motor yacht', $own, $s['callsign'], $s['mmsi'], $s['time'], self::posText($s['pos']));
        $identity = [self::times($own, 3), self::spell($s['callsign']), 'MMSI '.$s['mmsi']];
        $persons = $s['persons'] ?? 0;

        switch ($s['kind']) {
            case 'distress':
                [$de, $en, $msg, $dsc, $kw] = self::SRC_NATURES[$s['nature']];
                $situation = "{$intro} ".self::ucfirst($de).". {$persons} Personen an Bord. Sende den Notalarm und die Notmeldung!";
                $situationEn = "{$introEn} ".ucfirst($en).". {$persons} persons on board. Send the distress alert and the distress message!";
                $message = ['MAYDAY MAYDAY MAYDAY', 'THIS IS', ...$identity, 'MAYDAY', "{$own} ".self::spell($s['callsign'])." MMSI {$s['mmsi']}", 'MY POSITION '.self::posSpoken($s['pos'])." AT {$s['time']} UTC", $msg, 'I REQUIRE IMMEDIATE ASSISTANCE', "{$persons} PERSONS ON BOARD", 'OVER'];

                return self::srcTask($situation, $situationEn,
                    ['Notverkehr – Notalarm und Notmeldung (MAYDAY)', [['Notverkehr', self::RANK_DISTRESS, 1]]],
                    ["Notalarm (Distress): Klappe der DISTRESS-Taste öffnen, Art des Notfalls „{$dsc}“ wählen, Position und Uhrzeit prüfen bzw. eingeben (".self::posText($s['pos']).", {$s['time']} UTC), DISTRESS-Taste 5 Sekunden gedrückt halten. Das Gerät schaltet danach auf Kanal 16.", [
                        ['Notalarm über die DISTRESS-Taste', 'DISTRESS|NOTALARM|NOT ?TASTE|NOTRUF ?TASTE|\bSOS\b', 1],
                        ['Art des Notfalls auswählen', $kw, 1],
                        ['Position und Uhrzeit prüfen/eingeben', 'POSITION|\bPOS\b|UHRZEIT|\bZEIT\b|\bUTC\b', 1],
                        ['Taste 5 Sekunden gedrückt halten', '\b5 ?S|FÜNF|GEDRÜCKT HALTEN|LANGE? DRÜCKEN', 1],
                    ]],
                    self::srcRadioDistress(),
                    [$message, array_merge(self::checksMayday($own, $s['callsign'], $s['mmsi'], 'THIS IS'), [
                        ['Position', self::posPattern($s['pos']), 1],
                        ['Art der Not', $kw, 1],
                        ['Erbetene Hilfe', 'ASSISTANCE|HELP|HILFE', 1],
                        ["Personen an Bord ({$persons})", self::personsPattern($persons), 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ])]
                );

            case 'relay':
                $d = $s['distressed'];
                [$de, $en, $msg, $dsc, $kw] = self::SRC_NATURES[$d['nature']];
                $situation = "{$intro} Um {$d['time']} UTC hörst du auf Kanal 16 die Notmeldung der Yacht {$d['vessel']}, Rufzeichen {$d['callsign']}, MMSI {$d['mmsi']}, auf Position ".self::posText($d['pos']).": {$de}, {$d['persons']} Personen an Bord. Niemand bestätigt die Notmeldung, auch nicht Bremen Rescue Radio. Du selbst kannst nicht helfen. Leite die Notmeldung weiter!";
                $situationEn = "{$introEn} At {$d['time']} UTC you hear the distress message of the yacht {$d['vessel']}, call sign {$d['callsign']}, MMSI {$d['mmsi']}, in position ".self::posText($d['pos']).": {$en}, {$d['persons']} persons on board. Nobody acknowledges, not even Bremen Rescue Radio. You cannot help yourself. Relay the distress message!";
                $message = ['MAYDAY RELAY MAYDAY RELAY MAYDAY RELAY', self::times(self::BREMEN_RESCUE['name'], 3), 'THIS IS', ...$identity, "FOLLOWING RECEIVED FROM {$d['vessel']} ".self::spell($d['callsign'])." MMSI {$d['mmsi']} AT {$d['time']} UTC", "MAYDAY {$d['vessel']} ".self::spell($d['callsign'])." MMSI {$d['mmsi']}", 'POSITION '.self::posSpoken($d['pos']), $msg, 'REQUIRES IMMEDIATE ASSISTANCE', "{$d['persons']} PERSONS ON BOARD", 'OVER'];

                return self::srcTask($situation, $situationEn,
                    ['Notverkehr – Weiterleitung einer Notmeldung (MAYDAY RELAY)', [['Notverkehr', self::RANK_DISTRESS, 1], ['Weiterleitung (Relay)', 'RELAY|WEITERLEIT|WEITERGAB|WEITERGEB', 1]]],
                    ['Notalarm-Weiterleitung (Distress Relay) als Einzelanruf an Bremen Rescue Radio (MMSI '.self::BREMEN_RESCUE['mmsi']."), Art des Notfalls „{$dsc}“; ist keine Küstenfunkstelle erreichbar, an alle Funkstellen. Folgekanal 16.", [
                        ['Distress Relay', 'RELAY|WEITERLEIT', 1],
                        ['an Küstenfunkstelle (Bremen Rescue Radio) bzw. alle Funkstellen', 'BREMEN|KÜSTENFUNK|ALL SHIPS|ALL STATIONS|ALLE', 1],
                        ['Folgekanal 16', '(?<!\d)16(?!\d)', 1],
                    ]],
                    self::srcRadioDistress(),
                    [$message, [
                        ['MAYDAY RELAY dreimal', 'MAYDAY RELAY', 3],
                        ['Anruf Bremen Rescue Radio oder alle Funkstellen', 'BREMEN RESCUE|ALL STATIONS', 1],
                        ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                        ["Eigener Name ({$own})", self::word($own), 1],
                        ['Eigenes Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ["Schiff in Not ({$d['vessel']})", self::word($d['vessel']), 1],
                        ['Position des Schiffs in Not', self::posPattern($d['pos']), 1],
                        ['Art der Not', $kw, 1],
                        ["Personen an Bord ({$d['persons']})", self::personsPattern($d['persons']), 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ]]
                );

            case 'acknowledge':
                $d = $s['distressed'];
                [$de, $en, , , $kw] = self::SRC_NATURES[$d['nature']];
                $situation = "{$intro} Dein DSC-Controller empfängt einen Notalarm der Yacht {$d['vessel']}, MMSI {$d['mmsi']}: {$de}, Position ".self::posText($d['pos']).". Bremen Rescue Radio hat den Alarm auch nach angemessener Wartezeit nicht bestätigt. Du bist {$s['distance']} Seemeilen entfernt und kannst helfen. Bestätige den Empfang!";
                $situationEn = "{$introEn} Your DSC controller receives a distress alert from the yacht {$d['vessel']}, MMSI {$d['mmsi']}: {$en}, position ".self::posText($d['pos']).". Bremen Rescue Radio has not acknowledged the alert after a reasonable time. You are {$s['distance']} nautical miles away and able to help. Acknowledge receipt!";
                $message = ['MAYDAY', self::times($d['vessel'], 3), 'THIS IS', self::times($own, 3), self::spell($s['callsign']), 'RECEIVED MAYDAY', 'MY POSITION '.self::posSpoken($s['pos']).", I AM {$s['distance']} NAUTICAL MILES FROM YOUR POSITION AND PROCEEDING TO ASSIST", 'OVER'];

                return self::srcTask($situation, $situationEn,
                    ['Notverkehr – Bestätigung eines Notalarms', [['Notverkehr', self::RANK_DISTRESS, 1]]],
                    ['Keine Bestätigung per DSC: Seefunkstellen bestätigen einen DSC-Notalarm auf UKW im Sprechfunkverfahren auf Kanal 16 – nach der Bestätigung durch eine Küstenfunkstelle oder nach angemessener Wartefrist.', [
                        ['Keine DSC-Bestätigung durch die Seefunkstelle', '\bKEIN|\bNICHT\b|\bNO\b', 1],
                        ['Bestätigung im Sprechfunk auf Kanal 16', '(?<!\d)16(?!\d)|SPRECHFUNK', 1],
                    ]],
                    self::srcRadioDistress(),
                    [$message, [
                        ['MAYDAY', '\bMAYDAY\b', 1],
                        ["Schiff in Not dreimal ({$d['vessel']})", self::word($d['vessel']), 3],
                        ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                        ["Eigener Name ({$own})", self::word($own), 1],
                        ['Eigenes Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['RECEIVED MAYDAY', 'RECEIVED MAYDAY', 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ]]
                );

            case 'false_alert':
                $situation = "{$intro} Beim Reinigen der Funkanlage hast du um {$s['alert_time']} UTC versehentlich einen DSC-Notalarm ausgelöst. Hebe den Fehlalarm auf!";
                $situationEn = "{$introEn} While cleaning the radio you accidentally sent a DSC distress alert at {$s['alert_time']} UTC. Cancel the false alert!";
                $message = ['ALL STATIONS ALL STATIONS ALL STATIONS', 'THIS IS', ...$identity, 'POSITION '.self::posSpoken($s['pos']), "CANCEL MY DISTRESS ALERT OF {$s['alert_time']} UTC", 'OVER'];

                return self::srcTask($situation, $situationEn,
                    ['Notverkehr – Aufhebung eines Fehlalarms', [['Fehlalarm aufheben', 'FEHLALARM|AUFHEB|CANCEL|ZURÜCKNAHME|ZURÜCKNEHM', 1]]],
                    ['Gerät nicht ausschalten: den laufenden Notalarm sofort abbrechen bzw. das Gerät zurücksetzen und den Fehlalarm, wenn möglich, per DSC zurücknehmen (Distress Cancel). Anschließend auf Kanal 16 an alle Funkstellen aufheben.', [
                        ['Notalarm abbrechen / per DSC zurücknehmen', 'ABBRECH|CANCEL|ZURÜCK|RESET|AUFHEB|STOP', 1],
                    ]],
                    self::srcRadioDistress(),
                    [$message, [
                        ['ALL STATIONS dreimal', 'ALL STATIONS', 3],
                        ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                        ["Name dreimal ({$own})", self::word($own), 3],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['MMSI', self::digitsPattern($s['mmsi']), 1],
                        ['Position', self::posPattern($s['pos']), 1],
                        ['CANCEL MY DISTRESS ALERT', 'CANCEL', 1],
                        ["Uhrzeit des Fehlalarms ({$s['alert_time']} UTC)", self::timePattern($s['alert_time']), 1],
                        ['Abschluss OVER/OUT', self::OVER_OR_OUT, 1],
                    ]]
                );

            case 'urgency':
                [$de, $en, $msg, $assist, $kw] = self::SRC_PROBLEMS[$s['problem']];
                $situation = "{$intro} ".self::ucfirst($de).". {$persons} Personen an Bord. Sende die erforderliche Meldung!";
                $situationEn = "{$introEn} ".ucfirst($en).". {$persons} persons on board. Send the appropriate message!";
                $message = ['PAN PAN PAN PAN PAN PAN', 'ALL STATIONS ALL STATIONS ALL STATIONS', 'THIS IS', ...$identity, 'MY POSITION '.self::posSpoken($s['pos'])." AT {$s['time']} UTC", $msg, $assist, "{$persons} PERSONS ON BOARD", 'OVER'];

                return self::srcTask($situation, $situationEn,
                    ['Dringlichkeitsverkehr – Dringlichkeitsmeldung (PAN PAN)', [['Dringlichkeitsverkehr', self::RANK_URGENCY, 1]]],
                    self::srcDscAllShips('Dringlichkeitsanruf (Urgency)', 'Dringlichkeitsanruf (Urgency)', 'URGENCY|DRINGLICH'),
                    self::srcRadioDistress(),
                    [$message, [
                        ['PAN PAN dreimal', 'PAN PAN', 3],
                        ['ALL STATIONS dreimal', 'ALL STATIONS|BREMEN RESCUE', 3],
                        ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                        ["Name dreimal ({$own})", self::word($own), 3],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['MMSI', self::digitsPattern($s['mmsi']), 1],
                        ['Position', self::posPattern($s['pos']), 1],
                        ['Art des Problems', $kw, 1],
                        ['Erbetene Hilfe', 'ASSISTANCE|ADVICE|HELP|TOW|HILFE', 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ]]
                );

            case 'urgency_cancel':
                [$de, $en] = self::SRC_PROBLEMS[$s['problem']];
                [$reasonDe, $reasonEn, $reasonMsg] = self::SRC_CANCEL_REASONS[$s['reason']];
                $situation = "{$intro} Um {$s['urgency_time']} UTC hast du eine Dringlichkeitsmeldung an alle Funkstellen gesendet ({$de}). {$reasonDe}. Hebe die Dringlichkeitsmeldung auf!";
                $situationEn = "{$introEn} At {$s['urgency_time']} UTC you sent an urgency message to all stations ({$en}). {$reasonEn}. Cancel the urgency message!";
                $message = ['PAN PAN PAN PAN PAN PAN', 'ALL STATIONS ALL STATIONS ALL STATIONS', 'THIS IS', ...$identity, "CANCEL MY URGENCY MESSAGE OF {$s['urgency_time']} UTC", $reasonMsg, 'OUT'];

                return self::srcTask($situation, $situationEn,
                    ['Dringlichkeitsverkehr – Aufhebung einer Dringlichkeitsmeldung', [['Dringlichkeitsverkehr', self::RANK_URGENCY, 1], ['Aufhebung', 'AUFHEB|CANCEL|ZURÜCK|RÜCKNAHME', 1]]],
                    self::srcDscAllShips('Ankündigung wie bei der Dringlichkeitsmeldung: Dringlichkeitsanruf (Urgency)', 'Dringlichkeitsanruf (Urgency)', 'URGENCY|DRINGLICH'),
                    self::srcRadioDistress(),
                    [$message, [
                        ['PAN PAN dreimal', 'PAN PAN', 3],
                        ['ALL STATIONS dreimal', 'ALL STATIONS', 3],
                        ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                        ["Name dreimal ({$own})", self::word($own), 3],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['CANCEL MY URGENCY MESSAGE', 'CANCEL', 1],
                        ["Uhrzeit der Dringlichkeitsmeldung ({$s['urgency_time']} UTC)", self::timePattern($s['urgency_time']), 1],
                        ['Abschluss OUT', self::OVER_OR_OUT, 1],
                    ]]
                );

            case 'safety':
                // Sicherheitsmeldung an alle: auf Kanal 16 nur ankündigen,
                // die Meldung selbst auf dem angekündigten Arbeitskanal (so
                // erwarten es die Prüfer; Kanal 16 bleibt frei). Ohne
                // vorgegebenen Kanal gilt jeder übliche Arbeitskanal.
                [$de, $en, $msg, $kw] = self::SRC_HAZARDS[$s['hazard']];
                $work = $s['work_channel'] ?? 6;
                $workAlternatives = isset($s['work_channel']) ? [$work] : self::SAFETY_WORK_CHANNELS;
                $situation = "{$intro} Du sichtest {$de} auf Position ".self::posText($s['hazard_pos']).', eine Gefahr für die Schifffahrt. Sende die erforderliche Meldung!';
                $situationEn = "{$introEn} You sight {$en} in position ".self::posText($s['hazard_pos']).', a danger to navigation. Send the appropriate message!';
                $workSpoken = 'CHANNEL '.self::spell(sprintf('%02d', $work));
                $announcement = ['SECURITE SECURITE SECURITE', 'ALL STATIONS ALL STATIONS ALL STATIONS', 'THIS IS', ...$identity, "LISTEN ON {$workSpoken}", 'OUT'];
                $body = ['SECURITE SECURITE SECURITE', 'ALL STATIONS ALL STATIONS ALL STATIONS', 'THIS IS', ...$identity, 'NAVIGATIONAL WARNING', "{$msg} IN POSITION ".self::posSpoken($s['hazard_pos'])." AT {$s['time']} UTC", 'DANGER TO NAVIGATION', 'OUT'];
                $head = [
                    ['SECURITE dreimal', 'SECURIT', 3],
                    ['ALL STATIONS dreimal', 'ALL STATIONS', 3],
                    ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                    ["Name dreimal ({$own})", self::word($own), 3],
                    ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                ];
                $announce = ['Arbeitskanal angekündigt ('.implode(', ', array_map(fn ($c) => sprintf('%02d', $c), $workAlternatives)).')', '(?:CHANNEL|KANAL) (?:'.implode('|', array_map(fn ($c) => self::workChannelPattern($c), $workAlternatives)).')', 1];
                $hazard = [['Art der Gefahr', $kw, 1], ['Position der Gefahr', self::posPattern($s['hazard_pos']), 1]];
                $out = ['Abschluss OUT', self::OVER_OR_OUT, 1];

                $task = self::srcTask($situation, $situationEn,
                    ['Sicherheitsverkehr – Sicherheitsmeldung (SECURITE)', [['Sicherheitsverkehr', 'SICHERHEIT|SAFETY|SECURIT', 1]]],
                    self::srcDscAllShips('Sicherheitsanruf (Safety)', 'Sicherheitsanruf (Safety)', 'SAFETY|SICHERHEIT'),
                    [sprintf('Kanal 16 nur für die Ankündigung, dann Wechsel auf den angekündigten Arbeitskanal (z. B. %02d) für die Meldung', $work), [
                        ['Kanal 16 (Ankündigung)', '(?<!\d)16(?!\d)', 1],
                        ['Arbeitskanal für die Meldung', implode('|', array_map(fn ($c) => self::channelPattern($c), $workAlternatives)), 1],
                    ]],
                    [[...$announcement, sprintf('– Wechsel auf Kanal %02d –', $work), ...$body], [...$head, $announce, ...$hazard, $out]]
                );
                // Die Gerätesimulation prüft Ankündigung und Meldung getrennt.
                $task['fields'][3]['parts'] = [
                    'announcement' => ['solution' => implode("\n", $announcement), 'checks' => [...$head, $announce, $out]],
                    'message' => ['solution' => implode("\n", $body), 'checks' => [...$head, ...$hazard, $out]],
                ];

                return $task;

            case 'routine':
                $c = $s['called'];
                [$de, $en, $msg] = self::SRC_TOPICS[$s['topic']];
                $situation = "{$intro} Du möchtest mit der Yacht {$c['vessel']}, Rufzeichen {$c['callsign']}, MMSI {$c['mmsi']}, {$de}. Stelle die Verbindung her (Arbeitskanal {$s['channel']})!";
                $situationEn = "{$introEn} You want to {$en} with the yacht {$c['vessel']}, call sign {$c['callsign']}, MMSI {$c['mmsi']}. Establish contact (working channel {$s['channel']})!";
                $message = [self::times($c['vessel'], 2), 'THIS IS', self::times($own, 2), self::spell($s['callsign']), $msg, 'OVER'];

                return self::srcTask($situation, $situationEn,
                    ['Routineverkehr – Anruf Schiff–Schiff', [['Routineverkehr', 'ROUTINE', 1]]],
                    ["Einzelanruf (Individual) an MMSI {$c['mmsi']}, Kategorie Routine, Arbeitskanal {$s['channel']} vorschlagen (Schiff-Schiff-Kanal).", [
                        ['Einzelanruf', 'EINZEL|INDIVIDUAL', 1],
                        ['MMSI der gerufenen Yacht', self::digitsPattern($c['mmsi']), 1],
                        ['Kategorie Routine', 'ROUTINE', 1],
                        ["Arbeitskanal {$s['channel']}", self::channelPattern($s['channel']), 1],
                    ]],
                    ["Kanal {$s['channel']}, niedrige Sendeleistung (1 W)", [
                        ["Arbeitskanal {$s['channel']}", self::channelPattern($s['channel']), 1],
                        ['Niedrige Sendeleistung (1 W)', '(?<!\d)1 ?W|NIEDRIG|\bLOW\b|GERING|KLEIN', 1],
                    ]],
                    [$message, [
                        ["Gerufene Yacht ({$c['vessel']})", self::word($c['vessel']), 1],
                        ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                        ["Eigener Name ({$own})", self::word($own), 1],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ]]
                );
        }

        throw new \InvalidArgumentException("Unbekannte SRC-Aufgabenart {$s['kind']}");
    }

    private static function srcTask(string $situation, string $situationEn, array $rank, array $dsc, array $radio, array $message): array
    {
        return [
            'situation_de' => $situation,
            'situation_en' => $situationEn,
            'fields' => [
                self::field('rank', 'Rang', 'Verkehrsart und Meldungstyp …', $rank),
                self::field('dsc', 'DSC-Controller', 'Bedienung des DSC-Controllers …', $dsc),
                self::field('radio', 'Funkgerät', 'z. B. Kanal, Sendeleistung …', $radio),
                self::field('message', 'Meldung (Sprechfunk)', 'Wortlaut deiner Sprechfunk-Meldung – tippen oder einsprechen …', [implode("\n", $message[0]), $message[1]], true),
            ],
        ];
    }

    private static function srcRadioDistress(): array
    {
        return ['Kanal 16, hohe Sendeleistung (25 W)', [
            ['Kanal 16', '(?<!\d)16(?!\d)', 1],
            ['Hohe Sendeleistung (25 W)', '(?<!\d)25(?!\d)|HOCH|HIGH|VOLL|MAX', 1],
        ]];
    }

    private static function srcDscAllShips(string $call, string $label, string $pattern): array
    {
        return ["{$call} an alle Funkstellen (All Ships), Folgekanal 16, Sprechfunk (Telephony).", [
            [$label, $pattern, 1],
            ['an alle Funkstellen (All Ships)', 'ALL SHIPS|ALL STATIONS|ALLE FUNKSTELLEN|ALLE SCHIFFE|ALLE STATIONEN|ALLGEMEIN|\bALLE\b', 1],
            ['Folgekanal 16', '(?<!\d)16(?!\d)', 1],
        ]];
    }

    // --- UBI ---------------------------------------------------------------

    private static function buildUbi(array $s): array
    {
        $own = $s['vessel'];
        $where = self::ubiWhere($s['where']);
        $intro = sprintf('Du bist mit dem %s „%s“, Rufzeichen %s, %s.', $s['type'], self::title($own), $s['callsign'], $where['text']);
        $identity = [self::times($own, 3), self::spell($s['callsign'], 'de')];
        $persons = $s['persons'] ?? 0;
        $nif = 'Kanal des Verkehrskreises Nautische Information für diesen Streckenabschnitt (Handbuch Binnenschifffahrtsfunk bzw. blaues Tafelzeichen „UKW …“); Sendeleistung auf 25 W erhöhen – das ist nur in diesem Verkehrskreis zulässig. Kanal 10 sendet automatisch mit höchstens 1 W.';
        $nifChecks = [
            // Review: ein bloßes „Kanal“ (z. B. „Kanal 16“) zählte bisher als richtig.
            ['Kanal der Nautischen Information', self::NIF_CHANNELS_PATTERN.'|NAUTISCH|\bNIF\b|HANDBUCH|TAFEL', 1],
            ['Sendeleistung 25 W', '(?<!\d)25(?!\d)', 1],
        ];

        switch ($s['kind']) {
            case 'distress':
                [$de, $msg, $kw] = self::UBI_NATURES[$s['nature']];
                $situation = "{$intro} ".self::ucfirst($de).". {$persons} Personen an Bord. Leite den notwendigen Funkverkehr ein!";
                $message = ['MAYDAY MAYDAY MAYDAY', 'HIER IST', ...$identity, "MAYDAY {$own} ".self::spell($s['callsign'], 'de'), 'POSITION '.$where['spoken'], $msg, "{$persons} PERSONEN AN BORD", 'ERBITTE SOFORTIGE HILFE', 'OVER'];

                return self::ubiTask($situation,
                    ['Notverkehr – Notmeldung (MAYDAY)', [['Notverkehr', self::RANK_DISTRESS, 1]]],
                    ['Nautische Information – die zuständige Revierzentrale bzw. Schleuse anrufen (bevorzugt für Rettungsmaßnahmen); Schiffe in der Nähe hören zusätzlich Kanal 10 (Schiff–Schiff). Auf Strecken ohne Nautische Information: Kanal 10.', [
                        ['Nautische Information (Revierzentrale)', 'NAUTISCH|\bNIF\b|REVIERZENTRALE|SCHLEUSE', 1],
                    ]],
                    [$nif, $nifChecks],
                    [$message, array_merge(self::checksMayday($own, $s['callsign'], null, 'HIER IST'), [
                        ['Position', $where['pattern'], 1],
                        ['Art der Not', $kw, 1],
                        ["Personen an Bord ({$persons})", self::personsPattern($persons), 1],
                        ['Erbetene Hilfe', 'HILFE|ASSISTANCE', 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ])]
                );

            case 'relay':
                $d = $s['distressed'];
                [$de, $msg, $kw] = self::UBI_NATURES[$d['nature']];
                $situation = "{$intro} Du hörst auf Kanal 10 die Notmeldung vom {$d['type']} „".self::title($d['vessel'])."“, Rufzeichen {$d['callsign']}, {$where['near']}: {$de}, {$d['persons']} Personen an Bord. Niemand antwortet. Du kannst selbst nicht helfen. Leite die Notmeldung weiter!";
                // MAYDAY RELAY richtet sich im Binnenfunk zuerst gezielt an die
                // Revierzentrale (sie alarmiert die Rettungskette), nicht an alle.
                $station = $s['called'] ?? 'REVIERZENTRALE';
                $message = ['MAYDAY RELAY MAYDAY RELAY MAYDAY RELAY', self::times($station, 3), 'HIER IST', ...$identity, "MAYDAY {$d['vessel']} ".self::spell($d['callsign'], 'de'), 'POSITION '.$where['spoken'], $msg, "{$d['persons']} PERSONEN AN BORD", 'OVER'];

                return self::ubiTask($situation,
                    ['Notverkehr – Weiterleitung einer Notmeldung (MAYDAY RELAY)', [['Notverkehr', self::RANK_DISTRESS, 1], ['Weiterleitung (Relay)', 'RELAY|WEITERLEIT|WEITERGAB|WEITERGEB', 1]]],
                    ['Nautische Information – die zuständige Revierzentrale anrufen (sie leitet die Rettungsmaßnahmen ein).', [
                        ['Nautische Information (Revierzentrale)', 'NAUTISCH|\bNIF\b|REVIERZENTRALE', 1],
                    ]],
                    [$nif, $nifChecks],
                    [$message, [
                        ['MAYDAY RELAY dreimal', 'MAYDAY RELAY', 3],
                        ['An die Revierzentrale gerichtet', self::UBI_STATION, 1],
                        ['HIER IST', '\bHIER IST\b|\bTHIS IS\b', 1],
                        ["Eigener Name ({$own})", self::word($own), 1],
                        ['Eigenes Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['Fahrzeug in Not ('.self::title($d['vessel']).')', self::word($d['vessel']), 1],
                        ['Position', $where['pattern'], 1],
                        ['Art der Not', $kw, 1],
                        ["Personen an Bord ({$d['persons']})", self::personsPattern($d['persons']), 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ]]
                );

            case 'urgency':
                [$de, $msg, $assist, $kw] = self::UBI_PROBLEMS[$s['problem']];
                $situation = "{$intro} ".self::ucfirst($de).". {$persons} Personen an Bord. Leite den notwendigen Funkverkehr ein!";
                // PAN PAN im Binnenfunk an die zuständige Revierzentrale; an
                // alle Funkstellen nur, wenn keine Feststation erreichbar ist.
                $station = $s['called'] ?? 'REVIERZENTRALE';
                $message = ['PAN PAN PAN PAN PAN PAN', self::times($station, 3), 'HIER IST', ...$identity, 'POSITION '.$where['spoken'], $msg, $assist, "{$persons} PERSONEN AN BORD", 'OVER'];

                return self::ubiTask($situation,
                    ['Dringlichkeitsverkehr – Dringlichkeitsmeldung (PAN PAN)', [['Dringlichkeitsverkehr', self::RANK_URGENCY, 1]]],
                    ['Nautische Information – an die zuständige Revierzentrale (sie koordiniert den Verkehr); an alle Funkstellen nur, wenn keine Feststation erreichbar ist oder Schiffe in der Nähe unmittelbar gefährdet sind.', [
                        ['Nautische Information (Revierzentrale) oder Schiff–Schiff', 'NAUTISCH|\bNIF\b|REVIERZENTRALE|SCHIFF ?SCHIFF', 1],
                    ]],
                    [$nif, $nifChecks],
                    [$message, [
                        ['PAN PAN dreimal', 'PAN PAN', 3],
                        ['An die Revierzentrale gerichtet (sonst an alle Funkstellen)', self::UBI_STATION.'|AN ALLE FUNKSTELLEN', 1],
                        ['HIER IST', '\bHIER IST\b|\bTHIS IS\b', 1],
                        ["Name dreimal ({$own})", self::word($own), 3],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['Position', $where['pattern'], 1],
                        ['Art des Problems', $kw, 1],
                        ['Erbetene Hilfe', 'HILFE|SCHLEPP|TREIBSTOFF', 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ]]
                );

            case 'urgency_cancel':
                [$de] = self::UBI_PROBLEMS[$s['problem']];
                $situation = "{$intro} Um {$s['urgency_time']} Uhr hast du eine Dringlichkeitsmeldung gesendet ({$de}). Inzwischen ist das Problem behoben. Hebe die Dringlichkeitsmeldung auf!";
                $station = $s['called'] ?? 'REVIERZENTRALE';
                $message = ['PAN PAN PAN PAN PAN PAN', self::times($station, 3), 'HIER IST', ...$identity, "MEINE DRINGLICHKEITSMELDUNG VON {$s['urgency_time']} UHR IST AUFGEHOBEN", 'DAS PROBLEM IST BEHOBEN', 'OVER'];

                return self::ubiTask($situation,
                    ['Dringlichkeitsverkehr – Aufhebung einer Dringlichkeitsmeldung', [['Dringlichkeitsverkehr', self::RANK_URGENCY, 1], ['Aufhebung', 'AUFHEB|ZURÜCK|RÜCKNAHME|CANCEL', 1]]],
                    ['Derselbe Verkehrskreis wie bei der Dringlichkeitsmeldung: Nautische Information (Revierzentrale).', [
                        ['Nautische Information (Revierzentrale) oder Schiff–Schiff', 'NAUTISCH|\bNIF\b|REVIERZENTRALE|SCHIFF ?SCHIFF', 1],
                    ]],
                    [$nif, $nifChecks],
                    [$message, [
                        ['PAN PAN dreimal (gleiches Anrufschema)', 'PAN PAN', 3],
                        ['An dieselbe Funkstelle wie die Dringlichkeitsmeldung', self::UBI_STATION.'|AN ALLE FUNKSTELLEN', 1],
                        ['HIER IST', '\bHIER IST\b|\bTHIS IS\b', 1],
                        ["Name dreimal ({$own})", self::word($own), 3],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ["Uhrzeit der Dringlichkeitsmeldung ({$s['urgency_time']} Uhr)", self::timePattern($s['urgency_time']), 1],
                        ['Hinweis auf die Rücknahme', 'AUFGEHOBEN|AUFHEB|ZURÜCK|CANCEL', 1],
                    ]]
                );

            case 'safety':
                [$de, $msg, $kw] = self::UBI_HAZARDS[$s['hazard']];
                $situation = "{$intro} Du sichtest {$de}. Das ist eine Gefahr für die Schifffahrt. Leite den notwendigen Funkverkehr ein!";
                $message = ['SECURITE SECURITE SECURITE', 'AN ALLE FUNKSTELLEN AN ALLE FUNKSTELLEN AN ALLE FUNKSTELLEN', 'HIER IST', ...$identity, "{$msg} BEI ".$where['spoken'], 'GEFAHR FÜR DIE SCHIFFFAHRT', 'ENDE'];

                return self::ubiTask($situation,
                    ['Sicherheitsverkehr – Sicherheitsmeldung (SECURITE)', [['Sicherheitsverkehr', 'SICHERHEIT|SAFETY|SECURIT', 1]]],
                    ['Schiff–Schiff (Kanal 10) an alle Funkstellen; zusätzlich der zuständigen Revierzentrale (Nautische Information) melden.', [
                        ['Schiff–Schiff (Kanal 10) bzw. Nautische Information', 'SCHIFF ?SCHIFF|(?<!\d)10(?!\d)|NAUTISCH|\bNIF\b|REVIERZENTRALE', 1],
                    ]],
                    ['Kanal 10 (Schiff–Schiff), Sendeleistung automatisch höchstens 1 W', [
                        ['Kanal 10', '(?<!\d)10(?!\d)', 1],
                    ]],
                    [$message, [
                        ['SECURITE dreimal', 'SECURIT', 3],
                        ['HIER IST', '\bHIER IST\b|\bTHIS IS\b', 1],
                        ["Name dreimal ({$own})", self::word($own), 3],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['Art der Gefahr', $kw, 1],
                        ['Ort der Gefahr', $where['pattern'], 1],
                    ]]
                );

            case 'routine':
                $r = self::UBI_ROUTINE[$s['topic']];
                $called = $s['called'] ?? $r['called'];
                $situation = "{$intro} ".str_replace(['{called}', '{channel}'], [self::title($called), (string) ($s['channel'] ?? '')], $r['situation']);
                $message = [self::times($called, 2), 'HIER IST', self::times($own, 2), self::spell($s['callsign'], 'de'), $r['message'], 'OVER'];

                return self::ubiTask($situation,
                    ['Routineverkehr', [['Routineverkehr', 'ROUTINE', 1]]],
                    [$r['circle'], [[$r['circle_label'], $r['circle_pattern'], 1]]],
                    [str_replace('{channel}', (string) ($s['channel'] ?? ''), $r['radio']), [[str_replace('{channel}', (string) ($s['channel'] ?? ''), $r['radio_label']), isset($s['channel']) ? self::channelPattern($s['channel']) : $r['radio_pattern'], 1]]],
                    [$message, [
                        ['Gerufene Funkstelle ('.self::title($called).')', self::word($called), 1],
                        ['HIER IST', '\bHIER IST\b|\bTHIS IS\b', 1],
                        ["Eigener Name ({$own})", self::word($own), 1],
                        ['Rufzeichen', self::callsignPattern($s['callsign']), 1],
                        ['Abschluss OVER', self::OVER, 1],
                    ]]
                );
        }

        throw new \InvalidArgumentException("Unbekannte UBI-Aufgabenart {$s['kind']}");
    }

    /**
     * Bug-Review (Anrede/Begegnung): Situationstexte duzen den Lerner wie
     * die übrige Oberfläche (vorher "Sie sind mit …", "Senden Sie …"); der
     * Wortlaut der Funkmeldungen selbst (z. B. "ICH MÖCHTE SIE … ÜBERHOLEN"
     * an das andere Fahrzeug) bleibt förmlich. "meeting": ein Bergfahrer
     * hat das rechte Ufer (in Fließrichtung gesehen) an seiner
     * Backbordseite -- hält er sich dort, begegnet er dem Talfahrer
     * Steuerbord an Steuerbord. Für die angesagte Begegnung Backbord an
     * Backbord hält er sich am linken Ufer (vorher widersprüchlich).
     */
    private const UBI_ROUTINE = [
        'lock' => [
            'called' => 'SCHLEUSE', 'situation' => 'Du möchtest dich bei der vor dir liegenden Schleuse zur Schleusung anmelden. An der Einfahrt steht ein Tafelzeichen „UKW {channel}“. Stelle die Verbindung her!',
            'message' => 'ICH MÖCHTE MICH ZUR SCHLEUSUNG ANMELDEN, LÄNGE 10 METER',
            'circle' => 'Nautische Information (Schleuse).', 'circle_label' => 'Nautische Information', 'circle_pattern' => 'NAUTISCH|\bNIF\b|SCHLEUSE',
            'radio' => 'Kanal {channel} laut Tafelzeichen', 'radio_label' => 'Kanal {channel}', 'radio_pattern' => '',
        ],
        'bridge' => [
            'called' => 'HUBBRÜCKE', 'situation' => 'Du möchtest dich zur Durchfahrt der vor dir liegenden Hubbrücke anmelden. Ein Tafelzeichen weist auf „UKW {channel}“ hin. Stelle die Verbindung her!',
            'message' => 'ICH MÖCHTE DIE BRÜCKE PASSIEREN, BITTE UM ÖFFNUNG',
            'circle' => 'Nautische Information (Brückenfunkstelle).', 'circle_label' => 'Nautische Information', 'circle_pattern' => 'NAUTISCH|\bNIF\b|BRÜCKE',
            'radio' => 'Kanal {channel} laut Tafelzeichen', 'radio_label' => 'Kanal {channel}', 'radio_pattern' => '',
        ],
        'harbour' => [
            'called' => 'MARINA', 'situation' => 'Du möchtest bei der „{called}“ einen Liegeplatz für die Nacht anfragen. Die Marina ist laut Handbuch auf Kanal {channel} erreichbar. Stelle die Verbindung her!',
            'message' => 'ICH ERBITTE EINEN LIEGEPLATZ FÜR EINE NACHT, LÄNGE 10 METER',
            'circle' => 'Schiff–Hafenbehörde.', 'circle_label' => 'Schiff–Hafenbehörde', 'circle_pattern' => 'HAFEN',
            'radio' => 'Kanal {channel}, Sendeleistung 1 W', 'radio_label' => 'Kanal {channel}', 'radio_pattern' => '',
        ],
        'meeting' => [
            'called' => 'HANSESTADT', 'situation' => 'Vor dir kommt das Motorschiff „{called}“ zu Tal. Du möchtest die Begegnung absprechen (du hältst dich am linken Ufer). Stelle die Verbindung her!',
            'message' => 'ICH BIN BERGFAHRER, ICH HALTE MICH AM LINKEN UFER, BEGEGNUNG BACKBORD AN BACKBORD',
            'circle' => 'Schiff–Schiff.', 'circle_label' => 'Schiff–Schiff', 'circle_pattern' => 'SCHIFF ?SCHIFF',
            'radio' => 'Kanal 10, Sendeleistung automatisch höchstens 1 W', 'radio_label' => 'Kanal 10', 'radio_pattern' => '(?<!\d)10(?!\d)',
        ],
        'overtake' => [
            'called' => 'RHEINLAND', 'situation' => 'Du möchtest den vor dir fahrenden Schubverband „{called}“ überholen und das Manöver absprechen. Stelle die Verbindung her!',
            'message' => 'ICH MÖCHTE SIE AN BACKBORD ÜBERHOLEN, BITTE BESTÄTIGEN',
            'circle' => 'Schiff–Schiff.', 'circle_label' => 'Schiff–Schiff', 'circle_pattern' => 'SCHIFF ?SCHIFF',
            'radio' => 'Kanal 10, Sendeleistung automatisch höchstens 1 W', 'radio_label' => 'Kanal 10', 'radio_pattern' => '(?<!\d)10(?!\d)',
        ],
        'water_level' => [
            'called' => 'REVIERZENTRALE', 'situation' => 'Du möchtest bei der zuständigen Revierzentrale die aktuellen Wasserstände für deine weitere Fahrt erfragen. Stelle die Verbindung her!',
            'message' => 'ERBITTE DIE AKTUELLEN WASSERSTÄNDE FÜR MEINE WEITERE FAHRT',
            'circle' => 'Nautische Information (Revierzentrale).', 'circle_label' => 'Nautische Information', 'circle_pattern' => 'NAUTISCH|\bNIF\b|REVIERZENTRALE',
            'radio' => 'Kanal des Verkehrskreises Nautische Information für diesen Streckenabschnitt (Handbuch Binnenschifffahrtsfunk)', 'radio_label' => 'Kanal der Nautischen Information', 'radio_pattern' => self::NIF_CHANNELS_PATTERN.'|NAUTISCH|\bNIF\b|HANDBUCH',
        ],
    ];

    private static function ubiTask(string $situation, array $rank, array $circle, array $radio, array $message): array
    {
        return [
            'situation_de' => $situation,
            'situation_en' => null,
            'fields' => [
                self::field('rank', 'Rang', 'Verkehrsart und Meldungstyp …', $rank),
                self::field('circle', 'Verkehrskreis', 'Deine Eingabe …', $circle),
                self::field('radio', 'Funkgerät', 'z. B. Kanal, Sendeleistung …', $radio),
                self::field('message', 'Meldung (Sprechfunk)', 'Wortlaut deiner Sprechfunk-Meldung – tippen oder einsprechen …', [implode("\n", $message[0]), $message[1]], true),
            ],
        ];
    }

    private const FEMININE_WATERWAYS = ['Weser', 'Mosel', 'Elbe', 'Donau', 'Saar', 'Havel', 'Spree', 'Oder', 'Ems', 'Lahn'];

    /** @return array{text: string, near: string, spoken: string, pattern: string} */
    private static function ubiWhere(array $where): array
    {
        if (isset($where['km'])) {
            $direction = isset($where['direction']) ? ", {$where['direction']}" : '';
            $on = in_array($where['waterway'], self::FEMININE_WATERWAYS, true) ? 'auf der' : 'auf dem';

            return [
                'text' => "{$on} {$where['waterway']} bei Kilometer {$where['km']}{$direction}",
                'near' => "{$on} {$where['waterway']} bei Kilometer {$where['km']}",
                'spoken' => mb_strtoupper("{$where['waterway']} KILOMETER {$where['km']}".(isset($where['direction']) ? ", {$where['direction']}" : '')),
                'pattern' => '(?<!\d)'.$where['km'].'(?!\d)',
            ];
        }

        return ['text' => $where['text'], 'near' => $where['text'], 'spoken' => mb_strtoupper($where['spoken']), 'pattern' => $where['pattern']];
    }

    // --- Bausteine -----------------------------------------------------------

    private const RANK_DISTRESS = '\bNOT(VERKEHR|MELDUNG|ALARM|FALL|RUF)?\b|DISTRESS|MAYDAY';

    private const RANK_URGENCY = 'DRINGLICH|URGENCY|PAN PAN';

    private const OVER = '\b(OVER|KOMMEN)\b';

    // Feststation im Binnenfunk, an die sich PAN PAN und MAYDAY RELAY richten.
    private const UBI_STATION = 'REVIERZENTRALE|VERKEHRSZENTRALE|SCHLEUSE';

    private const OVER_OR_OUT = '\b(OVER|OUT|KOMMEN|ENDE)\b';

    private static function field(string $name, string $label, string $placeholder, array $solution, bool $multiline = false): array
    {
        return ['name' => $name, 'label' => $label, 'placeholder' => $placeholder, 'multiline' => $multiline, 'solution' => $solution[0], 'checks' => $solution[1]];
    }

    /** Prüfpunkte für den Anruf einer Notmeldung: MAYDAY dreimal, Name dreimal, Rufzeichen, ggf. MMSI, zweites MAYDAY. */
    private static function checksMayday(string $vessel, string $callsign, ?string $mmsi, string $thisIs): array
    {
        $checks = [
            ['MAYDAY dreimal', '\bMAYDAY\b', 3],
            [$thisIs, '\bTHIS IS\b|\bHIER IST\b', 1],
            ['Name dreimal ('.self::title($vessel).')', self::word($vessel), 3],
            ['Rufzeichen', self::callsignPattern($callsign), 1],
        ];

        if ($mmsi) {
            $checks[] = ['MMSI', self::digitsPattern($mmsi), 1];
        }

        $checks[] = ['Meldung beginnt erneut mit MAYDAY + Name', '\bMAYDAY\b', 4];

        return $checks;
    }

    private static function word(string $word): string
    {
        return '\b'.preg_quote(trim(self::normalize($word)), '/').'\b';
    }

    private static function times(string $word, int $count): string
    {
        return implode(' ', array_fill(0, $count, $word));
    }

    private static function title(string $name): string
    {
        return mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** Rufzeichen in Buchstabiertafel und Zahlwörtern, z. B. "DELTA BRAVO FOUR FIVE TWO ONE". */
    private static function spell(string $callsign, string $lang = 'en'): string
    {
        $digits = $lang === 'de' ? self::DIGITS_DE : self::DIGITS_EN;

        return implode(' ', array_map(fn ($c) => ctype_digit($c) ? $digits[(int) $c] : self::NATO[$c], str_split(strtoupper($callsign))));
    }

    // Review-Runde 4: Zahlwörter nur als ganzes Wort („SEVENTEEN“ ist keine 7).
    private static function digitAlternatives(int $digit): string
    {
        return $digit.'|(?:'.self::digitWords($digit).')\\b';
    }

    private static function digitWords(int $digit): string
    {
        return implode('|', array_merge([self::DIGITS_EN[$digit], self::DIGITS_DE[$digit]], self::DIGIT_ALIASES[$digit] ?? []));
    }

    /** Danach folgt keine weitere Ziffer (als Zahl oder Zahlwort). */
    private static function noFurtherDigit(): string
    {
        return '(?!\\d)(?! ?(?:'.implode('|', array_map(fn ($d) => self::digitWords($d), range(0, 9))).')\\b)';
    }

    /** Erkennt das Rufzeichen zusammengeschrieben, mit Leerzeichen oder buchstabiert. */
    private static function callsignPattern(string $callsign): string
    {
        $parts = array_map(function ($c) {
            if (ctype_digit($c)) {
                return '(?:'.self::digitAlternatives((int) $c).')';
            }

            $nato = self::NATO[$c];
            $alt = match ($c) {
                'A' => '|ALPHA', 'J' => '|JULIET', 'W' => '|WHISKY', default => ''
            };

            return "(?:{$nato}{$alt}|{$c})";
        }, str_split(strtoupper($callsign)));

        return '(?<![A-Z0-9])'.implode(' ?', $parts).'(?![0-9])';
    }

    private static function digitsPattern(string $digits): string
    {
        return '(?<!\d)'.implode(' ?', array_map(fn ($d) => '(?:'.self::digitAlternatives((int) $d).')', str_split($digits))).self::noFurtherDigit();
    }

    /** Kanal als Zahl („06“, „6“) oder Ziffer für Ziffer gesprochen („ZERO SIX“). */
    private static function workChannelPattern(int $channel): string
    {
        return self::channelPattern($channel).'|'.self::digitsPattern(sprintf('%02d', $channel)).'|'.self::digitsPattern((string) $channel);
    }

    private static function channelPattern(int $channel): string
    {
        return '(?<!\d)0?'.$channel.'(?!\d)';
    }

    // Review: Uhrzeit und Position auch Ziffer für Ziffer gesprochen
    // („ONE SIX ONE EIGHT“, „FIVE FOUR DEGREES“), wie Rufzeichen und MMSI.
    private static function timePattern(string $time): string
    {
        return self::digitsPattern(str_replace(':', '', $time));
    }

    /** Position als "54°11'N 007°55'E". */
    private static function posText(array $p): string
    {
        return sprintf("%02d°%02d'%s %03d°%02d'%s", $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]);
    }

    private static function posSpoken(array $p): string
    {
        $ns = $p[2] === 'N' ? 'NORTH' : 'SOUTH';
        $ew = $p[5] === 'E' ? 'EAST' : 'WEST';

        return sprintf('%02d DEGREES %02d MINUTES %s %03d DEGREES %02d MINUTES %s', $p[0], $p[1], $ns, $p[3], $p[4], $ew);
    }

    /** Die vier Zahlen der Position in dieser Reihenfolge, dazwischen beliebiger Text. */
    private static function posPattern(array $p): string
    {
        // Jede Ziffer als Ziffer oder Zahlwort, mit oder ohne Leerzeichen
        // („54“, „5 4“, „FIVE FOUR“), auch direkt hinter N/E („N54“).
        $digit = fn (int $d): string => '(?:'.$d.'|\\b(?:'.self::digitWords($d).')\\b)';
        $seq = fn (string $digits): string => implode(' ?', array_map(fn ($d) => $digit((int) $d), str_split($digits)));
        $widths = [2, 2, 3, 2];
        $values = [$p[0], $p[1], $p[3], $p[4]];
        $full = fn (int $i): string => sprintf('%0'.$widths[$i].'d', $values[$i]);

        // Danach folgt keine weitere Ziffer -- „ZERO ZERO SEVEN TWO“ ist
        // nicht 007. Ausnahme: Die Zahl steht vollständig (zwei bzw. drei
        // Stellen) und direkt danach beginnt die nächste Zahl der Position
        // („FIVE FOUR ONE ZERO NORTH“).
        $n = function (int $i) use ($digit, $seq, $values, $full): string {
            $flexible = '(?:'.$digit(0).' ?){0,2}'.$seq((string) $values[$i]).self::noFurtherDigit();
            if ($i === 3) {
                return '(?<!\\d)'.$flexible;
            }

            return '(?<!\\d)(?:'.$flexible.'|'.$seq($full($i)).'(?= ?'.$digit((int) $full($i + 1)[0]).'))';
        };

        return $n(0).'.{0,40}?'.$n(1).'.{0,60}?'.$n(2).'.{0,40}?'.$n(3);
    }

    private static function personsPattern(int $persons): string
    {
        $count = '(?:'.self::digitAlternatives($persons).')';
        $noun = '(?:PERSON|\bPOB\b|PEOPLE|CREW|SOULS|LEUTE|MANN)';

        return "\b{$count}\b.{0,25}?{$noun}|{$noun}.{0,25}?\b{$count}\b";
    }
}
