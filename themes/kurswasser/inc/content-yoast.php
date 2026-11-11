<?php
/**
 * Inhaltsseiten: SEO-Daten zusätzlich in die Felder von Yoast SEO schreiben (Option B der Einrichtung).
 *
 * Wohin die Synchronisation (inc/content-pages.php) SEO-Daten schreibt, steht in der Option
 * kurswasser_content_seo_target: „theme“ (Option A: nur die eigenen _kw_-Felder, Ausgabe durch inc/seo.php)
 * oder „yoast“ (Option B: zusätzlich die Yoast-Felder jeder Seite). Die _kw_-Felder setzt die Synchronisation
 * in beiden Fällen, damit ein späterer Wechsel zurück zum Theme funktioniert.
 *
 * Yoast-Felder je Seite:
 *   _yoast_wpseo_title                  seo_title, {{brand}} → %%sitename%% (fehlt die Marke und passt sie noch:
 *                                       „ %%sep%% %%sitename%%“ angehängt, wie inc/seo.php)
 *   _yoast_wpseo_metadesc               meta_description, {{brand}} → %%sitename%%
 *   _yoast_wpseo_meta-robots-noindex    „1“ bei index: noindex, sonst nicht gesetzt
 *   _yoast_wpseo_canonical              absolute URL (Slug/Pfad aus dem Manifest; Ziel muss veröffentlicht sein)
 *   _yoast_wpseo_schema_article_type    „Article“ bei schema: Article
 *   _yoast_wpseo_schema_page_type       AboutPage/ContactPage; FAQPage bewusst nicht (siehe unten)
 *   _yoast_wpseo_focuskw                nur aus dem Manifest-Feld focus_keyword – nie erfunden
 *
 * Überschreib-Regeln je Feld (Merker _kw_yoast_written = zuletzt vom Theme geschriebene Werte):
 *   - Feld leer und vom Theme noch nie beschrieben → wird gesetzt.
 *   - Feld enthält noch genau den zuletzt vom Theme geschriebenen Wert → wird auf den neuen Stand gesetzt.
 *   - Sonst (vom Betreiber ausgefüllt, geändert oder bewusst geleert) → bleibt, wie es ist.
 *   - force („Alles aus dem Theme übernehmen“, WP-CLI --force) überschreibt auch diese Felder.
 *
 * FAQ-Seite: Der Shortcode [bootspruefung_faq] gibt selbst JSON-LD „FAQPage“ mit den Fragen aus. Yoast macht
 * mit Seitentyp FAQPage nur seinen WebPage-Knoten zu „FAQPage“ (Fragen kämen nur aus Yoast-FAQ-Blöcken) –
 * zusammen gäbe das zwei FAQPage-Objekte, eines ohne Fragen. Deshalb bleibt der Seitentyp dort WebPage.
 *
 * Neuaufbau bei Yoast: Yoast liest Titel, Beschreibung, Robots und Canonical im Frontend und für die Sitemap
 * aus seinen „Indexables“. Nach dem Schreiben baut kurswasser_content_yoast_refresh() das Indexable der Seite
 * über Yoasts eigenen Watcher neu (Indexable_Post_Watcher::build_indexable, derselbe Weg wie beim Speichern
 * im Editor) und leert den Sitemap-Cache der Seite (WPSEO_Sitemaps_Cache::invalidate_post). Unabhängig davon
 * erkennt Yoasts Indexable_Post_Meta_Watcher Änderungen an _yoast_wpseo_-Feldern und baut am Ende der Anfrage
 * selbst neu. Notfalls: Yoast SEO › Werkzeuge › „SEO-Datenoptimierung“ bzw. `wp yoast index --reindex`.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Ist Yoast SEO aktiv? (Filter für Tests.) */
function kurswasser_yoast_active(): bool
{
    return (bool) apply_filters('kurswasser_yoast_active', defined('WPSEO_VERSION'));
}

/**
 * Gewähltes Ziel der SEO-Daten: „yoast“ nur, wenn so gewählt und Yoast aktiv ist; sonst „theme“.
 * kurswasser_content_seo_choice() liefert die gespeicherte Wahl selbst ('' = noch nicht gewählt).
 */
function kurswasser_content_seo_target(): string
{
    return kurswasser_content_seo_choice() === 'yoast' && kurswasser_yoast_active() ? 'yoast' : 'theme';
}

function kurswasser_content_seo_choice(): string
{
    $choice = (string) get_option('kurswasser_content_seo_target', '');

    return in_array($choice, ['theme', 'yoast'], true) ? $choice : '';
}

/** Felder, die das Theme in Yoast schreibt (Reihenfolge = Anzeige). */
function kurswasser_content_yoast_fields(): array
{
    return [
        '_yoast_wpseo_title' => __('SEO-Titel', 'kurswasser'),
        '_yoast_wpseo_metadesc' => __('Meta-Beschreibung', 'kurswasser'),
        '_yoast_wpseo_meta-robots-noindex' => __('noindex', 'kurswasser'),
        '_yoast_wpseo_canonical' => __('Canonical', 'kurswasser'),
        '_yoast_wpseo_schema_article_type' => __('Artikeltyp', 'kurswasser'),
        '_yoast_wpseo_schema_page_type' => __('Seitentyp', 'kurswasser'),
        '_yoast_wpseo_focuskw' => __('Fokus-Keyword', 'kurswasser'),
    ];
}

/** {{brand}} → %%sitename%% (Yoast ersetzt die Variable selbst, auch nach Umbenennen der Website). */
function kurswasser_content_yoast_vars(string $text): string
{
    return trim(kurswasser_content_tokens(str_replace('{{brand}}', '%%sitename%%', $text)));
}

/** Canonical als absolute URL oder '' (keins bzw. Ziel noch nicht veröffentlicht – dann Rückfall in inc/seo.php). */
function kurswasser_content_yoast_canonical(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value)) {
        return esc_url_raw($value);
    }
    $path = trim($value, '/');
    if ($path === '') {
        return home_url('/');
    }
    $page = get_page_by_path($path, OBJECT, 'page');
    if ($page instanceof WP_Post) {
        return $page->post_status === 'publish' ? (string) get_permalink($page) : '';
    }

    return home_url('/'.$path.'/');
}

/**
 * Soll-Werte der Yoast-Felder für einen Manifest-Eintrag ('' = Feld nicht gesetzt).
 *
 * @return array<string,string>
 */
function kurswasser_content_yoast_values(array $entry): array
{
    $title = (string) ($entry['seo_title'] ?? '');
    $yoastTitle = kurswasser_content_yoast_vars($title);
    if ($yoastTitle !== '' && ! str_contains($title, '{{brand}}')) {
        // Wie kurswasser_seo_title(): Marke anhängen, wenn sie fehlt und der Titel dann noch ≤ 60 Zeichen hat.
        $brand = (string) get_bloginfo('name');
        if ($brand !== '' && ! str_contains($yoastTitle, $brand) && mb_strlen($yoastTitle.' | '.$brand) <= 60) {
            $yoastTitle .= ' %%sep%% %%sitename%%';
        }
    }
    $schema = (string) ($entry['schema'] ?? '');

    return (array) apply_filters('kurswasser_content_yoast_values', [
        '_yoast_wpseo_title' => $yoastTitle,
        '_yoast_wpseo_metadesc' => kurswasser_content_yoast_vars((string) ($entry['meta_description'] ?? '')),
        '_yoast_wpseo_meta-robots-noindex' => ($entry['index'] ?? 'index') === 'noindex' ? '1' : '',
        '_yoast_wpseo_canonical' => kurswasser_content_yoast_canonical((string) ($entry['canonical'] ?? '')),
        '_yoast_wpseo_schema_article_type' => $schema === 'Article' ? 'Article' : '',
        '_yoast_wpseo_schema_page_type' => in_array($schema, ['AboutPage', 'ContactPage'], true) ? $schema : '',
        '_yoast_wpseo_focuskw' => trim((string) ($entry['focus_keyword'] ?? '')),
    ], $entry);
}

/**
 * Yoast-Felder einer Seite nach den Überschreib-Regeln setzen (bzw. im Probelauf nur ermitteln).
 *
 * @return array{written:list<string>,kept:list<string>,dry:bool}
 */
function kurswasser_content_yoast_store(int $id, array $entry, bool $force = false, bool $dry = false): array
{
    $last = $id ? get_post_meta($id, '_kw_yoast_written', true) : [];
    $last = is_array($last) ? $last : [];
    $record = $last;
    $written = [];
    $kept = [];
    foreach (kurswasser_content_yoast_values($entry) as $key => $value) {
        $value = (string) $value;
        $current = $id ? (string) get_post_meta($id, $key, true) : '';
        $mine = array_key_exists($key, $last);
        if ($current === $value) {
            if ($value !== '' || $mine) {
                $record[$key] = $value;
            }
            continue;
        }
        $free = $force || ($mine ? $current === (string) $last[$key] : $current === '');
        if (! $free) {
            $kept[] = $key;
            continue;
        }
        if (! $dry) {
            $value === '' ? delete_post_meta($id, $key) : update_post_meta($id, $key, wp_slash($value));
        }
        $written[] = $key;
        $record[$key] = $value;
    }
    if (! $dry && $id && $record !== $last) {
        update_post_meta($id, '_kw_yoast_written', wp_slash($record));
    }
    if (! $dry && $id && $written) {
        kurswasser_content_yoast_refresh($id);
    }

    return ['written' => $written, 'kept' => $kept, 'dry' => $dry];
}

/**
 * Yoast nach geänderten Feldern aktualisieren: Indexable neu bauen, Sitemap-Cache der Seite leeren.
 * Gibt zurück, ob das Indexable direkt neu gebaut wurde.
 */
function kurswasser_content_yoast_refresh(int $id): bool
{
    $built = false;
    if (function_exists('YoastSEO')) {
        try {
            $watcher = YoastSEO()->classes->get('Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Post_Watcher');
            if (is_object($watcher) && method_exists($watcher, 'build_indexable')) {
                $watcher->build_indexable($id);
                $built = true;
            }
        } catch (\Throwable $e) {
            $built = false; // Andere Yoast-Version: Yoasts Meta-Watcher baut am Ende der Anfrage selbst neu.
        }
    }
    if (class_exists('WPSEO_Sitemaps_Cache') && method_exists('WPSEO_Sitemaps_Cache', 'invalidate_post')) {
        WPSEO_Sitemaps_Cache::invalidate_post($id);
    }
    do_action('kurswasser_content_yoast_refreshed', $id, $built);

    return $built;
}
