<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

use Bootspruefung\Learning\Settings\Settings;

/**
 * Einstellungen der App-Schnittstelle (Option bp_learning_mobile, eigene Adminseite).
 * Ein Shop = ein Tenant: Slug konfigurierbar, Standard aus dem Markennamen.
 */
final class MobileSettings
{
    public const OPTION = 'bp_learning_mobile';

    private const DEFAULTS = [
        'enabled' => true,
        'tenant_slug' => '',
        'primary_color' => '',
        'support_email' => '',
        // Vertrauenswürdige Proxys (IP oder CIDR), deren X-Forwarded-For gilt. Leer = keiner (nur REMOTE_ADDR).
        'trusted_proxies' => '',
        // Sitzungsübergabe App → Web (POST /auth/web-session), z. B. Simulator in der Tablet-Webansicht.
        'web_session_enabled' => true,
        // Universal Links (iOS) / App Links (Android) für den Passwort-Reset-Link: leer = aus.
        'ios_app_ids' => '',
        'android_package' => '',
        'android_cert_fingerprints' => '',
        // Mindestversion der App je Plattform (z. B. 1.4.0); ältere Versionen bekommen 426 APP_UPDATE_REQUIRED. Leer = keine.
        'min_app_version_ios' => '',
        'min_app_version_android' => '',
        // Store-Einträge der App (To-do T24): Link beim Pflicht-Update und Safari-App-Banner. Leer = keine App im Store.
        'app_store_url' => '',
        'play_store_url' => '',
    ];

    /** Versionsnummer wie 1.4 oder 1.4.0 (höchstens vier Teile). */
    public const VERSION_PATTERN = '/^\d{1,4}(\.\d{1,4}){0,3}$/';

    /** iOS-App-ID: TEAMID (10 Zeichen) + Bundle-ID, z. B. ABCDE12345.at.example.boot */
    public const IOS_APP_ID_PATTERN = '/^[A-Z0-9]{10}\.[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/';
    /** Android-Paketname, z. B. at.example.boot */
    public const ANDROID_PACKAGE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/';

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function enabled(): bool
    {
        return (bool) self::all()['enabled'];
    }

    /** Tenant-Slug (^[a-z0-9][a-z0-9-]{1,62}$), Standard aus dem Markennamen. */
    public static function tenantSlug(): string
    {
        $slug = self::normalizeSlug((string) self::all()['tenant_slug']);
        if ($slug === '') {
            $slug = self::normalizeSlug(Settings::brand());
        }

        return $slug !== '' ? $slug : 'bootsschule';
    }

    public static function normalizeSlug(string $value): string
    {
        $slug = trim(substr(sanitize_title($value), 0, 63), '-');

        return preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) === 1 ? $slug : '';
    }

    public static function tenantName(): string
    {
        return Settings::brand();
    }

    public static function primaryColor(): ?string
    {
        $color = (string) self::all()['primary_color'];

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : null;
    }

    public static function supportEmail(): ?string
    {
        $email = (string) self::all()['support_email'];
        if ($email === '' && function_exists('WC')) {
            $email = (string) get_option('woocommerce_email_from_address', '');
        }
        $email = $email !== '' ? $email : (string) get_option('admin_email', '');

        return is_email($email) ? $email : null;
    }

    /** @return list<string> */
    public static function trustedProxies(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) self::all()['trusted_proxies']) ?: [])));
    }

    public static function webSessionEnabled(): bool
    {
        return (bool) self::all()['web_session_enabled'];
    }

    /** @return list<string> iOS-App-IDs (TEAMID.bundle.id) */
    public static function iosAppIds(): array
    {
        return self::lines((string) self::all()['ios_app_ids'], static fn (string $v) => preg_match(self::IOS_APP_ID_PATTERN, $v) === 1);
    }

    public static function androidPackage(): ?string
    {
        $package = trim((string) self::all()['android_package']);

        return preg_match(self::ANDROID_PACKAGE_PATTERN, $package) === 1 ? $package : null;
    }

    /** @return list<string> SHA-256-Fingerabdrücke der Signaturzertifikate (AA:BB:…, 32 Bytes) */
    public static function androidFingerprints(): array
    {
        return self::lines((string) self::all()['android_cert_fingerprints'], static fn (string $v) => self::normalizeFingerprint($v) === $v);
    }

    /** „aabb…“ oder „AA:BB:…“ (64 Hex-Zeichen) → „AA:BB:…“; sonst ''. */
    public static function normalizeFingerprint(string $value): string
    {
        $hex = strtoupper(str_replace([':', ' '], '', trim($value)));

        return preg_match('/^[0-9A-F]{64}$/', $hex) === 1 ? implode(':', str_split($hex, 2)) : '';
    }

    /** @param callable(string):bool $valid @return list<string> */
    private static function lines(string $value, callable $valid): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $value) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && $valid($entry) && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /** @param mixed $input @return array<string,mixed> */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $proxies = [];
        foreach (preg_split('/[\s,]+/', (string) ($input['trusted_proxies'] ?? '')) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && RateLimiter::validCidr($entry)) {
                $proxies[] = $entry;
            }
        }
        $color = trim((string) ($input['primary_color'] ?? ''));
        $email = sanitize_email((string) ($input['support_email'] ?? ''));
        $package = trim((string) ($input['android_package'] ?? ''));

        return [
            'enabled' => ! empty($input['enabled']),
            'tenant_slug' => self::normalizeSlug((string) ($input['tenant_slug'] ?? '')),
            'primary_color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : '',
            'support_email' => is_email($email) ? $email : '',
            'trusted_proxies' => implode("\n", array_slice($proxies, 0, 50)),
            // Fehlt das Feld (Aufruf aus Code), bleibt die Übergabe an (Standard); das Formular sendet 0/1.
            'web_session_enabled' => array_key_exists('web_session_enabled', $input) ? ! empty($input['web_session_enabled']) : true,
            'ios_app_ids' => implode("\n", array_slice(self::lines((string) ($input['ios_app_ids'] ?? ''), static fn (string $v) => preg_match(self::IOS_APP_ID_PATTERN, $v) === 1), 0, 20)),
            'android_package' => preg_match(self::ANDROID_PACKAGE_PATTERN, $package) === 1 ? $package : '',
            'min_app_version_ios' => self::version((string) ($input['min_app_version_ios'] ?? '')),
            'min_app_version_android' => self::version((string) ($input['min_app_version_android'] ?? '')),
            'app_store_url' => self::storeUrl((string) ($input['app_store_url'] ?? ''), 'ios'),
            'play_store_url' => self::storeUrl((string) ($input['play_store_url'] ?? ''), 'android'),
            'android_cert_fingerprints' => implode("\n", array_slice(array_values(array_unique(array_filter(array_map([self::class, 'normalizeFingerprint'], preg_split('/[\s,]+/', (string) ($input['android_cert_fingerprints'] ?? '')) ?: [])))), 0, 20)),
        ];
    }

    /** Mindestversion der App für ios|android oder null (keine Vorgabe). */
    public static function minAppVersion(string $platform): ?string
    {
        $v = (string) (self::all()['min_app_version_'.$platform] ?? '');

        return $v !== '' ? $v : null;
    }

    /** Geprüfter Store-Link für ios|android oder '' (ungültig bzw. App noch nicht im Store). */
    public static function storeUrl(string $value, string $platform): string
    {
        $value = trim($value);
        $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));
        $hosts = $platform === 'ios' ? ['apps.apple.com', 'itunes.apple.com'] : ['play.google.com'];
        if (! str_starts_with(strtolower($value), 'https://') || ! in_array($host, $hosts, true)) {
            return '';
        }
        // App Store: Adresse muss die numerische App-ID enthalten (…/id123456789); Google Play: ?id=<Paketname>.
        if ($platform === 'ios' && preg_match('#/id(\d{6,12})(?:[/?]|$)#', $value) !== 1) {
            return '';
        }
        if ($platform === 'android') {
            parse_str((string) wp_parse_url($value, PHP_URL_QUERY), $query);
            if (! isset($query['id']) || preg_match(self::ANDROID_PACKAGE_PATTERN, (string) $query['id']) !== 1) {
                return '';
            }
        }

        return esc_url_raw($value, ['https']);
    }

    /** @return array{ios: ?string, android: ?string} */
    public static function storeUrls(): array
    {
        $s = self::all();

        return [
            'ios' => (string) $s['app_store_url'] !== '' ? (string) $s['app_store_url'] : null,
            'android' => (string) $s['play_store_url'] !== '' ? (string) $s['play_store_url'] : null,
        ];
    }

    /** Numerische App-Store-ID aus dem Store-Link (für das Safari-App-Banner) oder null. */
    public static function appStoreId(): ?string
    {
        $url = (string) (self::storeUrls()['ios'] ?? '');

        return preg_match('#/id(\d{6,12})(?:[/?]|$)#', $url, $m) === 1 ? $m[1] : null;
    }

    private static function version(string $value): string
    {
        $value = trim($value);

        return preg_match(self::VERSION_PATTERN, $value) === 1 ? $value : '';
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void
    {
        update_option(self::OPTION, self::sanitize($values), false);
    }
}
