<?php

declare(strict_types=1);

/*
 * Liste aller Video-Lektionen eines gebauten Pakets als Vorlage für data/video_urls.json (To-do T62).
 *
 *   php tools/content-build/bin/video-lessons.php build/<package_id> > /tmp/video_urls.vorlage.json
 *
 * Ausgabe: JSON-Objekt „Kurs::Kapitel::Lektion“ => bisherige Adresse (Platzhalter als ""), nach Kursen sortiert.
 * Nur die Lektionen mit echter Adresse in data/video_urls.json übernehmen.
 */
$package = rtrim((string) ($argv[1] ?? ''), '/');
$file = $package.'/data/video_modules.json';
if (! is_file($file)) {
    fwrite(STDERR, "Aufruf: php tools/content-build/bin/video-lessons.php build/<package_id>\n");
    exit(2);
}
$out = [];
$placeholders = 0;
foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $module) {
    foreach ($module['lessons'] as $lesson) {
        $out[$lesson['source_id']] = $lesson['is_placeholder'] ? '' : (string) $lesson['video_url'];
        $placeholders += $lesson['is_placeholder'] ? 1 : 0;
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
fwrite(STDERR, count($out)." Lektionen, davon {$placeholders} mit Platzhalter.\n");
