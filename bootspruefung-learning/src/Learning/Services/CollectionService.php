<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use RuntimeException;
use wpdb;

/**
 * „Favoriten & Fehler“: eigene Sammlungen je Kurs, in die Lernende ihre Favoriten einsortieren.
 * Sammlungen gehören immer einem Nutzer und einem Kurs; Fragen nur aus dem freigeschalteten Umfang.
 */
final class CollectionService
{
    public const MAX_COLLECTIONS = 30;
    public const MAX_NAME = 60;

    private wpdb $db;

    public function __construct(private readonly LearningService $learning, private readonly ContentRepository $content)
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** @return list<array{id:int,name:string,count:int}> */
    public function collections(int $userId, object $course): array
    {
        $allowed = $this->allowedIds($userId, $course);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT id, name FROM {$this->t('favorite_collection')} WHERE user_id = %d AND course_id = %d ORDER BY name, id",
            $userId,
            $course->id
        ));
        $items = $this->itemsByCollection(array_map(static fn ($r) => (int) $r->id, $rows));

        return array_map(static fn ($r) => [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'count' => count(array_intersect($items[(int) $r->id] ?? [], $allowed)),
        ], $rows);
    }

    public function create(int $userId, object $course, string $name): array
    {
        $name = $this->cleanName($name);
        $count = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t('favorite_collection')} WHERE user_id = %d AND course_id = %d", $userId, $course->id));
        if ($count >= self::MAX_COLLECTIONS) {
            throw new RuntimeException(sprintf(__('Höchstens %d Sammlungen je Kurs.', 'bootspruefung-learning'), self::MAX_COLLECTIONS));
        }
        $this->assertUnique($userId, (int) $course->id, $name, null);
        if (! $this->db->insert($this->t('favorite_collection'), ['user_id' => $userId, 'course_id' => (int) $course->id, 'name' => $name, 'created_at' => current_time('mysql', true)])) {
            throw new RuntimeException(__('Die Sammlung konnte nicht angelegt werden.', 'bootspruefung-learning'));
        }

        return ['id' => (int) $this->db->insert_id, 'name' => $name, 'count' => 0];
    }

    public function rename(int $userId, object $course, int $id, string $name): array
    {
        $this->own($userId, $course, $id);
        $name = $this->cleanName($name);
        $this->assertUnique($userId, (int) $course->id, $name, $id);
        $this->db->update($this->t('favorite_collection'), ['name' => $name], ['id' => $id]);

        return ['id' => $id, 'name' => $name];
    }

    public function delete(int $userId, object $course, int $id): void
    {
        $this->own($userId, $course, $id);
        $this->db->delete($this->t('favorite_collection_item'), ['collection_id' => $id]);
        $this->db->delete($this->t('favorite_collection'), ['id' => $id]);
    }

    /**
     * Merkliste und Sammlungen einer Frage setzen.
     * @param list<int> $collectionIds
     */
    public function assign(int $userId, object $course, int $questionId, bool $favorite, array $collectionIds): array
    {
        if (! in_array($questionId, $this->allowedIds($userId, $course), true)) {
            throw new RuntimeException(__('Diese Frage gehört nicht zum freigeschalteten Umfang des Kurses.', 'bootspruefung-learning'));
        }
        $own = array_map(static fn ($c) => $c['id'], $this->collectionRows($userId, $course));
        $collectionIds = array_values(array_unique(array_map('intval', $collectionIds)));
        if (array_diff($collectionIds, $own) !== []) {
            throw new RuntimeException(__('Unbekannte Sammlung.', 'bootspruefung-learning'));
        }
        // Einsortieren nur für Favoriten; wer eine Frage aus den Favoriten nimmt, entfernt sie aus allen Sammlungen.
        if (! $favorite && $collectionIds !== []) {
            throw new RuntimeException(__('Nur Favoriten lassen sich in Sammlungen einsortieren.', 'bootspruefung-learning'));
        }
        // Merkliste und Zuordnungen gemeinsam ändern (alles oder nichts).
        $this->db->query('START TRANSACTION');
        try {
            $this->learning->setFavorite($userId, $questionId, $favorite);
            if ($own !== []) {
                $this->db->query($this->db->prepare(
                    "DELETE FROM {$this->t('favorite_collection_item')} WHERE question_id = %d AND collection_id IN (".implode(',', array_map('intval', $own)).')',
                    $questionId
                ));
            }
            $now = current_time('mysql', true);
            foreach ($collectionIds as $cid) {
                if (! $this->db->insert($this->t('favorite_collection_item'), ['collection_id' => $cid, 'question_id' => $questionId, 'created_at' => $now])) {
                    throw new RuntimeException(__('Die Sammlungen konnten nicht gespeichert werden.', 'bootspruefung-learning'));
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        return ['question_id' => $questionId, 'favorite' => $favorite, 'collections' => $collectionIds];
    }

    /** Fragen einer Sammlung (nur eigene), für den Lernmodus. @return list<int> */
    public function questionIds(int $userId, object $course, int $id): array
    {
        $this->own($userId, $course, $id);
        // Nur Favoriten (wie in der Übersicht): Altbestand ohne Stern wird nicht gelernt.
        $favorites = array_flip($this->learning->favoriteIds($userId));

        return array_values(array_filter($this->itemsByCollection([$id])[$id] ?? [], static fn ($q) => isset($favorites[$q])));
    }

    /**
     * Übersicht: gemerkte, einsortierte und zuletzt falsch beantwortete Fragen des Kurses.
     * @return array{collections:list<array>,items:list<array>}
     */
    public function overview(int $userId, object $course): array
    {
        $rows = $this->learning->allowedQuestions($userId, $course, array_map(static fn ($m) => (int) $m->id, $this->content->modules((int) $course->id)));
        $collections = $this->collections($userId, $course);
        $items = $this->itemsByCollection(array_map(static fn ($c) => $c['id'], $collections));
        $byQuestion = [];
        foreach ($items as $cid => $qids) {
            foreach ($qids as $qid) {
                $byQuestion[$qid][] = $cid;
            }
        }
        $favorites = array_flip($this->learning->favoriteIds($userId));
        // Nur Favoriten zählen als einsortiert (Altbestand ohne Stern bleibt unsichtbar).
        foreach (array_keys($byQuestion) as $qid) {
            if (! isset($favorites[$qid])) {
                unset($byQuestion[$qid]);
            }
        }
        $wrong = array_flip($this->learning->wrongIds($userId, array_keys($rows)));
        $ids = array_values(array_filter(array_keys($rows), static fn ($id) => isset($favorites[$id]) || isset($byQuestion[$id]) || isset($wrong[$id])));
        $modules = [];
        foreach ($this->content->modules((int) $course->id) as $m) {
            $modules[(int) $m->id] = (string) $m->name;
        }
        $texts = [];
        if ($ids !== []) {
            foreach ($this->db->get_results(
                "SELECT q.id, q.official_number, r.question_text FROM {$this->t('question')} q JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id WHERE q.id IN (".implode(',', array_map('intval', $ids)).')'
            ) as $r) {
                $texts[(int) $r->id] = $r;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $row = $rows[$id];
            $text = $texts[$id] ?? null;
            $out[] = [
                'question_id' => $id,
                'number' => $text && $text->official_number !== null ? (string) $text->official_number : null,
                'text' => $text ? trim(wp_strip_all_tags((string) $text->question_text)) : '',
                'module' => $modules[(int) $row->module_id] ?? '',
                'topic' => (string) ($row->topic ?? ''),
                'favorite' => isset($favorites[$id]),
                'wrong' => isset($wrong[$id]),
                'collections' => $byQuestion[$id] ?? [],
            ];
        }

        return ['collections' => $collections, 'items' => $out];
    }

    /** @return list<int> */
    private function allowedIds(int $userId, object $course): array
    {
        return array_keys($this->learning->allowedQuestions($userId, $course, array_map(static fn ($m) => (int) $m->id, $this->content->modules((int) $course->id))));
    }

    /** @return list<array{id:int}> */
    private function collectionRows(int $userId, object $course): array
    {
        return array_map(static fn ($id) => ['id' => (int) $id], $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t('favorite_collection')} WHERE user_id = %d AND course_id = %d",
            $userId,
            $course->id
        )));
    }

    /** @param list<int> $ids @return array<int,list<int>> */
    private function itemsByCollection(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->get_results("SELECT collection_id, question_id FROM {$this->t('favorite_collection_item')} WHERE collection_id IN (".implode(',', array_map('intval', $ids)).') ORDER BY created_at, question_id') as $r) {
            $out[(int) $r->collection_id][] = (int) $r->question_id;
        }

        return $out;
    }

    private function own(int $userId, object $course, int $id): void
    {
        $owner = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t('favorite_collection')} WHERE id = %d AND user_id = %d AND course_id = %d", $id, $userId, $course->id));
        if ($owner === null) {
            throw new RuntimeException(__('Unbekannte Sammlung.', 'bootspruefung-learning'));
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($name)) ?? '');
        if ($name === '') {
            throw new RuntimeException(__('Bitte gib einen Namen für die Sammlung ein.', 'bootspruefung-learning'));
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            throw new RuntimeException(sprintf(__('Der Name darf höchstens %d Zeichen lang sein.', 'bootspruefung-learning'), self::MAX_NAME));
        }

        return $name;
    }

    private function assertUnique(int $userId, int $courseId, string $name, ?int $except): void
    {
        $exists = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t('favorite_collection')} WHERE user_id = %d AND course_id = %d AND LOWER(name) = LOWER(%s) AND id <> %d",
            $userId,
            $courseId,
            $name,
            $except ?? 0
        ));
        if ($exists !== null) {
            throw new RuntimeException(__('Eine Sammlung mit diesem Namen gibt es schon.', 'bootspruefung-learning'));
        }
    }
}
