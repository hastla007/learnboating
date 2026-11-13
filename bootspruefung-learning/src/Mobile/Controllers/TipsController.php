<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Tips\Tips;
use WP_REST_Request;
use WP_REST_Response;

/**
 * „Tipps & Tricks“ – dieselben veröffentlichten Tipps wie im Web (GET bootspruefung/v1/tips,
 * Tips::groups): Kategorien in derselben Reihenfolge, je Tipp Titel und HTML (wp_kses_post wie im
 * Web, relative Links absolut), dazu reiner Text und die enthaltenen Links für Clients ohne
 * HTML-Darstellung. Kein Kurszugang nötig (wie im Web: angemeldet genügt).
 */
final class TipsController extends BaseController
{
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $groups = [];
        $tips = 0;
        foreach (Tips::groups() as $group) {
            $term = $group['term'];
            $items = [];
            foreach ($group['tips'] as $tip) {
                $html = self::absoluteLinks((string) $tip['html']);
                $items[] = [
                    'id' => (string) $tip['id'],
                    'title' => html_entity_decode((string) $tip['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'html' => $html,
                    'text' => self::plainText($html),
                    'links' => self::links($html),
                ];
            }
            $tips += count($items);
            $groups[] = [
                'id' => $term instanceof \WP_Term ? (string) $term->term_id : null,
                'slug' => $term instanceof \WP_Term ? (string) $term->slug : sanitize_title((string) $group['name']),
                'name' => (string) $group['name'],
                'tips' => $items,
            ];
        }

        return $this->ok($groups, 200, ['groups' => count($groups), 'tips' => $tips]);
    }

    /** Relative Links/Bilder (z. B. /bootsscheine/…) auf die Website-Adresse beziehen – die App hat keine Basis-URL. */
    public static function absoluteLinks(string $html): string
    {
        return (string) preg_replace_callback('#\b(href|src)=(["\'])(/(?!/)[^"\']*)\2#i', static function (array $m): string {
            $origin = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME).'://'.(string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            $port = wp_parse_url(home_url('/'), PHP_URL_PORT);

            return $m[1].'='.$m[2].esc_url($origin.($port ? ':'.$port : '').$m[3]).$m[2];
        }, $html);
    }

    /** HTML → Text: Absätze/Überschriften durch Leerzeilen, Zeilenumbrüche, Listenpunkte „• “. */
    public static function plainText(string $html): string
    {
        // Zeilenumbrüche im Quelltext sind in HTML nur Leerraum (wpautop setzt sie zwischen Tags).
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $html);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', "\n• ", $text);
        $text = (string) preg_replace('#</(p|div|h[1-6]|blockquote|ul|ol|table|tr|figure|pre)>#i', "\n\n", $text);
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** @return list<array{url:string,text:string}> Links im Tipp (z. B. auf Kurse oder Seiten der Website) */
    public static function links(string $html): array
    {
        $out = [];
        if (preg_match_all('#<a\b[^>]*\bhref=(["\'])([^"\']+)\1[^>]*>(.*?)</a>#is', $html, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $m) {
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('#^(https?:|mailto:|tel:)#i', $url) !== 1) {
                    continue;
                }
                $out[] = ['url' => $url, 'text' => trim(html_entity_decode(wp_strip_all_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
            }
        }

        return $out;
    }
}
