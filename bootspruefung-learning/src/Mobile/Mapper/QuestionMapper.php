<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Mapper;

/**
 * Fragen aus QuestionPresenter/LearningService/ExamService ins App-Format (Question).
 * IDs sind Strings (Vertrag); Antworten tragen Buchstaben (A, B, …) wie im Web.
 */
final class QuestionMapper
{
    public const SELECTION = ['single_choice' => 'single', 'multi_choice' => 'multiple', 'open_text' => 'open'];

    /** Lernstatus wie im Web (new/unsure/learning/secure/mastered) → Vertrag (neu/in_bearbeitung/gefestigt). */
    public static function learningState(?string $status): string
    {
        return match ($status) {
            'mastered' => 'gefestigt',
            'unsure', 'learning', 'secure' => 'in_bearbeitung',
            default => 'neu',
        };
    }

    /** learning_status im alten Wertebereich (bis 1.10.0): „sicher heute“ (secure) zählt als learning. */
    public static function legacyStatus(?string $status): string
    {
        return match ($status) {
            'secure' => 'learning',
            'unsure', 'learning', 'mastered' => $status,
            default => 'new',
        };
    }

    /**
     * @param array<string,mixed> $q presented (question_id, revision_id, type, text, topic, media, answers, official_number)
     * @return array<string,mixed>
     */
    public static function question(array $q, ?string $id = null): array
    {
        $type = (string) ($q['type'] ?? 'single_choice');

        return [
            'id' => $id ?? (string) $q['question_id'],
            'revision_id' => isset($q['revision_id']) ? (string) $q['revision_id'] : null,
            'official_number' => isset($q['official_number']) && $q['official_number'] !== null && $q['official_number'] !== '' ? (string) $q['official_number'] : null,
            'text' => (string) ($q['text'] ?? ''),
            'prompt_media' => array_map([MediaMapper::class, 'image'], (array) ($q['media'] ?? [])),
            'answers' => array_map(static fn ($a) => ['id' => (string) $a['id'], 'label' => (string) $a['label'], 'text' => (string) $a['text'], 'media' => []], (array) ($q['answers'] ?? [])),
            'answer_selection' => self::SELECTION[$type] ?? 'single',
            'type' => $type,
            'topic' => isset($q['topic']) && $q['topic'] !== '' ? (string) $q['topic'] : null,
            'module_id' => isset($q['module_id']) ? (string) $q['module_id'] : null,
        ];
    }

    /**
     * Seit 1.11.0 zusätzlich stage (mit „secure“ = sicher heute) und due_at (nächste Wiederholung);
     * learning_status/learning_state behalten ihren Wertebereich.
     * @param array{status:string,streak:int,attempts:int,due_at?:?string}|null $p
     * @return array<string,mixed>
     */
    public static function progress(?array $p): array
    {
        $status = $p['status'] ?? 'new';

        return [
            'learning_status' => self::legacyStatus($status),
            'learning_state' => self::learningState($status),
            'stage' => in_array($status, ['new', 'unsure', 'learning', 'secure', 'mastered'], true) ? $status : 'new',
            'due_at' => isset($p['due_at']) && $p['due_at'] !== null ? CourseMapper::iso($p['due_at']) : null,
            'streak' => (int) ($p['streak'] ?? 0),
            'streak_required' => 3,
            'attempts' => (int) ($p['attempts'] ?? 0),
        ];
    }

    /**
     * Antwort aus LearningService::answer() (Lernmodus/Videoquiz).
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function answerResult(array $r, int $attemptId, int $questionId, int $revisionId): array
    {
        $progress = self::progress($r['progress'] ?? null);

        return [
            'attempt_id' => (string) $attemptId,
            'question_id' => (string) $questionId,
            'revision_id' => (string) $revisionId,
            'correct' => (bool) $r['correct'],
            'selected_answer_ids' => array_map('strval', (array) ($r['selected_ids'] ?? [])),
            'correct_answer_ids' => array_map('strval', (array) ($r['correct_ids'] ?? [])),
            'model_answer' => $r['model_answer'] ?? null,
            'explanation' => $r['explanation'] ?? null,
            'explanation_locked' => (bool) ($r['explanation_locked'] ?? false),
            'learning_status' => $progress['learning_status'],
            'learning_state' => $progress['learning_state'],
            'completed' => $progress['learning_status'] === 'mastered',
            'streak_counted' => $r['streak_counted'] ?? null,
            'repeated' => (bool) ($r['repeated'] ?? false),
            'progress' => $progress,
        ];
    }

    /** @param list<int|string>|mixed $ids @return list<int> */
    public static function ids(mixed $ids): array
    {
        $out = [];
        foreach ((array) $ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }
}
