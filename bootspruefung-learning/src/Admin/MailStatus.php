<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Support\Mail;

/**
 * Mailversand vor dem Livegang prüfen (To-do T54): Absender, Versandweg (SMTP-Plugin oder PHP mail()), SPF- und
 * DMARC-Eintrag der Absender-Domain, Testmail mit Ergebnis. Dazu bekommen die WordPress-eigenen Mails (Passwort,
 * Datenschutzanfragen) denselben Absender wie die Shop-Mails statt „WordPress <wordpress@…>“.
 */
final class MailStatus
{
    public const OPTION_TEST = 'bp_mail_test';
    private const ACTION = 'bp_mail_test';

    public static function register(): void
    {
        add_filter('wp_mail_from', [self::class, 'from'], 20);
        add_filter('wp_mail_from_name', [self::class, 'fromName'], 20);
        add_action('admin_post_'.self::ACTION, [self::class, 'handleTest']);
    }

    /** Absender der Shop-Mails (WooCommerce) oder ''. */
    public static function shopAddress(): string
    {
        $address = (string) get_option('woocommerce_email_from_address', '');

        return is_email($address) ? $address : '';
    }

    /** wp_mail_from: Standard „wordpress@…“ durch den Shop-Absender ersetzen; eigene Einstellungen bleiben. @param mixed $from */
    public static function from($from): string
    {
        $from = (string) $from;
        $shop = self::shopAddress();

        return $shop !== '' && str_starts_with(strtolower($from), 'wordpress@') ? $shop : $from;
    }

    /** @param mixed $name */
    public static function fromName($name): string
    {
        $name = (string) $name;
        $shopName = trim((string) get_option('woocommerce_email_from_name', ''));

        return $shopName !== '' && self::shopAddress() !== '' && $name === 'WordPress' ? $shopName : $name;
    }

    /** Domain einer Adresse. */
    public static function domain(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }

    /** TXT-Einträge einer Domain, die mit $prefix beginnen (z. B. „v=spf1“). @return list<string>|null null = DNS nicht abfragbar */
    public static function txt(string $host, string $prefix): ?array
    {
        if ($host === '' || ! function_exists('dns_get_record')) {
            return null;
        }
        $records = @dns_get_record($host, DNS_TXT);
        if ($records === false) {
            return null;
        }
        $out = [];
        foreach ($records as $r) {
            $txt = isset($r['entries']) ? implode('', (array) $r['entries']) : (string) ($r['txt'] ?? '');
            if (stripos($txt, $prefix) === 0) {
                $out[] = $txt;
            }
        }

        return $out;
    }

    /** Wird der Versand umgebogen (SMTP-Plugin über phpmailer_init oder pre_wp_mail)? */
    public static function transport(): string
    {
        if (has_filter('pre_wp_mail')) {
            return __('eigener Versand (pre_wp_mail, z. B. Mail-Dienst per API)', 'bootspruefung-learning');
        }
        if (has_action('phpmailer_init')) {
            return __('angepasster Versand (phpmailer_init, z. B. SMTP-Plugin)', 'bootspruefung-learning');
        }

        return __('PHP mail() des Servers – für den Livegang ein SMTP-Konto oder einen Mail-Dienst einrichten', 'bootspruefung-learning');
    }

    /** Testmail an die Admin-Adresse; Ergebnis mit Fehlermeldung von wp_mail_failed. @return array{at:int,to:string,ok:bool,error:string} */
    public static function sendTest(string $to): array
    {
        $error = '';
        $catch = static function ($e) use (&$error): void {
            if ($e instanceof \WP_Error) {
                $error = $e->get_error_message();
            }
        };
        add_action('wp_mail_failed', $catch);
        $ok = Mail::send($to, sprintf(__('Testmail von %s', 'bootspruefung-learning'), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)), __("Diese Testmail kommt aus Bootsprüfung › Systemstatus.\n\nKommt sie an und landet nicht im Spam-Ordner, funktioniert der Versand. Prüfe im Quelltext der Mail (Kopfzeile Authentication-Results), ob SPF, DKIM und DMARC „pass“ melden.", 'bootspruefung-learning'));
        remove_action('wp_mail_failed', $catch);
        $result = ['at' => time(), 'to' => $to, 'ok' => $ok, 'error' => $error];
        update_option(self::OPTION_TEST, $result, false);

        return $result;
    }

    public static function handleTest(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $user = wp_get_current_user();
        self::sendTest((string) ($user->user_email ?: get_option('admin_email')));
        wp_safe_redirect(add_query_arg('page', 'bootspruefung-status', admin_url('admin.php')).'#bp-mail');
        exit;
    }

    /** @return array<string,string> Beschriftung => Wert */
    public static function rows(): array
    {
        $from = (string) apply_filters('wp_mail_from', 'wordpress@'.preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        $shop = self::shopAddress();
        $domain = self::domain($shop !== '' ? $shop : $from);
        $site = strtolower((string) preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        $spf = self::txt($domain, 'v=spf1');
        $dmarc = self::txt('_dmarc.'.$domain, 'v=DMARC1');
        $dns = static fn (?array $rec, string $missing): string => $rec === null ? __('DNS nicht abfragbar', 'bootspruefung-learning') : ($rec === [] ? $missing : implode(' | ', $rec));
        $test = get_option(self::OPTION_TEST);

        return [
            __('Absender Shop-Mails', 'bootspruefung-learning') => $shop !== '' ? trim((string) get_option('woocommerce_email_from_name', '')).' <'.$shop.'>' : __('nicht gesetzt (WooCommerce › Einstellungen › E-Mails)', 'bootspruefung-learning'),
            __('Absender WordPress-Mails', 'bootspruefung-learning') => $from,
            __('Absender-Domain', 'bootspruefung-learning') => $domain.($domain !== '' && $site !== '' && $domain !== $site && ! str_ends_with($site, '.'.$domain) ? ' · '.__('weicht von der Website-Domain ab – SPF/DKIM für diese Domain einrichten', 'bootspruefung-learning') : ''),
            __('Versandweg', 'bootspruefung-learning') => self::transport(),
            'SPF' => $dns($spf, __('fehlt – beim Mail-Dienst bzw. Hoster nachfragen', 'bootspruefung-learning')),
            'DMARC' => $dns($dmarc, __('fehlt – mindestens „v=DMARC1; p=none; rua=mailto:…“ setzen', 'bootspruefung-learning')),
            'DKIM' => __('mit dem Selektor des Mail-Dienstes prüfen (z. B. in der Testmail: Authentication-Results)', 'bootspruefung-learning'),
            __('Letzte Testmail', 'bootspruefung-learning') => is_array($test)
                ? wp_date('d.m.Y H:i', (int) $test['at']).' · '.$test['to'].' · '.($test['ok'] ? __('übergeben (Ankunft im Postfach prüfen)', 'bootspruefung-learning') : __('fehlgeschlagen', 'bootspruefung-learning').($test['error'] !== '' ? ': '.$test['error'] : ''))
                : __('noch keine', 'bootspruefung-learning'),
        ];
    }

    public static function render(): void
    {
        echo '<h2 id="bp-mail">'.esc_html__('E-Mail-Versand', 'bootspruefung-learning').'</h2>';
        echo '<p class="description">'.esc_html__('Bestellbestätigungen, Passwort-Links, Datenschutz-Bestätigungen und Gruppeneinladungen müssen zuverlässig ankommen.', 'bootspruefung-learning').'</p>';
        echo '<table class="widefat striped" style="max-width:720px"><tbody>';
        foreach (self::rows() as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html($value));
        }
        echo '</tbody></table>';
        if (current_user_can('manage_options')) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:8px"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
            wp_nonce_field(self::ACTION);
            echo '<button type="submit" class="button">'.esc_html(sprintf(__('Testmail an %s senden', 'bootspruefung-learning'), wp_get_current_user()->user_email)).'</button></form>';
        }
    }
}
