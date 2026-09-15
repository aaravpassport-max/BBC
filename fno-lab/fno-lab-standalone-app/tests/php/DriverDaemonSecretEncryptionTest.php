<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the encryption-
 * at-rest fix applied to fno_get_headless_driver_secret() and
 * fno_get_daemon_secret() - both previously stored their generated
 * bearer secret PLAINTEXT in wp_options while every other credential
 * in this app (OpenAI/TrueData/Kite) already went through
 * fno_encrypt_secret()/fno_decrypt_secret() (AES-256-CBC keyed off
 * wp_salt('auth')). This test verifies, against the REAL function
 * bodies (not reimplemented copies):
 *  1. A freshly-generated secret is stored encrypted in the option
 *     (not equal to the plaintext returned to the caller), and the
 *     same plaintext secret is returned - and re-returned - on
 *     subsequent reads (encrypt-on-generate, decrypt-on-read,
 *     persisted correctly).
 *  2. An ALREADY-generated plaintext secret from before this fix
 *     (i.e. an option value that is not valid ciphertext) still
 *     authenticates correctly - fno_get_headless_driver_secret()/
 *     fno_get_daemon_secret() must return that exact legacy plaintext
 *     value unchanged, so an already-running driver/daemon holding
 *     that old secret is never locked out on upgrade - and that the
 *     stored option is opportunistically migrated to encrypted form
 *     so subsequent reads take the encrypted path.
 *
 * Run with: php tests/php/DriverDaemonSecretEncryptionTest.php
 */

$GLOBALS['fno_test_options'] = [];
function get_option($key, $default = false) { return $GLOBALS['fno_test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['fno_test_options'][$key] = $value; return true; }
function wp_generate_password($length, $special = true) { return str_repeat('x', $length); }
function wp_salt($scheme = 'auth') { return 'test-fixed-salt-do-not-use-in-production-' . $scheme; }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach (['fno_encrypt_secret', 'fno_decrypt_secret', 'fno_get_headless_driver_secret', 'fno_get_daemon_secret'] as $fn) {
    $start = strpos($pluginSource, "function $fn(");
    if ($start === false) { fwrite(STDERR, "FATAL: $fn not found in fno-lab.php\n"); exit(1); }
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function assertEquals($expected, $actual, $label) {
    assertTrue($expected === $actual, "$label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

foreach ([
    ['fno_get_headless_driver_secret', 'fno_headless_driver_secret', 'Headless Driver'],
    ['fno_get_daemon_secret', 'fno_daemon_ingest_secret', 'Daemon Ingest'],
] as [$getterFn, $optionKey, $label]) {
    echo "=== $label secret ($getterFn) - encryption at rest ===\n";

    // --- 1. Fresh generation: stored option must be encrypted, not
    // plaintext, and the plaintext must round-trip on repeated reads
    // (this IS the authenticate round trip - fno_verify_app_access()/
    // fno_ingest_microstructure_fn() compare hash_equals() against
    // exactly this returned value, so "returns the same plaintext
    // every time" is equivalent to "an authenticating caller who
    // saved this value keeps authenticating").
    $GLOBALS['fno_test_options'] = [];
    $secret1 = $getterFn();
    assertTrue(strlen($secret1) > 0, "$label: fresh secret is generated (non-empty)");
    $storedRaw = $GLOBALS['fno_test_options'][$optionKey];
    assertTrue($storedRaw !== $secret1, "$label: the value stored in wp_options is NOT the plaintext secret (genuinely encrypted, not a no-op)");
    assertEquals($secret1, fno_decrypt_secret($storedRaw), "$label: the stored ciphertext decrypts back to exactly the plaintext secret returned to the caller");
    $secret2 = $getterFn();
    assertEquals($secret1, $secret2, "$label: fresh-generate+encrypt+authenticate round trip - repeated reads return the SAME plaintext secret a real caller would keep authenticating with");
    $secret3 = $getterFn();
    assertEquals($secret1, $secret3, "$label: a third read still returns the same persisted secret (no silent regeneration)");

    // --- 2. Legacy plaintext migration: an option value written by
    // the OLD, pre-fix code path (raw plaintext, not ciphertext) must
    // still be returned as-is (so an already-configured driver/daemon
    // holding this exact string keeps authenticating), and the option
    // should be migrated to encrypted storage in place.
    $legacyPlaintext = 'legacy-plaintext-secret-from-before-encryption-fix-' . $optionKey;
    $GLOBALS['fno_test_options'] = [$optionKey => $legacyPlaintext];
    $returned = $getterFn();
    assertEquals($legacyPlaintext, $returned, "$label: legacy-plaintext-secret-still-authenticates - a pre-existing plaintext secret from before this fix is returned unchanged, never locking out an already-running driver/daemon");
    $migratedRaw = $GLOBALS['fno_test_options'][$optionKey];
    assertTrue($migratedRaw !== $legacyPlaintext, "$label: the legacy plaintext option was opportunistically migrated to encrypted storage on read");
    assertEquals($legacyPlaintext, fno_decrypt_secret($migratedRaw), "$label: the migrated ciphertext decrypts back to the exact original legacy secret");
    // Second read after migration must still return the same plaintext,
    // now via the encrypted path.
    assertEquals($legacyPlaintext, $getterFn(), "$label: after migration, subsequent reads still return the same plaintext secret (authentication continuity across the migration)");
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
