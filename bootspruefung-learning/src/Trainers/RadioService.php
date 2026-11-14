<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Funktraining (Kap. 4.9, 6.4) wie FunkTrainingController der Quelle:
 * - SRC-Seefunktexte: Mitschrift und Übersetzung (DE→EN oder EN→DE), Auswertung per Wortvergleich;
 * - UBI-Aufnahme (Diktat, vorgelesen per Browserstimme) und -Abgabe (Buchstabieren);
 * - Vokabeln/Buchstabiertafel: „gewusst/nicht gewusst“, gelernt nach 3 × gewusst in Folge.
 * Fortschritt in bp_radio_progress mit den Schlüsseln der Quelle.
 */
final class RadioService
{
    public const VOCAB_STREAK_TO_LEARN = 3;
    /** Zweite Antwort auf dieselbe Karte innerhalb dieser Sekunden = Doppelklick/zweiter Tab, wird ignoriert. */
    public const VOCAB_REPEAT_SECONDS = 2;

    private \wpdb $db;

    public function __construct(private readonly AccessService $access)
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** @return array<string,mixed> */
    public function overview(int $userId, object $course): array
    {
        $this->require($userId, $course);
        $texts = $this->items('radio_text', $course, ['id', 'source_id', 'catalog', 'exercise_type', 'number', 'direction', 'LEFT(COALESCE(text_de, segments), 90) AS teaser']);
        $vocab = $this->items('radio_vocabulary', $course, ['id', 'source_id', 'catalog', 'category']);
        $progress = $this->progress($userId, array_merge(array_column($texts, 'source_id'), array_column($vocab, 'source_id')));
        $groups = [];
        $limit = $this->access->limit($userId, $course->code, AccessService::RADIO);
        foreach ($texts as $t) {
            $key = $t->catalog.'|'.$t->exercise_type;
            $groups[$key] ??= ['catalog' => $t->catalog, 'type' => $t->exercise_type, 'items' => []];
            // Teilumfang: erste n Texte je Übungsart.
            $locked = $limit !== null && count($groups[$key]['items']) >= $limit;
            $groups[$key]['items'][] = ['id' => $t->source_id, 'number' => (int) $t->number, 'direction' => $t->direction, 'teaser' => $locked ? null : $t->teaser, 'done' => (bool) ($progress[$t->source_id]->done ?? false), 'locked' => $locked];
        }
        $categories = [];
        foreach ($vocab as $v) {
            $key = $v->catalog.'|'.$v->category;
            $categories[$key] ??= ['catalog' => $v->catalog, 'category' => $v->category, 'total' => 0, 'learned' => 0];
            $categories[$key]['total']++;
            $categories[$key]['learned'] += ($progress[$v->source_id]->done ?? 0) ? 1 : 0;
        }

        $practice = new RadioPracticeService($this->access);
        $device = new DeviceSimService($this->access, $practice);

        return [
            'texts' => array_values($groups),
            'vocabulary' => array_values($categories),
            'practice' => $practice->overview($userId, $course)['catalogs'],
            'device' => $device->summary($userId, $course),
        ];
    }

    /**
     * Buchstabiertafel: Alphabet der Quelle (RadioText::ALPHABET) mit Aussprachehilfen aus den
     * Vokabeln (Kategorie „Buchstabiertafel“), Ziffern je Funkkatalog: SRC englisch, UBI deutsch.
     * @return array<string,mixed>
     */
    public function spelling(int $userId, object $course): array
    {
        $this->require($userId, $course);
        $hints = [];
        // Nur freigegebene Vokabeln dieses Kurses (Status, Kurszuordnung, Rechte wie überall im Funktraining).
        foreach ($this->items('radio_vocabulary', $course, ['term_de', 'hint'], ['category' => 'Buchstabiertafel']) as $v) {
            if ((string) $v->hint !== '') {
                $hints[mb_strtoupper(trim((string) $v->term_de))] ??= (string) $v->hint;
            }
        }
        $catalogs = (new RadioPracticeService($this->access))->catalogsFor($course);
        if ($catalogs === []) {
            $catalogs = array_values(array_unique(array_column($this->items('radio_vocabulary', $course, ['catalog']), 'catalog')));
        }
        $letters = [];
        foreach (RadioText::ALPHABET as $letter => $word) {
            $letters[] = ['letter' => $letter, 'word' => $word, 'hint' => $hints[$letter] ?? null];
        }
        $digits = [];
        foreach ($catalogs as $catalog) {
            $digits[] = ['catalog' => $catalog, 'items' => array_map(static fn ($n) => [
                'digit' => (string) $n,
                'word' => $catalog === 'UBI' ? RadioText::DIGITS[$n] : RadioText::DIGITS_EN[$n][0],
                'spoken' => $catalog === 'UBI' ? null : (RadioText::DIGITS_EN[$n][1] ?? null),
            ], range(0, 9))];
        }

        return ['letters' => $letters, 'digits' => $digits, 'catalogs' => $catalogs];
    }

    /** @return array<string,mixed> */
    public function text(int $userId, object $course, string $sourceId): array
    {
        $this->require($userId, $course);
        $row = $this->item('radio_text', $course, $sourceId);
        $this->requireText($userId, $course, $row);
        $p = $this->progress($userId, [$sourceId])[$sourceId] ?? null;
        $data = $p && $p->data ? json_decode((string) $p->data, true) : [];
        $lines = $row->segments ? json_decode((string) $row->segments, true) : null;
        $siblings = array_column($this->items('radio_text', $course, ['source_id', 'exercise_type', 'catalog'], ['exercise_type' => $row->exercise_type, 'catalog' => $row->catalog]), 'source_id');
        $pos = array_search($sourceId, $siblings, true);

        return [
            'id' => $row->source_id,
            'catalog' => $row->catalog,
            'type' => $row->exercise_type,
            'number' => (int) $row->number,
            'direction' => $row->direction,
            // Übersetzungstexte: beide Sprachen; die App blendet die Zielsprache bis zur Auswertung aus.
            'text_de' => $row->text_de,
            'text_en' => $row->text_en,
            'lines' => $lines === null ? null : array_map(static fn (string $line) => [
                'text' => str_replace('*', '', $line),
                'spell' => array_map(static fn ($w) => ['word' => $w, 'spelled' => RadioText::spellGerman($w)], self::marked($line)),
            ], $lines),
            'answers' => $data,
            'done' => (bool) ($p->done ?? false),
            'previous' => $pos > 0 ? $siblings[$pos - 1] : null,
            'next' => $pos !== false && isset($siblings[$pos + 1]) ? $siblings[$pos + 1] : null,
        ];
    }

    /**
     * Wertet Mitschrift/Übersetzung aus und speichert sie.
     * @return array<string,mixed>
     */
    public function evaluate(int $userId, object $course, string $sourceId, string $direction, string $transcript, string $translation): array
    {
        $this->require($userId, $course);
        $row = $this->item('radio_text', $course, $sourceId);
        $this->requireText($userId, $course, $row);
        if (trim($transcript.$translation) === '') {
            throw new RuntimeException(__('Schreib zuerst mit oder übersetze, dann kannst du auswerten.', 'bootspruefung-learning'));
        }
        $transcript = mb_substr($transcript, 0, 5000);
        $translation = mb_substr($translation, 0, 5000);
        if ($row->exercise_type === 'translation') {
            if (! in_array($direction, ['de-en', 'en-de'], true)) {
                throw new RuntimeException(__('Ungültige Richtung.', 'bootspruefung-learning'));
            }
            [$from, $to] = $direction === 'de-en' ? [$row->text_de, $row->text_en] : [$row->text_en, $row->text_de];
            $result = [
                'transcript' => trim($transcript) !== '' ? RadioText::wordMatch((string) $from, $transcript) : null,
                'translation' => trim($translation) !== '' ? RadioText::wordMatch((string) $to, $translation) : null,
                'solution' => ['from' => $from, 'to' => $to],
            ];
            $store = [$direction => ['transcript' => $transcript, 'translation' => $translation]];
        } elseif ($row->exercise_type === 'dictation') {
            $result = ['transcript' => RadioText::wordMatch((string) $row->text_de, $transcript), 'translation' => null, 'solution' => ['from' => $row->text_de, 'to' => null]];
            $store = ['transcript' => $transcript];
        } else {
            throw new RuntimeException(__('Diese Übung wird nicht ausgewertet.', 'bootspruefung-learning'));
        }
        $p = $this->progress($userId, [$sourceId])[$sourceId] ?? null;
        $data = array_merge($p && $p->data ? (json_decode((string) $p->data, true) ?: []) : [], $store);
        $this->save($userId, 'text', $sourceId, ['data' => wp_json_encode($data)]);

        return $result;
    }

    public function setDone(int $userId, object $course, string $sourceId, bool $done): void
    {
        $this->require($userId, $course);
        $this->requireText($userId, $course, $this->item('radio_text', $course, $sourceId));
        $this->save($userId, 'text', $sourceId, ['done' => $done ? 1 : 0]);
    }

    /** @return array<string,mixed> */
    public function vocabulary(int $userId, object $course, string $catalog, ?string $category): array
    {
        $this->require($userId, $course);
        $where = ['catalog' => $catalog];
        if ($category !== null && $category !== '') {
            $where['category'] = $category;
        }
        $rows = $this->items('radio_vocabulary', $course, ['source_id', 'category', 'term_de', 'term_en', 'hint'], $where);
        $progress = $this->progress($userId, array_column($rows, 'source_id'));
        $cards = array_map(static fn ($r) => [
            'id' => $r->source_id,
            'category' => $r->category,
            'de' => $r->term_de,
            'en' => $r->term_en,
            'hint' => $r->hint,
            'streak' => (int) ($progress[$r->source_id]->streak ?? 0),
            'learned' => (bool) ($progress[$r->source_id]->done ?? false),
        ], $rows);

        return ['cards' => $cards, 'learned' => count(array_filter($cards, static fn ($c) => $c['learned'])), 'streak_to_learn' => self::VOCAB_STREAK_TO_LEARN];
    }

    /**
     * „Gewusst/nicht gewusst“ atomar zählen: Zeile anlegen bzw. sperren (FOR UPDATE), dann
     * aktualisieren. Eine weitere Antwort auf dieselbe Karte innerhalb von VOCAB_REPEAT_SECONDS
     * (Doppelklick, zweiter Tab) ändert nichts und liefert den gespeicherten Stand (repeated).
     * Schickt der Client die angezeigte Serie mit ($expectedStreak), gilt statt des Zeitfensters
     * ein Vergleich: weicht der gespeicherte Stand ab, war die Antwort schon gezählt → ignorieren.
     * @return array{streak:int,learned:bool,repeated:bool}
     */
    public function vocabularyAnswer(int $userId, object $course, string $sourceId, bool $known, ?int $expectedStreak = null): array
    {
        $this->require($userId, $course);
        $this->item('radio_vocabulary', $course, $sourceId);
        $table = $this->t('radio_progress');
        $this->db->query('START TRANSACTION');
        try {
            $created = (int) $this->db->query($this->db->prepare(
                "INSERT IGNORE INTO {$table} (user_id, item_type, item_source_id, streak, done) VALUES (%d, 'vocab', %s, 0, 0)",
                $userId,
                $sourceId
            )) === 1;
            $p = $this->db->get_row($this->db->prepare(
                "SELECT id, streak, done, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM {$table} WHERE user_id = %d AND item_type = 'vocab' AND item_source_id = %s FOR UPDATE",
                $userId,
                $sourceId
            ));
            if (! $p) {
                throw new RuntimeException(__('Antwort konnte nicht gespeichert werden.', 'bootspruefung-learning'));
            }
            // Mit Client-Serie: Vergleich (compare-and-set), sonst Zeitfenster.
            $repeat = ! $created && ($expectedStreak !== null
                ? $expectedStreak !== (int) $p->streak
                : (int) $p->age >= 0 && (int) $p->age < self::VOCAB_REPEAT_SECONDS);
            if ($repeat) {
                $this->db->query('COMMIT');

                return ['streak' => (int) $p->streak, 'learned' => (bool) $p->done, 'repeated' => true];
            }
            $streak = $known ? (int) $p->streak + 1 : 0;
            $learned = (bool) $p->done || $streak >= self::VOCAB_STREAK_TO_LEARN;
            // updated_at ausdrücklich setzen (ON UPDATE greift nur bei geänderten Werten).
            $this->db->query($this->db->prepare(
                "UPDATE {$table} SET streak = %d, done = %d, updated_at = NOW() WHERE id = %d",
                $streak,
                $learned ? 1 : 0,
                (int) $p->id
            ));
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        return ['streak' => $streak, 'learned' => $learned, 'repeated' => false];
    }

    /** Überblick für die Kursseite. @return array<string,int> */
    public function summary(int $userId, object $course): array
    {
        if (! $this->access->can($userId, $course->code, AccessService::RADIO)) {
            return ['texts' => 0, 'vocabulary' => 0];
        }

        return [
            'texts' => count($this->items('radio_text', $course, ['id'])),
            'vocabulary' => count($this->items('radio_vocabulary', $course, ['id'])),
        ];
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private static function marked(string $line): array
    {
        preg_match_all('/\*([^*]+)\*/u', $line, $m);

        return $m[1];
    }

    /**
     * @param list<string> $columns
     * @param array<string,string> $where
     * @return list<object>
     */
    private function items(string $table, object $course, array $columns, array $where = []): array
    {
        $sql = 'SELECT '.implode(', ', array_map(static fn ($c) => str_contains($c, ' ') ? $c : 'x.'.$c, $columns))." FROM {$this->t($table)} x ".Overrides::rightsJoin($table, 'x', 'o')
            ." WHERE x.status = 'published' AND FIND_IN_SET(%s, x.course_codes) AND COALESCE(o.value, x.rights_status) NOT IN (".Overrides::blockingList().')';
        $args = [$course->code];
        foreach ($where as $column => $value) {
            $sql .= ' AND x.'.preg_replace('/[^a-z_]/', '', $column).' = %s';
            $args[] = $value;
        }

        return $this->db->get_results($this->db->prepare($sql.' ORDER BY x.sort_order, x.id', $args));
    }

    /** Teilumfang: Position des Textes in seiner Übungsart prüfen. */
    private function requireText(int $userId, object $course, object $row): void
    {
        $limit = $this->access->limit($userId, $course->code, AccessService::RADIO);
        if ($limit === null) {
            return;
        }
        $siblings = array_column($this->items('radio_text', $course, ['source_id', 'exercise_type', 'catalog'], ['exercise_type' => $row->exercise_type, 'catalog' => $row->catalog]), 'source_id');
        $pos = array_search($row->source_id, $siblings, true);
        if ($pos === false || $pos >= $limit) {
            throw new AccessDenied(__('Diese Übung ist im kostenlosen Umfang nicht enthalten.', 'bootspruefung-learning'));
        }
    }

    private function item(string $table, object $course, string $sourceId): object
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT x.* FROM {$this->t($table)} x ".Overrides::rightsJoin($table, 'x', 'o')."
             WHERE x.source_id = %s AND x.status = 'published' AND FIND_IN_SET(%s, x.course_codes) AND COALESCE(o.value, x.rights_status) NOT IN (".Overrides::blockingList().')',
            $sourceId,
            $course->code
        ));
        if ($rows === []) {
            throw new NotFound(__('Übung nicht gefunden.', 'bootspruefung-learning'));
        }

        return $rows[0];
    }

    /** @param list<string> $keys @return array<string,object> */
    private function progress(int $userId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_source_id IN ({$placeholders})", array_merge([$userId], $keys)));
        $out = [];
        foreach ($rows as $row) {
            $out[$row->item_source_id] = $row;
        }

        return $out;
    }

    /** @param array<string,mixed> $fields */
    private function save(int $userId, string $type, string $key, array $fields): void
    {
        $existing = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t('radio_progress')} WHERE user_id = %d AND item_type = %s AND item_source_id = %s", $userId, $type, $key));
        if ($existing) {
            $this->db->update($this->t('radio_progress'), $fields, ['id' => (int) $existing]);
        } else {
            $this->db->insert($this->t('radio_progress'), array_merge(['user_id' => $userId, 'item_type' => $type, 'item_source_id' => $key], $fields));
        }
    }

    private function require(int $userId, object $course): void
    {
        if (! $this->access->can($userId, $course->code, AccessService::RADIO)) {
            throw new AccessDenied(__('Das Funktraining ist für deinen Kurs nicht freigeschaltet.', 'bootspruefung-learning'));
        }
    }
}
