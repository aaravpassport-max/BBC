<?php
/**
 * Tests for IA_Cost_Tracker — API cost recording
 */
class Test_Cost_Tracker extends WP_UnitTestCase {

    private int $user_id;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create();
    }

    /** Recording Claude cost inserts a row with correct paise */
    public function test_record_claude_inserts_row(): void {
        global $wpdb;
        $before = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ia_api_costs WHERE user_id = {$this->user_id}"
        );

        IA_Cost_Tracker::record_claude($this->user_id, 0, 1000, 500, 'test_op');

        $after = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ia_api_costs WHERE user_id = {$this->user_id}"
        );
        $this->assertSame($before + 1, $after);
    }

    /** Cost calculation: 1000 input + 500 output tokens at Claude Sonnet rates */
    public function test_claude_cost_calculation(): void {
        global $wpdb;
        IA_Cost_Tracker::record_claude($this->user_id, 0, 1000, 500, 'calc_test');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT cost_paise FROM {$wpdb->prefix}ia_api_costs 
             WHERE user_id=%d AND operation='calc_test' ORDER BY id DESC LIMIT 1",
            $this->user_id
        ));
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, (int)$row->cost_paise);
    }

    /** Getting user total cost returns numeric value */
    public function test_get_user_total_returns_numeric(): void {
        IA_Cost_Tracker::record_claude($this->user_id, 0, 500, 200, 'total_test');
        $total = IA_Cost_Tracker::get_user_total($this->user_id);
        $this->assertIsNumeric($total);
        $this->assertGreaterThan(0, $total);
    }
}
