<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

/**
 * Kurze Sperre für kritische Abschnitte einzelner Anfragen (T83), z. B. Gruppenbeitritt: MySQL/MariaDB
 * GET_LOCK() auf der Verbindung von WordPress. Die Sperre gilt für den Namen (je Datenbank und Präfix), wartet
 * höchstens $timeout Sekunden und endet spätestens mit der Verbindung – eine abgebrochene Anfrage hinterlässt
 * keine hängende Sperre (anders als Lock, das für lange Läufe wie Import und Migration gedacht ist).
 *
 * Ergebnis von acquire(): true = gehalten, false = belegt (Zeit abgelaufen). Kann die Datenbank keine benannten
 * Sperren (Fehler, NULL), gilt die Sperre als gehalten und es wird protokolliert – die Aufrufer prüfen danach
 * ohnehin noch einmal (z. B. Platzzählung, laufende Prüfung, Bestellung je Gutscheincode), es wird also nur die
 * zusätzliche Sicherung übersprungen. Ein NULL blockiert damit nie dauerhaft (früher bei Prüfungsstart und
 * Gutscheineinlösung der Fall, T115). Belegt (false) heißt dagegen immer: nichts tun, Aufrufer meldet „gerade belegt“.
 *
 * HyperDB/LudicrousDB (Lese-Replikate): GET_LOCK und RELEASE_LOCK sind SELECT-Anweisungen und gingen sonst an ein
 * Replikat (andere Verbindung, andere Sperre). Vor dem Sperren werden deshalb alle weiteren Lesezugriffe dieser
 * Anfrage an den Hauptserver geschickt (send_reads_to_masters(), falls vorhanden).
 *
 * Wer NamedLock nutzt (T83, T115): Gruppenbeitritt und Einladungen (GroupService), Prüfungsstart je Nutzer
 * (ExamService), Kursgutschein-Bestellung je Code (Extensions\Vouchers), Geld-zurück-Anfrage je Bestellung (MoneyBackWoo).
 */
final class NamedLock
{
    /** @var array<string,int> in dieser Anfrage gehaltene Sperren (Name => Tiefe) */
    private static array $held = [];

    public static function acquire(string $name, ?int $timeout = null): bool
    {
        global $wpdb;
        if (isset(self::$held[$name])) {
            self::$held[$name]++;

            return true;
        }
        /** Filter: Wartezeit in Sekunden auf eine belegte Sperre (Standard 5). */
        $timeout = max(0, (int) apply_filters('bootspruefung_named_lock_timeout', $timeout ?? 5, $name));
        self::toPrimary();
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::dbName($name), $timeout));
        if ($result === null) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Betriebshinweis
            error_log('[bootspruefung-learning] GET_LOCK nicht verfügbar ('.$name.'): '.$wpdb->last_error);
            self::$held[$name] = 1;

            return true;
        }
        if ((int) $result !== 1) {
            return false;
        }
        self::$held[$name] = 1;

        return true;
    }

    public static function release(string $name): void
    {
        global $wpdb;
        if (! isset(self::$held[$name])) {
            return;
        }
        if (--self::$held[$name] > 0) {
            return;
        }
        unset(self::$held[$name]);
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::dbName($name)));
    }

    /**
     * $fn unter der Sperre ausführen; ist sie belegt, $busy() (z. B. Ausnahme werfen).
     * @template T
     * @param callable():T $fn
     * @param callable():T $busy
     * @return T
     */
    public static function with(string $name, callable $fn, callable $busy, ?int $timeout = null): mixed
    {
        if (! self::acquire($name, $timeout)) {
            return $busy();
        }
        try {
            return $fn();
        } finally {
            self::release($name);
        }
    }

    /** HyperDB/LudicrousDB: Sperre und Freigabe auf derselben Verbindung zum Hauptserver (nicht auf einem Replikat). */
    public static function toPrimary(): void
    {
        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'send_reads_to_masters')) {
            $wpdb->send_reads_to_masters();
        }
    }

    /** Name in der Datenbank: höchstens 64 Zeichen, eindeutig je Datenbank und Tabellenpräfix. */
    public static function dbName(string $name): string
    {
        global $wpdb;

        return 'bp:'.substr(hash('sha256', DB_NAME.'|'.$wpdb->prefix.'|'.$name), 0, 40);
    }
}
