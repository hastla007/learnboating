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
        // Zusätzliche vertrauenswürdige Proxys (IP oder CIDR), deren X-Forwarded-For gilt.
        'trusted_proxies' => '',
        // Sitzungsübergabe App → Web (POST /auth/web-session), z. B. Simulator in der Tablet-Webansicht.
        'web_session_enabled' => true,
        // Universal Links (iOS) / App Links (Android) für den Passwort-Reset-Link: leer = aus.
        'ios_app_ids' => '',
        'android_package' => '',
        'android_cert_fingerprints' => '',
    ];

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
            'android_cert_fingerprints' => implode("\n", array_slice(array_values(array_unique(array_filter(array_map([self::class, 'normalizeFingerprint'], preg_split('/[\s,]+/', (string) ($input['android_cert_fingerprints'] ?? '')) ?: [])))), 0, 20)),
        ];
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void
    {
        update_option(self::OPTION, self::sanitize($values), false);
    }
}
