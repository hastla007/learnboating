<?php

declare(strict_types=1);

/*
 * Integrationstest Praxis-Anmeldung ohne Prüfungsbuchung: Freischaltung per
 * Einstellung, Zugang, Fortschrittsschwelle, Profil, Formularvalidierung,
 * Ortsauflösung mit Prüfungsausschuss, Admin-Korrektur, Reimport-Schutz.
 *
 *   WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php tests/integration/practice-test.php
 *
 * Voraussetzung: content-flow-test.php (Paket ≥ 1.3 importiert).
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$packageDir = rtrim((string) getenv('PACKAGE_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Content\Import\EntityImporter;
use Bootspruefung\Learning\Content\Package\Package;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Practice\PracticeRegistrationService as Service;
use Bootspruefung\Learning\Settings\Settings;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
$error = static function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
};
global $wpdb;
wp_set_current_user(1);
$p = $wpdb->prefix.'bp_';
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only', 'praxis_registration_enabled' => false, 'praxis_notification_email' => 'schule@example.test']));
$login = 'bp_praxis_lerner';
$u = get_user_by('login', $login);
$learner = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'display_name' => 'Paula Praxis', 'role' => 'subscriber']);
foreach (Service::PROFILE_FIELDS as $f) {
    delete_user_meta($learner, 'bp_profile_'.$f);
}
$wpdb->query("DELETE FROM {$p}praxis_registration WHERE user_id = {$learner}");
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id = {$learner}");
$wpdb->query("DELETE FROM {$p}progress WHERE user_id = {$learner}");
$wpdb->query("DELETE FROM {$p}practice_location WHERE name LIKE 'Test-Hafen%'");
$content = new ContentRepository();
$service = new Service($content);
$course = $content->courseByCode('SBF-SEE');
$fkn = $content->courseByCode('FKN');
$mails = [];
add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
    $mails[] = $atts;

    return true;
}, 10, 2);

echo "Inhalte aus dem Paket\n";
$check(count($service->committees()) === 18, '18 Prüfungsausschüsse importiert');
$check(count($service->forms()) === 5, '5 Formulare importiert');
$check($service->formFor('FKN')?->title === 'Anmeldung Pyroschein-Prüfung' && (int) $service->formFor('FKN')->min_progress_percent === 70, 'FKN: Pyroschein-Formular, 70 %');
$check((bool) $service->formFor('SBF-SEE')->requires_fitness_certificate && ! $service->formFor('SRC')->requires_fitness_certificate, 'Tauglichkeitshinweis nur bei Praxis-Formularen');

echo "Freischaltung\n";
$check($service->state($learner, $course)['state'] === 'disabled', 'Standard: deaktiviert');
wp_set_current_user($learner);
$check(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see/praxis-anmeldung'))->get_status() === 404, 'REST ohne Freischaltung: 404');
$check(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see'))->get_data()['praxis'] === null, 'Kursseite ohne Praxis-Kachel');
wp_set_current_user(1);
update_option(Settings::OPTION, array_merge(Settings::all(), ['praxis_registration_enabled' => true]));
$check($service->state($learner, $course)['state'] === 'no_access', 'freigeschaltet, aber ohne Kurszugang: kein Formular');
(new EntitlementService($wpdb))->grantManual($learner, 'SBF-SEE', ['all'], null, 'Praxis-Test');
$state = $service->state($learner, $course);
$check($state['state'] === 'locked' && $state['threshold'] === 50 && $state['percent'] === 0, 'Fortschritt unter 50 %: gesperrt');

echo "Fortschritt und Profil\n";
$ids = [];
foreach ($content->modules((int) $course->id) as $m) {
    foreach ($content->questionsForModules([(int) $m->id]) as $row) {
        $ids[(int) $row->question_id] = true;
    }
}
$ids = array_keys($ids);
$half = array_slice($ids, 0, (int) floor(count($ids) / 2) - 1);
foreach ($half as $qid) {
    $wpdb->insert($p.'progress', ['user_id' => $learner, 'question_id' => $qid, 'attempts' => 3, 'correct_count' => 3, 'streak' => 3, 'learn_status' => 'mastered']);
}
$check($service->state($learner, $course)['state'] === 'locked', 'knapp unter 50 %: weiterhin gesperrt (ungerundet)');
foreach (array_slice($ids, count($half), 3) as $qid) {
    $wpdb->insert($p.'progress', ['user_id' => $learner, 'question_id' => $qid, 'attempts' => 3, 'correct_count' => 3, 'streak' => 3, 'learn_status' => 'mastered']);
}
$check($service->state($learner, $course)['state'] === 'incomplete_profile', 'Schwelle erreicht: erst Profil vervollständigen');
$check($error(fn () => $service->saveProfile($learner, ['street' => 'Kai 1', 'postal_code' => '20457', 'city' => 'Hamburg', 'country' => 'DE', 'birthdate' => '2030-01-01'])) !== '', 'ungültiges Geburtsdatum abgewiesen');
$service->saveProfile($learner, ['street' => 'Kai 1', 'postal_code' => '20457', 'city' => 'Hamburg', 'country' => 'DE', 'birthdate' => '1990-05-17']);
$state = $service->state($learner, $course);
$check($state['state'] === 'form' && ! in_array('ort', array_column($state['fields'], 'key'), true), 'Formular; ohne Praxisorte entfällt das Ortsfeld');

echo "Praxisorte und Prüfungsausschuss\n";
$committee = $service->committees()[1];
$wpdb->update($p.'exam_committee', ['course_codes' => 'SBF-SEE,SBF-BIN-MOTOR'], ['id' => $committee->id]);
$wpdb->insert($p.'practice_location', ['name' => 'Test-Hafen Nord', 'street' => 'Kai 5', 'postal_code' => '20457', 'city' => 'Hamburg', 'exam_name' => 'Prüfstelle Elbe', 'exam_city' => 'Hamburg',
    'theory_name' => 'Schulungsraum', 'theory_city' => 'Hamburg', 'committee_id' => $committee->id, 'course_codes' => 'SBF-SEE,SBF-BIN-MOTOR', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]);
$locationId = (int) $wpdb->insert_id;
$wpdb->insert($p.'practice_location', ['name' => 'Test-Hafen Süd', 'course_codes' => 'SBF-BIN-SEGEL', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]);
$state = $service->state($learner, $course);
$check(in_array('ort', array_column($state['fields'], 'key'), true) && count($state['locations']) === 1 && $state['locations'][0]['committee']['label'] === Service::committeeLabel($committee), 'Ortsfeld mit passenden Orten und Ausschuss');

echo "Anmeldung\n";
$valid = ['ort' => $locationId, 'wunschdatum' => '2027-05-01', 'geburtsort' => 'Kiel', 'nationalitaet' => 'Deutsch', 'telefonnummer' => '0401234', 'geschlecht' => 'Weiblich', 'tauglichkeit_vorhanden' => true, 'vorlaeufiger_fuehrerschein' => false];
$msg = $error(fn () => $service->submit($learner, $course, array_merge($valid, ['geburtsort' => '', 'geschlecht' => 'Unbekannt'])));
$check(str_contains($msg, 'Geburtsort') && str_contains($msg, 'Geschlecht'), 'Pflichtfeld und ungültige Option abgewiesen');
$check(str_contains($error(fn () => $service->submit($learner, $course, array_merge($valid, ['ort' => 999999]))), 'Ort'), 'fremder Ort abgewiesen');
$registration = $service->submit($learner, $course, $valid);
$data = json_decode((string) $registration->data, true);
$check($data['ort'] === 'Test-Hafen Nord' && $data['ort_location_id'] === $locationId && $data['ort_pruefungsort'] === 'Prüfstelle Elbe' && $data['ort_theorieort'] === 'Schulungsraum', 'Ort aufgelöst (Praxis-, Prüfungs-, Theorieort)');
$check($data['ort_pruefungsausschuss'] === Service::committeeLabel($committee) && $data['ort_pruefungsausschuss_kurse'] === ['SBF See', 'SBF Binnen'], 'Prüfungsausschuss mit Kursen übernommen');
$check(count($mails) === 1 && $mails[0]['to'] === ['schule@example.test'] && str_contains($mails[0]['subject'], 'SBF See'), 'Benachrichtigung an die Bootsschule');
$check($service->state($learner, $course)['state'] === 'submitted', 'Zustand: eingegangen');
$check(str_contains($error(fn () => $service->submit($learner, $course, $valid)), 'bereits'), 'zweite Anmeldung für denselben Kurs abgewiesen');
wp_set_current_user($learner);
$payload = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see/praxis-anmeldung'))->get_data();
$labels = array_column($payload['registration']['rows'] ?? [], 'label');
$check(in_array('Prüfungsausschuss', $labels, true) && in_array('Telefonnummer', $labels, true), 'REST zeigt die eingegangene Anmeldung');
$check(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see'))->get_data()['praxis']['title'] === 'Praxis & Prüfung Motorboot', 'Kursseite mit Praxis-Kachel');
$fknState = $service->state($learner, $fkn);
$check($fknState['state'] === 'no_access', 'anderer Kurs ohne Zugang (FKN)');
wp_set_current_user(1);

echo "Bootsschule\n";
$service->update((int) $registration->id, array_merge($valid, ['telefonnummer' => '0409999', 'ort' => $locationId]));
$data = json_decode((string) $service->registration((int) $registration->id)->data, true);
$check($data['telefonnummer'] === '0409999' && $data['ort'] === 'Test-Hafen Nord', 'Korrektur durch die Bootsschule');
$service->setStatus((int) $registration->id, 'forwarded', 'an PA gemeldet');
$check($service->registration((int) $registration->id)->status === 'forwarded', 'Status „an Prüfungsausschuss gemeldet“');
$check(count($service->search(['course' => 'SBF-SEE', 'search' => 'Paula'])) === 1, 'Suche in der Anmeldungsliste');
$service->delete((int) $registration->id, 'Test');
$check($service->state($learner, $course)['state'] === 'form', 'gelöscht: erneute Anmeldung möglich');

echo "Reimport schützt lokale Änderungen\n";
$wpdb->update($p.'exam_committee', ['phone' => '+49 000 LOKAL', 'locally_modified' => 1], ['id' => $committee->id]);
$wpdb->update($p.'praxis_form', ['source_hash' => null], ['source_id' => 'praxis-und-pruefung-motorboot']);
$package = new Package($packageDir);
$importer = new EntityImporter($wpdb, $package, 'test', false);
$record = array_values(array_filter($package->entities('exam_committees'), static fn ($c) => $c['source_id'] === $committee->source_id))[0];
$record['hash'] = 'sha256:'.str_repeat('0', 64);
$check($importer->import('exam_committees', $record) === 'unchanged' && $wpdb->get_var("SELECT phone FROM {$p}exam_committee WHERE id = {$committee->id}") === '+49 000 LOKAL', 'lokal geänderter Ausschuss bleibt erhalten');
$form = array_values(array_filter($package->entities('praxis_forms'), static fn ($f) => $f['source_id'] === 'praxis-und-pruefung-motorboot'))[0];
$check($importer->import('praxis_forms', $form) === 'update', 'nicht geändertes Formular wird aktualisiert');

// Aufräumen
$wpdb->update($p.'exam_committee', ['phone' => $record['phone'], 'course_codes' => '', 'locally_modified' => 0], ['id' => $committee->id]);
$wpdb->query("DELETE FROM {$p}practice_location WHERE name LIKE 'Test-Hafen%'");
$wpdb->query("DELETE FROM {$p}praxis_registration WHERE user_id = {$learner}");
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id = {$learner}");
$wpdb->query("DELETE FROM {$p}progress WHERE user_id = {$learner}");
update_option(Settings::OPTION, $settingsBefore);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
