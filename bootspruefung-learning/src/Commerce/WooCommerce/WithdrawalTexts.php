<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * Deutsche Ersatztexte für die WooCommerce-Widerrufsfunktion („Order withdrawal“, seit WooCommerce 11.1).
 *
 * Greift nur, wenn die Website Deutsch spricht UND das WooCommerce-Sprachpaket den Text (noch) nicht
 * übersetzt – eine vorhandene offizielle Übersetzung hat immer Vorrang. Die gesetzlich geforderten
 * Beschriftungen (§ 356a BGB) stehen so auch bei neuen, noch unübersetzten Texten auf Deutsch da.
 */
final class WithdrawalTexts
{
    /** @var array<string,string> Englischer Quelltext => Deutsch (Du-Form) */
    private const TEXTS = [
        // Seite und Formular
        'Withdraw from contract' => 'Vertrag hier widerrufen',
        'Tell us if you want to withdraw from an order placed on this store. You do not need to give a reason.' => 'Hier kannst du einen Vertrag aus einer Bestellung in diesem Shop widerrufen. Eine Begründung ist nicht nötig.',
        'Some items, like personalized products, may not be eligible. We review every request and reply by email.' => 'Nicht für jede Leistung besteht ein Widerrufsrecht (Einzelheiten in der Widerrufsbelehrung). Wir prüfen jeden Widerruf und antworten per E-Mail.',
        'Order withdrawal progress' => 'Schritte des Widerrufs',
        'Your details' => 'Deine Angaben',
        'Review and confirm' => 'Prüfen und bestätigen',
        'First name' => 'Vorname',
        'Last name' => 'Nachname',
        'Email address' => 'E-Mail-Adresse',
        'Confirm email address' => 'E-Mail-Adresse wiederholen',
        'Order number' => 'Bestellnummer',
        'What do you want to withdraw?' => 'Was möchtest du widerrufen?',
        'The full order' => 'Die gesamte Bestellung',
        'Specific items only' => 'Nur einzelne Artikel',
        'Additional details' => 'Weitere Angaben',
        'No reason needed. If you selected specific items, list them here.' => 'Keine Begründung nötig. Wenn du nur einzelne Artikel widerrufst, nenne sie hier.',
        'Continue to review' => 'Weiter zur Prüfung',
        'Check your details before confirming.' => 'Prüfe deine Angaben, bevor du bestätigst.',
        'Nothing has been sent yet. Your withdrawal is submitted when you select "Confirm withdrawal".' => 'Noch wurde nichts gesendet. Dein Widerruf wird erst abgeschickt, wenn du „Widerruf bestätigen“ wählst.',
        'After you confirm, we\'ll email an acknowledgment to %s with these details and the date and time of submission. Keep it as proof of your withdrawal.' => 'Nach der Bestätigung schicken wir eine Eingangsbestätigung mit diesen Angaben sowie Datum und Uhrzeit des Eingangs an %s. Bewahre sie als Nachweis deines Widerrufs auf.',
        'Confirm withdrawal' => 'Widerruf bestätigen',
        'Edit details' => 'Angaben ändern',
        'Your withdrawal has been submitted.' => 'Dein Widerruf ist eingegangen.',
        'We\'ve emailed an acknowledgment to %s with your details and the date and time of submission. Keep it as proof of your withdrawal.' => 'Wir haben dir eine Eingangsbestätigung mit deinen Angaben sowie Datum und Uhrzeit des Eingangs an %s geschickt. Bewahre sie als Nachweis deines Widerrufs auf.',
        'We\'ll review your request and contact you about next steps, including any refund due.' => 'Wir bearbeiten deinen Widerruf und melden uns bei dir, auch zu einer Rückzahlung.',
        'Back to the shop' => 'Zurück zum Shop',
        // Fehlermeldungen
        'We could not verify your request. Please try again.' => 'Wir konnten deine Anfrage nicht prüfen. Bitte versuche es noch einmal.',
        'First name is a required field.' => 'Bitte gib deinen Vornamen an.',
        'Last name is a required field.' => 'Bitte gib deinen Nachnamen an.',
        'Enter a valid email address.' => 'Bitte gib eine gültige E-Mail-Adresse an.',
        'Confirm email address is a required field.' => 'Bitte wiederhole deine E-Mail-Adresse.',
        'Email addresses do not match.' => 'Die E-Mail-Adressen stimmen nicht überein.',
        'Order number is a required field.' => 'Bitte gib die Bestellnummer an.',
        'Choose what you want to withdraw.' => 'Bitte wähle, was du widerrufen möchtest.',
        'List the specific items you want to withdraw.' => 'Bitte nenne die Artikel, die du widerrufen möchtest.',
        'We could not submit your withdrawal request. Please try again or contact us if the problem continues.' => 'Dein Widerruf konnte nicht gesendet werden. Bitte versuche es noch einmal oder schreib uns, wenn es weiter nicht klappt.',
        'A withdrawal request has already been submitted for this order. Please contact us if you need help or want to make changes.' => 'Zu dieser Bestellung ist bereits ein Widerruf eingegangen. Schreib uns, wenn du Hilfe brauchst oder etwas ändern möchtest.',
        'Please wait before submitting another withdrawal request.' => 'Bitte warte kurz, bevor du einen weiteren Widerruf sendest.',
        // E-Mails und Bestellnotiz
        'We received your withdrawal request' => 'Dein Widerruf ist bei uns eingegangen',
        'We have received your request to withdraw from the order below.' => 'Wir haben deinen Widerruf zur folgenden Bestellung erhalten.',
        'We will review your request and contact you about next steps, including any refund due.' => 'Wir bearbeiten deinen Widerruf und melden uns bei dir, auch zu einer Rückzahlung.',
        'Submitted' => 'Eingegangen am',
        'Name' => 'Name',
        'Withdrawing' => 'Widerrufen wird',
        'None provided' => 'Keine',
        'Order withdrawal requested. Withdrawal type: %s.' => 'Widerruf eingegangen. Umfang: %s.',
        'Order withdrawal request for #%s' => 'Widerruf zu Bestellung #%s',
        '[{site_title}]: Order withdrawal request for order {order_number}' => '[{site_title}]: Widerruf zu Bestellung {order_number}',
        'A customer submitted an order withdrawal request.' => 'Eine Kundin oder ein Kunde hat einen Widerruf erklärt.',
        'A customer submitted an order withdrawal request for order #%s. Review the matched order to confirm the request details.' => 'Zu Bestellung #%s ist ein Widerruf eingegangen. Bitte prüfe die Bestellung.',
        'WooCommerce matched this request to an order and added an order note.' => 'Der Widerruf wurde einer Bestellung zugeordnet und dort als Notiz vermerkt.',
        'WooCommerce could not match this request to an order automatically, so no order note was added.' => 'Der Widerruf ließ sich keiner Bestellung zuordnen (Bestellnummer und E-Mail-Adresse passen nicht zusammen). Bitte nimm Kontakt mit der Person auf.',
        'Matched order ID: %d' => 'Zugeordnete Bestellung (ID): %d',
        'View matched order' => 'Bestellung öffnen',
        'View order' => 'Bestellung ansehen',
        'Review the request details and contact the customer about next steps.' => 'Bitte prüfe die Angaben und melde dich bei der Person. Zurückzahlen über „Erstattung“ in der Bestellung – der Lernzugang endet dann automatisch.',
        'This order is older than %1$d days. Only orders within %2$d days of delivery are eligible for withdrawal.' => 'Diese Bestellung ist älter als %1$d Tage. Bitte prüfe, ob die Widerrufsfrist (%2$d Tage ab Vertragsschluss) noch lief.',
        'Order withdrawal request received' => 'Eingangsbestätigung Widerruf',
        'Order withdrawal request' => 'Widerruf eingegangen',
    ];

    public function register(): void
    {
        add_filter('gettext_woocommerce', [$this, 'translate'], 20, 2);
    }

    /** @param string $translation @param string $text */
    public function translate($translation, $text)
    {
        if ($translation !== $text || ! isset(self::TEXTS[$text]) || ! self::german()) {
            return $translation;
        }

        return self::TEXTS[$text];
    }

    private static function german(): bool
    {
        // Nicht zwischenspeichern: Mails an Kunden laufen ggf. mit anderer Sprache (switch_to_locale).
        return str_starts_with((string) (function_exists('determine_locale') ? determine_locale() : get_locale()), 'de');
    }
}
