<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Package;

use Bootspruefung\Learning\Content\Media\MediaStorage;
use RuntimeException;
use ZipArchive;

/** Ein entpacktes Content-Paket auf der Festplatte (Format: schema/v1). */
final class Package
{
    public const ENTITIES = ['courses', 'modules', 'questions', 'media', 'exam_rules', 'exam_papers', 'navigation_tasks', 'praxis_tasks', 'video_modules', 'radio_texts', 'radio_vocabulary', 'radio_practice', 'device_simulation', 'exam_committees', 'exam_guides'];

    /** Entität => Schema-Version, seit der sie im Paket steht; ältere Pakete liefern leere Listen. */
    public const SINCE = ['video_modules' => '1.1.0', 'radio_texts' => '1.1.0', 'radio_vocabulary' => '1.1.0', 'radio_practice' => '1.2.0', 'device_simulation' => '1.2.0', 'exam_committees' => '1.3.0', 'exam_guides' => '1.3.0'];
    public const MAX_ZIP_BYTES = 200 * 1024 * 1024;
    public const MAX_ENTRIES = 20000;

    /** @var array<string,list<array<string,mixed>>> */
    private array $cache = [];

    /** @var array<string,string>|null */
    private ?array $mediaErrors = null;

    public function __construct(public readonly string $dir)
    {
    }

    /** Höchstes Verhältnis entpackt/gepackt je Datei ab 1 MB (Schutz vor ZIP-Bomben; JSON im Paket liegt unter 40). */
    public const MAX_RATIO = 200;

    /** Höchstes Verhältnis entpackt/gepackt für das ganze Paket ab 50 MB entpackt (Medien sind kaum komprimierbar). */
    public const MAX_TOTAL_RATIO = 50;

    /**
     * Entpackt ein ZIP sicher nach $targetDir. Vorher wird das Inhaltsverzeichnis des ZIP geprüft (inspect):
     * Pfade, erlaubte Dateien und Endungen, symbolische Links, Anzahl, Gesamtgröße und Kompressionsverhältnis.
     * Ein abgelehntes Paket wird gar nicht erst entpackt; scheitert das Entpacken mittendrin, wird der
     * Zielordner wieder entfernt.
     */
    public static function extract(string $zipPath, string $targetDir): self
    {
        $zip = self::inspect($zipPath);
        $existed = is_dir($targetDir);
        $cleanup = static function () use ($existed, $targetDir): void {
            if (! $existed && is_dir($targetDir)) {
                \Bootspruefung\Learning\Content\Import\ImportService::removeDir($targetDir);
            }
        };
        if (! wp_mkdir_p($targetDir) || ! $zip->extractTo($targetDir)) {
            $zip->close();
            $cleanup();
            throw new RuntimeException('Paket konnte nicht entpackt werden.');
        }
        $zip->close();
        // Zur Sicherheit auch nach dem Entpacken: kein symbolischer Link im Paket.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($targetDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            if ($file->isLink()) {
                $cleanup();
                throw new RuntimeException('Symbolische Links sind im Paket nicht erlaubt.');
            }
        }

        return new self($targetDir);
    }

    /**
     * Prüft das Inhaltsverzeichnis eines ZIP, ohne etwas zu entpacken, und gibt das geöffnete Archiv zurück.
     * Wirft RuntimeException mit deutscher Meldung, wenn das Paket abgelehnt wird.
     */
    public static function inspect(string $zipPath): ZipArchive
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException('Paketdatei nicht gefunden.');
        }
        if (filesize($zipPath) > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('Paket ist größer als 200 MB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Datei ist kein gültiges ZIP-Archiv.');
        }
        $fail = static function (string $message) use ($zip): never {
            $zip->close();
            throw new RuntimeException($message);
        };
        if ($zip->numFiles === 0) {
            $fail('Das ZIP-Archiv ist leer.');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $fail('Paket enthält zu viele Dateien.');
        }
        $total = 0;
        $compressed = 0;
        $hasManifest = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! is_array($stat)) {
                $fail('Inhaltsverzeichnis des ZIP-Archivs ist beschädigt.');
            }
            $name = (string) $stat['name'];
            if (($error = self::entryError($name)) !== null) {
                $fail($error);
            }
            // Symbolische Links (Unix-Dateimodus im ZIP) nie entpacken.
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                $fail("Symbolischer Link im Paket nicht erlaubt: {$name}");
            }
            $size = (int) $stat['size'];
            $comp = (int) $stat['comp_size'];
            if ($size > 1024 * 1024 && ($comp <= 0 || $size / $comp > self::MAX_RATIO)) {
                $fail("Datei im Paket lässt sich ungewöhnlich stark entpacken (mögliche ZIP-Bombe): {$name}");
            }
            $hasManifest = $hasManifest || $name === 'manifest.json';
            $total += $size;
            $compressed += $comp;
        }
        if ($total > 4 * self::MAX_ZIP_BYTES) {
            $fail('Entpacktes Paket wäre zu groß.');
        }
        if ($total > 50 * 1024 * 1024 && $total / max(1, $compressed) > self::MAX_TOTAL_RATIO) {
            $fail('Paket lässt sich ungewöhnlich stark entpacken (mögliche ZIP-Bombe).');
        }
        if (! $hasManifest) {
            $fail('manifest.json fehlt im Paket. Erwartet wird das ZIP aus tools/content-build mit manifest.json, data/ und media/ auf oberster Ebene.');
        }

        return $zip;
    }

    /**
     * Ist dieser Eintrag (relativer Pfad im Paket, Ordner mit „/“ am Ende) erlaubt? Gilt für ZIP-Einträge und
     * für Dateien eines Paketordners. $checkMedia: auch Endung/Typ der Medien prüfen (ZIP: ja, vor dem Entpacken;
     * Paketordner: nein – dort meldet die Paketprüfung nicht erlaubte Medien mit Eintrag „abgelehnt“ im Protokoll).
     * @return string|null Fehlermeldung oder null
     */
    public static function entryError(string $name, bool $checkMedia = true): ?string
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, "\0") || preg_match('#^[A-Za-z]:#', $name)) {
            return "Unzulässiger Pfad im Paket: {$name}";
        }
        $allowed = $name === 'manifest.json' || $name === 'data/' || $name === 'media/'
            || preg_match('#^data/[a-z_]+\.json$#', $name) || str_starts_with($name, 'media/');
        if (! $allowed) {
            return "Unerwartete Datei im Paket: {$name}";
        }
        if (! str_starts_with($name, 'media/')) {
            return null;
        }
        if (str_ends_with($name, '/')) {
            foreach (explode('/', rtrim($name, '/')) as $segment) {
                if ($segment === '' || str_starts_with($segment, '.')) {
                    return "Unzulässiger Pfad im Paket: {$name}";
                }
            }

            return null;
        }
        if (! $checkMedia) {
            return null;
        }
        // Medien: nur erlaubte Endungen, keine versteckten Dateien, keine Doppelendungen wie .php.png.
        $error = MediaStorage::mediaTypeError($name, null);

        return $error === null ? null : "Medium nicht erlaubt: {$name} – {$error}";
    }

    /**
     * Prüft einen entpackten Paketordner (Import aus einem Ordner auf dem Server bzw. per WP-CLI): nur erwartete
     * Dateien, keine symbolischen Links, Anzahl und Gesamtgröße. Nicht erlaubte Medientypen lehnt danach die
     * Paketprüfung ab (Batch „fehlerhaft“, Eintrag „abgelehnt“ im Protokoll); der Ordner liegt nicht öffentlich.
     */
    public static function inspectDirectory(string $dir): void
    {
        $dir = rtrim($dir, '/');
        if (! is_file($dir.'/manifest.json')) {
            throw new RuntimeException('manifest.json fehlt im Paketordner.');
        }
        $count = 0;
        $total = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $relative = substr($file->getPathname(), strlen($dir) + 1).($file->isDir() ? '/' : '');
            if ($file->isLink()) {
                throw new RuntimeException("Symbolischer Link im Paket nicht erlaubt: {$relative}");
            }
            if (($error = self::entryError($relative, false)) !== null) {
                throw new RuntimeException($error);
            }
            if (++$count > self::MAX_ENTRIES) {
                throw new RuntimeException('Paket enthält zu viele Dateien.');
            }
            $total += $file->isFile() ? (int) $file->getSize() : 0;
        }
        if ($total > 4 * self::MAX_ZIP_BYTES) {
            throw new RuntimeException('Paket ist zu groß.');
        }
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        $manifest = $this->json('manifest.json');
        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('manifest.json fehlt oder ist ungültig.');
        }

        return $manifest;
    }

    /** @return list<array<string,mixed>> */
    public function entities(string $entity): array
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            throw new RuntimeException("Unbekannte Entität {$entity}");
        }

        if (isset(self::SINCE[$entity]) && ! is_file($this->path('data/'.$entity.'.json')) && version_compare((string) ($this->manifest()['schema_version'] ?? '1.0.0'), self::SINCE[$entity], '<')) {
            return $this->cache[$entity] = [];
        }

        return $this->cache[$entity] ??= (array) $this->json('data/'.$entity.'.json');
    }

    public function path(string $relative): string
    {
        return $this->dir.'/'.$relative;
    }

    /**
     * Prüft Dateien gegen das Manifest, Schemata und Querverweise.
     * @return list<string> Fehler
     */
    public function validate(SchemaValidator $validator): array
    {
        $errors = [];
        try {
            $manifest = $this->manifest();
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }
        foreach ($validator->validate($manifest, 'manifest.schema.json') as $e) {
            $errors[] = 'manifest.json '.$e;
        }
        if ($errors !== []) {
            return $errors;
        }
        if (! str_starts_with((string) $manifest['schema_version'], '1.')) {
            return ["Schema-Version {$manifest['schema_version']} wird nicht unterstützt."];
        }

        // Nur erlaubte Medientypen (kein PHP, HTML, SVG …), sie landen im öffentlichen Medienordner.
        foreach ($this->mediaTypeErrors() as $path => $message) {
            $errors[] = "Medium nicht erlaubt: {$path} – {$message}";
        }
        $listed = [];
        foreach ($manifest['files'] as $file) {
            $listed[$file['path']] = true;
            $full = $this->path($file['path']);
            if (! is_file($full)) {
                $errors[] = "Datei fehlt: {$file['path']}";
            } elseif (filesize($full) !== $file['size'] || hash_file('sha256', $full) !== $file['sha256']) {
                $errors[] = "Prüfsumme/Größe stimmt nicht: {$file['path']}";
            }
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $relative = substr($file->getPathname(), strlen($this->dir) + 1);
            if ($relative !== 'manifest.json' && ! isset($listed[$relative])) {
                $errors[] = "Nicht im Manifest aufgeführt: {$relative}";
            }
        }
        if ($errors !== []) {
            return array_slice($errors, 0, 50);
        }

        foreach (self::ENTITIES as $entity) {
            foreach ($validator->validate($this->entities($entity), $entity.'.schema.json') as $e) {
                $errors[] = "data/{$entity}.json {$e}";
            }
            $ids = array_column($this->entities($entity), 'source_id');
            if (count($ids) !== count(array_unique($ids))) {
                $errors[] = "data/{$entity}.json enthält doppelte source_id.";
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        return $this->referenceErrors();
    }

    /**
     * Nicht erlaubte Mediendateien: Endung, angegebener MIME-Typ (data/media.json) und am Inhalt
     * erkannter MIME-Typ, für alle Medien-Datensätze und alle Dateien unter media/ im Manifest.
     * @return array<string,string> Pfad im Paket => Fehlermeldung
     */
    public function mediaTypeErrors(): array
    {
        if ($this->mediaErrors !== null) {
            return $this->mediaErrors;
        }
        $declared = [];
        try {
            foreach ($this->entities('media') as $record) {
                if (is_array($record) && is_string($record['path'] ?? null)) {
                    $declared[$record['path']] = is_string($record['mime_type'] ?? null) ? $record['mime_type'] : null;
                }
            }
        } catch (RuntimeException) {
            // Ungültiges media.json meldet die Schemaprüfung.
        }
        $paths = array_keys($declared);
        try {
            foreach ((array) ($this->manifest()['files'] ?? []) as $file) {
                $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
                if (str_starts_with($path, 'media/')) {
                    $paths[] = $path;
                }
            }
        } catch (RuntimeException) {
        }
        $errors = [];
        foreach (array_unique($paths) as $path) {
            $full = $this->path($path);
            $error = MediaStorage::mediaTypeError($path, $declared[$path] ?? null, is_file($full) ? $full : null);
            if ($error !== null) {
                $errors[$path] = $error;
            }
        }

        return $this->mediaErrors = $errors;
    }

    /** @return list<string> */
    private function referenceErrors(): array
    {
        $errors = [];
        $questions = array_flip(array_column($this->entities('questions'), 'source_id'));
        $modules = array_flip(array_column($this->entities('modules'), 'code'));
        $courses = array_flip(array_column($this->entities('courses'), 'code'));
        $media = array_flip(array_column($this->entities('media'), 'source_id'));
        $add = static function (string $msg) use (&$errors): void {
            if (count($errors) < 50) {
                $errors[] = $msg;
            }
        };
        foreach ($this->entities('courses') as $c) {
            foreach ($c['modules'] as $m) {
                isset($modules[$m['module_code']]) || $add("Kurs {$c['code']}: Modul {$m['module_code']} fehlt");
            }
        }
        foreach ($this->entities('modules') as $m) {
            foreach ($m['questions'] as $q) {
                isset($questions[$q['question_source_id']]) || $add("Modul {$m['code']}: Frage {$q['question_source_id']} fehlt");
            }
        }
        foreach ($this->entities('questions') as $q) {
            foreach ($q['revision']['media'] as $m) {
                isset($media[$m['media_source_id']]) || $add("Frage {$q['source_id']}: Medium {$m['media_source_id']} fehlt");
            }
        }
        foreach ($this->entities('exam_rules') as $r) {
            isset($courses[$r['course_code']]) || $add("Regelwerk {$r['source_id']}: Kurs fehlt");
            foreach ($r['blueprints'] as $b) {
                isset($modules[$b['module_code']]) || $add("Regelwerk {$r['source_id']}: Modul {$b['module_code']} fehlt");
            }
        }
        foreach ($this->entities('exam_papers') as $p) {
            isset($courses[$p['course_code']]) || $add("Bogen {$p['source_id']}: Kurs fehlt");
            foreach ($p['questions'] as $q) {
                isset($questions[$q['question_source_id']]) || $add("Bogen {$p['source_id']}: Frage {$q['question_source_id']} fehlt");
            }
        }
        foreach ($this->entities('navigation_tasks') as $t) {
            foreach ($t['course_codes'] as $code) {
                isset($courses[$code]) || $add("Navigationsaufgabe {$t['source_id']}: Kurs {$code} fehlt");
            }
        }
        foreach ($this->entities('video_modules') as $m) {
            isset($courses[$m['course_code']]) || $add("Videokapitel {$m['source_id']}: Kurs fehlt");
            foreach ($m['lessons'] as $l) {
                foreach ($l['questions'] as $q) {
                    isset($questions[$q['question_source_id']]) || $add("Lektion {$l['source_id']}: Frage {$q['question_source_id']} fehlt");
                }
            }
        }
        foreach (array_merge($this->entities('radio_texts'), $this->entities('radio_vocabulary')) as $r) {
            foreach ($r['course_codes'] as $code) {
                isset($courses[$code]) || $add("Funkeintrag {$r['source_id']}: Kurs {$code} fehlt");
            }
        }
        foreach ($this->entities('praxis_tasks') as $t) {
            isset($courses[$t['course_code']]) || $add("Praxiskarte {$t['source_id']}: Kurs fehlt");
            $t['media_source_id'] === null || isset($media[$t['media_source_id']]) || $add("Praxiskarte {$t['source_id']}: Medium fehlt");
        }

        return $errors;
    }

    private function json(string $relative): mixed
    {
        $path = $this->path($relative);
        if (! is_file($path)) {
            throw new RuntimeException("{$relative} fehlt im Paket.");
        }
        try {
            return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("{$relative} ist kein gültiges JSON: {$e->getMessage()}");
        }
    }
}
