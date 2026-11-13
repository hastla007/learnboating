<?php

declare(strict_types=1);

/**
 * T125: Jede PHP-Datei unter src/ von Plugin und Add-ons beendet sich bei direktem Aufruf
 * (`defined('ABSPATH') || exit;` direkt nach der Namespace-Zeile).
 *
 * Aufruf aus dem Repository: php tools/qa/check-abspath.php
 */
$root = dirname(__DIR__, 2);
$dirs = ['bootspruefung-learning/src', 'learnboating-affiliate-connector/src', 'learnboating-voucher-connector/src'];
$missing = [];
$count = 0;
foreach ($dirs as $dir) {
    if (! is_dir($root.'/'.$dir)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        $head = (string) file_get_contents((string) $file, false, null, 0, 2000);
        if (! preg_match("/defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\|\\s*exit\\s*;|if\\s*\\(\\s*!\\s*defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\)\\s*\\{\\s*exit\\s*;/", $head)) {
            $missing[] = substr((string) $file, strlen($root) + 1);
        }
    }
}
if ($missing !== []) {
    fwrite(STDERR, "Ohne Direktaufruf-Schutz (defined('ABSPATH') || exit;):\n  ".implode("\n  ", $missing)."\n");
    exit(1);
}
echo "ABSPATH-Schutz in allen {$count} Dateien vorhanden.\n";
