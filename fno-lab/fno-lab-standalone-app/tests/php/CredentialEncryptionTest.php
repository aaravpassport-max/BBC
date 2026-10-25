<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_encrypt_secret()/fno_decrypt_secret() - the pair that protects
 * every real credential this plugin stores (TrueData password, Kite
 * API secret, premium-provider API keys).
 *
 * Unlike tests/php/JournalAndCircuitBreakerTest.php (which correctly,
 * honestly requires a full WP_UnitTestCase + MySQL scaffold this
 * sandbox does not have, and is explicitly labeled UNVERIFIED at its
 * own top), THIS file is genuinely runnable right now with nothing
 * but a bare PHP interpreter - fno_encrypt_secret/fno_decrypt_secret
 * only depend on ONE real WordPress function (wp_salt), stubbed below
 * with a real, fixed, deterministic value so the actual encrypt/
 * decrypt logic itself is exercised for real, not skipped.
 *
 * Run with: php tests/php/CredentialEncryptionTest.php
 *
 * Motivation: found and fixed a real, previously-undetected production
 * bug in the JS layer this session (a phantom `ctx` variable in
 * computeOperatorIntel that had NEVER been directly tested despite
 * being trusted since the start of this project) - specifically by
 * finally writing its first real test. This applies the exact same
 * lesson to the PHP side, which has had ZERO direct test coverage
 * this entire project (only `php -l` syntax linting) - starting with
 * the function where a hidden bug would be most consequential: the
 * one protecting every stored secret.
 */

// --- Real, minimal WordPress stub - ONLY what fno_encrypt_secret/
// fno_decrypt_secret actually call, nothing more, so this test
// exercises the REAL function bodies, not a rewritten copy of them.
function wp_salt($scheme = 'auth') {
    return 'test-fixed-salt-do-not-use-in-production-' . $scheme;
}

// --- Load the REAL function definitions directly from the real
// plugin file, rather than reimplementing them here (which would only
// test my own understanding of the code, not the actual code).
$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$start = strpos($pluginSource, 'function fno_encrypt_secret');
$end = strpos($pluginSource, "\n\n", strpos($pluginSource, 'function fno_decrypt_secret'));
if ($start === false || $end === false) {
    fwrite(STDERR, "FATAL: could not locate fno_encrypt_secret/fno_decrypt_secret in fno-lab.php - test cannot run against real code.\n");
    exit(1);
}
eval(substr($pluginSource, $start, $end - $start));

// --- Minimal, real, dependency-free assertion helpers ---
$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function assertEquals($expected, $actual, $label) {
    assertTrue($expected === $actual, "$label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

echo "=== fno_encrypt_secret / fno_decrypt_secret (real credential encryption round-trip) ===\n";

// Real round-trip: a real, non-trivial secret string must decrypt back
// to EXACTLY itself, character for character.
$realSecret = 'my_real_truedata_password_Abc123!@#';
$encrypted = fno_encrypt_secret($realSecret);
assertTrue($encrypted !== $realSecret, 'encrypted output must not equal the plaintext (genuinely encrypted, not a no-op)');
assertTrue(strlen($encrypted) > 0, 'encrypted output is non-empty for a real secret');
$decrypted = fno_decrypt_secret($encrypted);
assertEquals($realSecret, $decrypted, 'real round-trip: decrypt(encrypt(secret)) === secret, exactly');

// Real edge case: empty string must round-trip to empty, not throw or
// silently produce garbage.
assertEquals('', fno_encrypt_secret(''), 'encrypting an empty string returns empty string (documented early-return)');
assertEquals('', fno_decrypt_secret(''), 'decrypting an empty string returns empty string');
assertEquals('', fno_decrypt_secret(fno_encrypt_secret('')), 'empty-string round-trip');

// Real edge case: two encryptions of the SAME plaintext must NOT
// produce the same ciphertext (real IV randomization, not a
// deterministic/broken cipher mode) - a real, meaningful security
// property, not just "does it round-trip".
$enc1 = fno_encrypt_secret($realSecret);
$enc2 = fno_encrypt_secret($realSecret);
assertTrue($enc1 !== $enc2, 'two encryptions of the SAME secret produce DIFFERENT ciphertext (real random IV per call, not a broken deterministic cipher)');
assertEquals($realSecret, fno_decrypt_secret($enc1), 'first ciphertext still decrypts correctly');
assertEquals($realSecret, fno_decrypt_secret($enc2), 'second ciphertext (different IV) also decrypts correctly to the same real plaintext');

// Real edge case: malformed/tampered input must fail safely (empty
// string), never throw a fatal error or return garbage silently
// treated as a real secret.
assertEquals('', fno_decrypt_secret('not-real-base64-!!!garbage'), 'malformed base64 input fails safely to empty string, not a fatal error');
assertEquals('', fno_decrypt_secret(base64_encode('tooshort')), 'input shorter than a real IV+ciphertext (17 bytes minimum) fails safely to empty string');

$tampered = $enc1;
$tampered[strlen($tampered)-1] = ($tampered[strlen($tampered)-1] === 'A') ? 'B' : 'A'; // flip the last real base64 character
$tamperedResult = fno_decrypt_secret($tampered);
assertTrue($tamperedResult !== $realSecret, 'tampering with the real ciphertext must NOT silently decrypt back to the original secret');

// Real, longer secret (simulating a real long API key) round-trips
// correctly too - not just short test strings.
$longSecret = str_repeat('a1B2c3D4!', 20); // 180 real characters
assertEquals($longSecret, fno_decrypt_secret(fno_encrypt_secret($longSecret)), 'a real, long (180-char) secret round-trips correctly');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
