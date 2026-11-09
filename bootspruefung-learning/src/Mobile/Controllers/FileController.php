<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Gate\DownloadService;
use Bootspruefung\Learning\Gate\ProtectedFiles;
use Bootspruefung\Learning\Mobile\ApiException;
use WP_REST_Request;

/**
 * Geschützte Dateien (Kurs-PDFs, lizenzierte Seekarten) für die App: gleiche Freigabe wie
 * /bootsscheine/datei/<id>/ im Web (DownloadService::allowed inkl. Seekarten-Lizenzfilter),
 * aber mit Bearer-Token statt Cookie-Sitzung.
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
        $mime = (string) get_post_mime_type($id);
        nocache_headers();
        header('Content-Type: '.($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: inline; filename="'.rawurlencode(wp_basename($path)).'"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        if (! apply_filters('bootspruefung_protected_file_sendfile', false, $path)) {
            readfile($path);
        }
        exit;
    }
}
