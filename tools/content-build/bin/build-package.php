<?php

declare(strict_types=1);

/*
 * Erzeugt ein Content-Paket aus content-source/boatingschool/.
 *
 *   php tools/content-build/bin/build-package.php [--out=build] [--previous=<paketordner>] [--no-zip]
 */

use ContentBuild\PackageBuilder;
use ContentBuild\PackageWriter;
use ContentBuild\Report;
use ContentBuild\SourceInfo;

$root = dirname(__DIR__, 3);
$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'ContentBuild\\')) {
            require dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, 13)).'.php';
        }
    });
}

$options = getopt('', ['out:', 'previous:', 'source:', 'no-zip']);
$snapshot = $options['source'] ?? $root.'/content-source/boatingschool';
$outBase = $options['out'] ?? $root.'/build';
$previous = $options['previous'] ?? null;

$problems = SourceInfo::verify($snapshot);
if ($problems !== []) {
    fwrite(STDERR, "Snapshot weicht von SHA256SUMS ab:\n  ".implode("\n  ", $problems)."\n");
    exit(1);
}

$report = new Report();
$builder = new PackageBuilder($snapshot, $report);
$entities = $builder->build();

$writer = new PackageWriter();
$tmp = $outBase.'/.package-tmp';
$manifest = $writer->write($tmp, $entities, $builder->mediaFiles(), SourceInfo::read($snapshot), $builder, $report, $previous);
$final = $outBase.'/'.$manifest['package_id'];
if (is_dir($final)) {
    PackageWriter::removeDir($final);
}
rename($tmp, $final);
if (! isset($options['no-zip'])) {
    $writer->zip($final, $final.'.zip');
}

printf("Paket %s\n", $manifest['package_id']);
foreach ($manifest['counts'] as $entity => $count) {
    printf("  %-28s %s\n", $entity, is_array($count) ? json_encode($count, JSON_UNESCAPED_UNICODE) : $count);
}
printf("  Warnungen: %d, übersprungen: %d, noch nicht konvertiert: %d Bereiche\n", count($manifest['warnings']), count($manifest['skipped']), count($manifest['not_converted']));
printf("Ausgabe: %s%s\n", $final, isset($options['no-zip']) ? '' : ' (+ .zip)');
