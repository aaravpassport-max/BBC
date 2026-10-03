<?php
/**
 * Tests for the fixes made in this hardening pass:
 *  - turn() idempotency (duplicate submit does not duplicate rows or re-call Claude)
 *  - report retry endpoint (a failed report can be retried, capped at 3 attempts)
 *  - usage-minute tracking actually persists after do_end() (was silently
 *    failing against a nonexistent `date` column — see class-api-interviews.php)
 *  - payment webhook idempotency (retried webhook delivery doesn't duplicate a charge)
 */
class Test_Enterprise_Fixes extends WP_UnitTestCase {

    private WP_REST_Server $server;
    private int $user_id;
    private string $token;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');

        $this->user_id = self::factory()->user->create(['role' => 'subscriber']);
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ia_profiles", ['user_id' => $this->user_id, 'email_verified' => 1]);
        $this->token = IA_JWT::encode(['user_id' => $this->user_id], 3600);

        // A fake key so IA_Claude::call() doesn't short-circuit on ia_no_key —
        // the actual HTTP call is stubbed below via pre_http_request so no
        // real network access happens in tests.
        update_option('ia_claude_key', 'sk-ant-test-fake-key');
        add_filter('pre_http_request', [$this, 'stub_claude_http'], 10, 3);
    }

    public function tear_down(): void {
        remove_filter('pre_http_request', [$this, 'stub_claude_http'], 10);
        parent::tear_down();
    }

    /** Stubs the Claude API over HTTP so tests never make a real network call. Mirrors the shape IA_Claude::call() expects. */
    public function stub_claude_http($preempt, $args, $url) {
        if (strpos($url, 'api.anthropic.com') === false) return $preempt;
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => json_encode([
                'content' => [['type' => 'text', 'text' => 'That is a great point — tell me more about a specific project.']],
                'usage'   => ['input_tokens' => 120, 'output_tokens' => 40],
            ]),
        ];
    }

    private function auth_post(string $path, array $body = []): WP_REST_Response {
        $req = new WP_REST_Request('POST', '/ia/v1' . $path);
        $req->set_header('Content-Type', 'application/json');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        $req->set_body(json_encode($body));
        return $this->server->dispatch($req);
    }

    /** A duplicate turn submit (same turn_number, same interview) must not create a second user-turn row. */
    public function test_turn_idempotency_prevents_duplicate_rows(): void {
        global $wpdb;
        $create = $this->auth_post('/interviews', ['type' => 'Software Engineer', 'round_type' => 'technical']);
        $iid = $create->get_data()['interview_id'];
        $this->auth_post("/interviews/$iid/start");

        $body = ['transcript' => 'I have five years of backend experience.', 'turn_number' => 1];
        $first  = $this->auth_post("/interviews/$iid/turn", $body);
        $second = $this->auth_post("/interviews/$iid/turn", $body); // simulated retry, identical payload

        $this->assertSame(200, $first->get_status());
        $this->assertSame(200, $second->get_status());
        $this->assertTrue((bool)($second->get_data()['replayed'] ?? false), 'A retried turn must be flagged as replayed, not treated as new.');

        $userTurnCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ia_turns WHERE interview_id=%d AND turn_number=1 AND role='user'", $iid
        ));
        $this->assertSame(1, $userTurnCount, 'A duplicate submit must not create a second user-turn row.');
    }

    /** A report stuck in 'failed' can be retried up to 3 times, then refuses further retries. */
    public function test_report_retry_caps_at_three_attempts(): void {
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->insert("{$p}ia_interviews", ['user_id' => $this->user_id, 'type' => 'Software Engineer', 'status' => 'completed']);
        $iid = $wpdb->insert_id;
        $wpdb->insert("{$p}ia_reports", ['interview_id' => $iid, 'status' => 'failed', 'error_message' => 'simulated failure', 'retry_count' => 3]);
        $rid = $wpdb->insert_id;

        $resp = $this->auth_post("/reports/$rid/retry");
        $this->assertSame(422, $resp->get_status(), 'A report already at the retry cap must refuse another retry.');
        $data = $resp->get_data();
        $this->assertSame('max_retries', $data['data']['code'] ?? null);
    }

    /** IA_Plan_Enforcer::inc_usage() (routed through by do_end()) must actually persist minutes against the real month_year-keyed schema. */
    public function test_usage_minutes_persist_after_interview_ends(): void {
        global $wpdb; $p = $wpdb->prefix;
        $create = $this->auth_post('/interviews', ['type' => 'Software Engineer', 'round_type' => 'general']);
        $iid = $create->get_data()['interview_id'];
        $this->auth_post("/interviews/$iid/start");
        // Backdate started_at so do_end() computes a non-zero duration.
        $wpdb->update("{$p}ia_interviews", ['started_at' => gmdate('Y-m-d H:i:s', time() - 120)], ['id' => $iid]);

        $this->auth_post("/interviews/$iid/end");

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT minutes_used FROM {$p}ia_usage WHERE user_id=%d AND month_year=%s", $this->user_id, gmdate('Y-m')
        ));
        $this->assertNotNull($row, 'Ending an interview must write a usage row keyed by month_year (the real schema), not silently fail.');
        $this->assertGreaterThan(0, (int)$row->minutes_used);
    }

    /** A retried Razorpay webhook for the same payment must not create a second payment row. */
    public function test_webhook_payment_insert_is_idempotent(): void {
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->insert("{$p}ia_subscriptions", ['user_id' => $this->user_id, 'plan' => 'pro', 'status' => 'created', 'razorpay_sub_id' => 'sub_test123']);

        $payload = json_encode([
            'event'   => 'subscription.charged',
            'payload' => [
                'subscription' => ['entity' => ['id' => 'sub_test123', 'notes' => ['user_id' => (string)$this->user_id, 'plan' => 'pro'], 'current_start' => time(), 'current_end' => time() + 2592000]],
                'payment'      => ['entity' => ['id' => 'pay_test456', 'amount' => 29900, 'currency' => 'INR']],
            ],
        ]);

        $req1 = new WP_REST_Request('POST', '/ia/v1/billing/webhook');
        $req1->set_body($payload);
        $this->server->dispatch($req1);

        // Simulate Razorpay's at-least-once redelivery of the identical event.
        $req2 = new WP_REST_Request('POST', '/ia/v1/billing/webhook');
        $req2->set_body($payload);
        $this->server->dispatch($req2);

        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ia_payments WHERE razorpay_payment_id=%s", 'pay_test456'
        ));
        $this->assertSame(1, $count, 'A redelivered webhook for the same payment must not create a duplicate payment row.');
    }
}
