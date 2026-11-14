<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Frontend;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Plugin;

/**
 * Lernbereich unter /bootsscheine/ (Kap. 9 „Lernende“). Der Server liefert
 * eine Hülle im aktiven Theme; Inhalte lädt die App über die REST-API.
 *
 *   /bootsscheine/                         Mein Lernen (Kursübersicht)
 *   /bootsscheine/<kurs>/                  Kursseite mit Modulen und Prüfung
 *   /bootsscheine/<kurs>/lernen/           Lernmodus (?modul=, ?modus=)
 *   /bootsscheine/<kurs>/pruefung/<id>/    Prüfung bzw. Ergebnis
 */
final class Router
{
    public const BASE = 'bootsscheine';
    private const REWRITE_VERSION = '2';
    /** Stand der geschriebenen Permalinks; fehlt er (z. B. nach Aktivierung), schreibt init sie neu. */
    public const REWRITE_OPTION = 'bp_learning_rewrite_version';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public static function base(): string
    {
        /** Filter: Pfad des Lernbereichs (ohne Schrägstriche). */
        return trim((string) apply_filters('bootspruefung_base_path', self::BASE), '/');
    }

    public static function url(string $path = ''): string
    {
        return home_url('/'.self::base().'/'.($path === '' ? '' : trim($path, '/').'/'));
    }

    /**
     * Hinweis für Gäste auf einer Einladungsseite „gruppe/<token>“ (T51/UX4-09), sonst null.
     * Zeigt Gruppe und Kurse (wie nach der Anmeldung) bzw. eine deutsche Meldung bei ungültigem Link.
     */
    public static function guestInvite(string $path): ?string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
        if (($parts[0] ?? '') !== 'gruppe' || ! isset($parts[1])) {
            return null;
        }
        global $wpdb;
        $info = (new \Bootspruefung\Learning\Commerce\GroupService($wpdb))->describeToken((string) $parts[1]);
        if ($info === null) {
            return '<p class="bp-inline-error">'.esc_html__('Dieser Einladungslink ist ungültig oder abgelaufen.', 'bootspruefung-learning').'</p>'
                .'<p>'.esc_html__('Bitte frage bei der Person nach, die dich eingeladen hat. Mit einem Konto kannst du trotzdem lernen.', 'bootspruefung-learning').'</p>';
        }
        $html = '<p><strong>'.esc_html(sprintf(
            /* translators: %s: Name der Gruppe */
            __('Du bist in die Gruppe „%s“ eingeladen.', 'bootspruefung-learning'),
            (string) $info['name']
        )).'</strong></p>';
        if ($info['scopes'] !== []) {
            $html .= '<ul class="bp-scopes">';
            foreach ($info['scopes'] as $scope) {
                $html .= '<li>'.esc_html($scope['course'].' · '.$scope['feature']).'</li>';
            }
            $html .= '</ul>';
        }
        if ($info['status'] !== 'active' || (int) $info['free_seats'] < 1) {
            $html .= '<p class="bp-inline-error">'.esc_html($info['status'] !== 'active'
                ? __('Diese Gruppe ist derzeit nicht aktiv.', 'bootspruefung-learning')
                : __('Alle Plätze dieser Gruppe sind belegt.', 'bootspruefung-learning')).'</p>';
        }

        return $html.'<p>'.esc_html__('Melde dich an oder lege ein Konto an. Danach kommst du direkt hierher zurück und kannst der Gruppe beitreten.', 'bootspruefung-learning').'</p>';
    }

    /** Umschreibregeln des Lernbereichs. @return array<string,string> Muster => Ziel */
    public static function rules(): array
    {
        $base = preg_quote(self::base(), '#');

        return [
            '^'.$base.'/?$' => 'index.php?bp_app=1',
            '^'.$base.'/(.+?)/?$' => 'index.php?bp_app=1&bp_path=$matches[1]',
        ];
    }

    public static function addRules(): void
    {
        foreach (self::rules() as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    /** Regeln für diese Anfrage wieder abmelden (Deaktivierung, vor dem Neuschreiben der Permalinks). */
    public static function removeRules(): void
    {
        global $wp_rewrite;
        if (! $wp_rewrite instanceof \WP_Rewrite) {
            return;
        }
        foreach (array_keys(self::rules()) as $regex) {
            unset($wp_rewrite->extra_rules_top[$regex]);
        }
    }

    public function register(): void
    {
        add_action('init', [$this, 'init'], 99); // nach WooCommerce-Endpunkten (Mein Konto › Mein Lernen)
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'bp_app';
            $vars[] = 'bp_path';

            return $vars;
        });
        add_filter('pre_handle_404', static fn ($handled) => get_query_var('bp_app') ? true : $handled);
        add_filter('template_include', [$this, 'template'], 99);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_filter('document_title_parts', [$this, 'title']);
        add_filter('wp_robots', static fn (array $robots) => get_query_var('bp_app') ? ['noindex' => true, 'nofollow' => true] + $robots : $robots);
        add_filter('body_class', static fn (array $classes) => get_query_var('bp_app') ? array_merge($classes, ['bp-learning-app']) : $classes);
        add_shortcode('bootspruefung', [$this, 'shortcode']);
        // Nonce-Erneuerung für die App (abgelaufene Sitzung/Nonce ohne Neuladen der Seite).
        (new \Bootspruefung\Learning\Rest\NonceRefresh())->register();
        // Lernbereich, Einladung, Abmeldeseite, Widerruf und geschützte Inhalte nicht zwischenspeichern (T78).
        NoCache::register();
    }

    public function init(): void
    {
        self::addRules();
        $version = self::REWRITE_VERSION.':'.self::base().':'.(class_exists('WooCommerce') ? 'wc' : '-');
        if (get_option(self::REWRITE_OPTION) !== $version) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_OPTION, $version, true);
        }
    }

    public function template(string $template): string
    {
        if (! get_query_var('bp_app')) {
            return $template;
        }
        status_header(200);
        NoCache::mark('learning-area'); // je Person verschieden (Lernstand, Einladung, Abmeldelink)

        return dirname($this->plugin->file()).'/templates/app.php';
    }

    public function assets(): void
    {
        if (! get_query_var('bp_app')) {
            return;
        }
        $file = $this->plugin->file();
        wp_enqueue_style('bp-learning-app', plugins_url('assets/build/app.css', $file), [], Plugin::assetVersion($file, 'assets/build/app.css'));
        // Bestätigungsseite „Erinnerungen abbestellen“ wird serverseitig gerendert (auch ohne Anmeldung).
        if (! is_user_logged_in() || \Bootspruefung\Learning\Commerce\ReminderOptOut::isRequest()) {
            return;
        }
        wp_enqueue_script('bp-learning-app', plugins_url('assets/build/app.js', $file), [], Plugin::assetVersion($file, 'assets/build/app.js'), true);
        wp_localize_script('bp-learning-app', 'bpLearning', [
            'root' => esc_url_raw(rest_url('bootspruefung/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'loginUrl' => wp_login_url(),
            'base' => wp_make_link_relative(self::url()),
            'user' => wp_get_current_user()->display_name,
            'logoutUrl' => wp_logout_url(self::url()),
            'voice' => \Bootspruefung\Learning\Settings\Settings::get('radio_voice_output'),
            'deviceSim' => plugins_url('assets/device-sim/', $file),
            'maps' => plugins_url('assets/maps/', $file),
            // Für nachgeladene Simulator-Dateien (?ver=…): mit Dateistand, damit Browser Änderungen laden (T48).
            'version' => Plugin::assetVersion($file, 'assets/device-sim/device-sim.css'),
            'groups' => is_user_logged_in() ? count((new \Bootspruefung\Learning\Commerce\GroupService($GLOBALS['wpdb']))->forOwner(get_current_user_id())) : 0,
            'accountUrl' => \Bootspruefung\Learning\Commerce\WooCommerce\Integration::accountUrl(),
            // Kursfotos für Kacheln/Kopfbereich: ['sbf-see' => ['card' => url, 'hero' => url]], z. B. vom Theme.
            'courseImages' => (array) apply_filters('bootspruefung_course_images', []),
            // Shop-Kategorie der Gruppenpakete (leerer Zustand unter „Meine Gruppen“).
            'groupsUrl' => (static function (): ?string {
                $term = function_exists('get_term_by') ? get_term_by('slug', \Bootspruefung\Learning\Commerce\ShopCatalog::CATEGORY_SLUGS['groups'], 'product_cat') : false;
                $link = $term instanceof \WP_Term ? get_term_link($term) : null;

                return is_string($link) ? $link : null;
            })(),
            // Sprache der Seite für Datumsangaben im Lernbereich (T128), z. B. „de-DE“ oder „de-AT“.
            'locale' => str_replace('_', '-', determine_locale()),
            'i18n' => Strings::all(),
        ]);
    }

    /** @param array<string,string> $parts */
    public function title(array $parts): array
    {
        if (! get_query_var('bp_app')) {
            return $parts;
        }
        $slug = explode('/', (string) get_query_var('bp_path'))[0];
        $course = $slug !== '' ? (new ContentRepository())->courseBySlug($slug) : null;
        $parts['title'] = $course ? $course->name.' – '.\Bootspruefung\Learning\Settings\Settings::brand() : \Bootspruefung\Learning\Settings\Settings::brand();

        return $parts;
    }

    /** [bootspruefung] – Einstieg in den Lernbereich aus beliebigen Seiten. */
    public function shortcode(): string
    {
        return sprintf('<p class="bp-entry"><a class="wp-element-button" href="%s">%s</a></p>', esc_url(self::url()), esc_html__('Zum Lernbereich', 'bootspruefung-learning'));
    }
}
