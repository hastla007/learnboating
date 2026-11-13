<?php
/**
 * Hülle des Lernbereichs im aktiven Theme (Block- und klassische Themes).
 *
 * @package Bootspruefung\Learning
 */

defined('ABSPATH') || exit;

$bp_content = static function (): void {
    if (\Bootspruefung\Learning\Commerce\ReminderOptOut::isRequest()) {
        // Abmeldelink der Ablauf-Erinnerung: ohne Anmeldung und ohne App-Skript.
        echo '<div class="bp-app">'.\Bootspruefung\Learning\Commerce\ReminderOptOut::page().'</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- in page() maskiert

        return;
    }
    echo '<div id="bp-app" class="bp-app" aria-live="polite">';
    if (! is_user_logged_in()) {
        $here = home_url(add_query_arg([], (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/')));
        echo '<div class="bp-card bp-login"><h1>'.esc_html(\Bootspruefung\Learning\Settings\Settings::brand()).'</h1>';
        $bp_invite = \Bootspruefung\Learning\Frontend\Router::guestInvite((string) get_query_var('bp_path'));
        if ($bp_invite !== null) {
            // Gruppeneinladung (T51/UX4-09): Gäste sehen, wozu sie eingeladen sind; nach Anmeldung geht es hierher zurück.
            echo $bp_invite; // phpcs:ignore WordPress.Security.EscapeOutput -- in guestInvite() maskiert
        } else {
            echo '<p>'.esc_html__('Melde dich an, um zu lernen, deinen Fortschritt zu speichern und Prüfungen zu simulieren.', 'bootspruefung-learning').'</p>';
        }
        echo '<p><a class="bp-button bp-button-primary" href="'.esc_url(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::loginUrl($here)).'">'.esc_html__('Anmelden', 'bootspruefung-learning').'</a>';
        if (get_option('users_can_register') || get_option('woocommerce_enable_myaccount_registration') === 'yes') {
            // Nach der Registrierung zurück hierher (z. B. Gruppeneinladung).
            echo ' <a class="bp-button" href="'.esc_url(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::registrationUrl($here)).'">'.esc_html__('Konto anlegen', 'bootspruefung-learning').'</a>';
        }
        echo '</p></div>';
    } else {
        echo '<p class="bp-loading">'.esc_html__('Lernbereich wird geladen …', 'bootspruefung-learning').'</p>';
        echo '<noscript><p>'.esc_html__('Der Lernbereich benötigt JavaScript.', 'bootspruefung-learning').'</p></noscript>';
    }
    echo '</div>';
};

if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
    // Wie template-canvas.php: Template-Teile vor wp_head() rendern, damit ihre Styles im Kopf landen.
    $bp_header = do_blocks('<!-- wp:template-part {"slug":"header","tagName":"header"} /-->');
    $bp_footer = do_blocks('<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->');
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $bp_header; // phpcs:ignore WordPress.Security.EscapeOutput -- gerenderte Blöcke. ?>
	<main class="wp-block-group bp-main is-layout-constrained" id="bp-main">
		<?php $bp_content(); ?>
	</main>
	<?php echo $bp_footer; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
	<?php
} else {
    get_header();
    echo '<main class="bp-main" id="bp-main">';
    $bp_content();
    echo '</main>';
    get_footer();
}
