<?php
/**
 * Cookie-Einwilligung (TDDDG § 25, DSGVO): schlankes, selbst gehostetes Banner ohne Drittanbieter.
 *
 * Kategorien: „notwendig“ (immer aktiv), „statistik“ (WooCommerce-Bestellzuordnung, sbjs_*-Cookies) und – nur
 * wenn Inhalte von fremden Servern geladen würden (Filter kurswasser_consent_external_hosts, z. B. Videos des
 * Lern-Plugins bei einem Video-Dienst) – „extern“ (Externe Medien: Laden erst nach Einwilligung bzw. Klick).
 * Einwilligungs-Cookie kw_consent = rawurlencode("<version>|<kategorien>|<unix-zeit>"), 12 Monate.
 * Seiten-Cache-fest: Der Server liefert allen dieselbe Seite. wc_order_attribution_allow_tracking ist daher
 * immer false. Ein Inline-Script direkt vor order-attribution.js liest kw_consent im Browser und setzt bei
 * Statistik-Einwilligung params.allowTracking = true – sonst würde WooCommerce beim Start die sbjs_*-Cookies
 * löschen und jede Bestellung als „Direkt“ zuordnen. consent.js ruft setOrderTracking() nur bei einer
 * Änderung der Wahl auf. Auch Banner und Häkchen setzt erst das Script, nichts hängt vom Cookie im HTML ab.
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
/** Gültigkeit einer Entscheidung (Sekunden) – wie max-age des Cookies. */
const KURSWASSER_CONSENT_MAX_AGE = 365 * 24 * 60 * 60;

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
    $time = (int) ($parts[2] ?? 0);
    if ($time > 0 && time() - $time > KURSWASSER_CONSENT_MAX_AGE) {
        return [];
    }

    return array_values(array_intersect(['notwendig', 'statistik', 'extern'], explode(',', $parts[1])));
}

/**
 * Fremde Server, von denen Inhalte geladen würden (z. B. Video-Dienst des Lern-Plugins). Nicht leer = das Banner
 * zeigt die Kategorie „Externe Medien“. @return list<string> Hostnamen
 */
function kurswasser_consent_external_hosts(): array
{
    $hosts = apply_filters('kurswasser_consent_external_hosts', []);

    $hosts = array_map(static fn ($h) => strtolower(trim((string) $h)), is_array($hosts) ? $hosts : []);

    // Nur echte Hostnamen (Buchstaben, Ziffern, Bindestrich, Punkt) – der Wert erscheint im Banner.
    return array_values(array_unique(array_filter($hosts, static fn (string $h): bool => (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $h))));
}

/** Hat die besuchende Person in diese Kategorie eingewilligt? „notwendig“ ist immer erlaubt. */
function kurswasser_has_consent(string $category): bool
{
    return 'notwendig' === $category || in_array($category, kurswasser_consent_categories(), true);
}

/*
 * WooCommerce-Bestellzuordnung serverseitig immer aus: Der Wert landet per wp_localize_script im HTML und
 * würde mit einem Seiten-Cache an alle Besucher ausgeliefert. Eingeschaltet wird nur im Browser
 * (kurswasser_consent_tracking_script() vor order-attribution.js, Änderungen per consent.js).
 * Späte Priorität, damit auch der Filter der WP-Consent-API-Integration von WooCommerce überstimmt wird.
 */
add_filter('wc_order_attribution_allow_tracking', '__return_false', 100);

/* WP Consent API (falls installiert): Opt-in-Modell melden; die Kategorien setzt consent.js per wp_set_consent(). */
add_filter('wp_get_consent_type', static fn () => 'optin', 20);

add_action('wp_enqueue_scripts', static function (): void {
    $dir = get_template_directory();
    $uri = get_template_directory_uri();
    $ver = static fn (string $rel): string => is_file($dir.$rel) ? (string) filemtime($dir.$rel) : KURSWASSER_VERSION;

    wp_enqueue_style('kurswasser-consent', $uri.'/assets/css/consent.css', [], $ver('/assets/css/consent.css'));
    // Nach dem WooCommerce-Script laden, damit window.wc_order_attribution beim Start schon existiert.
    $deps = wp_script_is('wc-order-attribution', 'registered') ? ['wc-order-attribution'] : [];
    wp_enqueue_script('kurswasser-consent', $uri.'/assets/js/consent.js', $deps, $ver('/assets/js/consent.js'), ['in_footer' => true, 'strategy' => 'defer']);
    wp_localize_script('kurswasser-consent', 'kwConsent', [
        'cookie' => KURSWASSER_CONSENT_COOKIE,
        'version' => KURSWASSER_CONSENT_VERSION,
        'maxAge' => KURSWASSER_CONSENT_MAX_AGE,
        'path' => (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'),
        'secure' => is_ssl(),
        'saved' => __('Deine Auswahl ist gespeichert.', 'kurswasser'),
        'external' => kurswasser_consent_external_hosts() !== [],
    ]);

    /*
     * WP Consent API: WooCommerce führt die Bestellzuordnung dort als „marketing“. Für uns ist sie Statistik
     * (Herkunft des Besuchs, keine Weitergabe, kein Werbeprofil) – Banner und Datenschutzerklärung sagen das
     * ebenso. Die Integration von WooCommerce deshalb auf „statistics“ umstellen; „marketing“ geben wir nie frei.
     */
    /*
     * Vor order-attribution.js (nach dessen wp_localize_script-Daten): Mit gültiger Statistik-Einwilligung
     * startet WooCommerce die Erfassung selbst, statt beim Start die sbjs_*-Cookies zu löschen. Ohne
     * Einwilligung bleibt allowTracking false (WooCommerce löscht die Cookies). Passt zur WP Consent API:
     * consent.js hält deren Kategorie „statistics“ synchron zu kw_consent.
     */
    if (wp_script_is('wc-order-attribution', 'registered')) {
        wp_add_inline_script('wc-order-attribution', kurswasser_consent_tracking_script(), 'before');
    }

    if (wp_script_is('wp-consent-api-integration', 'enqueued')) {
        /*
         * Außerdem: Die Integration ruft bei wp_consent_type_defined setOrderTracking(true) auf, obwohl die
         * Erfassung (Inline-Script oben) schon läuft – sourcebuster.js zählte jeden Seitenaufruf doppelt.
         * Wiederholte true-Aufrufe bei laufender Erfassung daher ignorieren; false geht immer durch.
         */
        wp_add_inline_script(
            'wp-consent-api-integration',
            '(function(o){if(!o||!o.params){return;}o.params.consentCategory="statistics";var f=o.setOrderTracking;if(typeof f!=="function"){return;}'
            .'o.setOrderTracking=function(a){if(a&&o.params.allowTracking&&typeof sbjs!=="undefined"&&sbjs.get){return;}return f.apply(this,arguments);};})(window.wc_order_attribution);',
            'before'
        );
    }
}, 100);

/** Inline-Script: kw_consent im Browser lesen, bei Statistik-Einwilligung allowTracking einschalten. */
function kurswasser_consent_tracking_script(): string
{
    return sprintf(
        '(function(){var o=window.wc_order_attribution;if(!o||!o.params){return;}'
        .'var m=document.cookie.match(/(?:^|;\\s*)%1$s=([^;]*)/);if(!m){return;}'
        .'var p;try{p=decodeURIComponent(m[1]).split("|");}catch(e){return;}'
        .'var t=parseInt(p[2],10);'
        .'if(p.length<2||parseInt(p[0],10)!==%2$d||(t>0&&Date.now()/1e3-t>%3$d)){return;}'
        .'if(p[1].split(",").indexOf("statistik")!==-1){o.params.allowTracking=true;}})();',
        KURSWASSER_CONSENT_COOKIE,
        KURSWASSER_CONSENT_VERSION,
        KURSWASSER_CONSENT_MAX_AGE
    );
}

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
				<input type="checkbox" id="kw-consent-cat-statistik" name="statistik" value="1" aria-describedby="kw-consent-desc-statistik">
				<label for="kw-consent-cat-statistik"><strong><?php esc_html_e('Statistik', 'kurswasser'); ?></strong></label>
				<p id="kw-consent-desc-statistik"><?php esc_html_e('WooCommerce-Bestellzuordnung: Herkunft deines Besuchs (z. B. Suchmaschine oder Link) und Sitzungsdauer, gespeichert in sbjs_*-Cookies. Keine Weitergabe an Dritte.', 'kurswasser'); ?></p>
			</div>
			<?php if (($kwHosts = kurswasser_consent_external_hosts()) !== []) : ?>
			<div class="kw-consent-cat">
				<input type="checkbox" id="kw-consent-cat-extern" name="extern" value="1" aria-describedby="kw-consent-desc-extern">
				<label for="kw-consent-cat-extern"><strong><?php esc_html_e('Externe Medien', 'kurswasser'); ?></strong></label>
				<p id="kw-consent-desc-extern"><?php
                /* translators: %s: Hostnamen, z. B. videos.example.com */
                echo esc_html(sprintf(__('Videos werden von einem externen Dienst geladen (%s). Dabei erhält der Anbieter deine IP-Adresse. Ohne Einwilligung lädst du ein Video mit einem Klick einzeln.', 'kurswasser'), implode(', ', $kwHosts)));
                ?></p>
			</div>
			<?php endif; ?>
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

/**
 * [kurswasser_consent_settings label="…"] – Knopf zum erneuten Öffnen der Cookie-Einstellungen (Fußzeile,
 * Datenschutzerklärung). Startet mit hidden: Ohne JavaScript könnte er nichts öffnen (und ohne JavaScript
 * werden auch keine einwilligungspflichtigen Cookies gesetzt); consent.js blendet ihn ein – samt einem
 * umgebenden Element mit data-kw-consent-wrap (Listenpunkt der Fußzeile, Absatz des Shortcode-Blocks).
 */
add_shortcode('kurswasser_consent_settings', static function ($atts): string {
    $atts = shortcode_atts(['label' => __('Cookie-Einstellungen', 'kurswasser')], (array) $atts, 'kurswasser_consent_settings');

    return '<button type="button" class="kw-consent-open" aria-controls="kw-consent" hidden data-kw-consent-open>'.esc_html((string) $atts['label']).'</button>';
});

/*
 * Shortcode-Block, der nur den Knopf enthält (Datenschutzerklärung): Hat wpautop ihn in einen Absatz gepackt,
 * den Absatz ebenfalls verstecken, damit ohne JavaScript kein leerer Absatz stehen bleibt. (WordPress 7.1
 * ersetzt den Shortcode schon im Block, daher beide Formen; ohne Absatz bleibt die Ausgabe unverändert.)
 */
add_filter('render_block_core/shortcode', static function (string $html): string {
    $inner = '(?:\[kurswasser_consent_settings\b[^\]]*\]|<button\b[^>]*\bdata-kw-consent-open\b[^>]*>.*?</button>)';
    $out = preg_replace('#^(\s*)<p>(\s*'.$inner.'\s*)</p>(\s*)$#s', '$1<p hidden data-kw-consent-wrap>$2</p>$3', $html);

    return is_string($out) ? $out : $html;
});
