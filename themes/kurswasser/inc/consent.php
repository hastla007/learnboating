<?php
/**
 * Cookie-Einwilligung (TDDDG § 25, DSGVO): schlankes, selbst gehostetes Banner ohne Drittanbieter.
 *
 * Kategorien: „notwendig“ (immer aktiv) und „statistik“ (WooCommerce-Bestellzuordnung, sbjs_*-Cookies).
 * Einwilligungs-Cookie kw_consent = rawurlencode("<version>|<kategorien>|<unix-zeit>"), 12 Monate.
 * Ohne Einwilligung „statistik“ schaltet der Filter wc_order_attribution_allow_tracking die Bestellzuordnung
 * ab: WooCommerce startet sourcebuster.js dann nicht und löscht vorhandene sbjs_*-Cookies.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Bei inhaltlicher Änderung der Kategorien erhöhen – das Banner erscheint dann erneut. */
const KURSWASSER_CONSENT_VERSION = 1;
const KURSWASSER_CONSENT_COOKIE = 'kw_consent';

/**
 * Eingewilligte Kategorien aus dem Cookie (leer, wenn keine gültige Entscheidung vorliegt).
 *
 * @return list<string>
 */
function kurswasser_consent_categories(): array
{
    $raw = isset($_COOKIE[KURSWASSER_CONSENT_COOKIE]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[KURSWASSER_CONSENT_COOKIE])) : '';
    $parts = explode('|', rawurldecode($raw));
    if (count($parts) < 2 || (int) $parts[0] !== KURSWASSER_CONSENT_VERSION) {
        return [];
    }

    return array_values(array_intersect(['notwendig', 'statistik'], explode(',', $parts[1])));
}

/** Hat die besuchende Person in diese Kategorie eingewilligt? „notwendig“ ist immer erlaubt. */
function kurswasser_has_consent(string $category): bool
{
    return 'notwendig' === $category || in_array($category, kurswasser_consent_categories(), true);
}

/*
 * WooCommerce-Bestellzuordnung nur mit Einwilligung „Statistik“. Späte Priorität, damit die Entscheidung
 * auch gilt, wenn WooCommerce selbst einen Filter (WP Consent API, Kategorie „marketing“) registriert.
 */
add_filter('wc_order_attribution_allow_tracking', static fn ($allow): bool => (bool) $allow && kurswasser_has_consent('statistik'), 100);

/* WP Consent API (falls installiert): Opt-in-Modell melden; die Kategorien setzt consent.js per wp_set_consent(). */
add_filter('wp_get_consent_type', static fn () => 'optin', 20);

add_action('wp_enqueue_scripts', static function (): void {
    $dir = get_template_directory();
    $uri = get_template_directory_uri();
    $ver = static fn (string $rel): string => is_file($dir.$rel) ? (string) filemtime($dir.$rel) : KURSWASSER_VERSION;

    wp_enqueue_style('kurswasser-consent', $uri.'/assets/css/consent.css', [], $ver('/assets/css/consent.css'));
    wp_enqueue_script('kurswasser-consent', $uri.'/assets/js/consent.js', [], $ver('/assets/js/consent.js'), ['in_footer' => true, 'strategy' => 'defer']);
    wp_localize_script('kurswasser-consent', 'kwConsent', [
        'cookie' => KURSWASSER_CONSENT_COOKIE,
        'version' => KURSWASSER_CONSENT_VERSION,
        'path' => (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'),
        'secure' => is_ssl(),
    ]);
});

/**
 * Banner-Markup (startet versteckt; consent.js zeigt es ohne gültige Entscheidung oder auf Wunsch an).
 * Ausgabe früh im <body> (wp_body_open), damit es in der Tab-Reihenfolge vorn liegt; optisch unten fixiert.
 * Fallback wp_footer, falls ein Template wp_body_open nicht aufruft.
 */
function kurswasser_consent_banner(): void
{
    static $done = false;
    if ($done || is_admin() || is_customize_preview() || wp_is_json_request()) {
        return;
    }
    $done = true;
    $privacy = esc_url(kurswasser_url('datenschutz'));
    $stats = kurswasser_has_consent('statistik') ? ' checked' : '';
    ?>
<section class="kw-consent" id="kw-consent" role="dialog" aria-modal="false" aria-labelledby="kw-consent-title" aria-describedby="kw-consent-text" tabindex="-1" hidden data-kw-consent>
	<h2 class="kw-consent-title" id="kw-consent-title"><?php esc_html_e('Cookies & Datenschutz', 'kurswasser'); ?></h2>
	<p class="kw-consent-text" id="kw-consent-text"><?php
    printf(
        /* translators: %s: Link zur Datenschutzerklärung */
        esc_html__('Wir nutzen notwendige Cookies, damit Anmeldung und Warenkorb funktionieren. Mit deiner Einwilligung speichern wir zusätzlich, über welchen Weg du zu uns gekommen bist (Statistik). Du kannst deine Wahl jederzeit ändern. Mehr in der %s.', 'kurswasser'),
        '<a href="'.$privacy.'">'.esc_html__('Datenschutzerklärung', 'kurswasser').'</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL und Text oben escaped.
    );
    ?></p>
	<form class="kw-consent-settings" id="kw-consent-settings" hidden data-kw-consent-settings>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e('Cookie-Kategorien', 'kurswasser'); ?></legend>
			<div class="kw-consent-cat">
				<input type="checkbox" id="kw-consent-cat-notwendig" checked disabled aria-describedby="kw-consent-desc-notwendig">
				<label for="kw-consent-cat-notwendig"><strong><?php esc_html_e('Notwendig', 'kurswasser'); ?></strong> <span class="kw-consent-always"><?php esc_html_e('(immer aktiv)', 'kurswasser'); ?></span></label>
				<p id="kw-consent-desc-notwendig"><?php esc_html_e('Anmeldung, Warenkorb und das Speichern dieser Einstellung.', 'kurswasser'); ?></p>
			</div>
			<div class="kw-consent-cat">
				<input type="checkbox" id="kw-consent-cat-statistik" name="statistik" value="1"<?php echo $stats; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-describedby="kw-consent-desc-statistik">
				<label for="kw-consent-cat-statistik"><strong><?php esc_html_e('Statistik', 'kurswasser'); ?></strong></label>
				<p id="kw-consent-desc-statistik"><?php esc_html_e('WooCommerce-Bestellzuordnung: Herkunft deines Besuchs (z. B. Suchmaschine oder Link) und Sitzungsdauer, gespeichert in sbjs_*-Cookies. Keine Weitergabe an Dritte.', 'kurswasser'); ?></p>
			</div>
		</fieldset>
	</form>
	<div class="kw-consent-actions">
		<button type="button" class="kw-consent-btn" data-kw-consent-accept><?php esc_html_e('Alle akzeptieren', 'kurswasser'); ?></button>
		<button type="button" class="kw-consent-btn" data-kw-consent-reject><?php esc_html_e('Nur notwendige', 'kurswasser'); ?></button>
		<button type="button" class="kw-consent-btn kw-consent-btn-link" aria-expanded="false" aria-controls="kw-consent-settings" data-kw-consent-toggle><?php esc_html_e('Einstellungen', 'kurswasser'); ?></button>
		<button type="submit" form="kw-consent-settings" class="kw-consent-btn kw-consent-btn-link" hidden data-kw-consent-save><?php esc_html_e('Auswahl speichern', 'kurswasser'); ?></button>
	</div>
</section>
    <?php
}
add_action('wp_body_open', 'kurswasser_consent_banner', 5);
add_action('wp_footer', 'kurswasser_consent_banner', 50);

/** [kurswasser_consent_settings label="…"] – Knopf zum erneuten Öffnen der Cookie-Einstellungen (Fußzeile, Datenschutzerklärung). */
add_shortcode('kurswasser_consent_settings', static function ($atts): string {
    $atts = shortcode_atts(['label' => __('Cookie-Einstellungen ändern', 'kurswasser')], (array) $atts, 'kurswasser_consent_settings');

    return '<button type="button" class="kw-consent-open" aria-controls="kw-consent" data-kw-consent-open>'.esc_html((string) $atts['label']).'</button>';
});
