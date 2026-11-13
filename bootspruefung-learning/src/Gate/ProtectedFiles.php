<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Gate;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Geschützte Dateien (z. B. PDFs) in der Mediathek: Feld „Lernzugang erforderlich“
 * am Anhang. Geschützte Dateien werden in einen gesperrten Ordner verschoben und
 * nur über /bootsscheine/datei/<id>/ nach Zugriffsprüfung ausgeliefert.
 * Gelingt das Verschieben nicht, bleibt die Datei als „nicht geschützt“ markiert
 * (kein Schutzfeld, das Sicherheit vortäuscht) und Admins sehen einen Hinweis.
 * Seekarten (_bp_chart) lassen sich nicht auf „frei zugänglich“ stellen.
 */
final class ProtectedFiles
{
    public const META = '_bp_required_access';
    public const COURSE_META = '_bp_access_course';
    public const PATH_META = '_bp_protected_path';
    public const ERROR_META = '_bp_protect_error';
    private const NOTICE_TRANSIENT = 'bp_protect_notice_';

    public function register(): void
    {
        add_filter('attachment_fields_to_edit', [$this, 'fields'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'saveFields'], 10, 2);
        add_filter('wp_get_attachment_url', [$this, 'url'], 20, 2);
        add_filter('get_attached_file', [$this, 'attachedFile'], 20, 2);
        add_action('template_redirect', [$this, 'serve'], 0);
        add_action('delete_attachment', [$this, 'onDelete']);
        add_filter('rest_prepare_attachment', [$this, 'restAttachment'], 20, 2);
        add_action('admin_notices', [$this, 'adminNotice']);
    }

    /** Hinweis für Admins nach fehlgeschlagenem Schützen/Freigeben (einmalig je Nutzer). */
    public function adminNotice(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0 || ! current_user_can('upload_files')) {
            return;
        }
        $messages = get_transient(self::NOTICE_TRANSIENT.$userId);
        if (! is_array($messages) || $messages === []) {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT.$userId);
        foreach (array_unique($messages) as $message) {
            echo '<div class="notice notice-error"><p>'.esc_html((string) $message).'</p></div>';
        }
    }

    /** Hinweis am Anhang speichern und für die nächste Admin-Seite vormerken. */
    private static function fail(int $id, string $message): void
    {
        update_post_meta($id, self::ERROR_META, $message);
        $userId = get_current_user_id();
        if ($userId > 0) {
            $messages = get_transient(self::NOTICE_TRANSIENT.$userId);
            $messages = is_array($messages) ? $messages : [];
            $messages[] = $message;
            set_transient(self::NOTICE_TRANSIENT.$userId, array_slice($messages, -10), HOUR_IN_SECONDS);
        }
        AuditLog::record('file_protect_failed', 'attachment', (string) $id, $message);
    }

    /** Seekarte (BSH)? Diese Dateien bleiben immer geschützt. */
    public static function isChart(int $id): bool
    {
        return (string) get_post_meta($id, '_bp_chart', true) !== '';
    }

    public static function downloadUrl(int $attachmentId): string
    {
        return Router::url('datei/'.$attachmentId);
    }

    /** @return array{course:string,feature:string}|null */
    public static function requirement(int $attachmentId): ?array
    {
        $req = get_post_meta($attachmentId, self::META, true);

        return is_array($req) && ! empty($req['course']) ? ['course' => (string) $req['course'], 'feature' => (string) ($req['feature'] ?? ContentGate::ANY)] : null;
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    public function fields(array $fields, \WP_Post $post): array
    {
        if (! current_user_can('upload_files')) {
            return $fields;
        }
        $req = self::requirement((int) $post->ID);
        $current = $req ? $req['course'].'|'.$req['feature'] : '';
        $chart = self::isChart((int) $post->ID);
        $html = '<select name="attachments['.(int) $post->ID.'][bp_required_access]">'.($chart ? '' : '<option value="">'.esc_html__('– frei zugänglich –', 'bootspruefung-learning').'</option>');
        foreach (Labels::courses() as $code => $name) {
            foreach (array_merge([ContentGate::ANY], AccessService::FEATURES) as $feature) {
                $value = $code.'|'.$feature;
                $label = $name.' · '.($feature === ContentGate::ANY ? __('beliebige Funktion', 'bootspruefung-learning') : Labels::feature($feature));
                $html .= sprintf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
            }
        }
        $html .= '</select>';
        if ($req !== null) {
            $html .= '<p class="description">'.esc_html__('Link für Lernende:', 'bootspruefung-learning').' <code>'.esc_html(self::downloadUrl((int) $post->ID)).'</code></p>';
        }
        if ($chart) {
            $html .= '<p class="description">'.esc_html__('Seekarte (BSH): bleibt immer geschützt; verwalten unter Bootsprüfung → Rechte.', 'bootspruefung-learning').'</p>';
        }
        $error = (string) get_post_meta((int) $post->ID, self::ERROR_META, true);
        if ($error !== '') {
            $html .= '<p class="description" style="color:#b32d2e">'.esc_html($error).'</p>';
        }
        $slot = SectionFiles::slot((int) $post->ID);
        $fields['bp_file_slot'] = ['label' => __('Anzeige im Lernbereich', 'bootspruefung-learning'), 'input' => 'html',
            'html' => '<select name="attachments['.(int) $post->ID.'][bp_file_slot]"><option value="">'.esc_html__('Hauptseite des Bereichs', 'bootspruefung-learning').'</option><option value="vocabulary" '.selected($slot, 'vocabulary', false).'>'.esc_html__('Funk: auch auf der Vokabelseite', 'bootspruefung-learning').'</option><option value="samples" '.selected($slot, 'samples', false).'>'.esc_html__('Funk: auch auf der Seite Muster-Funksprüche', 'bootspruefung-learning').'</option><option value="spelling" '.selected($slot, 'spelling', false).'>'.esc_html__('Funk: auch auf der Seite Buchstabiertafel', 'bootspruefung-learning').'</option></select>',
            'helps' => __('Nur für PDFs mit Lernzugang. Funk-PDFs erscheinen auf der Funk-Seite und zusätzlich auf der gewählten Seite (Vokabeln, Muster-Funksprüche, Buchstabiertafel).', 'bootspruefung-learning')];
        $fields['bp_required_access'] = ['label' => __('Lernzugang erforderlich', 'bootspruefung-learning'), 'input' => 'html', 'html' => $html,
            'helps' => __('Die Datei wird dann nur angemeldeten Nutzern mit Zugang ausgeliefert. PDFs zu Navigationstrainer, Prüfungssimulation, Praxistrainer oder Funk erscheinen oben auf der jeweiligen Lernseite (Titel = Name, Beschriftung = Kurztext); laden dürfen sie nur Nutzer mit vollem Zugang.', 'bootspruefung-learning')];

        return $fields;
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveFields(array $post, array $input): array
    {
        $id = (int) ($post['ID'] ?? 0);
        if (! isset($input['bp_required_access']) || ! current_user_can('upload_files') || ! current_user_can('edit_post', $id)) {
            return $post;
        }

        $value = sanitize_text_field((string) $input['bp_required_access']);
        if ($value === '') {
            if (self::isChart($id)) {
                // Lizenzpflichtige Seekarte nie öffentlich machen (Löschen/Ersetzen über die Rechte-Seite).
                self::fail($id, __('Seekarten (BSH) können nicht frei zugänglich gemacht werden.', 'bootspruefung-learning'));

                return $post;
            }
            self::unprotect($id);

            return $post;
        }
        [$course, $feature] = array_pad(explode('|', $value, 2), 2, ContentGate::ANY);
        if (! isset(Labels::courses()[$course]) || ($feature !== ContentGate::ANY && ! in_array($feature, AccessService::FEATURES, true))) {
            return $post;
        }
        self::protect($id, $course, $feature);
        // Anzeigeort nur für geschützte Dateien mit gültiger Zuordnung.
        $slot = sanitize_key((string) ($input['bp_file_slot'] ?? ''));
        in_array($slot, SectionFiles::SLOTS, true) ? update_post_meta($id, SectionFiles::SLOT_META, $slot) : delete_post_meta($id, SectionFiles::SLOT_META);

        return $post;
    }

    /**
     * Datei in den gesperrten Ordner verschieben und Anforderung speichern. Das Schutzfeld wird
     * erst gesetzt, wenn die Datei sicher im gesperrten Ordner liegt.
     * @return bool false, wenn die Datei nicht geschützt abgelegt werden konnte (Hinweis für Admins)
     */
    public static function protect(int $id, string $course, string $feature): bool
    {
        $setMeta = static function () use ($id, $course, $feature): void {
            update_post_meta($id, self::META, ['course' => $course, 'feature' => $feature]);
            update_post_meta($id, self::COURSE_META, $course); // abfragbar für die Downloadliste
            delete_post_meta($id, self::ERROR_META);
        };
        $path = (string) get_post_meta($id, self::PATH_META, true);
        if ($path !== '' && is_file($path)) {
            $setMeta();

            return true;
        }
        $original = (string) get_post_meta($id, '_wp_attached_file', true);
        $uploads = wp_upload_dir(null, false);
        $source = trailingslashit($uploads['basedir']).$original;
        if ($original === '' || ! is_file($source)) {
            self::fail($id, sprintf(__('Datei #%d konnte nicht geschützt werden: Die Datei liegt nicht im Upload-Ordner. Bitte erneut hochladen.', 'bootspruefung-learning'), $id));

            return false;
        }
        $dir = MediaStorage::protectedDir().'/'.$id;
        wp_mkdir_p($dir);
        $target = $dir.'/'.wp_basename($source);
        if (! @rename($source, $target)) {
            self::fail($id, sprintf(__('Datei #%d konnte nicht in den geschützten Ordner verschoben werden und bleibt frei zugänglich. Bitte Schreibrechte von %s prüfen und erneut speichern.', 'bootspruefung-learning'), $id, MediaStorage::protectedDir()));

            return false;
        }
        update_post_meta($id, self::PATH_META, $target);
        $setMeta();
        // Vorschaubilder/Größen (PDF-Vorschau, Bildgrößen, unskaliertes Original) mitnehmen – sonst öffentlich abrufbar.
        $left = 0;
        foreach (self::derivedFiles($id, dirname($source)) as $file) {
            wp_mkdir_p($dir.'/derived');
            if (! @rename($file, $dir.'/derived/'.wp_basename($file))) {
                $left++;
            }
        }
        if ($left > 0) {
            self::fail($id, sprintf(__('Datei #%1$d ist geschützt, aber %2$d Vorschaubild(er) konnten nicht verschoben werden und sind noch öffentlich abrufbar.', 'bootspruefung-learning'), $id, $left));
        }
        AuditLog::record('file_protected', 'attachment', (string) $id, null, ['course' => $course, 'feature' => $feature]);

        return true;
    }

    /**
     * Schutz aufheben und Datei an den ursprünglichen Ort zurücklegen. Seekarten bleiben geschützt.
     * @return bool false, wenn der Schutz bestehen bleibt (Seekarte oder Zurücklegen fehlgeschlagen)
     */
    public static function unprotect(int $id): bool
    {
        if (self::isChart($id)) {
            return false;
        }
        $path = (string) get_post_meta($id, self::PATH_META, true);
        $clear = static function () use ($id): void {
            delete_post_meta($id, self::META);
            delete_post_meta($id, self::COURSE_META);
            delete_post_meta($id, SectionFiles::SLOT_META);
            delete_post_meta($id, self::ERROR_META);
        };
        if ($path === '' || ! is_file($path)) {
            $clear();
            delete_post_meta($id, self::PATH_META);

            return true;
        }
        $uploads = wp_upload_dir(null, false);
        $original = trailingslashit($uploads['basedir']).(string) get_post_meta($id, '_wp_attached_file', true);
        wp_mkdir_p(dirname($original));
        if (! @rename($path, $original)) {
            // Datei bleibt im gesperrten Ordner – dann auch geschützt lassen, statt einen toten Link zu erzeugen.
            self::fail($id, sprintf(__('Datei #%d konnte nicht zurückverschoben werden und bleibt geschützt.', 'bootspruefung-learning'), $id));

            return false;
        }
        $clear();
        delete_post_meta($id, self::PATH_META);
        foreach (glob(dirname($path).'/derived/*') ?: [] as $file) {
            @rename($file, dirname($original).'/'.wp_basename($file));
        }
        @rmdir(dirname($path).'/derived');
        @rmdir(dirname($path));
        AuditLog::record('file_unprotected', 'attachment', (string) $id);

        return true;
    }

    public function url($url, $attachmentId)
    {
        return self::requirement((int) $attachmentId) !== null ? self::downloadUrl((int) $attachmentId) : $url;
    }

    public function attachedFile($file, $attachmentId)
    {
        $path = (string) get_post_meta((int) $attachmentId, self::PATH_META, true);

        return $path !== '' ? $path : $file;
    }

    public function onDelete(int $id): void
    {
        $path = (string) get_post_meta($id, self::PATH_META, true);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
            foreach (glob(dirname($path).'/derived/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(dirname($path).'/derived');
            @rmdir(dirname($path));
        }
    }

    /** Von WordPress erzeugte Ableitungen eines Anhangs (Größen, PDF-Vorschau, original_image). @return list<string> */
    private static function derivedFiles(int $id, string $dir): array
    {
        $meta = wp_get_attachment_metadata($id);
        $names = [];
        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            if (! empty($size['file'])) {
                $names[] = (string) $size['file'];
            }
        }
        if (! empty($meta['original_image'])) {
            $names[] = (string) $meta['original_image'];
        }
        $out = [];
        foreach (array_unique($names) as $name) {
            $file = $dir.'/'.wp_basename($name);
            if (is_file($file)) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /** REST-Medienantwort geschützter Dateien: keine Dateinamen/Größen/Pfade preisgeben. */
    public function restAttachment($response, $post)
    {
        if ($response instanceof \WP_REST_Response && $post instanceof \WP_Post && self::requirement((int) $post->ID) !== null) {
            $data = $response->get_data();
            unset($data['media_details'], $data['guid'], $data['filename']);
            $data['source_url'] = self::downloadUrl((int) $post->ID);
            $response->set_data($data);
        }

        return $response;
    }

    /** Auslieferung unter /bootsscheine/datei/<id>/. */
    public function serve(): void
    {
        if (! get_query_var('bp_app') || ! preg_match('#^datei/(\d+)/?$#', (string) get_query_var('bp_path'), $m)) {
            return;
        }
        $id = (int) $m[1];
        $req = self::requirement($id);
        $path = (string) get_post_meta($id, self::PATH_META, true);
        if ($req === null || get_post_type($id) !== 'attachment') {
            status_header(404);
            exit;
        }
        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::downloadUrl($id)));
            exit;
        }
        $userId = get_current_user_id();
        if (! current_user_can('edit_post', $id) && ! DownloadService::allowed($userId, $id)) {
            status_header(403);
            nocache_headers();
            wp_die(wp_kses_post(ContentGate::notice($req['course'], $req['feature'])), esc_html__('Nicht freigeschaltet', 'bootspruefung-learning'), ['response' => 403]);
        }
        if ($path === '' || ! is_file($path)) {
            status_header(404);
            exit;
        }
        $this->stream($path, (string) get_post_mime_type($id));
    }

    private function stream(string $path, string $mime): void
    {
        // Ausgabepuffer leeren (T105): sonst geraten Leerzeilen oder Hinweise anderer Plugins vor die Datei (kaputtes
        // PDF), und große Dateien landen im Speicher statt direkt beim Browser.
        self::clearOutputBuffers();
        nocache_headers();
        header('Content-Type: '.($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: '.self::contentDisposition('inline', wp_basename($path)));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        /** Filter: true, wenn der Webserver X-Sendfile/X-Accel-Redirect übernimmt (nach eigenem Header). */
        if (! apply_filters('bootspruefung_protected_file_sendfile', false, $path)) {
            readfile($path);
        }
        exit;
    }

    /** Alle offenen Ausgabepuffer verwerfen (vor einer Dateiauslieferung). Nicht entfernbare Puffer bleiben. */
    public static function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            if (! @ob_end_clean()) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- nicht entfernbarer Puffer
                break;
            }
        }
    }

    /**
     * Content-Disposition mit ASCII-Ersatz filename="…" und UTF-8-Dateinamen filename*=UTF-8''… (RFC 6266, RFC 8187):
     * Umlaute bleiben im gespeicherten Namen erhalten, ältere Programme bekommen einen lesbaren Ersatz.
     */
    public static function contentDisposition(string $type, string $filename): string
    {
        $filename = str_replace(["\r", "\n", "\0"], '', $filename); // Anführungszeichen sind im ASCII-Teil ersetzt, im UTF-8-Teil kodiert
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', remove_accents($filename));
        $ascii = trim($ascii, '_') !== '' ? $ascii : 'download';

        return ($type === 'attachment' ? 'attachment' : 'inline').'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }
}
