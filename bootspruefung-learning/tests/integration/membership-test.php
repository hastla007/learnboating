<?php

declare(strict_types=1);

/*
 * Integrationstest Membership-Funktionen: Teilumfänge kostenlos, zeitgesteuerte
 * Freigabe, geschützte Seiten/Abschnitte/Dateien und Gruppenlizenzen.
 * Mitgliederrabatte werden in woocommerce-test.php gegen echtes WooCommerce geprüft.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/membership-test.php
 *
 * Voraussetzung: content-flow-test.php (Content veröffentlicht).
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once __DIR__.'/support/FakeOrders.php';

use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Commerce\OrderItemSnapshot;
use Bootspruefung\Learning\Commerce\OrderSync;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Exams\Services\ExamService;
use Bootspruefung\Learning\Gate\ContentGate;
use Bootspruefung\Learning\Gate\ProtectedFiles;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\CollectionService;
use Bootspruefung\Learning\Learning\Services\LearningService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Tests\FakeOrders;
use Bootspruefung\Learning\Trainers\RadioService;
use Bootspruefung\Learning\Trainers\TrainerService;
use Bootspruefung\Learning\Trainers\VideoService;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
$denied = static function (callable $fn): bool {
    try {
        $fn();
    } catch (AccessDenied) {
        return true;
    }

    return false;
};
$invalidRt = static function (callable $fn): string {
    try {
        $fn();
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
};
$invalid = static function (callable $fn): string {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return '';
};

global $wpdb;
wp_set_current_user(1);
$p = $wpdb->prefix.'bp_';
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));
// Trainerinhalte für den Test rechtlich freigeben (wie E2E-Setup).
foreach (['navigation_task', 'praxis_task', 'video_module', 'media'] as $type) {
    $ids = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}{$type} WHERE rights_status = 'unknown'"));
    if ($ids !== []) {
        Overrides::set($type, $ids, 'rights_status', 'licensed', 'Membership-Test');
    }
}
$user = static function (string $login): int {
    $u = get_user_by('login', $login);

    return $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'subscriber']);
};
$free = $user('bp_free_user');
$buyer = $user('bp_school_owner');
$students = [$user('bp_student_1'), $user('bp_student_2'), $user('bp_student_3'), $user('bp_student_4')];
$all = implode(',', array_merge([$free, $buyer], $students));
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$all}) OR order_id >= 910000");
$wpdb->query("DELETE FROM {$p}access_rule WHERE course_code IN ('SBF-SEE','SRC')");
$wpdb->query("DELETE FROM {$p}learning_group_member WHERE group_id IN (SELECT id FROM {$p}learning_group WHERE order_id >= 910000)");
$wpdb->query("DELETE FROM {$p}learning_group WHERE order_id >= 910000");
$wpdb->query("DELETE FROM {$p}product_access WHERE product_id >= 991000");
foreach (['favorite_collection', 'progress', 'favorite', 'navigation_progress', 'praxis_progress', 'video_progress', 'radio_progress', 'exam_session'] as $table) {
    $wpdb->query("DELETE FROM {$p}{$table} WHERE user_id IN ({$all})");
}

$content = new ContentRepository();
$presenter = new QuestionPresenter($content);
$access = new AccessService();
$learning = new LearningService($content, $presenter, $access);
$exams = new ExamService($content, $presenter, $access);
$trainers = new TrainerService($access);
$videos = new VideoService($access, $content, $presenter);
$radio = new RadioService($access);
$entitlements = new EntitlementService($wpdb);
$course = $content->courseByCode('SBF-SEE');
$src = $content->courseByCode('SRC');
$rule = static function (string $course, string $feature, string $type, ?int $value, ?string $module = null) use ($wpdb, $p): void {
    $now = current_time('mysql', true);
    $wpdb->insert($p.'access_rule', ['course_code' => $course, 'feature_code' => $feature, 'module_code' => $module, 'access_type' => $type,
        'limit_type' => $type === 'free_limited' ? AccessService::LIMIT_TYPES[$feature] : ($type === 'drip' ? 'days_after_start' : null), 'limit_value' => $value, 'created_at' => $now, 'updated_at' => $now]);
};

echo "Teilumfang kostenlos\n";
$check(! $access->can($free, 'SBF-SEE', 'question_bank'), 'ohne Regel kein Zugriff');
$rule('SBF-SEE', 'question_bank', 'free_limited', 3);
$check($access->can($free, 'SBF-SEE', 'question_bank') && $access->limit($free, 'SBF-SEE', 'question_bank') === 3 && ! $access->full($free, 'SBF-SEE', 'question_bank'), 'Teilumfang: 3 Fragen je Modul');
$progress = $learning->courseProgress($free, $course);
$check($progress['limit'] === 3 && array_unique(array_map(static fn ($m) => $m['available'], $progress['modules'])) === [3] && $progress['modules'][0]['catalog'] > 3, 'Kursfortschritt zählt nur den kostenlosen Umfang');
$allowed = array_keys($learning->allowedQuestions($free, $course, array_map(static fn ($m) => $m['id'], $progress['modules'])));
$seen = [];
for ($i = 0; $i < 4; $i++) {
    $next = $learning->next($free, $course, null, 'smart');
    $seen[] = $next['question_id'];
}
$check(array_diff($seen, $allowed) === [], 'Smarttrainer zeigt nur Fragen aus dem Teilumfang');
$topicsOk = true;
$topicLearn = null;
foreach ($progress['modules'] as $m) {
    if ($m['topics'] !== []) {
        $sum = array_sum(array_map(static fn ($tp) => $tp['questions'], $m['topics']));
        // Fragen ohne eigenes Thema (leer oder gleich dem Modulnamen) zählen nur zum Modul.
        $topicsOk = $topicsOk && $sum > 0 && $sum <= $m['questions'];
        $topicLearn ??= [$m['id'], $m['topics'][0]['name']];
    }
}
$check($topicsOk, 'Themen eines Moduls ergeben zusammen den Modulumfang');
if ($topicLearn !== null) {
    $rows = $learning->allowedQuestions($free, $course, [$topicLearn[0]]);
    $q = $learning->next($free, $course, $topicLearn[0], 'smart', $topicLearn[1]);
    $check($q !== null && (string) $rows[$q['question_id']]->topic === $topicLearn[1], 'Lernen nach Thema zeigt nur Fragen des Themas');
}
$check($learning->next($free, $course, (int) $progress['modules'][0]['id'], 'smart', 'Kein solches Thema') === null, 'unbekanntes Thema liefert keine Frage');

echo "Favoriten & Fehler: Sammlungen\n";
$collections = new CollectionService($learning, $content);
$col = $collections->create($free, $course, '  Notverkehr  ');
$check($col['name'] === 'Notverkehr' && $col['id'] > 0, 'Sammlung angelegt (Name bereinigt)');
$check($invalidRt(fn () => $collections->create($free, $course, 'notverkehr')) !== '', 'doppelter Name abgewiesen');
$check($invalidRt(fn () => $collections->create($free, $course, '   ')) !== '', 'leerer Name abgewiesen');
[$q1, $q2] = array_slice($allowed, 0, 2);
$check($invalidRt(fn () => $collections->assign($free, $course, $q1, false, [$col['id']])) !== '', 'nur Favoriten lassen sich einsortieren');
$collections->assign($free, $course, $q1, true, [$col['id']]);
$collections->assign($free, $course, $q2, true, []);
$ov = $collections->overview($free, $course);
$byId = array_column($ov['items'], null, 'question_id');
$check(isset($byId[$q1]) && $byId[$q1]['collections'] === [$col['id']] && $byId[$q2]['favorite'] && $byId[$q2]['collections'] === [] && $ov['collections'][0]['count'] === 1, 'Übersicht: Favoriten mit Sammlungen');
$learnt = $learning->next($free, $course, null, 'favorites', null, $collections->questionIds($free, $course, $col['id']));
$check($learnt !== null && $learnt['question_id'] === $q1, 'Lernen nach Sammlung');
$outside = (int) $wpdb->get_var("SELECT mq.question_id FROM {$p}module_question mq JOIN {$p}course_module cm ON cm.module_id = mq.module_id WHERE cm.course_id = ".(int) $course->id.' AND mq.question_id NOT IN ('.implode(',', $allowed).') LIMIT 1');
$check($invalidRt(fn () => $collections->assign($free, $course, $outside, true, [])) !== '', 'Frage außerhalb des Teilumfangs abgewiesen');
$check($invalidRt(fn () => $collections->rename($buyer, $course, $col['id'], 'X')) !== '' && $invalidRt(fn () => $collections->assign($buyer, $course, $q1, false, [$col['id']])) !== '', 'fremde Sammlung nicht nutzbar');
$learning->setFavorite($free, $q1, false);
$check($collections->questionIds($free, $course, $col['id']) === [], 'aus Favoriten entfernt = aus Sammlungen entfernt');
$collections->rename($free, $course, $col['id'], 'Seenot');
$collections->delete($free, $course, $col['id']);
$check($collections->collections($free, $course) === [] && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}favorite_collection_item WHERE collection_id = ".(int) $col['id']) === 0, 'Sammlung gelöscht, Zuordnungen entfernt');
$collections->assign($free, $course, $q2, false, []);

$rule('SBF-SEE', 'exam_simulation', 'free_limited', 2);
$overview = $exams->overview($free, $course);
$check($overview['simulation_locked'] && ! $overview['papers'][1]['locked'] && $overview['papers'][2]['locked'], 'Prüfung: erste 2 Bögen frei, Zufallsprüfung gesperrt');
$check($denied(fn () => $exams->start($free, $course, null)) && $denied(fn () => $exams->start($free, $course, $overview['papers'][2]['id'])), 'Start gesperrter Bögen/Zufallsprüfung abgewiesen');
$check($exams->start($free, $course, $overview['papers'][0]['id']) > 0, 'freier Bogen startet');

$rule('SBF-SEE', 'navigation_trainer', 'free_limited', 2);
$tasks = $trainers->navigationTasks($free, $course);
$check(! $tasks[1]['locked'] && $tasks[2]['locked'] && $tasks[2]['teaser'] === null, 'Navigation: erste 2 Aufgaben, Rest ohne Inhalt');
$check($denied(fn () => $trainers->navigationTask($free, $course, $tasks[2]['id'])) && $trainers->navigationTask($free, $course, $tasks[0]['id'])['id'] === $tasks[0]['id'], 'gesperrte Aufgabe abgewiesen, freie geladen');

$rule('SBF-SEE', 'praxis_trainer', 'free_limited', 5);
$cards = array_merge(...array_map(static fn ($c) => $c['cards'], $trainers->praxis($free, $course)['categories']));
$open = array_values(array_filter($cards, static fn ($c) => ! $c['locked']));
$locked = array_values(array_filter($cards, static fn ($c) => $c['locked']));
$check(count($open) === 5 && ! isset($locked[0]['answer']), 'Praxis: 5 Karten, gesperrte ohne Antwort');
$check($denied(fn () => $trainers->praxisStatus($free, $course, $locked[0]['id'], 'known')), 'Status für gesperrte Karte abgewiesen');

$rule('SBF-SEE', 'video_course', 'free_limited', 2);
$lessons = array_merge(...array_map(static fn ($m) => $m['lessons'], $videos->course($free, $course)['modules']));
$check(count(array_filter($lessons, static fn ($l) => ! $l['locked'])) === 2, 'Video: erste 2 Lektionen');
$check($denied(fn () => $videos->lesson($free, $course, $lessons[2]['id'])) && $videos->lesson($free, $course, $lessons[0]['id'])['id'] === $lessons[0]['id'], 'gesperrte Lektion abgewiesen');

$rule('SRC', 'radio_training', 'free_limited', 1);
$groups = $radio->overview($free, $src)['texts'];
$check($groups !== [] && ! $groups[0]['items'][0]['locked'] && (count($groups[0]['items']) < 2 || $groups[0]['items'][1]['locked']), 'Funk: erster Text je Übungsart');
if (isset($groups[0]['items'][1])) {
    $check($denied(fn () => $radio->text($free, $src, $groups[0]['items'][1]['id'])), 'gesperrter Funktext abgewiesen');
}
$check(! $access->can($free, 'SRC', 'device_simulation'), 'Gerätesimulation ohne Teilumfang');

$entitlements->grantManual($free, 'SBF-SEE', ['all'], null, 'Test Vollzugang');
$check($access->limit($free, 'SBF-SEE', 'question_bank') === null && ! $exams->overview($free, $course)['simulation_locked'], 'Vollzugang hebt Teilumfang auf');

echo "Zeitgesteuerte Freigabe\n";
$module = $content->modules((int) $course->id)[1];
$rule('SBF-SEE', 'question_bank', 'drip', 7, $module->code);
$gates = $learning->moduleGates($free, $course);
$check($gates[(int) $module->id]['locked'] && substr((string) $gates[(int) $module->id]['unlock_at'], 0, 10) === gmdate('Y-m-d', time() + 7 * DAY_IN_SECONDS), 'Modul 2 erst nach 7 Tagen ab Zugangsbeginn');
$check($denied(fn () => $learning->next($free, $course, (int) $module->id, 'smart')), 'gesperrtes Modul abgewiesen');
$check(! in_array((int) $module->id, array_map(static fn ($q) => (int) $q->module_id, $learning->allowedQuestions($free, $course, array_keys($gates))), true), 'Smarttrainer ohne Fragen aus gesperrtem Modul');
$wpdb->query($wpdb->prepare("UPDATE {$p}entitlement SET valid_from = %s WHERE user_id = %d", gmdate('Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS), $free));
$check(! $learning->moduleGates($free, $course)[(int) $module->id]['locked'], 'nach 8 Tagen freigegeben');
$videoModule = $wpdb->get_row($wpdb->prepare("SELECT id, source_id FROM {$p}video_module WHERE course_id = %d AND status = 'published' ORDER BY sort_order LIMIT 1", $course->id));
$rule('SBF-SEE', 'video_course', 'drip', 30, $videoModule->source_id);
$vm = array_values(array_filter($videos->course($free, $course)['modules'], static fn ($m) => $m['id'] === (int) $videoModule->id))[0];
$check($vm['locked'] && ! array_filter($vm['lessons'], static fn ($l) => ! $l['locked']), 'Videokapitel zeitgesteuert gesperrt');
$check($denied(fn () => $videos->lesson($free, $course, $vm['lessons'][0]['id'])), 'Lektion im gesperrten Kapitel abgewiesen');
$newcomer = $user('bp_free_newcomer');
wp_update_user(['ID' => $newcomer, 'user_registered' => gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS)]);
$check($access->unlocked($newcomer, 'SBF-SEE', 'video_course', $videoModule->source_id), 'ohne Kauf: Beginn = Registrierung');

echo "Geschützte Seiten und Abschnitte\n";
$secret = 'Geheimer Inhalt '.wp_generate_password(8, false);
$postId = wp_insert_post(['post_title' => 'Lernseite Test', 'post_content' => $secret, 'post_status' => 'publish', 'post_type' => 'page']);
update_post_meta($postId, ContentGate::META, ['course' => 'SBF-SEE', 'feature' => 'exam_simulation']);
$render = static function (int $userId) use ($postId): string {
    wp_set_current_user($userId);
    $GLOBALS['post'] = get_post($postId);
    setup_postdata($GLOBALS['post']);

    return (string) apply_filters('the_content', get_post_field('post_content', $postId));
};
$stranger = $students[3];
$check(! str_contains($render($stranger), $secret) && str_contains($render($stranger), 'Lernzugang'), 'ohne Zugang: Hinweis statt Inhalt');
$check(str_contains($render($free), $secret), 'mit Zugang: Inhalt sichtbar');
$check(str_contains($render(1), $secret), 'Redakteur sieht Inhalt');
wp_set_current_user($stranger);
$rest = rest_do_request(new WP_REST_Request('GET', '/wp/v2/pages/'.$postId));
$check(! str_contains(wp_json_encode($rest->get_data()), $secret), 'REST-API liefert geschützten Inhalt nicht aus');
$check(! str_contains((string) get_the_excerpt($postId), $secret), 'kein automatischer Auszug aus geschütztem Text');
$short = do_shortcode('[bootspruefung_zugang kurs="SBF-SEE" funktion="video_course"]'.$secret.'[/bootspruefung_zugang]');
$check(! str_contains($short, $secret), 'Shortcode sperrt Abschnitt');
$block = do_blocks('<!-- wp:bootspruefung/zugang {"course":"SBF-SEE","feature":"video_course"} --><p>'.$secret.'</p><!-- /wp:bootspruefung/zugang -->');
$check(! str_contains($block, $secret) && str_contains($block, 'bp-gate-notice'), 'Block sperrt Abschnitt');
wp_set_current_user($free);
$GLOBALS['post'] = null;
$check(str_contains(do_blocks('<!-- wp:bootspruefung/zugang {"course":"SBF-SEE","feature":"video_course"} --><p>'.$secret.'</p><!-- /wp:bootspruefung/zugang -->'), $secret), 'Block mit Zugang sichtbar');
wp_set_current_user(1);
wp_delete_post($postId, true);

echo "Geschützte Dateien\n";
$uploads = wp_upload_dir();
$file = $uploads['path'].'/bp-test-'.wp_generate_password(6, false).'.pdf';
file_put_contents($file, "%PDF-1.4\n% Test\n");
$attachment = wp_insert_attachment(['post_title' => 'Test-PDF', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $file);
update_post_meta($attachment, '_wp_attached_file', _wp_relative_upload_path($file));
ProtectedFiles::protect($attachment, 'SBF-SEE', 'question_bank');
$protected = get_attached_file($attachment);
$check(! is_file($file) && is_file($protected) && str_contains($protected, '/protected-'), 'Datei in gesperrten Ordner verschoben');
$check(is_file(dirname($protected, 2).'/.htaccess') && str_contains((string) file_get_contents(dirname($protected, 2).'/.htaccess'), 'Require all denied'), 'gesperrter Ordner mit .htaccess');
$check(wp_get_attachment_url($attachment) === ProtectedFiles::downloadUrl($attachment), 'Mediathek-URL zeigt auf geprüfte Auslieferung');
$check(ContentGate::allows($free, 'SBF-SEE', 'question_bank') && ! ContentGate::allows($stranger, 'SBF-SEE', 'question_bank'), 'Zugriffsprüfung für Download');
ProtectedFiles::unprotect($attachment);
$check(is_file($file) && wp_get_attachment_url($attachment) !== ProtectedFiles::downloadUrl($attachment), 'Schutz aufgehoben: Datei zurück');
wp_delete_attachment($attachment, true);

echo "Downloads\n";
$pdfs = [];
foreach (['A-Merkblatt', 'B-Lichterführung', 'C-Schallsignale'] as $title) {
    $path = $uploads['path'].'/bp-dl-'.wp_generate_password(6, false).'.pdf';
    file_put_contents($path, "%PDF-1.4\n% {$title}\n");
    $id = wp_insert_attachment(['post_title' => $title, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $path);
    update_post_meta($id, '_wp_attached_file', _wp_relative_upload_path($path));
    ProtectedFiles::protect($id, 'SBF-SEE', 'downloads');
    $pdfs[] = $id;
}
$check(\Bootspruefung\Learning\Gate\DownloadService::items($stranger, 'SBF-SEE')[0]['locked'] && ! \Bootspruefung\Learning\Gate\DownloadService::allowed($stranger, $pdfs[0]), 'ohne Zugang: Downloads gelistet, aber gesperrt');
$rule('SBF-SEE', 'downloads', 'free_limited', 1);
$items = \Bootspruefung\Learning\Gate\DownloadService::items($stranger, 'SBF-SEE');
$check(count($items) === 3 && ! $items[0]['locked'] && $items[0]['title'] === 'A-Merkblatt' && $items[1]['locked'] && $items[1]['url'] === null, 'Teilumfang: erste Datei frei');
$check(\Bootspruefung\Learning\Gate\DownloadService::allowed($stranger, $pdfs[0]) && ! \Bootspruefung\Learning\Gate\DownloadService::allowed($stranger, $pdfs[2]), 'Auslieferung nur für freie Datei');
$check(\Bootspruefung\Learning\Gate\DownloadService::allowed($free, $pdfs[2]), 'Vollzugang: alle Dateien');
wp_set_current_user($free);
$payload = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see'))->get_data();
$check(count($payload['downloads'] ?? []) === 3 && str_contains((string) $payload['downloads'][0]['url'], '/bootsscheine/datei/'), 'Kursdaten enthalten die Downloadliste');
// PDFs zu Lernbereichen (Navigationsaufgaben, Prüfungsbögen): nur mit vollem Zugang
$sectionPath = $uploads['path'].'/bp-nav-'.wp_generate_password(6, false).'.pdf';
file_put_contents($sectionPath, "%PDF-1.4\n% Navigationsaufgaben\n");
$sectionPdf = wp_insert_attachment(['post_title' => 'Navigationsaufgaben (PDF)', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit', 'post_excerpt' => 'Alle Aufgaben zum Ausdrucken'], $sectionPath);
update_post_meta($sectionPdf, '_wp_attached_file', _wp_relative_upload_path($sectionPath));
ProtectedFiles::protect((int) $sectionPdf, 'SBF-SEE', 'navigation_trainer');
$sf = \Bootspruefung\Learning\Gate\SectionFiles::forCourse($stranger, 'SBF-SEE');
$check(isset($sf['navigation_trainer'][0]) && $sf['navigation_trainer'][0]['locked'] && $sf['navigation_trainer'][0]['url'] === null && ! \Bootspruefung\Learning\Gate\DownloadService::allowed($stranger, (int) $sectionPdf), 'Bereichs-PDF ohne Zugang: gelistet, gesperrt, nicht ausgeliefert');
$sf = \Bootspruefung\Learning\Gate\SectionFiles::forCourse($free, 'SBF-SEE');
$check(! $sf['navigation_trainer'][0]['locked'] && str_contains((string) $sf['navigation_trainer'][0]['url'], '/bootsscheine/datei/') && $sf['navigation_trainer'][0]['description'] === 'Alle Aufgaben zum Ausdrucken', 'Bereichs-PDF mit Vollzugang: Link');
$check(! in_array((int) $sectionPdf, array_column(\Bootspruefung\Learning\Gate\DownloadService::items($free, 'SBF-SEE'), 'id'), true), 'Bereichs-PDF nicht in der Downloadliste');
wp_delete_attachment((int) $sectionPdf, true);
$vocPath = $uploads['path'].'/bp-voc-'.wp_generate_password(6, false).'.pdf';
file_put_contents($vocPath, "%PDF-1.4\n% Vokabeln\n");
$vocPdf = wp_insert_attachment(['post_title' => 'Vokabeln (PDF)', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $vocPath);
update_post_meta($vocPdf, '_wp_attached_file', _wp_relative_upload_path($vocPath));
ProtectedFiles::protect((int) $vocPdf, 'SRC', 'radio_training');
update_post_meta($vocPdf, \Bootspruefung\Learning\Gate\SectionFiles::SLOT_META, 'vocabulary');
$combo = \Bootspruefung\Learning\Gate\SectionFiles::forCourse($free, 'SRC-UBI')['radio_training'] ?? [];
$check(in_array((int) $vocPdf, array_column($combo, 'id'), true) && $combo[array_search((int) $vocPdf, array_column($combo, 'id'), true)]['slot'] === 'vocabulary', 'Kombikurs zeigt PDFs der Teilkurse, Vokabelseite als Anzeigeort');
$check(! in_array((int) $vocPdf, array_column(\Bootspruefung\Learning\Gate\SectionFiles::forCourse($free, 'SBF-SEE')['radio_training'] ?? [], 'id'), true), 'fremder Kurs zeigt die Datei nicht');
$addUser = $user('bp_add_ubi');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $addUser));
$ubiPath = $uploads['path'].'/bp-ubi-'.wp_generate_password(6, false).'.pdf';
file_put_contents($ubiPath, "%PDF-1.4\n% UBI\n");
$ubiPdf = wp_insert_attachment(['post_title' => 'UBI-Prüfungsbögen (PDF)', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $ubiPath);
update_post_meta($ubiPdf, '_wp_attached_file', _wp_relative_upload_path($ubiPath));
ProtectedFiles::protect((int) $ubiPdf, 'UBI', 'radio_training');
$check(! \Bootspruefung\Learning\Gate\DownloadService::allowed($addUser, (int) $ubiPdf), 'Ergänzung ohne Kauf: UBI-PDF gesperrt');
$now = current_time('mysql', true);
$wpdb->insert($p.'entitlement', ['user_id' => $addUser, 'course_code' => 'ADD-UBI-TO-SRC', 'feature_code' => 'all', 'status' => 'active', 'valid_from' => $now, 'source' => 'manual', 'reference_key' => 'add-ubi-'.$addUser, 'created_at' => $now, 'updated_at' => $now]);
$addFiles = \Bootspruefung\Learning\Gate\SectionFiles::forCourse($addUser, 'ADD-UBI-TO-SRC')['radio_training'] ?? [];
$check(\Bootspruefung\Learning\Gate\DownloadService::allowed($addUser, (int) $ubiPdf) && in_array((int) $ubiPdf, array_column(array_filter($addFiles, static fn ($f) => ! $f['locked']), 'id'), true), 'Ergänzung SRC → UBI: UBI-PDF gelistet und freigegeben');
$check(! \Bootspruefung\Learning\Gate\DownloadService::allowed($addUser, (int) $vocPdf), 'Ergänzung SRC → UBI: SRC-PDF bleibt gesperrt');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $addUser));
wp_delete_attachment((int) $ubiPdf, true);
wp_delete_attachment((int) $vocPdf, true);
wp_set_current_user(1);
foreach ($pdfs as $id) {
    wp_delete_attachment($id, true);
}

echo "Gruppenlizenzen\n";
$mails = [];
$capture = static function ($null, $atts) use (&$mails) {
    $mails[] = $atts;

    return true;
};
add_filter('pre_wp_mail', $capture, 10, 2);
$products = new ProductAccessRepository($wpdb);
$groupsService = new GroupService($wpdb);
$orders = new FakeOrders();
$sync = new OrderSync($orders, $products, $entitlements, new EntitlementPlanner(), $groupsService);
$products->save(991001, 0, [['course_code' => 'SBF-BIN-MOTOR', 'feature_code' => 'question_bank'], ['course_code' => 'SBF-BIN-MOTOR', 'feature_code' => 'exam_simulation']], 365, 'extend', 'revoke_on_full_refund', 3);
$item = static fn (int $qty = 1, int $refQty = 0, float $refTotal = 0.0) => new OrderItemSnapshot(91100, 991001, 0, $qty, 'SCHULE-3', 300.0 * $qty, $refQty, $refTotal);
$orders->put(910001, $buyer, 'on-hold', null, [$item()], 'bacs');
$sync->sync(910001);
$check($groupsService->forOrder(910001) === [], 'unbezahlt: noch keine Gruppe');
$orders->put(910001, $buyer, 'processing', '2026-09-01 10:00:00', [$item()], 'bacs');
$sync->plan(910001);
$preview = $sync->groupChanges();
$check(count($preview) === 1 && $preview[0]['change'] === 'Gruppe neu (3 Plätze)' && $groupsService->forOrder(910001) === [], 'Vorschau (reconcile): neue Gruppe angekündigt, nichts geschrieben');
$sync->sync(910001);
$sync->plan(910001);
$check($sync->groupChanges() === [], 'Vorschau nach dem Anwenden: keine Gruppenänderung');
$group = $groupsService->forOrder(910001)[0] ?? null;
$check($group !== null && (int) $group->seats === 3 && $group->valid_until === '2027-09-01 10:00:00', 'bezahlt: Gruppe mit 3 Plätzen, 365 Tage');
$check($entitlements->forOrder(910001) === [] && ! $access->can($buyer, 'SBF-BIN-MOTOR', 'question_bank'), 'Käufer erhält keinen Einzelzugang');
$check(count($groupsService->forOrder(910001)) === 1 && $sync->sync(910001) !== null && count($groupsService->forOrder(910001)) === 1, 'wiederholtes Ereignis: keine zweite Gruppe');
$token = (string) $group->join_token;
$info = $groupsService->describeToken($token);
$check($info['free_seats'] === 3 && count($info['scopes']) === 2, 'Beitrittslink beschreibt Gruppe');
$groupsService->join($token, $students[0]);
$groupsService->join($token, $students[1]);
$groupsService->join($token, $students[1]);
$check($access->can($students[0], 'SBF-BIN-MOTOR', 'exam_simulation') && $groupsService->freeSeats($groupsService->find((int) $group->id)) === 1, 'zwei Beitritte, doppelter Beitritt belegt keinen Platz');
$member = $entitlements->forUser($students[0])[0];
$check($member->source === 'group' && $member->valid_until === '2027-09-01 10:00:00', 'Platz-Zugang mit Laufzeit der Gruppe');
$result = $groupsService->invite((int) $group->id, $buyer, ['neu@example.test', 'kaputt', 'zweiter@example.test']);
$check($result['invited'] === 1 && count($result['skipped']) === 2 && count($mails) === 1, 'Einladung reserviert letzten Platz, Rest übersprungen');
$check(str_contains($invalid(fn () => $groupsService->join($token, $students[2])), 'belegt'), 'Gruppe voll: Beitritt abgewiesen');
$check($invalid(fn () => $groupsService->invite((int) $group->id, $students[0], ['x@example.test'])) !== '', 'nur Besitzer verwaltet die Gruppe');
preg_match('#gruppe/(i[0-9a-f]{32})#', (string) $mails[0]['message'], $m);
$groupsService->join($m[1], $students[2]);
$check($access->can($students[2], 'SBF-BIN-MOTOR', 'question_bank'), 'persönliche Einladung angenommen');
$members = $groupsService->membersOf((int) $group->id);
$groupsService->removeMember((int) $group->id, (int) $members[1]->id, $buyer, 'Kurs abgebrochen');
$check(! $access->can($students[1], 'SBF-BIN-MOTOR', 'question_bank') && $groupsService->freeSeats($groupsService->find((int) $group->id)) === 1, 'Platz freigegeben: Zugang widerrufen, Platz wieder frei');
$groupsService->join($token, $students[3]);
$check($access->can($students[3], 'SBF-BIN-MOTOR', 'question_bank'), 'freier Platz neu belegt');

// Abgelaufene Einladungen belegen keinen Platz und werden per Cron „expired“, die Adresse später gelöscht.
$gid = (int) $group->id;
$last = array_values(array_filter($groupsService->membersOf($gid), static fn ($m) => $m->status === 'active' && (int) $m->user_id === $students[3]))[0];
$groupsService->removeMember($gid, (int) $last->id, $buyer, 'Test Ablauf');
$mailCount = count($mails);
// Anzeigename = Login: nicht verwenden, stattdessen Vor-/Nachname (ohne Links, gekürzt).
$buyerUser = get_userdata($buyer);
wp_update_user(['ID' => $buyer, 'display_name' => $buyerUser->user_login, 'first_name' => 'Erika', 'last_name' => 'Muster https://evil.example '.str_repeat('x', 80)]);
$groupsService->invite($gid, $buyer, ['ablauf@example.test']);
$inviteMail = (string) ($mails[$mailCount]['message'] ?? '');
$privacyUrl = (string) get_privacy_policy_url();
$check(str_contains($inviteMail, 'Erika Muster') && ! str_contains($inviteMail, 'evil.example') && ! str_contains($inviteMail, str_repeat('x', 60)) && ! str_contains($inviteMail, $buyerUser->user_login.' hat')
    && str_contains($inviteMail, $group->name) && str_contains($inviteMail, 'gilt bis')
    && ($privacyUrl === '' || str_contains($inviteMail, $privacyUrl)), 'Einladungsmail nennt Einladende (Vor-/Nachname statt Login, ohne Links), Gruppe, Ablauf und Datenschutzerklärung');
wp_update_user(['ID' => $buyer, 'first_name' => '', 'last_name' => '']);
$check($groupsService->freeSeats($groupsService->find($gid)) === 0, 'offene Einladung belegt einen Platz');
$stale = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE group_id = %d AND email = %s", $gid, 'ablauf@example.test'));
$wpdb->update($p.'learning_group_member', ['invited_at' => gmdate('Y-m-d H:i:s', time() - (GroupService::INVITE_DAYS + 1) * DAY_IN_SECONDS)], ['id' => $stale->id]);
$check($groupsService->freeSeats($groupsService->find($gid)) === 1, 'abgelaufene Einladung belegt keinen Platz');
$check($groupsService->describeToken((string) $stale->invite_token) === null, 'abgelaufener persönlicher Link ungültig');
$groupsService->expireInvites(null, $gid);
$stale = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE id = %d", $stale->id));
$check($stale->status === GroupService::INVITE_EXPIRED && $stale->invite_token === null && $stale->email === 'ablauf@example.test', 'Cron: Einladung auf „expired“, Adresse vorerst erhalten');
$wpdb->update($p.'learning_group_member', ['invited_at' => gmdate('Y-m-d H:i:s', time() - (GroupService::INVITE_DAYS + GroupService::EXPIRED_RETENTION_DAYS + 1) * DAY_IN_SECONDS)], ['id' => $stale->id]);
$groupsService->expireInvites(null, $gid);
$check($wpdb->get_var($wpdb->prepare("SELECT email FROM {$p}learning_group_member WHERE id = %d", $stale->id)) === null, 'Cron: Adresse 30 Tage nach Ablauf gelöscht');
// Personal lädt in fremde Gruppe ein: Mail nennt den Shop, nicht die Person.
$mailCount = count($mails);
$groupsService->invite($gid, 1, ['personal@example.test']);
$check(str_contains((string) ($mails[$mailCount]['message'] ?? ''), Settings::brand().' hat dich') && ! str_contains((string) ($mails[$mailCount]['message'] ?? ''), (string) get_userdata(1)->display_name.' hat'), 'Einladung durch Personal nennt den Shop');
$staffInvite = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE group_id = %d AND email = %s", $gid, 'personal@example.test'));
$groupsService->removeMember($gid, (int) $staffInvite->id, 1, 'Test');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE id = %d", $staffInvite->id));
$check($row->status === 'removed' && $row->email === null && $row->invite_token === null, 'entfernte Einladung ohne Konto: Adresse sofort gelöscht');
$wpdb->update($p.'learning_group_member', ['email' => 'altfall@example.test'], ['id' => $staffInvite->id]);
$groupsService->expireInvites(null, $gid);
$check($wpdb->get_var($wpdb->prepare("SELECT email FROM {$p}learning_group_member WHERE id = %d", $staffInvite->id)) === null, 'Cron: Altfall entfernte Einladung bereinigt');
$check(str_contains($invalid(fn () => $groupsService->join((string) $staffInvite->invite_token, $students[3])), 'ungültig'), 'entfernte Einladung: Link ungültig');
$groupsService->join($token, $students[3]);
$check($access->can($students[3], 'SBF-BIN-MOTOR', 'question_bank') && $groupsService->freeSeats($groupsService->find($gid)) === 0, 'frei gewordener Platz wieder belegt');

$orders->put(910001, $buyer, 'on-hold', '2026-09-01 10:00:00', [$item()], 'stripe');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'suspended' && ! $access->can($students[0], 'SBF-BIN-MOTOR', 'question_bank'), 'Dispute: Gruppe und alle Plätze ausgesetzt');
$orders->put(910001, $buyer, 'completed', '2026-09-01 10:00:00', [$item()], 'stripe');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'active' && $access->can($students[0], 'SBF-BIN-MOTOR', 'question_bank') && ! $access->can($students[1], 'SBF-BIN-MOTOR', 'question_bank'), 'Dispute gewonnen: aktive Plätze wieder frei, entfernte bleiben draußen');
$sync->suspendOrder(910001, 'Prüfung Zahlung');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'suspended', 'manuelle Aussetzung bleibt trotz bezahlter Bestellung');
$sync->releaseOrder(910001, 'geklärt');
$check($groupsService->find((int) $group->id)->status === 'active', 'Aufhebung: Shop-Status gilt');
$orders->put(910001, $buyer, 'refunded', '2026-09-01 10:00:00', [$item(1, 1, 300.0)], 'stripe');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'revoked' && ! $access->can($students[0], 'SBF-BIN-MOTOR', 'question_bank') && ! $access->can($students[3], 'SBF-BIN-MOTOR', 'question_bank'), 'Erstattung: Gruppe beendet, alle Plätze widerrufen');
$check(str_contains($invalid(fn () => $groupsService->join($token, $students[1])), 'nicht aktiv'), 'beendete Gruppe: kein Beitritt');
// QA-11: Erstattung gelöscht bzw. Storno zurückgenommen → Gruppe lebt wieder auf (wie Einzelzugänge), Plätze aktiver Mitglieder auch.
$orders->put(910001, $buyer, 'completed', '2026-09-01 10:00:00', [$item()], 'stripe');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'active' && $access->can($students[0], 'SBF-BIN-MOTOR', 'question_bank') && $access->can($students[3], 'SBF-BIN-MOTOR', 'question_bank') && ! $access->can($students[1], 'SBF-BIN-MOTOR', 'question_bank'), 'Erstattung zurückgenommen: Gruppe und Plätze aktiver Mitglieder leben wieder auf, entfernte nicht');
$orders->put(910001, $buyer, 'cancelled', '2026-09-01 10:00:00', [$item()], 'stripe');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'revoked' && ! $access->can($students[0], 'SBF-BIN-MOTOR', 'question_bank'), 'Storno: Gruppe widerrufen');
$orders->put(910001, $buyer, 'processing', '2026-09-01 10:00:00', [$item()], 'stripe');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'active' && $access->can($students[0], 'SBF-BIN-MOTOR', 'question_bank'), 'Storno zurückgenommen (wieder bezahlt): Gruppe lebt wieder auf');
$sync->revokeOrder(910001, 'Zahlungskonflikt verloren');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'revoked' && ! $access->can($students[0], 'SBF-BIN-MOTOR', 'question_bank'), 'manueller Widerruf bleibt trotz bezahlter Bestellung');
$orders->put(910001, $buyer, 'refunded', '2026-09-01 10:00:00', [$item(1, 1, 300.0)], 'stripe');
$sync->sync(910001);
$check($groupsService->find((int) $group->id)->status === 'revoked', 'Erstattung: Gruppe bleibt beendet');

$orders->put(910002, $buyer, 'processing', '2026-10-01 10:00:00', [new OrderItemSnapshot(91200, 991001, 0, 2, 'SCHULE-3', 600.0)], 'klarna_payments');
$sync->sync(910002);
$g2 = $groupsService->forOrder(910002)[0];
$check((int) $g2->seats === 6, 'Menge 2: 6 Plätze');
foreach (array_slice($students, 0, 4) as $st) {
    $groupsService->join((string) $g2->join_token, $st);
}
$orders->put(910002, $buyer, 'processing', '2026-10-01 10:00:00', [new OrderItemSnapshot(91200, 991001, 0, 2, 'SCHULE-3', 600.0, 1, 300.0)], 'klarna_payments');
$sync->plan(910002);
$check(array_column($sync->groupChanges(), 'change') === ['Plätze 6 → 3'], 'Vorschau: Plätze nach Teilerstattung');
$sync->sync(910002);
$check((int) $groupsService->find((int) $g2->id)->seats === 3 && $groupsService->find((int) $g2->id)->status === 'active', 'Teilerstattung eines Pakets: Plätze reduziert, Gruppe bleibt');
$activeMembers = array_values(array_filter($groupsService->membersOf((int) $g2->id), static fn ($m) => $m->status === 'active'));
$check(count($activeMembers) === 3 && (int) $activeMembers[0]->user_id === $students[0] && ! $access->can($students[3], 'SBF-BIN-MOTOR', 'question_bank'), 'Teilerstattung: zuletzt beigetretenes Mitglied verliert den Platz, 3 bleiben');
$check(count($groupsService->forOwner($buyer)) === 2, 'Besitzer sieht beide Gruppen');
// QA-05: Konto eines Mitglieds gelöscht → Platz wird frei; Konto des Käufers gelöscht → Gruppe bleibt für die Mitglieder.
require_once ABSPATH.'wp-admin/includes/user.php';
$delOwner = $user('bp_group_owner_del');
$delMember = $user('bp_group_member_del');
$keepMember = $user('bp_group_member_keep');
$orders->put(910003, $delOwner, 'processing', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), [new OrderItemSnapshot(91300, 991001, 0, 1, 'SCHULE-3', 300.0)], 'stripe');
$sync->sync(910003);
$g3 = $groupsService->forOrder(910003)[0];
$groupsService->join((string) $g3->join_token, $delMember);
$groupsService->join((string) $g3->join_token, $keepMember);
$groupsService->invite((int) $g3->id, $delOwner, ['offen-del@example.test']);
$check($groupsService->freeSeats($groupsService->find((int) $g3->id)) === 0 && $access->can($delMember, 'SBF-BIN-MOTOR', 'question_bank'), 'Gruppe voll (2 Mitglieder, 1 Einladung)');
$delMemberRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE group_id = %d AND user_id = %d", $g3->id, $delMember));
wp_delete_user($delMember);
$delMemberRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE id = %d", $delMemberRow->id));
$delEnt = $wpdb->get_col($wpdb->prepare("SELECT status FROM {$p}entitlement WHERE reference_key = %s", GroupService::reference((int) $g3->id, (int) $delMemberRow->id)));
$check($delMemberRow->status === 'removed' && $delMemberRow->removed_at !== null && $delMemberRow->email === null && $groupsService->freeSeats($groupsService->find((int) $g3->id)) === 1 && $delEnt !== [] && array_unique($delEnt) === ['revoked'], 'Mitglied löscht Konto: Platz frei, Zugänge des Platzes widerrufen, Adresse entfernt');
$groupsService->join((string) $g3->join_token, $students[2]);
$check($access->can($students[2], 'SBF-BIN-MOTOR', 'question_bank'), 'frei gewordener Platz kann neu belegt werden');
wp_delete_user($delOwner);
$g3 = $groupsService->find((int) $g3->id);
$check($g3->status === 'active' && (int) $g3->owner_user_id === 0 && $access->can($keepMember, 'SBF-BIN-MOTOR', 'question_bank') && $groupsService->forOwner($delOwner) === [], 'Käufer löscht Konto: Gruppe bleibt aktiv, Mitglieder behalten ihre Plätze, kein Verwalter mehr');
$check($groupsService->describeToken((string) $g3->join_token) !== null && $invalid(fn () => $groupsService->invite((int) $g3->id, 1, ['betreiber@example.test'])) === '', 'Betreiber kann die Gruppe weiter verwalten');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE reference_key LIKE %s", 'group:'.(int) $g3->id.':%'));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}learning_group_member WHERE group_id = %d", $g3->id));
wp_delete_user($keepMember);
// Gruppen: Name ohne Links, Einladungen begrenzt
$groupsService->rename((int) $g2->id, $buyer, 'Segelclub https://evil.example Gewinn www.spam.de info@spam.de');
$check($groupsService->find((int) $g2->id)->name === 'Segelclub Gewinn', 'Gruppenname ohne Links/Adressen');
// DSGVO-Export: echte Datensätze statt Zähler
$exp = [];
$pageNo = 1;
do {
    $r = \Bootspruefung\Learning\Privacy\PersonalData::export(get_userdata($students[0])->user_email, $pageNo++);
    foreach ($r['data'] as $d) {
        $exp[$d['group_id']][] = $d;
    }
} while (! $r['done'] && $pageNo < 100);
$check(isset($exp['bootspruefung-learning-learning_group_member']) && ! str_contains((string) wp_json_encode($exp), 'invite_token'), 'Export enthält Mitgliedschaften als Datensätze, ohne Tokens');
// DSGVO ohne Konto: Einladungen an eine Adresse ohne WP-Nutzer exportieren und löschen.
$noAccount = 'ohne-konto-'.wp_generate_password(6, false).'@example.test';
$now = EntitlementService::now();
$wpdb->insert($p.'learning_group_member', ['group_id' => $gid, 'email' => $noAccount, 'status' => 'invited', 'invite_token' => 'i'.bin2hex(random_bytes(16)), 'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
$noAccountId = (int) $wpdb->insert_id;
$r = \Bootspruefung\Learning\Privacy\PersonalData::export($noAccount, 1);
$check($r['done'] && count($r['data']) === 1 && $r['data'][0]['group_id'] === 'bootspruefung-learning-learning_group_member' && str_contains((string) wp_json_encode($r['data']), $noAccount), 'Export ohne Konto: Einladung an die Adresse');
$r = \Bootspruefung\Learning\Privacy\PersonalData::erase($noAccount, 1);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE id = %d", $noAccountId));
$check($r['items_removed'] && $row->email === null && $row->invite_token === null && $row->status === 'removed', 'Löschung ohne Konto: Adresse und Link entfernt, Platz frei');
$check(\Bootspruefung\Learning\Privacy\PersonalData::export($noAccount, 1)['data'] === [], 'nach Löschung: nichts mehr zu exportieren');
// Rückkehr nach Registrierung/Anmeldung (Gruppeneinladung, Kasse)
$join = GroupService::joinUrl('gTEST');
$_REQUEST[\Bootspruefung\Learning\Frontend\ReturnAfterLogin::PARAM] = $join;
$check(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::loginRedirect(admin_url(), '', get_userdata($buyer)) === $join, 'nach Anmeldung zurück zur Einladung');
$check(str_contains(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::registrationRedirect(''), rawurlencode($join)), 'nach Registrierung: Anmeldung führt zur Einladung');
$check(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::wooRedirect('/mein-konto/') === $join, 'WooCommerce-Registrierung: zurück zur Einladung');
$_REQUEST[\Bootspruefung\Learning\Frontend\ReturnAfterLogin::PARAM] = 'https://evil.example/x';
$check(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::wooRedirect('/mein-konto/') === '/mein-konto/', 'fremdes Ziel wird ignoriert');
unset($_REQUEST[\Bootspruefung\Learning\Frontend\ReturnAfterLogin::PARAM]);
$check(str_contains(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::registrationUrl($join), 'bp_return='), 'Registrierungslink trägt Rückkehrziel');
// Schnupper-Paket und Geld-zurück-Garantie (ohne Shop)
echo "Schnupper-Paket, Geld-zurück-Garantie\n";
$ruleMax = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$p}access_rule");
$free = \Bootspruefung\Learning\Commerce\ShopCatalog::applyFreeTier();
$again = \Bootspruefung\Learning\Commerce\ShopCatalog::applyFreeTier();
$check($free['added'] === $free['courses'] * 5 && $again['added'] === 0, 'Schnupper-Paket: 5 Regeln je Kurs, wiederholbar ohne Dubletten');
$taster = $user('bp_taster');
$check($access->limit($taster, 'SKN', 'question_bank') === 10 && $access->limit($taster, 'SKN', 'exam_simulation') === 1 && ! $access->full($taster, 'SKN', 'question_bank'), 'ohne Kauf: 10 Fragen je Modul, 1 Prüfungsbogen');
$wpdb->query("DELETE FROM {$p}access_rule WHERE id > {$ruleMax}");
$catalog = \Bootspruefung\Learning\Commerce\ShopCatalog::products();
$check(count($catalog) === 62, 'Katalog: 17 Einzelprodukte + 12 Komplett-Stufen + 12 Video-Upgrades + 17 Gruppenpakete + 4 Simulator-Zugänge');
$bySku = array_column($catalog, null, 'sku');
$check(! in_array('video_course', $bySku['BP-SBF-SEE']['features'], true) && ! in_array('all', $bySku['BP-SBF-SEE']['features'], true) && in_array('question_bank', $bySku['BP-SBF-SEE']['features'], true), 'Theorie-Stufe ohne Videokurs');
$check($bySku['BP-SBF-SEE-KOMPLETT']['price'] === '40.80' && in_array('all', $bySku['BP-SBF-SEE-KOMPLETT']['features'], true), 'Komplett-Stufe mit Videokurs, 40,80 €');
$check($bySku['BP-SBF-SEE-VIDEO']['features'] === ['video_course'] && $bySku['BP-SBF-SEE-VIDEO']['hidden'] && $bySku['BP-SBF-SEE-VIDEO']['price'] === '11.90', 'Video-Upgrade: nur Videokurs, nicht gelistet, 11,90 €');
$check(in_array('video_course', $bySku['BP-SRC-KOMPLETT']['features'], true) && ! in_array('device_simulation', $bySku['BP-SRC-KOMPLETT']['features'], true) && ! in_array('video_course', $bySku['BP-SRC']['features'], true), 'SRC: Theorie ohne Video, Komplett mit Video, beide ohne Simulator');
$check(! isset($bySku['BP-SBF-SEE-BIN']['features']) && $bySku['BP-SBF-SEE-BIN']['price'] === '60.80', 'Kombi bleibt komplett (60,80 €)');
$check(\Bootspruefung\Learning\Commerce\ShopCatalog::tierBase('BP-SRC-VIDEO') === 'BP-SRC' && \Bootspruefung\Learning\Commerce\ShopCatalog::tierBase('BP-SRC-UBI') === null, 'Stufen-Zuordnung über die Artikelnummer');
// Eigene Preise (Admin „Preise“): überschreiben den Katalogpreis, ungültige werden abgewiesen.
$SC = \Bootspruefung\Learning\Commerce\ShopCatalog::class;
delete_option($SC::PRICE_OPTION);
$r = $SC::saveOwnPrices(['BP-SBF-SEE' => '31,50', 'BP-SBF-SEE-VIDEO' => '9.9', 'BP-SRC' => 'abc', 'BP-PASS-1M' => '-3', 'BP-SKN' => '', 'BP-UNBEKANNT' => '5', 'BP-SBF-BIN' => $bySku['BP-SBF-BIN']['price']]);
$own = $SC::ownPrices();
$check($own === ['BP-SBF-SEE' => '31.50', 'BP-SBF-SEE-VIDEO' => '9.90'] && array_keys($r['errors']) === ['BP-SRC', 'BP-PASS-1M'], 'eigene Preise: Komma/Punkt, Katalogpreis und leer werden nicht gespeichert, ungültige abgewiesen');
$now = $SC::find('BP-SBF-SEE');
$check($now['price'] === '31.50' && $now['catalog_price'] === '28.90' && $SC::find('BP-SBF-SEE-KOMPLETT')['price'] === '40.80', 'eigener Preis gilt im Katalog, Komplett bleibt eigenständig');
$check(str_contains($SC::texts($now)['long'], 'Upgrade für 9,90 €'), 'Text nennt den eigenen Upgrade-Preis');
$SC::saveOwnPrices(['BP-SBF-SEE' => '0', 'BP-SBF-SEE-VIDEO' => '9,90']);
$check($SC::ownPrices() === ['BP-SBF-SEE' => '31.50', 'BP-SBF-SEE-VIDEO' => '9.90'], 'ungültige Änderung behält den bisherigen eigenen Preis');
update_option($SC::PRICE_OPTION, ['BP-SKN' => '1e3', 'BP-SRC' => ['x']]);
$check($SC::ownPrices() === [], 'manipulierte Option wird ignoriert');
delete_option($SC::PRICE_OPTION);

$mbProduct = 995001;
$products->save($mbProduct, 0, [['course_code' => 'SKN', 'feature_code' => 'question_bank']], 182, 'extend', 'revoke_on_full_refund');
\Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($mbProduct, 14, 20.0);
$mbUser = $user('bp_moneyback');
$wpdb->delete($p.'progress', ['user_id' => $mbUser]);
$paid = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
$mbOrder = ['id' => 995100, 'user_id' => $mbUser, 'status' => 'processing', 'date_paid' => $paid, 'refunded' => false, 'items' => [['product_id' => $mbProduct, 'variation_id' => 0]]];
$qids = $wpdb->get_col("SELECT DISTINCT mq.question_id FROM {$p}course c JOIN {$p}course_module cm ON cm.course_id = c.id JOIN {$p}module_question mq ON mq.module_id = cm.module_id WHERE c.code = 'SKN' ORDER BY mq.question_id");
$answer = static function (array $ids, string $when) use ($wpdb, $p, $mbUser): void {
    foreach ($ids as $qid) {
        $wpdb->replace($p.'progress', ['user_id' => $mbUser, 'question_id' => (int) $qid, 'attempts' => 1, 'correct_count' => 1, 'learn_status' => 'learning', 'first_answered_at' => $when, 'last_answered_at' => $when]);
    }
};
$answer(array_slice($qids, 0, 40), gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS)); // vor dem Kauf (Schnupper) zählt nicht
$answer(array_slice($qids, 40, 10), gmdate('Y-m-d H:i:s'));
$st = \Bootspruefung\Learning\Commerce\MoneyBack::status($mbOrder);
$lessonCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}video_lesson vl JOIN {$p}video_module vm ON vm.id = vl.module_id JOIN {$p}course c ON c.id = vm.course_id WHERE c.code = 'SKN'");
$expect = round(10 * 100 / count($qids), 1); // Produkt nur mit Fragenkatalog: Lektionen zählen nicht
$check($st['applies'] && $st['eligible'] && abs($st['progress'] - $expect) < 0.01, "berechtigt: {$st['progress']} % seit Kauf gelernt (nur gekaufte Inhalte, vor dem Kauf zählt nicht)");
$answer(array_slice($qids, 50, (int) ceil(count($qids) * 0.2)), gmdate('Y-m-d H:i:s'));
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status($mbOrder)['reason'] === 'too_much_progress', 'ab 20 % gelernt: keine Garantie mehr');
$MB = \Bootspruefung\Learning\Commerce\MoneyBack::class;
$check(! $MB::limitReached(['percent' => 20.0, 'exact' => 19.95], 20.0) && $MB::limitReached(['percent' => 20.0, 'exact' => 20.0], 20.0) && $MB::limitReached(['percent' => 20.1], 20.0), 'Garantiegrenze: 19,95 % gilt als weniger als 20 % (ungerundet verglichen)');
$wpdb->delete($p.'progress', ['user_id' => $mbUser]);
// QA-01: In Prüfungssimulationen beantwortete Fragen zählen mit – vereinigt mit dem Smarttrainer, jede Frage einmal.
$sknId = (int) $wpdb->get_var("SELECT id FROM {$p}course WHERE code = 'SKN'");
$wpdb->query($wpdb->prepare("DELETE FROM {$p}exam_session WHERE user_id = %d", $mbUser));
$examSession = static function (array $ids, ?string $answeredAt) use ($wpdb, $p, $mbUser, $sknId): int {
    $wpdb->insert($p.'exam_session', ['user_id' => $mbUser, 'course_id' => $sknId, 'mode' => 'random', 'rule_snapshot' => '{}', 'status' => 'submitted', 'start_token' => wp_generate_uuid4(), 'started_at' => gmdate('Y-m-d H:i:s'), 'submitted_at' => gmdate('Y-m-d H:i:s')]);
    $sid = (int) $wpdb->insert_id;
    foreach (array_values($ids) as $pos => $qid) {
        $wpdb->insert($p.'exam_session_question', ['session_id' => $sid, 'position' => $pos + 1, 'question_id' => (int) $qid, 'revision_id' => (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}question_revision WHERE question_id = %d ORDER BY id DESC LIMIT 1", $qid)), 'answered_at' => $answeredAt, 'given_answer' => $answeredAt === null ? null : '[1]']);
    }

    return $sid;
};
$examSession(array_slice($qids, 0, 10), gmdate('Y-m-d H:i:s'));             // 10 beantwortet
$examSession(array_slice($qids, 5, 7), gmdate('Y-m-d H:i:s'));              // 5 davon schon in Prüfung 1, 2 neu
$examSession(array_slice($qids, 20, 10), null);                              // nur angezeigt, nicht beantwortet
$examSession(array_slice($qids, 30, 10), gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS)); // vor dem Kauf
$answer(array_slice($qids, 10, 4), gmdate('Y-m-d H:i:s'));                  // Smarttrainer: 2 auch in Prüfung 2, 2 neu
$exam = \Bootspruefung\Learning\Commerce\MoneyBack::status($mbOrder);
$expectExam = round(14 * 100 / count($qids), 1);
$check(count($qids) >= 50 && abs($exam['progress'] - $expectExam) < 0.01 && ($exam['usage']['done'] ?? 0) === 14 && $exam['eligible'], "Prüfungssimulationen zählen mit: {$exam['progress']} % (14 verschiedene Fragen, keine doppelt, unbeantwortete und vor dem Kauf nicht)");
$examSession(array_slice($qids, 40, 10), gmdate('Y-m-d H:i:s'));
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status($mbOrder)['reason'] === 'too_much_progress', 'weitere Prüfungssimulation hebt den Stand über 20 %: keine Garantie mehr');
// QA-06: Festgeschriebener Stand (vor einer Datenlöschung) gilt, auch wenn die Lerndaten danach fehlen.
$frozenUsage = \Bootspruefung\Learning\Commerce\MoneyBack::status($mbOrder)['usage'] ?? [];
$wpdb->query($wpdb->prepare("DELETE FROM {$p}exam_session WHERE user_id = %d", $mbUser));
$wpdb->delete($p.'progress', ['user_id' => $mbUser]);
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status($mbOrder)['reason'] === 'eligible' && \Bootspruefung\Learning\Commerce\MoneyBack::status(['usage' => $frozenUsage + ['at' => gmdate('Y-m-d H:i:s')]] + $mbOrder)['reason'] === 'too_much_progress', 'nach Datenlöschung: festgeschriebener Stand zählt weiter (Höchstwert)');
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status(['usage' => ['exact' => 'x']] + $mbOrder)['reason'] === 'eligible' && str_contains(\Bootspruefung\Learning\Commerce\MoneyBack::usageExplanation(), 'Prüfungssimulation'), 'ungültiger Wert wird ignoriert; Hinweis nennt Prüfungssimulationen');
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status(['date_paid' => gmdate('Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS)] + $mbOrder)['reason'] === 'expired', 'nach 14 Tagen: Frist abgelaufen');
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status($mbOrder, ['at' => $paid, 'progress' => 1.0])['reason'] === 'requested', 'Anfrage gestellt: kein zweiter Antrag');
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status(['refunded' => true] + $mbOrder)['reason'] === 'refunded' && \Bootspruefung\Learning\Commerce\MoneyBack::status(['items' => [['product_id' => 991001, 'variation_id' => 0]]] + $mbOrder)['applies'] === false, 'erstattet bzw. Produkt ohne Garantie');
// Video-Upgrade: Garantie misst nur die Lektionen; Teilerstattung (nur Theorie erstattet) beendet die Garantie des Rests nicht.
$mbVideo = 995002;
$products->save($mbVideo, 0, [['course_code' => 'SKN', 'feature_code' => 'video_course']], 182, 'extend', 'revoke_on_full_refund');
\Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($mbVideo, 14, 20.0);
$videoOrder = ['refunded' => true, 'items' => [['product_id' => $mbProduct, 'variation_id' => 0, 'refunded' => true], ['product_id' => $mbVideo, 'variation_id' => 0, 'refunded' => false]]] + $mbOrder;
$vs = \Bootspruefung\Learning\Commerce\MoneyBack::status($videoOrder);
$check($lessonCount > 0 && $vs['eligible'] && $vs['progress'] === 0.0, "Upgrade: viele Fragen geübt, keine Lektion gesehen → Garantie bleibt (Teilerstattung der Theorie zählt nicht)");
$check(\Bootspruefung\Learning\Commerce\MoneyBack::status(['items' => [['product_id' => $mbProduct, 'variation_id' => 0, 'refunded' => true], ['product_id' => $mbVideo, 'variation_id' => 0, 'refunded' => true]]] + $videoOrder)['reason'] === 'refunded', 'alle Garantie-Positionen erstattet → Erstattet');
$products->save($mbVideo, 0, [], null, 'extend', 'revoke_on_full_refund');
\Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($mbVideo, null, null);

// Upgrades hängen am Basiskurs: Video-Upgrade nur mit aktiver Theorie, Simulator-Upgrade nur mit Funkkurs.
$upUser = $user('bp_upgrade');
$wpdb->delete($p.'entitlement', ['user_id' => $upUser]);
$upNow = gmdate('Y-m-d H:i:s', time() - 60);
$row = static fn (string $course, string $feature, string $sku, string $ref) => ['user_id' => $upUser, 'course_code' => $course, 'feature_code' => $feature, 'status' => 'active', 'valid_from' => $upNow, 'valid_until' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS), 'source' => 'manual', 'reference_key' => $ref, 'sku_snapshot' => $sku, 'created_at' => $upNow, 'updated_at' => $upNow];
$wpdb->insert($p.'entitlement', $row('SKN', 'question_bank', 'BP-SKN', 'up-theory'));
$wpdb->insert($p.'entitlement', $row('SKN', 'video_course', 'BP-SKN-VIDEO', 'up-video'));
$wpdb->insert($p.'entitlement', $row('SRC', 'device_simulation', \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_UPGRADE, 'up-sim'));
$acc = new \Bootspruefung\Learning\Learning\Services\AccessService();
$check($acc->full($upUser, 'SKN', 'video_course') && ! $acc->full($upUser, 'SRC', 'device_simulation'), 'Video-Upgrade mit Theorie aktiv; Simulator-Upgrade ohne Funkkurs nicht');
$wpdb->insert($p.'entitlement', $row('SRC', 'question_bank', 'BP-SRC', 'up-src'));
$wpdb->update($p.'entitlement', ['status' => 'revoked'], ['user_id' => $upUser, 'reference_key' => 'up-theory']);
$check(! $acc->full($upUser, 'SKN', 'video_course') && $acc->full($upUser, 'SRC', 'device_simulation'), 'Theorie erstattet → Video-Upgrade ruht; mit Funkkurs gilt das Simulator-Upgrade');
$wpdb->delete($p.'entitlement', ['user_id' => $upUser]);

$products->save($mbProduct, 0, [], null, 'extend', 'revoke_on_full_refund');
\Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($mbProduct, null, null);
// Seekarte D49 (Upload, gesperrt bis BSH-Lizenz) und Sammelfreigabe
echo "Seekarte D49, Sammelfreigabe\n";
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/media.php';
require_once ABSPATH.'wp-admin/includes/image.php';
$charts = \Bootspruefung\Learning\Gate\Charts::class;
$sailor = $user('bp_navigator');
$check($charts::forCourse($sailor, 'SBF-SEE') === [['code' => 'D49', 'title' => 'Übungskarte D49 (BSH)', 'url' => null]] && $charts::forCourse($sailor, 'SRC') === [], 'ohne Upload: Hinweis im Navigationstrainer, nur See-Kurse');
$img = wp_tempnam('d49.png');
file_put_contents($img, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$chartId = media_handle_sideload(['name' => 'd49-test.png', 'tmp_name' => $img], 0, 'D49 Test');
$check(! is_wp_error($chartId), 'Karte hochgeladen');
$charts::attach('D49', (int) $chartId);
$navRule = $wpdb->insert($p.'access_rule', ['course_code' => 'ADD-BIN-TO-SEE', 'feature_code' => 'navigation_trainer', 'access_type' => 'free', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]) ? (int) $wpdb->insert_id : 0;
$check(\Bootspruefung\Learning\Gate\ProtectedFiles::requirement((int) $chartId) !== null && ! $charts::available('D49') && ! \Bootspruefung\Learning\Gate\DownloadService::allowed($sailor, (int) $chartId), 'hochgeladen, aber ohne Lizenz: geschützt und für Lernende gesperrt');
$threw = false;
try {
    $charts::setLicense('D49', true, '');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
$check($threw, 'Lizenz nur mit Nachweis');
$charts::setLicense('D49', true, 'BSH-Lizenz Test 2026');
$check(\Bootspruefung\Learning\Gate\DownloadService::allowed($sailor, (int) $chartId) && $charts::forCourse($sailor, 'ADD-BIN-TO-SEE')[0]['url'] !== null, 'nach Lizenz: sichtbar mit Navigationstrainer (auch Ergänzungskurs)');
$noNav = $user('bp_ohne_nav');
$rule('SBF-SEE', 'navigation_trainer', 'free_limited', 1);
$settings = \Bootspruefung\Learning\Settings\Settings::all();
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, array_merge($settings, ['default_access' => 'entitled_only']));
$anyFull = (bool) array_filter(['SBF-SEE', 'SBF-SEE-BIN', 'ADD-BIN-TO-SEE'], static fn ($c) => $access->full($noNav, $c, 'navigation_trainer'));
$check($access->can($noNav, 'SBF-SEE', 'navigation_trainer') && ! $access->full($noNav, 'SBF-SEE', 'navigation_trainer') && $charts::forCourse($noNav, 'SBF-SEE')[0]['url'] === null
    && \Bootspruefung\Learning\Gate\DownloadService::allowed($noNav, (int) $chartId) === $anyFull, 'Schnupper-Paket (Teilumfang Navigation): Karte gesperrt, nur mit vollem Zugang');
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, $settings);
$wpdb->query($wpdb->prepare("DELETE FROM {$p}access_rule WHERE course_code = 'SBF-SEE' AND feature_code = 'navigation_trainer' AND access_type = 'free_limited' AND limit_value = %d", 1));
$check(\Bootspruefung\Learning\Gate\DownloadService::items($sailor, 'SBF-SEE') === [] || ! in_array((int) $chartId, array_column(\Bootspruefung\Learning\Gate\DownloadService::items($sailor, 'SBF-SEE'), 'id'), true), 'Karte erscheint nicht in der Downloadliste');
$charts::remove('D49');
$check(get_post((int) $chartId) === null && ! $charts::available('D49'), 'Datei gelöscht');
delete_option($charts::OPTION);
$wpdb->delete($p.'access_rule', ['id' => $navRule]);

$preview = \Bootspruefung\Learning\Content\RightsRelease::releaseAll('Test-Sammelfreigabe', true);
$released = \Bootspruefung\Learning\Content\RightsRelease::releaseAll('Test-Sammelfreigabe');
$after = \Bootspruefung\Learning\Content\RightsRelease::releaseAll('Test-Sammelfreigabe', true);
$check($preview === $released && array_sum($after) === 0, 'Sammelfreigabe: '.array_sum($released).' Inhalte, danach nichts mehr offen');
$wpdb->delete($p.'override', ['reason' => 'Test-Sammelfreigabe']);
wp_cache_flush_group('bootspruefung');
$check(\Bootspruefung\Learning\Content\RightsRelease::releaseAll('x', true) === $preview, 'Testfreigaben zurückgenommen');
$repoC = new \Bootspruefung\Learning\Content\Repositories\ContentRepository();
$comboId = (int) $wpdb->get_var("SELECT id FROM {$p}course WHERE code = 'SBF-SEE-BIN'");
$rulesBefore = \Bootspruefung\Learning\Content\RightsRelease::releaseExamRules('Test-Regelfreigabe', true);
$check(in_array('SBF-SEE-BIN', array_column($rulesBefore, 'course'), true) && ! $repoC->examRule($comboId)['live'], 'Kombi-Regelwerk (Entwurf der Quelle) vor Freigabe nur Übung');
\Bootspruefung\Learning\Content\RightsRelease::releaseExamRules('Test-Regelfreigabe');
$check($repoC->examRule($comboId)['live'] && \Bootspruefung\Learning\Content\RightsRelease::releaseExamRules('x', true) === [], 'nach Freigabe: alle Regelwerke live');
$wpdb->delete($p.'override', ['reason' => 'Test-Regelfreigabe']);
wp_cache_flush_group('bootspruefung');
$check(! $repoC->examRule($comboId)['live'], 'Testfreigabe zurückgenommen');

echo "Kursempfehlungen und Ausblenden (Web-REST /me/courses)\n";
$relUsers = ['bin' => $user('bp_rel_binnen'), 'combo' => $user('bp_rel_kombi'), 'ubi' => $user('bp_rel_ubi'), 'trial' => $user('bp_rel_trial')];
$expUsers = ['a' => $user('bp_exp_a'), 'b' => $user('bp_exp_b'), 'later' => $user('bp_exp_later'), 'open' => $user('bp_exp_open'), 'far' => $user('bp_exp_far')];
$relAll = implode(',', array_merge($relUsers, $expUsers));
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$relAll})");
foreach (array_merge($relUsers, $expUsers) as $uid) {
    delete_user_meta($uid, \Bootspruefung\Learning\Commerce\ExpiryReminder::META);
}
$names = [];
foreach ($content->courses() as $c) {
    $names[$c->code] = $c->name;
}
$meCourses = static function (int $uid): array {
    wp_set_current_user($uid);
    $res = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/me/courses'));
    wp_set_current_user(1);

    return $res->get_status() === 200 ? array_column((array) $res->get_data(), null, 'code') : [];
};
$entitlements->grantManual($relUsers['bin'], 'SBF-BIN-MOTOR', ['question_bank', 'exam_simulation'], null, 'Test Empfehlung');
$entitlements->grantManual($relUsers['combo'], 'SBF-SEE-BIN', ['question_bank'], null, 'Test Empfehlung');
$entitlements->grantManual($relUsers['ubi'], 'UBI', ['question_bank'], null, 'Test Empfehlung');
$c = $meCourses($relUsers['bin']);
$check(isset($c['SBF-SEE-BIN'], $c['ADD-BIN-TO-SEE']) && array_key_exists('hidden', $c['SBF-SEE']) && array_key_exists('recommended_for', $c['SBF-SEE']), 'Kursliste enthält hidden und recommended_for');
$check($c['SBF-SEE-BIN']['hidden'] === true && $c['ADD-SEE-TO-BIN']['hidden'] === true && $c['SBF-SEE-BIN']['recommended_for'] === [], 'Binnen-Besitzer: SBF See & Binnen und Ergänzung See → Binnen ausgeblendet');
$check($c['ADD-BIN-TO-SEE']['hidden'] === false && $c['ADD-BIN-TO-SEE']['recommended_for'] === [$names['SBF-BIN-MOTOR']] && $c['ADD-BIN-SEGEL']['recommended_for'] === [$names['SBF-BIN-MOTOR']], 'Binnen-Besitzer: Ergänzung Binnen → See und Segeln empfohlen (Name des Kurses)');
$check($c['SBF-BIN-MOTOR']['hidden'] === false && $c['SBF-BIN-MOTOR']['recommended_for'] === [] && $c['SBF-SEE']['hidden'] === false && $c['SBF-SEE']['recommended_for'] === [], 'eigener Kurs und unbeteiligte Kurse unverändert');
$c = $meCourses($relUsers['trial']);
$check(($c['SBF-SEE']['trial'] ?? false) === true && $c['SBF-SEE-BIN']['hidden'] === false && $c['ADD-BIN-TO-SEE']['hidden'] === false && $c['ADD-SEE-TO-BIN']['recommended_for'] === [], 'kostenloser Teilumfang zählt nicht als Besitz (nichts ausgeblendet, nichts empfohlen)');
$c = $meCourses($relUsers['combo']);
$comboCodes = ['SBF-SEE', 'SBF-BIN-MOTOR', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE'];
$check(array_filter($comboCodes, static fn ($k) => $c[$k]['hidden'] !== ! $c[$k]['trial']) === [], 'Kombi-Besitzer: Einzelkurse und Ergänzungen ausgeblendet, Testkurse (Teilumfang) bleiben sichtbar');
$trialHub = $user('bp_rel_trialhub');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $trialHub));
$entitlements->grantManual($trialHub, 'SBF-BIN-MOTOR', ['question_bank'], null, 'Test Empfehlung');
$wpdb->insert($p.'access_rule', ['course_code' => 'SBF-SEE-BIN', 'feature_code' => 'question_bank', 'module_code' => null, 'access_type' => 'free_limited', 'limit_type' => 'questions_per_module', 'limit_value' => 5]);
$freeRuleId = (int) $wpdb->insert_id;
wp_cache_flush_group('bootspruefung');
$cT = $meCourses($trialHub);
$check($cT['SBF-SEE-BIN']['trial'] === true && $cT['SBF-SEE-BIN']['hidden'] === false && $cT['ADD-SEE-TO-BIN']['hidden'] === ($cT['ADD-SEE-TO-BIN']['trial'] === false), 'Binnen-Besitzer: Test-Kombikurs SBF See & Binnen bleibt sichtbar');
$wpdb->delete($p.'access_rule', ['id' => $freeRuleId]);
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $trialHub));
wp_cache_flush_group('bootspruefung');
$check($c['ADD-BIN-SEGEL']['recommended_for'] === [$names['SBF-SEE-BIN']] && $c['SBF-SEE-BIN']['hidden'] === false, 'Kombi-Besitzer: Ergänzung Segeln empfohlen');
$c = $meCourses($relUsers['ubi']);
$check($c['SRC']['recommended_for'] === [$names['UBI']] && $c['SRC']['hidden'] === false && $c['SRC-UBI']['hidden'] === true && $c['ADD-UBI-TO-SRC']['hidden'] === true, 'UBI-Besitzer: SRC empfohlen, Kombi und Ergänzung ausgeblendet');
$groupMember = $students[0];
$check($access->full($groupMember, 'SBF-BIN-MOTOR', 'question_bank') && in_array('SBF-BIN-MOTOR', \Bootspruefung\Learning\Commerce\CourseRelations::ownedCourses($groupMember), true)
    && \Bootspruefung\Learning\Commerce\CourseRelations::forUser($groupMember, ['SBF-SEE-BIN'])['SBF-SEE-BIN']['hidden'] === true, 'Zugang per Gruppe zählt als Besitz');
$queries = $wpdb->num_queries;
$rel = \Bootspruefung\Learning\Commerce\CourseRelations::forUser($relUsers['bin'], array_keys($names));
$check($wpdb->num_queries - $queries === 1 && count($rel) === count($names), 'eine Datenbankabfrage für alle Kurse');
$noRules = static fn (): array => [];
add_filter('bootspruefung_course_relations', $noRules);
$check(\Bootspruefung\Learning\Commerce\CourseRelations::forUser($relUsers['bin'], ['SBF-SEE-BIN'])['SBF-SEE-BIN']['hidden'] === false, 'Filter bootspruefung_course_relations ändert die Tabelle');
remove_filter('bootspruefung_course_relations', $noRules);

echo "Ablauf-Erinnerung\n";
$ER = \Bootspruefung\Learning\Commerce\ExpiryReminder::class;
$settingsNow = Settings::all();
update_option(Settings::OPTION, array_merge($settingsNow, ['expiry_reminder_enabled' => true, 'expiry_reminder_days' => 14]));
$in = static fn (int $days): string => gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS);
$untilA = $in(5);
$idsA = $entitlements->grantManual($expUsers['a'], 'SBF-SEE', ['question_bank', 'exam_simulation'], $untilA, 'Test Ablauf');
$entitlements->grantManual($expUsers['b'], 'SRC', ['question_bank'], $in(3), 'Test Ablauf');
$entitlements->grantManual($expUsers['b'], 'UBI', ['question_bank'], $in(10), 'Test Ablauf');
$entitlements->grantManual($expUsers['later'], 'SBF-BIN-MOTOR', ['question_bank'], $in(4), 'Test Ablauf');
$next = $entitlements->grantManual($expUsers['later'], 'SBF-BIN-MOTOR', ['question_bank'], $in(369), 'Verlängerung');
$wpdb->update($p.'entitlement', ['valid_from' => $in(4)], ['id' => $next[0]]); // beginnt nach Ablauf
$entitlements->grantManual($expUsers['open'], 'SRC', ['question_bank'], $in(2), 'Test Ablauf');
$entitlements->grantManual($expUsers['open'], 'SRC', ['question_bank'], null, 'unbefristet');
$entitlements->grantManual($expUsers['far'], 'SRC', ['question_bank'], $in(30), 'Test Ablauf');
$expiryMails = [];
$catch = static function ($null, $atts) use (&$expiryMails) {
    $expiryMails[] = $atts;

    return true;
};
add_filter('pre_wp_mail', $catch, 5, 2);
$to = static function (int $uid) use (&$expiryMails): array {
    $email = get_userdata($uid)->user_email;

    return array_values(array_filter($expiryMails, static fn ($m) => $m['to'] === $email || $m['to'] === [$email]));
};
$check(has_action($ER::HOOK, [$ER, 'run']) !== false, 'täglicher Cron bp_learning_daily ist verbunden');
$ER::run();
$mailA = $to($expUsers['a']);
$check(count($mailA) === 1 && str_contains($mailA[0]['subject'], $names['SBF-SEE']) && str_contains($mailA[0]['message'], wp_date('d.m.Y', strtotime($untilA.' UTC')))
    && str_contains($mailA[0]['message'], 'Dein Lernstand bleibt erhalten.') && str_contains($mailA[0]['message'], \Bootspruefung\Learning\Commerce\ExpiryReminder::renewUrl('SBF-SEE')), 'Erinnerung mit Kursname, deutschem Datum, Lernstand-Hinweis und Link zum Verlängern');
$mailB = $to($expUsers['b']);
$check(count($mailB) === 1 && str_contains($mailB[0]['message'], $names['SRC']) && str_contains($mailB[0]['message'], $names['UBI']), 'zwei Kurse eines Nutzers in einer Mail gebündelt');
$check($to($expUsers['later']) === [] && $to($expUsers['open']) === [], 'keine Erinnerung bei späterem (Verlängerung) oder unbefristetem Zugang');
$check($to($relUsers['trial']) === [] && $to($expUsers['far']) === [], 'keine Erinnerung im kostenlosen Teilumfang und außerhalb des Fensters');
$check(array_key_exists('SBF-SEE|'.$untilA, (array) get_user_meta($expUsers['a'], $ER::META, true)), 'gemerkt je Kurs und Ablaufdatum (Nutzer-Meta)');
$ER::run();
$check(count($to($expUsers['a'])) === 1 && count($to($expUsers['b'])) === 1, 'nicht doppelt beim nächsten Lauf');
$untilA2 = $in(8);
$entitlements->extend($idsA[0], $untilA2, 'Verlängert');
$ER::run();
$check(count($to($expUsers['a'])) === 2, 'neues Ablaufdatum (Verlängerung) erinnert erneut');
$c = $meCourses($expUsers['a']);
$check($c['SBF-SEE']['expires_soon'] === true && $c['SBF-SEE']['expires_at'] === $untilA2 && is_string($c['SBF-SEE']['renew_url']) && $c['SRC']['expires_soon'] === false && $c['SRC']['renew_url'] === null, 'Kursliste: expires_soon, expires_at und renew_url');
$check($meCourses($expUsers['later'])['SBF-BIN-MOTOR']['expires_soon'] === false && $meCourses($relUsers['trial'])['SBF-SEE']['expires_soon'] === false, 'kein Hinweis bei Verlängerung oder Teilumfang');
$comboExp = $user('bp_exp_combo');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $comboExp));
$untilC = $in(6);
foreach (['SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR'] as $code) {
    $entitlements->grantManual($comboExp, $code, ['question_bank'], $untilC, 'Test Ablauf Kombi');
}
$check(array_keys($ER::expiring($comboExp)[$comboExp] ?? []) === ['SBF-SEE-BIN'], 'Kombi: mitgekaufte Teilkurse werden nicht einzeln erinnert');
$check($ER::collapse(['SRC' => $untilC, 'SRC-UBI' => $untilC, 'UBI' => $in(9)]) === ['SRC-UBI' => $untilC, 'UBI' => $in(9)], 'Zusammenfassen nur bei gleichem Ablaufdatum');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $comboExp));
$before = count($expiryMails);
$entitlements->extend($idsA[0], $in(6), 'Test aus');
update_option(Settings::OPTION, array_merge(Settings::all(), ['expiry_reminder_enabled' => false]));
$check($ER::run() === 0 && count($expiryMails) === $before, 'abgeschaltet: keine Mail');
$check(Settings::sanitize([])['expiry_reminder_enabled'] === true && Settings::sanitize([])['expiry_reminder_days'] === 14 && Settings::sanitize(['expiry_reminder_enabled' => '0', 'expiry_reminder_days' => '500'])['expiry_reminder_enabled'] === false
    && Settings::sanitize(['expiry_reminder_days' => '500'])['expiry_reminder_days'] === 90, 'Einstellungen: Standard an und 14 Tage, aus speicherbar, höchstens 90 Tage');
update_option(Settings::OPTION, $settingsNow);
remove_filter('pre_wp_mail', $catch, 5);
$hourly = wp_next_scheduled('bp_learning_hourly');
wp_clear_scheduled_hook($ER::HOOK);
$ER::schedule();
$scheduled = wp_next_scheduled($ER::HOOK);
\Bootspruefung\Learning\Plugin::deactivate();
$check($scheduled !== false && wp_get_schedule($ER::HOOK) === false, 'Cron geplant (täglich) und beim Deaktivieren entfernt');
if ($hourly !== false) {
    wp_schedule_event($hourly, 'hourly', 'bp_learning_hourly');
}
$ER::schedule();
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$relAll})");
foreach (array_merge($relUsers, $expUsers) as $uid) {
    delete_user_meta($uid, $ER::META);
}
remove_filter('pre_wp_mail', $capture, 10);

// Aufräumen.
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$all}) OR order_id >= 910000");
$wpdb->query("DELETE FROM {$p}access_rule WHERE course_code IN ('SBF-SEE','SRC')");
$wpdb->query("DELETE FROM {$p}learning_group_member WHERE group_id IN (SELECT id FROM {$p}learning_group WHERE order_id >= 910000)");
$wpdb->query("DELETE FROM {$p}learning_group WHERE order_id >= 910000");
$wpdb->query("DELETE FROM {$p}product_access WHERE product_id >= 991000");
$wpdb->query("DELETE FROM {$p}exam_session WHERE user_id IN ({$all})");
update_option(Settings::OPTION, $settingsBefore);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
