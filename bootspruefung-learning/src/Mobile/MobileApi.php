<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

use Bootspruefung\Learning\Mobile\Controllers\AccountController;
use Bootspruefung\Learning\Mobile\Controllers\AuthController;
use Bootspruefung\Learning\Mobile\Controllers\BaseController;
use Bootspruefung\Learning\Mobile\Controllers\CouponController;
use Bootspruefung\Learning\Mobile\Controllers\CourseController;
use Bootspruefung\Learning\Mobile\Controllers\ExamController;
use Bootspruefung\Learning\Mobile\Controllers\FaqController;
use Bootspruefung\Learning\Mobile\Controllers\FileController;
use Bootspruefung\Learning\Mobile\Controllers\GroupController;
use Bootspruefung\Learning\Mobile\Controllers\LearningController;
use Bootspruefung\Learning\Mobile\Controllers\PrivacyController;
use Bootspruefung\Learning\Mobile\Controllers\RadioController;
use Bootspruefung\Learning\Mobile\Controllers\SettingsController;
use Bootspruefung\Learning\Mobile\Controllers\TipsController;
use Bootspruefung\Learning\Mobile\Controllers\TrainingController;
use Bootspruefung\Learning\Mobile\Controllers\VoucherController;
use Bootspruefung\Learning\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST-API für die mobilen Lern-Apps unter /wp-json/boatingschool/v1/ (Vertrag:
 * docs/mobile-api/openapi.yaml). Nutzt dieselben Konten, Inhalte, Zugänge, Lernstände und
 * Prüfungsregeln wie der Web-Lernbereich – die Controller rufen nur die bestehenden Services auf.
 *
 * Authentifizierung: ausschließlich Bearer-Token der App (bp_mobile_session) und nur für diesen
 * Namespace; WordPress-Cookies werden hier ignoriert (kein CSRF-Risiko, keine Nonces nötig).
 */
final class MobileApi
{
    public const NAMESPACE = 'boatingschool/v1';
    public const API_VERSION = '1.12.0';

    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'determineUser'], 100);
        add_filter('rest_authentication_errors', [self::class, 'authenticationErrors'], 90);
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_pre_dispatch', [self::class, 'preDispatch'], 10, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'afterCallbacks'], 10, 3);
        add_filter('rest_post_dispatch', [self::class, 'postDispatch'], 10, 3);
        add_action('bp_learning_hourly', \Bootspruefung\Learning\Infrastructure\Cron::guard('mobile_cleanup', [self::class, 'cleanup']));
        // Datenexport im Hintergrund (Action Scheduler bzw. WP-Cron, seit 1.4.0).
        add_action(PrivacyController::GENERATE_HOOK, [PrivacyController::class, 'generate']);
        // Passwortänderung (Reset, Profil, WooCommerce „Konto-Details“) beendet alle App-Sitzungen.
        add_action('after_password_reset', static fn ($user) => $user instanceof \WP_User ? TokenAuth::revokeAll((int) $user->ID, 'password_change') : null);
        add_action('wp_set_password', [self::class, 'passwordSet'], 10, 3);
        add_action('profile_update', [self::class, 'profileUpdated'], 10, 2);
        // Sitzungsübergabe App → Web (?bp_app_login=…) und Universal/App Links (/.well-known, /app/passwort/).
        WebSession::register();
        AppLinks::register();
        if (is_admin()) {
            (new Admin\MobileAdminPage())->register();
        }
    }

    /**
     * Anfrage an /wp-json/boatingschool/v1/… (auch ?rest_route=/boatingschool/v1/…)?
     * Wie WordPress (WP::parse_request): Ein Parameter rest_route aus POST oder GET hat Vorrang
     * vor dem Pfad. Sonst gälte z. B. /wp-json/boatingschool/v1/x?rest_route=/wp/v2/users/me als
     * App-Anfrage, ausgeführt würde aber /wp/v2/… mit dem Nutzer aus dem Bearer-Token.
     */
    public static function isMobileRequest(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $route = array_key_exists('rest_route', $_POST) ? $_POST['rest_route'] : ($_GET['rest_route'] ?? null);
        if ($route !== null) {
            return is_string($route) && self::isOwnRoute('/'.ltrim(wp_unslash($route), '/'));
        }
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path, $home)) {
            $path = substr($path, strlen($home));
        }
        $prefix = '/'.rest_get_url_prefix();

        return str_starts_with($path, $prefix.'/') && self::isOwnRoute(substr($path, strlen($prefix)));
    }

    private static function isOwnRoute(string $route): bool
    {
        return $route === '/'.self::NAMESPACE || str_starts_with($route, '/'.self::NAMESPACE.'/');
    }

    /** Für App-Anfragen zählt nur das Bearer-Token (Cookies/Anwendungspasswörter werden ignoriert). */
    public static function determineUser($userId)
    {
        if (! self::isMobileRequest()) {
            return $userId;
        }

        return TokenAuth::resolve(TokenAuth::bearer());
    }

    /** Cookie-Prüfung von WordPress (Nonce) für diesen Namespace überspringen. */
    public static function authenticationErrors($result)
    {
        if (! self::isMobileRequest() || is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /** @param mixed $result */
    public static function preDispatch($result, $server, WP_REST_Request $request)
    {
        if (! self::ours($request)) {
            // Zweite Sicherung: Ein App-Token darf nie eine andere Route ausführen.
            if (TokenAuth::current() !== null) {
                return new WP_Error('rest_forbidden', __('Das App-Token gilt nur für die App-Schnittstelle.', 'bootspruefung-learning'), ['status' => 401]);
            }

            return $result;
        }
        if ($request->get_route() !== '/'.self::NAMESPACE.'/health') {
            if (! MobileSettings::enabled()) {
                return Problem::response('MOBILE_API_DISABLED', __('Die App ist gerade nicht verfügbar. Bitte nutze den Lernbereich auf der Website.', 'bootspruefung-learning'), 503);
            }
            if (self::httpsRequired()) {
                return Problem::response('HTTPS_REQUIRED', __('Die App-Schnittstelle ist nur über HTTPS erreichbar.', 'bootspruefung-learning'), 403);
            }
            $update = self::updateRequired($request);
            if ($update !== null) {
                return $update;
            }
        }

        return $result;
    }

    /**
     * Pflicht-Update: Schickt die App ihre Version (Header X-Boatingschool-App-Version und
     * X-Boatingschool-Platform: ios|android; sonst Werte der aktuellen Sitzung bzw. beim Login aus dem
     * Body) und liegt sie unter der Mindestversion der Plattform, antwortet die API mit 426.
     * Ohne Versionsangabe wird nichts blockiert. /health bleibt immer erreichbar.
     */
    private static function updateRequired(WP_REST_Request $request): ?WP_REST_Response
    {
        $platform = strtolower(trim((string) $request->get_header('x-boatingschool-platform')));
        $version = trim((string) $request->get_header('x-boatingschool-app-version'));
        if ($version === '' || $platform === '') {
            wp_get_current_user(); // Bearer-Token auflösen, falls noch nicht geschehen
            $session = TokenAuth::current();
            $platform = $platform !== '' ? $platform : strtolower((string) ($session->device_platform ?? $request->get_param('device_platform') ?? ''));
            $version = $version !== '' ? $version : (string) ($session->app_version ?? $request->get_param('app_version') ?? '');
        }
        if (! in_array($platform, ['ios', 'android'], true) || $version === '') {
            return null;
        }
        $min = MobileSettings::minAppVersion($platform);
        if ($min === null || preg_match(MobileSettings::VERSION_PATTERN, $version) !== 1 || version_compare($version, $min, '>=')) {
            return null;
        }

        return Problem::response('APP_UPDATE_REQUIRED', __('Bitte aktualisiere die App, um weiterzulernen.', 'bootspruefung-learning'), 426, ['details' => ['platform' => $platform, 'app_version' => $version, 'min_app_version' => $min]]);
    }

    /**
     * Website läuft unter https://, die Anfrage kam aber unverschlüsselt an (auch nicht über einen
     * TLS-Proxy mit X-Forwarded-Proto): Tokens nicht im Klartext annehmen. Lokale/Staging-Seiten
     * unter http:// bleiben nutzbar.
     */
    private static function httpsRequired(): bool
    {
        $insecure = str_starts_with((string) home_url('/'), 'https://') && ! is_ssl() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) !== 'https';

        return (bool) apply_filters('bootspruefung_mobile_require_https', $insecure);
    }

    /** Fehler aus Rechte-/Parameterprüfung und Callbacks ins Fehlerobjekt übersetzen. */
    public static function afterCallbacks($response, $handler, WP_REST_Request $request)
    {
        if (self::ours($request) && $response instanceof WP_Error) {
            return Problem::fromWpError($response);
        }

        return $response;
    }

    /** Einheitliche Header (kein Caching persönlicher Daten, Request-ID) und Fehlerformat. */
    public static function postDispatch($response, $server, WP_REST_Request $request)
    {
        if (! self::ours($request) || ! $response instanceof WP_REST_Response) {
            return $response;
        }
        $data = $response->get_data();
        if ($response->get_status() >= 400 && is_array($data) && isset($data['code'], $data['message']) && ! isset($data['request_id'])) {
            $response = Problem::fromWpError(new WP_Error((string) $data['code'], (string) $data['message'], $data['data'] ?? ['status' => $response->get_status()]));
        }
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Request-Id', Problem::requestId());
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Boatingschool-Api-Version', self::API_VERSION);

        return $response;
    }

    private static function ours(WP_REST_Request $request): bool
    {
        return self::isOwnRoute($request->get_route());
    }

    public static function requireUser(): bool|WP_Error
    {
        if (TokenAuth::current() !== null && get_current_user_id() > 0) {
            return true;
        }

        return new WP_Error(
            'UNAUTHENTICATED',
            TokenAuth::bearerPresented() ? __('Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.', 'bootspruefung-learning') : __('Bitte melde dich an.', 'bootspruefung-learning'),
            ['status' => 401]
        );
    }

    /**
     * wp_set_password: echte Änderung → alle App-Sitzungen beenden. Ein reines Neu-Hashen beim
     * Anmelden (gleiches Passwort, z. B. Umstellung auf bcrypt) beendet keine Sitzungen.
     */
    public static function passwordSet($password, $userId = 0, $oldUser = null): void
    {
        if ($oldUser instanceof \WP_User && is_string($password) && wp_check_password($password, (string) $oldUser->user_pass, (int) $userId)) {
            return;
        }
        TokenAuth::revokeAll((int) $userId, 'password_change');
    }

    public static function profileUpdated($userId, $oldUser = null): void
    {
        if (! $oldUser instanceof \WP_User) {
            return;
        }
        global $wpdb;
        $now = $wpdb->get_var($wpdb->prepare("SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", (int) $userId));
        if ($now !== null && ! hash_equals((string) $oldUser->user_pass, (string) $now)) {
            TokenAuth::revokeAll((int) $userId, 'password_change');
        }
    }

    public static function cleanup(): void
    {
        TokenAuth::cleanup();
        Idempotency::cleanup();
        PrivacyController::cleanup();
        WebSession::cleanup();
        RateLimiter::cleanup();
    }

    // ------------------------------------------------------------------ Routen

    public static function routes(): void
    {
        $id = '(?P<%s>\d+)';
        $course = '/courses/(?P<courseId>[A-Za-z0-9-]+)';
        $string = static fn (int $max = 190, bool $required = false): array => ['type' => 'string', 'maxLength' => $max, 'required' => $required];
        $nullableString = static fn (int $max = 190): array => ['type' => ['string', 'null'], 'maxLength' => $max, 'default' => null];
        $idValue = ['type' => ['string', 'integer', 'null'], 'default' => null];
        $ids = ['type' => 'array', 'items' => ['type' => ['string', 'integer']], 'maxItems' => 26, 'default' => []];
        $page = ['page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]];
        $bool = ['type' => ['boolean', 'null'], 'default' => null];

        // Öffentlich
        self::route('/health', 'GET', [self::class, 'health'], false, false);
        self::route('/auth/login', 'POST', [AuthController::class, 'login'], false, false, [
            'email' => $string(190), 'password' => ['type' => 'string', 'maxLength' => 4096], 'device_name' => $string(100),
            'device_platform' => ['type' => ['string', 'null'], 'enum' => ['ios', 'android', null], 'default' => null], 'app_version' => $nullableString(40),
        ]);
        self::route('/auth/refresh', 'POST', [AuthController::class, 'refresh'], false, false, ['refresh_token' => $string(300, true)]);
        self::route('/auth/password/forgot', 'POST', [AuthController::class, 'forgotPassword'], false, false, ['email' => $string(190, true)]);
        self::route('/auth/password/reset', 'POST', [AuthController::class, 'resetPassword'], false, false, [
            'email' => $string(190), 'login' => $string(60), 'token' => $string(200, true), 'password' => ['type' => 'string', 'maxLength' => 4096, 'required' => true], 'password_confirmation' => ['type' => 'string', 'maxLength' => 4096, 'required' => true],
        ]);

        // Konto (ohne Tenant)
        self::route('/auth/logout', 'POST', [AuthController::class, 'logout'], true, false);
        self::route('/me', 'GET', [AccountController::class, 'me'], true, false);
        self::route('/me/sessions', 'GET', [AccountController::class, 'sessions'], true, false);
        self::route('/me/sessions', 'DELETE', [AccountController::class, 'revokeOthers'], true, false);
        self::route('/me/sessions/'.sprintf($id, 'sessionId'), 'DELETE', [AccountController::class, 'revokeSession'], true, false);
        self::route('/me/deletion-request', 'POST', [PrivacyController::class, 'deletionRequest'], true, false);
        // Bestätigung aus dem Mail-Link (App-Link /app/konto-loeschen/): ohne Anmeldung, wie Passwort-Reset.
        self::route('/me/deletion-request/confirm', 'POST', [PrivacyController::class, 'deletionConfirm'], false, false, [
            'request_id' => ['type' => ['string', 'integer'], 'required' => true], 'confirm_key' => $string(100, true),
        ]);
        self::route('/me/settings', 'GET', [SettingsController::class, 'show'], true, false);
        self::route('/me/settings', 'PUT', [SettingsController::class, 'replace'], true, false);
        self::route('/auth/web-session', 'POST', [AuthController::class, 'webSession'], true, false, ['redirect' => $string(400, true)]);
        self::route('/files/'.sprintf($id, 'fileId'), 'GET', [FileController::class, 'download'], true, false);
        self::route('/profile/export/(?P<exportId>[0-9a-f]{32})/download', 'GET', [PrivacyController::class, 'download'], true, false);
        // Gruppenlizenzen verwalten (seit 1.7.0, wie „Meine Gruppen“ im Web; nur die besitzende Person)
        $group = '/me/groups/'.sprintf($id, 'groupId');
        self::route('/me/groups', 'GET', [GroupController::class, 'list'], true, false);
        self::route($group, 'GET', [GroupController::class, 'show'], true, false);
        self::route($group.'/invites', 'POST', [GroupController::class, 'invite'], true, false, [
            'emails' => ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'maxLength' => 190], 'maxItems' => 200, 'default' => null], 'email' => $nullableString(190),
        ]);
        self::route($group.'/members/'.sprintf($id, 'memberId'), 'DELETE', [GroupController::class, 'removeMember'], true, false, ['reason' => $nullableString(190)]);
        self::route($group.'/join-code', 'POST', [GroupController::class, 'regenerateJoinCode'], true, false);

        // Kurse
        self::route('/courses', 'GET', [CourseController::class, 'list'], true, true, $page);
        self::route($course, 'GET', [CourseController::class, 'show']);
        self::route($course.'/overview', 'GET', [CourseController::class, 'overview']);
        self::route($course.'/modules', 'GET', [CourseController::class, 'modules']);
        self::route($course.'/progress', 'GET', [CourseController::class, 'progress']);
        self::route($course.'/exam-registration', 'GET', [CourseController::class, 'examRegistration']);

        // Lernen
        self::route($course.'/trainer/next', 'GET', [LearningController::class, 'next'], true, true, [
            'mode' => ['type' => 'string', 'enum' => ['smarttrainer', 'new', 'wrong', 'topic', 'module', 'favorites', 'collection', 'exam_mistakes', 'question'], 'default' => 'smarttrainer'],
            'module_id' => $idValue, 'topic' => $nullableString(190), 'collection_id' => $idValue, 'exam_id' => $idValue, 'question_id' => $idValue,
        ]);
        self::route($course.'/trainer/reveal', 'POST', [LearningController::class, 'reveal'], true, true, ['answer_token' => $string(2000, true)]);
        self::route($course.'/attempts', 'POST', [LearningController::class, 'attempt'], true, true, [
            'answer_token' => $string(2000, true), 'question_id' => $idValue, 'revision_id' => $idValue, 'selected_answer_ids' => $ids, 'known' => $bool,
            'mode' => $nullableString(32), 'client_answered_at' => $nullableString(40), 'response_ms' => ['type' => ['integer', 'null'], 'minimum' => 0, 'default' => null],
        ]);
        self::route($course.'/attempts/'.sprintf($id, 'attemptId'), 'GET', [LearningController::class, 'attemptShow']);
        self::route($course.'/favorites', 'GET', [LearningController::class, 'favorites'], true, true, [
            'collection_id' => $idValue,
            'sort' => ['type' => 'string', 'enum' => ['saved_at', 'assigned_at'], 'default' => 'saved_at'],
            'order' => ['type' => 'string', 'enum' => ['desc', 'asc'], 'default' => 'desc'],
        ]);
        self::route($course.'/mistakes', 'GET', [LearningController::class, 'mistakes']);
        self::route($course.'/favorites/'.sprintf($id, 'questionId'), 'PUT', [LearningController::class, 'favoriteAdd'], true, true, [
            'collection_id' => $idValue, 'collection_ids' => ['type' => ['array', 'null'], 'items' => ['type' => ['string', 'integer']], 'maxItems' => 30, 'default' => null], 'context' => $nullableString(32),
        ]);
        self::route($course.'/favorites/'.sprintf($id, 'questionId'), 'DELETE', [LearningController::class, 'favoriteRemove']);
        self::route($course.'/favorite-collections', 'GET', [LearningController::class, 'collectionsList']);
        self::route($course.'/favorite-collections', 'POST', [LearningController::class, 'collectionCreate'], true, true, ['name' => $string(200, true)]);
        self::route($course.'/favorite-collections/'.sprintf($id, 'collectionId'), 'PATCH', [LearningController::class, 'collectionRename'], true, true, ['name' => $string(200, true)]);
        self::route($course.'/favorite-collections/'.sprintf($id, 'collectionId'), 'DELETE', [LearningController::class, 'collectionDelete']);

        // Prüfungen
        self::route($course.'/exams', 'GET', [ExamController::class, 'list']);
        self::route($course.'/exams', 'POST', [ExamController::class, 'start'], true, true, [
            'mode' => ['type' => ['string', 'null'], 'enum' => ['official_simulation', 'simulation', 'paper', null], 'default' => null], 'paper_id' => $idValue,
        ]);
        self::route('/exams/'.sprintf($id, 'examId'), 'GET', [ExamController::class, 'show']);
        self::route('/exams/'.sprintf($id, 'examId').'/answers/'.sprintf($id, 'questionId'), 'PUT', [ExamController::class, 'answer'], true, true, [
            'selected_answer_ids' => $ids, 'known' => $bool, 'marked_for_review' => $bool,
            // Seit 1.11.0: offline gepufferte Antwort (Alter beim Senden in ms bzw. Gerätezeit), siehe ExamService::saveAnswer.
            'age_ms' => ['type' => ['integer', 'null'], 'minimum' => 0, 'default' => null], 'answered_at' => ['type' => ['string', 'null'], 'maxLength' => 40, 'default' => null],
        ]);
        self::route('/exams/'.sprintf($id, 'examId').'/answers/'.sprintf($id, 'questionId').'/reveal', 'POST', [ExamController::class, 'reveal']);
        self::route('/exams/'.sprintf($id, 'examId').'/answers/'.sprintf($id, 'questionId').'/flag', 'PUT', [ExamController::class, 'flag'], true, true, ['flagged' => ['type' => 'boolean', 'required' => true]]);
        self::route('/exams/'.sprintf($id, 'examId').'/submit', 'POST', [ExamController::class, 'submit']);
        self::route('/exams/'.sprintf($id, 'examId').'/abort', 'POST', [ExamController::class, 'abort']);
        self::route('/exams/'.sprintf($id, 'examId').'/result', 'GET', [ExamController::class, 'result']);

        // Videos, Navigation, Praxis
        self::route($course.'/videos', 'GET', [TrainingController::class, 'videoModules']);
        self::route('/videos/'.sprintf($id, 'lessonId'), 'GET', [TrainingController::class, 'video']);
        self::route('/videos/'.sprintf($id, 'lessonId').'/progress', 'PUT', [TrainingController::class, 'videoProgress'], true, true, [
            'position_seconds' => ['type' => 'integer', 'minimum' => 0, 'default' => 0], 'completed' => $bool,
        ]);
        self::route($course.'/navigation-tasks', 'GET', [TrainingController::class, 'navigationTasks'], true, true, $page);
        self::route('/navigation-tasks/'.sprintf($id, 'taskId'), 'GET', [TrainingController::class, 'navigationTask'], true, true, [
            'course_id' => $idValue, 'show_solution' => ['type' => ['boolean', 'string'], 'default' => false],
        ]);
        self::route('/navigation-tasks/'.sprintf($id, 'taskId').'/parts/'.sprintf($id, 'partId'), 'POST', [TrainingController::class, 'navigationAnswer'], true, true, [
            'course_id' => $idValue, 'answer' => $nullableString(5000), 'self_assessment' => ['type' => ['string', 'null'], 'enum' => ['correct', 'partly', 'wrong', null], 'default' => null],
        ]);
        self::route($course.'/praxis', 'GET', [TrainingController::class, 'praxis']);
        self::route($course.'/praxis/'.sprintf($id, 'cardId'), 'PUT', [TrainingController::class, 'praxisStatus'], true, true, [
            'status' => ['type' => 'string', 'enum' => ['known', 'practice', 'open'], 'required' => true],
        ]);

        // Funktraining SRC/UBI (wie TrainerController im Web; Schlüssel = Quell-IDs, z. B. TXT-SRC-01, SRC-01)
        $key = static fn (string $name): string => '(?P<'.$name.'>[A-Za-z0-9-]{1,64})';
        $done = ['done' => ['type' => 'boolean', 'required' => true]];
        self::route($course.'/radio', 'GET', [RadioController::class, 'overview']);
        self::route($course.'/radio/spelling', 'GET', [RadioController::class, 'spelling']);
        self::route($course.'/radio/texts/'.$key('textId'), 'GET', [RadioController::class, 'text'], true, true, [
            'direction' => ['type' => 'string', 'enum' => ['de-en', 'en-de', ''], 'default' => ''],
        ]);
        self::route($course.'/radio/texts/'.$key('textId').'/evaluate', 'POST', [RadioController::class, 'textEvaluate'], true, true, [
            'direction' => ['type' => 'string', 'enum' => ['de-en', 'en-de', ''], 'default' => ''],
            'transcript' => ['type' => 'string', 'maxLength' => 5000, 'default' => ''], 'translation' => ['type' => 'string', 'maxLength' => 5000, 'default' => ''],
        ]);
        self::route($course.'/radio/texts/'.$key('textId').'/done', 'PUT', [RadioController::class, 'textDone'], true, true, $done);
        self::route($course.'/radio/vocabulary', 'GET', [RadioController::class, 'vocabulary'], true, true, [
            'catalog' => ['type' => 'string', 'enum' => ['SRC', 'UBI', ''], 'default' => ''], 'category' => $nullableString(191),
        ]);
        self::route($course.'/radio/vocabulary/'.$key('cardId').'/answers', 'POST', [RadioController::class, 'vocabularyAnswer'], true, true, [
            'known' => ['type' => 'boolean', 'required' => true], 'streak' => ['type' => ['integer', 'null'], 'minimum' => 0, 'default' => null],
        ]);
        self::route($course.'/radio/practice', 'GET', [RadioController::class, 'practiceList']);
        self::route($course.'/radio/practice/'.$key('scenarioId'), 'GET', [RadioController::class, 'practiceShow']);
        self::route($course.'/radio/practice/'.$key('scenarioId').'/evaluate', 'POST', [RadioController::class, 'practiceEvaluate'], true, true, [
            'answers' => ['type' => 'object', 'required' => true],
        ]);
        self::route($course.'/radio/practice/'.$key('scenarioId').'/done', 'PUT', [RadioController::class, 'practiceDone'], true, true, $done);
        self::route($course.'/radio/samples', 'GET', [RadioController::class, 'samples']);

        // Kunden-FAQ eines Kurses (seit 1.10.0, wie „Fragen & Antworten zu deinem Kurs“ im Kurs-Hub)
        self::route($course.'/faqs', 'GET', [FaqController::class, 'list']);

        // Tipps & Tricks (seit 1.7.0, wie /bootsscheine/tipps/)
        self::route('/tips', 'GET', [TipsController::class, 'list']);

        // Codes und Datenschutz
        self::route('/coupons/redeem', 'POST', [CouponController::class, 'redeem'], true, true, ['code' => $string(300, true)]);
        // Kursgutscheine in „Mein Lernen“ (seit 1.12.0): Vorschau und Einlösung über einen Gutschein-Connector.
        $voucher = ['code' => $string(100, true), 'course_id' => $idValue];
        self::route('/vouchers/check', 'POST', [VoucherController::class, 'check'], true, true, $voucher);
        self::route('/vouchers/redeem', 'POST', [VoucherController::class, 'redeem'], true, true, $voucher);
        // Vorschau eines Einladungslinks (seit 1.7.0, wie GET bootspruefung/v1/groups/join/{token}); Beitritt über /coupons/redeem
        self::route('/groups/join/(?P<token>[A-Za-z0-9]{1,64})', 'GET', [GroupController::class, 'joinPreview']);
        self::route('/profile/export', 'POST', [PrivacyController::class, 'requestExport']);
        self::route('/profile/export/(?P<exportId>[0-9a-f]{32})', 'GET', [PrivacyController::class, 'export']);
    }

    /**
     * @param array{0:class-string<BaseController>|class-string,1:string} $handler
     * @param array<string,array<string,mixed>> $args
     */
    private static function route(string $path, string $method, array $handler, bool $auth = true, bool $tenant = true, array $args = []): void
    {
        [$class, $name] = $handler;
        $callback = $class === self::class
            ? static fn (WP_REST_Request $r) => self::$name($r)
            : static fn (WP_REST_Request $r) => (new $class())->$name($r);
        register_rest_route(self::NAMESPACE, $path, [
            'methods' => $method,
            'callback' => BaseController::wrap($callback, $tenant),
            'permission_callback' => $auth ? [self::class, 'requireUser'] : '__return_true',
            'args' => $args,
        ]);
    }

    /** Erreichbarkeit und Server-Voraussetzungen (kommt der Authorization-Header bei PHP an?). */
    public static function health(): WP_REST_Response
    {
        return new WP_REST_Response(['data' => [
            'status' => MobileSettings::enabled() ? 'ok' : 'disabled',
            'api_version' => self::API_VERSION,
            'plugin_version' => Plugin::VERSION,
            'tenant_slug' => MobileSettings::tenantSlug(),
            'tenant_name' => MobileSettings::tenantName(),
            'authorization_header_received' => TokenAuth::authorizationHeader() !== '',
            'https' => is_ssl() || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            // Ohne Anmeldung nutzbar (z. B. Links auf dem Anmeldebildschirm, Pflicht-Update vor dem Login).
            'legal' => Controllers\AccountController::legalLinks(),
            'min_app_version' => ['ios' => MobileSettings::minAppVersion('ios'), 'android' => MobileSettings::minAppVersion('android')],
        ]], 200);
    }
}
