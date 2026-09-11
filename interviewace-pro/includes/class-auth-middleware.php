<?php
defined('ABSPATH') || exit;

class IA_Auth_Middleware {
    public static function validate(WP_REST_Request $req){
        $token = null;
        $auth  = $req->get_header('Authorization');
        if ($auth && strncmp($auth,'Bearer ',7)===0) $token = trim(substr($auth,7));
        if (!$token) $token = $_COOKIE['ia_access_token'] ?? null;
        if (!$token) return new WP_Error('ia_no_token','Authentication required.',['status'=>401]);

        $payload = IA_JWT::decode($token);
        if (!$payload) return new WP_Error('ia_bad_token','Token invalid or expired.',['status'=>401]);

        $uid = absint($payload['user_id'] ?? 0);
        if (!$uid || !get_userdata($uid)) return new WP_Error('ia_no_user','User not found.',['status'=>401]);

        wp_set_current_user($uid);
        $req->set_param('_uid', $uid);
        $req->set_param('_jwt', $payload);
        return true;
    }

    public static function uid(WP_REST_Request $req){ return (int)($req->get_param('_uid') ?? 0); }
}
