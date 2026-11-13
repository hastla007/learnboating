<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

defined('ABSPATH') || exit;

/**
 * Universal Links (iOS) und App Links (Android) für den Passwort-Reset-Link und (seit 1.4.0) den
 * Bestätigungslink der Kontolöschung:
 * - /.well-known/apple-app-site-association und /.well-known/assetlinks.json direkt aus WordPress
 *   (application/json, ohne Weiterleitung), nur wenn in „Bootsprüfung → Mobile App“ eingetragen –
 *   sonst schlichte 404 (ohne Weiterleitung/Theme-Seite);
 * - App-Link-Pfad /app/passwort/: ist die App installiert, öffnet das System die App; sonst landet
 *   der Browser hier und wird auf die normale WordPress-Seite zum Setzen des Passworts
 *   (wp-login.php?action=rp&key=…&login=…) weitergeleitet – der Link funktioniert also immer;
 * - nur bei POST /auth/password/forgot (App) und nur bei eingetragenen App-Kennungen zeigt der Link
 *   der Reset-Mail auf /app/passwort/ (Filter retrieve_password_message nur für diese Anfrage);
 * - Kontolöschung: nur bei POST /me/deletion-request (App) und eingetragenen App-Kennungen zeigt der
 *   Bestätigungslink auf /app/konto-loeschen/?request_id=…&confirm_key=… (Filter
 *   user_request_action_email_content nur während dieses Aufrufs). Ohne App leitet die Website auf die
 *   normale WordPress-Bestätigung (wp-login.php?action=confirmaction&…) weiter.
 * - Einladungs-/Beitrittslinks von Gruppenlizenzen (seit 1.7.0): /bootsscheine/gruppe/<token>/
 *   (GroupService::joinUrl) steht in der apple-app-site-association; die App löst den Token über
 *   POST /coupons/redeem ein. Ohne App bleibt es die normale Seite des Lernbereichs (hier keine
 *   Weiterleitung, der Pfad wird nicht angefasst).
 */
final class AppLinks
{
    public const RESET_PATH = 'app/passwort';
    public const DELETION_PATH = 'app/konto-loeschen';

    public static function register(): void
    {
        add_action('parse_request', [self::class, 'handleRequest'], 0);
        add_action('wp_head', [self::class, 'smartBanner'], 2);
    }

    /** Pfad ab WordPress-Adresse (ohne Unterverzeichnis), z. B. /.well-known/assetlinks.json */
    private static function requestPath(string $requestUri): string
    {
        $path = (string) wp_parse_url($requestUri, PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path, $home.'/')) {
            $path = substr($path, strlen($home));
        }

        return $path;
    }

    public static function configured(): bool
    {
        return MobileSettings::iosAppIds() !== [] || (MobileSettings::androidPackage() !== null && MobileSettings::androidFingerprints() !== []);
    }

    /** Pfad-Muster des App-Links ab Domain (für apple-app-site-association). */
    public static function resetPathPattern(): string
    {
        return rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/').'/'.self::RESET_PATH.'/*';
    }

    public static function resetUrl(): string
    {
        return home_url('/'.self::RESET_PATH.'/');
    }

    /** Pfad-Muster des Kontolöschungs-Links ab Domain (für apple-app-site-association). */
    public static function deletionPathPattern(): string
    {
        return rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/').'/'.self::DELETION_PATH.'/*';
    }

    public static function deletionUrl(): string
    {
        return home_url('/'.self::DELETION_PATH.'/');
    }

    /** Pfad-Muster der Einladungslinks von Gruppenlizenzen ab Domain (Lernbereich, z. B. /bootsscheine/gruppe/*). */
    public static function groupJoinPathPattern(): string
    {
        return rtrim((string) wp_parse_url(\Bootspruefung\Learning\Frontend\Router::url('gruppe'), PHP_URL_PATH), '/').'/*';
    }

    /**
     * Antwort für eine der beiden /.well-known-Dateien: 200 mit JSON, wenn eingetragen, sonst 404;
     * null bei anderen Adressen (WordPress macht weiter).
     * @return ?array{status:int,content_type:string,body:string}
     */
    public static function wellKnown(string $path): ?array
    {
        if (! in_array($path, ['/.well-known/apple-app-site-association', '/apple-app-site-association', '/.well-known/assetlinks.json'], true)) {
            return null;
        }

        return self::file($path) ?? ['status' => 404, 'content_type' => 'text/plain; charset=utf-8', 'body' => 'Not Found'];
    }

    /** @return ?array{status:int,content_type:string,body:string} JSON-Inhalt oder null (nicht eingetragen) */
    private static function file(string $path): ?array
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($path === '/.well-known/apple-app-site-association' || $path === '/apple-app-site-association') {
            $ids = MobileSettings::iosAppIds();
            if ($ids === []) {
                return null;
            }
            $body = [
                'applinks' => [
                    'details' => [[
                        'appIDs' => $ids,
                        'components' => [
                            ['/' => self::resetPathPattern(), 'comment' => 'Passwort zurücksetzen in der App'],
                            ['/' => self::deletionPathPattern(), 'comment' => 'Kontolöschung in der App bestätigen'],
                            ['/' => self::groupJoinPathPattern(), 'comment' => 'Einladung in eine Gruppenlizenz in der App annehmen'],
                        ],
                    ]],
                ],
            ];

            return ['status' => 200, 'content_type' => 'application/json', 'body' => (string) wp_json_encode($body, $flags)];
        }
        if ($path === '/.well-known/assetlinks.json') {
            $package = MobileSettings::androidPackage();
            $prints = MobileSettings::androidFingerprints();
            if ($package === null || $prints === []) {
                return null;
            }
            $body = [[
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => $prints],
            ]];

            return ['status' => 200, 'content_type' => 'application/json', 'body' => (string) wp_json_encode($body, $flags)];
        }

        return null;
    }

    /**
     * Browser statt App: /app/passwort/?key=…&login=… → normale WordPress-Seite zum Passwortsetzen.
     * Ohne Schlüssel → Seite „Passwort vergessen“. @param array<string,mixed> $query
     */
    public static function resetRedirect(array $query): string
    {
        $key = isset($query['key']) && is_string($query['key']) ? $query['key'] : '';
        $login = isset($query['login']) && is_string($query['login']) ? $query['login'] : '';
        if ($key === '' || $login === '' || preg_match('/^[A-Za-z0-9]{1,64}$/', $key) !== 1 || strlen($login) > 200) {
            return wp_lostpassword_url();
        }
        $url = network_site_url('wp-login.php?action=rp&key='.rawurlencode($key).'&login='.rawurlencode($login), 'login');
        if (isset($query['wp_lang']) && is_string($query['wp_lang']) && preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?$/', $query['wp_lang']) === 1) {
            $url .= '&wp_lang='.$query['wp_lang'];
        }

        return $url;
    }

    /**
     * Browser statt App: /app/konto-loeschen/?request_id=…&confirm_key=… → WordPress-Bestätigung
     * (wp-login.php?action=confirmaction&request_id=…&confirm_key=…, wie im Original-Mail-Link).
     * Ohne/mit ungültigen Werten → Startseite. @param array<string,mixed> $query
     */
    public static function deletionRedirect(array $query): string
    {
        $id = isset($query['request_id']) && is_string($query['request_id']) ? $query['request_id'] : '';
        $key = isset($query['confirm_key']) && is_string($query['confirm_key']) ? $query['confirm_key'] : '';
        if (! ctype_digit($id) || (int) $id <= 0 || preg_match('/^[A-Za-z0-9]{1,64}$/', $key) !== 1) {
            return home_url('/');
        }

        return add_query_arg(['action' => 'confirmaction', 'request_id' => (int) $id, 'confirm_key' => $key], wp_login_url());
    }

    /** parse_request (früh, vor Kanonisierung/Template): /.well-known und App-Link-Pfade bedienen. */
    public static function handleRequest(): void
    {
        $path = self::requestPath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $file = self::wellKnown($path);
        if ($file !== null) {
            status_header($file['status']);
            header('Content-Type: '.$file['content_type']);
            header('Cache-Control: '.($file['status'] === 200 ? 'public, max-age=3600' : 'no-cache, max-age=0'));
            header('X-Content-Type-Options: nosniff');
            echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON aus geprüften Einstellungen
            exit;
        }
        if (rtrim($path, '/') === '/'.self::RESET_PATH) {
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
            wp_safe_redirect(self::resetRedirect(wp_unslash($_GET)), 302, 'bootspruefung-learning'); // phpcs:ignore WordPress.Security.NonceVerification
            exit;
        }
        if (rtrim($path, '/') === '/'.self::DELETION_PATH) {
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
            wp_safe_redirect(self::deletionRedirect(wp_unslash($_GET)), 302, 'bootspruefung-learning'); // phpcs:ignore WordPress.Security.NonceVerification
            exit;
        }
    }

    /**
     * Link der Reset-Mail auf den App-Link-Pfad umschreiben (nur innerhalb von $callback, nur wenn
     * App-Kennungen eingetragen sind). Erkannt wird der WordPress-Link wp-login.php?…action=rp…
     * mit genau diesem Schlüssel; Sprache (wp_lang) bleibt erhalten. Andere Mails unverändert.
     */
    public static function withAppResetLink(callable $callback): mixed
    {
        if (! self::configured()) {
            return $callback();
        }
        $filter = static function ($message, $key = '', $login = '') {
            if (! is_string($message) || ! is_string($key) || $key === '') {
                return $message;
            }
            $base = preg_quote(network_site_url('wp-login.php', 'login'), '#');

            return (string) preg_replace_callback('#'.$base.'\?[^\s<>"\']+#', static function (array $m) use ($key, $login): string {
                parse_str(html_entity_decode((string) wp_parse_url($m[0], PHP_URL_QUERY)), $q);
                if (($q['action'] ?? '') !== 'rp' || ($q['key'] ?? '') !== $key) {
                    return $m[0];
                }
                $args = ['key' => $key, 'login' => (string) ($q['login'] ?? $login)];
                if (isset($q['wp_lang']) && is_string($q['wp_lang'])) {
                    $args['wp_lang'] = $q['wp_lang'];
                }

                return self::resetUrl().'?'.http_build_query($args, '', '&', PHP_QUERY_RFC3986);
            }, $message);
        };
        add_filter('retrieve_password_message', $filter, 50, 3);
        try {
            return $callback();
        } finally {
            remove_filter('retrieve_password_message', $filter, 50);
        }
    }

    /**
     * Bestätigungslink der Löschantrags-Mail auf den App-Link-Pfad umschreiben (nur innerhalb von
     * $callback, nur mit App-Kennungen, nur für Anträge „remove_personal_data“). WordPress ersetzt den
     * Platzhalter ###CONFIRM_URL### erst nach dem Filter – hier wird er vorher durch die App-Adresse
     * mit denselben Werten (request_id, confirm_key) ersetzt.
     */
    public static function withAppDeletionLink(callable $callback): mixed
    {
        if (! self::configured()) {
            return $callback();
        }
        $filter = static function ($content, $data = []) {
            if (! is_string($content) || ! is_array($data) || ! isset($data['request'], $data['confirm_url'])
                || ($data['request']->action_name ?? '') !== 'remove_personal_data') {
                return $content;
            }
            parse_str(html_entity_decode((string) wp_parse_url((string) $data['confirm_url'], PHP_URL_QUERY)), $q);
            if (($q['action'] ?? '') !== 'confirmaction' || empty($q['request_id']) || empty($q['confirm_key'])) {
                return $content;
            }
            $url = self::deletionUrl().'?'.http_build_query(['request_id' => (string) $q['request_id'], 'confirm_key' => (string) $q['confirm_key']], '', '&', PHP_QUERY_RFC3986);

            return str_replace('###CONFIRM_URL###', esc_url_raw($url), $content);
        };
        add_filter('user_request_action_email_content', $filter, 50, 2);
        try {
            return $callback();
        } finally {
            remove_filter('user_request_action_email_content', $filter, 50);
        }
    }

    /** Safari-App-Banner („In der App öffnen/Laden“), sobald die App im App Store steht (To-do T24). */
    public static function smartBanner(): void
    {
        $id = MobileSettings::enabled() ? MobileSettings::appStoreId() : null;
        if ($id !== null) {
            echo '<meta name="apple-itunes-app" content="app-id='.esc_attr($id).'">'."\n";
        }
    }
}
