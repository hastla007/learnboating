<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\ExamGuide\ExamGuideService;
use Bootspruefung\Learning\Security\Capabilities;

/**
 * Adminbereich „Prüfungsanmeldung“: Prüfungsausschüsse (DMYV-Liste, bearbeitbar,
 * lokale Änderungen überstehen Reimporte) und Übersicht der Anleitungen je Prüfung
 * (Content-Paket). Lernende melden sich selbst an – keine Anmeldedaten im Plugin.
 */
final class ExamGuidePages
{
    public const SLUG = 'bootspruefung-pruefungsanmeldung';

    public function register(): void
    {
        add_action('admin_post_bp_exam_committee', [$this, 'save']);
        add_action('admin_post_bp_exam_committee_delete', [$this, 'delete']);
    }

    /** Quelle selbst angelegter Ausschüsse (überstehen Content-Importe, werden nie archiviert). */
    public const LOCAL_SOURCE = 'local';

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        // phpcs:disable WordPress.Security.NonceVerification -- Anzeige
        $tab = ($_GET['tab'] ?? '') === 'guides' ? 'guides' : 'committees';
        $id = absint($_GET['id'] ?? 0);
        $new = ! empty($_GET['new']);
        // phpcs:enable
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Prüfungsanmeldung', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        if (! ExamGuideService::enabled()) {
            echo '<div class="notice notice-info inline"><p>'.esc_html__('Die Seite „Prüfung anmelden“ ist vorbereitet, aber für Lernende noch nicht freigeschaltet (Einstellungen › Prüfungsanmeldung). Lernende melden sich selbst beim Prüfungsausschuss an; das Plugin zeigt nur Anleitung und Kontakte.', 'bootspruefung-learning').'</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach (['committees' => __('Prüfungsausschüsse', 'bootspruefung-learning'), 'guides' => __('Anleitungen', 'bootspruefung-learning')] as $key => $label) {
            printf('<a class="nav-tab %s" href="%s">%s</a>', $tab === $key ? 'nav-tab-active' : '', esc_url(Ui::url(self::SLUG, ['tab' => $key])), esc_html($label));
        }
        echo '</nav>';
        $tab === 'guides' ? $this->guides() : $this->committees($id, $new);
        echo '</div>';
    }

    private function guides(): void
    {
        $rows = [];
        foreach ((new ExamGuideService())->guides() as $g) {
            $courses = array_map(static fn ($c) => Labels::course($c['course_code']), $g['courses']);
            $rows[] = [esc_html($g['title']), esc_html('ca. '.$g['fee']), esc_html($g['min_age']), esc_html(implode(', ', array_column($g['variants'], 'label'))), esc_html(implode(', ', $courses)), (string) count($g['faq'])];
        }
        echo '<p>'.esc_html__('Anleitungen aus dem Inhaltspaket (Seite „Prüfung buchen“ der Quelle). Gebühren und Fristen sind Richtwerte; maßgeblich ist der Prüfungsausschuss.', 'bootspruefung-learning').'</p>';
        Ui::table([__('Prüfung', 'bootspruefung-learning'), __('Gebühr', 'bootspruefung-learning'), __('Mindestalter', 'bootspruefung-learning'), __('Prüfungsformen', 'bootspruefung-learning'), __('Kurse', 'bootspruefung-learning'), 'FAQ'], $rows, __('Keine Anleitungen – bitte Inhaltspaket importieren.', 'bootspruefung-learning'));
    }

    private function committees(int $id, bool $new = false): void
    {
        global $wpdb;
        $edit = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bp_exam_committee WHERE id = %d", $id)) : null;
        if (! $edit && $new) {
            // Leeres Formular für einen eigenen Eintrag (T26).
            $edit = (object) ['id' => 0, 'commission' => '', 'location_type' => '', 'course_codes' => '', 'source_system' => self::LOCAL_SOURCE, 'source_url' => ''];
        }
        $local = array_flip(array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}bp_exam_committee WHERE source_system = %s", self::LOCAL_SOURCE))));
        $rows = [];
        foreach ((new ExamGuideService())->committees() as $c) {
            $rows[] = ['<a href="'.esc_url(Ui::url(self::SLUG, ['id' => $c['id']])).'">'.esc_html($c['label']).'</a>'.(isset($local[$c['id']]) ? ' <span class="description">('.esc_html__('eigener Eintrag', 'bootspruefung-learning').')</span>' : ''), esc_html((string) $c['address']),
                esc_html(trim(($c['phone'] ?? '').' '.($c['email'] ?? ''))), esc_html(implode(', ', $c['course_codes']) ?: '–')];
        }
        echo '<p>'.esc_html__('Bundesweite Prüfungsausschüsse (DMYV-Liste aus dem Inhaltspaket). „Kurse“ legt fest, für welche Scheine ein Ausschuss in der Liste zuerst erscheint.', 'bootspruefung-learning').'</p>';
        Ui::table([__('Ausschuss', 'bootspruefung-learning'), __('Adresse', 'bootspruefung-learning'), __('Kontakt', 'bootspruefung-learning'), __('Kurse', 'bootspruefung-learning')], $rows, __('Keine Prüfungsausschüsse – bitte Inhaltspaket importieren.', 'bootspruefung-learning'));
        echo '<p><a class="button" href="'.esc_url(Ui::url(self::SLUG, ['new' => 1])).'">'.esc_html__('Prüfungsausschuss hinzufügen', 'bootspruefung-learning').'</a> <span class="description">'.esc_html__('Nur mit Quelle (z. B. Seite des Verbands oder des Ausschusses). Eigene Einträge bleiben bei neuen Importen erhalten.', 'bootspruefung-learning').'</span></p>';
        if (! $edit) {
            return;
        }
        $isLocal = ($edit->source_system ?? '') === self::LOCAL_SOURCE;
        echo '<h2>'.esc_html((int) $edit->id === 0 ? __('Neuer Prüfungsausschuss', 'bootspruefung-learning') : $edit->commission.($edit->location_type ? ' — '.$edit->location_type : '')).'</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_exam_committee');
        echo '<input type="hidden" name="action" value="bp_exam_committee"><input type="hidden" name="id" value="'.(int) $edit->id.'"><table class="form-table"><tbody>';
        foreach (['commission' => __('Kommission', 'bootspruefung-learning'), 'location_type' => __('Standorttyp', 'bootspruefung-learning'), 'contact_person' => __('Kontaktperson', 'bootspruefung-learning'),
            'street' => __('Straße', 'bootspruefung-learning'), 'postal_code' => __('PLZ', 'bootspruefung-learning'), 'city' => __('Ort', 'bootspruefung-learning'), 'phone' => __('Telefon', 'bootspruefung-learning'),
            'fax' => __('Fax', 'bootspruefung-learning'), 'email' => __('E-Mail', 'bootspruefung-learning'), 'website' => __('Website', 'bootspruefung-learning'), 'office_hours' => __('Sprechzeiten', 'bootspruefung-learning'), 'note' => __('Hinweis', 'bootspruefung-learning')] as $f => $label) {
            printf('<tr><th>%s</th><td><input class="regular-text" name="%s" value="%s"%s></td></tr>', esc_html($label), esc_attr($f), esc_attr((string) ($edit->{$f} ?? '')), $f === 'commission' ? ' required' : '');
        }
        printf('<tr><th>%s</th><td><input type="url" class="large-text" name="source_url" value="%s"%s><p class="description">%s</p></td></tr>', esc_html__('Quelle', 'bootspruefung-learning'), esc_attr((string) ($edit->source_url ?? '')), $isLocal ? ' required' : '', esc_html__('Adresse, auf der die Angaben stehen (wird nicht angezeigt, nur zur Nachprüfung).', 'bootspruefung-learning'));
        echo '<tr><th>'.esc_html__('Kurse', 'bootspruefung-learning').'</th><td>';
        $selected = array_filter(array_map('trim', explode(',', (string) $edit->course_codes)));
        foreach (Labels::courses() as $code => $name) {
            printf('<label style="display:inline-block;min-width:220px"><input type="checkbox" name="course_codes[]" value="%s" %s> %s</label>', esc_attr($code), checked(in_array($code, $selected, true), true, false), esc_html($name));
        }
        echo '</td></tr></tbody></table>';
        submit_button(__('Prüfungsausschuss speichern', 'bootspruefung-learning'));
        echo '<p class="description">'.esc_html__('Gespeicherte Änderungen bleiben bei späteren Importen erhalten.', 'bootspruefung-learning').'</p></form>';
        if ($isLocal && (int) $edit->id > 0) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\''.esc_js(__('Diesen Prüfungsausschuss löschen?', 'bootspruefung-learning')).'\')">';
            wp_nonce_field('bp_exam_committee_delete');
            echo '<input type="hidden" name="action" value="bp_exam_committee_delete"><input type="hidden" name="id" value="'.(int) $edit->id.'">';
            submit_button(__('Eigenen Eintrag löschen', 'bootspruefung-learning'), 'delete', 'submit', false);
            echo '</form>';
        }
    }

    public function save(): void
    {
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_exam_committee');
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $text = static fn (string $k): ?string => ($v = trim(sanitize_text_field(wp_unslash($_POST[$k] ?? '')))) === '' ? null : $v;
        $commission = $text('commission');
        $source = $text('source_url');
        $source = $source !== null && preg_match('#^https?://#i', $source) ? (esc_url_raw($source, ['http', 'https']) ?: null) : null;
        $existing = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT id, source_system FROM {$wpdb->prefix}bp_exam_committee WHERE id = %d", $id)) : null;
        if ($id > 0 && ! $existing) {
            wp_die(esc_html__('Prüfungsausschuss nicht gefunden.', 'bootspruefung-learning'), '', ['response' => 404]);
        }
        $isLocal = $existing === null || $existing->source_system === self::LOCAL_SOURCE;
        if ($commission === null) {
            Ui::flash(__('Bitte die Kommission angeben.', 'bootspruefung-learning'), 'error');
        } elseif ($isLocal && $source === null) {
            Ui::flash(__('Bitte die Quelle angeben (Adresse mit http:// oder https://).', 'bootspruefung-learning'), 'error');
        } else {
            $data = ['commission' => $commission];
            foreach (['location_type', 'contact_person', 'street', 'postal_code', 'city', 'phone', 'fax', 'email', 'website', 'office_hours', 'note'] as $f) {
                $data[$f] = $text($f);
            }
            // Nur http(s)-Links (kein javascript: o. Ä.) und gültige E-Mail-Adressen.
            $data['website'] = $data['website'] !== null && preg_match('#^https?://#i', $data['website']) ? (esc_url_raw($data['website'], ['http', 'https']) ?: null) : null;
            $data['email'] = $data['email'] !== null && is_email($data['email']) ? $data['email'] : null;
            $data['course_codes'] = implode(',', array_values(array_intersect(array_map('sanitize_text_field', (array) wp_unslash($_POST['course_codes'] ?? [])), array_keys(Labels::courses()))));
            $data['locally_modified'] = 1;
            $data['updated_at'] = current_time('mysql', true);
            if ($source !== null || $isLocal) {
                $data['source_url'] = $source;
            }
            if ($existing === null) {
                // Eigener Eintrag: eigene Quelle, sofort sichtbar, hinter den Einträgen des Content-Pakets.
                $data += ['source_system' => self::LOCAL_SOURCE, 'source_id' => 'local-'.wp_generate_uuid4(), 'status' => 'published',
                    'rights_status' => 'own', 'sort_order' => 1000, 'created_at' => current_time('mysql', true)];
                $wpdb->insert($wpdb->prefix.'bp_exam_committee', $data);
                $id = (int) $wpdb->insert_id;
            } else {
                $wpdb->update($wpdb->prefix.'bp_exam_committee', $data, ['id' => $id]);
            }
            AuditLog::record($existing === null ? 'exam_committee_created' : 'exam_committee_saved', 'exam_committee', (string) $id);
            Ui::flash(__('Gespeichert.', 'bootspruefung-learning'));
        }
        wp_safe_redirect(Ui::url(self::SLUG, $id > 0 ? ['id' => $id] : ['new' => 1]));
        exit;
    }

    /** Eigenen Eintrag löschen (Einträge aus dem Content-Paket nicht – sie kämen beim nächsten Import zurück). */
    public function delete(): void
    {
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_exam_committee_delete');
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $deleted = $id > 0 ? (int) $wpdb->delete($wpdb->prefix.'bp_exam_committee', ['id' => $id, 'source_system' => self::LOCAL_SOURCE]) : 0;
        if ($deleted > 0) {
            AuditLog::record('exam_committee_deleted', 'exam_committee', (string) $id);
            Ui::flash(__('Gelöscht.', 'bootspruefung-learning'));
        } else {
            Ui::flash(__('Nur eigene Einträge lassen sich löschen.', 'bootspruefung-learning'), 'error');
        }
        wp_safe_redirect(Ui::url(self::SLUG));
        exit;
    }
}
