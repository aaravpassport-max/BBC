<?php
/**
 * Tests for IA_Plan_Enforcer — quota and rate limiting logic
 */
class Test_Plan_Enforcer extends WP_UnitTestCase {

    private int $user_id;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create(['role' => 'subscriber']);
    }

    /** Free plan allows up to 2 interviews per week */
    public function test_free_plan_weekly_limit_enforced(): void {
        update_user_meta($this->user_id, 'ia_plan', 'free');
        update_option('ia_quota_free_weekly_interviews', 2);

        // Simulate 2 interviews already done
        global $wpdb;
        for ($i = 0; $i < 2; $i++) {
            $wpdb->insert("{$wpdb->prefix}ia_interviews", [
                'user_id'    => $this->user_id,
                'type'       => 'Software Engineer',
                'status'     => 'completed',
                'started_at' => current_time('mysql'),
                'ended_at'   => current_time('mysql'),
            ]);
        }

        $result = IA_Plan_Enforcer::can_start_interview($this->user_id);
        $this->assertWPError($result);
        $this->assertSame('weekly_limit', $result->get_error_code());
    }

    /** Pro plan has no weekly limit */
    public function test_pro_plan_no_weekly_limit(): void {
        update_user_meta($this->user_id, 'ia_plan', 'pro');

        $result = IA_Plan_Enforcer::can_start_interview($this->user_id);
        $this->assertTrue($result === true || !is_wp_error($result));
    }

    /** max_minutes returns correct value per plan */
    public function test_max_minutes_per_plan(): void {
        update_option('ia_quota_free_minutes', 15);
        update_option('ia_quota_pro_minutes', 60);
        update_option('ia_quota_premium_minutes', 90);

        update_user_meta($this->user_id, 'ia_plan', 'free');
        $this->assertSame(15, IA_Plan_Enforcer::max_minutes($this->user_id));

        update_user_meta($this->user_id, 'ia_plan', 'pro');
        $this->assertSame(60, IA_Plan_Enforcer::max_minutes($this->user_id));

        update_user_meta($this->user_id, 'ia_plan', 'premium');
        $this->assertSame(90, IA_Plan_Enforcer::max_minutes($this->user_id));
    }

    /** Admin plan always passes */
    public function test_admin_bypasses_all_limits(): void {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        update_user_meta($admin_id, 'ia_plan', 'admin');

        $result = IA_Plan_Enforcer::can_start_interview($admin_id);
        $this->assertNotWPError($result);
    }
}
