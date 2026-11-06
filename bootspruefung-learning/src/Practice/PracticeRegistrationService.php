<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Practice;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Content\Import\EntityImporter;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Gate\ContentGate;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use RuntimeException;

/**
 * Praxis-Anmeldung ohne Prüfungsbuchung (wie „Praxis & Prüfung“ der Quelle,
 * PraxisPruefungController/PraxisFormSubmissionController/PraxisFormDataResolver):
 * Lernende mit Kurszugang und ausreichendem Fortschritt füllen das Formular
 * ihres Kurses aus; die Bootsschule meldet sie damit bei der Prüfungskommission an.
 * Terminvorschläge und Prüfungsbuchung gehören bewusst nicht dazu.
 *
 * Standardmäßig deaktiviert (Einstellung „Praxis-Anmeldung freischalten“).
 */
final class PracticeRegistrationService
{
    public const PROFILE_FIELDS = ['street', 'postal_code', 'city', 'country', 'birthdate'];
    private const META_PREFIX = 'bp_profile_';

    private \wpdb $db;

    public function __construct(private readonly ContentRepository $content = new ContentRepository())
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('praxis_registration_enabled');
    }

    // ------------------------------------------------------------------ Formulare, Orte, Ausschüsse

    /** Aktives Formular des Kurses (ein Kurs gehört zu genau einem aktiven Formular). */
    public function formFor(string $courseCode): ?object
    {
        foreach ($this->forms(true) as $form) {
            if (in_array($courseCode, self::codes($form->course_codes), true)) {
                return $form;
            }
        }

        return null;
    }

    /** @return list<object> */
    public function forms(bool $activeOnly = false): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t('praxis_form')} WHERE status <> %s".($activeOnly ? ' AND active = 1' : '').' ORDER BY sort_order, id',
            EntityImporter::ARCHIVED
        ));
    }

    public function form(int $id): ?object
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('praxis_form')} WHERE id = %d", $id)) ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function fields(object $form): array
    {
        $fields = json_decode((string) $form->fields, true);

        return is_array($fields) ? $fields : [];
    }

    /** @return list<object> Praxisorte, die den Kurs anbieten (mit Prüfungsausschuss) */
    public function locations(?string $courseCode = null): array
    {
        $rows = $this->db->get_results(
            "SELECT l.*, c.commission AS committee_commission, c.location_type AS committee_location_type, c.street AS committee_street,
                    c.postal_code AS committee_postal_code, c.city AS committee_city, c.course_codes AS committee_course_codes
             FROM {$this->t('practice_location')} l LEFT JOIN {$this->t('exam_committee')} c ON c.id = l.committee_id ORDER BY l.sort_order, l.id"
        );

        return $courseCode === null ? $rows : array_values(array_filter($rows, static fn ($l) => in_array($courseCode, self::codes($l->course_codes), true)));
    }

    /** @return list<object> */
    public function committees(): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t('exam_committee')} WHERE status <> %s ORDER BY sort_order, id", EntityImporter::ARCHIVED));
    }

    public static function committeeLabel(object $c): string
    {
        return $c->location_type ? $c->commission.' — '.$c->location_type : $c->commission;
    }

    public static function address(?string $street, ?string $postal, ?string $city, ?string $country = null): ?string
    {
        $plzCity = trim(implode(' ', array_filter([$postal, $city])));
        $parts = array_filter([$street, $plzCity, $country]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** @return list<string> */
    public static function codes(?string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $csv))));
    }

    // ------------------------------------------------------------------ Profil (Adresse, Geburtsdatum)

    /** @return array<string,string> */
    public function profile(int $userId): array
    {
        $out = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $value = (string) get_user_meta($userId, self::META_PREFIX.$field, true);
            // Vorbelegung aus WooCommerce-Rechnungsadresse, falls vorhanden.
            if ($value === '' && $field !== 'birthdate') {
                $woo = ['street' => 'billing_address_1', 'postal_code' => 'billing_postcode', 'city' => 'billing_city', 'country' => 'billing_country'][$field];
                $value = (string) get_user_meta($userId, $woo, true);
            }
            $out[$field] = $value;
        }

        return $out;
    }

    public function profileComplete(int $userId): bool
    {
        foreach (self::PROFILE_FIELDS as $field) {
            if (trim((string) get_user_meta($userId, self::META_PREFIX.$field, true)) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $input */
    public function saveProfile(int $userId, array $input): array
    {
        $clean = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $value = trim(sanitize_text_field((string) ($input[$field] ?? '')));
            if ($value === '') {
                throw new RuntimeException(__('Bitte Adresse und Geburtsdatum vollständig angeben.', 'bootspruefung-learning'));
            }
            if ($field === 'birthdate') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (! $date || $date->format('Y-m-d') !== $value || $date > new \DateTimeImmutable('-10 years') || $date < new \DateTimeImmutable('-110 years')) {
                    throw new RuntimeException(__('Bitte ein gültiges Geburtsdatum angeben.', 'bootspruefung-learning'));
                }
            }
            $clean[$field] = mb_substr($value, 0, 190);
        }
        foreach ($clean as $field => $value) {
            update_user_meta($userId, self::META_PREFIX.$field, $value);
        }

        return $clean;
    }

    // ------------------------------------------------------------------ Kursfortschritt (wie CourseProgressService::exactPercent)

    public function progressPercent(int $userId, object $course): float
    {
        $ids = [];
        foreach ($this->content->modules((int) $course->id) as $module) {
            foreach ($this->content->questionsForModules([(int) $module->id]) as $row) {
                $ids[(int) $row->question_id] = true;
            }
        }
        if ($ids === []) {
            return 0.0;
        }
        $mastered = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->t('progress')} WHERE user_id = %d AND learn_status = 'mastered' AND question_id IN (".implode(',', array_keys($ids)).')',
            $userId
        ));

        return $mastered / count($ids) * 100;
    }

    // ------------------------------------------------------------------ Lernende

    /**
     * Zustand für die Seite „Praxis & Prüfung“.
     *
     * @return array<string,mixed>
     */
    public function state(int $userId, object $course): array
    {
        if (! self::enabled()) {
            return ['state' => 'disabled'];
        }
        if (! ContentGate::allows($userId, (string) $course->code, ContentGate::ANY)) {
            return ['state' => 'no_access'];
        }
        $registration = $this->registrationFor($userId, (string) $course->code);
        if ($registration !== null) {
            $form = $registration->form_id ? $this->form((int) $registration->form_id) : null;

            return ['state' => 'submitted', 'registration' => $this->registrationPayload($registration, $form)];
        }
        $form = $this->formFor((string) $course->code);
        if ($form === null) {
            return ['state' => 'unavailable'];
        }
        $percent = $this->progressPercent($userId, $course);
        $base = ['form' => ['title' => $form->title, 'min_progress_percent' => (int) $form->min_progress_percent, 'requires_fitness_certificate' => (bool) $form->requires_fitness_certificate]];
        if ($percent < (int) $form->min_progress_percent) {
            return $base + ['state' => 'locked', 'percent' => (int) round($percent), 'threshold' => (int) $form->min_progress_percent];
        }
        if (! $this->profileComplete($userId)) {
            return $base + ['state' => 'incomplete_profile', 'profile' => $this->profile($userId)];
        }
        $locations = $this->locations((string) $course->code);
        $fields = array_values(array_filter(self::fields($form), static fn ($f) => $f['type'] !== 'location' || $locations !== []));

        return $base + [
            'state' => 'form',
            'fields' => $fields,
            'locations' => array_map(fn ($l) => $this->locationPayload($l), $locations),
            'profile' => $this->profile($userId),
        ];
    }

    /**
     * Prüft und speichert die Anmeldung.
     *
     * @param array<string,mixed> $input
     */
    public function submit(int $userId, object $course, array $input): object
    {
        $state = $this->state($userId, $course);
        match ($state['state']) {
            'disabled', 'unavailable' => throw new NotFound(__('Die Praxis-Anmeldung ist für diesen Kurs nicht verfügbar.', 'bootspruefung-learning')),
            'no_access' => throw new AccessDenied(__('Für diesen Kurs liegt kein Zugang vor.', 'bootspruefung-learning')),
            'submitted' => throw new RuntimeException(__('Du hast dich für diesen Kurs bereits angemeldet.', 'bootspruefung-learning')),
            'locked' => throw new AccessDenied(sprintf(__('Für die Anmeldung werden mindestens %d %% Kursfortschritt benötigt.', 'bootspruefung-learning'), $state['threshold'])),
            'incomplete_profile' => throw new AccessDenied(__('Bitte vervollständige zuerst dein Profil (Adresse und Geburtsdatum).', 'bootspruefung-learning')),
            default => null,
        };
        $form = $this->formFor((string) $course->code);
        $locations = $this->locations((string) $course->code);
        $data = $this->resolve($form, $locations, $this->validate($form, $locations, $input));
        $now = current_time('mysql', true);
        $suppress = $this->db->suppress_errors(true);
        $ok = $this->db->insert($this->t('praxis_registration'), [
            'user_id' => $userId, 'course_code' => (string) $course->code, 'form_id' => (int) $form->id, 'form_title' => $form->title,
            'data' => wp_json_encode($data), 'status' => 'received', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->suppress_errors($suppress);
        if (! $ok) {
            throw new RuntimeException(__('Du hast dich für diesen Kurs bereits angemeldet.', 'bootspruefung-learning'));
        }
        $registration = $this->registration((int) $this->db->insert_id);
        AuditLog::record('praxis_registration_received', 'praxis_registration', (string) $registration->id, null, ['course' => $course->code]);
        $this->notifyAdmins($registration, $course);

        return $registration;
    }

    /**
     * Validierung je Feldtyp (wie PraxisFormDataResolver::rulesFor).
     *
     * @param list<object> $locations
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validate(object $form, array $locations, array $input): array
    {
        $out = [];
        $errors = [];
        foreach (self::fields($form) as $field) {
            $key = $field['key'];
            $raw = $input[$key] ?? null;
            $empty = $raw === null || $raw === '' || $raw === false;
            if ($field['type'] === 'location' && $locations === []) {
                continue; // ohne hinterlegte Orte entfällt das Feld
            }
            if ($field['type'] === 'checkbox') {
                $checked = in_array($raw, [true, 1, '1', 'on', 'true'], true);
                if (! empty($field['required']) && ! $checked) {
                    $errors[] = $field['label'];
                }
                $out[$key] = $checked;
                continue;
            }
            if ($empty) {
                if (! empty($field['required'])) {
                    $errors[] = $field['label'];
                }
                $out[$key] = null;
                continue;
            }
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $valid = match ($field['type']) {
                'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false,
                'select', 'radio' => in_array($value, $field['options'] ?? [], true),
                'location' => in_array((int) $value, array_map(static fn ($l) => (int) $l->id, $locations), true),
                'textarea' => mb_strlen($value) <= 2000,
                default => mb_strlen($value) <= 255,
            };
            if (! $valid) {
                $errors[] = $field['label'];
                continue;
            }
            $out[$key] = $field['type'] === 'location' ? (int) $value : sanitize_textarea_field($value);
        }
        if ($errors !== []) {
            throw new RuntimeException(sprintf(__('Bitte prüfe diese Angaben: %s', 'bootspruefung-learning'), implode(', ', array_unique($errors))));
        }

        return $out;
    }

    /**
     * Ortsfelder in lesbare Angaben auflösen (Praxis-, Prüfungs-, Theorieort, Ausschuss) –
     * wie PraxisFormDataResolver::resolveLocationFields.
     *
     * @param list<object> $locations
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function resolve(object $form, array $locations, array $data): array
    {
        foreach (self::fields($form) as $field) {
            if ($field['type'] !== 'location' || empty($data[$field['key']])) {
                continue;
            }
            $key = $field['key'];
            $location = null;
            foreach ($locations as $l) {
                if ((int) $l->id === (int) $data[$key]) {
                    $location = $l;
                }
            }
            if ($location === null) {
                continue;
            }
            $p = $this->locationPayload($location);
            $data[$key.'_location_id'] = (int) $location->id;
            $data[$key] = $p['name'];
            $data[$key.'_adresse'] = $p['address'];
            $data[$key.'_pruefungsort'] = $p['exam_name'];
            $data[$key.'_pruefungsort_adresse'] = $p['exam_address'];
            if ($p['committee'] !== null) {
                $data[$key.'_pruefungsausschuss'] = $p['committee']['label'];
                $data[$key.'_pruefungsausschuss_adresse'] = $p['committee']['address'];
                if ($p['committee']['courses'] !== []) {
                    $data[$key.'_pruefungsausschuss_kurse'] = $p['committee']['courses'];
                }
            }
            if ($p['theory_name'] !== null) {
                $data[$key.'_theorieort'] = $p['theory_name'];
                $data[$key.'_theorieort_adresse'] = $p['theory_address'];
            }
        }

        return $data;
    }

    /** @return array<string,mixed> */
    public function locationPayload(object $l): array
    {
        $courses = [];
        foreach (self::codes($l->committee_course_codes ?? '') as $code) {
            $course = $this->content->courseByCode($code);
            $courses[] = $course ? $course->name : $code;
        }

        return [
            'id' => (int) $l->id,
            'name' => $l->name,
            'address' => self::address($l->street, $l->postal_code, $l->city, $l->country),
            'exam_name' => $l->exam_name ?: $l->name,
            'exam_address' => self::address($l->exam_street, $l->exam_postal_code, $l->exam_city, $l->exam_country) ?? self::address($l->street, $l->postal_code, $l->city, $l->country),
            'theory_name' => $l->theory_name ?: null,
            'theory_address' => $l->theory_name ? self::address($l->theory_street, $l->theory_postal_code, $l->theory_city, $l->theory_country) : null,
            'lat' => $l->lat === null ? null : (float) $l->lat,
            'lng' => $l->lng === null ? null : (float) $l->lng,
            'committee' => $l->committee_id && $l->committee_commission ? [
                'label' => $l->committee_location_type ? $l->committee_commission.' — '.$l->committee_location_type : $l->committee_commission,
                'address' => self::address($l->committee_street, $l->committee_postal_code, $l->committee_city),
                'courses' => $courses,
            ] : null,
        ];
    }

    // ------------------------------------------------------------------ Anmeldungen

    public function registration(int $id): ?object
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('praxis_registration')} WHERE id = %d", $id)) ?: null;
    }

    public function registrationFor(int $userId, string $courseCode): ?object
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('praxis_registration')} WHERE user_id = %d AND course_code = %s", $userId, $courseCode)) ?: null;
    }

    /** @return array<string,mixed> Anzeige für Lernende/Admin: Feldbeschriftung → Wert */
    public function registrationPayload(object $registration, ?object $form): array
    {
        $data = json_decode((string) $registration->data, true) ?: [];
        $rows = [];
        foreach ($form ? self::fields($form) : [] as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            $rows[] = ['label' => $field['label'], 'value' => $field['type'] === 'checkbox' ? ($value ? __('ja', 'bootspruefung-learning') : __('nein', 'bootspruefung-learning')) : (string) $value];
            if ($field['type'] === 'location') {
                foreach (['_adresse' => __('Adresse', 'bootspruefung-learning'), '_pruefungsort' => __('Prüfungsort', 'bootspruefung-learning'), '_pruefungsort_adresse' => __('Adresse Prüfungsort', 'bootspruefung-learning'),
                    '_pruefungsausschuss' => __('Prüfungsausschuss', 'bootspruefung-learning'), '_pruefungsausschuss_adresse' => __('Adresse Prüfungsausschuss', 'bootspruefung-learning'),
                    '_theorieort' => __('Theorieort', 'bootspruefung-learning'), '_theorieort_adresse' => __('Adresse Theorieort', 'bootspruefung-learning')] as $suffix => $label) {
                    if (! empty($data[$key.$suffix])) {
                        $rows[] = ['label' => $label, 'value' => (string) $data[$key.$suffix]];
                    }
                }
            }
        }

        return [
            'id' => (int) $registration->id,
            'form_title' => $registration->form_title,
            'status' => $registration->status,
            'created_at' => $registration->created_at,
            'rows' => $rows,
            'requires_fitness_certificate' => $form ? (bool) $form->requires_fitness_certificate : false,
        ];
    }

    /**
     * @param array{course?:string,status?:string,search?:string} $filter
     * @return list<object>
     */
    public function search(array $filter = [], int $page = 1, int $perPage = 50): array
    {
        $where = ['1=1'];
        $args = [];
        if (($filter['course'] ?? '') !== '') {
            $where[] = 'r.course_code = %s';
            $args[] = $filter['course'];
        }
        if (($filter['status'] ?? '') !== '') {
            $where[] = 'r.status = %s';
            $args[] = $filter['status'];
        }
        if (($filter['search'] ?? '') !== '') {
            $like = '%'.$this->db->esc_like($filter['search']).'%';
            $where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s)';
            array_push($args, $like, $like);
        }
        $sql = "SELECT r.*, u.user_email, u.display_name FROM {$this->t('praxis_registration')} r LEFT JOIN {$this->db->users} u ON u.ID = r.user_id
                WHERE ".implode(' AND ', $where).' ORDER BY r.id DESC LIMIT %d OFFSET %d';

        return $this->db->get_results($this->db->prepare($sql, ...array_merge($args, [$perPage, ($page - 1) * $perPage])));
    }

    /**
     * Korrektur durch die Bootsschule (wie Admin\PraxisFormSubmissionController::update).
     *
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $input, ?string $status = null, ?string $note = null): void
    {
        $registration = $this->registration($id);
        $form = $registration && $registration->form_id ? $this->form((int) $registration->form_id) : null;
        if ($registration === null || $form === null) {
            throw new RuntimeException(__('Die Formularvorlage dieser Anmeldung wurde gelöscht und kann nicht mehr bearbeitet werden.', 'bootspruefung-learning'));
        }
        $locations = $this->locations((string) $registration->course_code);
        $data = $this->resolve($form, $locations, $this->validate($form, $locations, $input));
        $old = json_decode((string) $registration->data, true) ?: [];
        foreach (self::fields($form) as $field) {
            if ($field['type'] === 'location' && array_key_exists($field['key'], $data)) {
                $old = array_filter($old, static fn ($name) => $name !== $field['key'] && ! str_starts_with((string) $name, $field['key'].'_'), ARRAY_FILTER_USE_KEY);
            }
        }
        $update = ['data' => wp_json_encode(array_merge($old, $data)), 'updated_at' => current_time('mysql', true)];
        if ($status !== null && in_array($status, ['received', 'forwarded', 'done'], true)) {
            $update['status'] = $status;
        }
        if ($note !== null) {
            $update['admin_note'] = $note;
        }
        $this->db->update($this->t('praxis_registration'), $update, ['id' => $id]);
        AuditLog::record('praxis_registration_updated', 'praxis_registration', (string) $id, null, ['status' => $update['status'] ?? $registration->status]);
    }

    public function setStatus(int $id, string $status, ?string $note = null): void
    {
        if (! in_array($status, ['received', 'forwarded', 'done'], true)) {
            throw new RuntimeException('Ungültiger Status.');
        }
        $this->db->update($this->t('praxis_registration'), array_filter(['status' => $status, 'admin_note' => $note, 'updated_at' => current_time('mysql', true)], static fn ($v) => $v !== null), ['id' => $id]);
        AuditLog::record('praxis_registration_status', 'praxis_registration', (string) $id, $note, ['status' => $status]);
    }

    public function delete(int $id, string $reason): void
    {
        $registration = $this->registration($id);
        if ($registration === null) {
            return;
        }
        $this->db->delete($this->t('praxis_registration'), ['id' => $id]);
        AuditLog::record('praxis_registration_deleted', 'praxis_registration', (string) $id, $reason, ['course' => $registration->course_code]);
    }

    /** Mail an die Bootsschule (wie PraxisFormSubmissionReceived). */
    private function notifyAdmins(object $registration, object $course): void
    {
        $to = trim((string) Settings::get('praxis_notification_email'));
        $recipients = $to !== '' ? [$to] : array_map(static fn ($u) => $u->user_email, get_users(['capability' => Capabilities::MANAGE_ENTITLEMENTS, 'fields' => ['user_email']]));
        if ($recipients === []) {
            return;
        }
        $user = get_userdata((int) $registration->user_id);
        $name = $user ? $user->display_name : __('Ein Teilnehmer', 'bootspruefung-learning');
        wp_mail(
            $recipients,
            sprintf(__('Neue Praxis-Anmeldung: %s', 'bootspruefung-learning'), $course->name),
            sprintf(__("%1\$s hat sich für „%2\$s“ zu Praxis & Prüfung angemeldet.\n\nAnmeldung ansehen: %3\$s\n\nBitte melden Sie die Person bei der Prüfungskommission an.", 'bootspruefung-learning'),
                $name, $course->name, admin_url('admin.php?page=bootspruefung-praxis&registration='.(int) $registration->id))
        );
    }
}
