<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Faq\CustomerFaq;
use Bootspruefung\Learning\Faq\CustomerFaqRepository;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Kunden-FAQ eines Kurses (seit 1.10.0): dieselben veröffentlichten FAQs wie im Kurs-Hub des Web-Lernbereichs
 * (GET bootspruefung/v1/courses/{code}/customer-faqs, CustomerFaqRepository::forCourse) – gruppiert nach Schein
 * (Kombikurse: Teilscheine, Ergänzungen: Zielschein) und Unterkategorie. Je FAQ HTML (wp_kses_post, Links
 * absolut), Klartext und die enthaltenen Links wie bei den Tipps.
 *
 * Nur für Kunden des Kurses (voller Zugang zum Fragenkatalog): kostenloser Teilumfang → 403 CONTENT_LOCKED
 * (details.reason = partial_access), sonst 403 ENTITLEMENT_REQUIRED/EXPIRED/SUSPENDED wie bei anderen Bereichen.
 */
final class FaqController extends BaseController
{
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->course($request);
        $user = $this->user();
        $code = (string) $course->code;
        if (! CustomerFaq::isCustomer($user, $code, $this->access)) {
            $scope = $this->access->scope($user, $code, AccessService::QUESTION_BANK);
            if ((int) ($scope['limit'] ?? 0) > 0) {
                $offers = $this->courses->offers($user, $course);
                throw new ApiException('CONTENT_LOCKED', CustomerFaq::deniedMessage(), 403, ['details' => [
                    'course_id' => (string) $course->id, 'capability' => 'smarttrainer', 'reason' => 'partial_access',
                    'access_status' => 'limited', 'webshop_url' => $offers['smarttrainer'][0]['url'] ?? null,
                ]]);
            }
            throw $this->featureDenied($course, AccessService::QUESTION_BANK, CustomerFaq::deniedMessage());
        }
        $faqs = 0;
        $map = static function (array $list) use (&$faqs): array {
            $faqs += count($list);

            return array_map(static function (array $f): array {
                $html = TipsController::absoluteLinks((string) $f['answer_html']);

                return ['id' => (string) $f['id'], 'question' => (string) $f['question'], 'answer_html' => $html, 'answer_text' => TipsController::plainText($html), 'links' => TipsController::links($html)];
            }, $list);
        };
        $groups = [];
        foreach (CustomerFaqRepository::forCourse($code) as $group) {
            $groups[] = [
                'id' => (string) $group['id'],
                'key' => (string) $group['key'],
                'name' => (string) $group['name'],
                'slug' => (string) $group['slug'],
                'faqs' => $map($group['faqs']),
                'subcategories' => array_map(static fn (array $s): array => [
                    'id' => (string) $s['id'], 'name' => (string) $s['name'], 'slug' => (string) $s['slug'],
                    'parent_id' => (string) $s['parent'], 'depth' => (int) $s['depth'], 'faqs' => $map($s['faqs']),
                ], $group['subcategories']),
            ];
        }

        return $this->ok($groups, 200, ['course_id' => (string) $course->id, 'groups' => count($groups), 'faqs' => $faqs]);
    }
}
