<?php

declare(strict_types=1);

namespace ContentBuild;

use Normalizer;

final class Text
{
    /** Trim + Unicode-NFC (aus PDFs kopierte Texte enthalten zerlegte Umlaute). */
    public static function clean(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        return Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
    }

    public static function nullable(mixed $value): ?string
    {
        $text = self::clean($value);

        return $text === '' ? null : $text;
    }

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'wahr', 'ja', 'x'], true);
    }
}
