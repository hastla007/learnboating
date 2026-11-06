<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Media;

use RuntimeException;

/**
 * Ablage unter wp-content/uploads/bootspruefung/:
 *   packages/<batch>/  hochgeladene Pakete (per .htaccess gesperrt)
 *   media/<key>        ausgelieferte Medien
 */
final class MediaStorage
{
    public static function baseDir(): string
    {
        return trailingslashit(wp_upload_dir(null, false)['basedir']).'bootspruefung';
    }

    public static function baseUrl(): string
    {
        return trailingslashit(wp_upload_dir(null, false)['baseurl']).'bootspruefung';
    }

    public static function packagesDir(): string
    {
        $dir = self::baseDir().'/packages';
        self::ensureDir($dir, true);

        return $dir;
    }

    /**
     * Geschützte Dateien (PDFs etc. mit Lernzugang). Per .htaccess gesperrt; der
     * Ordnername enthält ein Zufallssuffix, damit er auch unter nginx ohne
     * eigene Regel nicht erratbar ist. Auslieferung nur über /bootsscheine/datei/<id>/.
     */
    public static function protectedDir(): string
    {
        $suffix = get_option('bp_learning_protected_suffix');
        if (! is_string($suffix) || $suffix === '') {
            $suffix = wp_generate_password(20, false, false);
            update_option('bp_learning_protected_suffix', $suffix, false);
        }
        $dir = self::baseDir().'/protected-'.$suffix;
        self::ensureDir($dir, true);

        return $dir;
    }

    public static function mediaDir(): string
    {
        $dir = self::baseDir().'/media';
        self::ensureDir($dir, false);

        return $dir;
    }

    public static function url(string $storageKey): string
    {
        return self::baseUrl().'/media/'.implode('/', array_map('rawurlencode', explode('/', $storageKey)));
    }

    /** Kopiert eine Paketdatei in die Medienablage; Rückgabe: Storage-Key. */
    public static function store(string $packageFile, string $packagePath, string $sha256): string
    {
        $key = preg_replace('#^media/#', '', $packagePath);
        if ($key === null || $key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new RuntimeException("Ungültiger Medienpfad: {$packagePath}");
        }
        $target = self::mediaDir().'/'.$key;
        if (is_file($target) && hash_file('sha256', $target) === $sha256) {
            return $key;
        }
        self::ensureDir(dirname($target), false);
        if (! copy($packageFile, $target)) {
            throw new RuntimeException("Medium kann nicht gespeichert werden: {$key}");
        }

        return $key;
    }

    public static function exists(string $storageKey): bool
    {
        return is_file(self::mediaDir().'/'.$storageKey);
    }

    private static function ensureDir(string $dir, bool $private): void
    {
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            throw new RuntimeException("Verzeichnis kann nicht angelegt werden: {$dir}");
        }
        if (! is_file($dir.'/index.php')) {
            file_put_contents($dir.'/index.php', "<?php\n// Silence is golden.\n");
        }
        if ($private && ! is_file($dir.'/.htaccess')) {
            file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
        }
    }
}
