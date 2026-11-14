<?php

declare(strict_types=1);

namespace ContentBuild;

final class Json
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value, bool $pretty = false): string
    {
        return json_encode($value, self::FLAGS | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    public static function decodeFile(string $path): mixed
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
