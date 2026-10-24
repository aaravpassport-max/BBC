<?php
defined('ABSPATH') || exit;

class IA_API_Auth {
    public static function register(){
        $ns = 'ia/v1'; $pub = '__return_true'; $auth = ['IA_Auth_Middleware','validate'];
        register_rest_route($ns,'/auth/register',        ['methods'=>'POST','callback'=>[__CLASS__,'do_register'],       'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/verify-otp',      ['methods'=>'POST','callback'=>[__CLASS__,'do_verify_otp'],     'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/resend-otp',      ['methods'=>'POST','callback'=>[__CLASS__,'do_resend_otp'],     'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/login',           ['methods'=>'POST','callback'=>[__CLASS__,'do_login'],          'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/logout',          ['methods'=>'POST','callback'=>[__CLASS__,'do_logout'],         'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/refresh',         ['methods'=>'POST','callback'=>[__CLASS__,'do_refresh'],        'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/forgot-password', ['methods'=>'POST','callback'=>[__CLASS__,'do_forgot'],         'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/me',              ['methods'=>'GET', 'callback'=>[__CLASS__,'do_me'],             'permission_callback'=>$auth]);
        register_rest_route($ns,'/auth/google',          ['methods'=>'GET', 'callback'=>[__CLASS__,'do_google_start'],   'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/google-callback', ['methods'=>'GET', 'callback'=>[__CLASS__,'do_google_callback'],'permission_callback'=>$pub]);
        register_rest_route($ns,'/auth/exchange-code',   ['methods'=>'POST','callback'=>[__CLASS__,'do_exchange_code'],  'permission_callback'=>$pub]);
    }

    public static function do_register(WP_REST_Request $req){
        $name  = sanitize_text_field($req->get_param('name') ?? '');
        $email = sanitize_email($req->get_param('email') ?? '');
        $pass  = $req->get_param('password') ?? '';
        $exp   = sanitize_text_field($req->get_param('experience_level') ?? 'fresher');

        if (strlen($name) < 2)                              return new WP_Error('ia_val','Name must be at least 2 characters.',['status'=>400,'field'=>'name']);
        if (!is_email($email))                              return new WP_Error('ia_val','Please enter a valid email.',['status'=>400,'field'=>'email']);
        if (strlen($pass) < 8)                             return new WP_Error('ia_val','Password must be at least 8 characters.',['status'=>400,'field'=>'password']);
        if (!preg_match('/[A-Z]/',$pass)||!preg_match('/[0-9]/',$pass)) return new WP_Error('ia_val','Password needs one uppercase letter and one number.',['status'=>400,'field'=>'password']);
        if (email_exists($email))                           return new WP_Error('ia_exists','An account with this email already exists.',['status'=>409,'field'=>'email']);
        /* Rate limit: 1 signup per device/day, 3 per IP total */
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = trim(explode(',',$ip)[0]);
        $ua = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        $rate_check = IA_Plan_Enforcer::check_signup_allowed($ip, $ua);
        if (is_wp_error($rate_check)) return $rate_check;

        $uid = wp_create_user(sanitize_user($email), $pass, $email);
        if (is_wp_error($uid)) return $uid;
        wp_update_user(['ID'=>$uid,'display_name'=>$name]);

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ia_profiles", [
            'user_id'=>$uid,'name'=>$name,'experience_level'=>in_array($exp,['fresher','0-2','2-5','5-10','10+'])?$exp:'fresher','email_verified'=>0
        ]);

        /* Record signup attempt */
        IA_Plan_Enforcer::record_signup($ip ?? '', $ua ?? '');
        $otp   = self::make_otp($uid, 'email_verify');
        self::mail_otp(get_userdata($uid)->user_email, $name, $otp);

        $utok = IA_JWT::encode(['user_id'=>$uid,'unverified'=>true], 600);
        return new WP_REST_Response(['success'=>true,'unverified_token'=>$utok,'email'=>$email,'message'=>"Verification code sent to $email."], 201);
    }

    public static function do_verify_otp(WP_REST_Request $req){
        $otp   = sanitize_text_field($req->get_param('otp') ?? '');
        $utok  = sanitize_text_field($req->get_param('unverified_token') ?? '');
        $pl    = IA_JWT::decode($utok);
        if (!$pl || empty($pl['unverified'])) return new WP_Error('ia_tok','Session expired. Please register again.',['status'=>401]);
        $uid   = (int)$pl['user_id'];
        $check = self::validate_otp($uid, $otp, 'email_verify');
        if (is_wp_error($check)) return $check;
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}ia_profiles",['email_verified'=>1],['user_id'=>$uid]);
        $tok   = IA_JWT::encode(['user_id'=>$uid]);
        $rt    = IA_JWT::store_refresh($uid);
        IA_JWT::set_cookie($rt);
        return new WP_REST_Response(['success'=>true,'access_token'=>$tok,'user'=>self::fmt_user($uid)]);
    }

    public static function do_resend_otp(WP_REST_Request $req){
        $utok = sanitize_text_field($req->get_param('unverified_token') ?? '');
        $pl   = IA_JWT::decode($utok);
        if (!$pl || empty($pl['unverified'])) return new WP_Error('ia_tok','Session expired.',['status'=>401]);
        $uid  = (int)$pl['user_id'];
        /* Throttle resend to prevent email-bombing a target inbox / burning mail-provider quota. */
        $rl_key = 'ia_otp_resend_'.$uid;
        if ((int)get_transient($rl_key) >= 3) {
            return new WP_Error('ia_rate','Please wait a minute before requesting another code.',['status'=>429]);
        }
        set_transient($rl_key, (int)get_transient($rl_key)+1, MINUTE_IN_SECONDS);
        $user = get_userdata($uid);
        $otp  = self::make_otp($uid, 'email_verify');
        self::mail_otp($user->user_email, $user->display_name, $otp);
        return new WP_REST_Response(['success'=>true]);
    }

    public static function do_login(WP_REST_Request $req){
        $email = sanitize_email($req->get_param('email') ?? '');
        $pass  = $req->get_param('password') ?? '';
        if (!$email || !$pass) return new WP_Error('ia_val','Email and password required.',['status'=>400]);

        $key = 'ia_login_fail_'.md5($email.($_SERVER['REMOTE_ADDR']??''));
        if ((int)get_transient($key) >= 5) return new WP_Error('ia_rate','Too many attempts. Wait 15 minutes.',['status'=>429]);

        $user = wp_authenticate($email, $pass);
        if (is_wp_error($user)) {
            set_transient($key, (int)get_transient($key)+1, 15*MINUTE_IN_SECONDS);
            return new WP_Error('ia_creds','Incorrect email or password.',['status'=>401]);
        }
        delete_transient($key);

        global $wpdb;
        $profile = $wpdb->get_row($wpdb->prepare("SELECT email_verified FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d", $user->ID));
        if ($profile && !$profile->email_verified) {
            $otp  = self::make_otp($user->ID, 'email_verify');
            self::mail_otp($user->user_email, $user->display_name, $otp);
            $utok = IA_JWT::encode(['user_id'=>$user->ID,'unverified'=>true], 600);
            return new WP_Error('ia_unverified','Please verify your email. New code sent.',['status'=>403,'code'=>'email_unverified','unverified_token'=>$utok,'email'=>$user->user_email]);
        }

        $tok = IA_JWT::encode(['user_id'=>$user->ID]);
        $rt  = IA_JWT::store_refresh($user->ID);
        IA_JWT::set_cookie($rt);
        return new WP_REST_Response(['success'=>true,'access_token'=>$tok,'user'=>self::fmt_user($user->ID)]);
    }

    public static function do_logout(WP_REST_Request $req){
        $raw = $_COOKIE['ia_rt'] ?? null;
        if ($raw) {
            global $wpdb;
            $wpdb->update("{$wpdb->prefix}ia_refresh_tokens",['revoked'=>1],['token_hash'=>hash('sha256',$raw)]);
        }
        IA_JWT::clear_cookie();
        return new WP_REST_Response(['success'=>true]);
    }

    public static function do_refresh(WP_REST_Request $req){
        $raw = $_COOKIE['ia_rt'] ?? null;
        if (!$raw) return new WP_Error('ia_no_rt','No refresh token.',['status'=>401]);
        $uid = IA_JWT::validate_refresh($raw);
        if (!$uid) { IA_JWT::clear_cookie(); return new WP_Error('ia_rt_bad','Refresh token invalid.',['status'=>401]); }
        $tok = IA_JWT::encode(['user_id'=>$uid]);
        $rt  = IA_JWT::store_refresh($uid);
        IA_JWT::set_cookie($rt);
        return new WP_REST_Response(['success'=>true,'access_token'=>$tok,'user'=>self::fmt_user($uid)]);
    }

    public static function do_forgot(WP_REST_Request $req){
        $email = sanitize_email($req->get_param('email') ?? '');
        if (is_email($email) && $u = get_user_by('email',$email)) retrieve_password($u->user_login);
        return new WP_REST_Response(['success'=>true,'message'=>'If an account exists, a reset link has been sent.']);
    }

    public static function do_me(WP_REST_Request $req){
        $uid = IA_Auth_Middleware::uid($req);
        return new WP_REST_Response(self::fmt_user($uid));
    }

    public static function do_google_start(WP_REST_Request $req){
        if (!ia_key('IA_GOOGLE_CLIENT_ID')) return new WP_Error('ia_cfg','Google login not configured.',['status'=>503]);
        $state = bin2hex(random_bytes(16));
        set_transient('ia_oauth_'.$state, true, 10*MINUTE_IN_SECONDS);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>ia_key('IA_GOOGLE_CLIENT_ID'),'redirect_uri'=>rest_url('ia/v1/auth/google-callback'),
            'response_type'=>'code','scope'=>'openid email profile','state'=>$state,
        ]);
        return new WP_REST_Response(['url'=>$url]);
    }

    public static function do_google_callback(WP_REST_Request $req){
        $code  = sanitize_text_field($req->get_param('code') ?? '');
        $state = sanitize_text_field($req->get_param('state') ?? '');
        $base  = home_url('/app/login');
        if (!$code || !get_transient('ia_oauth_'.$state)) { wp_redirect($base.'?error=oauth_failed'); exit; }
        delete_transient('ia_oauth_'.$state);

        $tr = wp_remote_post('https://oauth2.googleapis.com/token',[
            'body'=>['code'=>$code,'client_id'=>ia_key('IA_GOOGLE_CLIENT_ID'),'client_secret'=>ia_key('IA_GOOGLE_CLIENT_SECRET'),
                     'redirect_uri'=>rest_url('ia/v1/auth/google-callback'),'grant_type'=>'authorization_code']
        ]);
        if (is_wp_error($tr)) { wp_redirect($base.'?error=token_failed'); exit; }
        $td = json_decode(wp_remote_retrieve_body($tr), true);
        if (empty($td['access_token'])) { wp_redirect($base.'?error=no_token'); exit; }

        $ir = wp_remote_get('https://www.googleapis.com/oauth2/v3/userinfo',['headers'=>['Authorization'=>'Bearer '.$td['access_token']]]);
        if (is_wp_error($ir)) { wp_redirect($base.'?error=userinfo_failed'); exit; }
        $info = json_decode(wp_remote_retrieve_body($ir), true);
        if (empty($info['email'])) { wp_redirect($base.'?error=no_email'); exit; }

        $email = sanitize_email($info['email']);
        $name  = sanitize_text_field($info['name'] ?? $email);
        $user  = get_user_by('email', $email);
        if (!$user) {
            $uid = wp_create_user($email, wp_generate_password(24), $email);
            if (is_wp_error($uid)) { wp_redirect($base.'?error=create_failed'); exit; }
            wp_update_user(['ID'=>$uid,'display_name'=>$name]);
            global $wpdb;
            $wpdb->insert("{$wpdb->prefix}ia_profiles",['user_id'=>$uid,'name'=>$name,'email_verified'=>1]);
        } else {
            $uid = $user->ID;
            global $wpdb;
            $wpdb->update("{$wpdb->prefix}ia_profiles",['email_verified'=>1],['user_id'=>$uid]);
        }
        update_user_meta($uid,'ia_google_id',$info['sub']??'');

        /*
         * SECURITY FIX: this used to redirect with the JWT access token
         * embedded in the URL query string (home_url('/app?token=...')) —
         * every other auth path in this file correctly returns the token
         * in a JSON response body instead. A URL-embedded token lands in
         * browser history, gets written into any intermediate proxy/CDN/WAF
         * access log, and leaks via the Referer header on the very next
         * outbound request the page makes. Fixed to match the existing
         * `state` transient pattern already used earlier in this same
         * function: issue a short-lived, single-use exchange code, redirect
         * with only that opaque code, and let the SPA exchange it for the
         * real token via POST (see do_exchange_code below).
         */
        $rt = IA_JWT::store_refresh($uid);
        IA_JWT::set_cookie($rt);
        $code = bin2hex(random_bytes(24));
        set_transient('ia_oauth_code_'.$code, $uid, 2*MINUTE_IN_SECONDS);
        wp_redirect(home_url('/app/auth/callback?code='.$code));
        exit;
    }

    /** Exchanges the one-time code from do_google_callback() for a real access token. Single-use, 2-minute TTL. */
    public static function do_exchange_code(WP_REST_Request $req){
        $code = sanitize_text_field($req->get_param('code') ?: '');
        if (!$code) return new WP_Error('ia_val','Missing code.',['status'=>400]);
        $key = 'ia_oauth_code_'.$code;
        $uid = get_transient($key);
        if (!$uid) return new WP_Error('ia_bad_code','Code invalid or expired.',['status'=>401]);
        delete_transient($key); // single-use
        $tok = IA_JWT::encode(['user_id'=>(int)$uid]);
        return new WP_REST_Response(['success'=>true,'access_token'=>$tok,'user'=>self::fmt_user((int)$uid)]);
    }

    // ── Helpers ──────────────────────────────────────────────────
    private static function make_otp(int $uid, string $purpose){
        global $wpdb;
        $otp  = (string)random_int(100000,999999);
        $hash = hash('sha256',$otp);
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ia_otp_codes SET used=1 WHERE user_id=%d AND purpose=%s AND used=0",$uid,$purpose));
        $wpdb->insert("{$wpdb->prefix}ia_otp_codes",['user_id'=>$uid,'otp_hash'=>$hash,'purpose'=>$purpose,'expires_at'=>gmdate('Y-m-d H:i:s',time()+600),'used'=>0]);
        return $otp;
    }

    /**
     * SECURITY FIX: OTP verification previously had no rate limit at all,
     * unlike do_login() which correctly throttles password guesses. A
     * 6-digit OTP is 1,000,000 possibilities — without a limit here it was
     * realistically brute-forceable. Throttled the same way login already
     * is: 5 wrong attempts per user+purpose locks out further guesses for
     * 15 minutes, independent of the OTP's own 10-minute expiry.
     */
    private static function validate_otp(int $uid, string $otp, string $purpose){
        global $wpdb;
        $rl_key = 'ia_otp_fail_'.$uid.'_'.$purpose;
        if ((int)get_transient($rl_key) >= 5) {
            return new WP_Error('ia_rate','Too many incorrect attempts. Please request a new code and wait 15 minutes.',['status'=>429]);
        }
        $hash = hash('sha256',$otp);
        $row  = $wpdb->get_row($wpdb->prepare("SELECT id,expires_at,used FROM {$wpdb->prefix}ia_otp_codes WHERE user_id=%d AND otp_hash=%s AND purpose=%s ORDER BY created_at DESC LIMIT 1",$uid,$hash,$purpose));
        if (!$row) {
            set_transient($rl_key, (int)get_transient($rl_key)+1, 15*MINUTE_IN_SECONDS);
            return new WP_Error('ia_otp','Incorrect code.',['status'=>401]);
        }
        if ($row->used)     return new WP_Error('ia_otp','Code already used.',['status'=>401]);
        if (strtotime($row->expires_at)<time()) return new WP_Error('ia_otp','Code expired. Request a new one.',['status'=>410]);
        $wpdb->update("{$wpdb->prefix}ia_otp_codes",['used'=>1],['id'=>$row->id]);
        delete_transient($rl_key);
        return true;
    }

    private static function mail_otp(string $to, string $name, string $otp){
        $host = parse_url(home_url(), PHP_URL_HOST) ?: 'interviewace.in';
        wp_mail($to,"Your InterviewAce code: $otp","Hi $name,\n\nYour verification code is:\n\n$otp\n\nExpires in 10 minutes.\n\n— InterviewAce",
            ["Content-Type: text/plain; charset=UTF-8","From: InterviewAce <noreply@$host>"]);
    }

    public static function fmt_user(int $uid){
        global $wpdb;
        $user = get_userdata($uid);
        $prof = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d",$uid));
        $plan = IA_Plan_Enforcer::get_plan($uid);
        return [
            'id'                  => $uid,
            'email'               => $user->user_email,
            'name'                => $prof->name ?? $user->display_name,
            'experience_level'    => $prof->experience_level ?? 'fresher',
            'industry'            => $prof->industry ?? null,
            'current_role'        => $prof->current_role ?? null,
            'target_role'         => $prof->target_role ?? null,
            'language_pref'       => $prof->language_pref ?? 'english',
            'email_verified'      => (bool)($prof->email_verified ?? false),
            'onboarding_complete' => (bool)($prof->onboarding_complete ?? false),
            'has_resume'          => !empty($prof->resume_url),
            'plan'                => $plan,
        ];
    }
}
