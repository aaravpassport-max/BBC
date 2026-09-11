<?php
/**
 * Tests for IA_API_Interviews — create, start, turn, end
 */
class Test_API_Interviews extends WP_UnitTestCase {

    private WP_REST_Server $server;
    private int $user_id;
    private string $token;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');

        // Create verified user
        $this->user_id = self::factory()->user->create(['role' => 'subscriber']);
        update_user_meta($this->user_id, 'ia_plan', 'pro');
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ia_profiles", [
            'user_id'        => $this->user_id,
            'email_verified' => 1,
        ]);
        $this->token = IA_JWT::encode(['user_id' => $this->user_id, 'plan' => 'pro'], 3600);
    }

    private function auth_post(string $path, array $body): WP_REST_Response {
        $req = new WP_REST_Request('POST', '/ia/v1' . $path);
        $req->set_header('Content-Type', 'application/json');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        $req->set_body(json_encode($body));
        return $this->server->dispatch($req);
    }

    private function auth_get(string $path): WP_REST_Response {
        $req = new WP_REST_Request('GET', '/ia/v1' . $path);
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        return $this->server->dispatch($req);
    }

    /** Create interview returns 201 with interview_id */
    public function test_create_interview_returns_201(): void {
        $resp = $this->auth_post('/interviews', [
            'type'       => 'Software Engineer',
            'round_type' => 'general',
        ]);
        $this->assertSame(201, $resp->get_status(),
            json_encode($resp->get_data()));
        $data = $resp->get_data();
        $this->assertArrayHasKey('interview_id', $data);
        $this->assertGreaterThan(0, $data['interview_id']);
    }

    /** Unauthenticated request returns 401 */
    public function test_unauthenticated_returns_401(): void {
        $req = new WP_REST_Request('POST', '/ia/v1/interviews');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['type' => 'Engineer']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(401, $resp->get_status());
    }

    /** GET interview returns 200 with correct user */
    public function test_get_interview_returns_200(): void {
        $create = $this->auth_post('/interviews', ['type' => 'Engineer', 'round_type' => 'hr']);
        $id     = $create->get_data()['interview_id'];

        $resp = $this->auth_get("/interviews/{$id}");
        $this->assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertSame($id, $data['interview']['id'] ?? $data['id'] ?? null);
    }

    /** Cannot access another user's interview */
    public function test_cross_user_access_denied(): void {
        // Create interview as user 1
        $create = $this->auth_post('/interviews', ['type' => 'Engineer', 'round_type' => 'hr']);
        $id     = $create->get_data()['interview_id'];

        // Try to access as user 2
        $user2  = self::factory()->user->create(['role' => 'subscriber']);
        $tok2   = IA_JWT::encode(['user_id' => $user2, 'plan' => 'free'], 3600);
        $req    = new WP_REST_Request('GET', "/ia/v1/interviews/{$id}");
        $req->set_header('Authorization', 'Bearer ' . $tok2);
        $resp   = $this->server->dispatch($req);
        $this->assertContains($resp->get_status(), [403, 404]);
    }
}
