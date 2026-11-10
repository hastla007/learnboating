<?php
/**
 * Title: Häufige Fragen
 * Slug: kurswasser/faq
 * Categories: kurswasser
 * Inserter: yes
 *
 * Fragen: die ★-FAQs der Rubrik „Allgemeine Fragen“ aus dem Lern-Plugin (Bootsprüfung › FAQ).
 * WordPress wertet diese Datei erst aus, wenn das Muster gebraucht wird (je Seitenaufruf), daher
 * sind Änderungen im Admin sofort sichtbar. Ohne Plugin bzw. ohne ★-FAQ gilt der feste Text unten.
 * Variablen mit Präfix: die Datei läuft im Gültigkeitsbereich der Muster-Registry.
 */

$kurswasser_faq_items = function_exists('kurswasser_general_faqs') ? kurswasser_general_faqs() : [];
?>
<!-- wp:group {"tagName":"section","align":"wide","anchor":"faq","className":"kw-section kw-faq","layout":{"type":"constrained","contentSize":"820px"}} -->
<section id="faq" class="wp-block-group alignwide kw-section kw-faq"><!-- wp:group {"className":"kw-section-head has-text-align-center","layout":{"type":"default"}} --><div class="wp-block-group kw-section-head has-text-align-center"><!-- wp:paragraph {"align":"center","className":"kw-eyebrow"} --><p class="has-text-align-center kw-eyebrow">Häufige Fragen</p><!-- /wp:paragraph --><!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Gut zu wissen</h2><!-- /wp:heading --></div><!-- /wp:group -->
<?php if ($kurswasser_faq_items !== []) : ?>
<?php foreach ($kurswasser_faq_items as $kurswasser_faq_item) : ?>
<!-- wp:details --><details class="wp-block-details"><summary><?php echo esc_html($kurswasser_faq_item['question']); ?></summary><!-- wp:html --><?php echo $kurswasser_faq_item['answer_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- vom Plugin per wp_kses_post gefiltert ?><!-- /wp:html --></details><!-- /wp:details -->
<?php endforeach; ?>
<?php else : ?>
<?php
// Rückfall ohne Plugin: dieselben ★-FAQs „allgemein“ wie DefaultFaqs (Datenstand 3), Links nur auf veröffentlichte Seiten.
$kurswasser_faq_guarantee = function_exists('kurswasser_page_link') ? kurswasser_page_link('geld-zurueck-garantie-bootsfuehrerschein', __('Geld-zurück-Garantie', 'kurswasser')) : esc_html__('Geld-zurück-Garantie', 'kurswasser');
$kurswasser_faq_groups = function_exists('kurswasser_page_link') ? kurswasser_page_link('bootsfuehrerschein-gruppenangebote', __('Gruppenangebote', 'kurswasser')) : esc_html__('Gruppenangebote', 'kurswasser');
$kurswasser_faq_fallback = [
    ['Wie lange habe ich Zugriff auf die Lernplattform?', '<p>Sportbootführerscheine, Funkzeugnisse, Kombis, das Kapitäns-Bundle und Gruppenpakete laufen 12 Monate ab Zahlungseingang, SKN, FKN und Ergänzungskurse 6 Monate. Pässe laufen 30 oder 90 Tage, Simulator-Zugänge 14 Tage, 3 oder 12 Monate. Das Video-Upgrade läuft bis zum Ende deines Theoriezugangs. Bei Gruppenpaketen endet die Laufzeit für alle gleichzeitig. Kaufst du denselben Kurs erneut, schließt die neue Laufzeit an.</p>'],
    ['Kann ich den Kurs vor dem Kauf ausprobieren?', '<p>Ja. Mit einem kostenlosen Kundenkonto testest du in jedem Kurs:</p><ul class="wp-block-list"><li>10 Fragen je Modul;</li><li>einen Prüfungsbogen;</li><li>die erste Video-Lektion;</li><li>die erste Navigationsaufgabe;</li><li>die erste Praxiskarte.</li></ul><p>Bei den Funkkursen kannst du die Testversion des Funkgerät-Simulators ausprobieren. Die Zufallsprüfung gehört zum gekauften Kurs.</p>'],
    ['Brauche ich eine App, um zu lernen?', '<p>Nein. Du lernst direkt im Browser, am Rechner, Tablet oder Smartphone, ohne Installation. Dein Fortschritt wird in deinem Konto gespeichert. Eine App für iOS und Android ist in Vorbereitung. Sie nutzt dasselbe Konto und denselben Lernstand.</p>'],
    ['Wann kann ich nach dem Kauf loslegen?', '<p>Sobald die Zahlung eingegangen ist, wird dein Zugang automatisch freigeschaltet. Bei Überweisung kann es bis zum Zahlungseingang dauern.</p>'],
    ['Ist die amtliche Prüfung im Kurs enthalten?', '<p>Nein. Der Kurs bereitet dich online vor. Die amtliche Prüfung legst du bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl ab; Anmeldung, Termin und Prüfungsgebühr laufen direkt über den Ausschuss.</p>'],
    ['Gibt es eine Geld-zurück- oder Bestehensgarantie?', '<p>Eine Bestehensgarantie gibt es nicht: Die Prüfung nimmt ein Prüfungsausschuss des DSV oder DMYV ab, und dein Ergebnis hängt von deiner Vorbereitung ab. Zusätzlich zu deinen gesetzlichen Rechten geben wir dir freiwillig eine Geld-zurück-Garantie:</p><ul class="wp-block-list">'
        .'<li><strong>Frist:</strong> 14 Tage ab Zahlungseingang, auf die Minute genau. Das Fristende siehst du unter „Mein Lernen“.</li>'
        .'<li><strong>Bedingung:</strong> Du hast seit dem Zahlungseingang weniger als 20&nbsp;% der gekauften Inhalte genutzt. Gezählt werden die seit dem Zahlungseingang beantworteten Prüfungsfragen der gekauften Kurse (im Smarttrainer oder in den Quizfragen der Video-Lektionen, jede Frage einmal) und die abgeschlossenen Video-Lektionen. Bei der Theorie-Stufe zählen nur Fragen, beim Video-Upgrade nur Lektionen, bei Komplett, Kombis, Bundle und Pässen beides. Mehrere Produkte einer Bestellung werden zusammen berechnet.</li>'
        .'<li><strong>Gilt für:</strong> Einzelkurse (Theorie und Komplett), Video-Upgrades, Ergänzungskurse, Kombikurse, das Kapitäns-Bundle und die Pässe – auch soweit sie den Funkgerät-Simulator enthalten.</li>'
        .'<li><strong>Gilt nicht für:</strong> Gruppenpakete, eigenständige Zugänge zum Funkgerät-Simulator (Simulator-Pässe und Simulator-Upgrade) und bereits ganz oder teilweise erstattete Produkte.</li>'
        .'<li><strong>So geht’s:</strong> Unter „Mein Lernen“ auf „Geld zurück anfordern“ klicken; eine Anfrage je Bestellung, Bestätigung per E-Mail. Wir erstatten den gezahlten Preis der Produkte mit Garantie über deine ursprüngliche Zahlungsart. Produkte ohne Garantie bleiben nutzbar; der Zugang zu den erstatteten Kursen endet.</li>'
        .'<li><strong>Widerrufsrecht:</strong> Die Garantie ist kein Widerrufsrecht. Bei digitalen Kursen erlischt das Widerrufsrecht, sobald dein Zugang nach deiner Zustimmung in der Kasse freigeschaltet ist.</li></ul>'
        .'<p>Alle Bedingungen: '.$kurswasser_faq_guarantee.' und <a href="#kw:agb#geld-zurueck-garantie">AGB</a>.</p>'],
    ['Wie funktionieren Gruppenpakete?', '<p>Eine Person kauft das Paket mit 2 bis 50 Plätzen und lädt die Teilnehmenden per Link oder E-Mail ein; ein Platz kann auch für sie selbst sein. Jede Person lernt mit eigenem Konto und eigenem Fortschritt. Den Lernstand der anderen sieht niemand. Die Laufzeit von 12 Monaten beginnt mit der Zahlung und endet für alle gleichzeitig. Für Gruppenpakete gilt die Geld-zurück-Garantie nicht. Mehr dazu: '.$kurswasser_faq_groups.'.</p>'],
];
?>
<?php foreach ($kurswasser_faq_fallback as [$kurswasser_faq_q, $kurswasser_faq_a]) : ?>
<!-- wp:details --><details class="wp-block-details"><summary><?php echo esc_html($kurswasser_faq_q); ?></summary><!-- wp:html --><?php echo $kurswasser_faq_a; // phpcs:ignore WordPress.Security.EscapeOutput -- feste Texte, Links per kurswasser_page_link() ?><!-- /wp:html --></details><!-- /wp:details -->
<?php endforeach; ?>
<?php endif; ?>
</section>
<!-- /wp:group -->
