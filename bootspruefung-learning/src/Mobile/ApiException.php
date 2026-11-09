<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

/**
 * Fachlicher Fehler der App-API mit stabilem Code (Problem.code) und HTTP-Status.
 * $extra landet unverändert im Fehlerobjekt (z. B. errors, details, retry_after).
 */
final class ApiException extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string,list<string>> $errors Feld => Meldungen */
    public static function validation(array $errors, string $message = ''): self
    {
        return new self('VALIDATION_FAILED', $message !== '' ? $message : (string) (reset($errors)[0] ?? __('Bitte prüfe deine Eingaben.', 'bootspruefung-learning')), 422, ['errors' => $errors]);
    }

    public static function notFound(string $message = ''): self
    {
        return new self('RESOURCE_NOT_FOUND', $message !== '' ? $message : __('Nicht gefunden.', 'bootspruefung-learning'), 404);
    }

    public static function rateLimited(int $retryAfter, string $message = ''): self
    {
        return new self('RATE_LIMITED', $message !== '' ? $message : __('Zu viele Anfragen. Bitte warte kurz und versuche es dann erneut.', 'bootspruefung-learning'), 429, ['retry_after' => max(1, $retryAfter)]);
    }
}
