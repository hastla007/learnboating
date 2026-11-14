<?php

declare(strict_types=1);

/**
 * T89: Prüft, dass jeder Übersetzungsaufruf die Text-Domain als festes Wort trägt (sonst findet
 * `wp i18n make-pot` den Text nicht) und dass zu jedem Teil eine aktuelle .pot-Datei im Ordner languages/ liegt.
 *
 * Aufruf aus dem Repository: php tools/i18n/check-domains.php
 * Neue Vorlagen erzeugen: bash tools/i18n/make-pot.sh
 */
$root = dirname(__DIR__, 2);
$parts = [
    'bootspruefung-learning' => ['dir' => 'bootspruefung-learning', 'skip' => ['tests', 'vendor', 'node_modules', 'assets/device-sim'], 'also' => ['woocommerce']],
    'kurswasser' => ['dir' => 'themes/kurswasser', 'skip' => ['tests', 'tools', 'node_modules'], 'also' => ['woocommerce', 'default']],
    'learnboating-affiliate-connector' => ['dir' => 'learnboating-affiliate-connector', 'skip' => ['tests'], 'also' => []],
    'learnboating-voucher-connector' => ['dir' => 'learnboating-voucher-connector', 'skip' => ['tests'], 'also' => []],
];
// Funktion => Position des Domain-Arguments (0-basiert).
$funcs = ['__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1, '_x' => 2, '_ex' => 2,
    'esc_html_x' => 2, 'esc_attr_x' => 2, '_n' => 3, '_nx' => 4, '_n_noop' => 2, '_nx_noop' => 3];

$errors = [];
$calls = 0;
foreach ($parts as $domain => $part) {
    $base = $root.'/'.$part['dir'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $rel = substr((string) $file, strlen($base) + 1);
        if ($file->getExtension() !== 'php' || array_filter($part['skip'], static fn ($s) => str_starts_with($rel, $s.'/')) !== []) {
            continue;
        }
        $t = token_get_all((string) file_get_contents((string) $file));
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            if (! is_array($t[$i]) || $t[$i][0] !== T_STRING || ! isset($funcs[$t[$i][1]])) {
                continue;
            }
            $j = $i - 1;
            while ($j >= 0 && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) {
                $j--;
            }
            if ($j >= 0 && is_array($t[$j]) && in_array($t[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $k = $i + 1;
            while ($k < $n && is_array($t[$k]) && $t[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if (($t[$k] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $arg = 0;
            $args = [[]];
            for ($m = $k; $m < $n; $m++) {
                $tok = $t[$m];
                $s = is_array($tok) ? $tok[1] : $tok;
                if (in_array($s, ['(', '[', '{'], true) || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    if (++$depth === 1) {
                        continue;
                    }
                }
                if (in_array($s, [')', ']', '}'], true) && --$depth === 0) {
                    break;
                }
                if ($s === ',' && $depth === 1) {
                    $args[++$arg] = [];
                    continue;
                }
                if (! is_array($tok) || $tok[0] !== T_WHITESPACE) {
                    $args[$arg][] = $tok;
                }
            }
            $calls++;
            $dom = $args[$funcs[$t[$i][1]]] ?? [];
            $literal = count($dom) === 1 && is_array($dom[0]) && $dom[0][0] === T_CONSTANT_ENCAPSED_STRING ? trim($dom[0][1], '\'"') : null;
            if ($literal === null || ($literal !== $domain && ! in_array($literal, $part['also'], true))) {
                $line = is_array($t[$i]) ? $t[$i][2] : 0;
                $shown = $dom === [] ? '(fehlt)' : implode('', array_map(static fn ($x) => is_array($x) ? $x[1] : $x, $dom));
                $errors[] = $part['dir'].'/'.$rel.':'.$line.' '.$t[$i][1].'() mit Domain '.$shown.' statt \''.$domain.'\'';
            }
        }
    }
    $pot = $base.'/languages/'.$domain.'.pot';
    if (! is_file($pot)) {
        $errors[] = $part['dir'].'/languages/'.$domain.'.pot fehlt (bash tools/i18n/make-pot.sh)';
    }
}

foreach ($errors as $e) {
    echo 'FEHLER '.$e."\n";
}
echo $errors === [] ? "Übersetzbarkeit: {$calls} Aufrufe mit fester Text-Domain, alle .pot-Dateien vorhanden.\n" : count($errors)." Fehler.\n";
exit($errors === [] ? 0 : 1);
