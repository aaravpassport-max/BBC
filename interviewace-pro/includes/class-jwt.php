<?php
defined('ABSPATH') || exit;

class IA_JWT {
    private static function b64e(string $d){ return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
    private static function b64d(string $d){ return base64_decode(strtr($d,'-_','+/').str_repeat('=',(4-strlen($d)%4)%4)); }

    public static function encode(array $payload, int $exp = 900){
        $h = self::b64e(wp_json_encode(['typ'=>'JWT','alg'=>'HS256']));
        $payload['iat'] = time(); $payload['exp'] = time()+$exp;
        $b = self::b64e(wp_json_encode($payload));
        $s = self::b64e(hash_hmac('sha256',"$h.$b", ia_jwt_secret(), true));
        return "$h.$b.$s";
    }

    public static function decode(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$h,$b,$s] = $parts;
        $expected = self::b64e(hash_hmac('sha256',"$h.$b", ia_jwt_secret(), true));
        if (!hash_equals($expected, $s)) return null;
        $p = json_decode(self::b64d($b), true);
        if (!is_array($p) || ($p['exp'] ?? 0) < time()) return null;
        return $p;
    }

    public static function store_refresh(int $user_id, int $days = 30){
        global $wpdb;
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $wpdb->insert("{$wpdb->prefix}ia_refresh_tokens", [
            'user_id'    => $user_id,
            'token_hash' => $hash,
            'expires_at' => gmdate('Y-m-d H:i:s', time()+$days*DAY_IN_SECONDS),
            'revoked'    => 0,
        ]);
        return $raw;
    }

    public static function validate_refresh(string $raw): ?int {
        global $wpdb;
        $hash = hash('sha256', $raw);
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT id,user_id,expires_at,revoked FROM {$wpdb->prefix}ia_refresh_tokens WHERE token_hash=%s LIMIT 1", $hash
        ));
        if (!$row || $row->revoked || strtotime($row->expires_at) < time()) return null;
        $wpdb->update("{$wpdb->prefix}ia_refresh_tokens", ['revoked'=>1], ['id'=>$row->id]);
        return (int)$row->user_id;
    }

    public static function set_cookie(string $raw){
        $opts = ['expires'=>time()+30*DAY_IN_SECONDS,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Strict'];
        if (PHP_VERSION_ID >= 70300) { setcookie('ia_rt', $raw, $opts); }
        else { header('Set-Cookie: ia_rt='.urlencode($raw).'; Path=/; Expires='.gmdate('D, d M Y H:i:s T', $opts['expires']).($opts['secure']?'; Secure':'').'; HttpOnly; SameSite=Strict', false); }
    }

    public static function clear_cookie(){
        setcookie('ia_rt', '', time()-3600, '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
    }

    public static function revoke_all(int $user_id){
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}ia_refresh_tokens", ['revoked'=>1], ['user_id'=>$user_id]);
    }
}
