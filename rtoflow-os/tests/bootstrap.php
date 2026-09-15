<?php
/**
 * PHPUnit bootstrap for the Unit test suite.
 *
 * This project has no real WordPress runtime available in CI/sandbox for
 * unit tests (that's what tests/Integration/ is for, see its README.md).
 * To let pure-logic classes under app/Config and app/Services be exercised
 * without a full WP install, this file defines the minimal handful of
 * WordPress functions/constants those classes actually call, backed by a
 * simple in-memory option store. It intentionally does NOT attempt to
 * emulate WordPress in general — only get_option()/update_option()/
 * wp_json_encode() and the ABSPATH/RTOFLOW_DIR constants that
 * app/Config/*.php and app/Security/Encryption.php need to load at all.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('RTOFLOW_DIR')) {
    define('RTOFLOW_DIR', dirname(__DIR__) . '/');
}

// ── Minimal in-memory wp_options store ──────────────────────────────────────
// Real WordPress persists options in the database; for unit tests we only
// need get_option()/update_option() to behave consistently within a single
// test run so FeatureFlags/MatchingConfig/Encryption can read back what they
// just wrote.
if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['__rtoflow_test_options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['__rtoflow_test_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        unset($GLOBALS['__rtoflow_test_options'][$key]);
        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($data, $flags, $depth);
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql'): string|int
    {
        return $type === 'timestamp' ? time() : date('Y-m-d H:i:s');
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 0; }
}
if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void {}
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, ...$args): mixed { return $value; }
}
if (!function_exists('get_user_by')) {
    function get_user_by(string $field, mixed $value): bool { return false; }
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('wpdb')) {
    // Real WordPress's wpdb base class -- tests never load WP core, so any
    // class that type-hints \wpdb (e.g. WorkflowEngineService's constructor,
    // GdprService::hardDeleteLeadTree()'s parameter) needs SOMETHING to
    // `instanceof`/type-check against. This intentionally has no behavior;
    // FakeWpdb below is what tests actually construct and pass in.
    class wpdb {}
}

if (!class_exists('RTOFLOW\Tests\FakeWpdb')) {
    /**
     * Shared in-memory fake $wpdb for unit tests that exercise real
     * database-touching code (WorkflowEngineService, GdprService,
     * TdsService) without a live MySQL connection. Table contents are
     * plain PHP arrays a test populates directly; queries are pattern-
     * matched on table name + a handful of common WHERE shapes rather than
     * parsed as real SQL — deliberately minimal, the same tradeoff this
     * codebase's /tmp/verify_*.php real-execution harnesses already made
     * and proved sufficient during the gap-fix engagement.
     */
    class FakeWpdb extends \wpdb
    {
        public string $prefix = 'wp_';
        public string $usermeta = 'wp_usermeta';
        /** @var array<string, array<int, array<string,mixed>>> */
        public array $tables = [];
        public array $queries = [];
        public int $insert_id = 1;

        public function prepare(string $query, mixed ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            foreach ($args as $v) {
                $repl = is_string($v) ? "'" . addslashes($v) . "'" : (string)$v;
                $query = preg_replace('/%[dsf]/', $repl, $query, 1);
            }
            return $query;
        }

        public function esc_like(string $v): string { return $v; }

        private function matchTable(string $sql): ?string
        {
            foreach (array_keys($this->tables) as $t) {
                if (str_contains($sql, $t)) return $t;
            }
            return null;
        }

        public function get_row(string $sql, string $output = ARRAY_A): ?array
        {
            $this->queries[] = $sql;
            $t = $this->matchTable($sql);
            return $t ? ($this->tables[$t][0] ?? null) : null;
        }

        public function get_results(string $sql, string $output = ARRAY_A): array
        {
            $this->queries[] = $sql;
            $t = $this->matchTable($sql);
            return $t ? ($this->tables[$t] ?? []) : [];
        }

        public function get_var(string $sql): mixed
        {
            $this->queries[] = $sql;
            $t = $this->matchTable($sql);
            return $t ? count($this->tables[$t] ?? []) : 0;
        }

        public function query(string $sql): bool
        {
            $this->queries[] = $sql;
            return true;
        }

        public function insert(string $table, array $data): bool
        {
            $this->queries[] = "INSERT $table";
            $this->tables[$table][] = $data;
            return true;
        }

        public function update(string $table, array $data, array $where): int
        {
            $this->queries[] = "UPDATE $table";
            $matched = 0;
            foreach ($this->tables[$table] ?? [] as &$row) {
                $isMatch = true;
                foreach ($where as $k => $v) { if (($row[$k] ?? null) != $v) { $isMatch = false; break; } }
                if ($isMatch) { $row = array_merge($row, $data); $matched++; }
            }
            return $matched;
        }

        public function delete(string $table, array $where): bool
        {
            $this->queries[] = "DELETE $table";
            $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], function ($row) use ($where) {
                foreach ($where as $k => $v) { if (($row[$k] ?? null) != $v) return true; }
                return false;
            }));
            return true;
        }
    }
}

// Reset the in-memory option store before each test file's class is loaded.
// Individual tests that care about isolation should also clear this in
// setUp() — see tests/Unit/Config/*Test.php.
$GLOBALS['__rtoflow_test_options'] = [];

require dirname(__DIR__) . '/vendor/autoload.php';
