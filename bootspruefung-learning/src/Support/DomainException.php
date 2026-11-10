<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Support;

/**
 * Fachlicher Fehler mit stabilem Code (zusätzlich zur Meldung). Erbt von RuntimeException, damit
 * bestehende Aufrufer (Web-REST, Admin, Frontend) unverändert die Meldung anzeigen; die App-API
 * bildet über errorCode() auf ihre Fehlercodes ab statt über den Meldungstext.
 */
class DomainException extends \RuntimeException
{
    public const EXAM_EXPIRED = 'exam_expired';
    public const EXAM_FINISHED = 'exam_finished';
    public const EXAM_ANSWER_LOCKED = 'exam_answer_locked';
    public const EXAM_NOT_STARTABLE = 'exam_not_startable';
    public const EXAM_START_BUSY = 'exam_start_busy';
    public const ANSWER_RATE_LIMITED = 'answer_rate_limited';
    public const QUESTION_TOKEN_EXPIRED = 'question_token_expired';

    public function __construct(private readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
