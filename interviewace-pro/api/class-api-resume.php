<?php
defined('ABSPATH') || exit;

class IA_API_Resume {
    public static function register(){
        register_rest_route('ia/v1','/resume/upload',['methods'=>'POST','callback'=>[__CLASS__,'upload'],'permission_callback'=>['IA_Auth_Middleware','validate']]);
    }
    public static function upload(WP_REST_Request $req){
        $uid = IA_Auth_Middleware::uid($req);
        if (empty($_FILES['resume'])) return new WP_Error('ia_file','No file uploaded.',['status'=>400]);
        $file = $_FILES['resume'];
        if ($file['size']>5*1024*1024) return new WP_Error('ia_size','File must be under 5MB.',['status'=>413]);
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi,$file['tmp_name']);
        finfo_close($fi);
        $ok   = ['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($mime,$ok)) return new WP_Error('ia_type','Only PDF and DOCX supported.',['status'=>400]);
        $up   = wp_upload_dir();
        $dir  = $up['basedir'].'/interviewace/resumes/'.$uid.'/';
        wp_mkdir_p($dir);
        $htx  = $up['basedir'].'/interviewace/.htaccess';
        if (!file_exists($htx)) file_put_contents($htx,"deny from all\n");
        $ext  = $mime==='application/pdf'?'pdf':'docx';
        $name = 'resume_'.time().bin2hex(random_bytes(4)).'.'.$ext;
        $path = $dir.$name;
        if (!move_uploaded_file($file['tmp_name'],$path)) return new WP_Error('ia_save','Failed to save file.',['status'=>500]);
        $text = $ext==='pdf'?self::pdf_text($path):self::docx_text($path);
        $parsed = IA_Claude::parse_resume($text);
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}ia_profiles",[
            'resume_url'         => $up['baseurl'].'/interviewace/resumes/'.$uid.'/'.$name,
            'resume_text'        => $text,
            'resume_parsed_json' => is_wp_error($parsed)?null:wp_json_encode($parsed),
        ],['user_id'=>$uid]);
        return new WP_REST_Response(['success'=>true,'parsed'=>is_wp_error($parsed)?null:$parsed]);
    }
    /**
     * SECURITY/ROBUSTNESS FIX: previously shelled out to pdftotext with no
     * timeout or resource cap. poppler-utils (which provides pdftotext) has
     * a real CVE history for malformed-PDF DoS/memory exhaustion, and an
     * unbounded exec() on a shared WordPress host means one malicious
     * upload could hang a worker process indefinitely. Wrapped with the
     * `timeout` coreutil (kills the process after 20s) and `ulimit -v` to
     * cap virtual memory for the subprocess. If either wrapper command
     * isn't available on the host, falls straight through to the existing
     * pure-PHP regex extraction fallback below rather than running
     * unbounded — never silently drops the resource limit.
     */
    private static function pdf_text(string $path){
        if (function_exists('exec') && self::shell_cmd_exists('timeout') && self::shell_cmd_exists('pdftotext')) {
            $tmp = tempnam(sys_get_temp_dir(),'ia_');
            $cmd = 'timeout 20 bash -c '.escapeshellarg(
                'ulimit -v 262144; pdftotext -layout '.escapeshellarg($path).' '.escapeshellarg($tmp)
            ).' 2>/dev/null';
            @exec($cmd);
            if (file_exists($tmp)&&$t=file_get_contents($tmp)){@unlink($tmp);return $t;}
            @unlink($tmp);
        }
        $c = file_get_contents($path);
        preg_match_all('/BT\s+(.+?)\s+ET/s',$c,$m);
        $t='';
        foreach($m[1] as $b){preg_match_all('/\(([^)]+)\)\s*Tj/',$b,$p);$t.=implode(' ',$p[1])."\n";}
        return $t?:'[PDF text extraction unavailable]';
    }
    private static function shell_cmd_exists(string $cmd): bool {
        static $cache = [];
        if (isset($cache[$cmd])) return $cache[$cmd];
        $out = @shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null');
        return $cache[$cmd] = !empty(trim((string)$out));
    }

    private static function docx_text(string $path){
        $zip=new ZipArchive;
        if($zip->open($path)!==true) return '[Cannot open DOCX]';
        $xml=$zip->getFromName('word/document.xml');$zip->close();
        if(!$xml) return '[Cannot read DOCX]';
        return preg_replace('/\s+/',' ',trim(html_entity_decode(strip_tags($xml),ENT_QUOTES|ENT_XML1,'UTF-8')));
    }
}

// ═══════════════════════════════════════════
// INTERVIEWS
// ═══════════════════════════════════════════
