<?php
defined('ABSPATH') || exit;

class IA_Cost_Dashboard {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_ia_cost_data', [__CLASS__, 'ajax_cost_data']);
    }

    public static function add_menu() {
        add_submenu_page(
            'interviewace',
            'Cost Dashboard',
            '💰 Cost Dashboard',
            'manage_options',
            'interviewace-costs',
            [__CLASS__, 'render']
        );
    }

    public static function register_settings() {
        foreach (['claude_input','claude_output','deepgram_sec','elevenlabs_char'] as $k) {
            register_setting('ia_cost_rates_group', 'ia_cost_rate_'.$k, [
                'sanitize_callback' => fn($v) => (float)$v > 0 ? round((float)$v, 6) : null,
            ]);
        }
    }

    public static function ajax_cost_data() {
        if (!current_user_can('manage_options')) wp_send_json_error([], 403);
        check_ajax_referer('ia_cost_nonce', 'nonce');

        $month = sanitize_text_field($_POST['month'] ?? gmdate('Y-m'));
        [$y, $m] = explode('-', $month);
        $from  = "$y-$m-01";
        $to    = gmdate('Y-m-d', mktime(0,0,0,(int)$m+1,0,(int)$y));

        global $wpdb; $p = $wpdb->prefix;

        $daily     = IA_Cost_Tracker::daily_breakdown($month);
        $services  = IA_Cost_Tracker::service_breakdown($from, $to);
        $top_users = IA_Cost_Tracker::top_users($from, $to, 15);
        $cpi       = IA_Cost_Tracker::cost_per_interview($from, $to);
        $total     = IA_Cost_Tracker::total($from, $to);

        // Total interviews this month
        $iv_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ia_interviews
             WHERE status='completed' AND DATE_FORMAT(ended_at,'%%Y-%%m')=%s", $month
        ));

        // Active paying users
        $paying = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}ia_subscriptions WHERE status='active'"
        );

        // Revenue this month
        $revenue_paise = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_paise),0) FROM {$p}ia_payments
             WHERE status='captured' AND DATE_FORMAT(created_at,'%%Y-%%m')=%s", $month
        ));

        // Projected end-of-month cost based on daily average so far
        $days_so_far = count($daily);
        $days_in_month = (int)gmdate('t', mktime(0,0,0,(int)$m,1,(int)$y));
        $projected = $days_so_far > 0 ? (int)round(($total / $days_so_far) * $days_in_month) : 0;

        // Per-operation breakdown
        $ops = $wpdb->get_results($wpdb->prepare(
            "SELECT operation, service,
                    COUNT(*) as calls,
                    SUM(units) as total_units,
                    SUM(cost_paise) as total_paise
             FROM {$p}ia_api_costs
             WHERE recorded_at BETWEEN %s AND %s
             GROUP BY service, operation
             ORDER BY total_paise DESC",
            $from.' 00:00:00', $to.' 23:59:59'
        ), ARRAY_A);

        wp_send_json_success([
            'month'          => $month,
            'total_paise'    => $total,
            'total_fmt'      => IA_Cost_Tracker::fmt($total),
            'revenue_paise'  => $revenue_paise,
            'revenue_fmt'    => IA_Cost_Tracker::fmt($revenue_paise),
            'projected_paise'=> $projected,
            'projected_fmt'  => IA_Cost_Tracker::fmt($projected),
            'profit_paise'   => $revenue_paise - $total,
            'profit_fmt'     => IA_Cost_Tracker::fmt(abs($revenue_paise - $total)),
            'profit_sign'    => $revenue_paise >= $total ? '+' : '-',
            'cpi_paise'      => $cpi,
            'cpi_fmt'        => IA_Cost_Tracker::fmt($cpi),
            'iv_count'       => $iv_count,
            'paying_users'   => $paying,
            'daily'          => $daily,
            'services'       => $services,
            'top_users'      => $top_users,
            'operations'     => $ops,
        ]);
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('ia_cost_nonce');
        $cur_month = gmdate('Y-m');

        // Build month options (last 12 months)
        $months = [];
        for ($i=0; $i<12; $i++) {
            $ts  = mktime(0,0,0,(int)gmdate('m')-$i,1,(int)gmdate('Y'));
            $months[] = [gmdate('Y-m',$ts), gmdate('F Y',$ts)];
        }

        // Current API rates
        $rates = [
            'claude_input'    => get_option('ia_cost_rate_claude_input',    0.025),
            'claude_output'   => get_option('ia_cost_rate_claude_output',   0.126),
            'deepgram_sec'    => get_option('ia_cost_rate_deepgram_sec',    6.01),
            'elevenlabs_char' => get_option('ia_cost_rate_elevenlabs_char', 0.042),
        ];
        ?>
        <div class="wrap">
        <style>
        .ia-cost{max-width:1100px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
        .ia-cost h1{display:flex;align-items:center;gap:10px;margin-bottom:6px}
        .ia-cost .sub{color:#64748b;font-size:14px;margin-bottom:24px}
        .ia-kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:14px;margin-bottom:24px}
        .ia-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px}
        .ia-kpi__val{font-size:28px;font-weight:800;color:#1e293b;margin-bottom:3px}
        .ia-kpi__lbl{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
        .ia-kpi__sub{font-size:12px;color:#94a3b8;margin-top:3px}
        .ia-kpi--red   .ia-kpi__val{color:#dc2626}
        .ia-kpi--green .ia-kpi__val{color:#16a34a}
        .ia-kpi--purple.ia-kpi__val{color:#7c3aed}
        .ia-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:22px;margin-bottom:20px}
        .ia-panel h2{font-size:15px;font-weight:700;margin:0 0 18px;color:#1e293b}
        .ia-bar-wrap{display:flex;flex-direction:column;gap:12px}
        .ia-bar-row{display:grid;grid-template-columns:140px 1fr 80px;align-items:center;gap:10px;font-size:13px}
        .ia-bar-track{height:10px;background:#f1f5f9;border-radius:6px;overflow:hidden}
        .ia-bar-fill{height:100%;border-radius:6px;transition:width .8s ease}
        .ia-user-table{width:100%;border-collapse:collapse;font-size:13px}
        .ia-user-table th,.ia-user-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #f1f5f9}
        .ia-user-table th{font-weight:700;color:#64748b;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc}
        .ia-user-table tr:hover td{background:#fafafa}
        .ia-chart-wrap{height:160px;display:flex;align-items:flex-end;gap:4px;margin-top:8px}
        .ia-chart-bar{flex:1;border-radius:4px 4px 0 0;min-height:2px;position:relative;cursor:pointer;transition:opacity .15s}
        .ia-chart-bar:hover{opacity:.8}
        .ia-chart-bar .ia-tooltip{display:none;position:absolute;bottom:calc(100%+6px);left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;font-size:11px;padding:5px 9px;border-radius:6px;white-space:nowrap;z-index:10;pointer-events:none}
        .ia-chart-bar:hover .ia-tooltip{display:block}
        .ia-ops-table{width:100%;border-collapse:collapse;font-size:13px}
        .ia-ops-table th,.ia-ops-table td{padding:9px 12px;text-align:left;border-bottom:1px solid #f1f5f9}
        .ia-ops-table th{font-weight:700;color:#64748b;font-size:11.5px;text-transform:uppercase;background:#f8fafc}
        .ia-badge-svc{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
        .ia-badge-svc--claude{background:#ede9fe;color:#6d28d9}
        .ia-badge-svc--deepgram{background:#dbeafe;color:#1d4ed8}
        .ia-badge-svc--elevenlabs{background:#d1fae5;color:#065f46}
        .ia-rate-form{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
        .ia-rate-field{display:flex;flex-direction:column;gap:4px}
        .ia-rate-field label{font-size:12.5px;font-weight:600;color:#374151}
        .ia-rate-field input{width:100%;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:14px}
        .ia-rate-field .hint{font-size:11px;color:#94a3b8}
        .ia-month-sel{padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;background:#fff}
        #ia-loading{display:none;text-align:center;padding:40px;color:#64748b;font-size:14px}
        </style>

        <div class="ia-cost">
            <h1>💰 Cost Dashboard</h1>
            <p class="sub">Real-time API cost tracking — Claude · Deepgram · ElevenLabs</p>

            <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
                <label style="font-weight:600;font-size:13px;">Month:</label>
                <select id="ia-month" class="ia-month-sel" onchange="iaLoadCosts()">
                    <?php foreach ($months as [$val,$lbl]): ?>
                    <option value="<?php echo esc_attr($val); ?>"<?php selected($val,$cur_month); ?>>
                        <?php echo esc_html($lbl); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="iaLoadCosts()" class="button button-secondary">↻ Refresh</button>
            </div>

            <div id="ia-loading">Loading cost data…</div>
            <div id="ia-dashboard" style="display:none">

                <!-- KPIs -->
                <div class="ia-kpi-row" id="ia-kpis"></div>

                <!-- Daily chart -->
                <div class="ia-panel">
                    <h2>Daily spend <span id="ia-chart-month" style="font-weight:400;color:#64748b;font-size:13px;"></span></h2>
                    <div class="ia-chart-wrap" id="ia-daily-chart"></div>
                    <div style="display:flex;gap:16px;margin-top:10px;font-size:12px;">
                        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#7c3aed;margin-right:4px;"></span>Claude</span>
                        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#2563eb;margin-right:4px;"></span>Deepgram</span>
                        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#059669;margin-right:4px;"></span>ElevenLabs</span>
                    </div>
                </div>

                <!-- Service breakdown -->
                <div class="ia-panel">
                    <h2>Cost by service</h2>
                    <div class="ia-bar-wrap" id="ia-services"></div>
                </div>

                <!-- Operations breakdown -->
                <div class="ia-panel">
                    <h2>Cost by operation</h2>
                    <div id="ia-ops"></div>
                </div>

                <!-- Top users -->
                <div class="ia-panel">
                    <h2>Top 15 users by cost</h2>
                    <div id="ia-top-users"></div>
                </div>

            </div>

            <!-- API Rate Settings -->
            <div class="ia-panel">
                <h2>API rate configuration <span style="font-size:13px;font-weight:400;color:#64748b;">(paise per unit — update when vendor pricing changes)</span></h2>
                <form method="post" action="options.php">
                <?php settings_fields('ia_cost_rates_group'); ?>
                <div class="ia-rate-form">
                    <div class="ia-rate-field">
                        <label>Claude input tokens (paise/token)</label>
                        <input type="number" step="0.0001" name="ia_cost_rate_claude_input" value="<?php echo esc_attr($rates['claude_input']); ?>"/>
                        <span class="hint">Default 0.025 = $3/M tokens @ ₹84/$ · Sonnet 4.6 input</span>
                    </div>
                    <div class="ia-rate-field">
                        <label>Claude output tokens (paise/token)</label>
                        <input type="number" step="0.0001" name="ia_cost_rate_claude_output" value="<?php echo esc_attr($rates['claude_output']); ?>"/>
                        <span class="hint">Default 0.126 = $15/M tokens @ ₹84/$ · Sonnet 4.6 output</span>
                    </div>
                    <div class="ia-rate-field">
                        <label>Deepgram Nova-2 (paise/second)</label>
                        <input type="number" step="0.001" name="ia_cost_rate_deepgram_sec" value="<?php echo esc_attr($rates['deepgram_sec']); ?>"/>
                        <span class="hint">Default 6.01 = $0.0043/min @ ₹84/$</span>
                    </div>
                    <div class="ia-rate-field">
                        <label>ElevenLabs Turbo (paise/character)</label>
                        <input type="number" step="0.0001" name="ia_cost_rate_elevenlabs_char" value="<?php echo esc_attr($rates['elevenlabs_char']); ?>"/>
                        <span class="hint">Default 0.042 = $0.50/1k chars @ ₹84/$</span>
                    </div>
                </div>
                <p style="margin-top:18px;">
                    <button type="submit" class="button button-primary">Save Rates</button>
                    <span style="font-size:12px;color:#94a3b8;margin-left:12px;">Rate changes affect future cost calculations only — historical records are preserved.</span>
                </p>
                </form>
            </div>
        </div>

        <script>
        const IA_COST_NONCE = '<?php echo esc_js($nonce); ?>';
        const fmt = p => {
            if (p < 100)    return p + ' p';
            const r = p/100;
            if (r < 1000)   return '₹' + r.toFixed(2);
            if (r < 100000) return '₹' + (r/1000).toFixed(1) + 'K';
            return '₹' + (r/100000).toFixed(2) + 'L';
        };
        const svcColor = s => ({claude:'#7c3aed',deepgram:'#2563eb',elevenlabs:'#059669'}[s]||'#94a3b8');
        const svcName  = s => ({claude:'Claude AI',deepgram:'Deepgram STT',elevenlabs:'ElevenLabs TTS'}[s]||s);

        function iaLoadCosts() {
            const month = document.getElementById('ia-month').value;
            document.getElementById('ia-loading').style.display='block';
            document.getElementById('ia-dashboard').style.display='none';

            fetch(ajaxurl, {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action:'ia_cost_data',nonce:IA_COST_NONCE,month})
            })
            .then(r=>r.json())
            .then(({data:d}) => {
                renderKPIs(d);
                renderChart(d);
                renderServices(d.services);
                renderOps(d.operations);
                renderTopUsers(d.top_users);
                document.getElementById('ia-loading').style.display='none';
                document.getElementById('ia-dashboard').style.display='block';
                document.getElementById('ia-chart-month').textContent = '— ' + month;
            })
            .catch(e => {
                document.getElementById('ia-loading').innerHTML = '⚠ Error loading data: ' + e.message;
            });
        }

        function renderKPIs(d) {
            const profitColor = d.profit_sign==='+' ? 'green' : 'red';
            document.getElementById('ia-kpis').innerHTML = [
                {val:d.total_fmt,     lbl:'Total API cost',      sub:'This month',         cls:'red'},
                {val:d.revenue_fmt,   lbl:'Revenue (Razorpay)',  sub:'Subscriptions billed',cls:'green'},
                {val:d.profit_sign+d.profit_fmt, lbl:'Gross profit', sub:'Revenue minus API cost',cls:profitColor},
                {val:d.projected_fmt, lbl:'Projected month-end', sub:'Based on daily avg',  cls:''},
                {val:d.cpi_fmt,       lbl:'Cost per interview',  sub:'Avg this month',      cls:''},
                {val:d.iv_count,      lbl:'Interviews completed', sub:'This month',          cls:''},
                {val:d.paying_users,  lbl:'Paying subscribers',  sub:'Currently active',    cls:'purple'},
            ].map(k=>`<div class="ia-kpi ia-kpi--${k.cls}">
                <div class="ia-kpi__val">${k.val}</div>
                <div class="ia-kpi__lbl">${k.lbl}</div>
                <div class="ia-kpi__sub">${k.sub}</div>
            </div>`).join('');
        }

        function renderChart(d) {
            const days = d.daily || [];
            if (!days.length) { document.getElementById('ia-daily-chart').innerHTML='<p style="color:#94a3b8;padding:20px 0">No data for this month yet.</p>'; return; }
            const maxP = Math.max(...days.map(x=>+x.total_paise));
            document.getElementById('ia-daily-chart').innerHTML = days.map(day => {
                const tot = +day.total_paise, cl = +day.claude_paise, dg = +day.deepgram_paise, el = +day.elevenlabs_paise;
                const hPct = maxP > 0 ? (tot/maxP*100) : 0;
                const dt   = new Date(day.date).toLocaleDateString('en-IN',{day:'numeric',month:'short'});
                const tip  = `${dt}\nTotal: ${fmt(tot)}\nClaude: ${fmt(cl)}\nDG: ${fmt(dg)}\nEL: ${fmt(el)}\n${day.interviews} interviews`;
                // Stacked bar using gradient-like approach
                return `<div class="ia-chart-bar" style="height:${Math.max(2,hPct)}%;background:linear-gradient(to top,#059669 0%,#059669 ${el/Math.max(1,tot)*100}%,#2563eb ${el/Math.max(1,tot)*100}%,#2563eb ${(el+dg)/Math.max(1,tot)*100}%,#7c3aed ${(el+dg)/Math.max(1,tot)*100}%,#7c3aed 100%)">
                    <div class="ia-tooltip">${tip.replace(/\n/g,'<br>')}</div>
                </div>`;
            }).join('');
        }

        function renderServices(svcs) {
            if (!svcs || !svcs.length) { document.getElementById('ia-services').innerHTML='<p style="color:#94a3b8">No data yet.</p>'; return; }
            const maxP = Math.max(...svcs.map(s=>+s.total_paise));
            document.getElementById('ia-services').innerHTML = svcs.map(s => `
                <div class="ia-bar-row">
                    <div><span class="ia-badge-svc ia-badge-svc--${s.service}">${svcName(s.service)}</span></div>
                    <div class="ia-bar-track"><div class="ia-bar-fill" style="width:${maxP>0?(+s.total_paise/maxP*100):0}%;background:${svcColor(s.service)}"></div></div>
                    <div style="font-weight:700;text-align:right;color:${svcColor(s.service)}">${fmt(+s.total_paise)}</div>
                </div>
            `).join('');
        }

        function renderOps(ops) {
            if (!ops || !ops.length) { document.getElementById('ia-ops').innerHTML='<p style="color:#94a3b8">No data yet.</p>'; return; }
            document.getElementById('ia-ops').innerHTML = `<table class="ia-ops-table">
                <thead><tr><th>Service</th><th>Operation</th><th>Calls</th><th>Units</th><th>Cost</th></tr></thead>
                <tbody>${ops.map(o=>`<tr>
                    <td><span class="ia-badge-svc ia-badge-svc--${o.service}">${svcName(o.service)}</span></td>
                    <td style="font-family:monospace;font-size:12px">${o.operation}</td>
                    <td>${Number(o.calls).toLocaleString()}</td>
                    <td>${Number(o.total_units).toLocaleString()}</td>
                    <td style="font-weight:700;color:${svcColor(o.service)}">${fmt(+o.total_paise)}</td>
                </tr>`).join('')}</tbody>
            </table>`;
        }

        function renderTopUsers(users) {
            if (!users || !users.length) { document.getElementById('ia-top-users').innerHTML='<p style="color:#94a3b8">No data yet.</p>'; return; }
            document.getElementById('ia-top-users').innerHTML = `<table class="ia-user-table">
                <thead><tr><th>#</th><th>User</th><th>Plan</th><th>Interviews</th><th>API cost</th><th>Cost/interview</th></tr></thead>
                <tbody>${users.map((u,i)=>{
                    const cpi = u.interviews>0 ? Math.round(u.total_paise/u.interviews) : 0;
                    const plan = u.plan||'free';
                    const planBadge = {pro:'⭐ Pro',premium:'💎 Premium',b2b:'🏢 B2B',free:'Free'}[plan]||plan;
                    return `<tr>
                        <td style="color:#94a3b8;font-weight:600">${i+1}</td>
                        <td>
                            <div style="font-weight:600">${escH(u.display_name||'—')}</div>
                            <div style="font-size:11px;color:#94a3b8">${escH(u.user_email||'')}</div>
                        </td>
                        <td><span style="font-size:12px">${planBadge}</span></td>
                        <td>${u.interviews}</td>
                        <td style="font-weight:700;color:#dc2626">${fmt(+u.total_paise)}</td>
                        <td style="color:#64748b">${fmt(cpi)}</td>
                    </tr>`;
                }).join('')}</tbody>
            </table>`;
        }

        function escH(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

        // Auto-load on page ready
        document.addEventListener('DOMContentLoaded', iaLoadCosts);
        </script>
        </div>
        <?php
    }
}
