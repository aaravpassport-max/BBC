<?php
/**
 * IMPORTANT - READ BEFORE TRUSTING ANY RESULT FROM THIS FILE:
 *
 * This test file was written but NEVER EXECUTED. The sandbox this project
 * was built in has no PHP runtime (`apt-get install php-cli` failed - the
 * noble-updates PHP 8.3 packages 404'd from archive.ubuntu.com at the time
 * this was written) and no MySQL/WordPress test-suite scaffold. Per this
 * project's own audit standard: a claim of PASS requires evidence read in
 * THIS session - since these tests were never run, every one of them is
 * UNVERIFIED, not PASS, until someone runs them for real.
 *
 * To actually run this file:
 *   1. Set up the WordPress PHPUnit test scaffold:
 *      https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/
 *      (wp scaffold plugin-tests fno-lab-standalone-app)
 *   2. composer require --dev yoast/phpunit-polyfills wp-phpunit/wp-phpunit
 *   3. Copy this file into the scaffolded tests/ directory (or point
 *      phpunit.xml at tests/php/ in this repo)
 *   4. vendor/bin/phpunit
 *
 * Every test below is written to WordPress's real WP_UnitTestCase
 * conventions (factory users, $wpdb against a real test DB, wp_set_current_user)
 * specifically so it requires NO changes to become runnable - only a real
 * PHP+MySQL+WP-test-suite environment, which this sandbox does not have.
 */

class FnoJournalTest extends WP_UnitTestCase {

    private $table;

    public function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->table = $wpdb->prefix . 'fno_journal';
        fno_create_journal_table(); // idempotent - see fno-lab.php TRACE comment
    }

    /** Anonymous users must be rejected, not silently allowed. */
    public function test_journal_add_requires_login() {
        wp_set_current_user(0);
        $_POST = ['pnl' => '100', 'nonce' => wp_create_nonce('fno_standalone_nonce')];
        try {
            fno_journal_add_fn();
            $this->fail('Expected wp_die() from wp_send_json_error, none occurred');
        } catch (WPDieException $e) {
            $response = json_decode($this->_last_response, true);
            $this->assertFalse($response['success']);
        }
    }

    /** A logged-in insert must actually land in the table with correct user_id. */
    public function test_journal_add_inserts_row_for_correct_user() {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);
        $_POST = [
            'pnl' => '-450.50', 'ts' => (string)(time()*1000), 'symbol' => 'NIFTY',
            'strike' => '23200', 'option_type' => 'CE', 'action' => 'AUTO_SL_EXIT',
            'source' => 'auto_sl', 'mode' => 'paper', 'nonce' => wp_create_nonce('fno_standalone_nonce'),
        ];
        try { fno_journal_add_fn(); } catch (WPDieException $e) {}
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE user_id=%d", $user_id), ARRAY_A);
        $this->assertNotNull($row, 'Row was not inserted');
        $this->assertEquals(-450.50, (float)$row['pnl']);
        $this->assertEquals('CE', $row['option_type']);
    }

    /** DB insert failure must surface as an error response, not a ghost 200 success (Part 2.6-D). */
    public function test_journal_add_reports_db_error_not_ghost_success() {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);
        global $wpdb;
        $original_table = $this->table;
        // Force a failure by pointing at a nonexistent table temporarily.
        $wpdb->fno_journal_table_override = $original_table . '_nonexistent';
        $_POST = ['pnl' => '10', 'nonce' => wp_create_nonce('fno_standalone_nonce')];
        // NOTE: fno_journal_add_fn() currently hardcodes $wpdb->prefix.'fno_journal'
        // rather than reading an override - this test documents the INTENT
        // (insert failures must be caught and reported) and would need
        // fno_journal_add_fn() refactored to accept a table override for
        // real dependency injection. Flagging this as a design gap found
        // while writing tests, not silently skipping the assertion.
        $this->markTestIncomplete('fno_journal_add_fn() has no table-override seam for fault injection - needs a small refactor (accept $table as a filterable value) before this failure path can be tested without a real forced DB error.');
    }

    /** Import must be idempotent per user - running it twice must not duplicate rows. */
    public function test_journal_import_is_idempotent() {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);
        $entries = json_encode([
            ['pnl' => 100, 'ts' => time()*1000, 'symbol' => 'NIFTY', 'action' => 'MANUAL', 'mode' => 'paper'],
            ['pnl' => -50, 'ts' => time()*1000, 'symbol' => 'NIFTY', 'action' => 'MANUAL', 'mode' => 'paper'],
        ]);
        $_POST = ['entries' => $entries, 'nonce' => wp_create_nonce('fno_standalone_nonce')];
        try { fno_journal_import_fn(); } catch (WPDieException $e) {}
        $first = json_decode($this->_last_response, true);
        $this->assertEquals(2, $first['data']['imported']);

        try { fno_journal_import_fn(); } catch (WPDieException $e) {}
        $second = json_decode($this->_last_response, true);
        $this->assertTrue($second['data']['already_imported']);
        $this->assertEquals(0, $second['data']['imported']);

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE user_id=%d", $user_id));
        $this->assertEquals(2, $count, 'Second import call must not have duplicated rows');
    }

    /** Malformed JSON must be rejected explicitly, not silently treated as zero entries. */
    public function test_journal_import_rejects_malformed_json() {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);
        $_POST = ['entries' => 'not valid json{{{', 'nonce' => wp_create_nonce('fno_standalone_nonce')];
        try {
            fno_journal_import_fn();
            $this->fail('Expected wp_die() from wp_send_json_error');
        } catch (WPDieException $e) {
            $response = json_decode($this->_last_response, true);
            $this->assertFalse($response['success']);
        }
    }

    /** List must only return the current user's rows, never another user's (data isolation). */
    public function test_journal_list_is_scoped_to_current_user() {
        $user_a = self::factory()->user->create();
        $user_b = self::factory()->user->create();
        global $wpdb;
        $wpdb->insert($this->table, ['user_id'=>$user_a, 'trade_ts'=>time()*1000, 'pnl'=>111]);
        $wpdb->insert($this->table, ['user_id'=>$user_b, 'trade_ts'=>time()*1000, 'pnl'=>222]);

        wp_set_current_user($user_a);
        $_GET = ['nonce' => wp_create_nonce('fno_standalone_nonce')];
        try { fno_journal_list_fn(); } catch (WPDieException $e) {}
        $response = json_decode($this->_last_response, true);
        $this->assertEquals(1, $response['data']['count']);
        $this->assertEquals(111.0, (float)$response['data']['journal'][0]['pnl']);
    }
}

class FnoNseCircuitBreakerTest extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        delete_transient('fno_nse_circuit_breaker');
    }

    /** Circuit must be closed (allow requests) before any failures recorded. */
    public function test_circuit_starts_closed() {
        $this->assertFalse(fno_nse_circuit_open());
    }

    /** Circuit must open exactly at FNO_NSE_CB_THRESHOLD consecutive failures, not before. */
    public function test_circuit_opens_at_threshold_not_before() {
        for ($i = 0; $i < FNO_NSE_CB_THRESHOLD - 1; $i++) {
            fno_nse_circuit_record(false);
            $this->assertFalse(fno_nse_circuit_open(), "Circuit opened early at failure #" . ($i+1));
        }
        fno_nse_circuit_record(false); // this one crosses the threshold
        $this->assertTrue(fno_nse_circuit_open());
    }

    /** A single success must fully reset the streak (no gradual half-open state at this volume). */
    public function test_success_resets_streak_fully() {
        for ($i = 0; $i < FNO_NSE_CB_THRESHOLD; $i++) fno_nse_circuit_record(false);
        $this->assertTrue(fno_nse_circuit_open());
        fno_nse_circuit_record(true);
        $this->assertFalse(fno_nse_circuit_open());
    }

    /** Circuit must close again after the cooldown window elapses. */
    public function test_circuit_closes_after_cooldown() {
        for ($i = 0; $i < FNO_NSE_CB_THRESHOLD; $i++) fno_nse_circuit_record(false);
        $this->assertTrue(fno_nse_circuit_open());
        // Simulate cooldown elapsed by directly manipulating the transient's
        // opened_at timestamp - this is a legitimate test technique (time
        // travel via the stored state) since we cannot literally sleep for
        // FNO_NSE_CB_COOLDOWN seconds in a unit test.
        $state = get_transient('fno_nse_circuit_breaker');
        $state['opened_at'] = time() - FNO_NSE_CB_COOLDOWN - 1;
        set_transient('fno_nse_circuit_breaker', $state, 300);
        $this->assertFalse(fno_nse_circuit_open());
    }
}
