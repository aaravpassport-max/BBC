<?php

namespace RTOFLOW\Database;

if (!defined('ABSPATH')) exit;

/**
 * Database Migration Runner
 *
 * Versioned, ordered schema migrations with rollback support.
 * Each migration is a class in database/migrations/ with up() and down() methods.
 *
 * Migrations are tracked in wp_rto_migrations table.
 * Runs on plugin activation and when admin triggers manually.
 *
 * Usage:
 *   MigrationRunner::run();      // Apply all pending migrations
 *   MigrationRunner::rollback(); // Rollback last batch
 *   MigrationRunner::status();   // List all migrations and their status
 */
class MigrationRunner
{
    private const TABLE    = 'rto_migrations';
    // P1-ARCH-003 FIX: use method instead of class constant to avoid
    // fragile dependency on define() order at class-constant evaluation time
    private static function migsDir(): string
    {
        return defined('RTOFLOW_DIR')
            ? RTOFLOW_DIR . 'database/migrations/'
            : dirname(__DIR__, 2) . '/database/migrations/';
    }

    // ── Run all pending migrations ────────────────────────────────────────
    public static function run(): array
    {
        self::createTable();
        $pending = self::getPending();
        $results = [];

        if (empty($pending)) {
            return [['status' => 'info', 'message' => 'No pending migrations.']];
        }

        $batch = self::nextBatch();

        foreach ($pending as $migration) {
            try {
                $instance = self::load($migration);
                $instance->up();

                self::record($migration, $batch);
                $results[] = ['status' => 'success', 'migration' => $migration, 'message' => "Migrated: {$migration}"];
                error_log("RTOFLOW Migration: UP {$migration}");
            } catch (\Throwable $e) {
                $results[] = ['status' => 'error', 'migration' => $migration, 'message' => $e->getMessage()];
                error_log("RTOFLOW Migration ERROR [{$migration}]: " . $e->getMessage());
                break; // Stop on first failure
            }
        }

        return $results;
    }

    // ── Rollback last batch ───────────────────────────────────────────────
    public static function rollback(): array
    {
        self::createTable();
        $lastBatch  = self::lastBatch();
        if (!$lastBatch) {
            return [['status' => 'info', 'message' => 'Nothing to rollback.']];
        }

        $migrations = self::getBatch($lastBatch);
        $results    = [];

        foreach (array_reverse($migrations) as $migration) {
            try {
                $instance = self::load($migration);
                $instance->down();
                self::unrecord($migration);
                $results[] = ['status' => 'success', 'migration' => $migration, 'message' => "Rolled back: {$migration}"];
                error_log("RTOFLOW Migration: DOWN {$migration}");
            } catch (\Throwable $e) {
                $results[] = ['status' => 'error', 'migration' => $migration, 'message' => $e->getMessage()];
                break;
            }
        }

        return $results;
    }

    // ── Status ────────────────────────────────────────────────────────────
    public static function status(): array
    {
        self::createTable();
        $all     = self::allFiles();
        $ran     = self::getRan();
        $results = [];

        foreach ($all as $migration) {
            $results[] = [
                'migration' => $migration,
                'ran'       => in_array($migration, $ran, true),
                'batch'     => self::getBatchFor($migration),
            ];
        }

        return $results;
    }

    // ── Internal ──────────────────────────────────────────────────────────

    private static function createTable(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE . " (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration  VARCHAR(255) NOT NULL,
            batch      INT UNSIGNED NOT NULL,
            ran_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY migration (migration)
        ) {$c}");
    }

    private static function load(string $migration): Migration
    {
        $file = self::migsDir() . $migration . '.php';
        if (!file_exists($file)) {
            throw new \RuntimeException("Migration file not found: {$file}");
        }
        require_once $file;
        $class = 'RTOFLOW\\Database\\Migrations\\' . self::toClassName($migration);
        if (!class_exists($class)) {
            throw new \RuntimeException("Migration class not found: {$class}");
        }
        return new $class();
    }

    private static function allFiles(): array
    {
        if (!is_dir(self::migsDir())) return [];
        $files = glob(self::migsDir() . '*.php') ?: [];
        $names = array_map(fn($f) => basename($f, '.php'), $files);
        sort($names);
        return $names;
    }

    private static function getRan(): array
    {
        global $wpdb;
        return $wpdb->get_col("SELECT migration FROM {$wpdb->prefix}" . self::TABLE) ?: [];
    }

    private static function getPending(): array
    {
        return array_diff(self::allFiles(), self::getRan());
    }

    private static function getBatch(int $batch): array
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT migration FROM {$wpdb->prefix}" . self::TABLE . " WHERE batch = %d ORDER BY id DESC",
            $batch
        )) ?: [];
    }

    private static function getBatchFor(string $migration): ?int
    {
        global $wpdb;
        $batch = $wpdb->get_var($wpdb->prepare(
            "SELECT batch FROM {$wpdb->prefix}" . self::TABLE . " WHERE migration = %s",
            $migration
        ));
        return $batch !== null ? (int)$batch : null;
    }

    private static function nextBatch(): int
    {
        return (int)self::lastBatch() + 1;
    }

    private static function lastBatch(): int
    {
        global $wpdb;
        return (int)$wpdb->get_var("SELECT MAX(batch) FROM {$wpdb->prefix}" . self::TABLE);
    }

    private static function record(string $migration, int $batch): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::TABLE, [
            'migration' => $migration,
            'batch'     => $batch,
            'ran_at'    => current_time('mysql'),
        ]);
    }

    private static function unrecord(string $migration): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::TABLE, ['migration' => $migration]);
    }

    private static function toClassName(string $migration): string
    {
        // "2024_01_01_000001_create_core_tables" → "CreateCoreTables"
        $parts = explode('_', $migration);
        // Remove version prefix (year_month_day_seq)
        if (count($parts) > 4 && is_numeric($parts[0])) {
            $parts = array_slice($parts, 4);
        }
        return implode('', array_map('ucfirst', $parts));
    }
}
