<?php

namespace RTOFLOW\Database;

if (!defined('ABSPATH')) exit;

/**
 * Base Migration Class
 *
 * All migration classes extend this and implement up() and down().
 */
abstract class Migration
{
    protected function run(string $sql): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Use dbDelta for CREATE TABLE (handles column additions gracefully)
        if (stripos(ltrim($sql), 'CREATE TABLE') === 0) {
            dbDelta($sql);
            return;
        }

        // Direct query for ALTER TABLE, DROP TABLE, CREATE INDEX, etc.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException("Migration SQL failed: " . $wpdb->last_error . "\nSQL: " . $sql);
        }
    }

    protected function addColumn(string $table, string $column, string $definition): void
    {
        global $wpdb;
        $fullTable = $wpdb->prefix . $table;
        $exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$fullTable}` LIKE %s",
            $column
        ));
        if (empty($exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$fullTable}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    protected function dropColumn(string $table, string $column): void
    {
        global $wpdb;
        $fullTable = $wpdb->prefix . $table;
        $exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$fullTable}` LIKE %s",
            $column
        ));
        if (!empty($exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$fullTable}` DROP COLUMN `{$column}`");
        }
    }

    protected function addIndex(string $table, string $indexName, string $column, bool $unique = false): void
    {
        global $wpdb;
        $fullTable = $wpdb->prefix . $table;
        $exists = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM `{$fullTable}` WHERE Key_name = %s",
            $indexName
        ));
        if (empty($exists)) {
            $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$fullTable}` ADD {$type} `{$indexName}` ({$column})");
        }
    }

    protected function dropIndex(string $table, string $indexName): void
    {
        global $wpdb;
        $fullTable = $wpdb->prefix . $table;
        $exists = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM `{$fullTable}` WHERE Key_name = %s",
            $indexName
        ));
        if (!empty($exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$fullTable}` DROP INDEX `{$indexName}`");
        }
    }

    protected function tableExists(string $table): bool
    {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->prefix . $table
        ));
    }

    /** Shorthand for the WP table prefix */
    protected function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    abstract public function up(): void;
    abstract public function down(): void;
}
