<?php

declare(strict_types=1);

namespace ContentBuild;

use RuntimeException;

/** Liest Herkunft und Prüfsummen des Snapshots (SOURCE.md, SHA256SUMS). */
final class SourceInfo
{
    /** @return array{repository:string,branch:?string,commit_sha:string,commit_date:?string} */
    public static function read(string $snapshotDir): array
    {
        $md = @file_get_contents($snapshotDir.'/SOURCE.md');
        if ($md === false) {
            throw new RuntimeException("SOURCE.md fehlt in {$snapshotDir}");
        }
        $field = static function (string $label) use ($md): ?string {
            return preg_match('/^\| '.preg_quote($label, '/').' \| `?([^`|]+?)`?(?: \(.*\))? \|$/m', $md, $m) ? trim($m[1]) : null;
        };
        $sha = $field('Commit');
        if ($sha === null || ! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new RuntimeException('Commit-SHA in SOURCE.md nicht gefunden.');
        }

        return [
            'repository' => $field('Repository') ?? 'https://github.com/hastla007/boatingschool',
            'branch' => $field('Branch/Ref'),
            'commit_sha' => $sha,
            'commit_date' => $field('Commit-Datum'),
        ];
    }

    /** Prüft den Snapshot gegen SHA256SUMS. @return list<string> Abweichungen */
    public static function verify(string $snapshotDir): array
    {
        $sums = @file($snapshotDir.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($sums === false) {
            return ['SHA256SUMS fehlt'];
        }
        $problems = [];
        foreach ($sums as $line) {
            [$hash, $path] = preg_split('/\s+/', $line, 2);
            $file = $snapshotDir.'/'.$path;
            if (! is_file($file)) {
                $problems[] = "fehlt: {$path}";
            } elseif (hash_file('sha256', $file) !== $hash) {
                $problems[] = "verändert: {$path}";
            }
        }

        return $problems;
    }
}
