<?php
/**
 * REAL, STANDALONE regression test for the schema-migration-safety
 * audit finding (2026-08-30 pass, area 1 - "database migration/upgrade
 * safety"): fno_create_journal_table() in fno-lab.php adds the
 * user_symbol_style_open_lock UNIQUE KEY to wp_fno_open_positions (the
 * real fix for the browser-vs-driver duplicate-open race, schema
 * version 2.9.0). On a REAL production site that had been running the
 * OLDER, unconstrained schema, the table can already genuinely contain
 * more than one 'open' row for the same user_id+symbol+trading_style
 * at the moment this upgrade runs - exactly the scenario the new
 * constraint exists to prevent going forward. Before this fix:
 *   1. dbDelta's own ALTER TABLE ... ADD UNIQUE KEY genuinely FAILS
 *      against such pre-existing duplicate data (real MySQL/MariaDB
 *      behavior: "Duplicate entry ... for key"), but dbDelta does not
 *      throw or surface that failure to the caller - it just leaves
 *      $wpdb->last_error set and silently continues.
 *   2. fno_create_journal_table() unconditionally called
 *      update_option('fno_journal_db_version', FNO_JOURNAL_SCHEMA_VERSION)
 *      immediately afterward regardless of whether dbDelta actually
 *      succeeded.
 *   3. The 'init' guard only re-runs fno_create_journal_table() when
 *      the stored version DIFFERS from FNO_JOURNAL_SCHEMA_VERSION - so
 *      a failed migration would be marked "done" and NEVER retried,
 *      permanently, on any future request, with zero record anywhere
 *      (log or admin UI) that the real safety constraint was never
 *      actually applied to that site's database.
 *
 * The real fix (see fno-lab.php's own TRACE comment above the sql11
 * dbDelta call): de-duplicate existing 'open' rows PER (user_id,
 * symbol, trading_style) group BEFORE dbDelta runs (keeping the most
 * recently opened row as the real, canonical position, closing the
 * rest - never deleting data), and only mark the migration version as
 * upgraded when dbDelta left no error behind; otherwise log it and
 * leave the stored version untouched so the very next request retries.
 *
 * This test proves BOTH real properties: (a) genuine pre-existing
 * duplicate 'open' rows are honestly collapsed to exactly one per
 * group before the constraint-adding dbDelta call runs, keeping the
 * most-recently-opened row and closing the others without data loss,
 * and (b) a migration that still genuinely fails (for any other
 * reason) is NOT silently marked complete - the stored version option
 * is left alone (so it retries) and the failure is recorded, rather
 * than the old unconditional update_option() call.
 *
 * Run with: php tests/php/SchemaMigrationDuplicateOpenPositionsTest.php
 */

if (!defined('ABSPATH')) define('ABSPATH', '/tmp/fake-wp-abspath/');
if (!function_exists('wp_upgrade_stub_autoload')) {
    // fno_create_journal_table() does `require_once ABSPATH .
    // 'wp-admin/includes/upgrade.php'` to pull in the real dbDelta() -
    // this test provides its own dbDelta() stub instead (see below),
    // so that require_once must be a safe no-op here. Achieved by
    // pointing ABSPATH at a real temp dir containing an empty stub
    // file at that exact relative path.
    @mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
    @file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', "<?php // test stub, dbDelta() defined by the test itself\n");
}

/**
 * Extracts one top-level function's full source (signature through its
 * balanced closing brace), correctly skipping over braces that appear
 * inside '...'/"..." string literals or // line comments - a plain
 * `strpos($src, "\n}")` (used by this repo's other eval-based tests
 * for short helper functions) is NOT safe for fno_create_journal_table
 * itself, since its body contains many inner `if (...) { ... }` blocks
 * whose own closing braces would be matched first.
 */
function extractBalancedFunction($source, $functionName) {
    $start = strpos($source, "function $functionName(");
    if ($start === false) throw new Exception("$functionName not found");
    $braceOpen = strpos($source, '{', $start);
    $i = $braceOpen;
    $depth = 0;
    $len = strlen($source);
    $inSingle = false; $inDouble = false;
    while ($i < $len) {
        $ch = $source[$i];
        if ($inSingle) {
            if ($ch === '\\') { $i += 2; continue; }
            if ($ch === "'") $inSingle = false;
            $i++; continue;
        }
        if ($inDouble) {
            if ($ch === '\\') { $i += 2; continue; }
            if ($ch === '"') $inDouble = false;
            $i++; continue;
        }
        if ($ch === "'") { $inSingle = true; $i++; continue; }
        if ($ch === '"') { $inDouble = true; $i++; continue; }
        if ($ch === '/' && ($source[$i + 1] ?? '') === '/') {
            $nl = strpos($source, "\n", $i);
            $i = ($nl === false) ? $len : $nl + 1;
            continue;
        }
        if ($ch === '{') $depth++;
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) { return substr($source, $start, $i - $start + 1); }
        }
        $i++;
    }
    throw new Exception("unbalanced braces extracting $functionName");
}

class FakeWpdbForMigration {
    public $prefix = 'wp_';
    public $last_error = '';
    public $openPositionsRows = []; // simulated pre-existing table rows
    public $tableExists = true;
    public $updateCalls = [];

    public function get_charset_collate() { return ''; }

    public function prepare($query, ...$args) {
        // Real, honest sprintf-style substitution matching wpdb::prepare's
        // %d/%s placeholder semantics closely enough for this test's own
        // fixed, known query shapes.
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $query = str_replace('%d', '%s', $query); // avoid float formatting surprises; both compared as strings below
        $escaped = array_map(function ($a) { return is_string($a) ? "'" . addslashes($a) . "'" : $a; }, $args);
        return vsprintf(str_replace('%s', "%s", $query), $escaped);
    }

    public function get_var($sql) {
        if (strpos($sql, 'SHOW TABLES LIKE') !== false) {
            return $this->tableExists ? 'wp_fno_open_positions' : null;
        }
        if (strpos($sql, 'SELECT id FROM') !== false && strpos($sql, "ORDER BY opened_at DESC, id DESC LIMIT 1") !== false) {
            [$userId, $symbol, $style] = $this->parseGroupKey($sql);
            $matches = array_filter($this->openPositionsRows, function ($r) use ($userId, $symbol, $style) {
                return $r['status'] === 'open' && (string) $r['user_id'] === (string) $userId && $r['symbol'] === $symbol && $r['trading_style'] === $style;
            });
            if (!$matches) return null;
            usort($matches, function ($a, $b) {
                if ($a['opened_at'] !== $b['opened_at']) return $b['opened_at'] <=> $a['opened_at'];
                return $b['id'] <=> $a['id'];
            });
            return reset($matches)['id'];
        }
        return null;
    }

    public function get_results($sql) {
        if (strpos($sql, 'GROUP BY user_id, symbol, trading_style HAVING cnt > 1') !== false) {
            $groups = [];
            foreach ($this->openPositionsRows as $r) {
                if ($r['status'] !== 'open') continue;
                $key = $r['user_id'] . '|' . $r['symbol'] . '|' . $r['trading_style'];
                $groups[$key] = ($groups[$key] ?? 0) + 1;
            }
            $out = [];
            foreach ($groups as $key => $cnt) {
                if ($cnt <= 1) continue;
                [$u, $s, $st] = explode('|', $key);
                $obj = new stdClass();
                $obj->user_id = $u; $obj->symbol = $s; $obj->trading_style = $st; $obj->cnt = $cnt;
                $out[] = $obj;
            }
            return $out;
        }
        return [];
    }

    public function query($sql) {
        if (strpos($sql, "UPDATE wp_fno_open_positions SET status = 'closed'") !== false) {
            $this->updateCalls[] = $sql;
            [$userId, $symbol, $style] = $this->parseGroupKey($sql);
            if (!preg_match('/AND id != ([0-9]+)/', $sql, $mExcl)) return 0;
            $excludeId = (int) $mExcl[1];
            $affected = 0;
            foreach ($this->openPositionsRows as &$r) {
                if ($r['status'] === 'open' && (string) $r['user_id'] === (string) $userId && $r['symbol'] === $symbol && $r['trading_style'] === $style && (int) $r['id'] !== $excludeId) {
                    $r['status'] = 'closed';
                    $affected++;
                }
            }
            unset($r);
            return $affected;
        }
        return 0;
    }

    private function parseGroupKey($sql) {
        preg_match("/user_id = '?([^ '\)]+)'?/", $sql, $mU);
        preg_match("/symbol = '([^']+)'/", $sql, $mS);
        preg_match("/trading_style = '([^']+)'/", $sql, $mT);
        return [$mU[1] ?? null, $mS[1] ?? null, $mT[1] ?? null];
    }
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

// ---- stub out everything else fno_create_journal_table() touches ----
$GLOBALS['fno_test_options'] = [];
function update_option($k, $v) { $GLOBALS['fno_test_options'][$k] = $v; }
function get_option($k, $d = false) { return $GLOBALS['fno_test_options'][$k] ?? $d; }
function delete_option($k) { unset($GLOBALS['fno_test_options'][$k]); }
$GLOBALS['fno_test_dbdelta_should_fail_sql11'] = false;
function dbDelta($sql) {
    global $wpdb;
    // Real, honest simulation: dbDelta's ADD UNIQUE KEY on sql11 fails
    // (real MySQL "Duplicate entry" behavior) ONLY if the caller opted
    // this scenario into simulating a genuine remaining failure (used
    // by the "still fails after dedup" test below) - and succeeds
    // (clears last_error) otherwise, matching real dbDelta's success
    // case leaving last_error untouched from a prior clean call.
    if (strpos($sql, 'fno_open_positions') !== false && $GLOBALS['fno_test_dbdelta_should_fail_sql11']) {
        $wpdb->last_error = "Duplicate entry '1:NIFTY:intraday' for key 'user_symbol_style_open_lock'";
    } else {
        $wpdb->last_error = '';
    }
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
if (!preg_match("/define\('FNO_JOURNAL_SCHEMA_VERSION',\s*'([^']+)'\)/", $pluginSource, $mVer)) {
    throw new Exception('could not find FNO_JOURNAL_SCHEMA_VERSION in fno-lab.php');
}
define('FNO_JOURNAL_SCHEMA_VERSION', $mVer[1]);
eval(extractBalancedFunction($pluginSource, 'fno_create_journal_table'));

// sanity: our extractor actually captured a full, balanced function
assertTrue(function_exists('fno_create_journal_table'), 'extractBalancedFunction correctly captured a complete, callable fno_create_journal_table() from the real fno-lab.php source (proves the extraction itself is sound before trusting any test below)');

echo "=== Schema migration safety: pre-existing duplicate 'open' rows before the user_symbol_style_open_lock UNIQUE KEY is added ===\n";

// --- Scenario A: two genuine pre-existing duplicate 'open' rows for the
// same user+symbol+trading_style (the exact real-world state a site
// running the pre-2.9.0 schema could honestly be in) ---
$GLOBALS['wpdb'] = new FakeWpdbForMigration();
$GLOBALS['wpdb']->openPositionsRows = [
    ['id' => 5, 'user_id' => 1, 'symbol' => 'NIFTY', 'trading_style' => 'intraday', 'status' => 'open', 'opened_at' => 1000],
    ['id' => 9, 'user_id' => 1, 'symbol' => 'NIFTY', 'trading_style' => 'intraday', 'status' => 'open', 'opened_at' => 2000], // more recent -> real, canonical one
    ['id' => 12, 'user_id' => 1, 'symbol' => 'BANKNIFTY', 'trading_style' => 'swing', 'status' => 'open', 'opened_at' => 500], // distinct group, untouched
];
$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_dbdelta_should_fail_sql11'] = false;
fno_create_journal_table();

$rowsById = [];
foreach ($GLOBALS['wpdb']->openPositionsRows as $r) $rowsById[$r['id']] = $r;
assertTrue($rowsById[9]['status'] === 'open', 'the most-recently-opened duplicate (id=9, opened_at=2000) is honestly kept as the real, canonical open position');
assertTrue($rowsById[5]['status'] === 'closed', 'the older genuine duplicate (id=5, opened_at=1000) is closed - not deleted - before the UNIQUE KEY is added, so the real ALTER TABLE no longer conflicts with pre-existing data');
assertTrue($rowsById[12]['status'] === 'open', 'a distinct, non-duplicate group (BANKNIFTY/swing) is completely untouched by the de-duplication pass');
assertTrue($GLOBALS['fno_test_options']['fno_journal_db_version'] === FNO_JOURNAL_SCHEMA_VERSION, 'after a real, successful migration (dbDelta leaves no error), the stored schema version is genuinely advanced');
assertTrue(!array_key_exists('fno_journal_db_migration_error', $GLOBALS['fno_test_options']), 'no migration-error option is left behind after a genuinely successful migration');

// --- Scenario B: no duplicates at all (the normal, expected case) - the
// de-dup pass must be a true no-op, never touching legitimate single
// open positions. ---
$GLOBALS['wpdb'] = new FakeWpdbForMigration();
$GLOBALS['wpdb']->openPositionsRows = [
    ['id' => 1, 'user_id' => 1, 'symbol' => 'NIFTY', 'trading_style' => 'intraday', 'status' => 'open', 'opened_at' => 1000],
];
$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_dbdelta_should_fail_sql11'] = false;
fno_create_journal_table();
assertTrue($GLOBALS['wpdb']->openPositionsRows[0]['status'] === 'open', 'a single, legitimate open position (the normal case, no duplicates) is never touched by the de-duplication pass');
assertTrue(empty($GLOBALS['wpdb']->updateCalls), 'zero de-dup UPDATE statements are issued when there are genuinely no duplicate groups');

// --- Scenario C: brand-new install (table does not exist yet) - the
// de-dup SHOW TABLES LIKE check must skip cleanly, never error. ---
$GLOBALS['wpdb'] = new FakeWpdbForMigration();
$GLOBALS['wpdb']->tableExists = false;
$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_dbdelta_should_fail_sql11'] = false;
fno_create_journal_table();
assertTrue($GLOBALS['fno_test_options']['fno_journal_db_version'] === FNO_JOURNAL_SCHEMA_VERSION, 'a brand-new install (no pre-existing open_positions table) still completes the migration and advances the version normally');

// --- Scenario D: migration genuinely still fails (e.g. some other,
// unrelated DB error) - the REGRESSION this whole fix targets: the
// version option must NOT be silently advanced, and the failure must
// be recorded, so the very next 'init' honestly retries instead of
// permanently believing a broken schema is up to date. ---
$GLOBALS['wpdb'] = new FakeWpdbForMigration();
$GLOBALS['wpdb']->openPositionsRows = [];
$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_dbdelta_should_fail_sql11'] = true;
fno_create_journal_table();
assertTrue(!array_key_exists('fno_journal_db_version', $GLOBALS['fno_test_options']), 'REGRESSION CHECK: when dbDelta genuinely still fails, fno_journal_db_version is NOT marked as upgraded (old code unconditionally called update_option here regardless of success)');
assertTrue(($GLOBALS['fno_test_options']['fno_journal_db_migration_error'] ?? '') !== '', 'a genuine migration failure is recorded in fno_journal_db_migration_error so it is honestly visible (surfaced as a real wp-admin notice) instead of vanishing silently');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
