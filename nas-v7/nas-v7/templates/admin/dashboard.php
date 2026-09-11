<?php
/**
 * NAS Admin Dashboard Template
 * Suppress theme chrome on this page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Suppress WordPress theme header/footer/admin bar
add_action('wp_head', function() { ?>
<style id="nas-theme-suppress">
  #wpadminbar { display:none!important }
  html { margin-top:0!important }
  body.nas-fullpage > header,
  body.nas-fullpage > #masthead,
  body.nas-fullpage > .site-header,
  body.nas-fullpage > .ast-above-header-wrap,
  body.nas-fullpage > .ast-header,
  body.nas-fullpage > footer,
  body.nas-fullpage > .site-footer,
  body.nas-fullpage > #colophon { display:none!important }
</style>
<?php }, 1);

$nav_links = [
  [ 'label' => 'Admin Panel',  'url' => nas_get_page_url('nas_page_admin_dashboard','/admin-dashboard/'), 'active' => true ],
  [ 'label' => 'Staff Panel',  'url' => nas_get_page_url('nas_page_staff_dashboard','/staff-dashboard/') ],
  [ 'label' => 'Moderation',   'url' => nas_get_page_url('nas_page_moderation_dashboard','/moderation-dashboard/') ],
];
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<div class="nas-admin-wrap" id="nas-admin-dashboard">

  <!-- Sidebar -->
  <aside class="nas-admin-sidebar">
    <div class="nas-sidebar-logo"><span>NAS Admin</span></div>
    <nav class="nas-sidebar-nav">
      <button class="nas-admin-tab-btn active" data-panel="nas-analytics-panel">
        <i class="fa-solid fa-chart-line"></i> Analytics
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-admin-bookings-panel">
        <i class="fa-solid fa-list-check"></i> Bookings
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-clients-panel">
        <i class="fa-solid fa-users"></i> Clients
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-newspapers-panel">
        <i class="fa-solid fa-newspaper"></i> Newspapers
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-rate-cards-panel">
        <i class="fa-solid fa-tags"></i> Rate Cards
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-cities-panel">
        <i class="fa-solid fa-city"></i> Cities
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-categories-panel">
        <i class="fa-solid fa-grid-2"></i> Categories
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-vendors-panel">
        <i class="fa-solid fa-truck-ramp-box"></i> Vendors
      </button>
      <button class="nas-admin-tab-btn" data-panel="nas-settings-panel">
        <i class="fa-solid fa-gear"></i> Settings
      </button>
    </nav>
  </aside>

  <!-- Main -->
  <main class="nas-admin-main">

    <!-- ── Analytics ────────────────────────────────────────────────── -->
    <div class="nas-admin-panel active" id="nas-analytics-panel">
      <div class="nas-panel-header"><h2>Analytics Overview</h2></div>

      <div class="nas-stats-grid">
        <?php
        $stats = [
          [ 'id' => 'anal-total-bookings', 'label' => 'Total Bookings',   'icon' => 'fa-list-check',          'color' => '#2563eb' ],
          [ 'id' => 'anal-total-revenue',  'label' => 'Total Revenue',     'icon' => 'fa-indian-rupee-sign',   'color' => '#7c3aed' ],
          [ 'id' => 'anal-active',         'label' => 'Active Bookings',   'icon' => 'fa-spinner',             'color' => '#ca8a04' ],
          [ 'id' => 'anal-completed',      'label' => 'Completed',         'icon' => 'fa-circle-check',        'color' => '#059669' ],
          [ 'id' => 'anal-pending-payment','label' => 'Pending Payment',   'icon' => 'fa-clock',               'color' => '#ea580c' ],
          [ 'id' => 'anal-conversion',     'label' => 'Conversion Rate',   'icon' => 'fa-percent',             'color' => '#0284c7' ],
        ];
        foreach ( $stats as $s ): ?>
        <div class="nas-stat-card">
          <div class="nas-stat-card__icon" style="background:<?php echo esc_attr( $s['color'] ); ?>20;color:<?php echo esc_attr( $s['color'] ); ?>;">
            <i class="fa-solid <?php echo esc_attr( $s['icon'] ); ?>"></i>
          </div>
          <div class="nas-stat-card__body">
            <div class="nas-stat-value" id="<?php echo esc_attr( $s['id'] ); ?>">—</div>
            <div class="nas-stat-label"><?php echo esc_html( $s['label'] ); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="nas-charts-grid">
        <div class="nas-chart-card">
          <div class="nas-chart-card__header"><h4>Revenue (Monthly)</h4></div>
          <div class="nas-chart-wrap" style="height:260px;"><canvas id="nas-revenue-chart"></canvas></div>
        </div>
        <div class="nas-chart-card">
          <div class="nas-chart-card__header"><h4>Bookings (Monthly)</h4></div>
          <div class="nas-chart-wrap" style="height:260px;"><canvas id="nas-bookings-chart"></canvas></div>
        </div>
        <div class="nas-chart-card">
          <div class="nas-chart-card__header"><h4>Top Cities</h4></div>
          <div class="nas-chart-wrap" style="height:260px;"><canvas id="nas-cities-chart"></canvas></div>
        </div>
        <div class="nas-chart-card">
          <div class="nas-chart-card__header"><h4>Top Categories</h4></div>
          <div id="nas-top-categories-list" class="nas-top-list"></div>
        </div>
      </div>
    </div>

    <!-- ── Bookings ──────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-admin-bookings-panel">
      <div class="nas-panel-header">
        <h2>All Bookings</h2>
        <div class="nas-panel-actions">
          <div class="nas-bulk-bar">
            <select id="nas-bulk-action" class="nas-select nas-select--sm">
              <option value="">Bulk Action</option>
              <option value="mark_completed">Mark Completed</option>
              <option value="mark_rejected">Mark Rejected</option>
              <option value="delete">Delete</option>
            </select>
            <button class="nas-btn nas-btn-ghost nas-btn-sm" id="nas-bulk-apply">Apply</button>
          </div>
          <button class="nas-btn nas-btn-ghost nas-btn-sm" id="nas-export-csv">
            <i class="fa-solid fa-download"></i> Export CSV
          </button>
        </div>
      </div>
      <div class="nas-filter-bar">
        <input type="text" id="nas-admin-filter-search" class="nas-input nas-input--sm" placeholder="Search…">
        <select id="nas-admin-filter-status" class="nas-select nas-select--sm">
          <option value="">All Statuses</option>
          <option value="booking_received">Booking Received</option>
          <option value="under_review">Under Review</option>
          <option value="payment_received">Payment Received</option>
          <option value="published">Published</option>
          <option value="completed">Completed</option>
          <option value="rejected">Rejected</option>
        </select>
        <input type="text" id="nas-admin-filter-city" class="nas-input nas-input--sm" placeholder="City…">
        <input type="text" id="nas-admin-filter-newspaper" class="nas-input nas-input--sm" placeholder="Newspaper…">
      </div>
      <div id="nas-admin-bookings-table-wrap">
        <div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>
      </div>
      <div id="nas-admin-bookings-pagination"></div>
    </div>

    <!-- ── Clients ───────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-clients-panel">
      <div class="nas-panel-header">
        <h2>Clients</h2>
        <input type="text" id="nas-clients-search" class="nas-input nas-input--sm" placeholder="Search clients…">
      </div>
      <div id="nas-clients-table-wrap"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
      <div id="nas-clients-pagination"></div>
    </div>

    <!-- ── Newspapers ────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-newspapers-panel">
      <div class="nas-panel-header"><h2>Newspapers</h2></div>
      <div class="nas-two-col">
        <div>
          <h4 class="nas-section-title">Add Newspaper</h4>
          <form class="nas-form-card" id="nas-newspaper-form">
            <div class="nas-form-row"><label class="nas-label">Name *</label><input type="text" name="name" class="nas-input" required></div>
            <div class="nas-form-row"><label class="nas-label">Language</label>
              <select name="language" class="nas-select">
                <option>English</option><option>Hindi</option><option>Bengali</option><option>Tamil</option>
                <option>Telugu</option><option>Marathi</option><option>Gujarati</option><option>Kannada</option>
                <option>Malayalam</option><option>Punjabi</option><option>Urdu</option>
              </select>
            </div>
            <div class="nas-form-row"><label class="nas-label">Circulation Type</label>
              <select name="circulation_type" class="nas-select">
                <option value="national">National</option><option value="regional">Regional</option><option value="local">Local</option>
              </select>
            </div>
            <div class="nas-form-row"><label class="nas-label">Logo URL</label><input type="url" name="logo_url" class="nas-input" placeholder="https://…"></div>
            <div class="nas-form-row"><label class="nas-label">Website</label><input type="url" name="website" class="nas-input" placeholder="https://…"></div>
            <input type="hidden" name="id" value="">
            <button type="submit" class="nas-btn nas-btn-primary">Save Newspaper</button>
          </form>
        </div>
        <div>
          <h4 class="nas-section-title">Existing Newspapers</h4>
          <div id="nas-newspapers-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
        </div>
      </div>
    </div>

    <!-- ── Rate Cards ────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-rate-cards-panel">
      <div class="nas-panel-header"><h2>Rate Cards</h2></div>
      <div class="nas-two-col">
        <div>
          <h4 class="nas-section-title">Add / Edit Rate</h4>
          <form class="nas-form-card" id="nas-rate-card-form">
            <div class="nas-form-row"><label class="nas-label">Newspaper *</label>
              <select name="newspaper_id" id="rc-newspaper-select" class="nas-select" required>
                <option value="">Select Newspaper</option>
              </select>
            </div>
            <div class="nas-form-row"><label class="nas-label">Category *</label>
              <select name="category_id" id="rc-category-select" class="nas-select" required>
                <option value="">Select Category</option>
              </select>
            </div>
            <div class="nas-form-row"><label class="nas-label">City (optional)</label>
              <select name="city_id" id="rc-city-select" class="nas-select">
                <option value="">All Cities (national)</option>
              </select>
            </div>
            <div class="nas-form-row"><label class="nas-label">Base Rate (₹/word) *</label><input type="number" name="base_rate" class="nas-input" step="0.01" min="0" required></div>
            <div class="nas-form-row"><label class="nas-label">Min Words</label><input type="number" name="min_words" class="nas-input" value="10" min="1"></div>
            <div class="nas-form-row"><label class="nas-label">Max Words</label><input type="number" name="max_words" class="nas-input" value="50" min="1"></div>
            <div class="nas-form-row"><label class="nas-label">GST Rate (%)</label><input type="number" name="gst_rate" class="nas-input" value="5" min="0" max="28"></div>
            <div class="nas-form-row"><label class="nas-label">Agency Markup (%)</label><input type="number" name="markup_pct" class="nas-input" value="15" min="0"></div>
            <input type="hidden" name="id" value="">
            <button type="submit" class="nas-btn nas-btn-primary">Save Rate</button>
          </form>
        </div>
        <div>
          <h4 class="nas-section-title">Existing Rates</h4>
          <div id="nas-rate-cards-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
        </div>
      </div>
    </div>

    <!-- ── Cities ────────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-cities-panel">
      <div class="nas-panel-header"><h2>Cities</h2></div>
      <p class="nas-text-muted">Cities are pre-seeded from the plugin installer. Manage SEO metadata from the Moderation dashboard.</p>
      <div class="nas-info-box">
        <i class="fa-solid fa-circle-info"></i>
        120+ Indian cities are available including all Tier 1, Tier 2 and major Tier 3 cities.
        City-specific landing pages are auto-generated and can be customized in the Moderation panel.
      </div>
    </div>

    <!-- ── Categories ────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-categories-panel">
      <div class="nas-panel-header"><h2>Categories</h2></div>
      <p class="nas-text-muted">12 default ad categories are included: Obituary, Property, Matrimonial, Job, Education, Business, Name Change, Tender, Financial, Vehicle, Health, Entertainment.</p>
    </div>

    <!-- ── Vendors ───────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-vendors-panel">
      <div class="nas-panel-header"><h2>Vendors / Ad Reps</h2></div>
      <p class="nas-text-muted">Vendors are the newspaper ad representatives or local agents linked to specific newspapers.</p>
    </div>

    <!-- ── Settings ──────────────────────────────────────────────────── -->
    <div class="nas-admin-panel" id="nas-settings-panel">
      <div class="nas-panel-header"><h2>Settings</h2></div>
      <div class="nas-two-col">
        <div>
          <form class="nas-form-card" id="nas-settings-form">
            <h4 class="nas-section-title">General</h4>
            <div class="nas-form-row"><label class="nas-label">Agency Name</label><input type="text" name="agency_name" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">Agency Email</label><input type="email" name="agency_email" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">Agency Phone</label><input type="tel" name="agency_phone" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">Logo URL</label><input type="url" name="logo_url" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">Currency Symbol</label><input type="text" name="currency_symbol" class="nas-input" value="₹"></div>
            <div class="nas-form-row"><label class="nas-label">Default GST (%)</label><input type="number" name="default_gst" class="nas-input" value="5"></div>

            <h4 class="nas-section-title" style="margin-top:1.5rem;">Email / SMTP</h4>
            <div class="nas-form-row"><label class="nas-label">SMTP Host</label><input type="text" name="smtp_host" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">SMTP Port</label><input type="number" name="smtp_port" class="nas-input" value="587"></div>
            <div class="nas-form-row"><label class="nas-label">SMTP Username</label><input type="text" name="smtp_username" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">SMTP Password</label><input type="password" name="smtp_password" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">From Name</label><input type="text" name="smtp_from_name" class="nas-input"></div>

            <h4 class="nas-section-title" style="margin-top:1.5rem;">WhatsApp (CallMeBot)</h4>
            <div class="nas-form-row"><label class="nas-label">Agency WhatsApp</label><input type="tel" name="wa_phone" class="nas-input"></div>
            <div class="nas-form-row"><label class="nas-label">CallMeBot API Key</label><input type="text" name="wa_api_key" class="nas-input"></div>

            <h4 class="nas-section-title" style="margin-top:1.5rem;">AI</h4>
            <div class="nas-form-row"><label class="nas-label">Anthropic API Key</label><input type="password" name="anthropic_api_key" class="nas-input"></div>

            <button type="submit" class="nas-btn nas-btn-primary" style="margin-top:1rem;">Save Settings</button>
          </form>
        </div>

        <div>
          <h4 class="nas-section-title">Feature Flags</h4>
          <div class="nas-form-card">
            <?php
            $flags = [
              'ai_enabled'          => 'AI Ad Generation',
              'chat_enabled'        => 'Client Chat',
              'whatsapp_enabled'    => 'WhatsApp Notifications',
              'email_enabled'       => 'Email Notifications',
              'city_pages_enabled'  => 'City Landing Pages',
              'combo_offers_enabled'=> 'Combo Offers',
              'moderation_enabled'  => 'Content Moderation',
              'analytics_enabled'   => 'Analytics Tracking',
            ];
            foreach ( $flags as $key => $label ): ?>
            <div class="nas-toggle-row">
              <label class="nas-label" for="flag-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
              <label class="nas-toggle-switch">
                <input type="checkbox" class="nas-feature-toggle" id="flag-<?php echo esc_attr( $key ); ?>" data-flag="<?php echo esc_attr( $key ); ?>">
                <span class="nas-toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ════ NEW PANELS (Branding, Email Templates, Tickets, Blog, FAQ, Contact) ════ -->

    <!-- Branding -->
    <div class="nas-admin-panel" id="nas-branding-panel">
      <div class="nas-panel-header"><h2>🎨 Branding & Identity</h2></div>
      <div class="nas-form-card" id="nas-branding-form" style="max-width:680px">
        <div class="nas-form-grid">
          <?php
          $bfields = [
            'brand_name'=>'Brand Name','brand_tagline'=>'Tagline',
            'brand_primary_color'=>'Primary Colour (hex)','logo_url'=>'Logo URL',
            'favicon_url'=>'Favicon URL','brand_email'=>'Contact Email',
            'brand_phone'=>'Phone Number','brand_whatsapp'=>'WhatsApp Number',
            'brand_address'=>'Office Address',
            'social_facebook'=>'Facebook URL','social_instagram'=>'Instagram URL',
            'social_linkedin'=>'LinkedIn URL','social_twitter'=>'Twitter/X URL',
            'footer_tagline'=>'Footer Tagline',
          ];
          foreach($bfields as $key=>$label): ?>
          <div class="nas-form-row<?php echo in_array($key,['brand_address'])?' nas-form-row--full':''; ?>">
            <label class="nas-label"><?php echo esc_html($label); ?></label>
            <input type="<?php echo $key==='brand_primary_color'?'color':($key==='brand_email'?'email':'text'); ?>"
                   id="nas-brand-<?php echo esc_attr($key); ?>" class="nas-input"
                   <?php if($key==='brand_primary_color') echo 'style="height:40px;padding:4px 8px"'; ?>>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:14px">
          <button class="nas-btn nas-btn-primary" onclick="nasAdminSaveBranding()">Save Branding</button>
        </div>
      </div>
    </div>

    <!-- Email Templates -->
    <div class="nas-admin-panel" id="nas-email-tpl-panel">
      <div class="nas-panel-header"><h2>✉️ Email Templates</h2></div>
      <p style="color:#64748b;font-size:13px;margin-bottom:16px">Edit subject lines and HTML body for all automated emails. Placeholders like {client_name}, {order_id} are replaced automatically.</p>
      <div id="nas-email-tpl-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
    </div>

    <!-- Support Tickets -->
    <div class="nas-admin-panel" id="nas-tickets-panel">
      <div class="nas-panel-header">
        <h2>🎫 Support Tickets</h2>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php foreach([''=>'All','open'=>'Open','in_progress'=>'In Progress','waiting_client'=>'Waiting','resolved'=>'Resolved','closed'=>'Closed'] as $s=>$l): ?>
          <button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminLoadTickets('<?php echo esc_js($s); ?>')"><?php echo esc_html($l); ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="nas-admin-tickets-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
    </div>

    <!-- Blog -->
    <div class="nas-admin-panel" id="nas-blog-panel">
      <div class="nas-panel-header"><h2>📝 Blog Posts</h2></div>
      <div id="nas-blog-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
    </div>

    <!-- FAQ -->
    <div class="nas-admin-panel" id="nas-faq-panel">
      <div class="nas-panel-header">
        <h2>❓ FAQ Management</h2>
        <button class="nas-btn nas-btn-primary nas-btn-sm" onclick="nasAdminNewFAQ()">+ Add FAQ</button>
      </div>
      <div id="nas-faq-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
    </div>

    <!-- Contact Inbox -->
    <div class="nas-admin-panel" id="nas-contacts-panel">
      <div class="nas-panel-header"><h2>📥 Contact Inbox</h2></div>
      <div id="nas-contacts-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
    </div>

  </main>
</div><!-- /.nas-admin-wrap -->

<!-- Email Template Edit Modal -->
<div id="nas-email-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:flex-start;justify-content:center;padding:32px 16px;overflow-y:auto">
  <div style="background:#fff;border-radius:16px;padding:28px;max-width:700px;width:100%;margin:0 auto">
    <h3 style="margin:0 0 4px" id="nas-etpl-name">Edit Template</h3>
    <p style="font-size:12px;color:#94a3b8;margin:0 0 16px">Placeholders: <code id="nas-etpl-placeholders" style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px"></code></p>
    <input type="hidden" id="nas-etpl-id">
    <div class="nas-form-group"><label class="nas-label">Subject Line</label><input type="text" id="nas-etpl-subject" class="nas-input"></div>
    <div class="nas-form-group"><label class="nas-label">HTML Body</label><textarea id="nas-etpl-body" class="nas-textarea" rows="12" style="font-family:monospace;font-size:12px"></textarea></div>
    <div style="display:flex;gap:10px;margin-top:12px">
      <button class="nas-btn nas-btn-primary" onclick="nasAdminSaveTemplate()">Save Template</button>
      <button class="nas-btn nas-btn-secondary" onclick="document.getElementById('nas-email-edit-modal').style.display='none'">Cancel</button>
    </div>
  </div>
</div>

<!-- Blog Post Editor Modal -->
<div id="nas-post-editor-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:flex-start;justify-content:center;padding:32px 16px;overflow-y:auto">
  <div style="background:#fff;border-radius:16px;padding:28px;max-width:700px;width:100%;margin:0 auto">
    <h3 style="margin:0 0 16px">Blog Post Editor</h3>
    <input type="hidden" id="nas-post-id">
    <div class="nas-form-grid">
      <div class="nas-form-row nas-form-row--full"><label class="nas-label">Title</label><input type="text" id="nas-post-title" class="nas-input"></div>
      <div class="nas-form-row"><label class="nas-label">Category</label>
        <select id="nas-post-category" class="nas-select"><option value="news">News</option><option value="tips">Tips & Guides</option><option value="industry">Industry</option><option value="updates">Updates</option></select>
      </div>
      <div class="nas-form-row"><label class="nas-label">Status</label>
        <select id="nas-post-status" class="nas-select"><option value="draft">Draft</option><option value="published">Published</option></select>
      </div>
      <div class="nas-form-row nas-form-row--full"><label class="nas-label">Excerpt</label><textarea id="nas-post-excerpt" class="nas-textarea" rows="2"></textarea></div>
      <div class="nas-form-row nas-form-row--full"><label class="nas-label">Content (HTML supported)</label><textarea id="nas-post-content" class="nas-textarea" rows="8"></textarea></div>
      <div class="nas-form-row"><label class="nas-label">SEO Title</label><input type="text" id="nas-post-seo-title" class="nas-input"></div>
      <div class="nas-form-row"><label class="nas-label">SEO Description</label><input type="text" id="nas-post-seo-desc" class="nas-input"></div>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px">
      <button class="nas-btn nas-btn-primary" onclick="nasAdminSavePost()">Save Post</button>
      <button class="nas-btn nas-btn-secondary" onclick="document.getElementById('nas-post-editor-modal').style.display='none'">Cancel</button>
    </div>
  </div>
</div>

<!-- Admin Booking Drawer -->
<div class="nas-drawer-backdrop" id="nas-drawer-backdrop"></div>
<aside class="nas-drawer" id="nas-admin-booking-drawer" aria-hidden="true">
  <div class="nas-drawer-topbar">
    <h3>Booking Details</h3>
    <button class="nas-drawer-close" data-drawer-close><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="nas-drawer-body"></div>
</aside>

<script>
var NAS_ADMIN_NONCE = '<?php echo wp_create_nonce('nas_action'); ?>';
var NAS_AJAX = '<?php echo esc_js(get_permalink() ?: home_url('/')); ?>';

/* ── Hook into nas-admin.js tab switching to load new panels ── */
document.addEventListener('DOMContentLoaded', function() {
  // Add new nav buttons to sidebar programmatically so we don't duplicate the sidebar HTML
  var nav = document.querySelector('.nas-admin-sidebar .nas-sidebar-nav');
  if (nav) {
    var newBtns = [
      {panel:'nas-branding-panel',  icon:'fa-palette',       label:'Branding'},
      {panel:'nas-email-tpl-panel', icon:'fa-envelope',      label:'Email Templates'},
      {panel:'nas-tickets-panel',   icon:'fa-ticket',        label:'Support Tickets'},
      {panel:'nas-blog-panel',      icon:'fa-blog',          label:'Blog'},
      {panel:'nas-faq-panel',       icon:'fa-circle-question',label:'FAQ'},
      {panel:'nas-contacts-panel',  icon:'fa-inbox',         label:'Contact Inbox'},
    ];
    newBtns.forEach(function(b) {
      if (!document.querySelector('[data-panel="'+b.panel+'"]')) {
        var btn = document.createElement('button');
        btn.className = 'nas-admin-tab-btn';
        btn.dataset.panel = b.panel;
        btn.innerHTML = '<i class="fa-solid '+b.icon+'"></i> '+b.label;
        nav.appendChild(btn);
      }
    });
  }

  // Attach load handlers to all tab buttons
  document.querySelectorAll('.nas-admin-tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var panel = btn.dataset.panel;
      if (panel === 'nas-branding-panel')   nasAdminLoadBranding();
      if (panel === 'nas-email-tpl-panel')  nasAdminLoadEmailTemplates();
      if (panel === 'nas-tickets-panel')    nasAdminLoadTickets();
      if (panel === 'nas-blog-panel')       nasAdminLoadBlog();
      if (panel === 'nas-faq-panel')        nasAdminLoadFAQ();
      if (panel === 'nas-contacts-panel')   nasAdminLoadContacts();
    });
  });
});

/* ─── BRANDING ─── */
function nasAdminLoadBranding() {
  jQuery.post(NAS_AJAX, {action:'nas_get_branding',nonce:NAS_ADMIN_NONCE}, function(r) {
    if (!r.success) return;
    var b = r.data.branding || {};
    Object.keys(b).forEach(function(k) {
      var el = document.getElementById('nas-brand-'+k);
      if (el) el.value = b[k] || '';
    });
  });
}
function nasAdminSaveBranding() {
  var data = {action:'nas_save_branding',nonce:NAS_ADMIN_NONCE};
  document.querySelectorAll('#nas-branding-form [id^="nas-brand-"]').forEach(function(inp) {
    data[inp.id.replace('nas-brand-','')] = inp.value;
  });
  jQuery.post(NAS_AJAX, data, function(r) {
    r.success ? nasToast.success('✅ Branding saved!') : nasToast.error(r.data?.message||'Failed');
  });
}

/* ─── EMAIL TEMPLATES ─── */
function nasAdminLoadEmailTemplates() {
  jQuery.post(NAS_AJAX, {action:'nas_get_email_templates',nonce:NAS_ADMIN_NONCE}, function(r) {
    if (!r.success) return;
    var tpls = r.data.templates || [];
    var html = '<table class="nas-table"><thead><tr><th>Name</th><th>Key</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    tpls.forEach(function(t) {
      html += '<tr><td><strong>'+escH(t.name)+'</strong></td>'+
        '<td style="font-family:monospace;font-size:11px">'+escH(t.template_key)+'</td>'+
        '<td><span style="background:'+(t.is_enabled?'#dcfce7':'#fee2e2')+';color:'+(t.is_enabled?'#16a34a':'#dc2626')+';padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700">'+(t.is_enabled?'Active':'Disabled')+'</span></td>'+
        '<td style="display:flex;gap:6px"><button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminEditTemplate(\''+escH(t.template_key)+'\')">Edit</button>'+
        '<button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminTestEmail(\''+escH(t.template_key)+'\')">Test</button></td></tr>';
    });
    html += '</tbody></table>';
    document.getElementById('nas-email-tpl-list').innerHTML = html;
  });
}
function nasAdminEditTemplate(key) {
  jQuery.post(NAS_AJAX, {action:'nas_get_email_template',nonce:NAS_ADMIN_NONCE,template_key:key}, function(r) {
    if (!r.success||!r.data.template) return;
    var t = r.data.template;
    document.getElementById('nas-etpl-id').value = t.id;
    document.getElementById('nas-etpl-name').textContent = t.name;
    document.getElementById('nas-etpl-subject').value = t.subject;
    document.getElementById('nas-etpl-body').value = t.html_body;
    document.getElementById('nas-etpl-placeholders').textContent = t.placeholders||'';
    document.getElementById('nas-email-edit-modal').style.display='flex';
  });
}
function nasAdminSaveTemplate() {
  jQuery.post(NAS_AJAX, {action:'nas_save_email_template',nonce:NAS_ADMIN_NONCE,
    id: document.getElementById('nas-etpl-id').value,
    subject: document.getElementById('nas-etpl-subject').value,
    html_body: document.getElementById('nas-etpl-body').value
  }, function(r) {
    if (r.success) { nasToast.success('✅ Template saved!'); document.getElementById('nas-email-edit-modal').style.display='none'; }
    else nasToast.error(r.data?.message||'Save failed');
  });
}
function nasAdminTestEmail(key) {
  var email = prompt('Send test to:', '<?php echo esc_js(get_option('admin_email')); ?>');
  if (!email) return;
  jQuery.post(NAS_AJAX, {action:'nas_test_send_email',nonce:NAS_ADMIN_NONCE,template_key:key,email:email}, function(r) {
    r.success ? nasToast.success('✅ '+r.data.message) : nasToast.error(r.data?.message||'Failed');
  });
}

/* ─── SUPPORT TICKETS ─── */
function nasAdminLoadTickets(status) {
  var el = document.getElementById('nas-admin-tickets-list');
  el.innerHTML = '<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
  jQuery.post(NAS_AJAX, {action:'nas_admin_get_tickets',nonce:NAS_ADMIN_NONCE,status:status||'',page:1}, function(r) {
    var tickets = (r.success?r.data.tickets:[])||[];
    if (!tickets.length) { el.innerHTML='<div class="nas-empty-state"><p>No tickets found.</p></div>'; return; }
    var html = '<table class="nas-table"><thead><tr><th>Ticket ID</th><th>Client</th><th>Subject</th><th>Category</th><th>Status</th><th>Priority</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    tickets.forEach(function(t) {
      var sc = {open:'#2563eb',in_progress:'#d97706',waiting_client:'#7c3aed',resolved:'#16a34a',closed:'#94a3b8'}[t.status]||'#64748b';
      html += '<tr><td style="font-family:monospace">'+escH(t.ticket_uid)+'</td>'+
        '<td>'+escH(t.client_name||'')+'</td><td>'+escH(t.subject)+'</td>'+
        '<td>'+escH(t.category)+'</td>'+
        '<td><span style="background:'+sc+'18;color:'+sc+';padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700">'+escH(t.status.replace(/_/g,' '))+'</span></td>'+
        '<td>'+escH(t.priority)+'</td>'+
        '<td>'+new Date(t.created_at).toLocaleDateString('en-IN')+'</td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminReplyTicket('+t.id+')">Reply</button>'+
        '<button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminResolveTicket('+t.id+')">Resolve</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  });
}
function nasAdminReplyTicket(id) {
  var msg = prompt('Reply message:');
  if (!msg) return;
  jQuery.post(NAS_AJAX, {action:'nas_admin_reply_ticket',nonce:NAS_ADMIN_NONCE,ticket_id:id,message:msg}, function(r) {
    r.success ? nasToast.success('✅ Reply sent') : nasToast.error(r.data?.message||'Failed');
  });
}
function nasAdminResolveTicket(id) {
  jQuery.post(NAS_AJAX, {action:'nas_admin_update_ticket',nonce:NAS_ADMIN_NONCE,ticket_id:id,status:'resolved'}, function(r) {
    if (r.success) { nasToast.success('Ticket resolved'); nasAdminLoadTickets(); }
  });
}

/* ─── BLOG ─── */
function nasAdminLoadBlog() {
  var el = document.getElementById('nas-blog-list');
  el.innerHTML = '<div style="margin-bottom:12px"><button class="nas-btn nas-btn-primary nas-btn-sm" onclick="nasAdminNewPost()">+ New Post</button></div><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
  jQuery.post(NAS_AJAX, {action:'nas_admin_get_posts',nonce:NAS_ADMIN_NONCE}, function(r) {
    var posts = (r.success?r.data.posts:[])||[];
    var headerHtml = '<div style="margin-bottom:12px"><button class="nas-btn nas-btn-primary nas-btn-sm" onclick="nasAdminNewPost()">+ New Post</button></div>';
    if (!posts.length) { el.innerHTML = headerHtml + '<p style="color:#94a3b8">No posts yet.</p>'; return; }
    var html = headerHtml + '<table class="nas-table"><thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Views</th><th>Published</th><th>Actions</th></tr></thead><tbody>';
    posts.forEach(function(p) {
      var sc = p.status==='published'?'#16a34a':p.status==='draft'?'#d97706':'#94a3b8';
      html += '<tr><td>'+escH(p.title)+'</td><td>'+escH(p.category)+'</td>'+
        '<td><span style="color:'+sc+';font-weight:700;font-size:12px">'+p.status.toUpperCase()+'</span></td>'+
        '<td>'+p.views+'</td>'+
        '<td>'+(p.published_at?new Date(p.published_at).toLocaleDateString('en-IN'):'—')+'</td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminEditPost('+p.id+')">Edit</button>'+
        '<button class="nas-btn nas-btn-sm" style="background:#fee2e2;color:#dc2626" onclick="nasAdminDeletePost('+p.id+')">Delete</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  });
}
function nasAdminNewPost() { nasAdminOpenPostEditor({}); }
function nasAdminEditPost(id) {
  jQuery.post(NAS_AJAX, {action:'nas_admin_get_posts',nonce:NAS_ADMIN_NONCE}, function(r) {
    var p = ((r.data||{}).posts||[]).find(function(x){return x.id==id;});
    if (p) nasAdminOpenPostEditor(p);
  });
}
function nasAdminOpenPostEditor(p) {
  document.getElementById('nas-post-id').value = p.id||'';
  document.getElementById('nas-post-title').value = p.title||'';
  document.getElementById('nas-post-excerpt').value = p.excerpt||'';
  document.getElementById('nas-post-content').value = p.content||'';
  document.getElementById('nas-post-category').value = p.category||'news';
  document.getElementById('nas-post-status').value = p.status||'draft';
  document.getElementById('nas-post-seo-title').value = p.seo_title||'';
  document.getElementById('nas-post-seo-desc').value = p.seo_desc||'';
  document.getElementById('nas-post-editor-modal').style.display='flex';
}
function nasAdminSavePost() {
  jQuery.post(NAS_AJAX, {action:'nas_admin_save_post',nonce:NAS_ADMIN_NONCE,
    id:document.getElementById('nas-post-id').value,
    title:document.getElementById('nas-post-title').value,
    excerpt:document.getElementById('nas-post-excerpt').value,
    content:document.getElementById('nas-post-content').value,
    category:document.getElementById('nas-post-category').value,
    status:document.getElementById('nas-post-status').value,
    seo_title:document.getElementById('nas-post-seo-title').value,
    seo_desc:document.getElementById('nas-post-seo-desc').value,
  }, function(r) {
    if (r.success) { nasToast.success('✅ Post saved!'); document.getElementById('nas-post-editor-modal').style.display='none'; nasAdminLoadBlog(); }
    else nasToast.error(r.data?.message||'Failed');
  });
}
function nasAdminDeletePost(id) {
  if (!confirm('Delete this post?')) return;
  jQuery.post(NAS_AJAX, {action:'nas_admin_delete_post',nonce:NAS_ADMIN_NONCE,id:id}, function(r) {
    if (r.success) nasAdminLoadBlog();
  });
}

/* ─── FAQ ─── */
function nasAdminLoadFAQ() {
  var el = document.getElementById('nas-faq-list');
  el.innerHTML = '<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
  jQuery.post(NAS_AJAX, {action:'nas_get_faqs',nas_action:'1',nonce:NAS_ADMIN_NONCE}, function(r) {
    var faqs = (r.success?r.data.faqs:[])||[];
    if (!faqs.length) { el.innerHTML='<p style="color:#94a3b8">No FAQs yet. Click "+ Add FAQ" to create one.</p>'; return; }
    var html = '<table class="nas-table"><thead><tr><th>Category</th><th>Question</th><th>Actions</th></tr></thead><tbody>';
    faqs.forEach(function(f) {
      html += '<tr><td>'+escH(f.category)+'</td><td>'+escH(f.question)+'</td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminEditFAQ('+f.id+',\''+escH(f.category)+'\','+JSON.stringify(f.question)+','+JSON.stringify(f.answer)+')">Edit</button>'+
        '<button class="nas-btn nas-btn-sm" style="background:#fee2e2;color:#dc2626" onclick="nasAdminDeleteFAQ('+f.id+')">Delete</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  });
}
function nasAdminNewFAQ() {
  var cat = prompt('Category (Booking/Payment/Material/Publication/Refund):','Booking');
  if (!cat) return;
  var q = prompt('Question:');
  if (!q) return;
  var a = prompt('Answer:');
  if (!a) return;
  jQuery.post(NAS_AJAX, {action:'nas_admin_save_faq',nonce:NAS_ADMIN_NONCE,question:q,answer:a,category:cat}, function(r) {
    if (r.success) nasAdminLoadFAQ();
  });
}
function nasAdminEditFAQ(id, cat, q, a) {
  var nq = prompt('Question:',q); if (!nq) return;
  var na = prompt('Answer:',a); if (!na) return;
  jQuery.post(NAS_AJAX, {action:'nas_admin_save_faq',nonce:NAS_ADMIN_NONCE,id:id,question:nq,answer:na,category:cat}, function(r) {
    if (r.success) nasAdminLoadFAQ();
  });
}
function nasAdminDeleteFAQ(id) {
  if (!confirm('Delete this FAQ?')) return;
  jQuery.post(NAS_AJAX, {action:'nas_admin_delete_faq',nonce:NAS_ADMIN_NONCE,id:id}, function(r) {
    if (r.success) nasAdminLoadFAQ();
  });
}

/* ─── CONTACT INBOX ─── */
function nasAdminLoadContacts() {
  var el = document.getElementById('nas-contacts-list');
  el.innerHTML = '<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
  jQuery.post(NAS_AJAX, {action:'nas_admin_get_contacts',nonce:NAS_ADMIN_NONCE}, function(r) {
    var rows = (r.success?r.data.submissions:[])||[];
    if (!rows.length) { el.innerHTML='<p style="color:#94a3b8;text-align:center;padding:20px">No contact submissions yet.</p>'; return; }
    var html = '<table class="nas-table"><thead><tr><th>Name</th><th>Email</th><th>Subject</th><th>City</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    rows.forEach(function(c) {
      var sc = {new:'#dc2626',read:'#2563eb',replied:'#16a34a',spam:'#94a3b8'}[c.status]||'#64748b';
      html += '<tr><td>'+escH(c.name)+'</td><td>'+escH(c.email)+'</td>'+
        '<td>'+escH(c.subject||'—')+'</td><td>'+escH(c.city||'—')+'</td>'+
        '<td><span style="color:'+sc+';font-weight:700;font-size:11px">'+c.status.toUpperCase()+'</span></td>'+
        '<td>'+new Date(c.created_at).toLocaleDateString('en-IN')+'</td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-sm nas-btn-secondary" onclick="nasAdminViewContact('+c.id+',\''+escH(c.name)+'\',\''+escH(c.email)+'\','+JSON.stringify(c.message)+')">View</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  });
}
function nasAdminViewContact(id, name, email, message) {
  alert('From: '+name+' <'+email+'>\n\n'+message);
  jQuery.post(NAS_AJAX, {action:'nas_admin_mark_contact',nonce:NAS_ADMIN_NONCE,id:id,status:'read'}, function() { nasAdminLoadContacts(); });
}

function escH(t) { return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── Rate Card Dropdowns — load newspapers, categories, cities ── */
(function() {
  var rcLoaded = false;
  function rcLoadDropdowns() {
    if (rcLoaded) return;
    rcLoaded = true;
    var C = JSON.parse(document.getElementById('nas-admin-config').textContent);
    var nonce = C.nonce;
    var ajaxUrl = C.ajaxUrl;

    // Load newspapers
    var fd1 = new FormData(); fd1.append('action','nas_get_newspapers'); fd1.append('nonce',nonce);
    {const _c1=new AbortController();setTimeout(()=>_c1.abort(),30000);
    fetch(ajaxUrl,{method:'POST',body:fd1,signal:_c1.signal}).then(r=>r.json()).then(d=>{
      var sel = document.getElementById('rc-newspaper-select');
      if (!sel) return;
      (d.data?.newspapers||d.data||[]).forEach(function(n){
        var o=document.createElement('option');o.value=n.id;o.textContent=n.name+' ('+n.circulation_type+')';sel.appendChild(o);
      });
    });}

    // Load categories
    var fd2 = new FormData(); fd2.append('action','nas_get_categories'); fd2.append('nonce',nonce);
    {const _c2=new AbortController();setTimeout(()=>_c2.abort(),30000);
    fetch(ajaxUrl,{method:'POST',body:fd2,signal:_c2.signal}).then(r=>r.json()).then(d=>{
      var sel = document.getElementById('rc-category-select');
      if (!sel) return;
      (d.data?.categories||d.data||[]).forEach(function(c){
        var o=document.createElement('option');o.value=c.id;o.textContent=c.name;sel.appendChild(o);
      });
    });}

    // Load cities
    var fd3 = new FormData(); fd3.append('action','nas_get_cities'); fd3.append('nonce',nonce);
    {const _c3=new AbortController();setTimeout(()=>_c3.abort(),30000);
    fetch(ajaxUrl,{method:'POST',body:fd3,signal:_c3.signal}).then(r=>r.json()).then(d=>{
      var sel = document.getElementById('rc-city-select');
      if (!sel) return;
      (d.data?.cities||d.data||[]).forEach(function(c){
        var o=document.createElement('option');o.value=c.id;o.textContent=c.name+', '+c.state;sel.appendChild(o);
      });
    });}
  }

  // Trigger when rate cards panel becomes visible
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-panel="nas-rate-cards-panel"]');
    if (btn) setTimeout(rcLoadDropdowns, 100);
  });
  // Also load on panel tab click using existing tab system
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.nas-admin-tab-btn').forEach(function(btn) {
      if (btn.dataset.panel === 'nas-rate-cards-panel') {
        btn.addEventListener('click', function(){setTimeout(rcLoadDropdowns,100);});
      }
    });
  });
})();

</script>

