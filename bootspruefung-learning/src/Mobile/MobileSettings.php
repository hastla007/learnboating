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
    ];

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

        return [
            'enabled' => ! empty($input['enabled']),
            'tenant_slug' => self::normalizeSlug((string) ($input['tenant_slug'] ?? '')),
            'primary_color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : '',
            'support_email' => is_email($email) ? $email : '',
            'trusted_proxies' => implode("\n", array_slice($proxies, 0, 50)),
        ];
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void
    {
        update_option(self::OPTION, self::sanitize($values), false);
    }
}
