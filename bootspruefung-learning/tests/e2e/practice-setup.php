<?php

declare(strict_types=1);

// Vorbereitung für practice-flow.mjs (nach setup.php): Praxis-Anmeldung kurzzeitig freischalten,
// lerner1 mit ausreichendem Fortschritt, ein Praxisort. Mit „reset“ wieder deaktivieren.
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim((string) getenv('WP_DIR'), '/').'/wp-load.php';

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Practice\PracticeRegistrationService;
use Bootspruefung\Learning\Settings\Settings;

global $wpdb;
$p = $wpdb->prefix.'bp_';
$learner = (int) get_user_by('login', 'lerner1')->ID;
$wpdb->query("DELETE FROM {$p}praxis_registration WHERE user_id = {$learner}");
$wpdb->query("DELETE FROM {$p}practice_location WHERE name = 'E2E-Hafen'");
foreach (PracticeRegistrationService::PROFILE_FIELDS as $f) {
    delete_user_meta($learner, 'bp_profile_'.$f);
}
if (($argv[1] ?? '') === 'reset') {
    update_option(Settings::OPTION, array_merge(Settings::all(), ['praxis_registration_enabled' => false]));
    $wpdb->query("DELETE FROM {$p}progress WHERE user_id = {$learner}");
    echo "Praxis-Setup zurückgesetzt.\n";
    exit(0);
}
update_option(Settings::OPTION, array_merge(Settings::all(), ['praxis_registration_enabled' => true, 'default_access' => 'logged_in']));
$content = new ContentRepository();
$course = $content->courseByCode('SBF-SEE');
foreach ($content->modules((int) $course->id) as $m) {
    foreach ($content->questionsForModules([(int) $m->id]) as $row) {
        $wpdb->query($wpdb->prepare("INSERT INTO {$p}progress (user_id, question_id, attempts, correct_count, streak, learn_status) VALUES (%d, %d, 3, 3, 3, 'mastered') ON DUPLICATE KEY UPDATE learn_status = 'mastered'", $learner, $row->question_id));
    }
}
$committee = $wpdb->get_var("SELECT id FROM {$p}exam_committee ORDER BY sort_order LIMIT 1");
$now = current_time('mysql', true);
$wpdb->insert($p.'practice_location', ['name' => 'E2E-Hafen', 'street' => 'Kai 1', 'postal_code' => '20457', 'city' => 'Hamburg', 'exam_name' => 'Prüfstelle Elbe', 'committee_id' => $committee, 'course_codes' => 'SBF-SEE', 'created_at' => $now, 'updated_at' => $now]);
echo "Praxis-Setup fertig.\n";
