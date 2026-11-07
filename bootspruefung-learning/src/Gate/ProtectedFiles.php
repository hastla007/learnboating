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
 */
final class ProtectedFiles
{
    public const META = '_bp_required_access';
    public const COURSE_META = '_bp_access_course';
    private const PATH_META = '_bp_protected_path';

    public function register(): void
    {
        add_filter('attachment_fields_to_edit', [$this, 'fields'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'saveFields'], 10, 2);
        add_filter('wp_get_attachment_url', [$this, 'url'], 20, 2);
        add_filter('get_attached_file', [$this, 'attachedFile'], 20, 2);
        add_action('template_redirect', [$this, 'serve'], 0);
        add_action('delete_attachment', [$this, 'onDelete']);
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
        $html = '<select name="attachments['.(int) $post->ID.'][bp_required_access]"><option value="">'.esc_html__('– frei zugänglich –', 'bootspruefung-learning').'</option>';
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
        $slot = SectionFiles::slot((int) $post->ID);
        $fields['bp_file_slot'] = ['label' => __('Anzeige im Lernbereich', 'bootspruefung-learning'), 'input' => 'html',
            'html' => '<select name="attachments['.(int) $post->ID.'][bp_file_slot]"><option value="">'.esc_html__('Hauptseite des Bereichs', 'bootspruefung-learning').'</option><option value="vocabulary" '.selected($slot, 'vocabulary', false).'>'.esc_html__('Funk: auch auf der Vokabelseite', 'bootspruefung-learning').'</option></select>',
            'helps' => __('Nur für PDFs mit Lernzugang. Funk-PDFs (z. B. Muster-Funksprüche, Buchstabiertafel) erscheinen auf der Funk-Seite, das Vokabel-PDF zusätzlich auf der Vokabelseite.', 'bootspruefung-learning')];
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
        if (! isset($input['bp_required_access']) || ! current_user_can('upload_files')) {
            return $post;
        }
        $id = (int) $post['ID'];
        if (isset($input['bp_file_slot'])) {
            $slot = sanitize_key((string) $input['bp_file_slot']);
            in_array($slot, SectionFiles::SLOTS, true) ? update_post_meta($id, SectionFiles::SLOT_META, $slot) : delete_post_meta($id, SectionFiles::SLOT_META);
        }
        $value = sanitize_text_field((string) $input['bp_required_access']);
        if ($value === '') {
            self::unprotect($id);

            return $post;
        }
        [$course, $feature] = array_pad(explode('|', $value, 2), 2, ContentGate::ANY);
        if (! isset(Labels::courses()[$course]) || ($feature !== ContentGate::ANY && ! in_array($feature, AccessService::FEATURES, true))) {
            return $post;
        }
        self::protect($id, $course, $feature);

        return $post;
    }

    /** Datei in den gesperrten Ordner verschieben und Anforderung speichern. */
    public static function protect(int $id, string $course, string $feature): void
    {
        update_post_meta($id, self::META, ['course' => $course, 'feature' => $feature]);
        update_post_meta($id, self::COURSE_META, $course); // abfragbar für die Downloadliste
        if (get_post_meta($id, self::PATH_META, true)) {
            return;
        }
        $original = (string) get_post_meta($id, '_wp_attached_file', true);
        $uploads = wp_upload_dir(null, false);
        $source = trailingslashit($uploads['basedir']).$original;
        if ($original === '' || ! is_file($source)) {
            return;
        }
        $dir = MediaStorage::protectedDir().'/'.$id;
        wp_mkdir_p($dir);
        $target = $dir.'/'.wp_basename($source);
        if (@rename($source, $target)) {
            update_post_meta($id, self::PATH_META, $target);
            AuditLog::record('file_protected', 'attachment', (string) $id, null, ['course' => $course, 'feature' => $feature]);
        }
    }

    /** Schutz aufheben und Datei an den ursprünglichen Ort zurücklegen. */
    public static function unprotect(int $id): void
    {
        $path = (string) get_post_meta($id, self::PATH_META, true);
        delete_post_meta($id, self::META);
        delete_post_meta($id, self::COURSE_META);
        if ($path === '') {
            return;
        }
        $uploads = wp_upload_dir(null, false);
        $original = trailingslashit($uploads['basedir']).(string) get_post_meta($id, '_wp_attached_file', true);
        wp_mkdir_p(dirname($original));
        if (is_file($path) && @rename($path, $original)) {
            delete_post_meta($id, self::PATH_META);
            @rmdir(dirname($path));
            AuditLog::record('file_unprotected', 'attachment', (string) $id);
        }
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
            @rmdir(dirname($path));
        }
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
        nocache_headers();
        header('Content-Type: '.($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: inline; filename="'.rawurlencode(wp_basename($path)).'"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        /** Filter: true, wenn der Webserver X-Sendfile/X-Accel-Redirect übernimmt (nach eigenem Header). */
        if (! apply_filters('bootspruefung_protected_file_sendfile', false, $path)) {
            readfile($path);
        }
        exit;
    }
}
