<?php
/**
 * Title: Ratgeber (Kacheln)
 * Slug: kurswasser/guide-tiles
 * Categories: kurswasser
 * Inserter: yes
 *
 * Ratgeber-Band für die Startseite: vier Kacheln mit dem Hero-Foto der jeweiligen Ratgeberseite. Es erscheinen nur
 * veröffentlichte Seiten; ohne eine einzige veröffentlichte Seite entfällt das Band. Die Datei wird je Aufruf ausgewertet.
 * Variablen mit Präfix: die Datei läuft im Gültigkeitsbereich der Muster-Registry.
 */

$kurswasser_guides = [
    ['sportbootfuehrerschein', 'Sportbootführerschein', 'Welcher Schein, welche Voraussetzungen, welcher Weg.', 'anchor', 'sbf-see', 'Überblick'],
    ['bootsfuehrerschein-kosten', 'Kosten des Bootsführerscheins', 'Kurs, Praxis, Prüfungsgebühr und Attest.', 'cart', 'pakete', 'Kosten'],
    ['mindestalter-bootsfuehrerschein', 'Mindestalter', 'Ab wann du welchen Schein machen kannst.', 'user', 'sbf-binnen-segel', 'Voraussetzungen'],
    ['aerztliches-attest-bootsfuehrerschein', 'Ärztliches Attest', 'Wer es ausstellt und wie alt es sein darf.', 'shield', 'ubi', 'Voraussetzungen'],
];
$kurswasser_guide_html = '';
foreach ($kurswasser_guides as [$kurswasser_guide_path, $kurswasser_guide_title, $kurswasser_guide_text, $kurswasser_guide_icon, $kurswasser_guide_photo, $kurswasser_guide_pill]) {
    if (! function_exists('kurswasser_page_published') || ! kurswasser_page_published($kurswasser_guide_path)) {
        continue;
    }
    $kurswasser_guide_img = function_exists('kurswasser_course_image') ? (string) kurswasser_course_image($kurswasser_guide_photo, true) : '';
    $kurswasser_guide_html .= '<article class="kw-tile"><div class="kw-tile-photo"'.($kurswasser_guide_img !== '' ? ' style="background-image:url(\''.esc_url($kurswasser_guide_img).'\')"' : '').'><span class="kw-tile-pill"><span class="kw-icon kw-icon-'.esc_attr($kurswasser_guide_icon).'" aria-hidden="true"></span>'.esc_html($kurswasser_guide_pill).'</span></div>'
        .'<div class="kw-tile-body"><h3><a href="'.esc_url(home_url('/'.$kurswasser_guide_path.'/')).'">'.esc_html($kurswasser_guide_title).'</a></h3><p class="kw-tile-text">'.esc_html($kurswasser_guide_text).'</p></div>'
        .'<footer class="kw-tile-foot"><span class="kw-tile-go">Lesen<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></span></footer></article>';
}
if ($kurswasser_guide_html === '') {
    return;
}
$kurswasser_guide_all = function_exists('kurswasser_page_published') && kurswasser_page_published('ratgeber-bootsfuehrerschein')
    ? '<p class="kw-courses-all"><a href="'.esc_url(home_url('/ratgeber-bootsfuehrerschein/')).'">Alle Ratgeber ansehen<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></a></p>' : '';
?>
<!-- wp:group {"tagName":"section","align":"full","anchor":"ratgeber","className":"kw-section kw-mist kw-guides","layout":{"type":"constrained","wideSize":"1240px"}} -->
<section id="ratgeber" class="wp-block-group alignfull kw-section kw-mist kw-guides"><!-- wp:html -->
<div class="kw-courses-head alignwide"><div><p class="kw-eyebrow">Ratgeber</p><h2 class="wp-block-heading">Gut vorbereitet zur Prüfung</h2></div><p class="kw-lead">Was du vor der Anmeldung wissen solltest: Schein, Kosten, Alter und Attest – kurz erklärt.</p></div>
<div class="kw-tiles kw-more-tiles alignwide"><?php echo $kurswasser_guide_html; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert ?></div>
<?php echo $kurswasser_guide_all; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert ?>
<!-- /wp:html --></section>
<!-- /wp:group -->
