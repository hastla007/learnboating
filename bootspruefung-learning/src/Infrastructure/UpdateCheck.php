<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Admin\Notices;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;

/**
 * „Auf neue Versionen prüfen“ (To-do T117). Das Plugin kommt nicht über wordpress.org (`Update URI: false`); damit
 * Betreiber trotzdem von neuen Versionen – vor allem Sicherheitsupdates – erfahren, fragt es auf Wunsch die
 * GitHub-Releases des Projekts ab.
 *
 * - Standard AUS (Bootsprüfung › Einstellungen › Betrieb). Ohne Zustimmung keine Anfrage nach außen.
 * - Höchstens einmal am Tag, im stündlichen Wartungslauf (WP-Cron), auch wenn die Anfrage scheitert.
 * - Es wird nichts über die Website gesendet: kein Website-Name in der Kennung (User-Agent), keine Version, keine
 *   Nutzerzahlen. Die Anfrage ist ein einfaches GET auf die öffentliche Release-Seite der API.
 * - Ergebnis im Systemstatus (Zeile „Neue Version“) und als Admin-Hinweis mit Link zur Release-Seite. Es wird nie
 *   etwas automatisch heruntergeladen oder installiert.
 * - Sicherheitsupdates (Release-Text enthält „Sicherheitsupdate“, docs/release-regel.md) erscheinen als Warnung,
 *   die sich nur vertagen lässt.
 *
 * Quelle: Konstante BOOTSPRUEFUNG_UPDATE_SOURCE bzw. Filter `bootspruefung_update_source` (nur https).
 */
final class UpdateCheck
{
    public const OPTION = 'bp_learning_update_check';

    public const DEFAULT_SOURCE = 'https://api.github.com/repos/hastla007/learnboating/releases/latest';

    /** Mindestabstand zwischen zwei Anfragen (Sekunden). */
    public const INTERVAL = 86400;

    public const NOTICE_KEY = 'bp_update_available';

    /** Kennzeichen eines Sicherheitsupdates im Titel bzw. Text des Releases. */
    public const SECURITY_MARKER = 'Sicherheitsupdate';

    private const SETTING = 'update_check_enabled';

    public static function register(): void
    {
        add_action(Cron::HOURLY, Cron::guard('update_check', [self::class, 'maybeRun']), 20);
        add_action('admin_notices', [self::class, 'notice'], 20);
        // Ausgeschaltet: gespeichertes Ergebnis verwerfen (kein Hinweis aus alten Daten).
        add_action('update_option_'.Settings::OPTION, [self::class, 'settingsChanged'], 10, 2);
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get(self::SETTING);
    }

    /** Abgefragte Adresse (https), sonst ''. */
    public static function source(): string
    {
        $source = defined('BOOTSPRUEFUNG_UPDATE_SOURCE') ? (string) constant('BOOTSPRUEFUNG_UPDATE_SOURCE') : self::DEFAULT_SOURCE;
        $source = trim((string) apply_filters('bootspruefung_update_source', $source));

        return preg_match('#^https://[^/\s]+/\S*$#i', $source) ? $source : '';
    }

    /**
     * Gespeichertes Ergebnis der letzten Prüfung.
     *
     * @return array{checked_at:int,latest:?string,url:?string,security:bool,error:?string}
     */
    public static function state(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'checked_at' => (int) ($stored['checked_at'] ?? 0),
            'latest' => isset($stored['latest']) && is_string($stored['latest']) && $stored['latest'] !== '' ? $stored['latest'] : null,
            'url' => isset($stored['url']) && is_string($stored['url']) && $stored['url'] !== '' ? $stored['url'] : null,
            'security' => ! empty($stored['security']),
            'error' => isset($stored['error']) && is_string($stored['error']) && $stored['error'] !== '' ? $stored['error'] : null,
        ];
    }

    /** Cron: prüfen, wenn eingeschaltet und die letzte Prüfung mindestens INTERVAL her ist. */
    public static function maybeRun(?int $now = null): bool
    {
        $now ??= time();
        if (! self::enabled()) {
            return false;
        }
        $last = self::state()['checked_at'];
        if ($last > 0 && $now - $last < self::INTERVAL && $last <= $now) {
            return false;
        }
        self::run($now);

        return true;
    }

    /**
     * Eine Abfrage ausführen und das Ergebnis speichern (auch bei Fehlern, damit höchstens einmal am Tag gefragt wird).
     *
     * @return array{checked_at:int,latest:?string,url:?string,security:bool,error:?string}
     */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $previous = self::state();
        $source = self::source();
        $state = ['checked_at' => $now, 'latest' => $previous['latest'], 'url' => $previous['url'], 'security' => $previous['security'], 'error' => null];
        if ($source === '') {
            $state['error'] = __('Keine gültige Adresse (nur https) für die Versionsprüfung.', 'bootspruefung-learning');
        } else {
            $response = wp_safe_remote_get($source, [
                'timeout' => 10,
                'redirection' => 2,
                'limit_response_size' => 1024 * 1024,
                // Bewusst ohne Website-Adresse (WordPress schickt sonst „WordPress/x; https://website“).
                'user-agent' => 'bootspruefung-learning-update-check',
                'headers' => ['Accept' => 'application/vnd.github+json'],
            ]);
            $parsed = is_wp_error($response) ? $response : self::parse((int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response), $source);
            if (is_wp_error($parsed)) {
                $state['error'] = mb_substr($parsed->get_error_message(), 0, 200);
            } else {
                $state = ['checked_at' => $now, 'error' => null] + $parsed;
            }
        }
        update_option(self::OPTION, $state, false);

        return self::state();
    }

    /**
     * Antwort der Releases-API auswerten.
     *
     * @return array{latest:string,url:?string,security:bool}|\WP_Error
     */
    public static function parse(int $code, string $body, string $source = '')
    {
        if ($code === 404) {
            return new \WP_Error('bp_update_none', __('Es gibt noch kein veröffentlichtes Release.', 'bootspruefung-learning'));
        }
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('bp_update_http', sprintf(__('Antwort HTTP %d.', 'bootspruefung-learning'), $code));
        }
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return new \WP_Error('bp_update_json', __('Antwort ist kein gültiges JSON.', 'bootspruefung-learning'));
        }
        if (! empty($data['draft']) || ! empty($data['prerelease'])) {
            return new \WP_Error('bp_update_pre', __('Das neueste Release ist ein Entwurf bzw. eine Vorabversion.', 'bootspruefung-learning'));
        }
        $version = self::normalizeVersion((string) ($data['tag_name'] ?? ''));
        if ($version === null) {
            return new \WP_Error('bp_update_tag', __('Das Release hat keine erkennbare Versionsnummer (Tag v<Version>).', 'bootspruefung-learning'));
        }
        $url = (string) ($data['html_url'] ?? '');
        $url = str_starts_with(strtolower($url), 'https://') ? esc_url_raw($url, ['https']) : '';
        if ($url === '') {
            $url = self::releasesPage($source);
        }
        $text = (string) ($data['name'] ?? '')."\n".(string) ($data['body'] ?? '');

        return [
            'latest' => $version,
            'url' => $url !== '' ? $url : null,
            'security' => stripos($text, self::SECURITY_MARKER) !== false,
        ];
    }

    /** „v0.15.0“ → „0.15.0“; null, wenn keine Versionsnummer. */
    public static function normalizeVersion(string $tag): ?string
    {
        $tag = ltrim(trim($tag), 'vV');

        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $tag) ? $tag : null;
    }

    /** Release-Übersicht zu einer GitHub-API-Adresse (…/repos/<besitzer>/<repo>/releases/latest), sonst ''. */
    public static function releasesPage(string $source): string
    {
        return preg_match('#^https://api\.github\.com/repos/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/releases#', $source, $m)
            ? 'https://github.com/'.$m[1].'/'.$m[2].'/releases'
            : '';
    }

    /**
     * Neuere Version laut letzter Prüfung (nur wenn eingeschaltet), sonst null.
     *
     * @return array{version:string,url:?string,security:bool}|null
     */
    public static function available(): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $state = self::state();
        if ($state['latest'] === null || ! version_compare($state['latest'], Plugin::VERSION, '>')) {
            return null;
        }

        return ['version' => $state['latest'], 'url' => $state['url'], 'security' => $state['security']];
    }

    /** Text für die Zeile „Neue Version“ im Systemstatus. */
    public static function statusLine(): string
    {
        if (! self::enabled()) {
            return __('Prüfung aus (einschalten unter Bootsprüfung › Einstellungen › Betrieb)', 'bootspruefung-learning');
        }
        $state = self::state();
        if ($state['checked_at'] === 0) {
            return __('Prüfung an, noch nicht gelaufen (beim nächsten stündlichen Wartungslauf)', 'bootspruefung-learning');
        }
        $when = wp_date('d.m.Y H:i', $state['checked_at']);
        $available = self::available();
        if ($available !== null) {
            return sprintf(
                /* translators: 1: Version, 2: Zeitpunkt, 3: Adresse */
                __('%1$s verfügbar (geprüft %2$s) – %3$s', 'bootspruefung-learning'),
                $available['version'].($available['security'] ? ' · '.__('Sicherheitsupdate', 'bootspruefung-learning') : ''),
                $when,
                (string) ($available['url'] ?? self::releasesPage(self::source()))
            ).' · '.__('nichts wird automatisch installiert', 'bootspruefung-learning');
        }
        if ($state['error'] !== null) {
            return sprintf(__('Prüfung %1$s fehlgeschlagen: %2$s', 'bootspruefung-learning'), $when, $state['error']);
        }

        return sprintf(__('keine – %1$s ist aktuell (geprüft %2$s)', 'bootspruefung-learning'), Plugin::VERSION, $when);
    }

    /** Admin-Hinweis bei neuerer Version (Dashboard, Plugins, Aktualisierungen, Bootsprüfung-Seiten). */
    public static function notice(): void
    {
        if (! current_user_can('update_plugins') && ! current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        if ($screen && ! in_array($id, ['dashboard', 'plugins', 'update-core'], true) && ! str_contains($id, 'bootspruefung')) {
            return;
        }
        $args = self::noticeArgs();
        if ($args !== null) {
            Notices::add($args);
        }
    }

    /** Argumente für Notices::add() oder null (für Tests getrennt). @return array<string,mixed>|null */
    public static function noticeArgs(): ?array
    {
        $available = self::available();
        if ($available === null) {
            return null;
        }
        $url = (string) ($available['url'] ?? self::releasesPage(self::source()));
        $text = sprintf(
            /* translators: 1: neue Version, 2: installierte Version */
            __('Version %1$s des Lern-Plugins ist erschienen (installiert: %2$s). Änderungen und Hinweise stehen auf der Release-Seite. Das Update spielst du wie gewohnt per ZIP ein (vorher sichern); es wird nichts automatisch installiert.', 'bootspruefung-learning'),
            $available['version'],
            Plugin::VERSION
        );

        return [
            'key' => self::NOTICE_KEY,
            'type' => $available['security'] ? 'warning' : 'info',
            'title' => $available['security'] ? __('Sicherheitsupdate – bitte zeitnah einspielen.', 'bootspruefung-learning') : __('Neue Version.', 'bootspruefung-learning'),
            'text' => $text,
            'buttons' => $url !== '' ? [[__('Release-Seite ansehen', 'bootspruefung-learning'), $url, true]] : [],
            // Normale Version: wegklickbar bis zur nächsten Version; Sicherheitsupdate: nur einen Tag vertagen.
            'dismiss' => $available['security'] ? 'snooze' : 'forever',
            'snooze_days' => 1,
            'version' => str_replace('.', '-', $available['version']),
        ];
    }

    /** Einstellung geändert: beim Ausschalten das gespeicherte Ergebnis löschen. */
    public static function settingsChanged(mixed $old, mixed $new): void
    {
        if (is_array($new) && empty($new[self::SETTING])) {
            delete_option(self::OPTION);
        }
    }

    /**
     * Abschnitt „Betrieb“ der Einstellungsseite (im Formular von Settings::OPTION).
     *
     * @param array<string,mixed> $settings
     */
    public static function renderSettingsSection(array $settings): void
    {
        $source = self::source();
        echo '<h2 id="bp-set-operations">'.esc_html__('Betrieb', 'bootspruefung-learning').'</h2><table class="form-table"><tbody>';
        printf(
            '<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s[%3$s]" value="0"><label><input type="checkbox" name="%2$s[%3$s]" value="1" %4$s> %5$s</label><p class="description">%6$s</p></td></tr>',
            esc_html__('Neue Versionen', 'bootspruefung-learning'),
            esc_attr(Settings::OPTION),
            esc_attr(self::SETTING),
            checked(! empty($settings[self::SETTING]), true, false),
            esc_html__('Auf neue Versionen prüfen', 'bootspruefung-learning'),
            esc_html(sprintf(
                /* translators: %s: Host */
                __('Höchstens einmal am Tag fragt die Website %s nach dem neuesten Release des Lern-Plugins. Dabei werden keine Daten über die Website gesendet. Gibt es eine neuere Version, erscheinen ein Hinweis im Admin und eine Zeile im Systemstatus mit Link zur Release-Seite. Installiert wird nie automatisch. Standard: aus.', 'bootspruefung-learning'),
                $source !== '' ? (string) wp_parse_url($source, PHP_URL_HOST) : '–'
            ))
        );
        echo '</tbody></table>';
    }
}
