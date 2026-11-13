<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Extensions;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Schnittstellen für Erweiterungs-Plugins (Gutschein, Tickets/Praxis-Termine); Übersicht aller Hooks, Filter und
 * Routen in docs/schnittstellen-erweiterungen.md. Das Lern-Plugin funktioniert ohne diese Plugins unverändert:
 * Ohne Gegenstelle bleiben die Abschnitte leer und unsichtbar.
 */
final class Extensions
{
    /** Höchstzahl der Praxis-Angebote je Kurs (Kurs-Hub und App). */
    public const MAX_OFFERS = 20;

    public static function register(): void
    {
        Vouchers::register();
        Tickets::register();
        add_filter('bootspruefung_user_owns_course', [self::class, 'ownsCourse'], 10, 3);
    }

    /**
     * Filter bootspruefung_user_owns_course: true, wenn der Nutzer vollen Zugang zum Fragenkatalog des Kurses hat
     * (AccessService – gekaufter, manueller oder Gruppen-Zugang; auch eine kostenlose Vollfreigabe zählt).
     *
     * @param mixed $owns
     */
    public static function ownsCourse($owns, $userId = 0, $courseCode = ''): bool
    {
        if ($owns === true) {
            return true;
        }
        $code = strtoupper(trim((string) $courseCode));
        if ((int) $userId <= 0 || preg_match('/^[A-Z0-9-]{1,40}$/', $code) !== 1) {
            return (bool) $owns;
        }

        return (new AccessService())->full((int) $userId, $code, AccessService::QUESTION_BANK) || (bool) $owns;
    }

    /**
     * Region für Praxis-Angebote. Ein serverseitig gespeichertes Prüfungsprofil (Bundesland) gibt es nicht – die
     * Bundesland-Auswahl der Prüfungsausschuss-Suche bleibt im Browser. Daher null, außer ein Plugin liefert sie.
     */
    public static function region(int $userId, string $courseCode): ?string
    {
        /**
         * Region (ISO-3166-2, z. B. DE-BY) für Praxis-Angebote eines Nutzers; Standard null.
         *
         * @param string|null $region
         * @param int         $userId
         * @param string      $courseCode
         */
        $region = apply_filters('bootspruefung_practice_region', null, $userId, $courseCode);

        return is_string($region) && preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $region) === 1 ? $region : null;
    }

    /**
     * „Praxis in deiner Nähe“: Angebote des Ticket-Plugins für einen Kurs (Filter bootspruefung_practice_offers).
     * Ohne Plugin leer.
     *
     * @return list<array{title:string,url:string,date:?string,location:?string,price:?string}>
     */
    public static function practiceOffers(string $courseCode, int $userId): array
    {
        $code = strtoupper($courseCode);
        /**
         * Praxis-Angebote (Termine) für einen Kurs.
         *
         * @param array       $offers     Liste: title, url, date, location, price
         * @param string      $courseCode
         * @param string|null $region     ISO-3166-2 oder null
         */
        $raw = apply_filters('bootspruefung_practice_offers', [], $code, self::region($userId, $code));
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $title = mb_substr(sanitize_text_field((string) ($offer['title'] ?? '')), 0, 200);
            $url = esc_url_raw((string) ($offer['url'] ?? ''), ['http', 'https']);
            if ($title === '' || $url === '') {
                continue;
            }
            $text = static fn (string $key, int $max): ?string => is_scalar($offer[$key] ?? null) && ($v = mb_substr(sanitize_text_field((string) $offer[$key]), 0, $max)) !== '' ? $v : null;
            $out[] = ['title' => $title, 'url' => $url, 'date' => $text('date', 40), 'location' => $text('location', 200), 'price' => $text('price', 60)];
            if (count($out) >= self::MAX_OFFERS) {
                break;
            }
        }

        return $out;
    }
}
