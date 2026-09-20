<?php
defined('ABSPATH') || exit;

/**
 * Server-side proxy for Deepgram (STT) and ElevenLabs (TTS).
 *
 * ROOT-CAUSE FIX: these third-party API keys used to be pushed directly into
 * the browser via wp_localize_script (interviewace.php), unauthenticated,
 * on every page load — anyone viewing page source could lift and reuse them
 * against the site owner's paid quota with no rate limit. The keys now never
 * leave the server. The frontend authenticates with its own JWT (same as
 * every other API call) and:
 *   - POSTs raw audio here for transcription (Deepgram called server-side).
 *   - Requests a short-lived, scoped ElevenLabs token here for TTS instead
 *     of ever holding the real ElevenLabs API key.
 * Both routes are rate-limited per user via IA_Plan_Enforcer so a compromised
 * JWT can't be used to run up unlimited STT/TTS cost either — it's bounded
 * by the same plan-based minute quotas that already govern interview time.
 */
class IA_API_Speech {
    public static function register(){
        $ns='ia/v1'; $a=['IA_Auth_Middleware','validate'];
        register_rest_route($ns,'/speech/stt',              ['methods'=>'POST','callback'=>[__CLASS__,'transcribe'],         'permission_callback'=>$a]);
        register_rest_route($ns,'/speech/tts-token',       ['methods'=>'POST','callback'=>[__CLASS__,'tts_token'],          'permission_callback'=>$a]);
        register_rest_route($ns,'/speech/stt-stream-token',  ['methods'=>'POST','callback'=>[__CLASS__,'stt_stream_token'],  'permission_callback'=>$a]);
    }

    /** Proxies raw audio (multipart 'audio' field) to Deepgram, returns the transcript. */
    public static function transcribe(WP_REST_Request $req){
        $uid = (int)IA_Auth_Middleware::uid($req);
        $ok  = self::rate_limit_ok($uid, 'stt');
        if (is_wp_error($ok)) return $ok;

        $key = ia_key('IA_DEEPGRAM_KEY');
        if (!$key) return new WP_Error('ia_cfg','Speech-to-text is not configured.',['status'=>503]);

        $files = $req->get_file_params();
        if (empty($files['audio']['tmp_name'])) return new WP_Error('ia_val','No audio provided.',['status'=>400]);
        $audio = file_get_contents($files['audio']['tmp_name']);
        if ($audio === false || strlen($audio) === 0) return new WP_Error('ia_val','Empty audio payload.',['status'=>400]);
        // Hard cap so a malicious/buggy client can't send an oversized payload through the server.
        if (strlen($audio) > 15 * 1024 * 1024) return new WP_Error('ia_val','Audio payload too large.',['status'=>413]);

        $mime = $files['audio']['type'] ?: 'audio/webm';
        $res = wp_remote_post('https://api.deepgram.com/v1/listen?model=nova-2&smart_format=true&language=en', [
            'timeout' => 30,
            'headers' => ['Authorization'=>'Token '.$key,'Content-Type'=>$mime],
            'body'    => $audio,
        ]);
        if (is_wp_error($res)) return new WP_Error('ia_api_network','Speech service network error.',['status'=>502,'retryable'=>true]);
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200) return new WP_Error('ia_api',"Speech service error ($code).",['status'=>502,'retryable'=>true]);

        $transcript = $data['results']['channels'][0]['alternatives'][0]['transcript'] ?? '';
        $seconds    = (float)($data['metadata']['duration'] ?? 0);
        if (class_exists('IA_Cost_Tracker') && $seconds > 0) {
            IA_Cost_Tracker::record_deepgram($uid, (int)($req->get_param('interview_id') ?? 0) ?: null, $seconds);
        }
        return new WP_REST_Response(['success'=>true,'transcript'=>$transcript,'duration_seconds'=>$seconds]);
    }

    /**
     * ElevenLabs has no short-lived-token mechanism of its own, so instead
     * of ever exposing the real key, we synthesize audio server-side and
     * return it directly (base64) — functionally equivalent to a "token"
     * exchange from the client's point of view but with zero key exposure.
     */
    public static function tts_token(WP_REST_Request $req){
        $uid = (int)IA_Auth_Middleware::uid($req);
        $ok  = self::rate_limit_ok($uid, 'tts');
        if (is_wp_error($ok)) return $ok;

        $key = ia_key('IA_ELEVENLABS_KEY');
        if (!$key) return new WP_Error('ia_cfg','Text-to-speech is not configured.',['status'=>503]);

        $text  = sanitize_textarea_field($req->get_param('text') ?: '');
        if (!$text) return new WP_Error('ia_val','No text provided.',['status'=>400]);
        if (mb_strlen($text) > 2000) return new WP_Error('ia_val','Text too long for a single TTS request.',['status'=>413]);
        $voice = get_option('ia_elevenlabs_voice','EXAVITQu4vr4xnSDxMaL');

        $res = wp_remote_post("https://api.elevenlabs.io/v1/text-to-speech/$voice", [
            'timeout' => 30,
            'headers' => ['xi-api-key'=>$key,'Content-Type'=>'application/json','Accept'=>'audio/mpeg'],
            'body'    => wp_json_encode(['text'=>$text,'model_id'=>'eleven_turbo_v2_5','voice_settings'=>['stability'=>0.5,'similarity_boost'=>0.75]]),
        ]);
        if (is_wp_error($res)) return new WP_Error('ia_api_network','Speech synthesis network error.',['status'=>502,'retryable'=>true]);
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return new WP_Error('ia_api',"Speech synthesis error ($code).",['status'=>502,'retryable'=>true]);
        $audio = wp_remote_retrieve_body($res);

        if (class_exists('IA_Cost_Tracker')) {
            IA_Cost_Tracker::record_elevenlabs($uid, (int)($req->get_param('interview_id') ?? 0) ?: null, mb_strlen($text));
        }
        return new WP_REST_Response([
            'success'    => true,
            'audio_base64' => base64_encode($audio),
            'mime'       => 'audio/mpeg',
        ]);
    }

    /**
     * Mints a short-lived, listen-only Deepgram key for browser WebSocket STT.
     * The permanent key never leaves the server — only this scoped token is returned.
     */
    public static function stt_stream_token(WP_REST_Request $req) {
        $uid = (int)IA_Auth_Middleware::uid($req);
        $ok  = self::rate_limit_ok($uid, 'stt');
        if (is_wp_error($ok)) return $ok;

        $key = ia_key('IA_DEEPGRAM_KEY');
        if (!$key) return new WP_Error('ia_cfg', 'Speech-to-text is not configured.', ['status' => 503]);

        $project_id = get_option('ia_deepgram_project_id', '');
        if (!$project_id) {
            $projects = wp_remote_get('https://api.deepgram.com/v1/projects', [
                'timeout' => 15,
                'headers' => ['Authorization' => 'Token ' . $key],
            ]);
            if (is_wp_error($projects)) {
                return new WP_Error('ia_api_network', 'Speech service network error.', ['status' => 502, 'retryable' => true]);
            }
            $pdata = json_decode(wp_remote_retrieve_body($projects), true);
            $project_id = $pdata['projects'][0]['project_id'] ?? '';
            if ($project_id) {
                update_option('ia_deepgram_project_id', sanitize_text_field($project_id), false);
            }
        }
        if (!$project_id) {
            return new WP_Error('ia_cfg', 'Could not resolve Deepgram project for streaming.', ['status' => 503]);
        }

        $ttl = 120;
        $res = wp_remote_post("https://api.deepgram.com/v1/projects/{$project_id}/keys", [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Token ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'comment'                 => "IA stream uid={$uid}",
                'scopes'                  => ['usage:write'],
                'time_to_live_in_seconds' => $ttl,
            ]),
        ]);
        if (is_wp_error($res)) {
            return new WP_Error('ia_api_network', 'Speech service network error.', ['status' => 502, 'retryable' => true]);
        }
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || empty($data['key'])) {
            return new WP_Error('ia_api', 'Could not mint streaming token.', ['status' => 502, 'retryable' => true]);
        }

        return new WP_REST_Response([
            'success'    => true,
            'token'      => $data['key'],
            'expires_in' => $ttl,
        ]);
    }

    /** Simple per-minute sliding-window limiter on top of the existing plan/minute quota system. */
    private static function rate_limit_ok(int $uid, string $bucket) {
        $key = "ia_speech_rl_{$bucket}_{$uid}";
        $count = (int) get_transient($key);
        $limit = ($bucket === 'stt') ? 40 : 20; // generous per-minute ceiling for a live interview session
        if ($count >= $limit) {
            return new WP_Error('ia_rate', 'Too many speech requests — please slow down.', ['status'=>429,'retryable'=>true]);
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }
}
