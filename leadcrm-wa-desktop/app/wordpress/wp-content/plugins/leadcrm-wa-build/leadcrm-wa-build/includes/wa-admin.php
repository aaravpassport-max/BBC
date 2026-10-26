<?php
/**
 * LeadCRM WhatsApp — Admin UI
 * Adds:
 *   1. "WhatsApp" tab on the Settings page
 *   2. "WhatsApp" tab + "Calls" section inside the Lead modal (injected via hook/filter)
 *   3. "WA Inbox" submenu page — pending review queue
 *   4. Pending review badge on menu
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── MENU ADDITIONS ──────────────────────────────────────────────────────────

add_action( 'admin_menu', 'lcrm_wa_add_menu_pages', 20 );
function lcrm_wa_add_menu_pages() {
    // Pending Review submenu under LeadCRM
    add_submenu_page(
        'leadcrm',
        'WA Inbox',
        'WA Inbox <span id="lcrm-wa-pending-badge" class="lcrm-wa-menu-badge" style="display:none;"></span>',
        'lcrm_view_leads',
        'leadcrm-wa-inbox',
        'lcrm_wa_page_inbox'
    );
}

// ─── SETTINGS PAGE TAB INJECTION ─────────────────────────────────────────────

// Tab link injected directly in menus.php settings tabs array (see leadcrm.php patch)

function lcrm_wa_add_settings_tab( $tabs ) {
    $tabs['whatsapp'] = 'WhatsApp';
    return $tabs;
}

// Because the core settings page doesn't have those hooks, we override it via output buffering
// by patching lcrm_page_settings at runtime. The cleanest way without modifying core:
// We check $_GET['tab'] === 'whatsapp' early and render before the footer.
// Removed: tab now dispatched natively in menus.php
function lcrm_wa_handle_settings_tab_early() {
    if ( ! is_admin() ) return;
    $page = $_GET['page'] ?? '';
    $tab  = $_GET['tab']  ?? '';
    if ( $page === 'leadcrm-settings' && $tab === 'whatsapp' ) {
        // We'll intercept lcrm_page_settings output by replacing the render
        add_action( 'lcrm_settings_render_tab_whatsapp', 'lcrm_wa_render_settings_tab' );
    }
}

/**
 * Standalone WA Settings renderer.
 * Called as a full page because we hook into the settings dispatch below.
 */
function lcrm_wa_render_settings_full_page() {
    lcrm_wa_render_settings_tab();
}

function lcrm_wa_render_settings_tab() {
    $brands   = lcrm_get_brands();
    $accounts = lcrm_wa_get_all_accounts();
    $mode     = get_option( 'lcrm_wa_capture_mode', 'smart' );
    $thresh   = (int) get_option( 'lcrm_wa_capture_threshold', 2 );
    $remind   = (int) get_option( 'lcrm_wa_missed_call_reminder_mins', 60 );
    $no_reply = (int) get_option( 'lcrm_wa_no_reply_hours', 24 );
    $blocklist = implode( "\n", (array) get_option( 'lcrm_wa_blocklist', [] ) );
    ?>
    <!-- ── WA ACCOUNTS ─────────────────────────── -->
    <div class="lcrm-card" style="margin-bottom:20px;">
        <div class="lcrm-card-head">
            <h3>&#128242; WhatsApp Accounts</h3>
            <button class="button button-primary lcrm-btn" id="lcrm-wa-add-account-btn">+ Add Account</button>
        </div>

        <div id="lcrm-wa-account-form" class="lcrm-inline-form" style="display:none;background:#f8fafc;padding:14px;border-radius:8px;margin-bottom:14px;border:1px solid #e2e8f0;">
            <input type="hidden" id="lwa-id" value="">
            <div class="lcrm-form-grid" style="grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px;">
                <div class="lcrm-form-group">
                    <label>Display Name</label>
                    <input type="text" id="lwa-display" class="lcrm-input lcrm-input-full" placeholder="e.g. NRI Services WA">
                </div>
                <div class="lcrm-form-group">
                    <label>WhatsApp Number <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="lwa-number" class="lcrm-input lcrm-input-full" placeholder="919876543210 (no + or spaces)">
                </div>
                <div class="lcrm-form-group">
                    <label>Brand / Business <span style="color:#ef4444;">*</span></label>
                    <select id="lwa-brand" class="lcrm-input lcrm-input-full">
                        <option value="">— Select Brand —</option>
                        <?php foreach ( $brands as $b ) echo '<option value="' . esc_attr( $b->id ) . '">' . esc_html( $b->name ) . '</option>'; ?>
                    </select>
                </div>
                <div class="lcrm-form-group">
                    <label>Platform</label>
                    <select id="lwa-platform" class="lcrm-input lcrm-input-full">
                        <option value="cloud_api">Meta Cloud API</option>
                        <option value="generic">Generic / Baileys Bridge</option>
                    </select>
                </div>
                <div class="lcrm-form-group" id="lwa-phoneid-wrap">
                    <label>Phone Number ID <small style="color:#94a3b8;">(Meta Cloud API)</small></label>
                    <input type="text" id="lwa-phoneid" class="lcrm-input lcrm-input-full" placeholder="From Meta Business Manager">
                </div>
                <div class="lcrm-form-group">
                    <label>API Token / Access Token</label>
                    <input type="text" id="lwa-token" class="lcrm-input lcrm-input-full" placeholder="Leave blank to keep existing">
                </div>
                <div class="lcrm-form-group">
                    <label>Webhook Verify Token <small style="color:#94a3b8;">(auto-generated if blank)</small></label>
                    <input type="text" id="lwa-verify-tok" class="lcrm-input lcrm-input-full" placeholder="Auto-generated">
                </div>
                <div class="lcrm-form-group">
                    <label>Status</label>
                    <select id="lwa-active" class="lcrm-input lcrm-input-full">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <button class="button button-primary lcrm-btn" id="lwa-save-btn">Save Account</button>
                <button class="button lcrm-btn" id="lwa-cancel-btn">Cancel</button>
                <span id="lwa-msg" class="lcrm-form-msg"></span>
            </div>
        </div>

        <?php if ( empty( $accounts ) ): ?>
        <p style="color:#94a3b8;padding:16px;">No WhatsApp accounts added yet. Click "+ Add Account" to connect your first number.</p>
        <?php else: ?>
        <table class="lcrm-table" style="margin:0;">
            <thead><tr><th>Number</th><th>Display Name</th><th>Brand</th><th>Platform</th><th>Webhook URL</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="lcrm-wa-accounts-tbody">
            <?php foreach ( $accounts as $acc ): ?>
            <tr id="lwa-row-<?php echo $acc->id; ?>">
                <td><code><?php echo esc_html( $acc->wa_number ); ?></code></td>
                <td><?php echo esc_html( $acc->display_name ); ?></td>
                <td><?php echo esc_html( $acc->brand_name ?? '—' ); ?></td>
                <td><?php echo $acc->platform === 'cloud_api' ? 'Meta Cloud API' : 'Generic'; ?></td>
                <td style="font-size:11px;">
                    <code style="word-break:break-all;font-size:10px;"><?php
                        echo esc_html( rest_url( 'lcrm/v1/wa-webhook/' . $acc->id ) );
                    ?></code>
                    <button class="button lcrm-btn lcrm-btn-sm lcrm-wa-copy-url"
                        data-url="<?php echo esc_attr( rest_url( 'lcrm/v1/wa-webhook/' . $acc->id ) ); ?>">Copy</button>
                </td>
                <td><span class="lcrm-badge" style="background:<?php echo $acc->is_active ? '#10b981' : '#94a3b8'; ?>;"><?php echo $acc->is_active ? 'Active' : 'Inactive'; ?></span></td>
                <td>
                    <button class="button lcrm-btn lcrm-btn-sm lcrm-wa-edit-account"
                        data-id="<?php echo $acc->id; ?>"
                        data-display="<?php echo esc_attr( $acc->display_name ); ?>"
                        data-number="<?php echo esc_attr( $acc->wa_number ); ?>"
                        data-brand="<?php echo $acc->brand_id; ?>"
                        data-platform="<?php echo esc_attr( $acc->platform ); ?>"
                        data-phoneid="<?php echo esc_attr( $acc->phone_number_id ); ?>"
                        data-verify="<?php echo esc_attr( $acc->webhook_verify_token ); ?>"
                        data-active="<?php echo $acc->is_active; ?>">Edit</button>
                    <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-wa-del-account" data-id="<?php echo $acc->id; ?>">Del</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- ── CAPTURE ENGINE SETTINGS ─────────────── -->
    <div class="lcrm-card" style="margin-bottom:20px;">
        <div class="lcrm-card-head"><h3>&#129302; Smart Capture Settings</h3></div>
        <div class="lcrm-form-grid" style="grid-template-columns:repeat(2,1fr);gap:16px;padding:4px 0 12px;">
            <div class="lcrm-form-group">
                <label>Capture Mode</label>
                <select id="lwa-mode" class="lcrm-input lcrm-input-full">
                    <option value="smart"  <?php selected( $mode,'smart' ); ?>>Smart Filter — auto-capture real inquiries, queue uncertain</option>
                    <option value="all"    <?php selected( $mode,'all' ); ?>>Capture All — create lead for every new number (review manually)</option>
                    <option value="manual" <?php selected( $mode,'manual' ); ?>>Manual Only — all new numbers go to review queue, nothing auto-created</option>
                </select>
                <small style="color:#64748b;">Smart Filter is recommended. It scores messages and only auto-captures real client inquiries.</small>
            </div>
            <div class="lcrm-form-group">
                <label>Smart Filter Threshold (0–8)</label>
                <input type="number" id="lwa-threshold" class="lcrm-input lcrm-input-full" value="<?php echo $thresh; ?>" min="0" max="8">
                <small style="color:#64748b;">Messages scoring at or above this are auto-captured as leads. Lower = more aggressive. Default: 2.</small>
            </div>
            <div class="lcrm-form-group">
                <label>Missed Call Callback Reminder (minutes)</label>
                <input type="number" id="lwa-remind-mins" class="lcrm-input lcrm-input-full" value="<?php echo $remind; ?>" min="5" max="1440">
                <small style="color:#64748b;">Auto-create a reminder this many minutes after a missed WhatsApp call.</small>
            </div>
            <div class="lcrm-form-group">
                <label>No-Reply Alert (hours)</label>
                <input type="number" id="lwa-noreply-hrs" class="lcrm-input lcrm-input-full" value="<?php echo $no_reply; ?>" min="1" max="168">
                <small style="color:#64748b;">Create high-priority alert if an inbound WA message goes unanswered for this many hours.</small>
            </div>
        </div>
        <button class="button button-primary lcrm-btn" id="lwa-save-settings-btn">Save Settings</button>
        <span id="lwa-settings-msg" class="lcrm-form-msg"></span>
    </div>

    <!-- ── BLOCKLIST ────────────────────────────── -->
    <div class="lcrm-card">
        <div class="lcrm-card-head">
            <h3>&#128683; Blocklist — Personal / Non-Client Numbers</h3>
        </div>
        <p style="color:#64748b;font-size:13px;margin-top:0;">
            Numbers on this list are <strong>never</strong> captured as leads — messages are silently ignored.
            Add family, friends, personal contacts, or any number you never want in the CRM.
            One number per line. Partial match supported (last 10 digits).
        </p>
        <p style="color:#64748b;font-size:13px;margin-top:0;">
            <strong>Note:</strong> Staff WP user phone numbers and registered vendor phones are
            automatically blocked — you don't need to add them here.
        </p>
        <textarea id="lwa-blocklist" class="lcrm-input lcrm-input-full lcrm-textarea" rows="6"
            placeholder="919876543210&#10;919123456789&#10;+91 98765 43210"
            style="font-family:monospace;font-size:12px;"><?php echo esc_textarea( $blocklist ); ?></textarea>
        <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
            <button class="button button-primary lcrm-btn" id="lwa-save-blocklist-btn">Save Blocklist</button>
            <span id="lwa-blocklist-msg" class="lcrm-form-msg"></span>
        </div>
    </div>
    <?php
}

// ─── PATCH lcrm_page_settings TO ADD THE WA TAB ──────────────────────────────

// We use output buffering to intercept the settings page render since menus.php
// hardcodes the tabs array. This is the minimal-change approach.
// Removed: tab now dispatched natively in menus.php
function lcrm_wa_patch_settings_page_output() {
    $page = $_GET['page'] ?? '';
    $tab  = $_GET['tab']  ?? '';
    if ( $page !== 'leadcrm-settings' ) return;
    if ( $tab !== 'whatsapp' ) {
        // Inject a "WhatsApp" tab link via JS into the settings tabs bar
        ?>
        <script>
        jQuery(function($){
            var $tabs = $('.lcrm-settings-tabs');
            if (!$tabs.length) return;
            var url = '<?php echo esc_js( admin_url( 'admin.php?page=leadcrm-settings&tab=whatsapp' ) ); ?>';
            $tabs.append('<a href="' + url + '" class="lcrm-stab">&#128242; WhatsApp</a>');
        });
        </script>
        <?php
        return;
    }
    // For the whatsapp tab, we need to render the full page ourselves
    // because menus.php doesn't know about this tab.
    // We hook into admin_notices with high priority to output before the page renders,
    // but the cleanest way is to add a hook that fires inside lcrm_page_settings flow.
    // Since we can't modify menus.php inline, we use the following trick:
    // Output buffer the entire admin page when tab=whatsapp and replace content.
    ob_start( 'lcrm_wa_filter_settings_output' );
}

function lcrm_wa_filter_settings_output( $buffer ) {
    // Find the lcrm-settings-tabs div and inject active tab link
    $buffer = preg_replace(
        '/(<a href="[^"]*tab=automation[^"]*"[^>]*>Automation<\/a>)/',
        '$1<a href="' . admin_url( 'admin.php?page=leadcrm-settings&tab=whatsapp' ) . '" class="lcrm-stab active">&#128242; WhatsApp</a>',
        $buffer
    );
    // Replace the main content area between the tabs and the footer
    $buffer = preg_replace(
        '/(<\/div>)\s*(<div[^>]+class="[^"]*lcrm-footer[^"]*")/s',
        '$1' . lcrm_wa_get_settings_html() . '$2',
        $buffer,
        1
    );
    return $buffer;
}

function lcrm_wa_get_settings_html() {
    ob_start();
    lcrm_wa_render_settings_tab();
    return ob_get_clean();
}

// ─── WA INBOX PAGE ───────────────────────────────────────────────────────────

function lcrm_wa_page_inbox() {
    if ( ! current_user_can( 'lcrm_view_leads' ) ) wp_die( 'Access denied.' );
    lcrm_header( '&#128242; WA Inbox — Pending Review',
        '<span style="color:#64748b;font-size:13px;">Messages that scored too low for auto-capture. Review and approve the real leads.</span>'
    );
    ?>
    <div style="margin:0 18px 24px;">
        <!-- Filter bar -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <input type="text" id="lcrm-wa-inbox-search" class="lcrm-input" placeholder="&#128269; Search number or message…" style="width:260px;">
            <select id="lcrm-wa-inbox-account" class="lcrm-input">
                <option value="">All Accounts</option>
                <?php foreach ( lcrm_wa_get_all_accounts() as $acc )
                    echo '<option value="' . esc_attr( $acc->id ) . '">' . esc_html( $acc->display_name ?: $acc->wa_number ) . '</option>'; ?>
            </select>
            <button class="button lcrm-btn" id="lcrm-wa-inbox-refresh">&#8635; Refresh</button>
            <span id="lcrm-wa-inbox-count" style="color:#94a3b8;font-size:13px;margin-left:auto;"></span>
        </div>

        <!-- Info strip -->
        <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:#78350f;">
            <strong>Smart Filter is ON.</strong>
            Messages here didn't score high enough to auto-capture.
            Approve to create a lead, reject to discard, or add their number to the blocklist to permanently ignore them.
        </div>

        <div id="lcrm-wa-inbox-wrap">
            <div class="lcrm-wa-inbox-loading" style="text-align:center;padding:40px;color:#94a3b8;">Loading…</div>
        </div>
    </div>
    <?php lcrm_footer();
}

// ─── LEAD MODAL WA TAB + CALL LOG (injected via hook) ────────────────────────
// We inject a WA tab button and panel into the existing lead form via a filter.
// This requires the lead form to support lcrm_lead_form_extra_tabs hook.
// Since it doesn't, we inject via admin_footer JS that manipulates the DOM.

add_action( 'admin_footer', 'lcrm_wa_inject_lead_modal_panels' );

// TRACE: Called directly from lcrm_spa_shell() to output WA modal templates on SPA page.
function lcrm_wa_render_modal_templates() {
    lcrm_wa_inject_lead_modal_panels_output();
}

function lcrm_wa_inject_lead_modal_panels() {
    $page = $_GET['page'] ?? '';
    if ( strpos( $page, 'leadcrm' ) === false ) return;
    lcrm_wa_inject_lead_modal_panels_output();
}

function lcrm_wa_inject_lead_modal_panels_output() {
    ?>
    <script id="lcrm-wa-modal-templates" type="text/html">
    <!-- WA TAB BUTTON -->
    <button type="button" class="lcrm-ftab" data-tab="whatsapp" id="lcrm-wa-tab-btn">&#128242; WhatsApp</button>

    <!-- WA TAB PANEL -->
    <div class="lcrm-ftab-pane" data-pane="whatsapp" style="display:none;">
        <div id="lcrm-wa-events-panel">
            <!-- Header row -->
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <span style="font-weight:600;font-size:14px;color:#1e293b;">&#128242; WhatsApp Activity</span>
                <span id="lcrm-wa-unread-badge" class="lcrm-wa-unread" style="display:none;"></span>
                <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-wa-mark-read-btn" style="margin-left:auto;">&#10003; Mark All Read</button>
                <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-wa-refresh-btn">&#8635;</button>
            </div>

            <!-- WA timeline -->
            <div id="lcrm-wa-timeline" class="lcrm-wa-timeline">
                <div class="lcrm-conv-empty">Loading…</div>
            </div>
        </div>

        <!-- Call log section -->
        <div style="margin-top:20px;border-top:1px solid #e2e8f0;padding-top:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                <span style="font-weight:600;font-size:14px;color:#1e293b;">&#128222; Call Log</span>
                <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-wa-add-call-btn">+ Log Call</button>
            </div>
            <div id="lcrm-call-log-form" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:12px;">
                <div class="lcrm-form-grid" style="grid-template-columns:repeat(3,1fr);gap:10px;">
                    <div class="lcrm-form-group">
                        <label>Call Type</label>
                        <select id="lcrm-call-type" class="lcrm-input lcrm-input-full">
                            <option value="incoming">Incoming</option>
                            <option value="outgoing">Outgoing</option>
                            <option value="missed">Missed</option>
                        </select>
                    </div>
                    <div class="lcrm-form-group">
                        <label>Date &amp; Time</label>
                        <input type="datetime-local" id="lcrm-call-at" class="lcrm-input lcrm-input-full">
                    </div>
                    <div class="lcrm-form-group">
                        <label>Duration (seconds)</label>
                        <input type="number" id="lcrm-call-duration" class="lcrm-input lcrm-input-full" placeholder="0 for missed/unknown" min="0">
                    </div>
                    <div class="lcrm-form-group" style="grid-column:1/-1;">
                        <label>Notes</label>
                        <input type="text" id="lcrm-call-notes" class="lcrm-input lcrm-input-full" placeholder="e.g. Discussed visa requirements, will send docs">
                    </div>
                    <div class="lcrm-form-group" style="grid-column:1/-1;">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;cursor:pointer;">
                            <input type="checkbox" id="lcrm-call-reminder"> Create callback reminder for tomorrow
                        </label>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
                    <button type="button" class="button button-primary lcrm-btn lcrm-btn-sm" id="lcrm-call-save-btn">Save Call</button>
                    <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-call-cancel-btn">Cancel</button>
                    <span id="lcrm-call-msg" class="lcrm-form-msg"></span>
                </div>
            </div>
            <div id="lcrm-call-logs-list">
                <div class="lcrm-conv-empty">No calls logged yet.</div>
            </div>
        </div>
    </div>
    </script>
    <?php
}

// ─── BLOCKLIST-FROM-LEAD shortcut rendered inside lead detail ─────────────────
// We add a small "Block this number" link to the phone field area via admin_footer.
add_action( 'admin_footer', 'lcrm_wa_inject_blocklist_shortcut' );
function lcrm_wa_inject_blocklist_shortcut() {
    $page = $_GET['page'] ?? '';
    if ( strpos( $page, 'leadcrm' ) === false ) return;
    // Injected via JS below
}

// ─── v4.0: OUTBOUND WA + AI SUGGESTION + PDF UPLOAD MODAL PANELS ─────────────
add_action( 'admin_footer', 'lcrm_v4_inject_modal_panels' );
function lcrm_v4_inject_modal_panels() {
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    if ( strpos( $page, 'leadcrm' ) === false ) return;

    global $wpdb;
    $wa_accounts = $wpdb->get_results(
        "SELECT id, display_name FROM {$wpdb->prefix}lcrm_wa_accounts WHERE is_active=1 AND platform='cloud_api' ORDER BY display_name ASC"
    );
    ?>
    <script id="lcrm-v4-modal-templates" type="text/html">
    <!-- OUTBOUND WA REPLY TAB BUTTON -->
    <button type="button" class="lcrm-ftab" data-tab="outbound_wa" id="lcrm-v4-wa-tab-btn">&#9992; Send WA</button>

    <!-- OUTBOUND WA REPLY TAB PANEL -->
    <div class="lcrm-ftab-pane" data-pane="outbound_wa" style="display:none;">
        <div style="margin-bottom:14px;">
            <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:10px;">&#9992; Send WhatsApp Message</div>
            <?php if ( ! empty( $wa_accounts ) ): ?>
            <div class="lcrm-form-group" style="margin-bottom:10px;">
                <label style="font-size:12px;font-weight:500;">WhatsApp Account</label>
                <select id="lcrm-v4-wa-account" class="lcrm-input lcrm-input-full">
                    <option value="">Auto-select by brand</option>
                    <?php foreach ( $wa_accounts as $a ): ?>
                    <option value="<?php echo esc_attr($a->id); ?>"><?php echo esc_html($a->display_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div style="background:#fef3c7;border:1px solid #fde68a;padding:8px 12px;border-radius:6px;font-size:12px;color:#92400e;margin-bottom:10px;">No Cloud API WhatsApp accounts configured. Go to Settings → WhatsApp.</div>
            <?php endif; ?>
            <div class="lcrm-form-group" style="margin-bottom:10px;">
                <label style="font-size:12px;font-weight:500;">Message</label>
                <textarea id="lcrm-v4-wa-message" class="lcrm-input lcrm-input-full lcrm-textarea" rows="4" placeholder="Type your WhatsApp message..."></textarea>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="button button-primary lcrm-btn lcrm-btn-sm" id="lcrm-v4-wa-send-btn">&#9992; Send Message</button>
                <?php if ( get_option('lcrm_ai_enabled', 0) ): ?>
                <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v4-ai-suggest-btn">&#129302; AI Suggest Reply</button>
                <?php endif; ?>
                <span id="lcrm-v4-wa-msg" style="font-size:12px;color:#10b981;"></span>
            </div>
        </div>
        <!-- Sent messages history -->
        <div style="border-top:1px solid #e2e8f0;padding-top:12px;margin-top:12px;">
            <div style="font-weight:500;font-size:13px;color:#374151;margin-bottom:8px;">&#128172; Outbound History</div>
            <div id="lcrm-v4-wa-history" style="max-height:200px;overflow-y:auto;"></div>
        </div>
    </div>

    <!-- AI TAB BUTTON -->
    <button type="button" class="lcrm-ftab" data-tab="ai_panel" id="lcrm-v4-ai-tab-btn">&#129302; AI</button>

    <!-- AI TAB PANEL -->
    <div class="lcrm-ftab-pane" data-pane="ai_panel" style="display:none;">
        <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:12px;">&#129302; AI Intelligence</div>
        <?php if ( ! get_option('lcrm_ai_enabled', 0) ): ?>
        <div style="background:#fef3c7;border:1px solid #fde68a;padding:10px 14px;border-radius:6px;font-size:13px;color:#92400e;">
            AI is not enabled. Go to <a href="<?php echo admin_url('admin.php?page=leadcrm-settings&tab=ai'); ?>">Settings → AI Engine</a> to set it up.
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:14px;">
            <div>
                <div style="font-weight:500;font-size:13px;color:#374151;margin-bottom:6px;">&#128172; Smart Reply Suggestion</div>
                <div id="lcrm-v4-ai-suggestion-box" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 12px;font-size:13px;min-height:60px;color:#166534;white-space:pre-wrap;">Click "Suggest" to generate an AI reply for this lead.</div>
                <div style="display:flex;gap:8px;margin-top:8px;">
                    <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v4-ai-suggest-btn2">&#129302; Suggest Reply</button>
                    <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v4-ai-use-suggestion-btn" style="display:none;">Use This Reply &#8594;</button>
                </div>
            </div>
            <div>
                <div style="font-weight:500;font-size:13px;color:#374151;margin-bottom:6px;">&#128197; Morning Brief</div>
                <div id="lcrm-v4-brief-box" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:10px 12px;font-size:13px;min-height:40px;color:#1e40af;white-space:pre-wrap;">Click to load today's AI brief.</div>
                <button type="button" class="button lcrm-btn lcrm-btn-sm" style="margin-top:6px;" id="lcrm-v4-brief-btn">&#127305; Load Brief</button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- PDF TAB BUTTON -->
    <button type="button" class="lcrm-ftab" data-tab="pdf_panel" id="lcrm-v4-pdf-tab-btn">&#128196; Documents</button>

    <!-- PDF TAB PANEL -->
    <div class="lcrm-ftab-pane" data-pane="pdf_panel" style="display:none;">
        <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:12px;">&#128196; Document Intelligence</div>
        <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:8px;padding:20px;text-align:center;margin-bottom:14px;" id="lcrm-v4-pdf-dropzone">
            <div style="font-size:28px;margin-bottom:6px;">&#128196;</div>
            <div style="font-size:13px;color:#374151;font-weight:500;">Drop PDF here or click to upload</div>
            <div style="font-size:11px;color:#6b7280;margin-top:4px;">Max 20MB · PDF only · AI will analyse automatically</div>
            <input type="file" id="lcrm-v4-pdf-input" accept=".pdf,application/pdf" style="display:none;">
            <button type="button" class="button lcrm-btn lcrm-btn-sm" style="margin-top:10px;" id="lcrm-v4-pdf-choose-btn">Choose PDF</button>
        </div>
        <div id="lcrm-v4-pdf-upload-progress" style="display:none;margin-bottom:12px;">
            <div style="height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">
                <div id="lcrm-v4-pdf-progress-bar" style="height:4px;background:#6366f1;width:0%;transition:width .3s;"></div>
            </div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;" id="lcrm-v4-pdf-progress-text">Uploading...</div>
        </div>
        <div id="lcrm-v4-pdf-upload-result" style="display:none;"></div>
        <div id="lcrm-v4-pdf-list">
            <div style="font-weight:500;font-size:13px;color:#374151;margin-bottom:8px;">Uploaded Documents</div>
            <div id="lcrm-v4-pdf-docs-container"><div class="lcrm-conv-empty">Loading documents...</div></div>
        </div>
    </div>

    <!-- INTERNAL NOTES TAB -->
    <button type="button" class="lcrm-ftab" data-tab="internal_notes" id="lcrm-v5-notes-tab-btn">&#128203; Notes</button>
    <div class="lcrm-ftab-pane" data-pane="internal_notes" style="display:none;">
        <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:12px;">&#128203; Internal Notes</div>
        <div id="lcrm-v5-notes-list" style="max-height:260px;overflow-y:auto;margin-bottom:12px;">
            <div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">Loading notes…</div>
        </div>
        <div style="border-top:1px solid #f1f5f9;padding-top:12px;">
            <textarea id="lcrm-v5-note-text" class="lcrm-input lcrm-input-full lcrm-textarea" rows="3"
                placeholder="Add a note… Use @username to mention a team member"></textarea>
            <button type="button" class="button button-primary lcrm-btn lcrm-btn-sm" id="lcrm-v5-note-save-btn" style="margin-top:8px;">Add Note</button>
            <span id="lcrm-v5-note-msg" class="lcrm-form-msg" style="margin-left:8px;"></span>
        </div>
    </div>

    <!-- PAYMENT LINKS TAB -->
    <button type="button" class="lcrm-ftab" data-tab="payment_links" id="lcrm-v5-pay-tab-btn">&#128180; Payments</button>
    <div class="lcrm-ftab-pane" data-pane="payment_links" style="display:none;">
        <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:12px;">&#128180; Payment Links</div>
        <div id="lcrm-v5-pay-links-list" style="margin-bottom:12px;">
            <div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">Loading…</div>
        </div>
        <div style="border-top:1px solid #f1f5f9;padding-top:12px;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">AMOUNT (₹)</label>
                    <input type="number" id="lcrm-v5-pay-amount" class="lcrm-input" style="width:120px;" placeholder="5000" min="1">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">DESCRIPTION</label>
                    <input type="text" id="lcrm-v5-pay-desc" class="lcrm-input" style="width:180px;" placeholder="Service description">
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="lcrm-v5-pay-sendwa" checked>
                    <label style="font-size:12px;font-weight:normal;margin:0;" for="lcrm-v5-pay-sendwa">Send via WA</label>
                </div>
            </div>
            <div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
                <button type="button" class="button button-primary lcrm-btn lcrm-btn-sm" id="lcrm-v5-pay-create-btn">Generate Link</button>
                <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v5-pay-manual-btn">Record Cash/Bank</button>
                <span id="lcrm-v5-pay-msg" class="lcrm-form-msg" style="margin-left:4px;"></span>
            </div>
        </div>
    </div>

    <!-- PORTAL TAB -->
    <button type="button" class="lcrm-ftab" data-tab="portal_link" id="lcrm-v5-portal-tab-btn">&#128279; Portal</button>
    <div class="lcrm-ftab-pane" data-pane="portal_link" style="display:none;">
        <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:12px;">&#128279; Client Portal</div>
        <div id="lcrm-v5-portal-content">
            <p style="font-size:13px;color:#6b7280;">Generate a magic link so your client can check their case status, upload documents, and send messages — no login required.</p>
            <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">LINK VALID FOR</label>
                    <select id="lcrm-v5-portal-expiry" class="lcrm-input" style="width:140px;">
                        <option value="7">7 days</option>
                        <option value="30" selected>30 days</option>
                        <option value="90">90 days</option>
                        <option value="365">1 year</option>
                    </select>
                </div>
                <div style="margin-top:18px;display:flex;gap:6px;">
                    <button type="button" class="button button-primary lcrm-btn lcrm-btn-sm" id="lcrm-v5-portal-gen-btn">Generate Link</button>
                    <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v5-portal-revoke-btn" style="color:#ef4444;">Revoke</button>
                </div>
            </div>
            <div id="lcrm-v5-portal-link-wrap" style="display:none;margin-top:14px;">
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;">
                    <div style="font-size:11px;font-weight:600;color:#166534;margin-bottom:6px;">CLIENT PORTAL LINK</div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" id="lcrm-v5-portal-url" class="lcrm-input" readonly style="flex:1;font-size:12px;color:#166534;">
                        <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v5-portal-copy-btn">Copy</button>
                        <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v5-portal-wa-btn">Send via WA</button>
                    </div>
                    <div style="font-size:11px;color:#6b7280;margin-top:6px;" id="lcrm-v5-portal-expires"></div>
                </div>
            </div>
            <div id="lcrm-v5-portal-messages" style="margin-top:14px;"></div>
            <span id="lcrm-v5-portal-msg" class="lcrm-form-msg" style="display:block;margin-top:6px;"></span>
        </div>
    </div>

    <!-- WA TEMPLATE PICKER TAB -->
    <button type="button" class="lcrm-ftab" data-tab="wa_templates" id="lcrm-v5-tpl-tab-btn">&#128203; Templates</button>
    <div class="lcrm-ftab-pane" data-pane="wa_templates" style="display:none;">
        <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:12px;">&#128203; WA Template Library</div>
        <div id="lcrm-v5-tpl-list" style="max-height:300px;overflow-y:auto;"></div>
        <div id="lcrm-v5-tpl-preview" style="display:none;margin-top:12px;background:#f8fafc;border-radius:8px;padding:12px;">
            <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">Preview (editable before sending):</div>
            <textarea id="lcrm-v5-tpl-text" class="lcrm-input lcrm-input-full lcrm-textarea" rows="6"></textarea>
            <div style="margin-top:8px;display:flex;gap:8px;">
                <button type="button" class="button button-primary lcrm-btn lcrm-btn-sm" id="lcrm-v5-tpl-send-btn">Send via WA</button>
                <button type="button" class="button lcrm-btn lcrm-btn-sm" id="lcrm-v5-tpl-cancel-btn">Cancel</button>
                <span id="lcrm-v5-tpl-send-msg" class="lcrm-form-msg"></span>
            </div>
        </div>
    </div>
    </script>

    <script>
    (function($){
        // ── Inject v4 tabs into lead modal (same pattern as WA tab injection)
        function lcrmV4InjectTabs(){
            if($('#lcrm-v4-wa-tab-btn').length) return;
            var $tpl = $('#lcrm-v4-modal-templates');
            if(!$tpl.length) return;
            var $parser = $('<div>').html($tpl.html());

            ['outbound_wa','ai_panel','pdf_panel','internal_notes','payment_links','portal_link','wa_templates'].forEach(function(key){
                var $btn   = $parser.find('[data-tab="'+key+'"]').first();
                var $panel = $parser.find('[data-pane="'+key+'"]').first();
                if($btn.length)   $('.lcrm-form-tabs').append($btn.clone(true,true));
                if($panel.length) $('.lcrm-ftab-pane').last().after($panel.clone(true,true));
            });
        }

        // Hook into existing lead modal open
        var _v4Orig = window.lcrmOpenLeadModal;
        window.lcrmOpenLeadModal = function(id){
            if(typeof _v4Orig === 'function') _v4Orig(id);
            if(id){
                setTimeout(function(){
                    lcrmV4InjectTabs();
                    var leadId = parseInt($('#lf-id').val()||0);
                    if(leadId) lcrmV4LoadPdfs(leadId);
                }, 700);
            } else {
                setTimeout(lcrmV4InjectTabs, 200);
            }
        };

        // ── PDF Tab load ─────────────────────────────────────────────────────
        function lcrmV4LoadPdfs(leadId){
            var $c = $('#lcrm-v4-pdf-docs-container');
            if(!$c.length) return;
            $c.html('<div class="lcrm-conv-empty"><span class="lcrm-spinner"></span> Loading…</div>');
            lcrmPost('lcrm_get_pdfs',{lead_id:leadId},function(d){
                if(!d.docs || !d.docs.length){
                    $c.html('<div class="lcrm-conv-empty">No documents uploaded yet.</div>');
                    return;
                }
                var html='';
                d.docs.forEach(function(doc){
                    html += '<div class="lcrm-pdf-doc-row" style="display:flex;align-items:flex-start;gap:10px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;">'
                          + '<div style="font-size:24px;line-height:1;">&#128196;</div>'
                          + '<div style="flex:1;min-width:0;">'
                          + '<div style="font-weight:500;font-size:13px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
                          + '<a href="'+lcrmEscHtml(doc.file_url)+'" target="_blank" style="color:#6366f1;">'+lcrmEscHtml(doc.file_name)+'</a>'
                          + ' <span style="font-size:11px;color:#6b7280;">('+lcrmEscHtml(doc.file_size)+')</span></div>'
                          + (doc.ai_summary ? '<div style="font-size:12px;color:#374151;margin-top:4px;">AI: '+lcrmEscHtml(doc.ai_summary)+'</div>' : '')
                          + '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">'+lcrmEscHtml(doc.created_at)+'</div>'
                          + '</div>'
                          + '<button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-v4-pdf-delete" data-id="'+doc.id+'" data-name="'+lcrmEscAttr(doc.file_name)+'">&#128465;</button>'
                          + '</div>';
                });
                $c.html(html);
            },function(){ $c.html('<div class="lcrm-conv-empty" style="color:#ef4444;">Failed to load documents.</div>'); });
        }

        // PDF tab click → reload list
        $(document).on('click','#lcrm-v4-pdf-tab-btn',function(){
            var leadId = parseInt($('#lf-id').val()||0);
            if(leadId) lcrmV4LoadPdfs(leadId);
        });

        // Choose / upload PDF
        $(document).on('click','#lcrm-v4-pdf-choose-btn',function(){ $('#lcrm-v4-pdf-input').click(); });
        $(document).on('change','#lcrm-v4-pdf-input',function(){ lcrmV4UploadPdf(this.files[0]); });

        // Drag & drop
        $(document).on('dragover','#lcrm-v4-pdf-dropzone',function(e){ e.preventDefault(); $(this).css('border-color','#6366f1'); });
        $(document).on('dragleave','#lcrm-v4-pdf-dropzone',function(){ $(this).css('border-color','#cbd5e1'); });
        $(document).on('drop','#lcrm-v4-pdf-dropzone',function(e){
            e.preventDefault();
            $(this).css('border-color','#cbd5e1');
            var f = e.originalEvent.dataTransfer.files[0];
            if(f) lcrmV4UploadPdf(f);
        });

        function lcrmV4UploadPdf(file){
            var leadId = parseInt($('#lf-id').val()||0);
            if(!leadId){ alert('Save the lead first before uploading documents.'); return; }
            if(!file){ return; }
            if(!file.name.toLowerCase().endsWith('.pdf')){ alert('Only PDF files are supported.'); return; }
            if(file.size > 20*1024*1024){ alert('File is too large. Maximum size is 20MB.'); return; }

            var $prog = $('#lcrm-v4-pdf-upload-progress');
            var $bar  = $('#lcrm-v4-pdf-progress-bar');
            var $txt  = $('#lcrm-v4-pdf-progress-text');
            var $res  = $('#lcrm-v4-pdf-upload-result');

            $prog.show(); $res.hide(); $bar.css('width','30%');
            $txt.text('Uploading '+file.name+'...');

            var fd = new FormData();
            fd.append('action','lcrm_upload_pdf');
            fd.append('nonce',LCRM.nonce);
            fd.append('lead_id',leadId);
            fd.append('pdf_file',file);

            $.ajax({
                url: LCRM.ajax_url, method:'POST', data:fd,
                processData:false, contentType:false,
                xhr:function(){
                    var x=new XMLHttpRequest();
                    x.upload.addEventListener('progress',function(e){
                        if(e.lengthComputable){ var pct=Math.round(e.loaded/e.total*60); $bar.css('width',(30+pct)+'%'); }
                    });
                    return x;
                },
                success:function(res){
                    $bar.css('width','100%');
                    $txt.text('Analysing with AI...');
                    setTimeout(function(){
                        $prog.hide(); $bar.css('width','0%');
                        if(res.success){
                            var d=res.data;
                            var html='<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px;margin-bottom:10px;">'
                                   + '<div style="font-weight:600;font-size:13px;color:#166534;margin-bottom:6px;">✓ '+lcrmEscHtml(d.msg)+'</div>';
                            if(d.ai_summary) html+='<div style="font-size:12px;color:#374151;"><strong>AI Summary:</strong> '+lcrmEscHtml(d.ai_summary)+'</div>';
                            if(d.alerts_created) html+='<div style="font-size:12px;color:#374151;margin-top:4px;"><strong>'+d.alerts_created+' deadline alert(s)</strong> created.</div>';
                            if(d.deadlines && d.deadlines.length){
                                html+='<div style="font-size:12px;margin-top:4px;"><strong>Deadlines:</strong> ';
                                html+=d.deadlines.map(function(dl){ return lcrmEscHtml(dl.description)+(dl.date?' ('+dl.date+')':''); }).join(', ');
                                html+='</div>';
                            }
                            html+='</div>';
                            $res.html(html).show();
                            lcrmV4LoadPdfs(leadId);
                        } else {
                            $res.html('<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:10px;font-size:13px;color:#991b1b;">✗ '+(res.data&&res.data.msg?lcrmEscHtml(res.data.msg):'Upload failed.')+'</div>').show();
                        }
                    }, 500);
                },
                error:function(){ $prog.hide(); $res.html('<div style="color:#ef4444;font-size:13px;">Upload error. Check console.</div>').show(); }
            });
        }

        // Delete PDF
        $(document).on('click','.lcrm-v4-pdf-delete',function(){
            var id=$(this).data('id'), name=$(this).data('name');
            if(!confirm('Delete "'+name+'"? This cannot be undone.')) return;
            var $btn=$(this).prop('disabled',true);
            lcrmPost('lcrm_delete_pdf',{doc_id:id},function(){
                var leadId=parseInt($('#lf-id').val()||0);
                if(leadId) lcrmV4LoadPdfs(leadId);
            },function(e){ $btn.prop('disabled',false); alert(e.msg||'Delete failed.'); });
        });

        // ── Outbound WA ──────────────────────────────────────────────────────
        $(document).on('click','#lcrm-v4-wa-tab-btn',function(){
            // Load sent history from conversations
        });

        $(document).on('click','#lcrm-v4-wa-send-btn',function(){
            var leadId   = parseInt($('#lf-id').val()||0);
            var message  = $('#lcrm-v4-wa-message').val().trim();
            var accountId= $('#lcrm-v4-wa-account').val()||0;
            var $msg     = $('#lcrm-v4-wa-msg');
            if(!leadId){ $msg.css('color','#ef4444').text('No lead selected.'); return; }
            if(!message){ $msg.css('color','#ef4444').text('Message cannot be empty.'); return; }
            var $btn=$(this).prop('disabled',true).text('Sending…');
            lcrmPost('lcrm_wa_send_reply',{lead_id:leadId,message:message,account_id:accountId},function(d){
                $btn.prop('disabled',false).text('⮕ Send Message');
                $msg.css('color','#10b981').text('✓ Sent at '+d.time);
                $('#lcrm-v4-wa-message').val('');
                // Append to outbound history immediately
                var $h=$('#lcrm-v4-wa-history');
                var row='<div style="background:#f0fdf4;border-radius:6px;padding:8px 10px;margin-bottom:6px;font-size:12px;">'
                      + '<span style="color:#6b7280;">'+lcrmEscHtml(d.time)+' · '+lcrmEscHtml(d.user)+'</span><br>'
                      + '<span>'+lcrmEscHtml(d.text)+'</span></div>';
                $h.prepend(row);
            },function(e){ $btn.prop('disabled',false).text('⮕ Send Message'); $msg.css('color','#ef4444').text('✗ '+(e.msg||'Send failed.')); });
        });

        // ── AI Suggestion ────────────────────────────────────────────────────
        function lcrmV4GetSuggestion(targetBox){
            var leadId = parseInt($('#lf-id').val()||0);
            if(!leadId) return;
            $(targetBox).text('Generating suggestion…');
            lcrmPost('lcrm_ai_get_suggestion',{lead_id:leadId},function(d){
                $(targetBox).text(d.suggestion||'No suggestion generated.');
                $('#lcrm-v4-ai-use-suggestion-btn').show();
            },function(e){ $(targetBox).text('AI error: '+(e.msg||'Unknown error')); });
        }

        $(document).on('click','#lcrm-v4-ai-suggest-btn',function(){ lcrmV4GetSuggestion('#lcrm-v4-ai-suggestion-box'); });
        $(document).on('click','#lcrm-v4-ai-suggest-btn2',function(){ lcrmV4GetSuggestion('#lcrm-v4-ai-suggestion-box'); });

        // "Use This Reply" copies suggestion to WA message box and switches tab
        $(document).on('click','#lcrm-v4-ai-use-suggestion-btn',function(){
            var suggestion = $('#lcrm-v4-ai-suggestion-box').text();
            $('#lcrm-v4-wa-message').val(suggestion);
            // Switch to outbound tab
            $('.lcrm-ftab[data-tab="outbound_wa"]').click();
        });

        // AI Brief
        $(document).on('click','#lcrm-v4-brief-btn',function(){
            var $box=$('#lcrm-v4-brief-box');
            $box.text('Loading…');
            lcrmPost('lcrm_ai_get_brief',{},function(d){ $box.text(d.brief||'No brief available.'); },function(){ $box.text('Error loading brief.'); });
        });

        // Helper — escape HTML for display
        function lcrmEscHtml(str){
            if(!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function lcrmEscAttr(str){
            if(!str) return '';
            return String(str).replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }
    })(jQuery);
    </script>
    <?php
}
