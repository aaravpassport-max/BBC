<?php
/**
 * Tests for IA_API_Auth — register, login, OTP flow
 */
class Test_API_Auth extends WP_UnitTestCase {

    private WP_REST_Server $server;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    private function post(string $path, array $body): WP_REST_Response {
        $req = new WP_REST_Request('POST', '/ia/v1' . $path);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode($body));
        return $this->server->dispatch($req);
    }

    /** Register returns 201 with unverified_token */
    public function test_register_returns_201(): void {
        $resp = $this->post('/auth/register', [
            'name'             => 'Test User',
            'email'            => 'test_' . uniqid() . '@example.com',
            'password'         => 'Password1',
            'experience_level' => 'fresher',
        ]);
        $this->assertSame(201, $resp->get_status(),
            'Expected 201, got: ' . json_encode($resp->get_data()));
        $data = $resp->get_data();
        $this->assertArrayHasKey('unverified_token', $data);
    }

    /** Duplicate email returns 409 */
    public function test_duplicate_email_returns_409(): void {
        $email = 'dup_' . uniqid() . '@example.com';
        $this->post('/auth/register', ['name'=>'A','email'=>$email,'password'=>'Password1']);
        $resp = $this->post('/auth/register', ['name'=>'B','email'=>$email,'password'=>'Password1']);
        $this->assertSame(409, $resp->get_status());
    }

    /** Short password returns 400 with field=password */
    public function test_weak_password_returns_400(): void {
        $resp = $this->post('/auth/register', [
            'name' => 'X', 'email' => uniqid().'@e.com', 'password' => 'short'
        ]);
        $this->assertSame(400, $resp->get_status());
        $data = $resp->get_data();
        $this->assertSame('password', $data['data']['field'] ?? null);
    }

    /** Login with wrong password returns 401 */
    public function test_wrong_password_returns_401(): void {
        $email = 'login_' . uniqid() . '@example.com';
        wp_create_user($email, 'RealPass1', $email);

        $resp = $this->post('/auth/login', ['email' => $email, 'password' => 'WrongPass1']);
        $this->assertSame(401, $resp->get_status());
    }

    /** Login brute-force: 5 failures trigger 429 */
    public function test_brute_force_lockout(): void {
        $email = 'bf_' . uniqid() . '@example.com';
        wp_create_user($email, 'RealPass1', $email);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/auth/login', ['email' => $email, 'password' => 'wrong']);
        }
        $resp = $this->post('/auth/login', ['email' => $email, 'password' => 'wrong']);
        $this->assertSame(429, $resp->get_status());
    }
}
