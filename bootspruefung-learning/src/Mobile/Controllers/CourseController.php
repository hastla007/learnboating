<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\ExamGuide\ExamGuideService;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Gate\Charts;
use Bootspruefung\Learning\Gate\DownloadService;
use Bootspruefung\Learning\Gate\SectionFiles;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Mapper\CourseMapper;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Rest\Controllers\TrainerController;
use Bootspruefung\Learning\Trainers\DeviceSimService;
use Bootspruefung\Learning\Trainers\RadioPracticeService;
use Bootspruefung\Learning\Trainers\VideoService;
use WP_REST_Request;
use WP_REST_Response;

/** Kursliste, Kursdetails, Übersicht, Module und Fortschritt (wie /me/courses und /courses/{code} im Web). */
final class CourseController extends BaseController
{
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $all = array_values($this->publishedCourses());
        [$page, $pagination] = self::paginate($all, $request);
        $out = array_map(fn ($course) => $this->courses->summary($user, $course), $page);

        return $this->ok($out, 200, ['tenant' => ['slug' => MobileSettings::tenantSlug(), 'name' => MobileSettings::tenantName()], 'pagination' => $pagination]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $data = $this->courses->summary($user, $course);
        $features = $this->access->features($user, (string) $course->code);
        $exam = $features[AccessService::EXAM] ? $this->exams->overview($user, $course) : null;
        $offers = $this->courses->offers($user, $course);
        // Wie der Web-Lernbereich: Prüfungsanmeldung nur, wenn eingeschaltet und für den Kurs eine Anleitung existiert.
        $guide = ExamGuideService::enabled() && (new ExamGuideService())->pagesFor((string) $course->code) !== [];
        $data += [
            'modules_count' => count($this->content->modules((int) $course->id)),
            'exam_options_count' => $exam === null ? 0 : count($exam['papers']) + ($exam['available'] ? 1 : 0),
            'favorites_count' => $features[AccessService::QUESTION_BANK] ? $this->learning->courseFavoriteCount($user, $course) : 0,
            'trainers' => (new TrainerController())->summary($user, $course),
            'offers' => (object) $offers,
            'downloads' => array_map([self::class, 'file'], DownloadService::items($user, (string) $course->code)),
            'section_files' => (object) array_map(static fn ($list) => array_map([self::class, 'file'], $list), SectionFiles::forCourse($user, (string) $course->code)),
            'charts' => array_map(static fn ($c) => ['code' => (string) $c['code'], 'title' => (string) $c['title']] + self::fileUrl($c['url'] ?? null), Charts::forCourse($user, (string) $course->code)),
            'web_url' => Router::url(strtolower((string) $course->code)),
            // Funkgerät-Simulator: nur Link in den Web-Lernbereich (keine Bedien-API).
            'device_simulator' => RadioController::simulator($user, $course, new DeviceSimService($this->access, new RadioPracticeService($this->access)), $offers),
            // Knoten: Videokapitel „Knoten“ als eigene Kachel (wie im Web-Lernbereich).
            'knots' => $this->knots($user, $course, $features[AccessService::VIDEO], $offers),
            'exam_registration' => ['available' => $guide, 'web_url' => $guide ? Router::url(strtolower((string) $course->code).'/pruefung-anmelden') : null],
            // „Praxis in deiner Nähe“ (seit 1.11.0): Angebote eines Ticket-Plugins, ohne Plugin leer.
            'practice_offers' => \Bootspruefung\Learning\Extensions\Extensions::practiceOffers((string) $course->code, $user),
        ];

        return $this->ok($data);
    }

    public function overview(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $progress = $this->learning->courseProgress($user, $course);
        $modules = self::modulesOf($progress['modules']);
        $continue = null;
        foreach ($modules as $m) {
            if (! $m['locked'] && $m['question_count'] > 0 && $m['progress']['percent'] < 100) {
                // Erstes Thema des Moduls, das noch nicht gefestigt ist (für „Dein nächster Schritt“).
                $topic = null;
                foreach ($m['topics'] as $t) {
                    if ($t['progress']['total'] > 0 && $t['progress']['percent'] < 100) {
                        $topic = $t;
                        break;
                    }
                }
                $p = $m['progress'];
                $continue = [
                    'module_id' => $m['id'],
                    'module_name' => $m['name'],
                    'mode' => 'smarttrainer',
                    'topic' => $topic['name'] ?? null,
                    'open' => $p['new'] + $p['unsure'] + $p['learning'],
                    'unsure' => $p['unsure'],
                    // Parameter für GET /courses/{id}/trainer/next, um genau dieses Thema zu üben.
                    'trainer_query' => $topic !== null ? ['mode' => 'topic', 'module_id' => $m['id'], 'topic' => $topic['name']] : ['mode' => 'module', 'module_id' => $m['id']],
                ];
                break;
            }
        }

        return $this->ok([
            'course' => $this->courses->summary($user, $course),
            'progress' => CourseMapper::progressSummary($progress['total']),
            'continue_learning' => $continue,
            'modules' => $modules,
            'limit' => $progress['limit'],
        ]);
    }

    public function modules(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);

        return $this->ok(self::modulesOf($this->learning->courseProgress($this->user(), $course)['modules']));
    }

    public function progress(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $this->requireFeature($course, AccessService::QUESTION_BANK);
        $progress = $this->learning->courseProgress($this->user(), $course);

        return $this->ok([
            'overall' => CourseMapper::progressSummary($progress['total']),
            'modules' => self::modulesOf($progress['modules']),
            'limit' => $progress['limit'],
        ]);
    }

    /**
     * Prüfungsanmeldung (nur lesen): Anleitung je Prüfung (Gebühr, Fristen, Unterlagen, Ablauf,
     * FAQ) und Prüfungsausschüsse über ExamGuideService::payload wie die Web-Seite
     * „Prüfung anmelden“. Anmelden können sich Lernende nur selbst beim Prüfungsausschuss –
     * die App sendet nichts.
     */
    public function examRegistration(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        if (! ExamGuideService::enabled()) {
            throw ApiException::notFound(__('Diese Seite ist nicht verfügbar.', 'bootspruefung-learning'));
        }
        $payload = (new ExamGuideService())->payload((string) $course->code);
        if ($payload['tabs'] === []) {
            throw ApiException::notFound(__('Für diesen Kurs gibt es keine Anleitung zur Prüfungsanmeldung.', 'bootspruefung-learning'));
        }

        return $this->ok([
            'course_id' => (string) $course->id,
            'web_url' => Router::url(strtolower((string) $course->code).'/pruefung-anmelden'),
            'registration_in_app' => false,
            'guides' => $payload['tabs'],
            'committees' => array_map(static fn ($c) => ['id' => (string) $c['id']] + $c, $payload['committees']),
            'committees_note' => $payload['committees_note'],
        ]);
    }

    /** @param list<array<string,mixed>> $modules @return list<array<string,mixed>> */
    public static function modulesOf(array $modules): array
    {
        return array_map([self::class, 'module'], $modules, array_keys($modules));
    }

    /** @param array<string,mixed> $m Modul aus LearningService::courseProgress() @return array<string,mixed> */
    public static function module(array $m, int $index = 0): array
    {
        return [
            'id' => (string) $m['id'],
            'code' => (string) $m['code'],
            'name' => (string) $m['name'],
            'description' => null,
            'order' => $index + 1,
            'question_count' => (int) $m['available'],
            'catalog_count' => (int) $m['catalog'],
            'locked' => (bool) $m['locked'],
            'lock_reason' => $m['locked'] ? 'drip' : ((int) $m['available'] < (int) $m['catalog'] ? 'trial' : null),
            'unlock_at' => CourseMapper::iso($m['unlock_at'] ?? null),
            'progress' => CourseMapper::progressSummary($m),
            'topics' => array_map(static fn ($t) => ['name' => (string) $t['name'], 'progress' => CourseMapper::progressSummary($t)], (array) ($m['topics'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public static function file(array $f): array
    {
        return [
            'id' => (string) $f['id'],
            'title' => (string) $f['title'],
            'description' => isset($f['description']) && $f['description'] !== '' ? (string) $f['description'] : null,
            'size_bytes' => $f['size'] ?? null,
            'locked' => (bool) ($f['locked'] ?? false),
        ] + self::fileUrl($f['url'] ?? null);
    }

    /**
     * Geschützte Dateien liefert das Web nur mit Cookie-Sitzung aus; die App lädt sie über
     * GET /files/{id} mit Bearer-Token (gleiche Prüfung DownloadService::allowed).
     * @return array{url:?string,requires_authorization:bool}
     */
    public static function fileUrl(?string $webUrl): array
    {
        if ($webUrl === null || preg_match('#/datei/(\d+)/?$#', $webUrl, $m) !== 1) {
            return ['url' => null, 'requires_authorization' => true];
        }

        return ['url' => rest_url('boatingschool/v1/files/'.$m[1]), 'requires_authorization' => true];
    }

    /**
     * Videokapitel „Knoten“ (SBF See, SBF Binnen, Kombi) als eigener Bereich. null, wenn der Kurs
     * keines hat. Ohne Videozugang: gesperrt mit Anzahl der Lektionen und Shop-Link; Lektionen
     * über GET /courses/{id}/videos (Kapitel module_id).
     * @param array<string,list<array<string,string>>> $offers
     * @return ?array<string,mixed>
     */
    private function knots(int $user, object $course, bool $videoAccess, array $offers): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT m.id, COUNT(l.id) AS lessons FROM {$wpdb->prefix}bp_video_module m ".Overrides::rightsJoin('video_module', 'm', 'o')."
             JOIN {$wpdb->prefix}bp_video_lesson l ON l.module_id = m.id AND l.status <> 'source_archived'
             WHERE m.course_id = %d AND m.status = 'published' AND m.title = 'Knoten'
               AND COALESCE(o.value, m.rights_status) NOT IN (".Overrides::blockingList().')
             GROUP BY m.id, m.sort_order ORDER BY m.sort_order LIMIT 1',
            $course->id
        ));
        if (! $row || (int) $row->lessons === 0) {
            return null;
        }
        $locked = ['available' => true, 'locked' => true, 'module_id' => (string) $row->id, 'lessons' => (int) $row->lessons, 'completed' => 0, 'unlock_at' => null,
            'webshop_url' => $offers[AccessService::VIDEO][0]['url'] ?? null];
        if (! $videoAccess) {
            return $locked;
        }
        try {
            $overview = (new VideoService($this->access, $this->content, $this->presenter))->course($user, $course);
        } catch (\Throwable $e) {
            return $locked;
        }
        foreach ($overview['modules'] as $m) {
            if ((int) $m['id'] === (int) $row->id) {
                return [
                    'available' => true,
                    'locked' => (bool) $m['locked'],
                    'module_id' => (string) $m['id'],
                    'lessons' => count($m['lessons']),
                    'completed' => count(array_filter($m['lessons'], static fn ($l) => $l['completed'])),
                    'unlock_at' => CourseMapper::iso($m['unlock_at']),
                    'webshop_url' => null,
                ];
            }
        }

        return $locked;
    }
}
