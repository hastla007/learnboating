<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

use Bootspruefung\Learning\Content\Import\EntityImporter;
use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Baut die Client-Darstellung einer Frage ohne Lösung (Kap. 6.1, 10) und
 * signiert die ausgelieferte Fassung (Revision, Antwortreihenfolge, Nutzer)
 * als Token. Die Antwortreihenfolge variiert je Abruf, bleibt aber für dieses
 * Token stabil; ausgewertet wird immer gegen die signierte Revision.
 */
final class QuestionPresenter
{
    /**
     * Gültigkeit eines Frage-Tokens. Jede neue Frage (Lernmodus) bzw. jeder Aufruf einer
     * Videolektion holt ein frisches Token; 2 Stunden reichen für Bedenkzeit und Videos,
     * begrenzen aber, wie lange ein einmal ausgeliefertes Token nach Zugangsende nutzbar wäre.
     */
    public const TTL = 2 * HOUR_IN_SECONDS;

    /** @var array<int,?object> */
    private array $revisions = [];
    /** @var array<int,list<object>> */
    private array $answerRows = [];
    /** @var array<int,list<array{url:string,alt:string,caption:?string}>> */
    private array $mediaRows = [];

    public function __construct(private readonly ContentRepository $content)
    {
    }

    /**
     * Lädt Revisionen, Antworten und Bilder mehrerer Fragen mit je einer Abfrage vor
     * (Prüfungsauswertung, Videoquiz) – statt drei Abfragen je Frage. Gleiche Regeln wie
     * ContentRepository::revision()/answers()/media(). Jeder Aufruf ersetzt den Vorrat
     * (gilt nur für den laufenden Vorgang, keine veralteten Rechte in langen Prozessen).
     * @param list<int> $revisionIds
     */
    public function preload(array $revisionIds): void
    {
        $this->revisions = [];
        $this->answerRows = [];
        $this->mediaRows = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $revisionIds))));
        if ($ids === []) {
            return;
        }
        global $wpdb;
        $t = static fn (string $name): string => $wpdb->prefix.'bp_'.$name;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            foreach ($chunk as $id) {
                $this->revisions[$id] = null;
                $this->answerRows[$id] = [];
                $this->mediaRows[$id] = [];
            }
            foreach ($wpdb->get_results("SELECT r.*, q.source_id, q.official_number, q.content_role FROM {$t('question_revision')} r JOIN {$t('question')} q ON q.id = r.question_id WHERE r.id IN ({$in})") as $row) {
                $this->revisions[(int) $row->id] = $row;
            }
            foreach ($wpdb->get_results("SELECT revision_id, id, answer_key, answer_text, is_correct, sort_order FROM {$t('answer')} WHERE revision_id IN ({$in}) ORDER BY revision_id, sort_order, id") as $row) {
                $this->answerRows[(int) $row->revision_id][] = $row;
            }
            $media = $wpdb->get_results($wpdb->prepare(
                "SELECT cm.object_id AS revision_id, m.storage_key, COALESCE(cm.caption_override, m.caption) AS caption, COALESCE(cm.alt_override, m.alt_text) AS alt, COALESCE(o.value, m.rights_status) AS rights
                 FROM {$t('content_media')} cm JOIN {$t('media')} m ON m.id = cm.media_id ".Overrides::rightsJoin('media', 'm', 'o')."
                 WHERE cm.object_type = 'question_revision' AND cm.object_id IN ({$in}) AND m.status <> %s ORDER BY cm.object_id, cm.sort_order",
                EntityImporter::ARCHIVED
            ));
            foreach ($media as $row) {
                if (in_array($row->rights, Overrides::BLOCKING_RIGHTS, true) || ! $row->storage_key) {
                    continue;
                }
                $this->mediaRows[(int) $row->revision_id][] = ['url' => MediaStorage::url($row->storage_key), 'alt' => (string) ($row->alt ?: __('Abbildung zur Frage', 'bootspruefung-learning')), 'caption' => $row->caption];
            }
        }
    }

    /** Revision (vorgeladen oder einzeln). */
    public function revision(int $revisionId): ?object
    {
        return array_key_exists($revisionId, $this->revisions) ? $this->revisions[$revisionId] : $this->content->revision($revisionId);
    }

    /** @return list<object> Antworten einer Revision (vorgeladen oder einzeln) */
    public function answers(int $revisionId): array
    {
        return $this->answerRows[$revisionId] ?? $this->content->answers($revisionId);
    }

    /** @return list<array{url:string,alt:string,caption:?string}> */
    private function media(int $revisionId): array
    {
        return $this->mediaRows[$revisionId] ?? $this->content->media($revisionId);
    }

    /**
     * @param list<int>|null $answerOrder feste Reihenfolge (Prüfungs-Snapshot) oder null = neu mischen
     * @return array<string,mixed>
     */
    public function present(int $revisionId, ?array $answerOrder = null): array
    {
        $revision = $this->revision($revisionId);
        if ($revision === null) {
            throw new NotFound('Frage nicht gefunden.');
        }
        $answers = $this->answers($revisionId);
        $byId = [];
        foreach ($answers as $answer) {
            $byId[(int) $answer->id] = $answer;
        }
        if ($revision->question_type === 'open_text') {
            $order = [];
        } elseif ($answerOrder !== null) {
            $order = array_values(array_filter($answerOrder, static fn ($id) => isset($byId[$id])));
        } else {
            $order = array_keys($byId);
            self::shuffle($order);
        }
        $letters = range('A', 'Z');

        return [
            'question_id' => (int) $revision->question_id,
            'revision_id' => $revisionId,
            'source_id' => $revision->source_id,
            'official_number' => $revision->official_number,
            'type' => $revision->question_type,
            'text' => $revision->question_text,
            'topic' => $revision->smart_category ?: $revision->topic,
            'media' => $this->media($revisionId),
            'answers' => array_map(static fn (int $id, int $i) => ['id' => $id, 'label' => $letters[$i] ?? (string) ($i + 1), 'text' => $byId[$id]->answer_text], $order, array_keys($order)),
            'answer_order' => $order,
        ];
    }

    /** @param list<int> $order */
    public function token(int $userId, int $revisionId, array $order, string $context): string
    {
        $payload = rtrim(strtr(base64_encode((string) wp_json_encode(['u' => $userId, 'r' => $revisionId, 'o' => $order, 'c' => $context, 't' => time(), 'n' => bin2hex(random_bytes(6))])), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    /** @return array{u:int,r:int,o:list<int>,c:string,t:int} */
    public function verify(string $token, int $userId): array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || ! hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $signature)) {
            throw new RuntimeException('Ungültiges Frage-Token.');
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($data) || (int) ($data['u'] ?? 0) !== $userId) {
            throw new RuntimeException('Frage-Token gehört zu einem anderen Konto.');
        }
        if (time() - (int) $data['t'] > self::TTL) {
            throw new RuntimeException('Frage-Token ist abgelaufen. Bitte die Frage neu laden.');
        }

        return $data;
    }

    /**
     * Bewertet eine Antwort gegen eine Revision.
     * @param list<int> $selectedIds
     * @return array{correct:bool,correct_ids:list<int>}
     */
    public function evaluate(int $revisionId, string $type, array $selectedIds, ?bool $known): array
    {
        $correctIds = [];
        foreach ($this->answers($revisionId) as $answer) {
            if ((int) $answer->is_correct === 1) {
                $correctIds[] = (int) $answer->id;
            }
        }
        sort($correctIds);
        $selected = array_values(array_unique(array_map('intval', $selectedIds)));
        sort($selected);
        $correct = match ($type) {
            'open_text' => $known === true,
            'multi_choice' => $selected !== [] && $selected === $correctIds,
            default => count($selected) === 1 && in_array($selected[0], $correctIds, true),
        };

        return ['correct' => $correct, 'correct_ids' => $correctIds];
    }

    public function modelAnswer(int $revisionId): ?string
    {
        foreach ($this->answers($revisionId) as $answer) {
            if ((int) $answer->is_correct === 1) {
                return $answer->answer_text;
            }
        }

        return null;
    }

    /** @param list<int> $items */
    public static function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }
}
