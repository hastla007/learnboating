<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

defined('ABSPATH') || exit;

/**
 * Wartungsläufe robust ausführen: Jede Teilaufgabe des stündlichen Crons läuft für sich. Ein Fehler
 * (z. B. ein defekter Datensatz) wird protokolliert und bricht weder die übrigen Aufgaben noch
 * spätere Cron-Ereignisse desselben WP-Cron-Laufs (auch anderer Plugins) ab.
 */
final class Cron
{
    public const HOURLY = 'bp_learning_hourly';

    /** Letzte Fehler der Wartungsläufe (Systemstatus/Support), höchstens MAX_ERRORS. */
    public const OPTION_ERRORS = 'bp_learning_cron_errors';

    private const MAX_ERRORS = 20;

    /** Führt eine Teilaufgabe aus. @return bool false, wenn sie mit einem Fehler endete */
    public static function run(string $task, callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (\Throwable $e) {
            self::recordError($task, $e);

            return false;
        }
    }

    /** Callback für add_action(), der Fehler wie run() abfängt. */
    public static function guard(string $task, callable $callback): \Closure
    {
        return static function () use ($task, $callback): void {
            self::run($task, $callback);
        };
    }

    /** Fehler eines einzelnen Eintrags festhalten (z. B. eine Prüfungssitzung), ohne den Lauf abzubrechen. */
    public static function recordError(string $task, \Throwable $e, ?string $item = null): void
    {
        $message = sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Betriebsprotokoll
        error_log(sprintf('[bootspruefung-learning] Wartungslauf „%s“%s fehlgeschlagen: %s', $task, $item !== null ? ' ('.$item.')' : '', $message));
        $errors = get_option(self::OPTION_ERRORS, []);
        $errors = is_array($errors) ? $errors : [];
        array_unshift($errors, ['task' => $task, 'item' => $item, 'message' => mb_substr($message, 0, 500), 'at' => gmdate('Y-m-d H:i:s')]);
        update_option(self::OPTION_ERRORS, array_slice($errors, 0, self::MAX_ERRORS), false);
    }

    /** @return list<array{task:string,item:?string,message:string,at:string}> */
    public static function errors(): array
    {
        $errors = get_option(self::OPTION_ERRORS, []);

        return is_array($errors) ? array_values($errors) : [];
    }
}
