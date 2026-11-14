<?php

/*
 * Nur für PHPStan: die genutzten Teile von WP-CLI. Das Paket php-stubs/wp-cli-stubs verlangt WordPress-Stubs bis 6.x
 * und passt deshalb nicht zu den WordPress-7-Stubs. Wird nie geladen (scanFiles).
 */

namespace {
    class WP_CLI
    {
        /** @param callable|class-string|object $callable @param array<string, mixed> $args */
        public static function add_command(string $name, $callable, array $args = []): bool { return true; }

        /** @param array<string, mixed> $assoc_args */
        public static function confirm(string $question, array $assoc_args = []): void {}

        public static function error(string|\WP_Error|\Throwable $message, bool|int $exit = true): never { exit(1); }

        public static function halt(int $return_code): never { exit($return_code); }

        public static function line(string $message = ''): void {}

        public static function log(string $message): void {}

        public static function success(string $message): void {}

        public static function warning(string|\WP_Error|\Throwable $message): void {}
    }
}

namespace WP_CLI\Utils {
    /** @param array<mixed> $items @param array<string>|string $fields */
    function format_items(string $format, array $items, array|string $fields): void {}

    /** @param array<string, mixed> $assoc_args */
    function get_flag_value(array $assoc_args, string $flag, mixed $default = null): mixed { return $default; }
}
