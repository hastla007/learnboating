<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\AppSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Synchronisierte App-Einstellungen (GET/PUT /me/settings) – dünner Adapter auf AppSettings.
 * Versionsprüfung optional über If-Match ("3") oder expected_version; Abweichung → 409 VERSION_CONFLICT
 * mit aktuellem Stand in details.current. Antworten tragen ETag = Version.
 */
final class SettingsController extends BaseController
{
    public function show(): WP_REST_Response
    {
        return $this->respond(AppSettings::get($this->user()));
    }

    public function replace(WP_REST_Request $request): WP_REST_Response
    {
        $body = (string) $request->get_body();
        // Roh lesen (Objekte bleiben Objekte, {} ≠ []); grobe Obergrenze vor dem Parsen.
        $decoded = strlen($body) <= 8 * AppSettings::MAX_BYTES ? json_decode($body, false, 64) : null;
        if (! $decoded instanceof \stdClass || ! property_exists($decoded, 'settings')) {
            throw ApiException::validation(['settings' => [strlen($body) > 8 * AppSettings::MAX_BYTES
                ? sprintf(__('Die Einstellungen dürfen höchstens %d Bytes groß sein.', 'bootspruefung-learning'), AppSettings::MAX_BYTES)
                : __('Bitte sende die Einstellungen als JSON-Objekt im Feld settings.', 'bootspruefung-learning')]]);
        }
        $errors = AppSettings::validate($decoded->settings);
        $expected = $this->expectedVersion($request, $decoded);
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $this->respond(AppSettings::put($this->user(), $decoded->settings, $expected));
    }

    /** expected_version (Body) oder If-Match: "3" / W/"3" / 3; '*' bzw. leer = ohne Prüfung. */
    private function expectedVersion(WP_REST_Request $request, \stdClass $body): ?int
    {
        if (property_exists($body, 'expected_version') && $body->expected_version !== null) {
            if (! is_int($body->expected_version) || $body->expected_version < 0) {
                throw ApiException::validation(['expected_version' => [__('expected_version muss eine ganze Zahl ≥ 0 sein.', 'bootspruefung-learning')]]);
            }

            return $body->expected_version;
        }
        $header = trim((string) $request->get_header('if_match'));
        if ($header === '' || $header === '*') {
            return null;
        }
        if (preg_match('/^(?:W\/)?"?(\d{1,10})"?$/', $header, $m) !== 1) {
            throw ApiException::validation(['If-Match' => [__('If-Match muss die Version enthalten, z. B. "3".', 'bootspruefung-learning')]]);
        }

        return (int) $m[1];
    }

    /** @param array{settings:object,version:int,updated_at:?string} $state */
    private function respond(array $state): WP_REST_Response
    {
        $response = $this->ok($state);
        $response->header('ETag', '"'.$state['version'].'"');

        return $response;
    }
}
