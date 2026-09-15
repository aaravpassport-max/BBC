<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('administrator') && !current_user_can('rto_admin')) {
    wp_redirect(home_url('/rto-login/')); exit;
}

$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
global $wpdb;
$p = $wpdb->prefix;

// Known Limitations audit fix: these six COUNT/SUM queries ran uncached on
// every single load of this navigation/links screen. Applying the same
// short-TTL transient pattern already used for the Dashboard's KPI tiles —
// a 60s TTL keeps this banner close to live (far tighter than the
// Dashboard's own 3-minute cache) while sparing repeat page views the
// full six-query cost, which is what actually matters on a large dataset.
$stats = get_transient('rtoflow_nav_stats_v1');
if ($stats === false) {
    $stats = [
        'total_leads'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE deleted_at IS NULL"),
        'active_vendors' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_vendors WHERE status='active'"),
        'total_cities'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_cities WHERE is_active=1"),
        'total_services' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_services WHERE is_active=1"),
        'open_complaints'=> (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_complaints WHERE status NOT IN ('closed','resolved')"),
        'today_revenue'  => (float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$p}rto_payments WHERE DATE(created_at)=CURDATE() AND status='completed'"),
    ];
    set_transient('rtoflow_nav_stats_v1', $stats, 60);
}
?>
<!-- Part 5.2 UI/UX audit fix: .rto-nav-section/-grid/-card(-icon/-text/-title/
     -sub), .rto-stat-banner/-item/-num/-label, .rto-url-box, and
     .rto-copy-btn used to be defined ONLY here, inline, for this one
     screen — the same "component-specific CSS, at risk of drifting"
     pattern flagged and centralized in admin.css. Only .rto-nav-page
     (this screen's own outer max-width wrapper) is genuinely specific to
     this one page and stays local. -->
<style>
.rto-nav-page{max-width:1100px;margin:0 auto;padding:0}
</style>

<div class="rto-nav-page">
  <!-- Welcome banner -->
  <div style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;border-radius:12px;padding:22px 24px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
    <div>
      <h2 style="font-size:20px;font-weight:900;margin-bottom:4px">Welcome to <?= esc_html($company) ?> Admin</h2>
      <p style="opacity:.85;font-size:13px">RTOFLOW OS v<?= RTOFLOW_VERSION ?> · All systems operational</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?= esc_url(home_url('/rto-admin/leads/')) ?>" style="background:#E97B28;color:#fff;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none">+ New Order</a>
      <a href="<?= esc_url(home_url('/rto-apply/')) ?>" style="background:rgba(255,255,255,.15);color:#fff;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid rgba(255,255,255,.3)">→ Apply Form</a>
    </div>
  </div>

  <!-- Quick Stats -->
  <div class="rto-stat-banner">
    <div class="rto-stat-item"><div class="rto-stat-num"><?= number_format($stats['total_leads']) ?></div><div class="rto-stat-label">Total Orders</div></div>
    <div class="rto-stat-item"><div class="rto-stat-num"><?= number_format($stats['active_vendors']) ?></div><div class="rto-stat-label">Active Vendors</div></div>
    <div class="rto-stat-item"><div class="rto-stat-num"><?= number_format($stats['total_services']) ?></div><div class="rto-stat-label">Services</div></div>
    <div class="rto-stat-item"><div class="rto-stat-num"><?= number_format($stats['total_cities']) ?></div><div class="rto-stat-label">Active Cities</div></div>
    <div class="rto-stat-item"><div class="rto-stat-num" style="color:<?= $stats['open_complaints']>0?'#DC2626':'#16A34A' ?>"><?= number_format($stats['open_complaints']) ?></div><div class="rto-stat-label">Open Complaints</div></div>
    <div class="rto-stat-item"><div class="rto-stat-num" style="color:#16A34A">₹<?= number_format($stats['today_revenue'],0) ?></div><div class="rto-stat-label">Today Revenue</div></div>
  </div>

  <!-- Operations -->
  <div class="rto-nav-section">
    <div class="rto-nav-section-title">📋 Operations</div>
    <div class="rto-nav-grid">
      <?php
      $ops = [
        [home_url('/rto-admin/'),          '#EFF6FF','#2563EB','📊','Dashboard',        'Overview & KPIs'],
        [home_url('/rto-admin/leads/'),    '#F0FDF4','#16A34A','📋','All Orders',        'View & manage leads'],
        [home_url('/rto-admin/vendors/'),  '#FFF7ED','#EA580C','👷','Vendors',           'Manage vendor agents'],
        [home_url('/rto-admin/payments/'), '#FFFBEB','#D97706','💳','Payments',          'Payment records'],
        [home_url('/rto-admin/payouts/'),  '#F5F3FF','#7C3AED','💸','Payouts',           'Vendor payout reports'],
        [home_url('/rto-admin/reports/'),  '#F0FDFA','#0D9488','📈','Reports',           'Analytics & exports'],
      ];
      foreach ($ops as [$url,$bg,$col,$icon,$title,$sub]):
      ?>
      <a href="<?= esc_url($url) ?>" class="rto-nav-card">
        <div class="rto-nav-card-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><?= $icon ?></div>
        <div class="rto-nav-card-text"><div class="rto-nav-card-title"><?= esc_html($title) ?></div><div class="rto-nav-card-sub"><?= esc_html($sub) ?></div></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Configuration -->
  <div class="rto-nav-section">
    <div class="rto-nav-section-title">⚙️ Configuration</div>
    <div class="rto-nav-grid">
      <?php
      $cfg = [
        [home_url('/rto-admin/settings/'),                    '#EFF6FF','#2563EB','⚙','Settings',          'Company, payments, SMS, WhatsApp'],
        [home_url('/rto-admin/services/'),                    '#F0FDF4','#16A34A','🛠','Services & Pricing','Manage all 30+ services'],
        [home_url('/rto-admin/masters/'),                     '#FFF7ED','#EA580C','🗺','Cities & RTOs',     'Enable cities, manage RTOs'],
        [home_url('/rto-admin/?rto_area=admin&rto_page=features'), '#F5F3FF','#7C3AED','🎛','Feature Flags',     'Enable/disable modules'],
        [home_url('/rto-admin/email-templates/'),             '#F0FDFA','#0D9488','✉','Email Templates',   'Notification templates'],
        [home_url('/rto-admin/?rto_area=admin&rto_page=automation'),'#FFFBEB','#D97706','🤖','Automation',       'Rule-based automation'],
        [home_url('/rto-admin/webhooks/'),                    '#F0F9FF','#0284C7','🔗','Webhooks',          'Outbound event notifications'],
        [home_url('/rto-admin/client-requests/'),             '#FEF2F2','#DC2626','📮','Client Requests',   'Client-submitted cancellation/refund requests'],
        [home_url('/rto-admin/staff-roles/'),                 '#F5F3FF','#7C3AED','🛡','Staff Roles',       'Define permission matrices for staff roles'],
        [home_url('/rto-admin/exceptions/'),                  '#FEF2F2','#DC2626','🐞','Exceptions',        'Captured errors and failures, platform-wide'],
        [home_url('/rto-admin/backups/'),                     '#ECFDF5','#059669','💾','Backups',           'Weekly automatic database backups, on-demand too'],
        [home_url('/rto-admin/config-approvals/'),            '#FFFBEB','#D97706','🔏','Config Approvals',  'Maker-checker approval for Payment/SMS/WhatsApp settings'],
        [home_url('/rto-admin/partner-api/'),                 '#EEF2FF','#4F46E5','🔌','Partner API',       'Issue/revoke API keys for external integrations'],
        [home_url('/rto-admin/inbound-webhooks/'),            '#F0F9FF','#0284C7','📥','Inbound Webhooks',  'Generic signature-verified inbound integration framework'],
        [home_url('/rto-admin/data-export/'),                 '#ECFDF5','#059669','📤','Data Export',       'Scheduled BI/warehouse export of core tables'],
        [home_url('/rto-admin/eligibility/'),                 '#FEF2F2','#DC2626','✅','Eligibility Rules', 'Configure who qualifies per service'],
        [home_url('/rto-admin/forms/'),                       '#ECFDF5','#059669','📝','Dynamic Forms',     'Define fields per service, no code'],
        [home_url('/rto-admin/workflows/'),                   '#EEF2FF','#4F46E5','🔀','Workflow Engine',   'Custom status lifecycle per service'],
        [home_url('/rto-admin/customizer/'),                  '#FDF4FF','#A21CAF','🧩','Customizer Hub',    'All configuration engines in one place'],
      ];
      foreach ($cfg as [$url,$bg,$col,$icon,$title,$sub]):
      ?>
      <a href="<?= esc_url($url) ?>" class="rto-nav-card">
        <div class="rto-nav-card-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><?= $icon ?></div>
        <div class="rto-nav-card-text"><div class="rto-nav-card-title"><?= esc_html($title) ?></div><div class="rto-nav-card-sub"><?= esc_html($sub) ?></div></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Support & Monitoring -->
  <div class="rto-nav-section">
    <div class="rto-nav-section-title">🔍 Support & Monitoring</div>
    <div class="rto-nav-grid">
      <?php
      $sup = [
        [home_url('/rto-admin/complaints/'),                 '#FEF2F2','#DC2626','📣','Complaints',        'Grievance management'],
        [home_url('/rto-admin/ratings/'),                    '#FFFBEB','#D97706','⭐','Ratings',            'Client & vendor ratings'],
        [home_url('/rto-admin/?rto_area=admin&rto_page=ai'), '#EFF6FF','#2563EB','🤖','AI Insights',        'Risk scores & analytics'],
        [home_url('/rto-admin/staff/'),                      '#F0FDF4','#16A34A','👥','Staff & Users',      'Manage all users'],
        [admin_url('admin.php?page=rtoflow-setup'),          '#F5F3FF','#7C3AED','🔧','Setup & Status',     'Plugin health check'],
        [home_url('/rto-health/?token='),                    '#F0FDFA','#0D9488','❤','Health Check',       'System status endpoint'],
      ];
      if (rto_is_admin()) {
          $sup[] = [home_url('/rto-admin/audit-log/'), '#FDF4FF','#A21CAF','🕵','Audit Log', 'Global action/change history'];
      }
      foreach ($sup as [$url,$bg,$col,$icon,$title,$sub]):
      ?>
      <a href="<?= esc_url($url) ?>" class="rto-nav-card">
        <div class="rto-nav-card-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><?= $icon ?></div>
        <div class="rto-nav-card-text"><div class="rto-nav-card-title"><?= esc_html($title) ?></div><div class="rto-nav-card-sub"><?= esc_html($sub) ?></div></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Frontend / Public URLs -->
  <div class="rto-nav-section">
    <div class="rto-nav-section-title">🌐 Frontend URLs</div>
    <div style="display:grid;gap:8px">
      <?php
      $urls = [
        ['Apply Form (Public)',        home_url('/rto-apply/'),             'Share with clients to submit requests'],
        ['Login Page',                 home_url('/rto-login/'),             'Custom standalone login — no WP login'],
        ['Client Dashboard',           home_url('/rto-dashboard/'),         'After client login'],
        ['Vendor Portal',              home_url('/rto-vendor/'),            'After vendor login'],
        ['Admin Panel',                home_url('/rto-admin/'),             'Staff & admin access'],
        ['Pricing Page',               home_url('/pricing'),                'Public pricing listing'],
        ['How It Works',               home_url('/how-it-works'),           'Public information page'],
        ['All Cities',                 home_url('/rto-services-cities'),    'City coverage page'],
        ['Contact',                    home_url('/contact'),                'Contact form'],
      ];
      foreach ($urls as [$label,$url,$hint]):
      ?>
      <div class="rto-url-box">
        <div>
          <span style="font-weight:700;color:#374151"><?= esc_html($label) ?></span>
          <span style="color:#94a3b8;margin:0 8px">·</span>
          <a href="<?= esc_url($url) ?>" target="_blank"><?= esc_html($url) ?></a>
          <span style="color:#94a3b8;font-size:11px;margin-left:8px"><?= esc_html($hint) ?></span>
        </div>
        <button type="button" class="rto-copy-btn" aria-label="Copy URL: <?= esc_attr($label) ?>" data-copy-url="<?= esc_attr($url) ?>">Copy</button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Setup Status -->
  <div class="rto-nav-section">
    <div class="rto-nav-section-title">✅ Setup Checklist</div>
    <div style="background:#fff;border-radius:10px;border:1px solid #e2e8f0;overflow:hidden">
      <?php
      $phone_set   = !empty(get_option('rtoflow_company_phone'));
      $email_set   = !empty(get_option('rtoflow_support_email'));
      // Known Limitations audit fix: the ≥10 thresholds are a heuristic
      // matched to this plugin's own default seeded catalog, not a rule
      // every installation must meet — an admin running a smaller,
      // intentionally curated catalog can now acknowledge that once and
      // stop seeing it flagged, instead of the threshold being permanent
      // and unappeasable.
      $services_ack = !empty(get_option('rtoflow_setup_ack_services'));
      $cities_ack   = !empty(get_option('rtoflow_setup_ack_cities'));
      $has_services= $stats['total_services'] >= 10 || $services_ack;
      $has_cities  = $stats['total_cities'] >= 10 || $cities_ack;
      $has_vendors = $stats['active_vendors'] >= 1;
      $rp_key      = !empty(get_option('rtoflow_razorpay_key_id'));
      $checks = [
        [$has_services,  'Services seeded',           $services_ack && $stats['total_services'] < 10 ? $stats['total_services'].' services — acknowledged as intentional' : ($has_services ? $stats['total_services'].' services ready' : 'Resave settings to trigger seeder'), 'services'],
        [$has_cities,    'Cities seeded',              $cities_ack && $stats['total_cities'] < 10 ? $stats['total_cities'].' cities — acknowledged as intentional' : ($has_cities ? $stats['total_cities'].' cities active' : 'Resave settings to trigger seeder'), 'cities'],
        [$phone_set,     'Company phone set',          $phone_set ? get_option('rtoflow_company_phone') : 'Go to Settings → Company', null],
        [$email_set,     'Support email set',          $email_set ? get_option('rtoflow_support_email') : 'Go to Settings → Company', null],
        [$has_vendors,   'At least one vendor added',  $has_vendors ? $stats['active_vendors'].' active vendors' : 'Go to Vendors → Add Vendor', null],
        [$rp_key,        'Razorpay configured',        $rp_key ? 'Payment gateway ready' : 'Optional: Settings → Payment', null],
      ];
      foreach ($checks as [$ok,$label,$detail,$checkKey]):
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f1f5f9">
        <span style="font-size:16px"><?= $ok ? '✅' : '⚠️' ?></span>
        <div style="flex:1">
          <div style="font-size:13px;font-weight:600;color:<?= $ok?'#0f172a':'#92400E' ?>"><?= esc_html($label) ?></div>
          <div style="font-size:11px;color:#94a3b8"><?= esc_html($detail) ?></div>
        </div>
        <?php if (!$ok): ?>
          <a href="<?= esc_url(home_url('/rto-admin/settings/')) ?>" style="font-size:11px;color:#2563EB;text-decoration:none;font-weight:600">Fix →</a>
          <?php if ($checkKey): ?>
          <button type="button" class="rto-btn rto-btn--sm setup-ack-btn" data-check="<?= esc_attr($checkKey) ?>" style="font-size:11px;margin-left:6px" title="Dismiss permanently if your catalog is intentionally smaller than the default threshold">This is intentional</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
  // CSP fix: 'nonce-...' only covers <script> elements, not inline
  // onclick= attributes — bind copy-URL buttons via addEventListener.
  document.querySelectorAll('.rto-copy-btn[data-copy-url]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var self = this;
      navigator.clipboard.writeText(btn.dataset.copyUrl).then(function(){
        self.textContent = 'Copied!';
        setTimeout(function(){ self.textContent = 'Copy'; }, 1500);
      });
    });
  });
  document.querySelectorAll('.setup-ack-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Stop flagging this check permanently? You can only re-enable it by growing your catalog past the threshold.')) return;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','dismiss_setup_warning');
      fd.append('check', btn.dataset.check);
      fd.append('rto_nonce', (window.rtoflowAdmin||{}).nonce || '');
      btn.disabled = true;
      fetch((window.rtoflowAdmin||{}).ajax_url || '/wp-admin/admin-ajax.php', {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (r.success) { location.reload(); } else { alert(r.message || 'Failed.'); btn.disabled = false; }
        }).catch(function(){ btn.disabled = false; });
    });
  });
  </script>

  <?php rto_help_box('nav'); ?>
</div>
