<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Import;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Package\Package;
use RuntimeException;

/**
 * Wege, ein Inhaltspaket ohne Hochladen im Browser zu importieren (To-do T96): Das Paket (ZIP oder entpackter
 * Ordner) liegt per SFTP/Dateimanager des Hosters im Ablageordner oder wird über einen https-Link geladen. Dazu die
 * Grenze für Uploads im Browser (PHP und WordPress).
 *
 * Ablageordner (T108): Unterordner import/ im privaten Paketordner (packages-<geheim>/import/, mit
 * BOOTSPRUEFUNG_PRIVATE_DIR außerhalb des Webroots). Damit gilt derselbe Schutz wie für die Pakete: Ordnername mit
 * Zufallsgeheimnis, .htaccess, nginx-Regel und Selbsttest. Der frühere feste Ordner uploads/bootspruefung-import/
 * war unter nginx öffentlich abrufbar; sein Inhalt wird einmalig in den neuen Ordner verschoben (migrateLegacy).
 * Nach erfolgreicher Übernahme löscht die Importseite die abgelegte ZIP-Datei bzw. den Paketordner.
 */
final class ImportSources
{
    /** Name des Ablageordners im privaten Paketordner. */
    public const DROP_FOLDER = 'import';

    /** Früherer Ablageordner direkt im Upload-Ordner (bis 0.14.0; wird verschoben). */
    public const LEGACY_DROP_FOLDER = 'bootspruefung-import';

    /** Option: Ergebnis des Umzugs aus dem früheren Ablageordner ['at', 'moved' => Namen, 'left' => Namen]. */
    public const MOVED_OPTION = 'bp_learning_import_drop_moved';

    /** So lange erwähnt die Importseite den erfolgreichen Umzug. */
    private const MOVED_NOTICE_DAYS = 30;

    /** Höchstgröße eines Downloads per Link (wie ZIP-Upload). */
    public const MAX_DOWNLOAD_BYTES = Package::MAX_ZIP_BYTES;

    /** Höchstens so viele Weiterleitungen beim Download per Link (jede nur auf https). */
    public const MAX_REDIRECTS = 3;

    private static bool $migrated = false;

    /** Ablageordner (angelegt und gegen Direktabruf gesperrt); verschiebt vorher einmalig den früheren Ordner. */
    public static function dropDir(): string
    {
        self::migrateLegacy();

        return self::ensureDropDir();
    }

    private static function ensureDropDir(): string
    {
        try {
            $dir = MediaStorage::packagesDir().'/'.self::DROP_FOLDER;
            MediaStorage::protectDir($dir);
        } catch (RuntimeException) {
            // Ordner nicht anlegbar: die Liste bleibt leer, die Seite nennt den Pfad trotzdem.
            $dir = MediaStorage::targetDir('packages').'/'.self::DROP_FOLDER;
        }

        return $dir;
    }

    /** Früherer Ablageordner uploads/bootspruefung-import (nur noch für Umzug, Hinweis und Deinstallation). */
    public static function legacyDropDir(): string
    {
        return rtrim((string) wp_upload_dir(null, false)['basedir'], '/').'/'.self::LEGACY_DROP_FOLDER;
    }

    /** Pfad des Ablageordners relativ zum WordPress-Verzeichnis (Anzeige für SFTP), sonst absolut. */
    public static function dropDirLabel(): string
    {
        return self::pathLabel(self::dropDir());
    }

    private static function pathLabel(string $dir): string
    {
        return str_starts_with($dir, ABSPATH) ? substr($dir, strlen(ABSPATH)).'/' : $dir.'/';
    }

    /**
     * Einmaliger Umzug (T108): Liegt im früheren Ablageordner uploads/bootspruefung-import/ etwas, wird es in den
     * neuen Ablageordner verschoben; die eigenen Schutzdateien (index.php, .htaccess) und Systemdateien
     * (.DS_Store, Thumbs.db, __MACOSX) werden gelöscht und der leere Ordner entfernt. Einträge, die es im neuen
     * Ordner schon gibt oder die sich nicht verschieben lassen, bleiben liegen (die Importseite nennt sie).
     * Aufruf: Importseite, stündlicher Cron und „Erneut prüfen“ im Systemstatus (MediaStorage::relocateAll).
     * @return array{moved:list<string>,left:list<string>}|null null, wenn es den früheren Ordner nicht gibt
     */
    public static function migrateLegacy(bool $force = false): ?array
    {
        if (self::$migrated && ! $force) {
            return null;
        }
        self::$migrated = true;
        $legacy = self::legacyDropDir();
        if (! is_dir($legacy) || is_link($legacy)) {
            return null;
        }
        $target = self::ensureDropDir();
        $moved = [];
        $left = [];
        foreach ((array) scandir($legacy) as $name) {
            $name = (string) $name;
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $legacy.'/'.$name;
            if (in_array($name, ['index.php', '.htaccess'], true) && is_file($path) && filesize($path) < 1024) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- eigene Schutzdateien
                continue;
            }
            if (Package::isJunk($name)) {
                MediaStorage::removePath($path);
                continue;
            }
            if (! is_dir($target) || file_exists($target.'/'.$name) || is_link($path) || ! MediaStorage::movePath($path, $target.'/'.$name)) {
                $left[] = $name;
                continue;
            }
            $moved[] = $name;
        }
        if ($left === []) {
            @rmdir($legacy); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        } else {
            try {
                MediaStorage::protectDir($legacy); // bleibt vorerst: für Apache wieder sperren
            } catch (RuntimeException) {
                // nicht beschreibbar: der Hinweis auf der Importseite bleibt
            }
        }
        if ($moved !== [] || $left !== []) {
            update_option(self::MOVED_OPTION, ['at' => time(), 'moved' => $moved, 'left' => $left], false);
            if ($moved !== []) {
                error_log(sprintf('[bootspruefung-learning] Ablageordner umgezogen: %d Einträge von %s nach %s verschoben.', count($moved), $legacy, $target)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            }
        }

        return ['moved' => $moved, 'left' => $left];
    }

    /**
     * Hinweis zum Umzug des Ablageordners für die Importseite: Warnung, solange im früheren Ordner noch etwas liegt;
     * nach erfolgreichem Umzug 30 Tage lang eine Info mit dem neuen Pfad. Sonst null.
     * @return array{type:string,text:string}|null
     */
    public static function migrationNotice(): ?array
    {
        $legacy = self::legacyDropDir();
        if (is_dir($legacy)) {
            $rest = array_values(array_filter(array_map('strval', (array) scandir($legacy)), static fn (string $n): bool => ! in_array($n, ['.', '..', 'index.php', '.htaccess'], true)));
            if ($rest !== []) {
                return ['type' => 'warning', 'text' => sprintf(
                    /* translators: 1: früherer Ordner, 2: Liste, 3: neuer Ablageordner */
                    __('Im früheren Ablageordner %1$s liegt noch: %2$s. Das ließ sich nicht verschieben (gleicher Name schon im neuen Ordner oder fehlende Schreibrechte). Bitte per SFTP in den neuen Ablageordner %3$s verschieben oder löschen – der frühere Ordner ist auf nginx-Servern womöglich öffentlich abrufbar.', 'bootspruefung-learning'),
                    self::pathLabel($legacy),
                    implode(', ', array_slice($rest, 0, 10)).(count($rest) > 10 ? ' …' : ''),
                    self::dropDirLabel()
                )];
            }
        }
        $moved = get_option(self::MOVED_OPTION);
        if (is_array($moved) && ! empty($moved['moved']) && time() - (int) ($moved['at'] ?? 0) < self::MOVED_NOTICE_DAYS * DAY_IN_SECONDS) {
            $count = count((array) $moved['moved']);

            return ['type' => 'info', 'text' => sprintf(
                /* translators: 1: Anzahl, 2: früherer Ordner, 3: neuer Ablageordner */
                _n('Der Ablageordner ist umgezogen: %1$d Eintrag wurde aus %2$s in den geschützten Ordner %3$s verschoben. Neue Pakete bitte nur noch dort ablegen.', 'Der Ablageordner ist umgezogen: %1$d Einträge wurden aus %2$s in den geschützten Ordner %3$s verschoben. Neue Pakete bitte nur noch dort ablegen.', $count, 'bootspruefung-learning'),
                $count,
                self::pathLabel($legacy),
                self::dropDirLabel()
            )];
        }

        return null;
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
     * muss direkt im Ablageordner liegen – sonst RuntimeException. Der Name kommt unverändert aus dem Formular:
     * Leerzeichen, Klammern und mehrere Punkte („Paket (1).zip“, „inhalt.final.zip“) sind erlaubt (T108).
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
     * Abgelegtes Paket (Ergebnis von resolveDrop) nach der Übernahme löschen – ZIP-Datei oder Paketordner. Nur
     * Einträge direkt im Ablageordner. true, wenn es danach nicht mehr da ist.
     */
    public static function removeDropped(string $path): bool
    {
        $base = realpath(self::ensureDropDir());
        if ($base === false || dirname($path) !== $base || is_link($path)) {
            return false;
        }
        MediaStorage::removePath($path);
        clearstatcache(true, $path);

        return ! file_exists($path);
    }

    /**
     * Lädt ein Paket per https-Link in eine temporäre Datei im geschützten Paketordner (wp_safe_remote_get:
     * keine internen Adressen, Größenlimit). Weiterleitungen folgt die Methode selbst (T125): höchstens drei, jede
     * nur auf eine https-Adresse, die wp_http_validate_url besteht. Mit $sha256 (64 Hexzeichen) muss die Prüfsumme
     * der geladenen Datei übereinstimmen. Der Aufrufer löscht die Datei.
     */
    public static function download(string $url, int $maxBytes = self::MAX_DOWNLOAD_BYTES, string $sha256 = ''): string
    {
        $url = trim($url);
        $sha256 = strtolower((string) preg_replace('/\s+/', '', $sha256));
        if ($sha256 !== '' && ! preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new RuntimeException(__('Die SHA-256-Prüfsumme muss aus 64 Zeichen (0–9, a–f) bestehen.', 'bootspruefung-learning'));
        }
        if (! self::httpsUrl($url)) {
            throw new RuntimeException(__('Bitte einen vollständigen https-Link angeben (http und interne Adressen sind nicht erlaubt).', 'bootspruefung-learning'));
        }
        $target = MediaStorage::packagesDir().'/download-'.wp_generate_password(12, false).'.zip';
        $fail = static function (string $message) use ($target): never {
            if (is_file($target)) {
                @unlink($target); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            throw new RuntimeException($message);
        };
        for ($hop = 0; ; $hop++) {
            $response = wp_safe_remote_get($url, [
                'timeout' => 300,
                'redirection' => 0, // Weiterleitungen selbst prüfen (nur https)
                'stream' => true,
                'filename' => $target,
                'limit_response_size' => $maxBytes + 1,
                'headers' => ['Accept' => 'application/zip, application/octet-stream'],
            ]);
            if (is_wp_error($response)) {
                $fail(sprintf(__('Download fehlgeschlagen: %s', 'bootspruefung-learning'), $response->get_error_message()));
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $location = trim((string) wp_remote_retrieve_header($response, 'location'));
            if (! in_array($code, [301, 302, 303, 307, 308], true) || $location === '') {
                break;
            }
            if ($hop >= self::MAX_REDIRECTS) {
                $fail(sprintf(__('Download abgebrochen: mehr als %d Weiterleitungen.', 'bootspruefung-learning'), self::MAX_REDIRECTS));
            }
            $next = \WP_Http::make_absolute_url($location, $url);
            if (! self::httpsUrl($next)) {
                $fail(__('Download abgebrochen: Der Link leitet auf eine Adresse ohne https oder auf eine interne Adresse weiter.', 'bootspruefung-learning'));
            }
            $url = $next;
        }
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
        if ($sha256 !== '' && ! hash_equals($sha256, (string) hash_file('sha256', $target))) {
            $fail(__('Die SHA-256-Prüfsumme der geladenen Datei stimmt nicht mit der angegebenen überein. Die Datei wurde verworfen.', 'bootspruefung-learning'));
        }

        return $target;
    }

    private static function httpsUrl(string $url): bool
    {
        return strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) === 'https' && wp_http_validate_url($url) !== false;
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
