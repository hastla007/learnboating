<?php

declare(strict_types=1);

/*
 * Integrationstest Versionsabgleich Theme ↔ Lern-Plugin (Expertenrunde 3, ARC-08): Ist das Plugin zu alt
 * oder inkompatibel (nachgestellt über den Filter bootspruefung_learning_contract), nutzt das Theme keine
 * Plugin-Klassen, rendert Kurs- und Angebotsseiten wie ohne Plugin und zeigt im Admin einen Hinweis.
 *
 *   WP_DIR=/pfad/zu/wordpress php themes/kurswasser/tests/learning-compat-test.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 3).'/bootspruefung-learning/tests/integration/support/guard.php';

$theme = dirname(__DIR__);
if (! function_exists('kurswasser_learning_compatible')) {
    require_once $theme.'/functions.php'; // Theme nicht aktiv: Funktionen und Shortcodes trotzdem laden.
}

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
if (! function_exists('bootspruefung_learning_contract')) {
    fwrite(STDERR, "Lern-Plugin nicht aktiv.\n");
    exit(2);
}
wp_set_current_user(1);
$notice = static function (): string {
    ob_start();
    kurswasser_learning_notice();

    return (string) ob_get_clean();
};

echo "Aktuelles Plugin\n";
$check(kurswasser_learning_compatible(), 'kompatibel (Mindestversion '.KURSWASSER_LEARNING_MIN_VERSION.')');
$check(! str_contains($notice(), 'Kurswasser braucht das Plugin'), 'kein Admin-Hinweis');
$check(kurswasser_offer('BP-SBF-SEE') !== null, 'Angebot aus dem Plugin-Katalog');

echo "Älteres Plugin (nachgestellt)\n";
$old = static fn () => ['version' => '0.11.0', 'contract' => 1];
add_filter('bootspruefung_learning_contract', $old);
$check(! kurswasser_learning_compatible() && kurswasser_learning_version() === '0.11.0', 'als inkompatibel erkannt');
$error = null;
try {
    $html = do_shortcode('[kurswasser_offers]').do_shortcode('[kurswasser_course id="sbf-see"]').do_shortcode('[kurswasser_course id="src"]').do_shortcode('[kurswasser_course_tiles]');
} catch (\Throwable $e) {
    $error = $e;
    $html = '';
}
$check($error === null && $html !== '', 'Kurs- und Angebotsseiten rendern ohne Fehler'.($error ? ' ('.$error->getMessage().')' : ''));
$check(kurswasser_offer('BP-SBF-SEE') === null && kurswasser_price_note() === 'inkl. MwSt.', 'keine Plugin-Daten genutzt (Rückfall wie ohne Plugin)');
$check(str_contains($notice(), 'Kurswasser braucht das Plugin') && str_contains($notice(), '0.11.0'), 'Admin-Hinweis mit Mindest- und vorhandener Version');
remove_filter('bootspruefung_learning_contract', $old);
$other = static fn () => ['version' => '9.0.0', 'contract' => 2];
add_filter('bootspruefung_learning_contract', $other);
$check(! kurswasser_learning_compatible(), 'andere Vertragsversion: inkompatibel');
remove_filter('bootspruefung_learning_contract', $other);
$check(kurswasser_learning_compatible(), 'danach wieder kompatibel');

$headers = get_file_data($theme.'/style.css', ['update' => 'Update URI', 'wp' => 'Requires at least', 'version' => 'Version', 'author' => 'Author']);
$check($headers['update'] === 'false', 'Theme-Kopf: Update URI (keine fremden Updates von wordpress.org)');

echo "Theme-Version (T92)\n";
$check($headers['version'] === KURSWASSER_VERSION, 'Version in style.css = KURSWASSER_VERSION ('.$headers['version'].' / '.KURSWASSER_VERSION.')');
preg_match('/^## (\S+)/m', (string) @file_get_contents($theme.'/CHANGELOG.md'), $log);
$check(($log[1] ?? '') === KURSWASSER_VERSION, 'oberster Eintrag in CHANGELOG.md = Theme-Version');
$check($headers['author'] !== '' && ! str_contains($headers['author'], 'XXXX'), 'Theme-Kopf: Author ohne Platzhalter');
do_action('wp_enqueue_scripts');
$offers = wp_scripts()->registered['kurswasser-offers'] ?? null;
$check($offers !== null && $offers->ver === KURSWASSER_VERSION, 'offers.js trägt die Theme-Version (Browser laden nach einem Theme-Update neu)');

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
