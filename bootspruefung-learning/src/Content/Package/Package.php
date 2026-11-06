<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Package;

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

    public function __construct(public readonly string $dir)
    {
    }

    /** Entpackt ein ZIP sicher nach $targetDir. */
    public static function extract(string $zipPath, string $targetDir): self
    {
        if (filesize($zipPath) > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('Paket ist größer als 200 MB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Datei ist kein gültiges ZIP-Archiv.');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new RuntimeException('Paket enthält zu viele Dateien.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, "\0")) {
                $zip->close();
                throw new RuntimeException("Unzulässiger Pfad im Paket: {$name}");
            }
            $allowed = $name === 'manifest.json' || $name === 'data/' || $name === 'media/'
                || preg_match('#^data/[a-z_]+\.json$#', $name) || str_starts_with($name, 'media/');
            if (! $allowed) {
                $zip->close();
                throw new RuntimeException("Unerwartete Datei im Paket: {$name}");
            }
            $total += (int) $stat['size'];
        }
        if ($total > 4 * self::MAX_ZIP_BYTES) {
            $zip->close();
            throw new RuntimeException('Entpacktes Paket wäre zu groß.');
        }
        if (! wp_mkdir_p($targetDir) || ! $zip->extractTo($targetDir)) {
            $zip->close();
            throw new RuntimeException('Paket konnte nicht entpackt werden.');
        }
        $zip->close();

        return new self($targetDir);
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
