<?php
/**
 * Tests for IA_JWT — pure PHP HMAC-SHA256 token implementation
 */
class Test_JWT extends WP_UnitTestCase {

    /** JWT encodes and decodes correctly */
    public function test_encode_decode_roundtrip(): void {
        $payload = ['user_id' => 42, 'plan' => 'pro'];
        $token   = IA_JWT::encode($payload, 3600);

        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);

        $decoded = IA_JWT::decode($token);
        $this->assertIsArray($decoded);
        $this->assertSame(42, $decoded['user_id']);
        $this->assertSame('pro', $decoded['plan']);
    }

    /** Expired token returns WP_Error */
    public function test_expired_token_returns_error(): void {
        // Encode with -1 second TTL (already expired)
        $token = IA_JWT::encode(['user_id' => 1], -1);
        $result = IA_JWT::decode($token);
        $this->assertWPError($result);
        $this->assertSame('ia_token_expired', $result->get_error_code());
    }

    /** Tampered signature returns WP_Error */
    public function test_tampered_signature_returns_error(): void {
        $token = IA_JWT::encode(['user_id' => 1], 3600);
        $parts = explode('.', $token);
        $parts[2] = 'invalidsignature';
        $tampered = implode('.', $parts);

        $result = IA_JWT::decode($tampered);
        $this->assertWPError($result);
        $this->assertSame('ia_token_invalid', $result->get_error_code());
    }

    /** Tampered payload returns WP_Error */
    public function test_tampered_payload_returns_error(): void {
        $token = IA_JWT::encode(['user_id' => 1, 'plan' => 'free'], 3600);
        $parts = explode('.', $token);
        // Change user_id to 999 in payload
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $payload['plan'] = 'admin'; // tamper
        $parts[1] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $result = IA_JWT::decode($tampered);
        $this->assertWPError($result);
    }

    /** Token with no expiry field returns WP_Error */
    public function test_missing_exp_returns_error(): void {
        $secret = defined('IA_JWT_SECRET') ? IA_JWT_SECRET : 'test-secret';
        $header  = rtrim(strtr(base64_encode(json_encode(['typ'=>'JWT','alg'=>'HS256'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['user_id'=>1])), '+/', '-_'), '=');
        $sig     = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
        $result  = IA_JWT::decode("$header.$payload.$sig");
        $this->assertWPError($result);
    }
}
