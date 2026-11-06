<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

/** PSR-4-Fallback, falls kein Composer-vendor/ mitgeliefert wird. */
final class Autoloader
{
    private const PREFIX = 'Bootspruefung\\Learning\\';

    public static function register(string $srcDir): void
    {
        spl_autoload_register(static function (string $class) use ($srcDir): void {
            if (! str_starts_with($class, self::PREFIX)) {
                return;
            }
            $file = $srcDir.'/'.str_replace('\\', '/', substr($class, strlen(self::PREFIX))).'.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
