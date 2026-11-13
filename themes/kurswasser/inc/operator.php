<?php
/**
 * Betreiberangaben: Firmenname, Anschrift, Kontakt, Register, Steuernummern, Dienstleister usw. einmal
 * eintragen (Design › Betreiberangaben) – sie ersetzen beim Übernehmen der Rechtstext-Vorlagen
 * (inc/legal.php) die passenden [[Betreiber: …]]-Platzhalter in Impressum, Datenschutz, AGB, Widerruf
 * und Kontakt und beim Abgleich der Inhaltsseiten (inc/content-pages.php) dieselben Platzhalter dort,
 * z. B. Garantiegeber, Erstattungsfrist, Support-E-Mail.
 *
 * Ersetzt wird nur, wofür ein Wert eingetragen ist; alles andere bleibt als markierter Platzhalter stehen.
 * Platzhalter, die eine Entscheidung oder Prüfung verlangen (Varianten wählen, Abschnitt löschen, „prüfen“),
 * ersetzt das Theme nie – das bleibt Sache des Betreibers bzw. der Rechtsberatung. Keine Rechtsberatung.
 *
 * Geänderte Angaben gelten wie eine geänderte Vorlage: Entwürfe unbearbeiteter Rechtsseiten werden
 * automatisch nachgezogen, veröffentlichte unbearbeitete Seiten bekommen den Hinweis „Vorlagen übernehmen“,
 * vom Betreiber bearbeitete Seiten bleiben unberührt (kurswasser_fill_legal_pages()).
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const KURSWASSER_OPERATOR_OPTION = 'kurswasser_operator';

/**
 * Felder der Betreiberangaben: Schlüssel => [Beschriftung, Hilfetext, Art].
 * @return array<string,array{0:string,1:string,2:string}>
 */
function kurswasser_operator_fields(): array
{
    return [
        'firma' => [__('Name bzw. Firma', 'kurswasser'), __('wie im Register bzw. Vor- und Nachname bei Einzelunternehmen', 'kurswasser'), 'text'],
        'rechtsform' => [__('Rechtsform', 'kurswasser'), __('z. B. Einzelunternehmen, GmbH, UG (haftungsbeschränkt)', 'kurswasser'), 'text'],
        'vertretung' => [__('Vertretungsberechtigte Person(en)', 'kurswasser'), __('z. B. Geschäftsführer/in; bei Einzelunternehmen leer lassen', 'kurswasser'), 'text'],
        'strasse' => [__('Straße und Hausnummer', 'kurswasser'), '', 'text'],
        'plz_ort' => [__('PLZ und Ort', 'kurswasser'), '', 'text'],
        'land' => [__('Land', 'kurswasser'), '', 'text'],
        'email' => [__('E-Mail (allgemein, Impressum, Widerruf)', 'kurswasser'), '', 'email'],
        'telefon' => [__('Telefon', 'kurswasser'), '', 'text'],
        'support_email' => [__('Support-E-Mail', 'kurswasser'), __('für die Kontaktseite', 'kurswasser'), 'email'],
        'privacy_email' => [__('E-Mail für Datenschutzanfragen', 'kurswasser'), '', 'email'],
        'erreichbarkeit' => [__('Telefonische Erreichbarkeit', 'kurswasser'), __('z. B. Mo–Fr 10–16 Uhr – nur, was eingehalten wird', 'kurswasser'), 'text'],
        'register' => [__('Registergericht und Registernummer', 'kurswasser'), __('z. B. Amtsgericht Musterstadt, HRB 12345; ohne Eintragung leer lassen', 'kurswasser'), 'text'],
        'ust_id' => [__('USt-IdNr.', 'kurswasser'), __('leer lassen, wenn nicht vorhanden', 'kurswasser'), 'text'],
        'wirtschafts_id' => [__('Wirtschafts-IdNr.', 'kurswasser'), __('nur, falls erteilt', 'kurswasser'), 'text'],
        'verantwortlich' => [__('Verantwortlich für den Inhalt (Vor- und Nachname)', 'kurswasser'), __('§ 18 Abs. 2 MStV', 'kurswasser'), 'text'],
        'verantwortlich_anschrift' => [__('Anschrift der verantwortlichen Person', 'kurswasser'), '', 'text'],
        'schlichtung' => [__('Verbraucherschlichtungsstelle', 'kurswasser'), __('Name, Anschrift und Website – nur nach rechtlicher Prüfung', 'kurswasser'), 'text'],
        'aufsicht' => [__('Datenschutz-Aufsichtsbehörde', 'kurswasser'), __('Name und Anschrift der zuständigen Behörde', 'kurswasser'), 'text'],
        'hoster' => [__('Hoster (Name)', 'kurswasser'), '', 'text'],
        'hoster_anschrift' => [__('Hoster (Anschrift)', 'kurswasser'), '', 'text'],
        'mailversand' => [__('E-Mail-Versand (Dienst)', 'kurswasser'), __('z. B. Mailserver des Hosters', 'kurswasser'), 'text'],
        'bank' => [__('Bank (für Überweisungen)', 'kurswasser'), __('Name der Bank, wie in der Datenschutzerklärung genannt', 'kurswasser'), 'text'],
        'steuerberatung' => [__('Steuerberatung bzw. Buchhaltungsdienst', 'kurswasser'), __('leer lassen, wenn keine', 'kurswasser'), 'text'],
        'logfiles' => [__('Speicherdauer der Server-Logfiles', 'kurswasser'), __('z. B. 7 Tage – beim Hoster nachfragen', 'kurswasser'), 'text'],
        'frist_kontakt' => [__('Löschfrist Kontaktanfragen', 'kurswasser'), __('z. B. 6 Monate', 'kurswasser'), 'text'],
        'frist_bewerbung' => [__('Löschfrist Bewerbungen', 'kurswasser'), __('z. B. 6 Monate nach Abschluss des Verfahrens', 'kurswasser'), 'text'],
        'bewerbungsweg' => [__('Bewerbungsweg', 'kurswasser'), __('z. B. E-Mail-Adresse oder Formular mit verschlüsselter Übermittlung', 'kurswasser'), 'text'],
        'stand_agb' => [__('Stand der AGB', 'kurswasser'), __('Datum der Veröffentlichung, z. B. 1. Dezember 2026', 'kurswasser'), 'text'],
        'erstattungsfrist' => [__('Erstattungsfrist der Geld-zurück-Garantie', 'kurswasser'), __('z. B. 14 Tage – spätestens so lange nach Eingang der Anfrage wird erstattet (AGB Nr. 9, Garantie-Seite)', 'kurswasser'), 'text'],
    ];
}

/** @return array<string,string> */
function kurswasser_operator_data(): array
{
    $stored = get_option(KURSWASSER_OPERATOR_OPTION, []);
    $out = [];
    foreach (array_keys(kurswasser_operator_fields()) as $key) {
        $out[$key] = is_array($stored) ? trim((string) ($stored[$key] ?? '')) : '';
    }

    return $out;
}

/**
 * Platzhalter-Text (ohne „[[Betreiber: “ und „]]“, genau wie in den Vorlagen) => Wert aus den Angaben.
 * Nur reine Angaben; Platzhalter mit Entscheidung bzw. Prüfung stehen hier bewusst nicht.
 * @return array<string,string>
 */
function kurswasser_operator_replacements(?array $data = null): array
{
    $d = $data ?? kurswasser_operator_data();
    $address = implode(', ', array_filter([$d['strasse'], $d['plz_ort'], $d['land']], static fn ($v) => $v !== ''));
    $hosterFull = implode(', ', array_filter([$d['hoster'], $d['hoster_anschrift']], static fn ($v) => $v !== ''));
    $map = [
        'Firmenname' => $d['firma'],
        'Name bzw. Firma' => $d['firma'],
        'Markenname' => function_exists('kurswasser_brand') ? kurswasser_brand() : (string) get_bloginfo('name'),
        'Rechtsform, z. B. Einzelunternehmen, GmbH, UG (haftungsbeschränkt)' => $d['rechtsform'],
        'Vertretungsberechtigte Person' => $d['vertretung'],
        'Vertretungsberechtigte Person(en), z. B. Geschäftsführer/in – bei Einzelunternehmen diesen Abschnitt löschen' => $d['vertretung'],
        // Nur vollständige Anschrift (Straße und Ort) – sonst bliebe ein halber Wert im Rechtstext.
        'Anschrift' => $d['strasse'] !== '' && $d['plz_ort'] !== '' ? $address : '',
        'Anschrift – Straße und Hausnummer' => $d['strasse'],
        'Anschrift – PLZ und Ort' => $d['plz_ort'],
        'Anschrift – Land' => $d['land'],
        'E-Mail' => $d['email'],
        'Telefon' => $d['telefon'],
        'Support-E-Mail' => $d['support_email'],
        'E-Mail für Datenschutzanfragen' => $d['privacy_email'],
        'telefonische Erreichbarkeit, z. B. Mo–Fr 10–16 Uhr' => $d['erreichbarkeit'],
        'Registergericht/Registernummer' => $d['register'],
        'Registergericht/Registernummer, z. B. Amtsgericht Musterstadt, HRB 12345 – ohne Eintragung im Handels-, Vereins- oder Partnerschaftsregister diesen Abschnitt löschen' => $d['register'],
        'USt-IdNr.' => $d['ust_id'],
        'Wirtschafts-IdNr. – nur angeben, falls erteilt' => $d['wirtschafts_id'],
        'Vor- und Nachname der verantwortlichen Person' => $d['verantwortlich'],
        'Anschrift der verantwortlichen Person' => $d['verantwortlich_anschrift'],
        'Name, Anschrift und Website der Verbraucherschlichtungsstelle' => $d['schlichtung'],
        'Name, Anschrift und Website der zuständigen Verbraucherschlichtungsstelle' => $d['schlichtung'],
        'zuständige Aufsichtsbehörde mit Anschrift' => $d['aufsicht'],
        'Hoster/Auftragsverarbeiter' => $d['hoster'],
        'Hoster/Auftragsverarbeiter, Anschrift' => $d['hoster_anschrift'] !== '' ? $hosterFull : '',
        'E-Mail-Versand' => $d['mailversand'],
        'E-Mail-Versand, z. B. Mailserver des Hosters' => $d['mailversand'],
        'Bank' => $d['bank'],
        'Steuerberater/Buchhaltungsdienst, falls vorhanden' => $d['steuerberatung'],
        'Speicherdauer der Logfiles, z. B. 7 Tage' => $d['logfiles'],
        'Frist, z. B. 6 Monate' => $d['frist_kontakt'],
        'Frist, üblich etwa 6 Monate nach Abschluss des Verfahrens' => $d['frist_bewerbung'],
        'Bewerbungsweg, z. B. E-Mail-Adresse oder Formular mit verschlüsselter Übermittlung' => $d['bewerbungsweg'],
        'Datum der Veröffentlichung' => $d['stand_agb'],
        // Garantie (AGB Nr. 9, Inhaltsseite „Geld-zurück-Garantie“): Garantiegeber = Anbieter.
        'Erstattungsfrist' => $d['erstattungsfrist'],
        'E-Mail-Adresse' => $d['email'],
        'Firma, Anschrift, E-Mail' => $d['firma'] !== '' && $d['strasse'] !== '' && $d['plz_ort'] !== '' && $d['email'] !== '' ? $d['firma'].', '.$address.', '.$d['email'] : '',
    ];

    return array_filter($map, static fn ($v) => trim((string) $v) !== '');
}

/**
 * Garantiegeber für Bestellmail und „Mein Lernen“ (Lern-Plugin, Filter bootspruefung_guarantor; To-do T52): Anbieter
 * mit Anschrift und E-Mail aus den Betreiberangaben. Ohne vollständige Angaben bleibt der Standard des Plugins.
 */
add_filter('bootspruefung_guarantor', static function ($default) {
    $d = kurswasser_operator_data();
    if ($d['firma'] === '' || $d['strasse'] === '' || $d['plz_ort'] === '') {
        return $default;
    }
    $parts = array_filter([$d['firma'], $d['strasse'], $d['plz_ort'], $d['land'], $d['email']], static fn ($v) => $v !== '');

    return implode(', ', $parts);
});

/**
 * Fuß der Plugin-Mails (Lern-Plugin, Filter bootspruefung_mail_footer; To-do T58): Anbieter-Angaben aus den
 * Betreiberangaben – Name bzw. Firma, Anschrift, vertretungsberechtigte Person und Register (nur, wenn eingetragen),
 * E-Mail. Ohne Firma, Straße und Ort bleibt der Standard des Plugins (Website-Name, Shop-Adresse, Absender).
 */
function kurswasser_operator_mail_footer(?array $data = null): string
{
    $d = $data ?? kurswasser_operator_data();
    if ($d['firma'] === '' || $d['strasse'] === '' || $d['plz_ort'] === '') {
        return '';
    }
    $lines = [$d['firma'], implode(', ', array_filter([$d['strasse'], $d['plz_ort'], $d['land']], static fn ($v) => $v !== ''))];
    if ($d['vertretung'] !== '') {
        /* translators: %s: Name(n) */
        $lines[] = sprintf(__('Vertreten durch: %s', 'kurswasser'), $d['vertretung']);
    }
    if ($d['register'] !== '') {
        /* translators: %s: Registergericht und -nummer */
        $lines[] = sprintf(__('Registereintrag: %s', 'kurswasser'), $d['register']);
    }
    $email = $d['email'] !== '' ? $d['email'] : $d['support_email'];
    if ($email !== '') {
        /* translators: %s: E-Mail-Adresse */
        $lines[] = sprintf(__('E-Mail: %s', 'kurswasser'), $email);
    }

    return implode("\n", $lines);
}

add_filter('bootspruefung_mail_footer', static function ($default) {
    $own = kurswasser_operator_mail_footer();

    return $own !== '' ? $own : $default;
});

/** Platzhalter einer Vorlage mit den eingetragenen Angaben ersetzen (Werte HTML-maskiert). */
function kurswasser_operator_fill(string $html, ?array $data = null): string
{
    $map = kurswasser_operator_replacements($data);
    if ($map === [] || (! str_contains($html, '[[Betreiber:') && ! str_contains($html, '&#91;&#91;Betreiber:'))) {
        return $html;
    }
    // In Shortcode-Attributen (z. B. Hinweis im Seitenkopf) stehen die Klammern maskiert als &#91;&#91; … &#93;&#93;.
    $html = (string) preg_replace_callback('/&#91;&#91;Betreiber:\s*(.+?)\s*&#93;&#93;/u', static function (array $m) use ($map): string {
        $key = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return isset($map[$key]) ? esc_html($map[$key]) : $m[0];
    }, $html);

    return (string) preg_replace_callback('/\[\[Betreiber:\s*([^\]]+?)\s*\]\]/u', static function (array $m) use ($map): string {
        // Vorlagen enthalten „&amp;“ in Platzhaltern (z. B. „Tipps &amp; Tricks“) – für den Vergleich entschlüsseln.
        $key = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return isset($map[$key]) ? esc_html($map[$key]) : $m[0];
    }, $html);
}

/** Prüfsumme der Angaben (für den Abgleich der Rechtsseiten). */
function kurswasser_operator_hash(): string
{
    return md5((string) wp_json_encode(kurswasser_operator_data()));
}

/**
 * Noch offene Platzhalter je Rechtstext-Vorlage nach dem Einsetzen der Angaben.
 * @return array<string,list<string>> Slug => Platzhalter-Texte
 */
function kurswasser_operator_open_placeholders(): array
{
    $out = [];
    foreach (array_keys(kurswasser_legal_pages()) as $slug) {
        $html = kurswasser_legal_content($slug);
        if ($html === null) {
            continue;
        }
        preg_match_all('/\[\[Betreiber:\s*([^\]]+?)\s*\]\]/u', kurswasser_operator_fill($html), $m);
        $out[$slug] = array_values(array_map(static fn ($t) => html_entity_decode((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]));
    }

    return $out;
}

/** Angaben bereinigen (Text bzw. E-Mail, höchstens 300 Zeichen). @return array<string,string> */
function kurswasser_operator_sanitize(array $input): array
{
    $out = [];
    foreach (kurswasser_operator_fields() as $key => [, , $type]) {
        $raw = trim(wp_unslash((string) ($input[$key] ?? '')));
        $value = $type === 'email' ? (string) sanitize_email($raw) : sanitize_text_field($raw);
        $out[$key] = mb_substr($value, 0, 300);
    }

    return $out;
}

/**
 * E-Mail-Felder mit Inhalt, der keine gültige Adresse ist (T126).
 * @return list<string> Feldschlüssel
 */
function kurswasser_operator_invalid(array $input): array
{
    $out = [];
    foreach (kurswasser_operator_fields() as $key => [, , $type]) {
        $raw = trim(wp_unslash((string) ($input[$key] ?? '')));
        if ($type === 'email' && $raw !== '' && ! is_email($raw)) {
            $out[] = $key;
        }
    }

    return $out;
}

add_action('admin_menu', static function (): void {
    add_theme_page(__('Betreiberangaben', 'kurswasser'), __('Betreiberangaben', 'kurswasser'), 'manage_options', 'kurswasser-operator', 'kurswasser_operator_page');
});

add_action('admin_post_kurswasser_operator_save', static function (): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), 403);
    }
    check_admin_referer('kurswasser_operator_save');
    $input = (array) ($_POST['kw_operator'] ?? []);
    // T126: ungültige E-Mail-Adresse nicht still leeren – nichts speichern, Eingaben behalten, Fehler zeigen.
    $invalid = kurswasser_operator_invalid($input);
    if ($invalid !== []) {
        $keep = [];
        foreach (array_keys(kurswasser_operator_fields()) as $key) {
            $keep[$key] = mb_substr(sanitize_text_field(trim(wp_unslash((string) ($input[$key] ?? '')))), 0, 300);
        }
        set_transient('kurswasser_operator_input_'.get_current_user_id(), ['values' => $keep, 'invalid' => $invalid], 300);
        wp_safe_redirect(add_query_arg(['page' => 'kurswasser-operator', 'error' => '1'], admin_url('themes.php')));
        exit;
    }
    update_option(KURSWASSER_OPERATOR_OPTION, kurswasser_operator_sanitize($input), false);
    // Wie eine geänderte Vorlage: unbearbeitete Entwürfe nachziehen, veröffentlichte melden.
    if (function_exists('kurswasser_legal_sync')) {
        kurswasser_legal_sync(true);
    }
    wp_safe_redirect(add_query_arg(['page' => 'kurswasser-operator', 'saved' => '1'], admin_url('themes.php')));
    exit;
});

function kurswasser_operator_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }
    $data = kurswasser_operator_data();
    $fields = kurswasser_operator_fields();
    $kept = get_transient('kurswasser_operator_input_'.get_current_user_id());
    delete_transient('kurswasser_operator_input_'.get_current_user_id());
    $invalid = [];
    if (is_array($kept) && is_array($kept['values'] ?? null)) {
        $data = array_merge($data, array_intersect_key(array_map('strval', $kept['values']), $data));
        $invalid = array_values(array_intersect(array_map('strval', (array) ($kept['invalid'] ?? [])), array_keys($fields)));
    }
    echo '<div class="wrap"><h1>'.esc_html__('Betreiberangaben', 'kurswasser').'</h1>';
    if ($invalid !== []) {
        echo '<div class="notice notice-error"><p><strong>'.esc_html__('Nicht gespeichert.', 'kurswasser').'</strong> '.esc_html(sprintf(
            /* translators: %s: Feldnamen */
            _n('Bitte eine gültige E-Mail-Adresse eintragen: %s.', 'Bitte gültige E-Mail-Adressen eintragen: %s.', count($invalid), 'kurswasser'),
            implode(', ', array_map(static fn ($k) => (string) $fields[$k][0], $invalid))
        )).' '.esc_html__('Deine Eingaben stehen noch im Formular.', 'kurswasser').'</p></div>';
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige nach Weiterleitung
    if (isset($_GET['saved']) && $invalid === []) {
        echo '<div class="notice notice-success"><p>'.esc_html__('Gespeichert. Unbearbeitete Entwürfe der Rechtsseiten sind aktualisiert; veröffentlichte Seiten übernimmst du über den Hinweis „Vorlagen übernehmen“.', 'kurswasser').'</p></div>';
    }
    echo '<p>'.esc_html__('Diese Angaben ersetzen die passenden [[Betreiber: …]]-Platzhalter in Impressum, Datenschutzerklärung, AGB, Widerrufsbelehrung und Kontakt. Leere Felder bleiben als Platzhalter stehen. Platzhalter, die eine Entscheidung oder rechtliche Prüfung verlangen, ersetzt du direkt auf der jeweiligen Seite. Keine Rechtsberatung – die Texte vor der Veröffentlichung rechtlich prüfen lassen.', 'kurswasser').'</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kurswasser_operator_save">';
    wp_nonce_field('kurswasser_operator_save');
    echo '<table class="form-table" role="presentation">';
    foreach ($fields as $key => [$label, $help, $type]) {
        $bad = in_array($key, $invalid, true);
        printf(
            '<tr><th scope="row"><label for="kw-op-%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="kw-op-%1$s" name="kw_operator[%1$s]" value="%4$s" maxlength="300"%6$s>%5$s</td></tr>',
            esc_attr($key),
            esc_html($label),
            $type === 'email' ? 'email' : 'text',
            esc_attr($data[$key]),
            ($bad ? '<p class="description" id="kw-op-'.esc_attr($key).'-error" style="color:#b32d2e">'.esc_html__('Keine gültige E-Mail-Adresse.', 'kurswasser').'</p>' : '')
                .($help !== '' ? '<p class="description">'.esc_html($help).'</p>' : ''),
            $bad ? ' aria-invalid="true" aria-describedby="kw-op-'.esc_attr($key).'-error"' : ''
        );
    }
    echo '</table>';
    submit_button(__('Speichern und Rechtsseiten abgleichen', 'kurswasser'));
    echo '</form>';
    $open = kurswasser_operator_open_placeholders();
    $pages = kurswasser_legal_pages();
    echo '<h2>'.esc_html__('Noch offen in den Vorlagen', 'kurswasser').'</h2>';
    foreach ($open as $slug => $list) {
        printf('<h3>%s (%d)</h3>', esc_html($pages[$slug] ?? $slug), count($list));
        if ($list !== []) {
            echo '<ul style="list-style:disc;padding-left:1.5em">';
            foreach ($list as $item) {
                echo '<li>'.esc_html($item).'</li>';
            }
            echo '</ul>';
        }
    }
    echo '</div>';
}
