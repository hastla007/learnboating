<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Support;

/**
 * Plugin-Mails mit demselben Absender wie die Shop-Mails (WooCommerce-Einstellungen),
 * statt „WordPress <wordpress@…>“.
 *
 * Mails an Kundinnen und Kunden (Gruppeneinladung, Ablauf-Erinnerung, Geld-zurück-Bestätigung, Testmail) bekommen
 * am Ende einen einheitlichen Fuß mit den Anbieter-Angaben: Name bzw. Firma, Anschrift, E-Mail (To-do T58).
 * Standard: Name der Website, Shop-Adresse und Absenderadresse aus WooCommerce. Das Theme setzt über den Filter
 * `bootspruefung_mail_footer` die Betreiberangaben ein (Design › Betreiberangaben). Interne Mails an den Betreiber
 * (z. B. „Geld-zurück-Anfrage“) senden ohne Fuß (`footer: false`).
 */
final class Mail
{
    /** Trenner vor dem Fuß (Signatur-Trenner „-- “ mit Leerzeichen, wie in Mailprogrammen üblich). */
    public const SEPARATOR = '-- ';

    /**
     * @param list<string> $extraHeaders zusätzliche Kopfzeilen, z. B. List-Unsubscribe
     * @param bool $footer Anbieter-Angaben anhängen (Mails an Kunden); false für interne Mails an den Betreiber
     */
    public static function send(string $to, string $subject, string $body, array $extraHeaders = [], bool $footer = true): bool
    {
        $headers = [];
        $address = (string) get_option('woocommerce_email_from_address', '');
        $name = (string) get_option('woocommerce_email_from_name', get_bloginfo('name'));
        if ($address !== '' && is_email($address)) {
            $headers[] = 'From: '.self::header($name).' <'.$address.'>';
            $headers[] = 'Reply-To: '.$address;
        }

        foreach ($extraHeaders as $line) {
            $headers[] = (string) preg_replace('/[\r\n]+/', ' ', (string) $line);
        }

        if ($footer) {
            $body = self::withFooter($body, self::footer($to, $subject));
        }

        return wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Anbieter-Angaben für den Fuß (mehrzeilig, ohne Trenner). Leer = kein Fuß.
     *
     * Standard: Name der Website, Shop-Adresse (WooCommerce › Einstellungen › Allgemein, nur vollständig mit Straße
     * und Ort) und die Absenderadresse der Shop-Mails.
     */
    public static function footer(string $to = '', string $subject = ''): string
    {
        $lines = [];
        $name = trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
        if ($name !== '') {
            $lines[] = $name;
        }
        $street = trim((string) get_option('woocommerce_store_address').' '.(string) get_option('woocommerce_store_address_2'));
        $city = trim((string) get_option('woocommerce_store_postcode').' '.(string) get_option('woocommerce_store_city'));
        if ($street !== '' && $city !== '') {
            $lines[] = implode(', ', array_filter([$street, $city, self::country()], static fn ($v) => $v !== ''));
        }
        $email = (string) get_option('woocommerce_email_from_address', '');
        if ($email !== '' && is_email($email)) {
            /* translators: %s: E-Mail-Adresse */
            $lines[] = sprintf(__('E-Mail: %s', 'bootspruefung-learning'), $email);
        }

        /**
         * Fuß der Plugin-Mails (Anbieter-Angaben, mehrzeilig, ohne Trenner „-- “). Leer = kein Fuß.
         *
         * @param string $footer  Standard aus Website-Name, Shop-Adresse und Absenderadresse
         * @param string $to      Empfänger
         * @param string $subject Betreff
         */
        $footer = (string) apply_filters('bootspruefung_mail_footer', implode("\n", $lines), $to, $subject);

        return trim(str_replace("\r", '', $footer));
    }

    /**
     * Fuß anhängen – genau einmal: Steht der Fuß schon im Text, bleibt der Text unverändert. Hat der Text schon einen
     * Abschnitt nach „-- “ (z. B. Abmeldehinweis der Ablauf-Erinnerung), kommt der Fuß ans Ende dieses Abschnitts
     * statt eines zweiten Trenners.
     */
    public static function withFooter(string $body, string $footer): string
    {
        if ($footer === '' || str_contains($body, $footer)) {
            return $body;
        }
        $body = rtrim($body);
        $hasSeparator = preg_match('/(^|\n)'.preg_quote(self::SEPARATOR, '/').'\n/', $body) === 1;

        return $body."\n\n".($hasSeparator ? '' : self::SEPARATOR."\n").$footer;
    }

    /** Land der Shop-Adresse (WooCommerce), sonst leer. */
    private static function country(): string
    {
        if (! function_exists('WC') || ! WC()->countries instanceof \WC_Countries) {
            return '';
        }
        $code = (string) WC()->countries->get_base_country();
        $countries = WC()->countries->get_countries();

        return $code !== '' && isset($countries[$code]) ? html_entity_decode((string) $countries[$code], ENT_QUOTES, 'UTF-8') : '';
    }

    private static function header(string $value): string
    {
        return trim((string) preg_replace('/[\r\n"<>]+/', ' ', $value));
    }
}
