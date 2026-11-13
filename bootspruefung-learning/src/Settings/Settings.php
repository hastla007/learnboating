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
        // Solange kein Shop/keine Zahlungsart angebunden ist: alle angemeldeten Nutzer
        // dürfen lernen und prüfen. 'entitled_only' = nur Free-Regeln und Freischaltungen.
        'default_access' => 'logged_in',
        // Prüfungsanmeldung (Anleitung + Prüfungsausschüsse): vorbereitet, aber noch nicht freigeschaltet.
        'exam_guide_enabled' => false,
        // Markenname – Lernbereich, Seitentitel, Produkttexte, App (Tenant). Leer = Name der Website
        // (Einstellungen › Allgemein); erst ohne Website-Namen der Platzhalter „XXXX“ (siehe brand()).
        'brand_name' => '',
        // Preisangabe (PAngV): Hinweis an allen Preisen, Kasse und E-Mails; z. B. „inkl. MwSt.“
        // oder bei Kleinunternehmern „gem. § 19 UStG ohne MwSt.“ (Festlegung 07.11.2026).
        'price_note' => 'inkl. MwSt.',
        // Erinnerung per E-Mail vor Ablauf eines Zugangs zum Fragenkatalog (Festlegung 10.11.2026).
        'expiry_reminder_enabled' => true,
        'expiry_reminder_days' => 14,
        // Bestätigte Datenschutz-Anträge sofort ausführen: Export per Download-Link, Löschung inkl. Kundenkonto
        // (Bestellungen je nach WooCommerce-Einstellung anonymisiert). Aus = WordPress-Standard (Werkzeuge).
        'privacy_requests_auto' => true,
        // Untertitel der Lernvideos (T62): 'vtt' = WebVTT-Datei neben dem Video (gleicher Name, Endung .vtt).
        'video_captions' => 'off',
        // T117: höchstens täglich die GitHub-Releases abfragen (Infrastructure\UpdateCheck). Ohne Zustimmung aus.
        'update_check_enabled' => false,
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    /** Früherer Standardwert; gespeichert gilt er als „nicht festgelegt“. */
    public const BRAND_PLACEHOLDER = 'XXXX';

    /**
     * Markenname: eigener Wert aus den Einstellungen, sonst der Name der Website, erst ohne beides „XXXX“.
     * So erscheint öffentlich nie der Platzhalter, sobald die Website einen Namen hat (UX-02, T10).
     */
    public static function brand(): string
    {
        $own = trim((string) self::get('brand_name'));
        if ($own !== '' && $own !== self::BRAND_PLACEHOLDER) {
            return $own;
        }
        $site = trim(wp_strip_all_tags(html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8')));

        return $site !== '' ? mb_substr($site, 0, 60) : self::BRAND_PLACEHOLDER;
    }

    /** Ist ein eigener Markenname eingetragen (nicht leer, nicht der alte Platzhalter)? */
    public static function brandIsSet(): bool
    {
        $own = trim((string) self::get('brand_name'));

        return $own !== '' && $own !== self::BRAND_PLACEHOLDER;
    }

    public static function priceNote(): string
    {
        return trim((string) self::get('price_note'));
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
            'default_access' => in_array($input['default_access'] ?? '', ['logged_in', 'entitled_only'], true) ? $input['default_access'] : 'logged_in',
            'radio_voice_output' => in_array($input['radio_voice_output'] ?? '', ['browser', 'recording', 'off'], true) ? $input['radio_voice_output'] : 'browser',
            'exam_guide_enabled' => ! empty($input['exam_guide_enabled']),
            'price_note' => mb_substr(trim(sanitize_text_field((string) ($input['price_note'] ?? self::DEFAULTS['price_note']))), 0, 80),
            // Formular sendet „0“ vor der Checkbox; fehlt der Schlüssel ganz, gilt der Standard (an).
            'expiry_reminder_enabled' => array_key_exists('expiry_reminder_enabled', $input) ? ! empty($input['expiry_reminder_enabled']) : self::DEFAULTS['expiry_reminder_enabled'],
            'expiry_reminder_days' => isset($input['expiry_reminder_days']) && (int) $input['expiry_reminder_days'] > 0 ? min(90, (int) $input['expiry_reminder_days']) : self::DEFAULTS['expiry_reminder_days'],
            'privacy_requests_auto' => array_key_exists('privacy_requests_auto', $input) ? ! empty($input['privacy_requests_auto']) : self::DEFAULTS['privacy_requests_auto'],
            'video_captions' => in_array($input['video_captions'] ?? '', ['off', 'vtt'], true) ? $input['video_captions'] : 'off',
            'update_check_enabled' => ! empty($input['update_check_enabled']),
            'brand_name' => ($brand = trim(sanitize_text_field((string) ($input['brand_name'] ?? '')))) !== '' && $brand !== self::BRAND_PLACEHOLDER ? mb_substr($brand, 0, 60) : '',
        ];
    }
}
