<?php
/**
 * Versionsabgleich mit dem Lern-Plugin „Bootsprüfung Lernplattform“.
 *
 * Das Theme nutzt Klassen, Konstanten und Methoden des Plugins (Katalog, Preise, Prüfungsanmeldung).
 * Statt nur class_exists() zu prüfen, fragt es den Versionsvertrag des Plugins ab
 * (bootspruefung_learning_contract()). Ist das Plugin zu alt oder inkompatibel, arbeitet das Theme wie
 * ohne Plugin weiter (kein Fatal Error) und zeigt im Admin einen Hinweis.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Mindestversion des Lern-Plugins (VIDEO_TIERS, komplettSku(), SIM_PASSES, courseStats(), ExamGuideService::payload()). */
const KURSWASSER_LEARNING_MIN_VERSION = '0.13.0';

/** Erwartete Vertragsversion der Plugin-API. */
const KURSWASSER_LEARNING_CONTRACT = 1;

/** Ist das Lern-Plugin aktiv und mit diesem Theme kompatibel? */
function kurswasser_learning_compatible(): bool
{
    if (function_exists('bootspruefung_learning_compatible')) {
        return (bool) bootspruefung_learning_compatible(KURSWASSER_LEARNING_MIN_VERSION, KURSWASSER_LEARNING_CONTRACT);
    }
    // Ältere Plugin-Versionen ohne Vertragsfunktion: nur die Versionsnummer zählt.
    $version = kurswasser_learning_version();

    return $version !== null && version_compare($version, KURSWASSER_LEARNING_MIN_VERSION, '>=');
}

/**
 * Adresse im Lernbereich (T105), z. B. kurswasser_learning_url('tipps'). Nutzt den Router des Plugins (eigener Pfad
 * über den Filter bootspruefung_base_path, WordPress im Unterordner); ohne Plugin der Standardpfad /bootsscheine/.
 */
function kurswasser_learning_url(string $path = ''): string
{
    $path = trim($path, '/');
    if (class_exists('\Bootspruefung\Learning\Frontend\Router') && method_exists('\Bootspruefung\Learning\Frontend\Router', 'url')) {
        return (string) \Bootspruefung\Learning\Frontend\Router::url($path);
    }

    return home_url('/bootsscheine/'.($path === '' ? '' : $path.'/'));
}

/** Version des aktiven Lern-Plugins oder null (nicht aktiv). */
function kurswasser_learning_version(): ?string
{
    if (function_exists('bootspruefung_learning_contract')) {
        return (string) (bootspruefung_learning_contract()['version'] ?? '');
    }
    if (defined('BP_LEARNING_VERSION')) {
        return (string) constant('BP_LEARNING_VERSION');
    }
    if (class_exists('\Bootspruefung\Learning\Plugin') && defined('\Bootspruefung\Learning\Plugin::VERSION')) {
        return (string) constant('\Bootspruefung\Learning\Plugin::VERSION');
    }

    return null;
}

/** Hinweis im Admin, wenn das Plugin aktiv, aber zu alt bzw. inkompatibel ist. */
function kurswasser_learning_notice(): void
{
    $version = kurswasser_learning_version();
    if ($version === null || kurswasser_learning_compatible() || ! current_user_can('update_plugins')) {
        return;
    }
    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html(sprintf(
            /* translators: 1: benötigte Version, 2: vorhandene Version */
            __('Kurswasser braucht das Plugin „Bootsprüfung Lernplattform“ ab Version %1$s (vorhanden: %2$s). Bis zum Update zeigt das Theme Kurse, Preise und Prüfungsanmeldung ohne Plugin-Daten an.', 'kurswasser'),
            KURSWASSER_LEARNING_MIN_VERSION,
            $version
        ))
    );
}
add_action('admin_notices', 'kurswasser_learning_notice');
