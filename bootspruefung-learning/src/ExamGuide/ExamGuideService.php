<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\ExamGuide;

use Bootspruefung\Learning\Content\Import\EntityImporter;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Seite „Prüfung anmelden“ (wie ExamBooking/exam-booking der Quelle): Lernende
 * melden sich selbst beim Prüfungsausschuss an; die Seite zeigt je Prüfung
 * Gebühr, Fristen, Mindestalter, Unterlagen, Prüfungsformen mit Umfang und
 * Bestehensgrenze, Prüfungsteile und FAQ – dazu die Liste der Prüfungsausschüsse
 * (aus dem Content-Paket; nicht vollständig, die Seite weist darauf hin).
 * Kein Formular, keine Buchung, keine Personendaten.
 */
final class ExamGuideService
{
    private \wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('exam_guide_enabled');
    }

    /** @return list<array<string,mixed>> alle Anleitungen (dekodiert, mit source_id) */
    public function guides(): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT source_id, data FROM {$this->t('exam_guide')} WHERE status <> %s ORDER BY sort_order, id", EntityImporter::ARCHIVED));
        $out = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true);
            if (is_array($data)) {
                $out[] = GuideCorrections::apply(['key' => $row->source_id] + $data);
            }
        }

        return $out;
    }

    /**
     * Seiten eines Kurses in Reiter-Reihenfolge, jeweils mit der Variante, die zuerst steht.
     *
     * @return list<array{guide:array<string,mixed>,first:string}>
     */
    public function pagesFor(string $courseCode): array
    {
        $pages = [];
        foreach ($this->guides() as $guide) {
            foreach ($guide['courses'] as $c) {
                if ($c['course_code'] === $courseCode) {
                    $pages[] = [
                        'guide' => GuideCorrections::forCourse($guide, $courseCode),
                        'first' => GuideCorrections::firstVariant($courseCode, (string) $guide['key'], (string) $c['first_variant']),
                        'order' => (int) $c['tab_order'],
                    ];
                }
            }
        }
        usort($pages, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(static fn ($p) => ['guide' => $p['guide'], 'first' => $p['first']], $pages);
    }

    /** @return array<string,mixed> Daten für die Seite eines Kurses */
    public function payload(string $courseCode): array
    {
        $tabs = [];
        foreach ($this->pagesFor($courseCode) as $page) {
            $g = $page['guide'];
            $tabs[] = [
                'key' => $g['key'], 'tab' => $g['tab'], 'title' => $g['title'], 'intro' => $g['intro'],
                'fee' => $g['fee'], 'fee_note' => $g['fee_note'] ?? null, 'lead_time' => $g['lead_time'], 'min_age' => $g['min_age'],
                'apply' => $g['apply'], 'deadline' => $g['deadline'], 'documents' => $g['documents'],
                'variants' => $this->variants($g['variants'], $page['first']),
                'exam_parts' => $g['exam_parts'], 'faq' => $g['faq'], 'providers' => $g['providers'],
            ];
        }

        return ['tabs' => $tabs, 'committees' => $this->committees($courseCode), 'committees_note' => self::committeesNote()];
    }

    /**
     * Hinweis zur Liste der Prüfungsausschüsse: Das Content-Paket enthält nur einen Teil (derzeit die DMYV-Liste der
     * Quelle, keine DSV-Ausschüsse) – die Liste darf nicht als vollständig erscheinen (Fachprüfung FACH-09).
     */
    public static function committeesNote(): string
    {
        return __('Die Liste ist nicht vollständig. Fehlt dein Prüfungsausschuss, findest du ihn über die Verbände oben – im Zweifel frag direkt beim zuständigen Prüfungsausschuss nach.', 'bootspruefung-learning');
    }

    /**
     * Varianten, die angegebene zuerst, mit Umfang und Bestehensgrenze (wie ExamBooking::variants).
     *
     * @param list<array<string,mixed>> $variants
     * @return list<array<string,mixed>>
     */
    public function variants(array $variants, string $first): array
    {
        usort($variants, static fn ($a, $b) => ($b['key'] === $first) <=> ($a['key'] === $first));
        $out = [];
        foreach ($variants as $v) {
            $parts = array_map(fn ($p) => $p + ['catalog' => $this->catalogSize((string) $p['module'])], $v['parts']);
            $out[] = $v + ['scope' => self::scope($parts), 'pass_text' => $v['pass'] ?? self::pass($parts)];
        }

        return $out;
    }

    /**
     * Fragen im amtlichen Katalog eines Moduls (wie withCount('questions') der Quelle).
     * Die freien Kartenaufgaben (chart_task) gibt es nur im Paket; sie zählen nicht zum Katalog.
     */
    public function catalogSize(string $moduleCode): ?int
    {
        $count = $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->t('module')} m JOIN {$this->t('module_question')} mq ON mq.module_id = m.id
             JOIN {$this->t('question')} q ON q.id = mq.question_id AND q.status <> %s
             JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id AND r.question_type <> 'chart_task'
             WHERE m.code = %s",
            EntityImporter::ARCHIVED, $moduleCode
        ));

        return $count === null ? null : (int) $count;
    }

    /** „24 Fragen aus 180“ bzw. „30 Fragen: 7 Basisfragen aus 72, 23 See-Fragen aus 213“. @param list<array<string,mixed>> $parts */
    public static function scope(array $parts): string
    {
        if (count($parts) === 1) {
            $p = $parts[0];

            return $p['of'] !== null
                ? sprintf(__('%1$d Fragen aus %2$d', 'bootspruefung-learning'), $p['of'], $p['catalog'])
                : sprintf(__('Fragen aus dem amtlichen Katalog (%d Fragen)', 'bootspruefung-learning'), $p['catalog']);
        }

        return sprintf(__('%d Fragen', 'bootspruefung-learning'), array_sum(array_column($parts, 'of'))).': '
            .implode(', ', array_map(static fn ($p) => sprintf(__('%1$d %2$s aus %3$d', 'bootspruefung-learning'), $p['of'], $p['label'], $p['catalog']), $parts));
    }

    /** „ab 19 richtigen (max. 5 Fehler)“; null ohne amtliche Grenze. @param list<array<string,mixed>> $parts */
    public static function pass(array $parts): ?string
    {
        foreach ($parts as $p) {
            if ($p['min'] === null) {
                return null;
            }
        }
        if (count($parts) === 1) {
            return sprintf(__('ab %1$d richtigen (max. %2$d Fehler)', 'bootspruefung-learning'), $parts[0]['min'], $parts[0]['of'] - $parts[0]['min']);
        }

        return implode(' '.__('und', 'bootspruefung-learning').' ', array_map(static fn ($p) => sprintf(__('%1$s mind. %2$d von %3$d', 'bootspruefung-learning'), $p['label'], $p['min'], $p['of']), $parts));
    }

    /**
     * Alle Prüfungsausschüsse; die für den Kurs gepflegten zuerst.
     *
     * @return list<array<string,mixed>>
     */
    public function committees(?string $courseCode = null): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t('exam_committee')} WHERE status <> %s ORDER BY sort_order, id", EntityImporter::ARCHIVED));
        $out = [];
        foreach ($rows as $c) {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string) $c->course_codes))));
            $derived = $codes === [] ? GuideCorrections::committeeCourses($c->location_type) : [];
            $out[] = [
                'id' => (int) $c->id,
                'label' => $c->location_type ? $c->commission.' — '.$c->location_type : $c->commission,
                'commission' => $c->commission, 'location_type' => $c->location_type, 'contact_person' => $c->contact_person,
                'address' => self::address($c->street, $c->postal_code, $c->city),
                'postal_code' => $c->postal_code, 'city' => $c->city,
                'phone' => $c->phone, 'fax' => $c->fax, 'email' => $c->email, 'website' => $c->website,
                'office_hours' => $c->office_hours, 'note' => $c->note, 'course_codes' => $codes,
                'states' => self::statesFor((string) $c->commission),
                // 2 = für diesen Kurs gepflegt/abgeleitet, 1 = allgemeine Stelle, 0 = Stelle für andere Prüfungen
                'rank' => $courseCode === null ? 1 : (in_array($courseCode, $codes ?: $derived, true) ? 2 : (($codes ?: $derived) === [] ? 1 : 0)),
                'matches' => $courseCode !== null && in_array($courseCode, $codes ?: $derived, true),
            ];
        }
        usort($out, static fn ($a, $b) => $b['rank'] <=> $a['rank']);

        return $out;
    }

    /**
     * Bundesländer, für die ein Prüfungsausschuss laut seinem Namen tätig ist (Karte im Lernbereich).
     * Abgeleitet aus Orts- und Regionsnamen – keine amtliche Zuständigkeitsliste.
     *
     * @return list<string> ISO-3166-2-Codes, z. B. DE-BY
     */
    public static function statesFor(string $commission): array
    {
        $rules = [
            '/\bNRW\b|Nordrhein/u' => 'DE-NW', '/Aurich|Niedersachsen|Ostfriesland/u' => 'DE-NI', '/Berlin/u' => 'DE-BE', '/Mecklenburg/u' => 'DE-MV',
            '/Bodensee|Stuttgart|Mannheim|Baden/u' => 'DE-BW', '/Brandenburg/u' => 'DE-BB', '/Sachsen-Anhalt/u' => 'DE-ST', '/Sachsen(?!-Anhalt)/u' => 'DE-SN',
            '/Thüringen/u' => 'DE-TH', '/Bremen/u' => 'DE-HB', '/Hamburg/u' => 'DE-HH', '/Deggendorf|München|Nürnberg|Regensburg|Bayern|\bMain\b/u' => 'DE-BY',
            '/Koblenz|Rhein-Mosel|Pfalz|Saar-Mosel/u' => 'DE-RP', '/Darmstadt|Wetzlar|Offenbach|Hessen|Frankfurt/u' => 'DE-HE', '/Saar/u' => 'DE-SL',
            '/Schleswig-Holstein|Kiel/u' => 'DE-SH',
        ];
        $states = [];
        foreach ($rules as $pattern => $code) {
            if (preg_match($pattern, $commission)) {
                $states[] = $code;
            }
        }

        return array_values(array_unique($states));
    }

    public static function address(?string $street, ?string $postal, ?string $city): ?string
    {
        $plzCity = trim(implode(' ', array_filter([$postal, $city])));
        $parts = array_filter([$street, $plzCity]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
