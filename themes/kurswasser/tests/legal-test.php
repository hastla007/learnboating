<?php

declare(strict_types=1);

/*
 * Integrationstest Rechtstext-Vorlagen und Betreiberangaben (To-do T11): Angaben aus „Design › Betreiberangaben“
 * ersetzen die passenden [[Betreiber: …]]-Platzhalter beim Übernehmen der Vorlagen (maskiert), leere Angaben und
 * Platzhalter mit Entscheidung bleiben, geänderte Angaben aktualisieren unbearbeitete Entwürfe, bearbeitete
 * Seiten bleiben unberührt.
 *
 *   WP_DIR=/pfad/zu/wordpress php themes/kurswasser/tests/legal-test.php
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
if (! function_exists('kurswasser_operator_fill')) {
    require_once $theme.'/functions.php';
}

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
wp_set_current_user(1);
$backup = get_option(KURSWASSER_OPERATOR_OPTION, null);
$created = [];

echo "Platzhalter ersetzen\n";
$data = array_fill_keys(array_keys(kurswasser_operator_fields()), '');
$data['firma'] = 'Wasser & Wind GmbH';
$data['strasse'] = 'Hafenstraße 1';
$data['plz_ort'] = '20457 Hamburg';
$data['email'] = 'info@example.org';
$html = '<p>[[Betreiber: Firmenname]] · [[Betreiber: Anschrift]] · [[Betreiber: E-Mail]] · [[Betreiber: Telefon]] · [[Betreiber: ja/nein – zutreffende Variante wählen, die andere löschen.]]</p>';
$filled = kurswasser_operator_fill($html, $data);
$check(str_contains($filled, 'Wasser &amp; Wind GmbH') && str_contains($filled, 'Hafenstraße 1, 20457 Hamburg') && str_contains($filled, 'info@example.org'), 'Firmenname, Anschrift und E-Mail eingesetzt (maskiert)');
$check(str_contains($filled, '[[Betreiber: Telefon]]') && str_contains($filled, '[[Betreiber: ja/nein'), 'leere Angabe und Entscheidungs-Platzhalter bleiben stehen');
$half = $data;
$half['plz_ort'] = '';
$check(str_contains(kurswasser_operator_fill('[[Betreiber: Anschrift]]', $half), '[[Betreiber: Anschrift]]'), 'unvollständige Anschrift wird nicht halb eingesetzt');
$xss = $data;
$xss['firma'] = '<script>alert(1)</script>';
$check(! str_contains(kurswasser_operator_fill('[[Betreiber: Firmenname]]', $xss), '<script>'), 'Werte werden HTML-maskiert');
$garant = $data;
$garant['erstattungsfrist'] = '14 Tage';
$note = kurswasser_operator_fill('[kurswasser_page_hero note="Garantiegeber: &#91;&#91;Betreiber: Firma, Anschrift, E-Mail&#93;&#93;"] spätestens [[Betreiber: Erstattungsfrist]]', $garant);
$check(str_contains($note, 'Garantiegeber: Wasser &amp; Wind GmbH, Hafenstraße 1, 20457 Hamburg, info@example.org') && str_contains($note, 'spätestens 14 Tage'), 'Garantie: Garantiegeber im Seitenkopf (maskierte Klammern) und Erstattungsfrist');
$check(kurswasser_content_template(['file' => '09-geld-zurueck-garantie-bootsfuehrerschein.html']) !== null, 'Inhaltsseiten-Vorlage wird mit Betreiberangaben gelesen');
$clean = kurswasser_operator_sanitize(['firma' => '  <b>Firma</b>  ', 'email' => 'kein@mail', 'support_email' => 'support@example.org']);
$check($clean['firma'] === 'Firma' && $clean['email'] === '' && $clean['support_email'] === 'support@example.org', 'Speichern bereinigt Text und E-Mail');

echo "Fuß der Plugin-Mails (T58)\n";
$footerData = $data;
$footerData['vertretung'] = 'Erika Muster';
$footerData['register'] = 'Amtsgericht Hamburg, HRB 12345';
$mailFooter = kurswasser_operator_mail_footer($footerData);
$check($mailFooter === "Wasser & Wind GmbH\nHafenstraße 1, 20457 Hamburg\nVertreten durch: Erika Muster\nRegistereintrag: Amtsgericht Hamburg, HRB 12345\nE-Mail: info@example.org", 'Firma, Anschrift, Vertretung, Register und E-Mail (Klartext, nicht maskiert)');
$check(kurswasser_operator_mail_footer($half) === '', 'ohne vollständige Anschrift: kein eigener Fuß');
update_option(KURSWASSER_OPERATOR_OPTION, $data, false);
$check(str_starts_with((string) apply_filters('bootspruefung_mail_footer', 'Standard'), "Wasser & Wind GmbH\nHafenstraße 1, 20457 Hamburg"), 'Filter bootspruefung_mail_footer liefert die Betreiberangaben');
update_option(KURSWASSER_OPERATOR_OPTION, $half, false);
$check(apply_filters('bootspruefung_mail_footer', 'Standard') === 'Standard', 'unvollständige Angaben: Standard des Plugins bleibt');
if (class_exists(\Bootspruefung\Learning\Support\Mail::class)) {
    update_option(KURSWASSER_OPERATOR_OPTION, $data, false);
    $sentMails = [];
    $catchMail = static function ($short, $atts) use (&$sentMails) {
        $sentMails[] = $atts;

        return true;
    };
    add_filter('pre_wp_mail', $catchMail, 10, 2);
    \Bootspruefung\Learning\Support\Mail::send('kunde@example.test', 'Test', "Hallo,\n\nText.");
    remove_filter('pre_wp_mail', $catchMail, 10);
    $check(count($sentMails) === 1 && str_ends_with((string) $sentMails[0]['message'], "Text.\n\n-- \nWasser & Wind GmbH\nHafenstraße 1, 20457 Hamburg\nE-Mail: info@example.org"), 'Plugin-Mail endet mit den Betreiberangaben');
}
$backup === null ? delete_option(KURSWASSER_OPERATOR_OPTION) : update_option(KURSWASSER_OPERATOR_OPTION, $backup, false);

echo "Alle Vorlagen\n";
$before = array_sum(array_map('count', kurswasser_operator_open_placeholders()));
$all = array_fill_keys(array_keys(kurswasser_operator_fields()), 'Testwert');
foreach (['email', 'support_email', 'privacy_email'] as $mailKey) {
    $all[$mailKey] = 'test@example.org';
}
update_option(KURSWASSER_OPERATOR_OPTION, $all, false);
$open = kurswasser_operator_open_placeholders();
$after = array_sum(array_map('count', $open));
$check($before >= 70 && $after < $before && $after > 0, "alle Angaben eingetragen: offene Platzhalter von {$before} auf {$after} (Rest = Entscheidungen/Prüfungen)");
$check(! in_array('Firmenname', array_merge(...array_values($open)), true) && ! in_array('E-Mail', array_merge(...array_values($open)), true), 'Firmenname und E-Mail sind in keiner Vorlage mehr offen');

echo "Rechtsseiten übernehmen\n";
foreach (array_keys(kurswasser_legal_pages()) as $slug) {
    $page = kurswasser_legal_page($slug);
    if (! $page) {
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_name' => $slug, 'post_title' => ucfirst($slug), 'post_content' => ''], true);
        if (! is_wp_error($id)) {
            $created[] = (int) $id;
        }
    }
}
$imp = kurswasser_legal_page('impressum');
$agb = kurswasser_legal_page('agb');
$impBak = $imp ? $imp->post_content : '';
$agbBak = $agb ? $agb->post_content : '';
if ($imp && $agb) {
    wp_update_post(['ID' => $imp->ID, 'post_content' => '']);
    wp_update_post(['ID' => $agb->ID, 'post_content' => '<!-- wp:paragraph --><p>Vom Betreiber geschrieben.</p><!-- /wp:paragraph -->']);
    delete_post_meta($agb->ID, '_kurswasser_legal_hash');
    $result = kurswasser_fill_legal_pages(false, false);
    $impNow = (string) get_post_field('post_content', $imp->ID);
    $check(in_array($result['impressum'] ?? '', ['filled', 'updated'], true) && str_contains($impNow, 'Testwert') && ! str_contains($impNow, '[[Betreiber: Name bzw. Firma]]'), 'leere Seite: Vorlage mit Betreiberangaben übernommen');
    $check(($result['agb'] ?? '') === 'edited' && str_contains((string) get_post_field('post_content', $agb->ID), 'Vom Betreiber geschrieben.'), 'bearbeitete Seite bleibt unberührt');
    $hashBefore = kurswasser_legal_templates_hash();
    $all['firma'] = 'Neue Firma';
    update_option(KURSWASSER_OPERATOR_OPTION, $all, false);
    $check(kurswasser_legal_templates_hash() !== $hashBefore, 'geänderte Angaben ändern die Vorlagen-Prüfsumme (Abgleich wird ausgelöst)');
    $result = kurswasser_fill_legal_pages(false, false);
    $check(($result['impressum'] ?? '') === 'updated' && str_contains((string) get_post_field('post_content', $imp->ID), 'Neue Firma'), 'unbearbeitete Seite bekommt die geänderte Angabe');
} else {
    $check(false, 'Rechtsseiten impressum/agb vorhanden');
}

// Aufräumen
if ($imp) {
    wp_update_post(['ID' => $imp->ID, 'post_content' => $impBak]);
}
if ($agb) {
    wp_update_post(['ID' => $agb->ID, 'post_content' => $agbBak]);
}
foreach ($created as $id) {
    wp_delete_post($id, true);
}
if ($backup === null) {
    delete_option(KURSWASSER_OPERATOR_OPTION);
} else {
    update_option(KURSWASSER_OPERATOR_OPTION, $backup, false);
}

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
