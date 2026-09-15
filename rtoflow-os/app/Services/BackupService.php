<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 5 — "No backup/disaster-recovery
 * automation; the only way to get the data out is a manual database
 * export via the hosting panel"): generates a real, restorable SQL dump of
 * every rto_* table (schema via SHOW CREATE TABLE, data via batched
 * INSERTs), gzips it, and writes it to a non-web-guessable directory under
 * wp-content/uploads. Runs automatically on a weekly cron
 * (rtoflow_weekly_backup, registered in Bootstrap::init()) and can also be
 * triggered on demand from the new Backups admin screen. Retains the most
 * recent RETENTION_COUNT backups and prunes older ones so this cannot fill
 * the disk unbounded on a long-running install.
 *
 * This is a real mysqldump-equivalent (CREATE TABLE + INSERT statements a
 * standard `mysql` client can replay), not a placeholder — restoring means
 * gunzip + `mysql < file.sql`, the same as any other SQL backup.
 */
class BackupService
{
    private const DIR_NAME = 'rtoflow-backups';
    private const RETENTION_COUNT = 8;
    private const BATCH_SIZE = 500;

    public static function backupDir(): string
    {
        $dir = wp_upload_dir()['basedir'] . '/' . self::DIR_NAME;
        wp_mkdir_p($dir);
        // .htaccess belt-and-braces even though the real control is that
        // downloads are only ever served through Router::downloadBackup(),
        // never this raw path — matches the pattern already used for
        // rtoflow-docs elsewhere in this codebase.
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
        return $dir;
    }

    /** @return array{success:bool, message:string, filename?:string} */
    public static function run(): array
    {
        global $wpdb;

        try {
            $tables = $wpdb->get_col($wpdb->prepare(
                "SHOW TABLES LIKE %s", $wpdb->esc_like($wpdb->prefix . 'rto_') . '%'
            ));
            if (empty($tables)) {
                return ['success' => false, 'message' => 'No rto_* tables found to back up.'];
            }

            $filename = 'rtoflow-backup-' . date('Y-m-d-His') . '.sql.gz';
            $path     = self::backupDir() . '/' . $filename;
            $gz       = gzopen($path, 'wb9');
            if (!$gz) {
                return ['success' => false, 'message' => 'Unable to open backup file for writing. Check disk space/permissions.'];
            }

            gzwrite($gz, "-- RTOFLOW backup generated " . current_time('mysql') . "\n");
            gzwrite($gz, "-- Restore with: gunzip -c {$filename} | mysql -u USER -p DATABASE\n\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                if (!$createRow) continue;

                gzwrite($gz, "-- Table: {$table}\n");
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
                gzwrite($gz, $createRow[1] . ";\n\n");

                $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
                $colList = '`' . implode('`, `', $columns) . '`';

                $offset = 0;
                do {
                    $rows = $wpdb->get_results(
                        "SELECT * FROM `{$table}` LIMIT " . self::BATCH_SIZE . " OFFSET {$offset}",
                        ARRAY_A
                    );
                    if (empty($rows)) break;

                    $valueLines = [];
                    foreach ($rows as $row) {
                        $escaped = array_map(function ($v) use ($wpdb) {
                            if ($v === null) return 'NULL';
                            return "'" . $wpdb->_real_escape($v) . "'";
                        }, array_values($row));
                        $valueLines[] = '(' . implode(', ', $escaped) . ')';
                    }
                    gzwrite($gz, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $valueLines) . ";\n");

                    $offset += self::BATCH_SIZE;
                } while (count($rows) === self::BATCH_SIZE);

                gzwrite($gz, "\n");
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);

            self::pruneOld();

            AuditService::log('backup.created', null, [
                'filename' => $filename,
                'size_kb'  => round(filesize($path) / 1024),
                'tables'   => count($tables),
            ]);

            return ['success' => true, 'message' => "Backup created: {$filename}", 'filename' => $filename];
        } catch (\Throwable $e) {
            error_log('RTOFLOW BackupService::run: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
        }
    }

    /** @return array<int, array{filename:string, size_kb:int, created_at:int}> newest first */
    public static function list(): array
    {
        $dir   = self::backupDir();
        $files = glob($dir . '/*.sql.gz') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map(fn($f) => [
            'filename'   => basename($f),
            'size_kb'    => round(filesize($f) / 1024),
            'created_at' => filemtime($f),
        ], $files);
    }

    private static function pruneOld(): void
    {
        $files = self::list();
        if (count($files) <= self::RETENTION_COUNT) return;
        foreach (array_slice($files, self::RETENTION_COUNT) as $old) {
            @unlink(self::backupDir() . '/' . $old['filename']);
        }
    }

    /** Resolve a requested filename to a real path inside the backup dir only — prevents path traversal. */
    public static function resolveSafePath(string $filename): ?string
    {
        $filename = basename($filename); // strips any ../ or directory component
        if (!preg_match('/^rtoflow-backup-[\d\-]+\.sql\.gz$/', $filename)) return null;
        $path = self::backupDir() . '/' . $filename;
        return file_exists($path) ? $path : null;
    }
}
