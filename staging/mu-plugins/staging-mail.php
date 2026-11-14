<?php
/**
 * Staging: alle E-Mails an das Test-Postfach (Mailpit) statt ins Internet.
 * Ansehen unter http://localhost:8025 (siehe staging/README.md).
 */
add_action('phpmailer_init', static function ($mailer): void {
    $mailer->isSMTP();
    $mailer->Host = getenv('STAGING_SMTP_HOST') ?: 'mailpit';
    $mailer->Port = 1025;
    $mailer->SMTPAuth = false;
    $mailer->SMTPAutoTLS = false;
});
add_filter('wp_mail_from', static fn () => 'staging@example.invalid');
