<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Gate;

use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Feature „downloads“ (Kap. 7.1): freigegebene PDF-Dateien je Kurs. Dateien
 * werden in der Mediathek mit „Lernzugang erforderlich: Kurs · Downloads“
 * markiert und erscheinen dann in der Kursseite. Teilumfang: erste n Dateien
 * (Reihenfolge der Mediathek, dann Titel).
 */
final class DownloadService
{
    /**
     * @return list<array{id:int,title:string,size:?int,mime:string,url:?string,locked:bool}>
     */
    public static function items(int $userId, string $courseCode): array
    {
        $posts = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 200,
            'meta_key' => ProtectedFiles::COURSE_META, 'meta_value' => $courseCode,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'], 'suppress_filters' => false,
        ]);
        $access = new AccessService();
        $limit = $access->limit($userId, $courseCode, AccessService::DOWNLOADS);
        $out = [];
        $index = 0;
        foreach ($posts as $post) {
            $req = ProtectedFiles::requirement((int) $post->ID);
            if ($req === null || $req['feature'] !== AccessService::DOWNLOADS) {
                continue;
            }
            $locked = $limit === 0 || ($limit !== null && $index >= $limit);
            $index++;
            $file = get_attached_file((int) $post->ID);
            $out[] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'size' => $file && is_file($file) ? (int) filesize($file) : null,
                'mime' => (string) $post->post_mime_type,
                'url' => $locked ? null : ProtectedFiles::downloadUrl((int) $post->ID),
                'locked' => $locked,
            ];
        }

        return $out;
    }

    /** Darf der Nutzer diese geschützte Datei laden? */
    public static function allowed(int $userId, int $attachmentId): bool
    {
        $req = ProtectedFiles::requirement($attachmentId);
        if ($req === null) {
            return true;
        }
        if ($req['feature'] !== AccessService::DOWNLOADS) {
            return ContentGate::allows($userId, $req['course'], $req['feature']);
        }
        foreach (self::items($userId, $req['course']) as $item) {
            if ($item['id'] === $attachmentId) {
                return ! $item['locked'];
            }
        }

        return false;
    }
}
