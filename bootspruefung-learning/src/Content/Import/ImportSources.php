<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Import;

use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Package\Package;
use RuntimeException;

/**
 * Wege, ein Inhaltspaket ohne Hochladen im Browser zu importieren (To-do T96): Das Paket (ZIP oder entpackter
 * Ordner) liegt per SFTP/Dateimanager des Hosters im Ablageordner wp-content/uploads/bootspruefung-import/ oder
 * wird über einen https-Link geladen. Dazu die Grenze für Uploads im Browser (PHP und WordPress).
 */
final class ImportSources
{
    public const DROP_FOLDER = 'bootspruefung-import';

    /** Höchstgröße eines Downloads per Link (wie ZIP-Upload). */
    public const MAX_DOWNLOAD_BYTES = Package::MAX_ZIP_BYTES;

    /** Ablageordner (angelegt und gegen Direktabruf gesperrt). */
    public static function dropDir(): string
    {
        $uploads = wp_upload_dir(null, false);
        $dir = rtrim((string) $uploads['basedir'], '/').'/'.self::DROP_FOLDER;
        try {
            MediaStorage::protectDir($dir);
        } catch (RuntimeException) {
            // Ordner nicht anlegbar: die Liste bleibt leer, die Seite nennt den Pfad trotzdem.
        }

        return $dir;
    }

    /** Pfad des Ablageordners relativ zum WordPress-Verzeichnis (Anzeige). */
    public static function dropDirLabel(): string
    {
        $dir = self::dropDir();

        return str_starts_with($dir, ABSPATH) ? substr($dir, strlen(ABSPATH)).'/' : $dir.'/';
    }

    /**
     * Pakete im Ablageordner: ZIP-Dateien und Ordner mit manifest.json (nur oberste Ebene, keine Links).
     * @return list<array{name:string,type:string,size:int,modified:int}>
     */
    public static function dropItems(): array
    {
        $dir = self::dropDir();
        if (! is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach ((array) scandir($dir) as $name) {
            $name = (string) $name;
            if ($name === '' || str_starts_with($name, '.') || $name === 'index.php') {
                continue;
            }
            $path = $dir.'/'.$name;
            if (is_link($path)) {
                continue;
            }
            if (is_file($path) && str_ends_with(strtolower($name), '.zip')) {
                $items[] = ['name' => $name, 'type' => 'zip', 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
            } elseif (is_dir($path) && is_file($path.'/manifest.json')) {
                $items[] = ['name' => $name, 'type' => 'dir', 'size' => 0, 'modified' => (int) filemtime($path.'/manifest.json')];
            }
        }
        usort($items, static fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $items;
    }

    /**
     * Pfad eines Pakets im Ablageordner. Nur ein Name ohne Verzeichnisanteile; der aufgelöste Pfad (realpath)
     * muss direkt im Ablageordner liegen – sonst RuntimeException.
     */
    public static function resolveDrop(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name !== wp_basename($name) || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || str_starts_with($name, '.')) {
            throw new RuntimeException(__('Ungültiger Dateiname.', 'bootspruefung-learning'));
        }
        $base = realpath(self::dropDir());
        $path = $base !== false ? realpath($base.'/'.$name) : false;
        if ($base === false || $path === false || dirname($path) !== $base || is_link($base.'/'.$name)) {
            throw new RuntimeException(__('Datei nicht im Ablageordner gefunden.', 'bootspruefung-learning'));
        }
        if (is_file($path) && str_ends_with(strtolower($path), '.zip')) {
            return $path;
        }
        if (is_dir($path) && is_file($path.'/manifest.json')) {
            return $path;
        }
        throw new RuntimeException(__('Im Ablageordner sind nur ZIP-Dateien oder Paketordner mit manifest.json erlaubt.', 'bootspruefung-learning'));
    }

    /**
     * Lädt ein Paket per https-Link in eine temporäre Datei im geschützten Paketordner (wp_safe_remote_get:
     * keine internen Adressen, höchstens 3 Weiterleitungen, Größenlimit). Der Aufrufer löscht die Datei.
     */
    public static function download(string $url, int $maxBytes = self::MAX_DOWNLOAD_BYTES): string
    {
        $url = trim($url);
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! wp_http_validate_url($url)) {
            throw new RuntimeException(__('Bitte einen vollständigen https-Link angeben (http und interne Adressen sind nicht erlaubt).', 'bootspruefung-learning'));
        }
        $target = MediaStorage::packagesDir().'/download-'.wp_generate_password(12, false).'.zip';
        $response = wp_safe_remote_get($url, [
            'timeout' => 300,
            'redirection' => 3,
            'stream' => true,
            'filename' => $target,
            'limit_response_size' => $maxBytes + 1,
            'headers' => ['Accept' => 'application/zip, application/octet-stream'],
        ]);
        $fail = static function (string $message) use ($target): never {
            if (is_file($target)) {
                @unlink($target); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            throw new RuntimeException($message);
        };
        if (is_wp_error($response)) {
            $fail(sprintf(__('Download fehlgeschlagen: %s', 'bootspruefung-learning'), $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $fail(sprintf(__('Download fehlgeschlagen: Der Server antwortete mit HTTP %d.', 'bootspruefung-learning'), $code));
        }
        clearstatcache(true, $target);
        if (! is_file($target) || filesize($target) === 0) {
            $fail(__('Download fehlgeschlagen: Die Datei ist leer.', 'bootspruefung-learning'));
        }
        if (filesize($target) > $maxBytes) {
            $fail(sprintf(__('Die Datei hinter dem Link ist größer als %s.', 'bootspruefung-learning'), size_format($maxBytes)));
        }

        return $target;
    }

    /**
     * Grenze für ZIP-Uploads im Browser: kleinster Wert aus upload_max_filesize, post_max_size,
     * wp_max_upload_size() und der Paketgrenze von 200 MB. 0-Werte in php.ini bedeuten „ohne Grenze“.
     */
    public static function uploadLimit(): int
    {
        $limits = [Package::MAX_ZIP_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $bytes = (int) wp_convert_hr_to_bytes((string) ini_get($ini));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        $wp = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
        if ($wp > 0) {
            $limits[] = $wp;
        }

        return min($limits);
    }

    /** post_max_size in Bytes (0 = ohne Grenze). */
    public static function postMaxBytes(): int
    {
        return max(0, (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size')));
    }

    /**
     * Hat PHP die Anfrage verworfen, weil sie größer als post_max_size war? Dann sind $_POST und $_FILES leer
     * (auch das Sicherheitsfeld fehlt) – nur CONTENT_LENGTH verrät die Größe.
     */
    public static function postTooLarge(?int $contentLength = null, ?int $postMax = null): bool
    {
        $contentLength ??= (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMax ??= self::postMaxBytes();

        return $postMax > 0 && $contentLength > $postMax;
    }

    /** Verständliche Meldung zu einem PHP-Upload-Fehlercode (UPLOAD_ERR_*), null bei UPLOAD_ERR_OK. */
    public static function uploadErrorMessage(int $code, int $size = 0): ?string
    {
        return match ($code) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::tooLargeMessage($size),
            UPLOAD_ERR_PARTIAL => __('Die Datei kam nur teilweise an (Verbindung abgebrochen). Bitte erneut hochladen oder den Ablageordner nutzen.', 'bootspruefung-learning'),
            UPLOAD_ERR_NO_FILE => __('Bitte eine ZIP-Datei auswählen.', 'bootspruefung-learning'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => __('Der Server konnte die hochgeladene Datei nicht zwischenspeichern (temporärer Ordner fehlt oder ist voll). Bitte den Hoster fragen oder den Ablageordner nutzen.', 'bootspruefung-learning'),
            default => sprintf(__('Hochladen fehlgeschlagen (PHP-Fehler %d). Bitte den Ablageordner nutzen.', 'bootspruefung-learning'), $code),
        };
    }

    /** Meldung „zu groß“ mit Lösungswegen (Ablageordner, Link, WP-CLI, Grenze erhöhen). */
    public static function tooLargeMessage(int $size = 0): string
    {
        $limit = size_format(self::uploadLimit());
        $text = $size > 0
            ? sprintf(__('Die Datei ist zu groß zum Hochladen (ca. %1$s, auf diesem Server höchstens %2$s).', 'bootspruefung-learning'), size_format($size), $limit)
            : sprintf(__('Die Datei ist zu groß zum Hochladen (auf diesem Server höchstens %s).', 'bootspruefung-learning'), $limit);

        return $text.' '.sprintf(
            /* translators: %s: Ablageordner */
            __('So geht es trotzdem: die ZIP-Datei per SFTP oder Dateimanager des Hosters in den Ordner %s legen und unten unter „Ohne Hochladen importieren“ auswählen, per https-Link importieren oder per WP-CLI: wp bootspruefung import <datei>. Alternativ beim Hoster upload_max_filesize und post_max_size erhöhen (z. B. auf 64M).', 'bootspruefung-learning'),
            self::dropDirLabel()
        );
    }
}
