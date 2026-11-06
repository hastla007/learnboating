<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Frontend;

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
    private const REWRITE_VERSION = '1';

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

    public static function addRules(): void
    {
        $base = preg_quote(self::base(), '#');
        add_rewrite_rule('^'.$base.'/?$', 'index.php?bp_app=1', 'top');
        add_rewrite_rule('^'.$base.'/(.+?)/?$', 'index.php?bp_app=1&bp_path=$matches[1]', 'top');
    }

    public function register(): void
    {
        add_action('init', [$this, 'init']);
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
    }

    public function init(): void
    {
        self::addRules();
        if (get_option('bp_learning_rewrite_version') !== self::REWRITE_VERSION.':'.self::base()) {
            flush_rewrite_rules(false);
            update_option('bp_learning_rewrite_version', self::REWRITE_VERSION.':'.self::base(), true);
        }
    }

    public function template(string $template): string
    {
        if (! get_query_var('bp_app')) {
            return $template;
        }
        status_header(200);
        nocache_headers();

        return dirname($this->plugin->file()).'/templates/app.php';
    }

    public function assets(): void
    {
        if (! get_query_var('bp_app')) {
            return;
        }
        $file = $this->plugin->file();
        wp_enqueue_style('bp-learning-app', plugins_url('assets/build/app.css', $file), [], Plugin::VERSION);
        if (! is_user_logged_in()) {
            return;
        }
        wp_enqueue_script('bp-learning-app', plugins_url('assets/build/app.js', $file), [], Plugin::VERSION, true);
        wp_localize_script('bp-learning-app', 'bpLearning', [
            'root' => esc_url_raw(rest_url('bootspruefung/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'base' => wp_make_link_relative(self::url()),
            'user' => wp_get_current_user()->display_name,
            'logoutUrl' => wp_logout_url(self::url()),
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
        $parts['title'] = $course ? $course->name.' – '.__('Bootsscheine', 'bootspruefung-learning') : __('Bootsscheine', 'bootspruefung-learning');

        return $parts;
    }

    /** [bootspruefung] – Einstieg in den Lernbereich aus beliebigen Seiten. */
    public function shortcode(): string
    {
        return sprintf('<p class="bp-entry"><a class="wp-element-button" href="%s">%s</a></p>', esc_url(self::url()), esc_html__('Zum Lernbereich', 'bootspruefung-learning'));
    }
}
