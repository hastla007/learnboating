<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Idempotency;
use Bootspruefung\Learning\Trainers\DeviceSimService;
use Bootspruefung\Learning\Trainers\RadioPracticeService;
use Bootspruefung\Learning\Trainers\RadioService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Funktraining SRC/UBI (Seefunktexte, Diktat/Buchstabieren, Vokabeln, Buchstabiertafel,
 * Funkpraxis, Muster-Funksprüche) über RadioService und RadioPracticeService – dieselben
 * Aufrufe wie TrainerController im Web, daher derselbe Lernstand (bp_radio_progress),
 * dieselben Sperren (Teilumfang je Übungsart/Katalog) und dieselbe Auswertung.
 * Der Funkgerät-Simulator hat bewusst keine Bedien-API: die App verlinkt ihn im Browser.
 */
final class RadioController extends BaseController
{
    private RadioService $radio;
    private RadioPracticeService $practice;
    private DeviceSimService $device;

    public function __construct()
    {
        parent::__construct();
        $this->radio = new RadioService($this->access);
        $this->practice = new RadioPracticeService($this->access);
        $this->device = new DeviceSimService($this->access, $this->practice);
    }

    // ------------------------------------------------------------------ Übersicht

    public function overview(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $o = $this->radio->overview($this->user(), $course);

        return $this->ok([
            'course_id' => (string) $course->id,
            'catalogs' => $this->practice->catalogsFor($course),
            'texts' => array_map(static fn ($g) => [
                'catalog' => (string) $g['catalog'],
                'type' => (string) $g['type'],
                'items' => array_map(static fn ($i) => [
                    'id' => (string) $i['id'],
                    'number' => (int) $i['number'],
                    'direction' => $i['direction'] !== null ? (string) $i['direction'] : null,
                    'teaser' => $i['teaser'] !== null ? (string) $i['teaser'] : null,
                    'done' => (bool) $i['done'],
                    'locked' => (bool) $i['locked'],
                ], $g['items']),
            ], $o['texts']),
            'vocabulary' => array_map(static fn ($v) => [
                'catalog' => (string) $v['catalog'],
                'category' => (string) $v['category'],
                'total' => (int) $v['total'],
                'learned' => (int) $v['learned'],
            ], $o['vocabulary']),
            'practice' => array_map([self::class, 'practiceCatalog'], $o['practice']),
            // Simulator-Fortschritt wie die Funk-Seite im Web (sichtbare Lektionen, davon gemeistert).
            'device_simulator' => self::simulator($this->user(), $course, $this->device, $this->courses->offers($this->user(), $course))
                + ['lessons' => (int) ($o['device']['lessons'] ?? 0), 'mastered' => (int) ($o['device']['mastered'] ?? 0)],
        ], 200, ['limit' => $this->access->limit($this->user(), (string) $course->code, AccessService::RADIO), 'streak_to_learn' => RadioService::VOCAB_STREAK_TO_LEARN]);
    }

    public function spelling(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);

        return $this->ok($this->radio->spelling($this->user(), $course));
    }

    // ------------------------------------------------------------------ Funktexte

    /**
     * Funktext. Übersetzungen: Die Lösung (Zielsprache) liefert der Server erst nach der
     * Auswertung. Die Richtung wählt die App (`direction`, Standard de-en wie im Web); vor der
     * ersten Auswertung in dieser Richtung ist die Zielsprache null (`solution_hidden: true`),
     * danach – wie beim erneuten Öffnen im Web – enthalten. Die Lösung steht außerdem in der
     * Antwort von POST …/evaluate (`solution.to`).
     */
    public function text(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $t = $this->radio->text($this->user(), $course, (string) $request['textId']);
        $translation = $t['type'] === 'translation';
        $textDe = $t['text_de'];
        $textEn = $t['text_en'];
        $lines = $t['lines'];
        $direction = null;
        $hidden = false;
        $answers = $t['answers'] ?: [];
        if ($translation) {
            $direction = (string) $request['direction'] !== '' ? (string) $request['direction'] : 'de-en';
            $hidden = empty($answers[$direction]);
            if ($hidden) {
                if ($direction === 'de-en') {
                    $textEn = null;
                } else {
                    $textDe = null;
                    $lines = null;
                }
            }
        }

        return $this->ok([
            'id' => (string) $t['id'],
            'course_id' => (string) $course->id,
            'catalog' => (string) $t['catalog'],
            'type' => (string) $t['type'],
            'number' => (int) $t['number'],
            'direction' => $t['direction'],
            'translation_direction' => $direction,
            'solution_hidden' => $hidden,
            'evaluable' => in_array($t['type'], ['translation', 'dictation'], true),
            'text_de' => $textDe,
            'text_en' => $textEn,
            'lines' => $lines,
            'answers' => (object) $answers,
            'done' => (bool) $t['done'],
            'previous_id' => $t['previous'],
            'next_id' => $t['next'],
        ]);
    }

    /** Mitschrift/Übersetzung auswerten (RadioService::evaluate speichert die Eingabe wie im Web). */
    public function textEvaluate(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $r = $this->radio->evaluate($this->user(), $course, (string) $request['textId'], (string) $request['direction'], (string) $request['transcript'], (string) $request['translation']);

        return $this->ok(['text_id' => (string) $request['textId']] + $r);
    }

    public function textDone(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $this->radio->setDone($this->user(), $course, (string) $request['textId'], (bool) $request['done']);

        return $this->ok(['text_id' => (string) $request['textId'], 'done' => (bool) $request['done']]);
    }

    // ------------------------------------------------------------------ Vokabeln

    public function vocabulary(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $category = $request['category'] !== null && $request['category'] !== '' ? (string) $request['category'] : null;
        $catalog = (string) $request['catalog'];
        if ($catalog === '') {
            // Ohne Angabe: der einzige Vokabelkatalog des Kurses; bei SRC und UBI muss die App wählen.
            $catalogs = array_values(array_unique(array_column($this->radio->overview($this->user(), $course)['vocabulary'], 'catalog')));
            if (count($catalogs) !== 1) {
                throw ApiException::validation(['catalog' => [$catalogs === []
                    ? __('Dieser Kurs enthält keine Vokabeln.', 'bootspruefung-learning')
                    : __('Dieser Kurs hat mehrere Vokabelkataloge. Bitte wähle SRC oder UBI.', 'bootspruefung-learning')]]);
            }
            $catalog = (string) $catalogs[0];
        }
        $v = $this->radio->vocabulary($this->user(), $course, $catalog, $category);

        return $this->ok($v['cards'], 200, ['catalog' => $catalog, 'category' => $category, 'total' => count($v['cards']), 'learned' => (int) $v['learned'], 'streak_to_learn' => (int) $v['streak_to_learn']]);
    }

    /**
     * „Gewusst/nicht gewusst“ (RadioService::vocabularyAnswer). Doppelte Zählung verhindern die
     * angezeigte Serie (`streak`, wie im Web) und optional ein Idempotency-Key.
     */
    public function vocabularyAnswer(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $course = $this->radioCourse($request);
        $cardId = (string) $request['cardId'];
        $known = (bool) $request['known'];
        $streak = $request['streak'] === null ? null : (int) $request['streak'];
        [$status, $body] = Idempotency::run($user, 'radio-vocab:'.$course->id.':'.$cardId, $this->idempotencyKey($request, false), ['known' => $known, 'streak' => $streak], function () use ($user, $course, $cardId, $known, $streak): array {
            $r = $this->radio->vocabularyAnswer($user, $course, $cardId, $known, $streak);

            return [200, ['data' => ['card_id' => $cardId, 'known' => $known] + $r], null];
        });

        return new WP_REST_Response($body, $status);
    }

    // ------------------------------------------------------------------ Funkpraxis

    public function practiceList(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);

        return $this->ok(array_map([self::class, 'practiceCatalog'], $this->practice->overview($this->user(), $course)['catalogs']));
    }

    public function practiceShow(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $s = $this->practice->show($this->user(), $course, (string) $request['scenarioId']);

        return $this->ok([
            'id' => (string) $s['id'],
            'course_id' => (string) $course->id,
            'catalog' => (string) $s['catalog'],
            'number' => (int) $s['number'],
            'kind' => (string) $s['kind'],
            'situation_de' => (string) $s['situation_de'],
            'situation_en' => $s['situation_en'],
            'fields' => $s['fields'],
            'answers' => (object) ($s['answers'] ?: []),
            'done' => (bool) $s['done'],
            'previous_id' => $s['previous'],
            'next_id' => $s['next'],
        ]);
    }

    /** Eingaben je Feld gegen die Prüfpunkte auswerten (RadioPracticeService::evaluate) – mit Musterlösung je Feld. */
    public function practiceEvaluate(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $answers = $request['answers'];
        if (! is_array($answers)) {
            throw ApiException::validation(['answers' => [__('Bitte sende die Eingaben als Objekt (Feldname → Text).', 'bootspruefung-learning')]]);
        }
        $r = $this->practice->evaluate($this->user(), $course, (string) $request['scenarioId'], array_map('strval', array_filter($answers, 'is_scalar')));

        return $this->ok(['scenario_id' => (string) $request['scenarioId']] + $r);
    }

    public function practiceDone(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);
        $this->practice->setDone($this->user(), $course, (string) $request['scenarioId'], (bool) $request['done']);

        return $this->ok(['scenario_id' => (string) $request['scenarioId'], 'done' => (bool) $request['done']]);
    }

    public function samples(WP_REST_Request $request): WP_REST_Response
    {
        $course = $this->radioCourse($request);

        return $this->ok($this->practice->samples($this->user(), $course)['catalogs']);
    }

    // ------------------------------------------------------------------

    /**
     * Kurs mit Funktraining: ohne Funkinhalte 404 (wie ein unbekannter Bereich), ohne Zugang
     * 403 mit Grund und Shop-Angebot (requireFeature), danach entscheiden die Services
     * (Teilumfang → 403 CONTENT_LOCKED, unbekannte Übung → 404).
     */
    private function radioCourse(WP_REST_Request $request): object
    {
        $course = $this->course($request);
        if (! in_array('funk', $this->courses->capabilities($course), true)) {
            throw ApiException::notFound(__('Dieser Kurs enthält kein Funktraining.', 'bootspruefung-learning'));
        }
        $this->requireFeature($course, AccessService::RADIO);

        return $course;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function practiceCatalog(array $c): array
    {
        return [
            'catalog' => (string) $c['catalog'],
            'tasks' => array_map(static fn ($t) => [
                'id' => (string) $t['id'],
                'number' => (int) $t['number'],
                'kind' => (string) $t['kind'],
                'teaser' => $t['teaser'] !== null ? (string) $t['teaser'] : null,
                'done' => (bool) $t['done'],
                'evaluated' => (bool) $t['evaluated'],
                'locked' => (bool) $t['locked'],
            ], $c['tasks']),
        ];
    }

    /**
     * Funkgerät-Simulator: keine Bedien-API, nur Verfügbarkeit und Link in den Web-Lernbereich
     * (/bootsscheine/<kurs>/funk/geraet/, wie die Kachel im Web). available/premium aus
     * DeviceSimService (Testversion für alle angemeldeten Konten in Funkkursen, Vollversion mit
     * Gerätesimulations-Zugang).
     * @param array<string,list<array<string,string>>> $offers CourseMapper::offers
     * @return array<string,mixed>
     */
    public static function simulator(int $userId, object $course, DeviceSimService $device, array $offers = []): array
    {
        $available = $device->available($userId, $course);
        $premium = $available && $device->premium($userId, $course);

        return [
            'available' => $available,
            'premium' => $premium,
            'tier' => $available ? ($premium ? 'premium' : 'free') : null,
            'web_url' => $available ? Router::url(strtolower((string) $course->code).'/funk/geraet') : null,
            'open_in' => 'browser',
            'hint' => $available ? __('Der Funkgerät-Simulator läuft im Web-Lernbereich. Öffne ihn am Tablet oder im Browser und melde dich dort mit demselben Konto an.', 'bootspruefung-learning') : null,
            'webshop_url' => $available && ! $premium ? ($offers['funk_device'][0]['url'] ?? null) : null,
        ];
    }
}
