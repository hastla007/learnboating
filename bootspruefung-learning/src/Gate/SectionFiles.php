<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Gate;

use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * PDF-Dateien zu einem Lernbereich (z. B. Navigationsaufgaben, Prüfungsbögen, Lösungen).
 * Der Betreiber lädt die Dateien in der Mediathek hoch und wählt „Lernzugang erforderlich:
 * Kurs · Funktion“ (z. B. „SBF See · Navigationstrainer“). Sie erscheinen dann oben auf der
 * passenden Lernseite. Herunterladen nur mit vollem Zugang zu dieser Funktion; im kostenlosen
 * Teilumfang werden sie gesperrt angezeigt (mit Kaufangebot).
 */
final class SectionFiles
{
    /** Funktionen, deren Dateien auf einer Lernseite erscheinen (Downloads haben eine eigene Seite). */
    public const SECTIONS = [AccessService::NAVIGATION, AccessService::EXAM, AccessService::PRAXIS, AccessService::RADIO];

    /**
     * @return array<string,list<array{id:int,title:string,description:string,size:?int,url:?string,locked:bool}>>
     */
    public static function forCourse(int $userId, string $courseCode): array
    {
        $posts = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 200,
            'meta_key' => ProtectedFiles::COURSE_META, 'meta_value' => $courseCode,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'], 'suppress_filters' => false,
        ]);
        $access = new AccessService();
        $full = [];
        $out = [];
        foreach ($posts as $post) {
            $req = ProtectedFiles::requirement((int) $post->ID);
            if ($req === null || ! in_array($req['feature'], self::SECTIONS, true)) {
                continue;
            }
            // Seekarten (z. B. D49) haben eine eigene Anzeige mit Lizenzsperre; hier nur PDFs.
            if (get_post_meta((int) $post->ID, '_bp_chart', true) || $post->post_mime_type !== 'application/pdf') {
                continue;
            }
            $feature = $req['feature'];
            $full[$feature] ??= $access->full($userId, $courseCode, $feature);
            $file = get_attached_file((int) $post->ID);
            $out[$feature][] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'description' => wp_strip_all_tags((string) $post->post_excerpt),
                'size' => $file && is_file($file) ? (int) filesize($file) : null,
                'url' => $full[$feature] ? ProtectedFiles::downloadUrl((int) $post->ID) : null,
                'locked' => ! $full[$feature],
            ];
        }

        return $out;
    }
}
