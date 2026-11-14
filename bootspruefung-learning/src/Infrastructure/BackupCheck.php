<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Gate\ProtectedFiles;
use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\Database\Schema;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Plugin;

/**
 * Prüfung nach einem Restore (To-do T66, docs/backup-restore.md): Passen Datenbank-Schema, Tabellen,
 * Optionen, Paketordner, geschützte Dateien, Medien und Zugänge zusammen?
 *
 * Nur lesend: legt keine Ordner an, verschiebt nichts und schreibt keine Optionen. Deshalb werden die
 * Ordner hier selbst berechnet (MediaStorage::packagesDir()/protectedDir() würden Ordner anlegen bzw. umziehen).
 *
 * Ergebnis je Prüfung: status ok | warn | fail, Bezeichnung und Erklärung.
 */
final class BackupCheck
{
    /** Höchstzahl geprüfter Zugänge (Stichprobe über AccessService::can). */
    public const ACCESS_SAMPLE = 25;

    /** @return list<array{check:string,status:string,detail:string}> */
    public static function run(): array
    {
        $out = [];
        foreach ([
            'schema', 'tables', 'foreignKeys', 'options', 'storage', 'activePackage', 'protectedFiles', 'media', 'access', 'orders',
        ] as $step) {
            foreach (self::$step() as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Schlechtester Status: fail vor warn vor ok. @param list<array{status:string}> $rows */
    public static function overall(array $rows): string
    {
        $statuses = array_column($rows, 'status');

        return in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
    }

    /** @return array{check:string,status:string,detail:string} */
    private static function row(string $check, string $status, string $detail): array
    {
        return ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function schema(): array
    {
        $migrator = Plugin::instance()->migrator();
        $installed = $migrator->installedVersion();
        $label = __('Datenbank-Schema', 'bootspruefung-learning');
        if ($installed === null) {
            return [self::row($label, 'fail', sprintf(__('Option %s fehlt – Optionen bzw. Plugin-Tabellen sind nicht zurückgespielt.', 'bootspruefung-learning'), Migrator::OPTION_VERSION))];
        }
        if ($migrator->schemaIsNewer()) {
            return [self::row($label, 'fail', sprintf(__('Datenbank hat Schema %1$s, das Plugin erwartet %2$s: Das Backup stammt aus einer neueren Plugin-Version. Passende Plugin-Version einspielen.', 'bootspruefung-learning'), $installed, Migrator::SCHEMA_VERSION))];
        }
        if ($migrator->needsMigration()) {
            return [self::row($label, 'warn', sprintf(__('Schema %1$s, erwartet %2$s: Migration fällig (wp bootspruefung migrate oder Admin öffnen). Das Backup ist älter als das Plugin.', 'bootspruefung-learning'), $installed, Migrator::SCHEMA_VERSION))];
        }

        return [self::row($label, 'ok', $installed)];
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function tables(): array
    {
        $missing = [];
        $notInno = [];
        $rows = 0;
        foreach (Plugin::instance()->migrator()->tableStatus() as $table => $info) {
            if (! $info['exists']) {
                $missing[] = 'bp_'.$table;
            } elseif (strtolower((string) $info['engine']) !== 'innodb') {
                $notInno[] = 'bp_'.$table.' ('.$info['engine'].')';
            }
            $rows += (int) ($info['rows'] ?? 0);
        }
        $label = __('Tabellen', 'bootspruefung-learning');
        if ($missing !== []) {
            return [self::row($label, 'fail', sprintf(__('%1$d von %2$d fehlen: %3$s', 'bootspruefung-learning'), count($missing), count(Schema::TABLES), implode(', ', $missing)))];
        }
        if ($notInno !== []) {
            return [self::row($label, 'fail', __('Nicht InnoDB (Fremdschlüssel und Transaktionen fehlen): ', 'bootspruefung-learning').implode(', ', $notInno))];
        }

        return [self::row($label, 'ok', sprintf(__('alle %1$d vorhanden, ca. %2$s Zeilen', 'bootspruefung-learning'), count(Schema::TABLES), number_format_i18n($rows)))];
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function foreignKeys(): array
    {
        $have = Plugin::instance()->migrator()->foreignKeyCount();
        $want = count(Schema::foreignKeys());
        $label = __('Fremdschlüssel', 'bootspruefung-learning');

        return [$have >= $want
            ? self::row($label, 'ok', (string) $have)
            : self::row($label, 'warn', sprintf(__('%1$d von %2$d – der Dump wurde evtl. ohne Fremdschlüssel eingespielt. wp bootspruefung migrate legt fehlende an, wenn die Daten passen.', 'bootspruefung-learning'), $have, $want))];
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function options(): array
    {
        $missing = [];
        foreach ([MediaStorage::PACKAGES_SECRET_OPTION, MediaStorage::PROTECTED_SECRET_OPTION] as $name) {
            $value = get_option($name);
            if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
                $missing[] = $name;
            }
        }
        $label = __('Optionen bp_learning_*', 'bootspruefung-learning');
        if ($missing !== []) {
            return [self::row($label, 'fail', sprintf(__('Fehlt: %s. Ohne diese Geheimnisse findet das Plugin Paket- und Schutzordner nicht (es würde neue, leere Ordner anlegen). Optionen aus dem Backup zurückspielen.', 'bootspruefung-learning'), implode(', ', $missing)))];
        }
        if (! is_array(get_option(\Bootspruefung\Learning\Settings\Settings::OPTION))) {
            return [self::row($label, 'warn', sprintf(__('%s fehlt – es gelten die Standardeinstellungen (z. B. „Zugang ohne Shop“). Bitte prüfen.', 'bootspruefung-learning'), \Bootspruefung\Learning\Settings\Settings::OPTION))];
        }

        return [self::row($label, 'ok', __('Einstellungen und Ordner-Geheimnisse vorhanden', 'bootspruefung-learning'))];
    }

    /** Soll-Ordner ohne Nebenwirkung (wie MediaStorage::targetDir, aber ohne Anlegen). */
    private static function expectedDir(string $kind): ?string
    {
        $secret = get_option($kind === 'packages' ? MediaStorage::PACKAGES_SECRET_OPTION : MediaStorage::PROTECTED_SECRET_OPTION);
        if (! is_string($secret) || $secret === '') {
            return null;
        }
        $configured = MediaStorage::configuredPrivateDir();
        $base = $configured !== null && is_dir($configured) && is_writable($configured) ? $configured : MediaStorage::baseDir();

        return $base.'/'.$kind.'-'.$secret;
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function storage(): array
    {
        $out = [];
        $layout = MediaStorage::layout();
        $labels = ['packages' => __('Paketordner', 'bootspruefung-learning'), 'protected' => __('Schutzordner', 'bootspruefung-learning')];
        foreach ($labels as $kind => $label) {
            $expected = self::expectedDir($kind);
            if ($expected === null) {
                $out[] = self::row($label, 'fail', __('Ordnername unbekannt (Geheimnis-Option fehlt).', 'bootspruefung-learning'));
                continue;
            }
            $current = isset($layout[$kind]) && is_string($layout[$kind]) ? $layout[$kind] : null;
            if ($current !== null && $current !== $expected && is_dir($current)) {
                // relocate() würde diesen Ordner beim nächsten Admin-Aufruf bzw. Cron-Lauf hierher verschieben.
                $out[] = self::row($label, 'fail', sprintf(
                    __('Option %1$s zeigt auf %2$s (anderer Ort, z. B. das Original-System). Beim nächsten Admin-Aufruf oder Cron-Lauf verschiebt das Plugin diesen Ordner nach %3$s. Auf einer Kopie neben dem Original vorher den Eintrag „%4$s“ auf den neuen Pfad setzen.', 'bootspruefung-learning'),
                    MediaStorage::LAYOUT_OPTION, $current, $expected, $kind
                ));
                continue;
            }
            if (! is_dir($expected)) {
                $out[] = self::row($label, 'fail', sprintf(__('%s fehlt – Ordner aus dem Backup zurückspielen (mit dem Namen samt Geheimnis).', 'bootspruefung-learning'), $expected));
                continue;
            }
            $count = count(glob($expected.'/*', GLOB_NOSORT) ?: []);
            $note = $current !== null && $current !== $expected ? ' · '.sprintf(__('Option %s ist veraltet; das Plugin übernimmt den neuen Pfad beim nächsten Admin-Aufruf.', 'bootspruefung-learning'), MediaStorage::LAYOUT_OPTION) : '';
            $out[] = self::row($label, $note === '' ? 'ok' : 'warn', sprintf(__('%1$s (%2$d Einträge)', 'bootspruefung-learning'), $expected, $count).$note);
        }

        return $out;
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function activePackage(): array
    {
        $label = __('Aktives Content-Paket', 'bootspruefung-learning');
        $package = (string) get_option('bp_learning_active_package', '');
        if ($package === '') {
            return [self::row($label, 'warn', __('Kein aktives Paket eingetragen (bp_learning_active_package). Vor dem ersten Import normal, sonst Optionen prüfen.', 'bootspruefung-learning'))];
        }
        $batch = Plugin::instance()->importService()->activeBatchId();
        if ($batch === null) {
            return [self::row($label, 'fail', sprintf(__('%s: kein veröffentlichter Import dazu in bp_import_batch – Tabellen und Optionen stammen nicht aus demselben Backup.', 'bootspruefung-learning'), $package))];
        }
        $dir = self::expectedDir('packages');
        if ($dir === null || ! is_dir($dir.'/batch-'.$batch)) {
            return [self::row($label, 'warn', sprintf(__('%1$s (Import #%2$d): Paketkopie batch-%2$d fehlt im Paketordner. Die Inhalte liegen in der Datenbank; nur „Wiederherstellen“ nach einem abgebrochenen Import braucht die Kopie (Paket ggf. erneut hochladen).', 'bootspruefung-learning'), $package, $batch))];
        }

        return [self::row($label, 'ok', sprintf(__('%1$s (Import #%2$d), Paketkopie vorhanden', 'bootspruefung-learning'), $package, $batch))];
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function protectedFiles(): array
    {
        global $wpdb;
        $label = __('Geschützte Dateien', 'bootspruefung-learning');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", ProtectedFiles::PATH_META));
        if ($rows === []) {
            return [self::row($label, 'ok', __('keine geschützten Dateien eingetragen', 'bootspruefung-learning'))];
        }
        $dir = self::expectedDir('protected');
        $missing = [];
        $elsewhere = [];
        foreach ($rows as $r) {
            $path = (string) $r->meta_value;
            if (! is_file($path)) {
                $missing[] = '#'.(int) $r->post_id;
            } elseif ($dir !== null && ! str_starts_with(wp_normalize_path($path), trailingslashit(wp_normalize_path($dir)))) {
                $elsewhere[] = '#'.(int) $r->post_id;
            }
        }
        if ($missing !== []) {
            return [self::row($label, 'fail', sprintf(
                __('%1$d von %2$d Dateien fehlen am eingetragenen Pfad (Anhänge %3$s). Die Pfade stehen absolut in %4$s: Schutzordner zurückspielen bzw. nach einem Umzug die Pfade ersetzen (wp search-replace).', 'bootspruefung-learning'),
                count($missing), count($rows), implode(', ', array_slice($missing, 0, 10)), ProtectedFiles::PATH_META
            ))];
        }
        if ($elsewhere !== []) {
            return [self::row($label, 'warn', sprintf(_n('%1$d Datei liegt außerhalb des Schutzordners (Anhänge %2$s) – z. B. noch im Ordner des Original-Systems. Pfade ersetzen.', '%1$d Dateien liegen außerhalb des Schutzordners (Anhänge %2$s) – z. B. noch im Ordner des Original-Systems. Pfade ersetzen.', count($elsewhere), 'bootspruefung-learning'), count($elsewhere), implode(', ', array_slice($elsewhere, 0, 10))))];
        }

        return [self::row($label, 'ok', sprintf(_n('%d Datei vorhanden', '%d Dateien vorhanden', count($rows), 'bootspruefung-learning'), count($rows)))];
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    private static function media(): array
    {
        global $wpdb;
        $label = __('Medien (Bilder der Inhalte)', 'bootspruefung-learning');
        $keys = $wpdb->get_col("SELECT DISTINCT storage_key FROM {$wpdb->prefix}bp_media WHERE storage_key IS NOT NULL AND storage_key <> ''");
        if ($keys === []) {
            return [self::row($label, 'ok', __('keine Medien eingetragen', 'bootspruefung-learning'))];
        }
        $dir = MediaStorage::baseDir().'/media/';
        $missing = array_values(array_filter($keys, static fn ($k) => ! is_file($dir.$k)));
        if ($missing !== []) {
            return [self::row($label, count($missing) === count($keys) ? 'fail' : 'warn', sprintf(__('%1$d von %2$d Dateien fehlen in %3$s (z. B. %4$s).', 'bootspruefung-learning'), count($missing), count($keys), $dir, implode(', ', array_slice($missing, 0, 3))))];
        }

        return [self::row($label, 'ok', sprintf(_n('%d Datei vorhanden', '%d Dateien vorhanden', count($keys), 'bootspruefung-learning'), count($keys)))];
    }

    /**
     * Stichprobe: aktive, gültige Theorie-Zugänge müssen AccessService::can() bestehen. Upgrades (Video, Simulator)
     * sind ausgenommen – sie ruhen gewollt, wenn der Basiskurs endet.
     * @return list<array{check:string,status:string,detail:string}>
     */
    private static function access(): array
    {
        global $wpdb;
        $label = __('Lernzugänge', 'bootspruefung-learning');
        $t = $wpdb->prefix.'bp_entitlement';
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT user_id, course_code FROM {$t} WHERE status = 'active' AND feature_code IN (%s, 'all')
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s)
             AND (sku_snapshot IS NULL OR (sku_snapshot NOT LIKE %s AND sku_snapshot <> %s)) ORDER BY user_id DESC LIMIT %d",
            AccessService::QUESTION_BANK, $now, $now, '%'.$wpdb->esc_like(ShopCatalog::VIDEO_SUFFIX), ShopCatalog::SIM_UPGRADE, self::ACCESS_SAMPLE
        ));
        $orphans = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} e LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id WHERE e.status = 'active' AND u.ID IS NULL");
        if ($rows === []) {
            return [self::row($label, 'ok', __('keine aktiven Zugänge (Stichprobe leer)', 'bootspruefung-learning'))];
        }
        $access = new AccessService();
        $denied = [];
        foreach ($rows as $r) {
            if (! $access->can((int) $r->user_id, (string) $r->course_code, AccessService::QUESTION_BANK)) {
                $denied[] = 'Nutzer '.(int) $r->user_id.'/'.$r->course_code;
            }
        }
        if ($denied !== []) {
            return [self::row($label, 'fail', sprintf(__('%1$d von %2$d geprüften Zugängen werden abgelehnt: %3$s', 'bootspruefung-learning'), count($denied), count($rows), implode(', ', array_slice($denied, 0, 5))))];
        }
        $detail = sprintf(__('%d Zugänge geprüft, alle freigeschaltet', 'bootspruefung-learning'), count($rows));
        if ($orphans > 0) {
            return [self::row($label, 'warn', $detail.' · '.sprintf(__('%d aktive Zugänge gehören zu Nutzern, die es nicht gibt (Tabelle wp_users nicht aus demselben Backup?).', 'bootspruefung-learning'), $orphans))];
        }

        return [self::row($label, 'ok', $detail)];
    }

    /**
     * Zugänge aus Bestellungen: gibt es die Bestellungen (WooCommerce-Daten im Backup)? Beendete Zugänge (revoked)
     * zählen nicht – wer eine Bestellung endgültig löscht, beendet damit auch den Zugang.
     * @return list<array{check:string,status:string,detail:string}>
     */
    private static function orders(): array
    {
        global $wpdb;
        $label = __('WooCommerce-Bestellungen', 'bootspruefung-learning');
        $withOrder = (int) $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}bp_entitlement WHERE order_id IS NOT NULL AND order_id > 0 AND status <> 'revoked'");
        if ($withOrder === 0) {
            return [self::row($label, 'ok', __('keine Zugänge aus Bestellungen', 'bootspruefung-learning'))];
        }
        if (! function_exists('WC')) {
            return [self::row($label, 'warn', sprintf(__('%d Bestellungen mit Lernzugang, aber WooCommerce ist nicht aktiv.', 'bootspruefung-learning'), $withOrder))];
        }
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $join = $hpos
            ? "LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id = e.order_id"
            : "LEFT JOIN {$wpdb->posts} o ON o.ID = e.order_id AND o.post_type IN ('shop_order', 'shop_order_placehold')";
        $idCol = $hpos ? 'o.id' : 'o.ID';
        $missing = $wpdb->get_col("SELECT DISTINCT e.order_id FROM {$wpdb->prefix}bp_entitlement e {$join} WHERE e.order_id IS NOT NULL AND e.order_id > 0 AND e.status <> 'revoked' AND {$idCol} IS NULL ORDER BY e.order_id LIMIT 50");
        if ($missing !== []) {
            return [self::row($label, 'fail', sprintf(__('%1$d von %2$d Bestellungen mit Lernzugang fehlen (z. B. #%3$s). WooCommerce-Tabellen (%4$s) gehören ins selbe Backup.', 'bootspruefung-learning'), count($missing), $withOrder, implode(', #', array_slice($missing, 0, 5)), $hpos ? 'wc_orders*' : 'posts/postmeta'))];
        }

        return [self::row($label, 'ok', sprintf(__('%1$d Bestellungen mit Lernzugang vorhanden (%2$s)', 'bootspruefung-learning'), $withOrder, $hpos ? 'HPOS' : __('Beitragstabellen', 'bootspruefung-learning')))];
    }
}
