<?php
/**
 * Öffentliche Funktionen des Plugins für Themes.
 *
 * @package Bootspruefung\Learning
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('bootspruefung_faqs')) {
    /**
     * Veröffentlichte FAQs, sortiert nach Rubrik, Unterkategorie und Reihenfolge (menu_order).
     *
     * Argumente: rubric (Schlüssel, z. B. „allgemein“, „SBF-SEE“, „SRC-UBI“), theme_only (nur ★),
     * subcategory (Slug, z. B. „sbf-see-lernen“). Platzhalter wie {{agb}} sind ersetzt, die Antwort
     * ist per wp_kses_post gefiltert.
     *
     * @param array{rubric?:string,theme_only?:bool,subcategory?:string} $args
     * @return list<array{id:int,ref:?int,question:string,answer_html:string,rubric:string,rubric_label:string,subcategory:string,subcategory_label:string,theme:bool}>
     */
    function bootspruefung_faqs(array $args = []): array
    {
        if (! post_type_exists(\Bootspruefung\Learning\Faq\Faq::POST_TYPE)) {
            return []; // vor „init“
        }

        return \Bootspruefung\Learning\Faq\FaqRepository::query($args);
    }
}
