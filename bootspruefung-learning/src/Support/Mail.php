<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Support;

/**
 * Plugin-Mails mit demselben Absender wie die Shop-Mails (WooCommerce-Einstellungen),
 * statt „WordPress <wordpress@…>“.
 */
final class Mail
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $headers = [];
        $address = (string) get_option('woocommerce_email_from_address', '');
        $name = (string) get_option('woocommerce_email_from_name', get_bloginfo('name'));
        if ($address !== '' && is_email($address)) {
            $headers[] = 'From: '.self::header($name).' <'.$address.'>';
            $headers[] = 'Reply-To: '.$address;
        }

        return wp_mail($to, $subject, $body, $headers);
    }

    private static function header(string $value): string
    {
        return trim((string) preg_replace('/[\r\n"<>]+/', ' ', $value));
    }
}
