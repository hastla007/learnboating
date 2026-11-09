<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure\Database;

use RuntimeException;
use wpdb;

/**
 * Legt Tabellen per dbDelta() an und ergänzt Fremdschlüssel in eigenen,
 * idempotenten Schritten. Läuft bei Aktivierung und bei Versionswechsel,
 * geschützt durch eine Sperre gegen Parallelausführung.
 */
final class Migrator
{
    public const SCHEMA_VERSION = '1.10.0';
    public const OPTION_VERSION = 'bp_learning_schema_version';
    private const OPTION_LOCK = 'bp_learning_migration_lock';
    private const LOCK_TTL = 300;

    public function __construct(private readonly wpdb $db)
    {
    }

    public function schema(): Schema
    {
        return new Schema($this->db->prefix, $this->db->get_charset_collate());
    }

    public function installedVersion(): ?string
    {
        $version = get_option(self::OPTION_VERSION);

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function needsMigration(): bool
    {
        return $this->installedVersion() !== self::SCHEMA_VERSION;
    }

    /** Führt fällige Migrationen aus. @return bool false, wenn ein anderer Lauf die Sperre hält. */
    public function migrate(): bool
    {
        if (! $this->acquireLock()) {
            return false;
        }

        try {
            $this->assertInnoDb();
            require_once ABSPATH.'wp-admin/includes/upgrade.php';

            $schema = $this->schema();
            foreach ($schema->definitions() as $sql) {
                dbDelta($sql);
            }
            foreach (Schema::TABLES as $table) {
                if (! $this->tableExists($schema->table($table))) {
                    throw new RuntimeException(sprintf('Tabelle %s konnte nicht angelegt werden: %s', $schema->table($table), $this->db->last_error));
                }
            }
            $this->addForeignKeys($schema);

            update_option(self::OPTION_VERSION, self::SCHEMA_VERSION, true);
        } finally {
            $this->releaseLock();
        }

        return true;
    }

    /** Status der Tabellen für die Systemstatus-Seite. @return array<string,array{exists:bool,engine:?string,rows:?int}> */
    public function tableStatus(): array
    {
        $schema = $this->schema();
        $status = [];
        foreach (Schema::TABLES as $table) {
            $name = $schema->table($table);
            $row = $this->db->get_row($this->db->prepare(
                'SELECT ENGINE AS engine, TABLE_ROWS AS table_rows FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $name
            ));
            $status[$table] = [
                'exists' => $row !== null,
                'engine' => $row->engine ?? null,
                'rows' => isset($row->table_rows) ? (int) $row->table_rows : null,
            ];
        }

        return $status;
    }

    /** Anzahl angelegter Fremdschlüssel dieses Plugins. */
    public function foreignKeyCount(): int
    {
        return (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME LIKE %s",
            $this->db->esc_like($this->db->prefix.'bpfk_').'%'
        ));
    }

    /** Löscht alle Plugin-Tabellen (nur Deinstallation mit ausdrücklicher Einstellung). */
    public function dropAll(): void
    {
        $schema = $this->schema();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(Schema::TABLES) as $table) {
            $this->db->query('DROP TABLE IF EXISTS `'.esc_sql($schema->table($table)).'`');
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        delete_option(self::OPTION_VERSION);
    }

    private function addForeignKeys(Schema $schema): void
    {
        foreach (Schema::foreignKeys() as [$table, $column, $target, $onDelete]) {
            $name = $this->constraintName($table, $column);
            $exists = (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                $schema->table($table),
                $name
            ));
            if ($exists > 0) {
                continue;
            }
            $sql = sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s',
                esc_sql($schema->table($table)),
                esc_sql($name),
                esc_sql($column),
                esc_sql($schema->table($target)),
                $onDelete
            );
            if ($this->db->query($sql) === false) {
                throw new RuntimeException(sprintf('Fremdschlüssel %s fehlgeschlagen: %s', $name, $this->db->last_error));
            }
        }
    }

    /** Namen sind datenbankweit eindeutig; das Tabellenpräfix trennt mehrere Installationen. */
    private function constraintName(string $table, string $column): string
    {
        $name = $this->db->prefix.'bpfk_'.$table.'_'.$column;
        if (strlen($name) > 64) {
            $name = $this->db->prefix.'bpfk_'.substr(md5($table.'.'.$column), 0, 16);
        }

        return $name;
    }

    private function assertInnoDb(): void
    {
        $engines = $this->db->get_col("SELECT ENGINE FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB' AND SUPPORT IN ('YES','DEFAULT')");
        if ($engines === []) {
            throw new RuntimeException('Die Datenbank unterstützt kein InnoDB. Das Lernplugin benötigt InnoDB für Transaktionen und Fremdschlüssel.');
        }
    }

    private function tableExists(string $name): bool
    {
        return $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($name))) === $name;
    }

    private function acquireLock(): bool
    {
        // add_option ist atomar (UNIQUE auf option_name) und dient als Sperre.
        if (add_option(self::OPTION_LOCK, (string) time(), '', false)) {
            return true;
        }
        $since = (int) get_option(self::OPTION_LOCK);
        if ($since > 0 && time() - $since > self::LOCK_TTL) {
            delete_option(self::OPTION_LOCK);

            return add_option(self::OPTION_LOCK, (string) time(), '', false);
        }

        return false;
    }

    private function releaseLock(): void
    {
        delete_option(self::OPTION_LOCK);
    }
}
