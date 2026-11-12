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

if (! function_exists('bootspruefung_learning_contract')) {
    /**
     * Versionsvertrag für Theme und Add-ons: Plugin-Version und Vertragsversion der öffentlichen
     * PHP-API (Klassen, Methoden, Katalogschlüssel, die Theme Kurswasser und der Partnerprogramm-
     * Connector nutzen). Die Vertragsversion steigt nur bei inkompatiblen Änderungen.
     *
     * Nutzer prüfen damit vor dem ersten Aufruf statt nur mit class_exists() und schalten bei einem
     * älteren oder inkompatiblen Plugin kontrolliert ab (kein Fatal Error).
     *
     * @return array{version:string,contract:int}
     */
    function bootspruefung_learning_contract(): array
    {
        $contract = ['version' => \Bootspruefung\Learning\Plugin::VERSION, 'contract' => \Bootspruefung\Learning\Plugin::API_CONTRACT];
        /** Filter: Vertrag ändern (nur für Tests, z. B. ein älteres Plugin nachstellen). */
        $filtered = apply_filters('bootspruefung_learning_contract', $contract);

        return is_array($filtered) ? ['version' => (string) ($filtered['version'] ?? $contract['version']), 'contract' => (int) ($filtered['contract'] ?? $contract['contract'])] : $contract;
    }
}

if (! function_exists('bootspruefung_learning_compatible')) {
    /**
     * Erfüllt das Plugin die Mindestversion und den Vertrag eines Nutzers?
     *
     * @param string $minVersion Mindestversion des Plugins (z. B. „0.13.0“)
     * @param int    $contract   erwartete Vertragsversion
     */
    function bootspruefung_learning_compatible(string $minVersion, int $contract = 1): bool
    {
        $c = bootspruefung_learning_contract();

        return $c['contract'] === $contract && version_compare($c['version'], $minVersion, '>=');
    }
}
