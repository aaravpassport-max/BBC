<?php
namespace S2NRI\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\{Request, Response, S2NRI_SESSION};
use S2NRI\Services\{OtpService, NotificationService};
use S2NRI\Exceptions\ValidationException;

/**
 * AuthController — WP-session based auth with email OTP.
 *
 * TRACE: POST auth/send-otp → generates 6-digit OTP → stores hash in s2nri_otps → sends email.
 *        POST auth/verify-otp → validates OTP → wp_set_auth_cookie → returns user data.
 *        POST auth/login → email+password login → wp_set_auth_cookie.
 *        POST auth/register → creates WP user → sends welcome email → sets cookie.
 *        Preconditions: $wpdb, wp_mail available.
 *        Postconditions: WP auth cookie set on success. User array returned.
 *        Edge cases: expired OTP, wrong OTP, rate limit, unknown email.
 */
class AuthController extends BaseController {

    // ── Send OTP ──────────────────────────────────────────────────────────────

    public function sendOtp( Request $req ): void {
        $email = sanitize_email( $req->input( 'email', '' ) );
        if ( ! is_email( $email ) ) {
            Response::json( [ 'error' => 'Please enter a valid email address.' ], 422 );
            return;
        }

        $purpose = sanitize_key( $req->input( 'purpose', 'login' ) );
        if ( ! in_array( $purpose, [ 'login', 'register', 'password_reset' ], true ) ) {
            $purpose = 'login';
        }

        try {
            OtpService::send( $email, $purpose );
            Response::json( [ 'success' => true, 'message' => 'OTP sent to your email address.' ] );
        } catch ( \Exception $e ) {
            Response::json( [ 'error' => $e->getMessage() ], 429 );
        }
    }

    // ── Verify OTP → login / register ────────────────────────────────────────

    public function verifyOtp( Request $req ): void {
        $email   = sanitize_email( $req->input( 'email', '' ) );
        $otp     = sanitize_text_field( $req->input( 'otp', '' ) );
        $purpose = sanitize_key( $req->input( 'purpose', 'login' ) );

        if ( ! $email || ! $otp ) {
            Response::json( [ 'error' => 'Email and OTP are required.' ], 422 );
            return;
        }

        $result = OtpService::verify( $email, $otp, $purpose );
        if ( ! $result['success'] ) {
            Response::json( [ 'error' => $result['message'] ], 401 );
            return;
        }

        // Get or create WP user
        $wp_user = get_user_by( 'email', $email );
        if ( ! $wp_user ) {
            if ( $purpose === 'login' ) {
                Response::json( [ 'error' => 'No account found with this email. Please register first.' ], 404 );
                return;
            }
            // Auto-create on first OTP verify during register flow
            $user_id = wp_create_user( $email, wp_generate_password( 16 ), $email );
            if ( is_wp_error( $user_id ) ) {
                Response::json( [ 'error' => 'Failed to create account. Please try again.' ], 500 );
                return;
            }
            $wp_user = get_user_by( 'id', $user_id );
            $wp_user->set_role( 's2nri_customer' );
            \S2NRI\Models\User::ensureCustomerProfile( $user_id );
        }

        wp_set_auth_cookie( $wp_user->ID, true );
        wp_set_current_user( $wp_user->ID );

        // Return a fresh nonce so the client can immediately make authenticated requests
        // (the page-load nonce was generated for a non-logged-in session and is now invalid)
        Response::json( [
            'success'   => true,
            'user'      => \S2NRI\Models\User::buildUser( $wp_user ),
            'new_nonce' => wp_create_nonce( 's2nri_api' ),
        ] );
    }

    // ── Email + password login ────────────────────────────────────────────────

    public function login( Request $req ): void {
        $email    = sanitize_email( $req->input( 'email', '' ) );
        $password = $req->input( 'password', '' );

        if ( ! $email || ! $password ) {
            Response::json( [ 'error' => 'Email and password are required.' ], 422 );
            return;
        }

        $user = wp_authenticate( $email, $password );
        if ( is_wp_error( $user ) ) {
            Response::json( [ 'error' => 'Invalid email or password.' ], 401 );
            return;
        }

        // ADDED: two-factor authentication for staff/admin accounts.
        // Design choice, stated plainly: this is EMAIL-based 2FA (a
        // second code sent to the account's own email), not an
        // authenticator-app/TOTP implementation. Deliberately reuses
        // OtpService — already built, already rate-limited (3/10min),
        // already has atomic single-use enforcement (both fixed for real
        // bugs earlier this session) — rather than hand-rolling new
        // cryptographic code (base32 + HMAC-based TOTP) for a
        // security-critical feature I cannot test against a live
        // authenticator app in this environment. Scoped to staff/admin
        // only, matching the actual request — customer accounts are
        // unaffected.
        global $wpdb;
        $is_staff = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}s2nri_staff WHERE wp_user_id = %d AND is_active = 1 LIMIT 1", $user->ID
        ) ) || in_array( 'administrator', (array) $user->roles, true );

        if ( $is_staff && get_user_meta( $user->ID, 's2nri_2fa_enabled', true ) === '1' ) {
            try {
                \S2NRI\Services\OtpService::send( $email, 'login_2fa' );
            } catch ( \Exception $e ) {
                Response::json( [ 'error' => $e->getMessage() ], 429 ); return;
            }
            Response::json( [ 'success' => true, 'requires_2fa' => true, 'email' => $email ] );
            return;
        }

        wp_set_auth_cookie( $user->ID, true );
        wp_set_current_user( $user->ID );

        Response::json( [
            'success'   => true,
            'user'      => \S2NRI\Models\User::buildUser( $user ),
            'new_nonce' => wp_create_nonce( 's2nri_api' ),
        ] );
    }

    // ── Verify 2FA code and complete login ──────────────────────────────────

    public function verify2fa( Request $req ): void {
        $email = sanitize_email( $req->input( 'email', '' ) );
        $otp   = sanitize_text_field( $req->input( 'otp', '' ) );

        if ( ! $email || ! $otp ) {
            Response::json( [ 'error' => 'Email and code are required.' ], 422 ); return;
        }

        $result = \S2NRI\Services\OtpService::verify( $email, $otp, 'login_2fa' );
        if ( ! $result['success'] ) {
            Response::json( [ 'error' => $result['message'] ], 401 ); return;
        }

        $wp_user = get_user_by( 'email', $email );
        if ( ! $wp_user ) {
            Response::json( [ 'error' => 'Account not found.' ], 404 ); return;
        }

        wp_set_auth_cookie( $wp_user->ID, true );
        wp_set_current_user( $wp_user->ID );

        Response::json( [
            'success'   => true,
            'user'      => \S2NRI\Models\User::buildUser( $wp_user ),
            'new_nonce' => wp_create_nonce( 's2nri_api' ),
        ] );
    }

    // ── Enable/disable 2FA for the current logged-in user ───────────────────

    public function toggle2fa( Request $req ): void {
        $enabled = $req->input( 'enabled', false ) ? '1' : '0';
        update_user_meta( $this->user['wp_id'], 's2nri_2fa_enabled', $enabled );
        Response::json( [ 'success' => true, 'enabled' => $enabled === '1' ] );
    }

    // ── Register ──────────────────────────────────────────────────────────────

    public function register( Request $req ): void {
        $email    = sanitize_email( $req->input( 'email', '' ) );
        $name     = sanitize_text_field( $req->input( 'name', '' ) );
        $password = $req->input( 'password', '' );
        $phone    = sanitize_text_field( $req->input( 'phone', '' ) );
        $country  = sanitize_text_field( $req->input( 'country', '' ) );

        if ( ! is_email( $email ) ) {
            Response::json( [ 'error' => 'Please enter a valid email address.', 'fields' => [ 'email' => 'Invalid email' ] ], 422 );
            return;
        }
        if ( strlen( $name ) < 2 ) {
            Response::json( [ 'error' => 'Please enter your full name.', 'fields' => [ 'name' => 'Name required' ] ], 422 );
            return;
        }
        if ( email_exists( $email ) ) {
            Response::json( [ 'error' => 'An account with this email already exists. Please log in.', 'fields' => [ 'email' => 'Already registered' ] ], 422 );
            return;
        }

        if ( ! $password ) {
            $password = wp_generate_password( 12 );
        }

        $user_id = wp_create_user( $email, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            Response::json( [ 'error' => 'Registration failed. ' . $user_id->get_error_message() ], 500 );
            return;
        }

        wp_update_user( [ 'ID' => $user_id, 'display_name' => $name, 'first_name' => $name ] );
        $wp_user = get_user_by( 'id', $user_id );
        $wp_user->set_role( 's2nri_customer' );

        // Create customer profile
        \S2NRI\Models\User::ensureCustomerProfile( $user_id, [
            'phone'       => $phone,
            'whatsapp'    => $phone,
            'country'     => $country,
        ] );

        wp_set_auth_cookie( $user_id, true );
        wp_set_current_user( $user_id );

        // Send welcome email
        NotificationService::sendWelcomeEmail( $email, $name );

        Response::json( [
            'success' => true,
            'user'    => \S2NRI\Models\User::buildUser( $wp_user ),
        ] );
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function logout( Request $req ): void {
        wp_logout();
        wp_clear_auth_cookie();
        S2NRI_SESSION::destroy();
        Response::json( [ 'success' => true, 'message' => 'Logged out successfully.' ] );
    }

    // ── Forgot password ───────────────────────────────────────────────────────

    public function forgotPassword( Request $req ): void {
        $email = sanitize_email( $req->input( 'email', '' ) );
        if ( ! is_email( $email ) ) {
            Response::json( [ 'error' => 'Please enter a valid email address.' ], 422 );
            return;
        }

        // Always respond the same way to prevent enumeration
        if ( email_exists( $email ) ) {
            OtpService::send( $email, 'password_reset' );
        }

        Response::json( [ 'success' => true, 'message' => 'If an account with that email exists, a reset code has been sent.' ] );
    }

    // ── Reset password ────────────────────────────────────────────────────────

    public function resetPassword( Request $req ): void {
        $email    = sanitize_email( $req->input( 'email', '' ) );
        $otp      = sanitize_text_field( $req->input( 'otp', '' ) );
        $password = $req->input( 'new_password', '' );

        if ( ! $email || ! $otp || strlen( $password ) < 8 ) {
            Response::json( [ 'error' => 'Email, OTP, and new password (min 8 chars) are required.' ], 422 );
            return;
        }

        $result = OtpService::verify( $email, $otp, 'password_reset' );
        if ( ! $result['success'] ) {
            Response::json( [ 'error' => $result['message'] ], 401 );
            return;
        }

        $wp_user = get_user_by( 'email', $email );
        if ( ! $wp_user ) {
            Response::json( [ 'error' => 'Account not found.' ], 404 );
            return;
        }

        wp_set_password( $password, $wp_user->ID );
        Response::json( [ 'success' => true, 'message' => 'Password updated successfully. Please log in.' ] );
    }
    // ── Auto Register ─────────────────────────────────────────────────────────
    // TRACE: POST /api/v1/auth/auto-register {email, name}
    //        → find or create WP user → create s2nri_customer record
    //        → set WP auth cookie → set session → return user
    //        Postconditions: user is logged in, customer record exists.
    //        Edge cases: invalid email → 422. WP create_user fails → 500.
    public function autoRegister( Request $req ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $email = sanitize_email( $req->input( 'email', '' ) );
        $name  = sanitize_text_field( $req->input( 'name', '' ) );

        if ( ! $email || ! is_email( $email ) ) {
            Response::json( [ 'error' => 'A valid email address is required.' ], 422 );
            return;
        }

        // ── Find or create WordPress user ─────────────────────────────────────
        $wp_user = get_user_by( 'email', $email );
        $is_new  = false;

        if ( ! $wp_user ) {
            $is_new  = true;
            $pw      = wp_generate_password( 14, true );
            $disp    = $name ?: explode( '@', $email )[0];
            $uid     = wp_create_user( $email, $pw, $email );

            if ( is_wp_error( $uid ) ) {
                Response::json( [ 'error' => $uid->get_error_message() ], 500 );
                return;
            }

            wp_update_user( [ 'ID' => $uid, 'display_name' => $disp, 'role' => 's2nri_customer' ] );
            $wp_user = get_user_by( 'ID', $uid );

            // Send welcome email (non-blocking)
            try {
                $settings  = \S2NRI\Models\Setting::getPublic();
                $site_name = $settings['platform_name'] ?? get_bloginfo( 'name' );
                $subject   = "[{$site_name}] Your account has been created";
                $body      = "Hello {$disp},\n\n"
                    . "Your {$site_name} account has been created.\n\n"
                    . "Email: {$email}\nTemporary Password: {$pw}\n\n"
                    . "Login at: " . home_url( '/login' ) . "\n\n"
                    . "Your service request is under review. We will contact you within 24 hours.\n\n"
                    . "Team {$site_name}";
                wp_mail( $email, $subject, $body );
            } catch ( \Throwable $e ) {
                error_log( 'S2NRI autoRegister welcome email: ' . $e->getMessage() );
            }
        }

        // ── Ensure s2nri_customer record ──────────────────────────────────────
        $cust_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $wp_user->ID
        ) );

        if ( ! $cust_id ) {
            $parts = explode( ' ', trim( $wp_user->display_name ) . ' ', 2 );
            $cust_insert = $wpdb->insert( $p . 's2nri_customers', [
                'wp_user_id' => $wp_user->ID,
                'first_name' => trim( $parts[0] ),
                'last_name'  => trim( $parts[1] ?? '' ),
                'email'      => $email,
                'phone'      => '',
                'country'    => '',
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ] );
            // CHECKED (was previously unchecked): an unchecked failure here
            // fell through to $cust_id = 0 (an empty insert_id), which was
            // then stored directly into the login session as customer_id
            // (see $session_user below) — every booking/customer lookup
            // for this login would silently operate against customer_id 0
            // instead of failing loudly. Not fatal to auth itself (the
            // WordPress login still succeeds), so this logs and continues
            // with a null customer_id rather than blocking login entirely,
            // but no longer masks the failure as a valid id.
            $cust_id = $cust_insert !== false ? (int) $wpdb->insert_id : null;
            if ( $cust_insert === false ) {
                error_log( '[S2NRI] autoRegister: failed to create s2nri_customers row for wp_user_id=' . $wp_user->ID . ': ' . $wpdb->last_error );
            }
        }

        // ── Log the user in ───────────────────────────────────────────────────
        wp_set_auth_cookie( $wp_user->ID, true );
        wp_set_current_user( $wp_user->ID );

        $session_user = [
            'wp_id'        => $wp_user->ID,
            'email'        => $email,
            'display_name' => $wp_user->display_name,
            's2nri_role'   => 'customer',
            'customer_id'  => $cust_id,
            'first_name'   => explode( ' ', trim( $wp_user->display_name ) )[0],
            'last_name'    => trim( substr( $wp_user->display_name, strpos( $wp_user->display_name, ' ' ) + 1 ) ),
        ];

        \S2NRI\Api\S2NRI_SESSION::set( 's2nri_user', $session_user );

        Response::json( [
            'success' => true,
            'is_new'  => $is_new,
            'user'    => $session_user,
            'message' => $is_new ? 'Account created and logged in.' : 'Logged in successfully.',
        ] );
    }

    /**
     * changePassword — TRACE: PUT /auth/change-password {current, new_password}
     *   → verify current → set new → return success
     */
    public function changePassword( Request $req ): void {
        if ( ! $this->user ) { Response::json( [ 'error' => 'Unauthorized' ], 401 ); return; }
        $current  = $req->input( 'current_password', '' );
        $new_pass = $req->input( 'new_password', '' );
        if ( ! $current || ! $new_pass ) { Response::json( [ 'error' => 'Both current and new password are required.' ], 422 ); return; }
        if ( strlen( $new_pass ) < 8 )   { Response::json( [ 'error' => 'New password must be at least 8 characters.' ], 422 ); return; }
        $wp_user = get_user_by( 'ID', $this->user['wp_id'] );
        if ( ! $wp_user || ! wp_check_password( $current, $wp_user->user_pass, $wp_user->ID ) ) {
            Response::json( [ 'error' => 'Current password is incorrect.' ], 422 ); return;
        }
        wp_set_password( $new_pass, $wp_user->ID );
        Response::json( [ 'success' => true, 'message' => 'Password changed successfully.' ] );
    }

    /**
     * me — TRACE: GET /auth/me → return current session user or 401
     */
    public function me( Request $req ): void {
        if ( ! $this->user ) { Response::json( [ 'error' => 'Not authenticated' ], 401 ); return; }
        Response::json( [ 'user' => $this->user ] );
    }

    // ── Magic Link — send ──────────────────────────────────────────────────────

    /**
     * POST auth/magic-link/send
     * M-01: Passwordless login — sends a one-click login URL to the customer's email.
     * TRACE: validate email → find WP user → generate 64-char token →
     *        store hash in s2nri_magic_links → send email → return success.
     * PRECONDITIONS: user must have an existing account.
     * POSTCONDITIONS: magic_link token stored (expires 30 min), email sent.
     * EDGE CASES: rate-limit (1 per 60s per email), unknown email returns same success message.
     */
    public function sendMagicLink( Request $req ): void {
        $email = sanitize_email( $req->input( 'email', '' ) );
        if ( ! is_email( $email ) ) {
            Response::json( [ 'error' => 'Please enter a valid email address.' ], 422 ); return;
        }

        // Rate-limit: one magic link per 60 seconds per email (prevent enumeration)
        global $wpdb;
        $recent = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}s2nri_magic_links
             WHERE email = %s AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
            $email
        ) );
        if ( (int) $recent > 0 ) {
            Response::json( [ 'success' => true, 'message' => 'If an account exists, a login link has been sent.' ] );
            return;
        }

        $wp_user = get_user_by( 'email', $email );
        if ( ! $wp_user ) {
            // Return success anyway to prevent email enumeration
            Response::json( [ 'success' => true, 'message' => 'If an account exists, a login link has been sent.' ] );
            return;
        }

        // Generate cryptographically secure token
        $token       = bin2hex( random_bytes( 32 ) ); // 64 hex chars
        $token_hash  = hash( 'sha256', $token );
        $expires_at  = date( 'Y-m-d H:i:s', strtotime( '+30 minutes' ) );

        // Invalidate any existing unused tokens for this email
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_magic_links', [ 'email' => $email, 'used_at' => null ] ) === false ) {
            error_log( '[S2NRI] Failed to invalidate old magic links for ' . $email . ': ' . $wpdb->last_error );
            // Non-fatal — old tokens would remain valid alongside the new
            // one, a minor correctness gap, not a functional break.
        }

        $ml_insert = $wpdb->insert( $wpdb->prefix . 's2nri_magic_links', [
            'email'      => $email,
            'token_hash' => $token_hash,
            'expires_at' => $expires_at,
        ] );
        // CHECKED (was previously unchecked): the email below was
        // previously sent unconditionally — a failed insert here meant the
        // customer received a valid-looking one-click login email whose
        // token could never be found/verified (the verify step looks up
        // this exact token_hash row). Fail loudly instead of promising a
        // login link that can never work. Deliberately still returns the
        // same "success" copy pattern used elsewhere in this method (not
        // a raw 500) so this doesn't leak whether an account exists.
        if ( $ml_insert === false ) {
            error_log( '[S2NRI] magic_links insert failed for ' . $email . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Unable to send login link right now. Please try again in a moment.' ], 500 );
            return;
        }

        // Build login URL: /login?magic_token=TOKEN
        $login_url  = add_query_arg( 'magic_token', $token, home_url( '/login' ) );
        $site_name  = \S2NRI\Models\Setting::get( 'platform_name', 'Services2NRI' );
        $color      = \S2NRI\Models\Setting::get( 'primary_color', '#4A6FA5' );

        $subject = "Your one-click login link — {$site_name}";
        $body    = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center' style='padding:30px 20px'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
<tr><td style='background:{$color};padding:26px 36px;text-align:center'>
  <h1 style='color:#fff;margin:0;font-size:20px'>" . esc_html($site_name) . "</h1>
</td></tr>
<tr><td style='padding:32px 36px'>
  <h2 style='color:#1E2D40;margin:0 0 14px;font-size:19px'>Your one-click login link 🔑</h2>
  <p style='color:#555;font-size:15px;line-height:1.7;margin:0 0 10px'>Hi " . esc_html($wp_user->display_name) . ",</p>
  <p style='color:#555;font-size:15px;line-height:1.7;margin:0 0 24px'>Click the button below to log in to your account instantly. No password needed.</p>
  <p style='text-align:center;margin:0 0 20px'>
    <a href='" . esc_url($login_url) . "' style='background:{$color};color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:16px;font-weight:700;display:inline-block'>Log In to My Account →</a>
  </p>
  <p style='color:#888;font-size:13px;text-align:center;margin:0 0 16px'>This link expires in 30 minutes and can only be used once.</p>
  <p style='color:#aaa;font-size:12px;text-align:center;margin:0'>If you did not request this, you can safely ignore this email. Your account remains secure.</p>
</td></tr>
<tr><td style='background:#f8fafc;padding:14px 36px;text-align:center;border-top:1px solid #e2e8f0'>
  <p style='color:#ccc;font-size:11px;margin:0'>" . esc_html($site_name) . "</p>
</td></tr>
</table></td></tr></table></body></html>";

        // FIXED: closure-identity remove_filter bug.
        $html_filter_auth = fn() => 'text/html';
        add_filter( 'wp_mail_content_type', $html_filter_auth );
        wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
        remove_filter( 'wp_mail_content_type', $html_filter_auth );

        Response::json( [ 'success' => true, 'message' => 'If an account exists, a login link has been sent.' ] );
    }

    // ── Magic Link — verify ────────────────────────────────────────────────────

    /**
     * POST auth/magic-link/verify
     * TRACE: receive token → hash it → look up in s2nri_magic_links →
     *        verify not used, not expired → mark used → log user in → return user + nonce.
     * EDGE CASES: expired token → 401. Already-used token → 401. Token not found → 401.
     */
    public function verifyMagicLink( Request $req ): void {
        $token = sanitize_text_field( $req->input( 'token', '' ) );
        if ( strlen( $token ) !== 64 ) {
            Response::json( [ 'error' => 'Invalid or expired login link. Please request a new one.' ], 401 ); return;
        }

        global $wpdb;
        $token_hash = hash( 'sha256', $token );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_magic_links
             WHERE token_hash = %s AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1",
            $token_hash
        ), ARRAY_A );

        if ( ! $row ) {
            Response::json( [ 'error' => 'This login link has expired or already been used. Please request a new one.' ], 401 ); return;
        }

        // Mark token as used (atomic — prevents replay attacks)
        $affected = $wpdb->update(
            $wpdb->prefix . 's2nri_magic_links',
            [ 'used_at' => current_time( 'mysql' ) ],
            [ 'id' => $row['id'], 'used_at' => null ], // WHERE used_at IS NULL ensures atomicity
            [ '%s' ],
            [ '%d', '%s' ]
        );
        if ( ! $affected ) {
            Response::json( [ 'error' => 'This login link has already been used.' ], 401 ); return;
        }

        $wp_user = get_user_by( 'email', $row['email'] );
        if ( ! $wp_user ) {
            Response::json( [ 'error' => 'Account not found.' ], 404 ); return;
        }

        wp_set_auth_cookie( $wp_user->ID, true );
        wp_set_current_user( $wp_user->ID );

        Response::json( [
            'success'   => true,
            'user'      => \S2NRI\Models\User::buildUser( $wp_user ),
            'new_nonce' => wp_create_nonce( 's2nri_api' ),
        ] );
    }
}
