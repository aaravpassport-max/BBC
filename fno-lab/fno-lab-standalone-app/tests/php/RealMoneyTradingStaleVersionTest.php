<?php
/**
 * Real, deliberately isolated helper - run as its own, separate PHP
 * process specifically to verify the stale-plugin-version auto-disarm
 * branch inside fno_is_real_money_armed(), which is genuinely
 * unreachable when FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED is false
 * (the real, correct, production value) - that check runs first and
 * correctly short-circuits everything after it. PHP constants cannot
 * be redefined within one process, so verifying this specific,
 * narrower branch requires its own process where the constant is
 * defined as true ONLY here, never in the real, main test suite.
 */
function get_current_user_id() { return 1; }
function wp_salt($s = 'auth') { return 'test-fixed-salt-do-not-use-in-production-' . $s; }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED')) define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', true);

class FakeWpdbIsolated {
    public $prefix = 'wp_';
    public $updated = [];
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function prepare($q, ...$a) { return $q; }
    public function get_row($q, $o = null) { return $this->rows[0] ?? null; }
    public function update($t, $data, $where) { $this->updated[] = ['data' => $data]; return 1; }
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
preg_match("/define\('FNO_JOURNAL_SCHEMA_VERSION', '([^']+)'\)/", $pluginSource, $m);
define('FNO_JOURNAL_SCHEMA_VERSION', $m[1]);
$start = strpos($pluginSource, 'function fno_is_real_money_armed(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

global $wpdb;
$wpdb = new FakeWpdbIsolated([['id' => 4, 'user_id' => 1, 'is_armed' => 1, 'armed_plugin_version' => '0.0.1-old']]);
list($armed, $reason) = fno_is_real_money_armed(4, 1);

$pass1 = ($armed === false);
$pass2 = (count($wpdb->updated) === 1 && $wpdb->updated[0]['data']['is_armed'] === 0);
echo ($pass1 ? "  PASS" : "  FAIL") . "  a real, stale plugin version genuinely fails the armed check (isolated, master switch true)\n";
echo ($pass2 ? "  PASS" : "  FAIL") . "  a real, stale-version account is automatically, safely disarmed by this check, not left armed and merely blocked once (isolated, master switch true)\n";
exit(($pass1 && $pass2) ? 0 : 1);
