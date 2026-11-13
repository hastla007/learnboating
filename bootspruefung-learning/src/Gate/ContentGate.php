<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Gate;

use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Normale WordPress-Inhalte an einen Lernzugang binden:
 * - Metabox „Lernzugang erforderlich“ an Seiten/Beiträgen (ganzer Inhalt);
 * - Block „Nur mit Lernzugang“ und Shortcode [bootspruefung_zugang] für Abschnitte.
 * Ohne Zugang erscheint ein Hinweis mit Anmelde- bzw. Produktlink; der
 * geschützte Inhalt wird nicht ausgegeben (auch nicht in REST, Feeds, Auszügen).
 */
final class ContentGate
{
    public const META = '_bp_required_access';
    public const ANY = 'any';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'metaBox']);
        add_action('save_post', [$this, 'save'], 10, 2);
        add_filter('the_content', [$this, 'filterContent'], 1);
        add_filter('get_the_excerpt', [$this, 'filterExcerpt'], 1, 2);
        add_shortcode('bootspruefung_zugang', [$this, 'shortcode']);
        add_action('init', [$this, 'registerBlock']);
        add_filter('post_class', [$this, 'postClass'], 10, 3);
    }

    /** @return array{course:string,feature:string}|null */
    public static function requirement(int $postId): ?array
    {
        $req = get_post_meta($postId, self::META, true);

        return is_array($req) && ! empty($req['course']) ? ['course' => (string) $req['course'], 'feature' => (string) ($req['feature'] ?? self::ANY)] : null;
    }

    public static function allows(int $userId, string $course, string $feature): bool
    {
        $access = new AccessService();
        if ($feature === self::ANY) {
            foreach (AccessService::FEATURES as $f) {
                if ($access->full($userId, $course, $f)) {
                    return true;
                }
            }

            return false;
        }

        // Teilumfänge gelten nur im Lernbereich; geschützte Inhalte verlangen vollen Zugang.
        return $access->full($userId, $course, $feature);
    }

    public static function canView(int $postId, ?int $userId = null): bool
    {
        $req = self::requirement($postId);
        if ($req === null) {
            return true;
        }
        $userId ??= get_current_user_id();
        if ($userId > 0 && user_can($userId, 'edit_post', $postId)) {
            return true;
        }

        return self::allows($userId, $req['course'], $req['feature']);
    }

    public function filterContent($content)
    {
        $post = get_post();
        if (! $post instanceof \WP_Post) {
            return $content;
        }
        if (self::canView((int) $post->ID)) {
            return $content;
        }
        $req = self::requirement((int) $post->ID);

        return self::notice($req['course'], $req['feature']);
    }

    public function filterExcerpt($excerpt, $post = null)
    {
        $post = get_post($post);
        if ($post instanceof \WP_Post && ! self::canView((int) $post->ID) && trim((string) $post->post_excerpt) === '') {
            // Kein automatischer Auszug aus geschütztem Text; ein manueller Auszug ist der Teaser.
            return __('Dieser Inhalt gehört zum Lernzugang.', 'bootspruefung-learning');
        }

        return $excerpt;
    }

    /** @param list<string> $classes */
    public function postClass(array $classes, $class, $postId): array
    {
        if (self::requirement((int) $postId) !== null) {
            $classes[] = self::canView((int) $postId) ? 'bp-requires-access' : 'bp-requires-access bp-access-locked';
        }

        return $classes;
    }

    /** @param array<string,string>|string $atts */
    public function shortcode($atts, ?string $content = null): string
    {
        $atts = shortcode_atts(['kurs' => '', 'funktion' => self::ANY, 'hinweis' => ''], is_array($atts) ? $atts : [], 'bootspruefung_zugang');
        $course = strtoupper(sanitize_text_field($atts['kurs']));
        $feature = sanitize_key($atts['funktion']) ?: self::ANY;
        if ($course === '') {
            return do_shortcode((string) $content);
        }
        if (self::allows(get_current_user_id(), $course, $feature) || (get_the_ID() && current_user_can('edit_post', get_the_ID()))) {
            return do_shortcode((string) $content);
        }

        return self::notice($course, $feature, (string) $atts['hinweis']);
    }

    public function registerBlock(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }
        $file = \Bootspruefung\Learning\Plugin::instance()->file();
        wp_register_script('bp-restricted-block', plugins_url('assets/admin/restricted-block.js', $file), ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n'], \Bootspruefung\Learning\Plugin::assetVersion($file, 'assets/admin/restricted-block.js'), true);
        add_action('enqueue_block_editor_assets', static function (): void {
            $features = [self::ANY => __('beliebige Funktion des Kurses', 'bootspruefung-learning')];
            foreach (AccessService::FEATURES as $f) {
                $features[$f] = Labels::feature($f);
            }
            wp_localize_script('bp-restricted-block', 'bpRestricted', ['courses' => Labels::courses(), 'features' => $features]);
        });
        register_block_type('bootspruefung/zugang', [
            'api_version' => 3,
            'editor_script' => 'bp-restricted-block',
            'attributes' => [
                'course' => ['type' => 'string', 'default' => ''],
                'feature' => ['type' => 'string', 'default' => self::ANY],
                'message' => ['type' => 'string', 'default' => ''],
            ],
            'render_callback' => static function (array $attrs, string $content): string {
                $course = (string) ($attrs['course'] ?? '');
                $feature = (string) ($attrs['feature'] ?? self::ANY);
                if ($course === '' || self::allows(get_current_user_id(), $course, $feature) || (get_the_ID() && current_user_can('edit_post', get_the_ID()))) {
                    return $content;
                }

                return self::notice($course, $feature, (string) ($attrs['message'] ?? ''));
            },
        ]);
    }

    /** Hinweis statt geschütztem Inhalt: Anmeldung bzw. passende Produkte. */
    public static function notice(string $course, string $feature, string $message = ''): string
    {
        $html = '<div class="bp-gate-notice" role="note"><p><strong>'.esc_html__('Dieser Inhalt gehört zum Lernzugang', 'bootspruefung-learning').'</strong> – '
            .esc_html(Labels::course($course).($feature !== self::ANY ? ' · '.Labels::feature($feature) : '')).'</p>';
        if ($message !== '') {
            $html .= '<p>'.esc_html($message).'</p>';
        }
        if (! is_user_logged_in()) {
            $html .= '<p><a class="button" href="'.esc_url(wp_login_url(get_permalink() ?: Router::url())).'">'.esc_html__('Anmelden', 'bootspruefung-learning').'</a></p>';
        }
        $offers = Integration::offers($course, $feature === self::ANY ? array_fill_keys([AccessService::QUESTION_BANK], false) : [$feature => false]);
        $list = [];
        foreach ($offers as $items) {
            foreach ($items as $o) {
                $list[$o['url']] = '<li><a href="'.esc_url($o['url']).'">'.esc_html($o['name']).'</a>'.($o['price'] !== '' ? ' <span>'.esc_html($o['price']).'</span>' : '').'</li>';
            }
        }
        if ($list !== []) {
            $html .= '<p>'.esc_html__('Freischalten mit:', 'bootspruefung-learning').'</p><ul>'.implode('', $list).'</ul>';
        }

        return $html.'</div>';
    }

    // ------------------------------------------------------------------ Metabox

    public function metaBox(): void
    {
        foreach (get_post_types(['public' => true]) as $type) {
            if (! in_array($type, ['attachment', 'product'], true)) {
                add_meta_box('bp-required-access', __('Lernzugang erforderlich', 'bootspruefung-learning'), [$this, 'renderMetaBox'], $type, 'side');
            }
        }
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        $req = self::requirement((int) $post->ID);
        wp_nonce_field('bp_required_access', 'bp_required_access_nonce');
        echo '<p><label for="bp-req-course">'.esc_html__('Kurs', 'bootspruefung-learning').'</label><br><select id="bp-req-course" name="bp_required_access[course]" style="width:100%"><option value="">'.esc_html__('– frei zugänglich –', 'bootspruefung-learning').'</option>';
        foreach (Labels::courses() as $code => $name) {
            printf('<option value="%s" %s>%s</option>', esc_attr($code), selected($req['course'] ?? '', $code, false), esc_html($name));
        }
        echo '</select></p><p><label for="bp-req-feature">'.esc_html__('Funktion', 'bootspruefung-learning').'</label><br><select id="bp-req-feature" name="bp_required_access[feature]" style="width:100%">';
        $features = [self::ANY => __('beliebige Funktion des Kurses', 'bootspruefung-learning')];
        foreach (AccessService::FEATURES as $f) {
            $features[$f] = Labels::feature($f);
        }
        foreach ($features as $code => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($code), selected($req['feature'] ?? self::ANY, $code, false), esc_html($label));
        }
        echo '</select></p><p class="description">'.esc_html__('Ohne Zugang sehen Besucher nur Titel, manuellen Auszug und einen Hinweis mit Produktlink.', 'bootspruefung-learning').'</p>';
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if (! isset($_POST['bp_required_access_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bp_required_access_nonce'])), 'bp_required_access')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can('edit_post', $postId)) {
            return;
        }
        $input = is_array($_POST['bp_required_access'] ?? null) ? wp_unslash($_POST['bp_required_access']) : [];
        $course = sanitize_text_field((string) ($input['course'] ?? ''));
        $feature = sanitize_key((string) ($input['feature'] ?? self::ANY));
        if ($course === '' || ! isset(Labels::courses()[$course])) {
            delete_post_meta($postId, self::META);

            return;
        }
        if ($feature !== self::ANY && ! in_array($feature, AccessService::FEATURES, true)) {
            $feature = self::ANY;
        }
        update_post_meta($postId, self::META, ['course' => $course, 'feature' => $feature]);
    }
}
