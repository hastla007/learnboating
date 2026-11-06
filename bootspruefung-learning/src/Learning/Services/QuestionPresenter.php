<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Learning\Services;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
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
    private const TTL = DAY_IN_SECONDS;

    public function __construct(private readonly ContentRepository $content)
    {
    }

    /**
     * @param list<int>|null $answerOrder feste Reihenfolge (Prüfungs-Snapshot) oder null = neu mischen
     * @return array<string,mixed>
     */
    public function present(int $revisionId, ?array $answerOrder = null): array
    {
        $revision = $this->content->revision($revisionId);
        if ($revision === null) {
            throw new NotFound('Frage nicht gefunden.');
        }
        $answers = $this->content->answers($revisionId);
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
            'media' => $this->content->media($revisionId),
            'answers' => array_map(static fn (int $id, int $i) => ['id' => $id, 'label' => $letters[$i] ?? (string) ($i + 1), 'text' => $byId[$id]->answer_text], $order, array_keys($order)),
            'answer_order' => $order,
        ];
    }

    /** @param list<int> $order */
    public function token(int $userId, int $revisionId, array $order, string $context): string
    {
        $payload = rtrim(strtr(base64_encode((string) wp_json_encode(['u' => $userId, 'r' => $revisionId, 'o' => $order, 'c' => $context, 't' => time()])), '+/', '-_'), '=');

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
        foreach ($this->content->answers($revisionId) as $answer) {
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
        foreach ($this->content->answers($revisionId) as $answer) {
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
