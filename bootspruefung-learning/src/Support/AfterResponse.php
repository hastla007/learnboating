<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Support;

/**
 * Arbeit erst nach dem Senden der Antwort erledigen (T88, T110): Passwort-Mails in App und Web. Sonst verriete die
 * Antwortzeit, ob es ein Konto gibt (Schlüssel erzeugen und Mail senden dauern messbar).
 *
 * Möglich mit PHP-FPM (fastcgi_finish_request) bzw. LiteSpeed (litespeed_finish_request), nicht mit mod_php oder auf
 * der Kommandozeile. Dann gilt der Rückfall: Die Arbeit läuft sofort, und alle Antworten – mit und ohne Konto –
 * werden auf dieselbe Mindestdauer gestreckt (pad()). Filter bootspruefung_defer_after_response erzwingt bzw.
 * verhindert das Verschieben, Filter bootspruefung_neutral_response_seconds setzt die Mindestdauer (Standard 2 s).
 */
final class AfterResponse
{
    public const MIN_SECONDS = 2.0;

    /** @var list<callable():void> nach dem Senden der Antwort auszuführen */
    private static array $deferred = [];

    /** Lässt sich die Antwort vor dem Ende des Skripts abschließen? */
    public static function possible(): bool
    {
        $finish = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');

        return (bool) apply_filters('bootspruefung_defer_after_response', $finish && PHP_SAPI !== 'cli');
    }

    /** $fn nach dem Senden der Antwort ausführen (am Ende der Anfrage); ohne diese Möglichkeit sofort. */
    public static function run(callable $fn): void
    {
        if (! self::possible()) {
            $fn();

            return;
        }
        if (self::$deferred === []) {
            add_action('shutdown', static function (): void {
                self::finish();
                self::runDeferred();
            }, 0);
        }
        self::$deferred[] = $fn;
    }

    /** Verschobene Arbeit jetzt ausführen (Ende der Anfrage; Tests). */
    public static function runDeferred(): void
    {
        while (($fn = array_shift(self::$deferred)) !== null) {
            $fn();
        }
    }

    /**
     * Bisherige Antwort (Kopfzeilen, Ausgabe) jetzt an den Browser schicken und die Verbindung schließen; das Skript
     * läuft weiter. Ohne fastcgi_finish_request bzw. litespeed_finish_request passiert nichts.
     */
    public static function finish(): void
    {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /** Mindestdauer einer neutralen Antwort im Rückfall (Sekunden, 0 bis 10). */
    public static function minSeconds(): float
    {
        return max(0.0, min(10.0, (float) apply_filters('bootspruefung_neutral_response_seconds', self::MIN_SECONDS)));
    }

    /**
     * Rückfall ohne Verschieben: bis zur Mindestdauer seit Beginn der Anfrage warten ($start: Beginn, Standard
     * REQUEST_TIME_FLOAT). Dauert die Arbeit länger, wird nicht gewartet. Gibt die Wartezeit in Sekunden zurück.
     */
    public static function pad(?float $start = null): float
    {
        $start ??= (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $wait = $start + self::minSeconds() - microtime(true);
        if ($wait <= 0) {
            return 0.0;
        }
        usleep((int) round($wait * 1_000_000));

        return $wait;
    }
}
