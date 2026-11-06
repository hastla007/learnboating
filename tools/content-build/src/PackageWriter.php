<?php

declare(strict_types=1);

namespace ContentBuild;

use RuntimeException;
use ZipArchive;

/**
 * Schreibt das Content-Paket: manifest.json, data/<entität>.json, media/…
 * und optional ein ZIP derselben Struktur.
 */
final class PackageWriter
{
    public const ENTITY_FILES = [
        'courses', 'modules', 'questions', 'media', 'exam_rules', 'exam_papers', 'navigation_tasks', 'praxis_tasks',
        'video_modules', 'radio_texts', 'radio_vocabulary',
    ];

    /**
     * @param array<string,list<array<string,mixed>>> $entities
     * @param array<string,string> $mediaFiles source_id => Quelldatei
     * @param array<string,mixed> $source
     * @return array<string,mixed> Manifest
     */
    public function write(
        string $outDir,
        array $entities,
        array $mediaFiles,
        array $source,
        PackageBuilder $builder,
        Report $report,
        ?string $previousDir,
    ): array {
        if (is_dir($outDir)) {
            self::removeDir($outDir);
        }
        mkdir($outDir.'/data', 0775, true);

        $files = [];
        foreach (self::ENTITY_FILES as $entity) {
            $path = 'data/'.$entity.'.json';
            file_put_contents($outDir.'/'.$path, Json::encode($entities[$entity], true)."\n");
            $files[] = $this->fileEntry($outDir, $path, 'application/json');
        }
        $mediaPaths = [];
        foreach ($entities['media'] as $media) {
            $mediaPaths[$media['source_id']] = $media['path'];
        }
        foreach ($mediaFiles as $id => $file) {
            $target = $outDir.'/'.$mediaPaths[$id];
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            if (! copy($file, $target)) {
                throw new RuntimeException("Medium kann nicht kopiert werden: {$file}");
            }
            $files[] = $this->fileEntry($outDir, $mediaPaths[$id], null);
        }

        $contentHash = hash('sha256', Json::encode(array_map(static fn ($f) => [$f['path'], $f['sha256']], $files)));
        $packageId = sprintf('boatingschool-%s-%s', substr((string) $source['commit_sha'], 0, 7), substr($contentHash, 0, 12));

        [$previousId, $removed] = $this->compareWithPrevious($entities, $previousDir);
        foreach ($removed as $entity => $ids) {
            foreach ($ids as $id) {
                $report->warn('source_removed', $entity, $id, 'In der Quelle nicht mehr vorhanden; Importer markiert den Datensatz als source_archived.');
            }
        }

        $manifest = [
            'package_id' => $packageId,
            'schema_version' => PackageBuilder::SCHEMA_VERSION,
            'converter_version' => PackageBuilder::CONVERTER_VERSION,
            'source' => $source,
            'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'previous_package_id' => $previousId,
            'content_sha256' => $contentHash,
            'languages' => ['de-DE'],
            'catalogs' => $builder->catalogs(),
            'counts' => $this->counts($entities),
            'files' => $files,
            'warnings' => $report->warnings(),
            'skipped' => $report->skipped(),
            'not_converted' => $builder->notConverted(),
        ];
        file_put_contents($outDir.'/manifest.json', Json::encode($manifest, true)."\n");

        return $manifest;
    }

    public function zip(string $dir, string $zipPath): void
    {
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("ZIP kann nicht angelegt werden: {$zipPath}");
        }
        $paths = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $paths[] = substr($file->getPathname(), strlen($dir) + 1);
        }
        sort($paths);
        foreach ($paths as $relative) {
            $zip->addFile($dir.'/'.$relative, $relative);
        }
        $zip->close();
    }

    /** @return array{path:string,size:int,mime_type:string,sha256:string} */
    private function fileEntry(string $outDir, string $path, ?string $mime): array
    {
        $full = $outDir.'/'.$path;

        return [
            'path' => $path,
            'size' => filesize($full),
            'mime_type' => $mime ?? ((new \finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream'),
            'sha256' => hash_file('sha256', $full),
        ];
    }

    /** @param array<string,list<array<string,mixed>>> $entities @return array<string,mixed> */
    private function counts(array $entities): array
    {
        $counts = [];
        foreach (self::ENTITY_FILES as $entity) {
            $counts[$entity] = count($entities[$entity]);
        }
        $types = [];
        $status = [];
        foreach ($entities['questions'] as $q) {
            $types[$q['revision']['question_type']] = ($types[$q['revision']['question_type']] ?? 0) + 1;
            $status[$q['revision']['editorial_status']] = ($status[$q['revision']['editorial_status']] ?? 0) + 1;
        }
        ksort($types);
        ksort($status);
        $counts['question_types'] = $types;
        $counts['question_editorial_status'] = $status;
        $counts['answers'] = array_sum(array_map(static fn ($q) => count($q['revision']['answers']), $entities['questions']));
        $counts['navigation_questions'] = array_sum(array_map(static fn ($t) => count($t['questions']), $entities['navigation_tasks']));
        $counts['video_lessons'] = array_sum(array_map(static fn ($m) => count($m['lessons']), $entities['video_modules']));
        $counts['video_lesson_questions'] = array_sum(array_map(static fn ($m) => array_sum(array_map(static fn ($l) => count($l['questions']), $m['lessons'])), $entities['video_modules']));
        $counts['exam_paper_questions'] = array_sum(array_map(static fn ($p) => count($p['questions']), $entities['exam_papers']));

        return $counts;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $entities
     * @return array{0:?string,1:array<string,list<string>>}
     */
    private function compareWithPrevious(array $entities, ?string $previousDir): array
    {
        if ($previousDir === null) {
            return [null, []];
        }
        $manifest = Json::decodeFile($previousDir.'/manifest.json');
        $removed = [];
        foreach (self::ENTITY_FILES as $entity) {
            $file = $previousDir.'/data/'.$entity.'.json';
            if (! is_file($file)) {
                continue;
            }
            $old = array_column(Json::decodeFile($file), 'source_id');
            $new = array_column($entities[$entity], 'source_id');
            $gone = array_values(array_diff($old, $new));
            if ($gone !== []) {
                $removed[$entity] = $gone;
            }
        }

        return [$manifest['package_id'] ?? null, $removed];
    }

    public static function removeDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
