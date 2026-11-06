<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Practice\PracticeRegistrationService as Service;
use Bootspruefung\Learning\Security\Capabilities;

/**
 * Adminbereich „Praxis-Anmeldung“: Anmeldungen (ansehen, korrigieren, Status,
 * löschen), Praxisorte der Bootsschule, Formulare und Prüfungsausschüsse.
 * Ohne Prüfungsbuchung/Terminvergabe. Solange die Funktion nicht freigeschaltet
 * ist, lässt sich alles vorbereiten; Lernende sehen nichts.
 */
final class PracticePages
{
    public const SLUG = 'bootspruefung-praxis';
    private const TABS = ['registrations', 'locations', 'forms', 'committees'];

    public function register(): void
    {
        add_action('admin_post_bp_praxis', [$this, 'handle']);
    }

    private function service(): Service
    {
        return new Service();
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        // phpcs:disable WordPress.Security.NonceVerification -- Anzeige
        $tab = in_array($_GET['tab'] ?? '', self::TABS, true) ? (string) $_GET['tab'] : 'registrations';
        $id = absint($_GET['registration'] ?? $_GET['id'] ?? 0);
        // phpcs:enable
        if (isset($_GET['registration'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $tab = 'registrations';
        }
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Praxis-Anmeldung', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        if (! Service::enabled()) {
            echo '<div class="notice notice-info inline"><p>'.esc_html__('Die Praxis-Anmeldung ist vorbereitet, aber für Lernende noch nicht freigeschaltet (Einstellungen › Praxis-Anmeldung). Prüfungsbuchung und Terminvergabe sind nicht enthalten.', 'bootspruefung-learning').'</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach (['registrations' => __('Anmeldungen', 'bootspruefung-learning'), 'locations' => __('Praxisorte', 'bootspruefung-learning'), 'forms' => __('Formulare', 'bootspruefung-learning'), 'committees' => __('Prüfungsausschüsse', 'bootspruefung-learning')] as $key => $label) {
            printf('<a class="nav-tab %s" href="%s">%s</a>', $tab === $key ? 'nav-tab-active' : '', esc_url(Ui::url(self::SLUG, ['tab' => $key])), esc_html($label));
        }
        echo '</nav>';
        match ($tab) {
            'locations' => $this->locations($id),
            'forms' => $this->forms($id),
            'committees' => $this->committees($id),
            default => $id ? $this->registration($id) : $this->registrations(),
        };
        echo '</div>';
    }

    private function formStart(string $op, array $hidden = []): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_praxis');
        echo '<input type="hidden" name="action" value="bp_praxis"><input type="hidden" name="op" value="'.esc_attr($op).'">';
        foreach ($hidden as $k => $v) {
            echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string) $v).'">';
        }
    }

    private static function statusLabel(string $status): string
    {
        return ['received' => __('eingegangen', 'bootspruefung-learning'), 'forwarded' => __('an Prüfungsausschuss gemeldet', 'bootspruefung-learning'), 'done' => __('erledigt', 'bootspruefung-learning')][$status] ?? $status;
    }

    // ------------------------------------------------------------------ Anmeldungen

    private function registrations(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $filter = ['course' => sanitize_text_field(wp_unslash($_GET['course'] ?? '')), 'status' => sanitize_key(wp_unslash($_GET['status'] ?? '')), 'search' => sanitize_text_field(wp_unslash($_GET['s'] ?? ''))];
        $page = max(1, absint($_GET['paged'] ?? 1));
        // phpcs:enable
        echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">';
        printf('<input type="search" name="s" value="%s" placeholder="%s"> ', esc_attr($filter['search']), esc_attr__('Name oder E-Mail', 'bootspruefung-learning'));
        echo '<select name="course"><option value="">'.esc_html__('alle Kurse', 'bootspruefung-learning').'</option>';
        foreach (Labels::courses() as $code => $name) {
            printf('<option value="%s" %s>%s</option>', esc_attr($code), selected($filter['course'], $code, false), esc_html($name));
        }
        echo '</select> <select name="status"><option value="">'.esc_html__('alle Status', 'bootspruefung-learning').'</option>';
        foreach (['received', 'forwarded', 'done'] as $s) {
            printf('<option value="%s" %s>%s</option>', esc_attr($s), selected($filter['status'], $s, false), esc_html(self::statusLabel($s)));
        }
        echo '</select> ';
        submit_button(__('Filtern', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</form>';
        $rows = [];
        $list = $this->service()->search($filter, $page);
        foreach ($list as $r) {
            $rows[] = ['<a href="'.esc_url(Ui::url(self::SLUG, ['registration' => $r->id])).'">#'.(int) $r->id.'</a>', esc_html(($r->display_name ?: '–').' <'.($r->user_email ?: '#'.$r->user_id).'>'),
                esc_html(Labels::course($r->course_code)), esc_html((string) $r->form_title), esc_html(self::statusLabel($r->status)), esc_html(Ui::date($r->created_at))];
        }
        Ui::table(['#', __('Person', 'bootspruefung-learning'), __('Kurs', 'bootspruefung-learning'), __('Formular', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('eingegangen', 'bootspruefung-learning')], $rows, __('Noch keine Anmeldungen.', 'bootspruefung-learning'));
        Ui::pager($page, count($list) === 50, static fn (int $p) => Ui::url(self::SLUG, array_filter($filter) + ['paged' => $p]));
    }

    private function registration(int $id): void
    {
        $service = $this->service();
        $r = $service->registration($id);
        if ($r === null) {
            echo '<p>'.esc_html__('Anmeldung nicht gefunden.', 'bootspruefung-learning').'</p>';

            return;
        }
        $form = $r->form_id ? $service->form((int) $r->form_id) : null;
        $user = get_userdata((int) $r->user_id);
        $payload = $service->registrationPayload($r, $form);
        echo '<p><a href="'.esc_url(Ui::url(self::SLUG)).'">&larr; '.esc_html__('alle Anmeldungen', 'bootspruefung-learning').'</a></p>';
        $profile = $service->profile((int) $r->user_id);
        $pairs = [
            __('Person', 'bootspruefung-learning') => esc_html($user ? $user->display_name.' <'.$user->user_email.'>' : '#'.$r->user_id),
            __('Adresse', 'bootspruefung-learning') => esc_html((string) Service::address($profile['street'], $profile['postal_code'], $profile['city'], $profile['country'])),
            __('Geburtsdatum', 'bootspruefung-learning') => esc_html($profile['birthdate'] !== '' ? wp_date(get_option('date_format'), strtotime($profile['birthdate'])) : '–'),
            __('Kurs', 'bootspruefung-learning') => esc_html(Labels::course($r->course_code)),
            __('Formular', 'bootspruefung-learning') => esc_html((string) $r->form_title),
            __('Status', 'bootspruefung-learning') => esc_html(self::statusLabel($r->status)),
            __('eingegangen', 'bootspruefung-learning') => esc_html(Ui::date($r->created_at)),
        ];
        foreach ($payload['rows'] as $row) {
            $pairs[$row['label']] = esc_html($row['value']);
        }
        Ui::keyValue($pairs);

        echo '<h2>'.esc_html__('Status und Notiz', 'bootspruefung-learning').'</h2>';
        $this->formStart('status', ['id' => $id]);
        echo '<p><select name="status">';
        foreach (['received', 'forwarded', 'done'] as $s) {
            printf('<option value="%s" %s>%s</option>', esc_attr($s), selected($r->status, $s, false), esc_html(self::statusLabel($s)));
        }
        echo '</select> <input type="text" class="regular-text" name="note" value="'.esc_attr((string) $r->admin_note).'" placeholder="'.esc_attr__('interne Notiz', 'bootspruefung-learning').'"> ';
        submit_button(__('Speichern', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</p></form>';

        if ($form !== null) {
            $data = json_decode((string) $r->data, true) ?: [];
            echo '<h2>'.esc_html__('Angaben korrigieren', 'bootspruefung-learning').'</h2>';
            $this->formStart('update', ['id' => $id]);
            echo '<table class="form-table"><tbody>';
            $locations = $service->locations((string) $r->course_code);
            foreach (Service::fields($form) as $field) {
                $name = 'data['.$field['key'].']';
                $value = $field['type'] === 'location' ? (string) ($data[$field['key'].'_location_id'] ?? '') : (string) ($data[$field['key']] ?? '');
                echo '<tr><th scope="row">'.esc_html($field['label'].($field['required'] ? ' *' : '')).'</th><td>';
                echo match ($field['type']) {
                    'checkbox' => '<input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked(! empty($data[$field['key']]), true, false).'>',
                    'date' => '<input type="date" name="'.esc_attr($name).'" value="'.esc_attr($value).'">',
                    'textarea' => '<textarea name="'.esc_attr($name).'" rows="3" class="large-text">'.esc_textarea($value).'</textarea>',
                    'select', 'radio' => '<select name="'.esc_attr($name).'"><option value=""></option>'.implode('', array_map(static fn ($o) => sprintf('<option value="%s" %s>%s</option>', esc_attr($o), selected($value, $o, false), esc_html($o)), $field['options'])).'</select>',
                    'location' => '<select name="'.esc_attr($name).'"><option value=""></option>'.implode('', array_map(static fn ($l) => sprintf('<option value="%d" %s>%s</option>', (int) $l->id, selected($value, (string) $l->id, false), esc_html($l->name)), $locations)).'</select>',
                    default => '<input type="text" class="regular-text" name="'.esc_attr($name).'" value="'.esc_attr($value).'">',
                }; // phpcs:ignore WordPress.Security.EscapeOutput -- oben escaped
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            submit_button(__('Angaben speichern', 'bootspruefung-learning'));
            echo '</form>';
        }
        echo '<h2>'.esc_html__('Anmeldung löschen', 'bootspruefung-learning').'</h2>';
        $this->formStart('delete', ['id' => $id]);
        echo '<p><input type="text" class="regular-text" name="reason" required placeholder="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'"> <button class="button button-link-delete">'.esc_html__('Löschen', 'bootspruefung-learning').'</button></p>';
        echo '<p class="description">'.esc_html__('Danach kann sich die Person für diesen Kurs erneut anmelden.', 'bootspruefung-learning').'</p></form>';
    }

    // ------------------------------------------------------------------ Praxisorte

    private function locations(int $id): void
    {
        $service = $this->service();
        $edit = null;
        foreach ($service->locations() as $l) {
            if ((int) $l->id === $id) {
                $edit = $l;
            }
        }
        $rows = [];
        foreach ($service->locations() as $l) {
            $rows[] = ['<a href="'.esc_url(Ui::url(self::SLUG, ['tab' => 'locations', 'id' => $l->id])).'">'.esc_html($l->name).'</a>',
                esc_html((string) Service::address($l->street, $l->postal_code, $l->city)), esc_html($l->exam_name ?: '–'),
                esc_html($l->committee_commission ? Service::committeeLabel((object) ['commission' => $l->committee_commission, 'location_type' => $l->committee_location_type]) : '–'),
                esc_html(implode(', ', array_map([Labels::class, 'course'], Service::codes($l->course_codes))))];
        }
        echo '<p>'.esc_html__('Orte, an denen die Bootsschule Praxis und Prüfung anbietet – Lernende wählen im Formular-Feld „Ort“ daraus. Prüfungs- und Theorieort sowie der Prüfungsausschuss werden mit der Anmeldung übernommen.', 'bootspruefung-learning').'</p>';
        Ui::table([__('Praxisort', 'bootspruefung-learning'), __('Adresse', 'bootspruefung-learning'), __('Prüfungsort', 'bootspruefung-learning'), __('Prüfungsausschuss', 'bootspruefung-learning'), __('Kurse', 'bootspruefung-learning')], $rows, __('Noch keine Praxisorte.', 'bootspruefung-learning'));

        echo '<h2>'.esc_html($edit ? __('Praxisort bearbeiten', 'bootspruefung-learning') : __('Praxisort anlegen', 'bootspruefung-learning')).'</h2>';
        $this->formStart('location', ['id' => $edit->id ?? 0]);
        echo '<table class="form-table"><tbody>';
        foreach (['' => __('Praxisort', 'bootspruefung-learning'), 'exam_' => __('Prüfungsort (leer = wie Praxisort)', 'bootspruefung-learning'), 'theory_' => __('Theorieort (optional)', 'bootspruefung-learning')] as $prefix => $label) {
            $name = $prefix === '' ? 'name' : $prefix.'name';
            printf('<tr><th scope="row">%s</th><td><input class="regular-text" name="%s" value="%s" placeholder="%s"%s><br>', esc_html($label), esc_attr($name), esc_attr((string) ($edit->{$name} ?? '')), esc_attr__('Bezeichnung', 'bootspruefung-learning'), $prefix === '' ? ' required' : '');
            foreach (['street' => __('Straße', 'bootspruefung-learning'), 'postal_code' => __('PLZ', 'bootspruefung-learning'), 'city' => __('Ort', 'bootspruefung-learning'), 'country' => __('Land', 'bootspruefung-learning')] as $f => $ph) {
                printf('<input name="%s" value="%s" placeholder="%s" style="width:%s"> ', esc_attr($prefix.$f), esc_attr((string) ($edit->{$prefix.$f} ?? '')), esc_attr($ph), $f === 'street' ? '220px' : '110px');
            }
            if ($prefix === '') {
                printf('<br><input name="lat" value="%s" placeholder="%s" style="width:110px"> <input name="lng" value="%s" placeholder="%s" style="width:110px">', esc_attr((string) ($edit->lat ?? '')), esc_attr__('Breite', 'bootspruefung-learning'), esc_attr((string) ($edit->lng ?? '')), esc_attr__('Länge', 'bootspruefung-learning'));
            }
            echo '</td></tr>';
        }
        echo '<tr><th scope="row">'.esc_html__('Prüfungsausschuss', 'bootspruefung-learning').'</th><td><select name="committee_id"><option value="">–</option>';
        foreach ($service->committees() as $c) {
            printf('<option value="%d" %s>%s</option>', (int) $c->id, selected((int) ($edit->committee_id ?? 0), (int) $c->id, false), esc_html(Service::committeeLabel($c)));
        }
        echo '</select></td></tr><tr><th scope="row">'.esc_html__('Kurse', 'bootspruefung-learning').'</th><td>';
        $this->courseCheckboxes(Service::codes($edit->course_codes ?? ''));
        printf('</td></tr><tr><th scope="row">%s</th><td><input type="number" name="sort_order" value="%d" style="width:80px"></td></tr></tbody></table>', esc_html__('Reihenfolge', 'bootspruefung-learning'), (int) ($edit->sort_order ?? 0));
        submit_button($edit ? __('Praxisort speichern', 'bootspruefung-learning') : __('Praxisort anlegen', 'bootspruefung-learning'));
        echo '</form>';
        if ($edit) {
            $this->formStart('location_delete', ['id' => $edit->id]);
            echo '<p><button class="button button-link-delete">'.esc_html__('Praxisort löschen', 'bootspruefung-learning').'</button></p></form>';
        }
    }

    /** @param list<string> $selected */
    private function courseCheckboxes(array $selected): void
    {
        foreach (Labels::courses() as $code => $name) {
            printf('<label style="display:inline-block;min-width:220px"><input type="checkbox" name="course_codes[]" value="%s" %s> %s</label>', esc_attr($code), checked(in_array($code, $selected, true), true, false), esc_html($name));
        }
    }

    // ------------------------------------------------------------------ Formulare

    private function forms(int $id): void
    {
        $service = $this->service();
        $rows = [];
        foreach ($service->forms() as $f) {
            $rows[] = ['<a href="'.esc_url(Ui::url(self::SLUG, ['tab' => 'forms', 'id' => $f->id])).'">'.esc_html($f->title).'</a>', (int) $f->min_progress_percent.' %',
                $f->requires_fitness_certificate ? esc_html__('ja', 'bootspruefung-learning') : '–', esc_html(implode(', ', Service::codes($f->course_codes))), $f->active ? esc_html__('aktiv', 'bootspruefung-learning') : esc_html__('inaktiv', 'bootspruefung-learning'),
                $f->locally_modified ? esc_html__('lokal geändert', 'bootspruefung-learning') : esc_html__('aus Content-Paket', 'bootspruefung-learning')];
        }
        Ui::table([__('Formular', 'bootspruefung-learning'), __('Mindestfortschritt', 'bootspruefung-learning'), __('Tauglichkeit', 'bootspruefung-learning'), __('Kurse', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('Herkunft', 'bootspruefung-learning')], $rows, __('Keine Formulare – bitte Content-Paket importieren.', 'bootspruefung-learning'));
        $form = $id ? $service->form($id) : null;
        if ($form === null) {
            return;
        }
        echo '<h2>'.esc_html(sprintf(__('Formular „%s“ bearbeiten', 'bootspruefung-learning'), $form->title)).'</h2>';
        $this->formStart('form', ['id' => $form->id]);
        echo '<table class="form-table"><tbody>';
        printf('<tr><th>%s</th><td><input class="regular-text" name="title" value="%s" required></td></tr>', esc_html__('Titel', 'bootspruefung-learning'), esc_attr($form->title));
        printf('<tr><th>%s</th><td><input type="number" min="0" max="100" name="min_progress_percent" value="%d"> %%</td></tr>', esc_html__('Mindestfortschritt (gefestigte Fragen)', 'bootspruefung-learning'), (int) $form->min_progress_percent);
        printf('<tr><th>%s</th><td><label><input type="checkbox" name="requires_fitness_certificate" value="1" %s> %s</label></td></tr>', esc_html__('Tauglichkeit', 'bootspruefung-learning'), checked((bool) $form->requires_fitness_certificate, true, false), esc_html__('Hinweis auf ärztliche Tauglichkeitsbescheinigung', 'bootspruefung-learning'));
        printf('<tr><th>%s</th><td><label><input type="checkbox" name="active" value="1" %s> %s</label></td></tr>', esc_html__('Aktiv', 'bootspruefung-learning'), checked((bool) $form->active, true, false), esc_html__('Formular wird angeboten', 'bootspruefung-learning'));
        echo '<tr><th>'.esc_html__('Kurse', 'bootspruefung-learning').'</th><td>';
        $this->courseCheckboxes(Service::codes($form->course_codes));
        echo '<p class="description">'.esc_html__('Ein Kurs darf nur in genau einem aktiven Formular vorkommen.', 'bootspruefung-learning').'</p></td></tr>';
        printf('<tr><th>%s</th><td><textarea name="fields" rows="14" class="large-text code">%s</textarea><p class="description">%s</p></td></tr>', esc_html__('Felder (JSON)', 'bootspruefung-learning'),
            esc_textarea((string) wp_json_encode(Service::fields($form), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            esc_html__('Liste aus {key, label, type, required, options, help_text}; type: text, textarea, date, checkbox, select, radio, location.', 'bootspruefung-learning'));
        echo '</tbody></table>';
        submit_button(__('Formular speichern', 'bootspruefung-learning'));
        echo '<p class="description">'.esc_html__('Gespeicherte Änderungen bleiben bei späteren Content-Importen erhalten.', 'bootspruefung-learning').'</p></form>';
    }

    // ------------------------------------------------------------------ Prüfungsausschüsse

    private function committees(int $id): void
    {
        $service = $this->service();
        $rows = [];
        $edit = null;
        foreach ($service->committees() as $c) {
            if ((int) $c->id === $id) {
                $edit = $c;
            }
            $rows[] = ['<a href="'.esc_url(Ui::url(self::SLUG, ['tab' => 'committees', 'id' => $c->id])).'">'.esc_html(Service::committeeLabel($c)).'</a>',
                esc_html((string) Service::address($c->street, $c->postal_code, $c->city)), esc_html(trim(($c->phone ?? '').' '.($c->email ?? ''))),
                esc_html(implode(', ', Service::codes($c->course_codes)) ?: '–'), $c->locally_modified ? esc_html__('lokal geändert', 'bootspruefung-learning') : esc_html__('DMYV-Liste', 'bootspruefung-learning')];
        }
        echo '<p>'.esc_html__('Bundesweite Prüfungsausschüsse (DMYV-Liste aus dem Content-Paket). Praxisorte verweisen darauf; Kurse geben an, welche Scheine ein Ausschuss abnimmt.', 'bootspruefung-learning').'</p>';
        Ui::table([__('Ausschuss', 'bootspruefung-learning'), __('Adresse', 'bootspruefung-learning'), __('Kontakt', 'bootspruefung-learning'), __('Kurse', 'bootspruefung-learning'), __('Herkunft', 'bootspruefung-learning')], $rows, __('Keine Prüfungsausschüsse – bitte Content-Paket importieren.', 'bootspruefung-learning'));
        if ($edit === null) {
            return;
        }
        echo '<h2>'.esc_html(Service::committeeLabel($edit)).'</h2>';
        $this->formStart('committee', ['id' => $edit->id]);
        echo '<table class="form-table"><tbody>';
        foreach (['commission' => __('Kommission', 'bootspruefung-learning'), 'location_type' => __('Standorttyp', 'bootspruefung-learning'), 'contact_person' => __('Kontaktperson', 'bootspruefung-learning'),
            'street' => __('Straße', 'bootspruefung-learning'), 'postal_code' => __('PLZ', 'bootspruefung-learning'), 'city' => __('Ort', 'bootspruefung-learning'), 'phone' => __('Telefon', 'bootspruefung-learning'),
            'fax' => __('Fax', 'bootspruefung-learning'), 'email' => __('E-Mail', 'bootspruefung-learning'), 'website' => __('Website', 'bootspruefung-learning'), 'office_hours' => __('Sprechzeiten', 'bootspruefung-learning'), 'note' => __('Hinweis', 'bootspruefung-learning')] as $f => $label) {
            printf('<tr><th>%s</th><td><input class="regular-text" name="%s" value="%s"%s></td></tr>', esc_html($label), esc_attr($f), esc_attr((string) ($edit->{$f} ?? '')), $f === 'commission' ? ' required' : '');
        }
        echo '<tr><th>'.esc_html__('Kurse', 'bootspruefung-learning').'</th><td>';
        $this->courseCheckboxes(Service::codes($edit->course_codes));
        echo '</td></tr></tbody></table>';
        submit_button(__('Prüfungsausschuss speichern', 'bootspruefung-learning'));
        echo '<p class="description">'.esc_html__('Gespeicherte Änderungen bleiben bei späteren Content-Importen erhalten.', 'bootspruefung-learning').'</p></form>';
    }

    // ------------------------------------------------------------------ Speichern

    public function handle(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_praxis');
        global $wpdb;
        $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        $id = absint($_POST['id'] ?? 0);
        $text = static fn (string $k): ?string => ($v = trim(sanitize_text_field(wp_unslash($_POST[$k] ?? '')))) === '' ? null : $v;
        $codes = static fn (): string => implode(',', array_values(array_intersect(array_map('sanitize_text_field', (array) wp_unslash($_POST['course_codes'] ?? [])), array_keys(Labels::courses()))));
        $now = current_time('mysql', true);
        $back = ['tab' => 'registrations'];
        try {
            switch ($op) {
                case 'status':
                    $this->service()->setStatus($id, sanitize_key(wp_unslash($_POST['status'] ?? '')), sanitize_text_field(wp_unslash($_POST['note'] ?? '')));
                    $back = ['registration' => $id];
                    break;
                case 'update':
                    $this->service()->update($id, (array) wp_unslash($_POST['data'] ?? []));
                    $back = ['registration' => $id];
                    break;
                case 'delete':
                    $reason = trim(sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
                    if ($reason === '') {
                        throw new \RuntimeException(__('Bitte eine Begründung angeben.', 'bootspruefung-learning'));
                    }
                    $this->service()->delete($id, $reason);
                    break;
                case 'location':
                    $data = ['name' => $text('name') ?? throw new \RuntimeException(__('Bitte den Praxisort benennen.', 'bootspruefung-learning'))];
                    foreach (['', 'exam_', 'theory_'] as $prefix) {
                        if ($prefix !== '') {
                            $data[$prefix.'name'] = $text($prefix.'name');
                        }
                        foreach (['street', 'postal_code', 'city', 'country'] as $f) {
                            $data[$prefix.$f] = $text($prefix.$f);
                        }
                    }
                    foreach (['lat', 'lng'] as $f) {
                        $v = str_replace(',', '.', (string) ($text($f) ?? ''));
                        $data[$f] = is_numeric($v) ? (float) $v : null;
                    }
                    $data['committee_id'] = absint($_POST['committee_id'] ?? 0) ?: null;
                    $data['course_codes'] = $codes();
                    $data['sort_order'] = (int) ($_POST['sort_order'] ?? 0);
                    $data['updated_at'] = $now;
                    if ($id) {
                        $wpdb->update($wpdb->prefix.'bp_practice_location', $data, ['id' => $id]);
                    } else {
                        $wpdb->insert($wpdb->prefix.'bp_practice_location', $data + ['created_at' => $now]);
                        $id = (int) $wpdb->insert_id;
                    }
                    AuditLog::record('practice_location_saved', 'practice_location', (string) $id);
                    $back = ['tab' => 'locations', 'id' => $id];
                    break;
                case 'location_delete':
                    $wpdb->delete($wpdb->prefix.'bp_practice_location', ['id' => $id]);
                    AuditLog::record('practice_location_deleted', 'practice_location', (string) $id);
                    $back = ['tab' => 'locations'];
                    break;
                case 'form':
                    $fields = json_decode((string) wp_unslash($_POST['fields'] ?? ''), true);
                    if (! is_array($fields) || ! array_is_list($fields)) {
                        throw new \RuntimeException(__('Felder: ungültiges JSON.', 'bootspruefung-learning'));
                    }
                    $clean = [];
                    foreach ($fields as $f) {
                        if (! is_array($f) || ! preg_match('/^[a-z0-9_]+$/', (string) ($f['key'] ?? '')) || trim((string) ($f['label'] ?? '')) === ''
                            || ! in_array($f['type'] ?? '', ['text', 'textarea', 'date', 'checkbox', 'select', 'radio', 'location'], true)) {
                            throw new \RuntimeException(__('Felder: jedes Feld braucht key (a–z, 0–9, _), label und einen gültigen type.', 'bootspruefung-learning'));
                        }
                        $clean[] = ['key' => $f['key'], 'label' => sanitize_text_field($f['label']), 'type' => $f['type'], 'required' => ! empty($f['required']),
                            'options' => array_values(array_map('sanitize_text_field', (array) ($f['options'] ?? []))), 'help_text' => isset($f['help_text']) && $f['help_text'] !== '' ? sanitize_text_field((string) $f['help_text']) : null];
                    }
                    $active = ! empty($_POST['active']);
                    $courseCodes = $codes();
                    if ($active) {
                        foreach ($this->service()->forms(true) as $other) {
                            $clash = array_intersect(Service::codes($other->course_codes), Service::codes($courseCodes));
                            if ((int) $other->id !== $id && $clash !== []) {
                                throw new \RuntimeException(sprintf(__('Kurs %1$s ist bereits dem aktiven Formular „%2$s“ zugeordnet.', 'bootspruefung-learning'), implode(', ', $clash), $other->title));
                            }
                        }
                    }
                    $wpdb->update($wpdb->prefix.'bp_praxis_form', [
                        'title' => $text('title') ?? throw new \RuntimeException(__('Bitte einen Titel angeben.', 'bootspruefung-learning')),
                        'min_progress_percent' => max(0, min(100, (int) ($_POST['min_progress_percent'] ?? 0))), 'requires_fitness_certificate' => empty($_POST['requires_fitness_certificate']) ? 0 : 1,
                        'active' => $active ? 1 : 0, 'course_codes' => $courseCodes, 'fields' => wp_json_encode($clean), 'locally_modified' => 1, 'updated_at' => $now,
                    ], ['id' => $id]);
                    AuditLog::record('praxis_form_saved', 'praxis_form', (string) $id);
                    $back = ['tab' => 'forms', 'id' => $id];
                    break;
                case 'committee':
                    $data = ['commission' => $text('commission') ?? throw new \RuntimeException(__('Bitte die Kommission angeben.', 'bootspruefung-learning'))];
                    foreach (['location_type', 'contact_person', 'street', 'postal_code', 'city', 'phone', 'fax', 'email', 'website', 'office_hours', 'note'] as $f) {
                        $data[$f] = $text($f);
                    }
                    $data['course_codes'] = $codes();
                    $data['locally_modified'] = 1;
                    $data['updated_at'] = $now;
                    $wpdb->update($wpdb->prefix.'bp_exam_committee', $data, ['id' => $id]);
                    AuditLog::record('exam_committee_saved', 'exam_committee', (string) $id);
                    $back = ['tab' => 'committees', 'id' => $id];
                    break;
                default:
                    throw new \RuntimeException('Unbekannte Aktion.');
            }
            Ui::flash(__('Gespeichert.', 'bootspruefung-learning'));
        } catch (\RuntimeException $e) {
            Ui::flash($e->getMessage(), 'error');
            $back = $op === 'delete' || $op === 'status' || $op === 'update' ? ['registration' => $id] : ['tab' => $op === 'form' ? 'forms' : ($op === 'committee' ? 'committees' : 'locations'), 'id' => $id];
        }
        wp_safe_redirect(Ui::url(self::SLUG, $back));
        exit;
    }
}
