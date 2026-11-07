<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Gate;

use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * PDF-Dateien zu einem Lernbereich (z. B. Navigationsaufgaben, Prüfungsbögen, Vokabeln,
 * Muster-Funksprüche, Buchstabiertafel). Der Betreiber lädt die Dateien in der Mediathek hoch
 * und wählt „Lernzugang erforderlich: Kurs · Funktion“ (z. B. „SRC · Funkausbildung“). Sie
 * erscheinen dann oben auf der passenden Lernseite; Kombikurse zeigen auch die Dateien ihrer
 * Teilkurse. Herunterladen nur mit vollem Zugang; im Teilumfang gesperrt (mit Kaufangebot).
 */
final class SectionFiles
{
    /** Funktionen, deren Dateien auf einer Lernseite erscheinen (Downloads haben eine eigene Seite). */
    public const SECTIONS = [AccessService::NAVIGATION, AccessService::EXAM, AccessService::PRAXIS, AccessService::RADIO];

    /** Zusätzliche Anzeigeorte (Mediathek-Feld „Anzeige im Lernbereich“). */
    public const SLOT_META = '_bp_file_slot';
    public const SLOTS = ['vocabulary'];

    /** Kombikurse zeigen auch die Dateien ihrer Teilkurse. */
    public const PARTS = ['SRC-UBI' => ['SRC', 'UBI'], 'SBF-SEE-BIN' => ['SBF-SEE', 'SBF-BIN-MOTOR']];

    /**
     * @return array<string,list<array{id:int,title:string,description:string,size:?int,url:?string,locked:bool,slot:string}>>
     */
    public static function forCourse(int $userId, string $courseCode): array
    {
        $codes = array_merge([$courseCode], self::PARTS[$courseCode] ?? []);
        $posts = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 200, 'post_mime_type' => 'application/pdf',
            'meta_query' => [['key' => ProtectedFiles::COURSE_META, 'value' => $codes, 'compare' => 'IN']],
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'], 'suppress_filters' => false,
        ]);
        $out = [];
        $seen = [];
        foreach ($posts as $post) {
            $id = (int) $post->ID;
            $req = ProtectedFiles::requirement($id);
            if ($req === null || isset($seen[$id]) || ! in_array($req['feature'], self::SECTIONS, true)) {
                continue;
            }
            // Seekarten (z. B. D49) haben eine eigene Anzeige mit Lizenzsperre.
            if (get_post_meta($id, '_bp_chart', true)) {
                continue;
            }
            $seen[$id] = true;
            $allowed = DownloadService::allowed($userId, $id);
            $file = get_attached_file($id);
            $out[$req['feature']][] = [
                'id' => $id,
                'title' => get_the_title($post),
                'description' => wp_strip_all_tags((string) $post->post_excerpt),
                'size' => $file && is_file($file) ? (int) filesize($file) : null,
                'url' => $allowed ? ProtectedFiles::downloadUrl($id) : null,
                'locked' => ! $allowed,
                'slot' => self::slot($id),
            ];
        }

        return $out;
    }

    public static function slot(int $attachmentId): string
    {
        $slot = (string) get_post_meta($attachmentId, self::SLOT_META, true);

        return in_array($slot, self::SLOTS, true) ? $slot : '';
    }
}
