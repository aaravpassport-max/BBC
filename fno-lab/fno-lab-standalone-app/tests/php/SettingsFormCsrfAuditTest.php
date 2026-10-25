<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test - CSRF (nonce) and
 * capability-check audit of every settings-SAVE action reachable from
 * fno_render_premium_settings_page() (fno-lab.php:2123+), the third and
 * final piece of the standard WordPress security triad for this file
 * (SQL injection and XSS/output-escaping were each separately audited
 * earlier this session; this test covers CSRF specifically).
 *
 * This is an AUDIT-CONFIRMATION test, not a bug-fix regression test -
 * careful manual review of every `<form method="post">` on the settings
 * page and its corresponding save handler found all four distinct
 * settings-save actions already correctly protected:
 *
 *   1. fno_save_openai_key            (inline handler at fno-lab.php:2180,
 *      form at :2188-2193) - nonce action string 'fno_save_openai_key_action'
 *      used identically by wp_nonce_field() at :2189 and
 *      check_admin_referer() at :2180. Capability gated by the
 *      current_user_can('manage_options') guard at the very top of
 *      fno_render_premium_settings_page() (:2124), which the POST-handling
 *      code executes after.
 *
 *   2. fno_save_truedata_credentials_fn (:2694-2706, registered via
 *      admin_post_fno_save_truedata_credentials at :2687) - nonce action
 *      'fno_save_truedata_credentials' / field 'fno_truedata_nonce' at
 *      both wp_nonce_field() (:2655) and check_admin_referer() (:2696).
 *      Explicit current_user_can('manage_options') at :2695.
 *
 *   3. fno_save_premium_provider_fn (:2794-2814, registered via
 *      admin_post_fno_save_premium_provider at :2777) - nonce action
 *      'fno_save_premium_provider' / field 'fno_premium_nonce' at both
 *      wp_nonce_field() (:2135) and check_admin_referer() (:2796).
 *      Explicit current_user_can('manage_options') at :2795.
 *
 *   4. fno_save_driver_user_fn (:2827-2835, registered via
 *      admin_post_fno_save_driver_user at :2816) - nonce action
 *      'fno_save_driver_user' / field 'fno_driver_user_nonce' at both
 *      wp_nonce_field() (:2199) and check_admin_referer() (:2829).
 *      Explicit current_user_can('manage_options') at :2828.
 *
 * No gap was found. This test parses the real fno-lab.php source (no
 * WordPress needed) and asserts all four properties mechanically, so a
 * future edit that drops a nonce field, drops a capability check, or lets
 * the nonce action-string pair drift out of sync (a subtle, real bug
 * class: check_admin_referer() silently uses '-1' as its default action
 * if the second argument is omitted or misspelled, which would make the
 * check trivially bypassable) fails this test immediately.
 *
 * Run with: php tests/php/SettingsFormCsrfAuditTest.php
 */

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Settings-page CSRF (nonce) + capability audit (real fno-lab.php source scan) ===\n";

/**
 * Each entry: [render-side wp_nonce_field call regex, handler-side
 * check_admin_referer call regex, handler function name (or null for the
 * inline openai handler), label].
 */
$actions = [
    [
        'label' => 'fno_save_openai_key (inline handler in fno_render_premium_settings_page)',
        'nonceFieldPattern' => "/wp_nonce_field\\('fno_save_openai_key_action'\\)/",
        'refererPattern' => "/isset\\(\\\$_POST\\['fno_save_openai_key'\\]\\)\\s*&&\\s*check_admin_referer\\('fno_save_openai_key_action'\\)/",
        'capabilityScope' => 'inline', // covered by the function-top guard, checked separately below
    ],
    [
        'label' => 'fno_save_truedata_credentials_fn',
        'nonceFieldPattern' => "/wp_nonce_field\\('fno_save_truedata_credentials',\\s*'fno_truedata_nonce'/",
        'refererPattern' => "/check_admin_referer\\('fno_save_truedata_credentials',\\s*'fno_truedata_nonce'\\)/",
        'fnName' => 'fno_save_truedata_credentials_fn',
        'adminPostAction' => 'fno_save_truedata_credentials',
    ],
    [
        'label' => 'fno_save_premium_provider_fn',
        'nonceFieldPattern' => "/wp_nonce_field\\('fno_save_premium_provider',\\s*'fno_premium_nonce'/",
        'refererPattern' => "/check_admin_referer\\('fno_save_premium_provider',\\s*'fno_premium_nonce'\\)/",
        'fnName' => 'fno_save_premium_provider_fn',
        'adminPostAction' => 'fno_save_premium_provider',
    ],
    [
        'label' => 'fno_save_driver_user_fn',
        'nonceFieldPattern' => "/wp_nonce_field\\('fno_save_driver_user',\\s*'fno_driver_user_nonce'/",
        'refererPattern' => "/check_admin_referer\\('fno_save_driver_user',\\s*'fno_driver_user_nonce'\\)/",
        'fnName' => 'fno_save_driver_user_fn',
        'adminPostAction' => 'fno_save_driver_user',
    ],
];

assertTrue(count($actions) === 4, 'exactly 4 distinct settings-save actions audited on the premium settings page (found ' . count($actions) . ')');

foreach ($actions as $a) {
    assertTrue((bool) preg_match($a['nonceFieldPattern'], $pluginSource), $a['label'] . ": renders a wp_nonce_field() with the expected action string");
    assertTrue((bool) preg_match($a['refererPattern'], $pluginSource), $a['label'] . ": save path calls check_admin_referer()/verify with the SAME action string (and field name, where applicable) as the render side - no mismatch");

    if (isset($a['fnName'])) {
        // The handler must be registered on admin_post_<action> (standard
        // WP CSRF-protected admin form submission path - requires a valid
        // logged-in session, unlike admin-ajax.php's nopriv variants).
        $regPattern = "/add_action\\(\\s*'admin_post_" . preg_quote($a['adminPostAction'], '/') . "'\\s*,\\s*'" . preg_quote($a['fnName'], '/') . "'\\s*\\)/";
        assertTrue((bool) preg_match($regPattern, $pluginSource), $a['label'] . ": registered on admin_post_{$a['adminPostAction']}");

        // Explicit capability check inside the handler body itself
        // (independent of the nonce - a lower-privileged logged-in user
        // must not be able to POST directly to admin-post.php and succeed
        // even with a nonce obtained by viewing the page).
        if (preg_match('/function\s+' . preg_quote($a['fnName'], '/') . '\s*\(\)\s*\{/', $pluginSource, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $bodyEnd = strpos($pluginSource, "\n}", $start);
            $body = $bodyEnd !== false ? substr($pluginSource, $start, $bodyEnd - $start) : '';
            assertTrue(
                (bool) preg_match("/current_user_can\\('manage_options'\\)/", $body) && strpos($body, 'wp_die') !== false,
                $a['label'] . ": handler body has its own explicit current_user_can('manage_options') capability check (independent of the nonce check)"
            );
            assertTrue(
                (bool) preg_match($a['refererPattern'], $body),
                $a['label'] . ": the check_admin_referer() call found above is inside this handler's own body (not just present elsewhere in the file)"
            );
        } else {
            assertTrue(false, $a['label'] . ": could not locate function definition to verify capability check");
        }
    }
}

// The inline openai-key handler: capability is enforced by the guard at
// the top of fno_render_premium_settings_page() itself, since the
// isset($_POST[...]) block executes only as part of that same function
// call. Confirm that guard exists and precedes the POST-handling block.
$fnStart = strpos($pluginSource, 'function fno_render_premium_settings_page()');
$guardPos = strpos($pluginSource, "current_user_can('manage_options')", $fnStart);
$postHandlePos = strpos($pluginSource, "isset(\$_POST['fno_save_openai_key'])", $fnStart);
assertTrue($fnStart !== false && $guardPos !== false && $postHandlePos !== false && $guardPos < $postHandlePos,
    'fno_save_openai_key: the manage_options capability guard at the top of fno_render_premium_settings_page() precedes the POST-handling block (so it is enforced before any save)');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
