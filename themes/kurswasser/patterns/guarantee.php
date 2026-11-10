<?php
/**
 * Title: Geld-zurück-Garantie
 * Slug: kurswasser/guarantee
 * Categories: kurswasser
 * Inserter: yes
 *
 * Text wie Seite „Geld-zurück-Garantie“ und AGB Nr. 9 (Ausnahmen: nur Gruppenpakete und eigenständige Simulator-
 * Zugänge; die Garantie ist kein Widerrufsrecht). Der Button führt auf die Garantieseite, sobald sie veröffentlicht
 * ist (auf der Garantieseite selbst und bis dahin: auf den AGB-Abschnitt). Die Datei wird je Aufruf ausgewertet.
 * Variablen mit Präfix: die Datei läuft im Gültigkeitsbereich der Muster-Registry.
 */

$kurswasser_guarantee_page = function_exists('kurswasser_page_published') && kurswasser_page_published('geld-zurueck-garantie-bootsfuehrerschein')
    ? get_page_by_path('geld-zurueck-garantie-bootsfuehrerschein', OBJECT, 'page') : null;
$kurswasser_guarantee_queried = get_queried_object();
$kurswasser_guarantee_self = $kurswasser_guarantee_queried instanceof WP_Post && $kurswasser_guarantee_queried->post_type === 'page' && get_page_uri($kurswasser_guarantee_queried) === 'geld-zurueck-garantie-bootsfuehrerschein';
if ($kurswasser_guarantee_page instanceof WP_Post && ! $kurswasser_guarantee_self) {
    $kurswasser_guarantee_href = esc_url((string) get_permalink($kurswasser_guarantee_page));
    $kurswasser_guarantee_label = 'Alle Bedingungen';
} else {
    $kurswasser_guarantee_href = '#kw:agb#geld-zurueck-garantie';
    $kurswasser_guarantee_label = $kurswasser_guarantee_self ? 'Vollständige Bedingungen in den AGB' : 'Bedingungen in den AGB';
}
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"kw-section","style":{"spacing":{"padding":{"bottom":"0"}}},"layout":{"type":"default"}} -->
<section class="wp-block-group alignwide kw-section" style="padding-bottom:0"><!-- wp:html -->
<div class="kw-guarantee">
	<span class="kw-guarantee-icon"><span class="kw-icon kw-icon-shield" aria-hidden="true"></span></span>
	<div><h2>14 Tage Geld-zurück-Garantie</h2><p>Innerhalb von 14 Tagen ab Zahlungseingang erstatten wir dir den gezahlten Preis der Produkte mit Garantie, wenn du seit dem Zahlungseingang weniger als 20&nbsp;% der gekauften Inhalte genutzt hast. Die Erstattung forderst du unter „Mein Lernen“ an. Gruppenpakete und eigenständige Zugänge zum Funkgerät-Simulator sind ausgenommen. Die Garantie ist kein Widerrufsrecht (siehe <a href="#kw:widerruf">Widerrufsbelehrung</a>).</p></div>
	<div class="wp-block-buttons"><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="<?php echo $kurswasser_guarantee_href; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_url bzw. fester #kw:-Link ?>"><?php echo esc_html($kurswasser_guarantee_label); ?></a></div></div>
</div>
<!-- /wp:html --></section>
<!-- /wp:group -->
