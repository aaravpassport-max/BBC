<?php
/**
 * BOS_DB — PDO wrapper replacing WordPress $wpdb.
 * All queries are parameterized. No raw string interpolation.
 */
class BOS_DB {
    private static ?PDO $pdo = null;
    public static string $prefix = 'bos_';

    public static function connect(): PDO {
        if (self::$pdo) return self::$pdo;

        $cfg = self::config();
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";

        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    private static function config(): array {
        $cfg_file = BOS_DATA_DIR . '/db.json';
        if (file_exists($cfg_file)) {
            return json_decode(file_get_contents($cfg_file), true);
        }
        // Defaults written by Electron on first launch
        return [
            'host' => '127.0.0.1',
            'port' => getenv('BOS_DB_PORT') ?: '3307',
            'name' => 'businessos',
            'user' => 'bosuser',
            'pass' => getenv('BOS_DB_PASS') ?: 'bospass_changeme',
        ];
    }

    /** Return table name with prefix */
    public static function t(string $name): string {
        return self::$prefix . $name;
    }

    /** Execute SELECT and return all rows */
    public static function get_results(string $sql, array $params = []): array {
        try {
            $st = self::connect()->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        } catch (PDOException $e) {
            self::log_error($e, $sql);
            return [];
        }
    }

    /** Execute SELECT and return single row */
    public static function get_row(string $sql, array $params = []): ?object {
        try {
            $st = self::connect()->prepare($sql);
            $st->execute($params);
            $row = $st->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            self::log_error($e, $sql);
            return null;
        }
    }

    /** Execute SELECT and return single value */
    public static function get_var(string $sql, array $params = []): mixed {
        try {
            $st = self::connect()->prepare($sql);
            $st->execute($params);
            $val = $st->fetchColumn();
            return $val === false ? null : $val;
        } catch (PDOException $e) {
            self::log_error($e, $sql);
            return null;
        }
    }

    /** Execute INSERT, return inserted row ID */
    public static function insert(string $table, array $data): int|false {
        try {
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $st = self::connect()->prepare("INSERT INTO `$table` ($cols) VALUES ($placeholders)");
            $st->execute(array_values($data));
            return (int) self::connect()->lastInsertId();
        } catch (PDOException $e) {
            self::log_error($e, "INSERT INTO $table");
            return false;
        }
    }

    /** Execute UPDATE, return rows affected */
    public static function update(string $table, array $data, array $where): int|false {
        try {
            $set   = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
            $cond  = implode(' AND ', array_map(fn($k) => "`$k` = ?", array_keys($where)));
            $st    = self::connect()->prepare("UPDATE `$table` SET $set WHERE $cond");
            $st->execute([...array_values($data), ...array_values($where)]);
            return $st->rowCount();
        } catch (PDOException $e) {
            self::log_error($e, "UPDATE $table");
            return false;
        }
    }

    /** Execute DELETE, return rows affected */
    public static function delete(string $table, array $where): int|false {
        try {
            $cond = implode(' AND ', array_map(fn($k) => "`$k` = ?", array_keys($where)));
            $st   = self::connect()->prepare("DELETE FROM `$table` WHERE $cond");
            $st->execute(array_values($where));
            return $st->rowCount();
        } catch (PDOException $e) {
            self::log_error($e, "DELETE FROM $table");
            return false;
        }
    }

    /** Execute arbitrary SQL (DDL, complex queries) */
    public static function query(string $sql, array $params = []): bool {
        try {
            $st = self::connect()->prepare($sql);
            return $st->execute($params);
        } catch (PDOException $e) {
            self::log_error($e, $sql);
            return false;
        }
    }

    /** Begin transaction */
    public static function begin(): void { self::connect()->beginTransaction(); }
    public static function commit(): void { self::connect()->commit(); }
    public static function rollback(): void {
        if (self::connect()->inTransaction()) self::connect()->rollBack();
    }

    /** Get a setting value from bos_settings table */
    public static function get_setting(string $key, string $default = ''): string {
        $val = self::get_var(
            'SELECT setting_value FROM ' . self::t('settings') . ' WHERE setting_key = ?',
            [$key]
        );
        return $val ?? $default;
    }

    /** Set a setting value */
    public static function set_setting(string $key, string $value): void {
        $exists = self::get_var(
            'SELECT id FROM ' . self::t('settings') . ' WHERE setting_key = ?', [$key]
        );
        if ($exists) {
            self::update(self::t('settings'), ['setting_value' => $value], ['setting_key' => $key]);
        } else {
            self::insert(self::t('settings'), ['setting_key' => $key, 'setting_value' => $value]);
        }
    }

    private static function log_error(PDOException $e, string $context): void {
        error_log('[BOS_DB] ' . $context . ' — ' . $e->getMessage());
    }
}
