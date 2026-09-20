<?php
defined('ABSPATH') || exit;

/* ══════════════════════════════════════════════════════════════════
   PDF Report Generator — pure PHP, zero Composer deps
   Uses fpdf-style drawing primitives built from scratch
   Download protected by HMAC-SHA256 token
══════════════════════════════════════════════════════════════════ */
class IA_PDF_Report {

    /* ── Token generation for gated download ── */
    public static function generate_token(int $interview_id, int $uid): string {
        $secret = ia_jwt_secret();
        $exp    = time() + 3600; // 1-hour link
        $data   = "$interview_id:$uid:$exp";
        $sig    = hash_hmac('sha256', $data, $secret);
        return base64_encode("$data:$sig");
    }

    public static function verify_token(string $token): array|false {
        try {
            $dec  = base64_decode($token, true);
            if (!$dec) return false;
            $parts = explode(':', $dec);
            if (count($parts) !== 4) return false;
            [$iid, $uid, $exp, $sig] = $parts;
            if ((int)$exp < time()) return false;
            $data     = "$iid:$uid:$exp";
            $expected = hash_hmac('sha256', $data, ia_jwt_secret());
            if (!hash_equals($expected, $sig)) return false;
            return ['interview_id' => (int)$iid, 'user_id' => (int)$uid];
        } catch (\Throwable) {
            return false;
        }
    }

    /* ── REST endpoint registration ──
     * ROOT-CAUSE FIX: this method is invoked from inside the plugin's
     * `rest_api_init` callback (see interviewace.php). `init` fires once,
     * early in every request's load sequence, well BEFORE `rest_api_init`
     * ever runs — so an `add_action('init', ...)` executed from inside a
     * `rest_api_init` handler registers a callback for a hook that has
     * already finished firing on that same request. WordPress does not
     * retroactively invoke newly-added callbacks for a hook that already
     * ran, so `handle_download()` would NEVER execute on any request,
     * ever: the PDF download link the frontend generates would always
     * return a blank response instead of streaming the PDF. The download
     * handler must be bound to `init` directly at top-level plugin load
     * (see IA_PDF_Report::bind_download_handler(), called unconditionally
     * from interviewace.php outside of any other hook), not from inside
     * this REST-route registration method.
     */
    public static function register() {
        register_rest_route('ia/v1', '/reports/(?P<interview_id>\d+)/pdf-token', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'get_token'],
            'permission_callback' => ['IA_Auth_Middleware','validate'],
        ]);
    }

    /** Must be called directly (not from within another hook's callback) so it binds before `init` fires. */
    public static function bind_download_handler() {
        add_action('init', [__CLASS__, 'handle_download']);
    }

    public static function get_token(WP_REST_Request $req): WP_REST_Response|WP_Error {
        global $wpdb;
        $uid  = (int)IA_Auth_Middleware::uid($req);
        $iid  = (int)$req->get_param('interview_id');
        $owns = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}ia_interviews WHERE id=%d", $iid
        ));
        if ($owns !== $uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        $rep  = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}ia_reports WHERE interview_id=%d", $iid
        ));
        if (!$rep || $rep->status !== 'done')
            return new WP_Error('ia_404','Report not ready.',['status'=>404]);

        $tok  = self::generate_token($iid, $uid);
        $url  = add_query_arg(['ia_pdf'=>'1','tok'=>urlencode($tok)], home_url('/'));
        return new WP_REST_Response(['url'=>$url,'expires_in'=>3600]);
    }

    public static function handle_download(): void {
        if (empty($_GET['ia_pdf']) || empty($_GET['tok'])) return;
        $token  = sanitize_text_field(wp_unslash($_GET['tok']));
        $claims = self::verify_token($token);
        if (!$claims) { status_header(403); echo 'Link expired or invalid. Please generate a new download link.'; exit; }
        self::stream($claims['interview_id'], $claims['user_id']);
        exit;
    }

    /* ── PDF streaming ── */
    public static function stream(int $interview_id, int $uid): void {
        global $wpdb; $p = $wpdb->prefix;

        $iv   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_interviews WHERE id=%d AND user_id=%d", $interview_id, $uid));
        $rep  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_reports WHERE interview_id=%d", $interview_id));
        $prof = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_profiles WHERE user_id=%d", $uid));
        $user = get_userdata($uid);

        if (!$iv || !$rep || $rep->status !== 'done') { status_header(404); echo 'Report not found.'; return; }

        $r = [
            'name'              => $prof->name ?? ($user->display_name ?? 'Candidate'),
            'type'              => $iv->type,
            'company_pack'      => $iv->company_pack,
            'generated_at'      => $rep->generated_at,
            'overall_score'     => (int)$rep->overall_score,
            'comm_score'        => (int)$rep->comm_score,
            'tech_score'        => (int)$rep->tech_score,
            'conf_score'        => (int)$rep->conf_score,
            'recommendation'    => $rep->recommendation,
            'recommendation_reason' => $rep->recommendation_reason,
            'summary'           => $rep->summary,
            'strengths'         => json_decode($rep->strengths_json ?? '[]', true) ?: [],
            'weaknesses'        => json_decode($rep->weaknesses_json ?? '[]', true) ?: [],
            'improvements'      => json_decode($rep->improvements_json ?? '[]', true) ?: [],
            'question_evals'    => json_decode($rep->question_evaluations_json ?? '[]', true) ?: [],
            'hiring_radar'      => json_decode($rep->hiring_radar_json ?? '[]', true) ?: [],
            'comm_breakdown'    => json_decode($rep->communication_breakdown_json ?? '[]', true) ?: [],
            'wpm_avg'           => (int)$rep->wpm_avg,
            'wpm_feedback'      => $rep->wpm_feedback,
            'filler_total'      => json_decode($rep->filler_total_json ?? '[]', true) ?: [],
            'filler_feedback'   => $rep->filler_word_feedback,
            'star_compliance'   => $rep->star_compliance,
            'duration_min'      => (int)round($iv->duration_seconds / 60),
        ];

        $pdf = self::build($r);

        $fname = 'InterviewAce_Report_' . preg_replace('/[^a-z0-9]/i', '_', $r['type']) . '_' . date('Ymd', strtotime($r['generated_at'] ?? 'now')) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    /* ══════════════════════════════════════════════════════════════
       Pure-PHP PDF builder — no external libs
       Uses PDF 1.4 syntax with manually constructed content streams
    ══════════════════════════════════════════════════════════════ */
    private static function build(array $r): string {
        $pdf = new IA_PDF_Writer();

        /* ── Page 1: Header + scores ── */
        $pdf->add_page();
        // Purple header band
        $pdf->rect(0, 0, 595, 90, [0x5B,0x21,0xB6]);
        // Logo circle
        $pdf->circle(52, 45, 22, [0x7C,0x3A,0xED]);
        $pdf->text('IA', 44, 51, 16, [255,255,255], 'bold');
        // Title
        $pdf->text('InterviewAce', 85, 30, 22, [255,255,255], 'bold');
        $pdf->text('Official Interview Assessment Report', 85, 52, 11, [200,180,255]);
        // Candidate info on right
        $date_str = $r['generated_at'] ? date('d M Y', strtotime($r['generated_at'])) : date('d M Y');
        $pdf->text($r['name'], 350, 30, 13, [255,255,255], 'bold');
        $pdf->text($r['type'] . ($r['company_pack'] ? ' · ' . $r['company_pack'] : ''), 350, 46, 10, [200,180,255]);
        $pdf->text($date_str, 350, 60, 10, [200,180,255]);
        $pdf->text('Duration: ' . $r['duration_min'] . ' min', 350, 74, 10, [200,180,255]);

        // Score rings row
        $pdf->text('Performance Scores', 40, 110, 14, [40,20,80], 'bold');
        $scores = [
            [$r['overall_score'], 'Overall',       130, 145],
            [$r['comm_score'],    'Communication', 230, 145],
            [$r['tech_score'],    'Technical',     330, 145],
            [$r['conf_score'],    'Confidence',    430, 145],
        ];
        foreach ($scores as [$s, $lbl, $cx, $cy]) {
            $col = $s >= 70 ? [34,197,94] : ($s >= 50 ? [251,191,36] : [248,113,113]);
            $pdf->donut($cx, $cy, 32, 22, $s, $col);
            $pdf->text((string)$s, $cx - 8, $cy + 6, 14, $col, 'bold');
            $pdf->text($lbl, $cx - strlen($lbl)*3, $cy + 22, 8, [100,80,130]);
        }

        // Recommendation badge
        $reco_labels = ['strong_hire'=>'✓ Strong Hire','hire'=>'✓ Hire','borderline'=>'~ Borderline','reject'=>'× Needs Work'];
        $reco_cols   = ['strong_hire'=>[34,197,94],'hire'=>[52,211,153],'borderline'=>[251,191,36],'reject'=>[248,113,113]];
        $reco_str    = $reco_labels[$r['recommendation']] ?? $r['recommendation'];
        $reco_col    = $reco_cols[$r['recommendation']] ?? [150,150,150];
        $pdf->rect(40, 195, 200, 28, $reco_col, 4);
        $pdf->text($reco_str, 54, 213, 12, [255,255,255], 'bold');

        // Summary
        $pdf->text('Assessment Summary', 40, 244, 12, [40,20,80], 'bold');
        $pdf->rect(40, 252, 515, 1, [220,210,240]);
        $pdf->multiline_text($r['summary'] ?? '', 40, 262, 515, 10, [60,50,90]);

        // Strengths + Weaknesses side by side
        $ypos = 310;
        $pdf->text('✅  Strengths', 40, $ypos, 11, [34,150,80], 'bold');
        $pdf->text('📈  Areas to Improve', 310, $ypos, 11, [220,100,30], 'bold');
        $ypos += 14;
        foreach ($r['strengths'] as $s) {
            $pdf->multiline_text('• ' . $s, 40, $ypos, 250, 9, [50,80,50]);
            $ypos += 18;
        }
        $ypos2 = 324;
        foreach ($r['weaknesses'] as $w) {
            $pdf->multiline_text('• ' . $w, 310, $ypos2, 250, 9, [100,50,20]);
            $ypos2 += 18;
        }

        // Hiring radar bars
        $ybase = max($ypos, $ypos2) + 20;
        if ($ybase > 700) { $pdf->add_page(); $ybase = 40; }
        $pdf->text('Hiring Profile', 40, $ybase, 12, [40,20,80], 'bold');
        $pdf->rect(40, $ybase+8, 515, 1, [220,210,240]);
        $y = $ybase + 18;
        foreach ($r['hiring_radar'] as $key => $val) {
            $lbl = ucwords(str_replace('_',' ', $key));
            $col = $val >= 70 ? [34,197,94] : ($val >= 50 ? [251,191,36] : [248,113,113]);
            $pdf->text($lbl, 40, $y + 7, 9, [60,50,90]);
            $pdf->rect(180, $y, 280, 12, [235,230,245], 3);
            $pdf->rect(180, $y, (int)($val * 2.8), 12, $col, 3);
            $pdf->text((string)$val, 470, $y + 9, 9, $col, 'bold');
            $y += 18;
        }

        /* ── Page 2: Improvements + Q&A ── */
        $pdf->add_page();
        $pdf->rect(0, 0, 595, 36, [0x5B,0x21,0xB6]);
        $pdf->text('Improvement Plan', 40, 24, 14, [255,255,255], 'bold');
        $pdf->text($r['name'] . ' · ' . $r['type'], 350, 24, 10, [200,180,255]);

        $y = 55;
        foreach (array_slice($r['improvements'], 0, 6) as $imp) {
            $pdf->rect(40, $y, 515, 55, null, 5, [240,236,250]);
            $pdf->rect(40, $y, 4, 55, [0x7C,0x3A,0xED], 0, null, true);
            $pdf->text(strtoupper($imp['area'] ?? 'Area'), 52, $y + 12, 8, [0x7C,0x3A,0xED], 'bold');
            $pdf->multiline_text($imp['issue'] ?? '', 52, $y + 22, 490, 9, [60,50,90]);
            $pdf->text('→ ' . ($imp['suggestion'] ?? ''), 52, $y + 38, 9, [34,150,80]);
            $y += 64;
            if ($y > 680) { $pdf->add_page(); $y = 55; }
        }

        // Speaking metrics
        if ($r['wpm_avg'] > 0 || !empty($r['filler_total'])) {
            if ($y + 80 > 750) { $pdf->add_page(); $y = 55; }
            $pdf->text('Speaking Metrics', 40, $y, 12, [40,20,80], 'bold');
            $pdf->rect(40, $y+8, 515, 1, [220,210,240]);
            $y += 20;
            if ($r['wpm_avg'] > 0) {
                $pdf->text('Words per minute: ' . $r['wpm_avg'] . ' (target: 120–150)', 40, $y, 9, [60,50,90]);
                $y += 14;
                $pdf->multiline_text($r['wpm_feedback'] ?? '', 40, $y, 515, 9, [80,70,100]);
                $y += 20;
            }
            if (!empty($r['filler_total'])) {
                $total_fill = array_sum($r['filler_total']);
                $fill_str   = implode(', ', array_map(fn($w,$c) => "$w ($c)", array_keys($r['filler_total']), array_values($r['filler_total'])));
                $pdf->text("Filler words: $total_fill total — $fill_str", 40, $y, 9, [60,50,90]);
                $y += 14;
                $pdf->multiline_text($r['filler_feedback'] ?? '', 40, $y, 515, 9, [80,70,100]);
                $y += 14;
            }
        }

        /* ── Page 3: Question evaluations ── */
        if (!empty($r['question_evals'])) {
            $pdf->add_page();
            $pdf->rect(0, 0, 595, 36, [0x5B,0x21,0xB6]);
            $pdf->text('Question-by-Question Analysis', 40, 24, 14, [255,255,255], 'bold');
            $y = 50;
            foreach (array_slice($r['question_evals'], 0, 12) as $i => $q) {
                if ($y + 80 > 760) { $pdf->add_page(); $y = 40; }
                $sc    = (int)($q['score'] ?? 0);
                $scol  = $sc >= 7 ? [34,197,94] : ($sc >= 5 ? [251,191,36] : [248,113,113]);
                $pdf->rect(40, $y, 515, 75, null, 4, [245,242,252]);
                // Q number circle
                $pdf->circle(56, $y + 16, 10, [0x7C,0x3A,0xED]);
                $num = (string)($i+1);
                $pdf->text($num, 53 - (strlen($num)-1)*2, $y + 20, 8, [255,255,255], 'bold');
                // Score badge
                $pdf->rect(510, $y + 8, 34, 18, $scol, 3);
                $pdf->text($sc . '/10', 513, $y + 20, 9, [255,255,255], 'bold');
                $pdf->multiline_text($q['question'] ?? '', 72, $y + 9, 428, 9, [40,20,80]);
                $pdf->multiline_text('Your answer: ' . ($q['answer_summary'] ?? ''), 72, $y + 24, 428, 8, [80,70,100]);
                $pdf->multiline_text($q['feedback'] ?? '', 72, $y + 40, 428, 8, [60,50,90]);
                if (!empty($q['ideal_answer_hint'])) {
                    $pdf->text('💡 ' . $q['ideal_answer_hint'], 72, $y + 58, 8, [34,150,80]);
                }
                $y += 84;
            }
        }

        // Footer on last page
        $pdf->text('Generated by InterviewAce · interviewace.in · Confidential', 40, 812, 8, [150,140,170]);

        return $pdf->output();
    }
}

/* ══════════════════════════════════════════════════════════════════
   Minimal pure-PHP PDF writer
   Generates valid PDF 1.4 without any external library
══════════════════════════════════════════════════════════════════ */
class IA_PDF_Writer {
    private array  $pages     = [];
    private array  $streams   = [];
    private int    $cur_page  = -1;
    private array  $obj_refs  = [];
    private array  $obj_data  = [];
    private int    $next_obj  = 1;
    private string $buf       = '';

    public function add_page(): void {
        $this->pages[]    = '';
        $this->cur_page   = count($this->pages) - 1;
    }

    private function append(string $s): void {
        $this->pages[$this->cur_page] .= $s;
    }

    /* ── Drawing primitives ── */
    public function rect(float $x, float $y, float $w, float $h, ?array $fill=null, float $radius=0, ?array $fill2=null, bool $left_only=false): void {
        $fy = 842 - $y - $h; // PDF coordinates (bottom-up from 842 page height)
        $col = $fill2 ?? $fill;
        if ($col) $this->append(sprintf("%.3f %.3f %.3f rg\n", $col[0]/255, $col[1]/255, $col[2]/255));
        if ($radius > 0) {
            $k = 0.552284749831 * $radius;
            $this->append(sprintf("%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c f\n",
                $x+$radius, $fy,
                $x+$w-$radius, $fy, $x+$w, $fy, $x+$w, $fy+$radius,
                $x+$w, $fy+$h-$radius,
                $x+$w, $fy+$h, $x+$w-$radius, $fy+$h,
                $x+$radius, $fy+$h,
                $x, $fy+$h, $x, $fy+$h-$radius,
                $x, $fy+$radius,
                $x, $fy, $x+$radius, $fy
            ));
        } else {
            $this->append(sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $fy, $w, $h));
        }
        $this->append("0 g\n");
    }

    public function circle(float $cx, float $cy, float $r, array $col): void {
        $cy = 842 - $cy;
        $k  = 0.552284749831 * $r;
        $this->append(sprintf("%.3f %.3f %.3f rg\n", $col[0]/255, $col[1]/255, $col[2]/255));
        $this->append(sprintf("%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c f\n",
            $cx, $cy+$r,
            $cx+$k,$cy+$r, $cx+$r,$cy+$k, $cx+$r,$cy,
            $cx+$r,$cy-$k, $cx+$k,$cy-$r, $cx,$cy-$r,
            $cx-$k,$cy-$r, $cx-$r,$cy-$k, $cx-$r,$cy,
            $cx-$r,$cy+$k, $cx-$k,$cy+$r, $cx,$cy+$r
        ));
        $this->append("0 g\n");
    }

    public function donut(float $cx, float $cy, float $r_out, float $r_in, int $pct, array $col): void {
        // Background circle
        $this->circle($cx, $cy, $r_out, [235,230,245]);
        // Foreground — draw arc as many thin pie slices approximation
        $angle   = $pct / 100 * 360;
        $start   = 90; // start from top
        $steps   = max(1,(int)round($angle / 5));
        $cy_pdf  = 842 - $cy;
        $this->append(sprintf("%.3f %.3f %.3f rg\n", $col[0]/255, $col[1]/255, $col[2]/255));
        for ($i=0; $i<$steps; $i++) {
            $a1 = deg2rad($start - $i * ($angle/$steps));
            $a2 = deg2rad($start - ($i+1) * ($angle/$steps));
            $x1o = $cx + $r_out*cos($a1); $y1o = $cy_pdf + $r_out*sin($a1);
            $x2o = $cx + $r_out*cos($a2); $y2o = $cy_pdf + $r_out*sin($a2);
            $x1i = $cx + $r_in*cos($a2);  $y1i = $cy_pdf + $r_in*sin($a2);
            $x2i = $cx + $r_in*cos($a1);  $y2i = $cy_pdf + $r_in*sin($a1);
            $this->append(sprintf("%.2f %.2f m %.2f %.2f l %.2f %.2f l %.2f %.2f l f\n",
                $x1o,$y1o,$x2o,$y2o,$x1i,$y1i,$x2i,$y2i));
        }
        $this->append("0 g\n");
    }

    public function text(string $t, float $x, float $y, float $sz, array $col=[0,0,0], string $weight='normal'): void {
        $fy  = 842 - $y;
        $fnt = $weight === 'bold' ? 'F2' : 'F1';
        $this->append(sprintf("%.3f %.3f %.3f rg\nBT /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n0 g\n",
            $col[0]/255, $col[1]/255, $col[2]/255,
            $fnt, $sz, $x, $fy,
            $this->esc($t)
        ));
    }

    public function multiline_text(string $t, float $x, float $y, float $max_w, float $sz, array $col=[0,0,0]): void {
        $chars_per_line = (int)($max_w / ($sz * 0.52));
        $lines = [];
        $words = explode(' ', $t);
        $line  = '';
        foreach ($words as $w) {
            if (strlen($line . ' ' . $w) > $chars_per_line && $line !== '') {
                $lines[] = $line; $line = $w;
            } else {
                $line = $line === '' ? $w : $line . ' ' . $w;
            }
        }
        if ($line !== '') $lines[] = $line;
        foreach ($lines as $i => $l) {
            $this->text($l, $x, $y + $i * ($sz + 3), $sz, $col);
        }
    }

    private function esc(string $s): string {
        $s = mb_convert_encoding($s, 'ASCII', 'UTF-8');
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
    }

    /* ── PDF output ── */
    public function output(): string {
        $out   = "%PDF-1.4\n";
        $objs  = [];
        $xrefs = [];

        // Helper to add obj
        $add = function(string $content) use (&$objs, &$xrefs, &$out): int {
            $n = count($objs) + 1;
            $xrefs[$n] = strlen($out) + strlen(implode('', array_map(fn($o)=>$o, $objs)));
            $objs[$n]  = "$n 0 obj\n$content\nendobj\n";
            return $n;
        };

        // Fonts
        $f1 = $add("<</Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>");
        $f2 = $add("<</Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding>>");

        // Page resources dict
        $res_dict = "<</Font <</F1 $f1 0 R /F2 $f2 0 R>>>>";

        // Page tree kids
        $page_obj_ids = [];
        $pt_id = count($objs) + 1; // reserve slot for pages dict

        foreach ($this->pages as $stream) {
            $slen = strlen($stream);
            $sid  = $add("<</Length $slen>>\nstream\n$stream\nendstream");
            $pid  = $add("<</Type /Page /Parent $pt_id 0 R /MediaBox [0 0 595 842] /Contents $sid 0 R /Resources $res_dict>>");
            $page_obj_ids[] = "$pid 0 R";
        }

        $kids_str = implode(' ', $page_obj_ids);
        $np       = count($this->pages);
        $pages_id = $add("<</Type /Pages /Kids [$kids_str] /Count $np>>");

        $catalog_id = $add("<</Type /Catalog /Pages $pages_id 0 R>>");

        // Build xref
        $body  = implode('', $objs);
        $start = strlen("%PDF-1.4\n");
        $pos   = $start;
        $xref  = "xref\n0 " . (count($objs)+1) . "\n0000000000 65535 f \n";
        foreach ($objs as $n => $blob) {
            $xref .= sprintf("%010d 00000 n \n", $pos);
            $pos  += strlen($blob);
        }
        $xref_pos = $start + strlen($body);

        return "%PDF-1.4\n" . $body
             . $xref
             . "trailer\n<</Size " . (count($objs)+1) . " /Root $catalog_id 0 R>>\n"
             . "startxref\n$xref_pos\n%%EOF\n";
    }
}
