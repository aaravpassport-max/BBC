<?php
/**
 * LeadCRM Client Portal Module
 * Generates a magic-link (token-secured) page where clients can:
 *   - View their case status and stage
 *   - See a sanitised activity timeline
 *   - Upload documents
 *   - Send a message to the team
 *
 * No WordPress login required — access via unique token only.
 * Portal renders on a custom WordPress page using shortcode [lcrm_client_portal]
 * or via the rewrite rule /lcrm-portal/{token}
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB ──────────────────────────────────────────────────────────────────────

// TRACE: Creates lcrm_portal_tokens table for magic-link storage.
//        POSTCONDITIONS: Table exists. EDGE CASES: Idempotent.
function lcrm_portal_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_portal_tokens (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id      BIGINT UNSIGNED NOT NULL,
        token        VARCHAR(64)     NOT NULL DEFAULT '',
        expires_at   DATETIME        DEFAULT NULL,
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME        DEFAULT NULL,
        access_count INT             NOT NULL DEFAULT 0,
        is_active    TINYINT(1)      NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY token (token),
        KEY lead_id (lead_id)
    ) $c;" );

    // Portal client messages — messages sent by clients via portal
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_portal_messages (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id     BIGINT UNSIGNED NOT NULL,
        token_id    BIGINT UNSIGNED NOT NULL,
        message     TEXT            NOT NULL,
        is_read     TINYINT(1)      NOT NULL DEFAULT 0,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY is_read (is_read)
    ) $c;" );
}

// ─── REWRITE RULES ───────────────────────────────────────────────────────────

// TRACE: Registers /lcrm-portal/{token} rewrite rule on init.
//        POSTCONDITIONS: URL accessible after flush_rewrite_rules().
add_action( 'init', 'lcrm_portal_register_rewrite' );
function lcrm_portal_register_rewrite() {
    add_rewrite_rule( '^lcrm-portal/([a-f0-9]{64})/?$', 'index.php?lcrm_portal_token=$matches[1]', 'top' );
    add_rewrite_tag( '%lcrm_portal_token%', '([a-f0-9]{64})' );
}

// TRACE: Intercepts WordPress template loading when lcrm_portal_token query var is set →
//        renders the portal page and exits without loading any theme template.
//        EDGE CASES: Invalid token → shows "link expired" message; expired token → same.
add_filter( 'template_redirect', 'lcrm_portal_maybe_render' );
function lcrm_portal_maybe_render() {
    $token = get_query_var( 'lcrm_portal_token', '' );
    if ( ! $token ) return;

    $portal_data = lcrm_portal_get_by_token( $token );

    if ( ! $portal_data || ! $portal_data['valid'] ) {
        lcrm_portal_render_error( 'This link has expired or is invalid. Please contact us for a new link.' );
        exit;
    }

    lcrm_portal_render_page( $portal_data );
    exit;
}

// ─── TOKEN MANAGEMENT ────────────────────────────────────────────────────────

// TRACE: Generates a cryptographically secure 64-char hex token for a lead →
//        Deactivates any existing token for that lead → INSERTs new token row →
//        Returns {token, url, expires_at}.
//        PRECONDITIONS: lead_id valid.
//        POSTCONDITIONS: Old tokens for this lead deactivated; new token active.
//        EDGE CASES: Duplicate token collision (1 in 2^256) → handled by UNIQUE KEY retry.
function lcrm_portal_generate_token( $lead_id, $expiry_days = 30 ) {
    global $wpdb;

    // Deactivate old tokens for this lead
    $wpdb->update(
        "{$wpdb->prefix}lcrm_portal_tokens",
        [ 'is_active' => 0 ],
        [ 'lead_id' => (int) $lead_id ]
    );

    // Generate secure token
    $token      = bin2hex( random_bytes( 32 ) );
    $expires_at = date( 'Y-m-d H:i:s', strtotime( "+{$expiry_days} days" ) );

    $result = $wpdb->insert( "{$wpdb->prefix}lcrm_portal_tokens", [
        'lead_id'    => (int) $lead_id,
        'token'      => $token,
        'expires_at' => $expires_at,
        'is_active'  => 1,
        'created_at' => current_time( 'mysql' ),
    ] );

    if ( ! $result ) return null;

    $url = home_url( "/lcrm-portal/{$token}" );
    return [ 'token' => $token, 'url' => $url, 'expires_at' => $expires_at ];
}

// TRACE: Validates a token → loads lead data if valid → updates usage metrics.
//        Returns null if invalid/expired; returns portal data array if valid.
//        EDGE CASES: Token not in DB → null; is_active=0 → null; expires_at past → null.
function lcrm_portal_get_by_token( $token ) {
    global $wpdb;

    $token = sanitize_text_field( $token );
    if ( strlen( $token ) !== 64 ) return null;

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_portal_tokens
         WHERE token = %s AND is_active = 1",
        $token
    ) );

    if ( ! $row ) return null;
    if ( $row->expires_at && strtotime( $row->expires_at ) < time() ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_portal_tokens", [ 'is_active' => 0 ], [ 'id' => $row->id ] );
        return null;
    }

    // Update usage stats
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}lcrm_portal_tokens
         SET last_used_at = %s, access_count = access_count + 1
         WHERE id = %d",
        current_time( 'mysql' ), (int) $row->id
    ) );

    $lead = lcrm_get_lead( (int) $row->lead_id );
    if ( ! $lead ) return null;

    $stage = lcrm_get_stage( (int) $lead->stage_id );

    // Build sanitised timeline — only client-safe entries
    $timeline = lcrm_portal_get_client_timeline( (int) $row->lead_id );

    // Get client-uploaded docs
    global $wpdb;
    $docs = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, file_name, file_path, file_size, created_at
         FROM {$wpdb->prefix}lcrm_pdf_documents
         WHERE lead_id = %d
         ORDER BY created_at DESC",
        (int) $row->lead_id
    ) );

    return [
        'valid'       => true,
        'token_id'    => (int) $row->id,
        'lead_id'     => (int) $row->lead_id,
        'client_name' => $lead->client_name,
        'work_type'   => $lead->work_type,
        'stage_name'  => $stage ? $stage->name  : 'In Progress',
        'stage_color' => $stage ? $stage->color : '#6366f1',
        'lead_date'   => $lead->lead_date,
        'timeline'    => $timeline,
        'docs'        => (array) $docs,
        'token'       => $token,
    ];
}

// TRACE: Builds client-safe activity timeline — excludes internal notes, staff remarks, finance data.
//        Only includes: stage changes, document uploads, work updates, system messages.
//        POSTCONDITIONS: Returns array of {date, icon, message} safe to show client.
function lcrm_portal_get_client_timeline( $lead_id ) {
    global $wpdb;

    // Conversations marked as 'system' (stage changes, work updates)
    $convs = $wpdb->get_results( $wpdb->prepare(
        "SELECT message, created_at FROM {$wpdb->prefix}lcrm_conversations
         WHERE lead_id = %d
           AND direction IN ('system','client')
           AND message NOT LIKE '%payment%'
           AND message NOT LIKE '%balance%'
           AND message NOT LIKE '%quote%'
           AND message NOT LIKE '%[Internal]%'
         ORDER BY created_at ASC
         LIMIT 50",
        $lead_id
    ) );

    // Document uploads
    $uploads = $wpdb->get_results( $wpdb->prepare(
        "SELECT CONCAT('Document uploaded: ', file_name) AS message, created_at
         FROM {$wpdb->prefix}lcrm_pdf_documents WHERE lead_id = %d",
        $lead_id
    ) );

    $all = array_merge( (array) $convs, (array) $uploads );
    usort( $all, fn( $a, $b ) => strcmp( $a->created_at, $b->created_at ) );

    return array_map( fn( $item ) => [
        'message'    => wp_strip_all_tags( $item->message ),
        'created_at' => $item->created_at,
        'date_label' => date( 'd M Y, H:i', strtotime( $item->created_at ) ),
    ], $all );
}

// ─── PORTAL PAGE RENDERER ────────────────────────────────────────────────────

// TRACE: Renders the full client portal HTML page — standalone, no WP theme.
//        Outputs complete DOCTYPE HTML with inline CSS, then exits.
//        PRECONDITIONS: $portal_data is valid (from lcrm_portal_get_by_token).
//        POSTCONDITIONS: Full HTML page output; execution ends with exit.
function lcrm_portal_render_page( $portal_data ) {
    $name       = esc_html( $portal_data['client_name'] );
    $work       = esc_html( $portal_data['work_type'] );
    $stage      = esc_html( $portal_data['stage_name'] );
    $color      = esc_attr( $portal_data['stage_color'] );
    $lead_id    = (int) $portal_data['lead_id'];
    $token      = esc_attr( $portal_data['token'] );
    $ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
    $site_name  = esc_html( get_bloginfo( 'name' ) );
    $site_logo  = esc_url( get_site_icon_url( 64 ) );
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Case Status — <?php echo $site_name; ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;color:#1e293b;min-height:100vh}
.portal-wrap{max-width:680px;margin:0 auto;padding:20px 16px 60px}
.portal-header{background:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.portal-logo{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.portal-logo img{width:36px;height:36px;border-radius:8px}
.portal-logo span{font-size:15px;font-weight:600;color:#374151}
.portal-greeting{font-size:20px;font-weight:700;color:#1e293b;margin-bottom:4px}
.portal-work{font-size:13px;color:#6b7280}
.stage-badge{display:inline-flex;align-items:center;gap:6px;background:var(--sc);color:#fff;border-radius:20px;padding:6px 14px;font-size:13px;font-weight:600;margin-top:12px}
.stage-dot{width:8px;height:8px;background:rgba(255,255,255,.6);border-radius:50%}
.section{background:#fff;border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.section-title{font-size:14px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.timeline-item{display:flex;gap:12px;margin-bottom:14px;align-items:flex-start}
.tl-dot{width:10px;height:10px;background:#6366f1;border-radius:50%;margin-top:5px;flex-shrink:0}
.tl-msg{font-size:13px;color:#374151;flex:1}
.tl-date{font-size:11px;color:#9ca3af;margin-top:2px}
.tl-empty{font-size:13px;color:#9ca3af;text-align:center;padding:16px}
.doc-row{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f8fafc;border-radius:8px;margin-bottom:8px}
.doc-icon{font-size:20px;flex-shrink:0}
.doc-info{flex:1;min-width:0}
.doc-name{font-size:13px;font-weight:500;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.doc-meta{font-size:11px;color:#9ca3af;margin-top:2px}
.doc-dl{font-size:12px;color:#6366f1;text-decoration:none;font-weight:500}
.upload-area{border:2px dashed #cbd5e1;border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:.2s}
.upload-area:hover{border-color:#6366f1;background:#f5f3ff}
.upload-area .icon{font-size:32px;margin-bottom:8px}
.upload-area p{font-size:13px;color:#6b7280}
.upload-area small{font-size:11px;color:#9ca3af}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:.15s}
.btn-primary{background:#6366f1;color:#fff}.btn-primary:hover{background:#4f46e5}
.btn-sm{padding:7px 14px;font-size:13px}
.msg-textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:12px;font-size:13px;font-family:inherit;resize:vertical;min-height:90px;outline:none;transition:.15s}
.msg-textarea:focus{border-color:#6366f1}
.feedback{padding:10px 14px;border-radius:8px;font-size:13px;margin-top:10px;display:none}
.feedback.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.feedback.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.upload-prog{height:4px;background:#e2e8f0;border-radius:2px;margin-top:10px;overflow:hidden;display:none}
.upload-prog-bar{height:4px;background:#6366f1;width:0%;transition:.3s}
@media(max-width:420px){.portal-wrap{padding:12px 10px 40px}}
</style>
</head>
<body>
<div class="portal-wrap">
  <div class="portal-header">
    <div class="portal-logo">
      <?php if ( $site_logo ): ?><img src="<?php echo $site_logo; ?>" alt=""><?php endif; ?>
      <span><?php echo $site_name; ?></span>
    </div>
    <div class="portal-greeting">Namaste, <?php echo $name; ?> 🙏</div>
    <div class="portal-work"><?php echo $work ?: 'Your service request'; ?></div>
    <div class="stage-badge" style="--sc:<?php echo $color; ?>">
      <span class="stage-dot"></span>
      <?php echo $stage; ?>
    </div>
  </div>

  <!-- TIMELINE -->
  <div class="section">
    <div class="section-title">📋 Case Updates</div>
    <?php if ( empty( $portal_data['timeline'] ) ): ?>
      <div class="tl-empty">Your case is being reviewed. Updates will appear here.</div>
    <?php else: ?>
      <?php foreach ( array_reverse( $portal_data['timeline'] ) as $item ): ?>
      <div class="timeline-item">
        <div class="tl-dot"></div>
        <div>
          <div class="tl-msg"><?php echo esc_html( $item['message'] ); ?></div>
          <div class="tl-date"><?php echo esc_html( $item['date_label'] ); ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- DOCUMENTS -->
  <div class="section">
    <div class="section-title">📎 Documents</div>

    <?php if ( ! empty( $portal_data['docs'] ) ): ?>
    <?php foreach ( $portal_data['docs'] as $doc ): ?>
    <div class="doc-row">
      <div class="doc-icon">📄</div>
      <div class="doc-info">
        <div class="doc-name"><?php echo esc_html( $doc->file_name ); ?></div>
        <div class="doc-meta"><?php echo esc_html( date( 'd M Y', strtotime( $doc->created_at ) ) ); ?></div>
      </div>
      <a href="<?php echo esc_url( $doc->file_path ); ?>" class="doc-dl" target="_blank">Download ↗</a>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="upload-area" id="portal-drop-zone">
      <div class="icon">☁</div>
      <p>Tap to upload a document</p>
      <small>PDF, JPG, PNG · Max 10MB</small>
      <input type="file" id="portal-file-input" accept=".pdf,.jpg,.jpeg,.png" style="display:none">
    </div>
    <div class="upload-prog"><div class="upload-prog-bar" id="portal-prog-bar"></div></div>
    <div class="feedback" id="portal-upload-fb"></div>
  </div>

  <!-- MESSAGE -->
  <div class="section">
    <div class="section-title">💬 Send Us a Message</div>
    <textarea class="msg-textarea" id="portal-msg-text" placeholder="Any questions or updates? We'll get back to you shortly."></textarea>
    <button class="btn btn-primary btn-sm" id="portal-send-btn" style="margin-top:10px">Send Message</button>
    <div class="feedback" id="portal-msg-fb"></div>
  </div>

  <p style="text-align:center;font-size:11px;color:#9ca3af;margin-top:24px">
    This page is private to you. Do not share this link.<br>
    Powered by <?php echo $site_name; ?>
  </p>
</div>

<script>
(function(){
  var AJAX='<?php echo $ajax_url; ?>', TOKEN='<?php echo $token; ?>', LEAD=<?php echo $lead_id; ?>;

  function fb(id,type,msg){
    var el=document.getElementById(id);
    el.className='feedback '+type; el.textContent=msg; el.style.display='block';
    if(type==='success') setTimeout(function(){el.style.display='none';},4000);
  }

  // Upload
  var dz=document.getElementById('portal-drop-zone');
  var fi=document.getElementById('portal-file-input');
  var pb=document.getElementById('portal-prog-bar');
  var pg=pb.parentElement;

  dz.addEventListener('click',function(){fi.click();});
  dz.addEventListener('dragover',function(e){e.preventDefault();dz.style.borderColor='#6366f1';});
  dz.addEventListener('dragleave',function(){dz.style.borderColor='#cbd5e1';});
  dz.addEventListener('drop',function(e){e.preventDefault();dz.style.borderColor='#cbd5e1';doUpload(e.dataTransfer.files[0]);});
  fi.addEventListener('change',function(){if(fi.files[0]) doUpload(fi.files[0]);});

  function doUpload(file){
    if(!file) return;
    var allowed=['application/pdf','image/jpeg','image/png'];
    if(allowed.indexOf(file.type)===-1){fb('portal-upload-fb','error','Only PDF, JPG, and PNG files are allowed.');return;}
    if(file.size>10*1024*1024){fb('portal-upload-fb','error','File is too large. Maximum 10MB.');return;}
    var fd=new FormData();
    fd.append('action','lcrm_portal_upload');
    fd.append('token',TOKEN);
    fd.append('portal_file',file);
    pg.style.display='block'; pb.style.width='20%';
    var xhr=new XMLHttpRequest();
    xhr.upload.onprogress=function(e){if(e.lengthComputable)pb.style.width=(20+e.loaded/e.total*60)+'%';};
    xhr.onload=function(){
      pb.style.width='100%';
      setTimeout(function(){pg.style.display='none';pb.style.width='0%';},500);
      var res=JSON.parse(xhr.responseText);
      if(res.success){fb('portal-upload-fb','success','Document uploaded successfully.');setTimeout(function(){location.reload();},2000);}
      else{fb('portal-upload-fb','error',res.data&&res.data.msg?res.data.msg:'Upload failed.');}
    };
    xhr.open('POST',AJAX);
    xhr.send(fd);
  }

  // Send message
  document.getElementById('portal-send-btn').addEventListener('click',function(){
    var msg=document.getElementById('portal-msg-text').value.trim();
    if(!msg){fb('portal-msg-fb','error','Please type a message first.');return;}
    var fd=new FormData();
    fd.append('action','lcrm_portal_send_message');
    fd.append('token',TOKEN);
    fd.append('message',msg);
    fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
      if(res.success){fb('portal-msg-fb','success','Message sent. We\'ll be in touch soon.');document.getElementById('portal-msg-text').value='';}
      else{fb('portal-msg-fb','error',res.data&&res.data.msg?res.data.msg:'Could not send message.');}
    });
  });
})();
</script>
</body>
</html>
    <?php
}

// TRACE: Renders a standalone error page when token is invalid/expired.
function lcrm_portal_render_error( $message ) {
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Link Expired</title>
<style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f1f5f9;color:#374151}
.box{background:#fff;border-radius:16px;padding:40px;text-align:center;max-width:360px;box-shadow:0 4px 20px rgba(0,0,0,.08)}
h2{font-size:20px;margin-bottom:10px;color:#1e293b} p{font-size:14px;color:#6b7280;line-height:1.6}</style>
</head><body><div class="box"><div style="font-size:48px;margin-bottom:16px">🔒</div>
<h2>Link Expired</h2><p><?php echo esc_html( $message ); ?></p></div></body></html>
    <?php
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_nopriv_lcrm_portal_upload',       'lcrm_ajax_portal_upload' );
add_action( 'wp_ajax_lcrm_portal_upload',              'lcrm_ajax_portal_upload' );
add_action( 'wp_ajax_nopriv_lcrm_portal_send_message', 'lcrm_ajax_portal_send_message' );
add_action( 'wp_ajax_lcrm_portal_send_message',        'lcrm_ajax_portal_send_message' );
add_action( 'wp_ajax_lcrm_portal_generate_link',       'lcrm_ajax_portal_generate_link' );
add_action( 'wp_ajax_lcrm_portal_revoke_link',         'lcrm_ajax_portal_revoke_link' );
add_action( 'wp_ajax_lcrm_portal_get_messages',        'lcrm_ajax_portal_get_messages' );
add_action( 'wp_ajax_lcrm_portal_read_message',        'lcrm_ajax_portal_read_message' );

// TRACE: nopriv handler — validates token → validates file (PDF/JPG/PNG/10MB) →
//        saves to lead uploads dir → inserts pdf_documents row.
//        POSTCONDITIONS: File on disk; row in lcrm_pdf_documents.
//        EDGE CASES: Invalid token → 401; wrong file type → error; > 10MB → error.
function lcrm_ajax_portal_upload() {
    $token = sanitize_text_field( $_POST['token'] ?? '' );
    $data  = lcrm_portal_get_by_token( $token );
    if ( ! $data ) wp_send_json_error( [ 'msg' => 'Invalid or expired link.' ], 401 );

    if ( empty( $_FILES['portal_file']['tmp_name'] ) ) wp_send_json_error( [ 'msg' => 'No file received.' ] );

    $file     = $_FILES['portal_file'];
    $tmp      = $file['tmp_name'];
    $orig     = sanitize_file_name( $file['name'] );
    $size     = (int) $file['size'];
    $mime     = mime_content_type( $tmp );
    $ext      = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
    $allowed  = [ 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' ];

    if ( ! array_key_exists( $ext, $allowed ) ) {
        wp_send_json_error( [ 'msg' => 'Only PDF, JPG, and PNG files are allowed.' ] );
    }
    if ( $size > 10 * 1024 * 1024 ) wp_send_json_error( [ 'msg' => 'File must be under 10MB.' ] );
    if ( $size === 0 ) wp_send_json_error( [ 'msg' => 'File is empty.' ] );

    $upload_dir = wp_upload_dir();
    $dest_dir   = $upload_dir['basedir'] . '/lcrm-docs/lead-' . $data['lead_id'] . '/portal/';
    wp_mkdir_p( $dest_dir );

    $unique_name = time() . '_client_' . $orig;
    $dest_path   = $dest_dir . $unique_name;
    $dest_url    = $upload_dir['baseurl'] . '/lcrm-docs/lead-' . $data['lead_id'] . '/portal/' . $unique_name;

    if ( ! move_uploaded_file( $tmp, $dest_path ) ) {
        wp_send_json_error( [ 'msg' => 'Could not save file. Please try again.' ] );
    }

    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}lcrm_pdf_documents", [
        'lead_id'        => $data['lead_id'],
        'file_name'      => '[Client] ' . $orig,
        'file_path'      => $dest_url,
        'file_size'      => $size,
        'extracted_text' => '',
        'ai_summary'     => '',
        'ai_priority'    => 'medium',
        'ai_data'        => '{}',
        'created_by'     => 0,
        'created_at'     => current_time( 'mysql' ),
    ] );

    lcrm_log_activity( $data['lead_id'], 'portal_upload', "Client uploaded document: {$orig}" );
    lcrm_create_alert_deduped( $data['lead_id'], 'portal_doc', 'medium', "Client uploaded a document: {$orig}", 1 );

    wp_send_json_success( [ 'msg' => 'Document uploaded.', 'url' => $dest_url ] );
}

// TRACE: nopriv handler — validates token → saves portal message to DB →
//        creates alert for admin → appends to lead conversation.
//        POSTCONDITIONS: Message in lcrm_portal_messages; alert created; conversation updated.
//        EDGE CASES: Empty message → error; invalid token → 401.
function lcrm_ajax_portal_send_message() {
    $token   = sanitize_text_field( $_POST['token']   ?? '' );
    $message = sanitize_textarea_field( $_POST['message'] ?? '' );
    $data    = lcrm_portal_get_by_token( $token );
    if ( ! $data ) wp_send_json_error( [ 'msg' => 'Invalid or expired link.' ], 401 );
    if ( ! $message ) wp_send_json_error( [ 'msg' => 'Message cannot be empty.' ] );

    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}lcrm_portal_messages", [
        'lead_id'    => $data['lead_id'],
        'token_id'   => $data['token_id'],
        'message'    => $message,
        'is_read'    => 0,
        'created_at' => current_time( 'mysql' ),
    ] );

    lcrm_add_conversation( $data['lead_id'], '[Client via Portal] ' . mb_substr( $message, 0, 300 ) );
    do_action( 'lcrm_portal_message_sent', $data['lead_id'], $message );
    lcrm_create_alert_deduped(
        $data['lead_id'], 'portal_message', 'high',
        "Client sent a portal message: " . mb_substr( $message, 0, 100 ),
        1
    );

    wp_send_json_success( [ 'msg' => 'Message sent.' ] );
}

// TRACE: Admin generates a portal magic-link for a lead.
//        PRECONDITIONS: Admin; lead_id valid.
//        POSTCONDITIONS: Token in DB; URL returned for sharing via WA.
function lcrm_ajax_portal_generate_link() {
    lcrm_verify();
    $lead_id     = intval( $_POST['lead_id']     ?? 0 );
    $expiry_days = max( 1, min( 365, intval( $_POST['expiry_days'] ?? 30 ) ) );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $result = lcrm_portal_generate_token( $lead_id, $expiry_days );
    if ( ! $result ) wp_send_json_error( [ 'msg' => 'Failed to generate portal link.' ] );

    lcrm_log_activity( $lead_id, 'portal_link', "Portal link generated. Expires: {$result['expires_at']}" );
    wp_send_json_success( [
        'url'        => $result['url'],
        'expires_at' => $result['expires_at'],
        'msg'        => 'Portal link generated.',
    ] );
}

// TRACE: Revokes all active portal tokens for a lead.
function lcrm_ajax_portal_revoke_link() {
    lcrm_verify();
    global $wpdb;
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );
    $wpdb->update( "{$wpdb->prefix}lcrm_portal_tokens", [ 'is_active' => 0 ], [ 'lead_id' => $lead_id ] );
    lcrm_log_activity( $lead_id, 'portal_revoke', 'Portal link revoked.' );
    wp_send_json_success( [ 'msg' => 'Portal link revoked.' ] );
}

// TRACE: Returns unread portal messages for the admin panel.
function lcrm_ajax_portal_get_messages() {
    lcrm_verify();
    global $wpdb;
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    $messages = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_portal_messages WHERE lead_id=%d ORDER BY created_at DESC",
        $lead_id
    ) );
    wp_send_json_success( [ 'messages' => (array) $messages ] );
}

// TRACE: Marks a portal message as read.
function lcrm_ajax_portal_read_message() {
    lcrm_verify();
    global $wpdb;
    $msg_id = intval( $_POST['message_id'] ?? 0 );
    if ( $msg_id ) $wpdb->update( "{$wpdb->prefix}lcrm_portal_messages", [ 'is_read' => 1 ], [ 'id' => $msg_id ] );
    wp_send_json_success( [ 'msg' => 'Marked as read.' ] );
}
