<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Media;

use RuntimeException;

/**
 * Ablage unter wp-content/uploads/bootspruefung/:
 *   media/<key>               ausgelieferte Medien (öffentlich; nur erlaubte Dateitypen)
 *   packages-<geheim>/<batch> hochgeladene Content-Pakete (samt Lösungen)
 *   protected-<geheim>/<id>   geschützte Dateien (PDFs mit Lernzugang)
 *
 * Pakete und geschützte Dateien dürfen nie per Direkt-URL abrufbar sein. Schutz in drei Stufen:
 *  1. Ordnername mit Zufallsgeheimnis aus einer Option (nicht erratbar, auch ohne Webserver-Regel);
 *  2. .htaccess „Require all denied“ (Apache);
 *  3. auf Wunsch ganz außerhalb des Webroots: Konstante BOOTSPRUEFUNG_PRIVATE_DIR in wp-config.php
 *     bzw. Filter bootspruefung_private_dir (absoluter, beschreibbarer Pfad).
 * Ausgeliefert wird nur über PHP mit Zugangsprüfung (ProtectedFiles::serve); Pakete gar nicht.
 *
 * Bestehende Installationen (packages/ ohne Geheimnis, Wechsel in bzw. aus dem privaten Ordner)
 * werden beim ersten Zugriff bzw. bei admin_init und im stündlichen Cron verschoben. Scheitert das
 * Verschieben, bleibt der bisherige Ordner in Gebrauch (keine Daten verloren), der Systemstatus
 * zeigt den Fehler und der nächste Versuch folgt nach einer Stunde.
 */
final class MediaStorage
{
    public const PACKAGES_SECRET_OPTION = 'bp_learning_packages_suffix';
    public const PROTECTED_SECRET_OPTION = 'bp_learning_protected_suffix';
    /** Tatsächlich genutzte Ordner und Stand der Umzüge: ['packages' => Pfad, 'protected' => Pfad, 'failed' => [...], 'errors' => [...]] */
    public const LAYOUT_OPTION = 'bp_learning_storage_layout';
    private const RETRY_AFTER = HOUR_IN_SECONDS;

    /**
     * Medien, die ein Content-Paket in den öffentlichen Medienordner schreiben darf: Endung => erlaubte
     * MIME-Typen (angegeben und am Dateiinhalt erkannt). Das Paket aus tools/content-build enthält heute
     * nur PNG; Audio/Video/PDF sind laut Schema (media_type) vorgesehen. Kein SVG/HTML (Skripte auf der
     * eigenen Domain), kein PHP. Erweiterbar über den Filter bootspruefung_media_allowed_types.
     */
    public const ALLOWED_MEDIA = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'mp3' => ['audio/mpeg'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm', 'audio/webm'],
        'pdf' => ['application/pdf'],
    ];

    /** Endungen, die Webserver ausführen bzw. als Seite ausliefern – auch nicht als innere Endung (x.php.png). */
    private const DANGEROUS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'sh', 'shtml', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'js', 'htaccess'];

    private const HTACCESS_PRIVATE = "# Bootsprüfung: kein Direktabruf (Pakete bzw. geschützte Dateien)\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
    private const HTACCESS_LEGACY = "Require all denied\nDeny from all\n";
    private const HTACCESS_MEDIA = "# Bootsprüfung: im Medienordner keine Skripte ausführen bzw. ausliefern\n<FilesMatch \"\\.(?i:php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|asp|aspx|jsp|sh|shtml|html?|xhtml|svgz?|js)$\">\n\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n\t<IfModule !mod_authz_core.c>\n\t\tOrder deny,allow\n\t\tDeny from all\n\t</IfModule>\n</FilesMatch>\n";

    /** @var array<string,string> je Anfrage aufgelöste Ordner */
    private static array $resolved = [];

    public static function baseDir(): string
    {
        return trailingslashit(wp_upload_dir(null, false)['basedir']).'bootspruefung';
    }

    public static function baseUrl(): string
    {
        return trailingslashit(wp_upload_dir(null, false)['baseurl']).'bootspruefung';
    }

    /**
     * Wurzel für Pakete und geschützte Dateien: BOOTSPRUEFUNG_PRIVATE_DIR bzw. Filter
     * bootspruefung_private_dir (außerhalb des Webroots), sonst uploads/bootspruefung.
     * Ist der private Ordner nicht anleg- bzw. beschreibbar, gilt uploads/bootspruefung (Systemstatus warnt).
     */
    public static function privateBaseDir(): string
    {
        $dir = self::configuredPrivateDir();
        if ($dir === null) {
            return self::baseDir();
        }
        if ((is_dir($dir) || wp_mkdir_p($dir)) && is_writable($dir)) {
            return $dir;
        }

        return self::baseDir();
    }

    /** Eingestellter privater Ordner (Konstante/Filter) oder null. */
    public static function configuredPrivateDir(): ?string
    {
        $dir = defined('BOOTSPRUEFUNG_PRIVATE_DIR') ? (string) constant('BOOTSPRUEFUNG_PRIVATE_DIR') : '';
        /** Filter: absoluter Pfad außerhalb des Webroots für Content-Pakete und geschützte Dateien ('' = uploads). */
        $dir = rtrim((string) apply_filters('bootspruefung_private_dir', $dir), '/\\');

        return $dir !== '' && path_is_absolute($dir) ? wp_normalize_path($dir) : null;
    }

    public static function packagesDir(): string
    {
        return self::resolve('packages');
    }

    /**
     * Geschützte Dateien (PDFs etc. mit Lernzugang). Ordnername mit Zufallsgeheimnis, per .htaccess
     * gesperrt, auf Wunsch außerhalb des Webroots. Auslieferung nur über /bootsscheine/datei/<id>/.
     */
    public static function protectedDir(): string
    {
        return self::resolve('protected');
    }

    public static function mediaDir(): string
    {
        $dir = self::baseDir().'/media';
        self::ensureDir($dir, false);
        self::writeHtaccess($dir, self::HTACCESS_MEDIA, false);

        return $dir;
    }

    public static function url(string $storageKey): string
    {
        return self::baseUrl().'/media/'.implode('/', array_map('rawurlencode', explode('/', $storageKey)));
    }

    /**
     * Öffentliche URL einer Datei, falls sie unter dem Upload-Ordner bzw. unter ABSPATH liegt, sonst
     * null (außerhalb des Webroots).
     */
    public static function publicUrl(string $path): ?string
    {
        $path = wp_normalize_path($path);
        $uploads = wp_upload_dir(null, false);
        $base = trailingslashit(wp_normalize_path((string) $uploads['basedir']));
        if (str_starts_with($path, $base)) {
            return trailingslashit((string) $uploads['baseurl']).implode('/', array_map('rawurlencode', explode('/', substr($path, strlen($base)))));
        }
        $root = trailingslashit(wp_normalize_path(ABSPATH));
        if (str_starts_with($path, $root)) {
            return site_url('/'.implode('/', array_map('rawurlencode', explode('/', substr($path, strlen($root))))));
        }

        return null;
    }

    /** Kopiert eine Paketdatei in die Medienablage; Rückgabe: Storage-Key. */
    public static function store(string $packageFile, string $packagePath, string $sha256): string
    {
        $key = preg_replace('#^media/#', '', $packagePath);
        if ($key === null || $key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new RuntimeException("Ungültiger Medienpfad: {$packagePath}");
        }
        $error = self::mediaTypeError($packagePath, null, $packageFile);
        if ($error !== null) {
            throw new RuntimeException("Medium nicht erlaubt: {$packagePath} – {$error}");
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

    /** @return array<string,list<string>> Endung => erlaubte MIME-Typen */
    public static function allowedMedia(): array
    {
        /** Filter: erlaubte Medientypen im Content-Paket (Endung => MIME-Typen). */
        $types = apply_filters('bootspruefung_media_allowed_types', self::ALLOWED_MEDIA);
        $out = [];
        foreach (is_array($types) ? $types : [] as $ext => $mimes) {
            $ext = strtolower((string) $ext);
            if ($ext !== '' && ! in_array($ext, self::DANGEROUS, true)) {
                $out[$ext] = array_values(array_map('strtolower', array_map('strval', (array) $mimes)));
            }
        }

        return $out;
    }

    /**
     * Prüft Dateiendung, angegebenen MIME-Typ und (falls $file vorhanden) den am Inhalt erkannten
     * MIME-Typ eines Mediums. @return string|null Fehlermeldung oder null, wenn erlaubt
     */
    public static function mediaTypeError(string $path, ?string $declaredMime, ?string $file = null): ?string
    {
        $allowed = self::allowedMedia();
        $list = implode(', ', array_keys($allowed));
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return __('Versteckte bzw. leere Pfadteile sind nicht erlaubt.', 'bootspruefung-learning');
            }
        }
        $parts = explode('.', strtolower(wp_basename($path)));
        $ext = count($parts) > 1 ? (string) array_pop($parts) : '';
        array_shift($parts); // Dateiname ohne Endungen
        foreach ($parts as $inner) {
            if (in_array($inner, self::DANGEROUS, true)) {
                return sprintf(__('Doppelte Endung „.%s“ ist nicht erlaubt.', 'bootspruefung-learning'), $inner);
            }
        }
        if ($ext === '' || ! isset($allowed[$ext])) {
            return sprintf(__('Dateityp „.%1$s“ ist nicht erlaubt (erlaubt: %2$s).', 'bootspruefung-learning'), $ext, $list);
        }
        if ($declaredMime !== null && ! in_array(strtolower($declaredMime), $allowed[$ext], true)) {
            return sprintf(__('MIME-Typ „%1$s“ passt nicht zur Endung „.%2$s“.', 'bootspruefung-learning'), $declaredMime, $ext);
        }
        if ($file !== null && is_file($file) && class_exists(\finfo::class)) {
            $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($file));
            if ($detected !== '' && ! in_array($detected, $allowed[$ext], true)) {
                return sprintf(__('Dateiinhalt (%1$s) passt nicht zur Endung „.%2$s“.', 'bootspruefung-learning'), $detected, $ext);
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ Ordner und Umzug

    /** Soll-Pfad eines privaten Ordners ('packages' oder 'protected'). */
    public static function targetDir(string $kind): string
    {
        return self::privateBaseDir().'/'.$kind.'-'.self::secret($kind);
    }

    /** @return array<string,mixed> */
    public static function layout(): array
    {
        $layout = get_option(self::LAYOUT_OPTION, []);

        return is_array($layout) ? $layout : [];
    }

    /** Ordner, der aktuell in Gebrauch ist (ggf. nach Umzug); legt ihn an. */
    private static function resolve(string $kind): string
    {
        $target = self::targetDir($kind);
        if ((self::$resolved[$kind] ?? null) === $target && is_dir($target)) {
            return $target;
        }
        $dir = self::relocate($kind, false);
        self::ensureDir($dir, true);

        return self::$resolved[$kind] = $dir;
    }

    /**
     * Beide privaten Ordner an den Soll-Ort bringen (admin_init, stündlicher Cron, Systemstatus
     * „Erneut prüfen“). $force: auch innerhalb der Wartezeit nach einem Fehlschlag erneut versuchen.
     */
    public static function relocateAll(bool $force = false): void
    {
        foreach (['packages', 'protected'] as $kind) {
            try {
                $dir = self::relocate($kind, $force);
                self::ensureDir($dir, true);
                self::$resolved[$kind] = $dir;
            } catch (\Throwable $e) {
                error_log('[bootspruefung-learning] Ablageordner '.$kind.': '.$e->getMessage());
            }
        }
        if (empty(self::layout()['media_checked'])) {
            try {
                self::quarantineMedia();
                $layout = self::layout();
                $layout['media_checked'] = 1;
                update_option(self::LAYOUT_OPTION, $layout, true);
            } catch (\Throwable $e) {
                error_log('[bootspruefung-learning] Medienordner: '.$e->getMessage());
            }
        }
    }

    /**
     * Steht ein Umzug an (Layout zeigt nicht auf den Soll-Ort und keine Wartezeit nach einem Fehlschlag) oder die
     * einmalige Medienprüfung? Nur Optionen, kein Dateizugriff – günstig genug für admin_init.
     */
    public static function relocationPending(): bool
    {
        $layout = self::layout();
        if (empty($layout['media_checked'])) {
            return true;
        }
        foreach (['packages', 'protected'] as $kind) {
            if (($layout[$kind] ?? null) === self::targetDir($kind)) {
                continue;
            }
            $failedAt = (int) ($layout['failed'][$kind] ?? 0);
            if ($failedAt === 0 || time() - $failedAt >= self::RETRY_AFTER) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin-Aufruf (T105): relocateAll() nur, wenn wirklich etwas ansteht – sonst prüfte jeder Admin-Aufruf (auch
     * Heartbeat) beide Ordner, index.php und .htaccess im Dateisystem. Die laufende Pflege übernimmt der stündliche
     * Cron, „Erneut prüfen“ im Systemstatus erzwingt einen Lauf.
     */
    public static function relocateIfPending(): void
    {
        if (self::relocationPending()) {
            self::relocateAll();
        }
    }

    /**
     * Einmalig nach dem Update: Dateien im öffentlichen Medienordner, die nicht erlaubt sind (z. B. .php
     * aus einem früheren Paket), in den privaten Paketordner (quarantine/) verschieben – nicht löschen.
     * @return list<string> verschobene Storage-Keys
     */
    public static function quarantineMedia(): array
    {
        $media = self::baseDir().'/media';
        if (! is_dir($media)) {
            return [];
        }
        $moved = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($media, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $key = substr($file->getPathname(), strlen($media) + 1);
            $name = $file->getFilename();
            if ($name === '.htaccess' || ($name === 'index.php' && trim((string) file_get_contents($file->getPathname())) === "<?php\n// Silence is golden.")) {
                continue; // eigene Schutzdateien
            }
            if (self::mediaTypeError('media/'.$key, null, $file->getPathname()) === null) {
                continue;
            }
            $target = self::packagesDir().'/quarantine/'.$key;
            wp_mkdir_p(dirname($target));
            if (@rename($file->getPathname(), $target)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                $moved[] = $key;
            }
        }
        if ($moved !== []) {
            error_log('[bootspruefung-learning] Nicht erlaubte Dateien aus dem Medienordner in die Quarantäne verschoben: '.implode(', ', $moved));
        }

        return $moved;
    }

    /**
     * Verschiebt den bisher genutzten Ordner (laut Layout, sonst die Altordner) an den Soll-Ort.
     * Erst wird umbenannt (atomar); geht das nicht (z. B. anderes Dateisystem), wird kopiert und erst
     * nach vollständiger Kopie die Quelle gelöscht. Scheitert auch das, bleibt die Quelle in Gebrauch.
     * @return string der zu nutzende Ordner
     */
    private static function relocate(string $kind, bool $force): string
    {
        $target = self::targetDir($kind);
        $layout = self::layout();
        $current = isset($layout[$kind]) && is_string($layout[$kind]) ? $layout[$kind] : null;
        if ($current === $target) {
            return $target;
        }
        $sources = $current !== null ? [$current] : self::legacyDirs($kind);
        $sources = array_values(array_filter($sources, static fn (string $s): bool => $s !== $target && is_dir($s)));
        if ($sources === []) {
            self::saveLayout($kind, $target, null);

            return $target;
        }
        $failedAt = (int) ($layout['failed'][$kind] ?? 0);
        if (! $force && $failedAt > 0 && time() - $failedAt < self::RETRY_AFTER) {
            return $sources[0];
        }
        foreach ($sources as $source) {
            if (! self::moveDir($source, $target)) {
                $message = sprintf(__('Der Ordner %1$s konnte nicht nach %2$s verschoben werden; er bleibt vorerst in Gebrauch. Bitte Schreibrechte prüfen.', 'bootspruefung-learning'), $source, $target);
                self::saveLayout($kind, $source, $message);
                error_log('[bootspruefung-learning] '.$message);

                return $source;
            }
            if ($kind === 'protected') {
                self::rewriteProtectedPaths($source, $target);
            }
        }
        self::saveLayout($kind, $target, null);

        return $target;
    }

    /** Altordner aus der Zeit vor dem Layout (nur beim allerersten Umzug). @return list<string> */
    private static function legacyDirs(string $kind): array
    {
        $base = self::baseDir();
        if ($kind === 'packages') {
            return [$base.'/packages', $base.'/packages-'.self::secret('packages')];
        }

        return [$base.'/protected-'.self::secret('protected')];
    }

    private static function saveLayout(string $kind, string $dir, ?string $error): void
    {
        $layout = self::layout();
        $layout[$kind] = $dir;
        if ($error === null) {
            unset($layout['failed'][$kind], $layout['errors'][$kind]);
        } else {
            $layout['failed'][$kind] = time();
            $layout['errors'][$kind] = $error;
        }
        update_option(self::LAYOUT_OPTION, $layout, true);
        self::$resolved[$kind] = $dir;
    }

    /** Pfade geschützter Anhänge (Meta _bp_protected_path) auf den neuen Ordner umschreiben. */
    private static function rewriteProtectedPaths(string $from, string $to): void
    {
        global $wpdb;
        $from = trailingslashit($from);
        $like = $wpdb->esc_like($from).'%';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bp_protected_path' AND meta_value LIKE %s", $like));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CONCAT(%s, SUBSTRING(meta_value, %d)) WHERE meta_key = '_bp_protected_path' AND meta_value LIKE %s",
            trailingslashit($to),
            mb_strlen($from) + 1, // SUBSTRING zählt Zeichen
            $like
        ));
        foreach ($ids as $id) {
            wp_cache_delete((int) $id, 'post_meta');
        }
    }

    /** Ordner verschieben; true bei Erfolg (Quelle danach weg, alle Dateien am Ziel). */
    private static function moveDir(string $source, string $target): bool
    {
        wp_mkdir_p(dirname($target));
        if (! file_exists($target) && @rename($source, $target)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return true;
        }
        if (! is_dir($source) && is_dir($target)) {
            return true; // parallele Anfrage hat schon verschoben
        }
        if (file_exists($target) && ! is_dir($target)) {
            return false;
        }
        $created = ! is_dir($target);
        if (! self::copyTree($source, $target)) {
            if ($created && is_dir($target)) {
                self::removeTree($target);
            }

            return false;
        }
        self::removeTree($source);

        return true;
    }

    private static function copyTree(string $from, string $to): bool
    {
        if (! is_dir($to) && ! wp_mkdir_p($to)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $dest = $to.'/'.substr($file->getPathname(), strlen($from) + 1);
            if ($file->isDir() ? ! (is_dir($dest) || wp_mkdir_p($dest)) : ! @copy($file->getPathname(), $dest)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                return false;
            }
        }

        return true;
    }

    private static function removeTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    private static function secret(string $kind): string
    {
        $option = $kind === 'packages' ? self::PACKAGES_SECRET_OPTION : self::PROTECTED_SECRET_OPTION;
        $secret = get_option($option);
        // Ein vorhandenes Geheimnis nie ersetzen (sonst wäre der Ordner nicht mehr auffindbar).
        if (! is_string($secret) || ! preg_match('/^[A-Za-z0-9_-]+$/', $secret)) {
            $secret = wp_generate_password(24, false, false);
            update_option($option, $secret, false);
        }

        return $secret;
    }

    private static function ensureDir(string $dir, bool $private): void
    {
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            throw new RuntimeException("Verzeichnis kann nicht angelegt werden: {$dir}");
        }
        if (! is_file($dir.'/index.php')) {
            file_put_contents($dir.'/index.php', "<?php\n// Silence is golden.\n");
        }
        if ($private) {
            self::writeHtaccess($dir, self::HTACCESS_PRIVATE, true);
        }
    }

    /** .htaccess schreiben, wenn sie fehlt oder noch die alte Fassung des Plugins ist (fremde nicht überschreiben). */
    private static function writeHtaccess(string $dir, string $content, bool $private): void
    {
        $file = $dir.'/.htaccess';
        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        if ($existing === $content) {
            return;
        }
        if ($existing === null || ($private && $existing === self::HTACCESS_LEGACY)) {
            file_put_contents($file, $content);
        }
    }
}
