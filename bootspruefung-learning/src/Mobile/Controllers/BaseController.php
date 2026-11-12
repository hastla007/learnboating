<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Exams\Services\ExamService;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\CollectionService;
use Bootspruefung\Learning\Learning\Services\LearningService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Mapper\CourseMapper;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Mobile\Problem;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\DomainException;
use Bootspruefung\Learning\Support\NotFound;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Gemeinsame Bausteine der App-Controller: dieselben Services wie der Web-Lernbereich
 * (LearnerController/TrainerController), Tenant- und Kursprüfung, Antwort-Hülle {data, meta}
 * und Übersetzung fachlicher Fehler ins Fehlerobjekt.
 */
abstract class BaseController
{
    protected ContentRepository $content;
    protected AccessService $access;
    protected QuestionPresenter $presenter;
    protected LearningService $learning;
    protected ExamService $exams;
    protected CollectionService $collections;
    protected CourseMapper $courses;

    /** @var array<int,object>|null veröffentlichte Kurse nach ID */
    private ?array $courseList = null;

    public function __construct()
    {
        $this->content = new ContentRepository();
        $this->access = new AccessService();
        $this->presenter = new QuestionPresenter($this->content);
        $this->learning = new LearningService($this->content, $this->presenter, $this->access);
        $this->exams = new ExamService($this->content, $this->presenter, $this->access);
        $this->collections = new CollectionService($this->learning, $this->content);
        $this->courses = new CourseMapper($this->access, $this->learning);
    }

    protected function user(): int
    {
        return get_current_user_id();
    }

    /** @param array<string,mixed>|object|null $meta */
    protected function ok(mixed $data, int $status = 200, mixed $meta = null): WP_REST_Response
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return new WP_REST_Response($body, $status);
    }

    protected function noContent(): WP_REST_Response
    {
        return new WP_REST_Response(null, 204);
    }

    /** X-Tenant-Slug muss zum Shop passen; Mitgliedschaft = angemeldetes Kundenkonto. */
    public static function checkTenant(WP_REST_Request $request): void
    {
        $slug = strtolower(trim((string) $request->get_header('x_tenant_slug')));
        if ($slug === '') {
            throw ApiException::validation(['X-Tenant-Slug' => [__('Bitte sende den Header X-Tenant-Slug der gewählten Bootsschule mit.', 'bootspruefung-learning')]]);
        }
        if (! hash_equals(MobileSettings::tenantSlug(), $slug)) {
            throw new ApiException('TENANT_MEMBERSHIP_REQUIRED', __('Mit diesem Konto bist du bei dieser Bootsschule nicht angemeldet.', 'bootspruefung-learning'), 403);
        }
    }

    /** @return array<int,object> */
    protected function publishedCourses(): array
    {
        if ($this->courseList === null) {
            $this->courseList = [];
            foreach ($this->content->courses() as $course) {
                $this->courseList[(int) $course->id] = $course;
            }
        }

        return $this->courseList;
    }

    /** Kurs aus courseId (numerische ID; Kurscode wird ebenfalls akzeptiert) oder 404. */
    protected function course(WP_REST_Request $request, string $param = 'courseId'): object
    {
        $value = trim((string) $request[$param]);
        $courses = $this->publishedCourses();
        if (ctype_digit($value) && isset($courses[(int) $value])) {
            return $courses[(int) $value];
        }
        foreach ($courses as $course) {
            if (strcasecmp((string) $course->code, $value) === 0) {
                return $course;
            }
        }
        throw ApiException::notFound(__('Kurs nicht gefunden.', 'bootspruefung-learning'));
    }

    protected function courseById(int $id): object
    {
        $course = $this->publishedCourses()[$id] ?? null;
        if ($course === null) {
            throw ApiException::notFound(__('Kurs nicht gefunden.', 'bootspruefung-learning'));
        }

        return $course;
    }

    /**
     * Bereich (Fähigkeit) muss freigeschaltet sein – sonst 403 mit Grund und Shop-Angebot.
     * Gleiche Prüfung wie im Web: AccessService::can (voll oder Teilumfang).
     */
    protected function requireFeature(object $course, string $feature): void
    {
        if ($this->access->can($this->user(), (string) $course->code, $feature)) {
            return;
        }
        throw $this->featureDenied($course, $feature);
    }

    /**
     * Fehler für einen nicht freigeschalteten Bereich: ENTITLEMENT_REQUIRED/EXPIRED/SUSPENDED mit Grund und
     * Shop-Angebot ($message ersetzt die Standardmeldung bei ENTITLEMENT_REQUIRED).
     */
    protected function featureDenied(object $course, string $feature, ?string $message = null): ApiException
    {
        $access = $this->courses->access($this->user(), $course);
        $cap = array_search($feature, CourseMapper::CAPABILITIES, true);
        $code = match ($access['status']) {
            'expired' => 'ENTITLEMENT_EXPIRED',
            'suspended' => 'ENTITLEMENT_SUSPENDED',
            default => 'ENTITLEMENT_REQUIRED',
        };
        $message = match ($code) {
            'ENTITLEMENT_EXPIRED' => __('Dein Zugang zu diesem Kurs ist abgelaufen.', 'bootspruefung-learning'),
            'ENTITLEMENT_SUSPENDED' => __('Dein Zugang zu diesem Kurs ist derzeit ausgesetzt. Bitte wende dich an den Support.', 'bootspruefung-learning'),
            default => $access['status'] === 'pending' ? __('Deine Zahlung ist noch offen. Nach dem Zahlungseingang wird dieser Bereich automatisch freigeschaltet.', 'bootspruefung-learning') : ($message ?? __('Dieser Bereich ist für deinen Kurs nicht freigeschaltet.', 'bootspruefung-learning')),
        };
        $offers = $this->courses->offers($this->user(), $course);

        return new ApiException($code, $message, 403, ['details' => [
            'course_id' => (string) $course->id,
            'capability' => $cap !== false ? $cap : $feature,
            'access_status' => $access['status'],
            // Zahlung offen/gesperrt: kein Kaufangebot (kein Doppelkauf); abgelaufen: Verlängern (gekaufte Stufe; Videokurs wie bisher).
            'webshop_url' => in_array($access['status'], ['pending', 'suspended'], true) ? null
                : ($access['status'] === 'expired' && $access['renew_url'] !== null && $feature !== \Bootspruefung\Learning\Learning\Services\AccessService::VIDEO ? $access['renew_url'] : ($offers[$cap !== false ? $cap : $feature][0]['url'] ?? null)),
        ]]);
    }

    /** Idempotency-Key (UUID) aus dem Header; null, wenn optional und nicht gesendet. */
    protected function idempotencyKey(WP_REST_Request $request, bool $required): ?string
    {
        $key = strtolower(trim((string) $request->get_header('idempotency_key')));
        if ($key === '') {
            if ($required) {
                throw ApiException::validation(['Idempotency-Key' => [__('Bitte sende einen Idempotency-Key (UUID) mit.', 'bootspruefung-learning')]]);
            }

            return null;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key) !== 1) {
            throw ApiException::validation(['Idempotency-Key' => [__('Der Idempotency-Key muss eine UUID sein.', 'bootspruefung-learning')]]);
        }

        return $key;
    }

    /** @return array{page:int,per_page:int} */
    protected static function paging(WP_REST_Request $request): array
    {
        return ['page' => max(1, (int) ($request['page'] ?: 1)), 'per_page' => max(1, min(100, (int) ($request['per_page'] ?: 20)))];
    }

    /** @param list<mixed> $items @return array{0:list<mixed>,1:array<string,int>} */
    protected static function paginate(array $items, WP_REST_Request $request): array
    {
        ['page' => $page, 'per_page' => $per] = self::paging($request);
        $total = count($items);

        return [array_slice($items, ($page - 1) * $per, $per), ['page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $per))]];
    }

    /**
     * Ruft den Controller auf und übersetzt fachliche Fehler der Services:
     * AccessDenied (Sperre trotz Kurszugang: Teilumfang, zeitgesteuerte Freigabe) → 403 CONTENT_LOCKED,
     * NotFound → 404, Fachfehler mit Code (DomainException: Prüfung beendet/abgelaufen, Rate-Limit,
     * abgelaufenes Frage-Token …) auf eigene Codes, sonstige Fachfehler → 422.
     */
    public static function wrap(callable $callback, bool $tenant): callable
    {
        return static function (WP_REST_Request $request) use ($callback, $tenant) {
            try {
                if ($tenant) {
                    self::checkTenant($request);
                }
                $result = $callback($request);

                return $result instanceof WP_REST_Response ? $result : new WP_REST_Response(['data' => $result], 200);
            } catch (ApiException $e) {
                return Problem::fromException($e);
            } catch (AccessDenied $e) {
                return Problem::response('CONTENT_LOCKED', $e->getMessage(), 403);
            } catch (NotFound $e) {
                return Problem::response('RESOURCE_NOT_FOUND', $e->getMessage(), 404);
            } catch (DomainException $e) {
                return self::domainError($e);
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                return Problem::response('VALIDATION_FAILED', $e->getMessage(), 422);
            } catch (\Throwable $e) {
                error_log('[bootspruefung-learning mobile] '.Problem::requestId().' '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());

                return Problem::response('INTERNAL_ERROR', __('Es ist ein Fehler aufgetreten. Bitte versuche es später erneut.', 'bootspruefung-learning'), 500);
            }
        };
    }

    /**
     * Fachfehler der Services mit stabilem Code (Support\DomainException::errorCode) auf die
     * Fehlercodes der App abbilden. Bis 1.3.x wurde hier der (deutsche) Meldungstext verglichen;
     * seit 1.4.0 zählt nur noch der Code – Texte dürfen sich ändern, ohne die App zu brechen.
     * Fachfehler ohne Code (RuntimeException) bleiben 422 VALIDATION_FAILED mit der Meldung.
     */
    public static function domainError(DomainException $e): WP_REST_Response
    {
        $hit = match ($e->errorCode()) {
            DomainException::EXAM_EXPIRED => ['EXAM_EXPIRED', 410],
            DomainException::EXAM_FINISHED => ['EXAM_ALREADY_SUBMITTED', 409],
            DomainException::EXAM_ANSWER_LOCKED => ['EXAM_ANSWER_LOCKED', 409],
            DomainException::EXAM_NOT_STARTABLE => ['EXAM_NOT_STARTABLE', 409],
            DomainException::EXAM_START_BUSY => ['REQUEST_IN_PROGRESS', 409],
            DomainException::ANSWER_RATE_LIMITED => ['RATE_LIMITED', 429],
            DomainException::QUESTION_TOKEN_EXPIRED => ['QUESTION_TOKEN_EXPIRED', 409],
            default => ['VALIDATION_FAILED', 422],
        };
        $extra = match ($hit[0]) {
            'RATE_LIMITED' => ['retry_after' => 60],
            'REQUEST_IN_PROGRESS' => ['retry_after' => 2],
            default => [],
        };

        return Problem::response($hit[0], $e->getMessage(), $hit[1], $extra);
    }
}
