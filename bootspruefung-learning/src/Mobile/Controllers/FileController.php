<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Gate\DownloadService;
use Bootspruefung\Learning\Gate\ProtectedFiles;
use Bootspruefung\Learning\Mobile\ApiException;
use WP_REST_Request;

/**
 * Geschützte Dateien (Kurs-PDFs, lizenzierte Seekarten) für die App: gleiche Freigabe wie
 * /bootsscheine/datei/<id>/ im Web (DownloadService::allowed inkl. Seekarten-Lizenzfilter),
 * aber mit Bearer-Token statt Cookie-Sitzung.
 *
 * Seit 1.4.0: HTTP-Range (genau ein Bereich, z. B. zum Fortsetzen abgebrochener Downloads oder für
 * PDF-Viewer): 206 mit Content-Range, ungültiger/nicht erfüllbarer Bereich 416 mit
 * Content-Range „bytes *“ + „/Größe“; immer Accept-Ranges: bytes. Content-Disposition mit
 * ASCII-Ersatz filename="…" und filename*=UTF-8''… (RFC 6266/8187).
 */
final class FileController extends BaseController
{
    public function download(WP_REST_Request $request): void
    {
        $id = (int) $request['fileId'];
        $req = $id > 0 ? ProtectedFiles::requirement($id) : null;
        $path = (string) get_post_meta($id, ProtectedFiles::PATH_META, true);
        if ($req === null || get_post_type($id) !== 'attachment') {
            throw ApiException::notFound(__('Datei nicht gefunden.', 'bootspruefung-learning'));
        }
        if (! DownloadService::allowed($this->user(), $id)) {
            throw new ApiException('ENTITLEMENT_REQUIRED', __('Diese Datei ist für deinen Kurs nicht freigeschaltet.', 'bootspruefung-learning'), 403);
        }
        if ($path === '' || ! is_file($path)) {
            throw ApiException::notFound(__('Datei nicht gefunden.', 'bootspruefung-learning'));
        }
        $size = (int) filesize($path);
        // If-Range ohne passende Validatoren: ganze Datei (RFC 9110, 13.1.5) – wir senden kein ETag.
        $range = $request->get_header('if_range') === null ? self::parseRange($request->get_header('range'), $size) : null;
        if ($range === false) {
            throw new ApiException('RANGE_NOT_SATISFIABLE', __('Der angeforderte Bereich liegt außerhalb der Datei.', 'bootspruefung-learning'), 416, ['headers' => ['Content-Range' => 'bytes */'.$size, 'Accept-Ranges' => 'bytes']]);
        }
        $mime = (string) get_post_mime_type($id);
        ProtectedFiles::clearOutputBuffers(); // nichts vor der Datei ausgeben (T105)
        nocache_headers();
        header('Content-Type: '.($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Disposition: '.self::contentDisposition('inline', wp_basename($path)));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Accept-Ranges: bytes');
        if ($range !== null) {
            [$start, $end] = $range;
            status_header(206);
            header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
            header('Content-Length: '.($end - $start + 1));
            self::stream($path, $start, $end - $start + 1);
            exit;
        }
        header('Content-Length: '.$size);
        if (! apply_filters('bootspruefung_protected_file_sendfile', false, $path)) {
            readfile($path);
        }
        exit;
    }

    /**
     * Range-Header auswerten (nur Einheit bytes, genau ein Bereich).
     * @return array{0:int,1:int}|null|false [Start, Ende] inklusive; null = ganze Datei (kein bzw. anderer
     *         Einheiten-Header); false = 416 (fehlerhaft, mehrere Bereiche oder nicht erfüllbar)
     */
    public static function parseRange(?string $header, int $size): array|null|false
    {
        $header = trim((string) $header);
        if ($header === '' || stripos($header, 'bytes=') !== 0) {
            return null;
        }
        $spec = trim(substr($header, 6));
        if (preg_match('/^(\d*)-(\d*)$/', $spec, $m) !== 1 || ($m[1] === '' && $m[2] === '') || $size <= 0) {
            return false;
        }
        if ($m[1] === '') {
            // Suffix: letzte N Bytes
            $length = (int) $m[2];
            if ($length <= 0) {
                return false;
            }

            return [max(0, $size - $length), $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $end < $start) {
            return false;
        }

        return [$start, $end];
    }

    /** Content-Disposition mit ASCII-Ersatz und UTF-8-Dateinamen (RFC 6266, RFC 8187). */
    public static function contentDisposition(string $type, string $filename): string
    {
        return ProtectedFiles::contentDisposition($type, $filename);
    }

    private static function stream(string $path, int $offset, int $length): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        fseek($handle, $offset);
        while ($length > 0 && ! feof($handle)) {
            $chunk = fread($handle, min(65536, $length));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- Dateiinhalt
            $length -= strlen($chunk);
        }
        fclose($handle);
    }
}
