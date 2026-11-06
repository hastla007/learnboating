<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Settings;

/** Plugin-Einstellungen in einer Option (bp_learning_settings). */
final class Settings
{
    public const OPTION = 'bp_learning_settings';

    private const DEFAULTS = [
        // Kap. 8: Standard ist Erhalt aller Daten bei Deinstallation.
        'delete_data_on_uninstall' => false,
        // Kap. 6.4: Sprachausgabe im Funktrainer (Browserstimme statt Aufnahme).
        'radio_voice_output' => 'browser',
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function register(): void
    {
        register_setting('bp_learning', self::OPTION, [
            'type' => 'object',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::DEFAULTS,
            'show_in_rest' => false,
        ]);
    }

    /** @param mixed $input @return array<string,mixed> */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            'delete_data_on_uninstall' => ! empty($input['delete_data_on_uninstall']),
            'radio_voice_output' => in_array($input['radio_voice_output'] ?? '', ['browser', 'recording', 'off'], true) ? $input['radio_voice_output'] : 'browser',
        ];
    }
}
