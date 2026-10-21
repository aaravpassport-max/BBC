<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*  Lead CRUD  */

function lcrm_get_leads( $args = [] ) {
    global $wpdb;
    $defaults = [
        'stage_id'=>'','vendor_id'=>'','work_type'=>'','search'=>'',
        'date_from'=>'','date_to'=>'','priority'=>'','temperature'=>'',
        'brand_id'=>'','work_started'=>'','next_action_today'=>false,
        'client_followup_today'=>false,'vendor_followup_today'=>false,
        'internal_status'=>'','remarks_search'=>'','conv_search'=>'','tag_id'=>'','user_id'=>'',
        'orderby'=>'created_at','order'=>'DESC','limit'=>50,'offset'=>0,
    ];
    $a = wp_parse_args($args, $defaults);
    $where = ['1=1']; $vals = [];

    if ($a['stage_id'])   { $where[]='l.stage_id=%d';  $vals[]=intval($a['stage_id']); }
    if ($a['vendor_id'])  { $where[]='l.vendor_id=%d'; $vals[]=intval($a['vendor_id']); }
    if ($a['brand_id'])   { $where[]='l.brand_id=%d';  $vals[]=intval($a['brand_id']); }
    if ($a['priority'])   { $where[]='l.priority=%s';  $vals[]=$a['priority']; }
    if ($a['temperature']){ $where[]='l.lead_temperature=%s'; $vals[]=$a['temperature']; }
    if ($a['work_type'])  { $where[]='l.work_type=%s'; $vals[]=$a['work_type']; }
    if ($a['work_started']!== '') { $where[]='l.work_started=%d'; $vals[]=intval($a['work_started']); }
    if ($a['search']) {
        $like = '%' . $wpdb->esc_like($a['search']) . '%';
        $where[] = '(l.client_name LIKE %s OR l.client_phone LIKE %s OR l.work_type LIKE %s OR l.client_email LIKE %s)';
        $vals    = array_merge($vals,[$like,$like,$like,$like]);
    }
    if ($a['date_from'])           { $where[]='l.lead_date>=%s';            $vals[]=$a['date_from']; }
    if ($a['date_to'])             { $where[]='l.lead_date<=%s';            $vals[]=$a['date_to']; }
    if ($a['next_action_today'])   { $where[]='l.next_action_date=%s';      $vals[]=date('Y-m-d'); }
    if ($a['client_followup_today']){ $where[]='l.client_followup_date=%s'; $vals[]=date('Y-m-d'); }
    if ($a['vendor_followup_today']){ $where[]='l.vendor_followup_date=%s'; $vals[]=date('Y-m-d'); }
    if (!empty($a['internal_status'])) { $like = '%' . $wpdb->esc_like($a['internal_status']) . '%'; $where[] = 'l.internal_status LIKE %s'; $vals[] = $like; }
    if (!empty($a['remarks_search'])) { $like = '%' . $wpdb->esc_like($a['remarks_search']) . '%'; $where[] = '(l.remarks LIKE %s OR l.final_remarks LIKE %s)'; $vals[] = $like; $vals[] = $like; }
    // Conversation search — searches message text in conversations table
    if (!empty($a['conv_search']) && lcrm_conv_table_exists()) {
        $like = '%' . $wpdb->esc_like($a['conv_search']) . '%';
        $ct   = $wpdb->prefix . 'lcrm_conversations';
        $where[] = "l.id IN (SELECT DISTINCT lead_id FROM {$ct} WHERE message LIKE %s)";
        $vals[] = $like;
    }
    // Tag filter
    if (!empty($a['tag_id'])) {
        $where[] = "l.id IN (SELECT lead_id FROM {$wpdb->prefix}lcrm_lead_tags WHERE tag_id=%d)";
        $vals[]  = intval($a['tag_id']);
    }

    $allowed_order  = ['id','lead_date','client_name','work_type','total_quote','pmt_received','next_action_date','created_at','priority','lead_temperature'];
    $ob = in_array($a['orderby'],$allowed_order) ? 'l.'.$a['orderby'] : 'l.created_at';
    $od = strtoupper($a['order']) === 'ASC' ? 'ASC' : 'DESC';

    // User scoping: non-admins see ONLY leads explicitly assigned to them AND not revoked
    if (!empty($a['user_id']) && lcrm_col_exists('assigned_to')) {
        $uid = intval($a['user_id']);
        $where[] = '(l.assigned_to=%d AND l.is_access_revoked=0)';
        $vals[] = $uid;
    }

    $where_sql = implode(' AND ',$where);
    $vals[] = intval($a['limit']); $vals[] = intval($a['offset']);

    // User name joins — only when column exists
    $usr_sel  = lcrm_col_exists('assigned_to') ? ', au.display_name AS assigned_user_name, cu.display_name AS created_user_name' : ", '' AS assigned_user_name, '' AS created_user_name";
    $usr_join = lcrm_col_exists('assigned_to') ? "LEFT JOIN {$wpdb->users} au ON au.ID=l.assigned_to LEFT JOIN {$wpdb->users} cu ON cu.ID=l.created_by" : '';

    $sql = "SELECT l.*, s.name AS stage_name, s.color AS stage_color,
                   v.name AS vendor_name, v.phone AS vendor_phone,
                   b.name AS brand_name{$usr_sel}
            FROM {$wpdb->prefix}lcrm_leads l
            LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id=l.stage_id
            LEFT JOIN {$wpdb->prefix}lcrm_vendors v ON v.id=l.vendor_id
            LEFT JOIN {$wpdb->prefix}lcrm_brands b ON b.id=l.brand_id
            {$usr_join}
            WHERE $where_sql ORDER BY $ob $od LIMIT %d OFFSET %d";
    return $wpdb->get_results($wpdb->prepare($sql,...$vals));
}

function lcrm_count_leads($args=[]) {
    global $wpdb;
    $a = wp_parse_args($args,['stage_id'=>'','search'=>'','vendor_id'=>'','work_type'=>'','date_from'=>'','date_to'=>'','priority'=>'','temperature'=>'','user_id'=>'']);
    $where=['1=1'];$vals=[];
    if($a['stage_id'])  {$where[]='stage_id=%d';$vals[]=intval($a['stage_id']);}
    if($a['vendor_id']) {$where[]='vendor_id=%d';$vals[]=intval($a['vendor_id']);}
    if($a['priority'])  {$where[]='priority=%s';$vals[]=$a['priority'];}
    if($a['temperature']){$where[]='lead_temperature=%s';$vals[]=$a['temperature'];}
    if($a['work_type']) {$where[]='work_type=%s';$vals[]=$a['work_type'];}
    if($a['search']){$like='%'.$wpdb->esc_like($a['search']).'%';$where[]='(client_name LIKE %s OR client_phone LIKE %s OR work_type LIKE %s)';$vals=array_merge($vals,[$like,$like,$like]);}
    if($a['date_from']){$where[]='lead_date>=%s';$vals[]=$a['date_from'];}
    if($a['date_to'])  {$where[]='lead_date<=%s';$vals[]=$a['date_to'];}
    if(!empty($a['user_id']) && lcrm_col_exists('assigned_to')){
        $uid=intval($a['user_id']);
        $where[]='(assigned_to=%d AND is_access_revoked=0)';
        $vals[]=$uid;
    }
    $sql="SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE ".implode(' AND ',$where);
    return (int)(empty($vals)?$wpdb->get_var($sql):$wpdb->get_var($wpdb->prepare($sql,...$vals)));
}

function lcrm_get_lead($id) {
    global $wpdb;
    $lead = $wpdb->get_row($wpdb->prepare(
        "SELECT l.*,s.name AS stage_name,s.color AS stage_color,
                v.name AS vendor_name,v.phone AS vendor_phone,
                b.name AS brand_name
         FROM {$wpdb->prefix}lcrm_leads l
         LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id=l.stage_id
         LEFT JOIN {$wpdb->prefix}lcrm_vendors v ON v.id=l.vendor_id
         LEFT JOIN {$wpdb->prefix}lcrm_brands b ON b.id=l.brand_id
         WHERE l.id=%d",$id
    ));
    if ( $lead ) {
        // Attach decoded custom field values for convenience
        $lead->custom_field_values = json_decode(
            (!empty($lead->custom_fields) ? $lead->custom_fields : '{}'), true
        ) ?: [];
    }
    return $lead;
}

function lcrm_create_lead($data) {
    global $wpdb;
    $row = lcrm_sanitize_lead_row($data);
    $row['created_by'] = get_current_user_id();
    $row['created_at'] = $row['updated_at'] = current_time('mysql');
    $row['balance_payment'] = max(0,$row['total_quote']-$row['pmt_received']);
    $row['profit']          = max(0,$row['total_quote']-$row['vendor_charge']);
    // Save custom field values
    $custom_vals = lcrm_extract_custom_fields($data);
    if (!empty($custom_vals)) $row["custom_fields"] = json_encode($custom_vals);

    $ok = $wpdb->insert("{$wpdb->prefix}lcrm_leads",$row);
    if ($ok===false) return new WP_Error('db','Could not create lead. DB error: '.$wpdb->last_error);
    $id = $wpdb->insert_id;
    lcrm_log_activity($id,'created','Lead created.');
    do_action('lcrm_lead_created',$id,$row);
    return $id;
}

function lcrm_update_lead($id,$data) {
    global $wpdb;
    $old = lcrm_get_lead($id);
    $row = lcrm_sanitize_lead_row($data);
    $row['balance_payment'] = max(0,$row['total_quote']-$row['pmt_received']);
    $row['profit']          = max(0,$row['total_quote']-$row['vendor_charge']);
    $row['updated_at']      = current_time('mysql');

    // CRITICAL: Non-admin users must NEVER overwrite assignment fields.
    // Their form has no assigned_to field so it arrives as null in POST,
    // which would silently unassign the lead. Always preserve the DB values.
    if (!lcrm_can_assign_leads()) {
        unset($row['assigned_to']);
    }
    // Always preserve is_access_revoked — only admin actions should change this
    unset($row['is_access_revoked']);

    // Auto work_started if payment moved to a "work" stage
    if (isset($data['stage_id']) && $row['pmt_received']>0) {
        $stage = lcrm_get_stage($row['stage_id']);
        if ($stage && in_array($stage->name,['Assigned and In Progress','Work Completed','Delivered'])) $row['work_started']=1;
    }
    // Merge custom field values
    $custom_vals = lcrm_extract_custom_fields($data);
    if (!empty($custom_vals)) {
        $existing = json_decode((!empty($old) && !empty($old->custom_fields)) ? $old->custom_fields : "{}", true) ?: [];
        $row["custom_fields"] = json_encode(array_merge($existing, $custom_vals));
    }

    $wpdb->update("{$wpdb->prefix}lcrm_leads",$row,['id'=>intval($id)]);

    // Log individual field changes for full audit trail
    $loggable = [
        'stage_id'             => 'Stage',
        'priority'             => 'Priority',
        'lead_temperature'     => 'Temperature',
        'work_type'            => 'Work Type',
        'internal_status'      => 'Internal Status',
        'next_action_date'     => 'Next Action Date',
        'client_followup_date' => 'Client Follow-up',
        'vendor_followup_date' => 'Vendor Follow-up',
        'work_started'         => 'Work Started',
        'total_quote'          => 'Total Quote',
        'pmt_received'         => 'Payment Received',
        'vendor_charge'        => 'Vendor Charge',
        'pmt_to_vendor'        => 'PMT to Vendor',
        'assigned_to'          => 'Assigned To',
    ];
    foreach ($loggable as $field => $label) {
        if (!isset($row[$field])) continue;
        $old = isset($old->$field) ? $old->$field : '';
        $new = $row[$field];
        // For stage_id, show stage name not ID
        if ($field === 'stage_id') {
            $os = lcrm_get_stage($old); $ns2 = lcrm_get_stage($new);
            $old = $os ? $os->name : $old; $new = $ns2 ? $ns2->name : $new;
        }
        if ((string)$old !== (string)$new) lcrm_log_field_change($id, $label, $old, $new);
    }
    if ($old && isset($row['stage_id']) && $old->stage_id!=$row['stage_id']) {
        $ns=lcrm_get_stage($row['stage_id']);
        lcrm_log_activity($id,'stage_change','Stage changed to: '.((isset($ns->name) ? $ns->name : '')));
    }
    if (isset($data['remarks']) && $data['remarks']!=((isset($old->remarks) ? $old->remarks : ''))) {
        $wpdb->update("{$wpdb->prefix}lcrm_leads",['last_client_contact'=>current_time('mysql')],['id'=>intval($id)]);
    }
    do_action('lcrm_lead_updated',$id,$row,$old);
    return true;
}

function lcrm_delete_lead($id) {
    global $wpdb;
    $wpdb->delete("{$wpdb->prefix}lcrm_leads",    ['id'=>intval($id)]);
    $wpdb->delete("{$wpdb->prefix}lcrm_activity",  ['lead_id'=>intval($id)]);
    $wpdb->delete("{$wpdb->prefix}lcrm_reminders", ['lead_id'=>intval($id)]);
    $wpdb->delete("{$wpdb->prefix}lcrm_alerts",    ['lead_id'=>intval($id)]);
    $wpdb->delete("{$wpdb->prefix}lcrm_vendor_logs",['lead_id'=>intval($id)]);
    return true;
}

function lcrm_parse_date($d) {
    $d = sanitize_text_field($d);
    if ( $d && strtotime($d) ) {
        return date('Y-m-d', strtotime($d));
    }
    return date('Y-m-d');
}

function lcrm_sanitize_lead_row($data) {
    $row = [
        'lead_date'            => lcrm_parse_date(isset($data['lead_date'])?$data['lead_date']:''),
        'client_name'          => sanitize_text_field((isset($data['client_name']) ? $data['client_name'] : '')),
        'client_phone'         => sanitize_text_field((isset($data['client_phone']) ? $data['client_phone'] : '')),
        'client_email'         => sanitize_email((isset($data['client_email']) ? $data['client_email'] : '')),
        'work_type'            => sanitize_text_field((isset($data['work_type']) ? $data['work_type'] : '')),
        'lead_source'          => sanitize_text_field((isset($data['lead_source']) ? $data['lead_source'] : '')),
        'stage_id'             => intval((isset($data['stage_id']) ? $data['stage_id'] : 1)),
        'brand_id'             => intval((isset($data['brand_id']) ? $data['brand_id'] : 0)) ?: null,
        'internal_status'      => sanitize_text_field((isset($data['internal_status']) ? $data['internal_status'] : '')),
        'next_action_date'     => sanitize_text_field((isset($data['next_action_date']) ? $data['next_action_date'] : '')) ?: null,
        'client_followup_date' => sanitize_text_field((isset($data['client_followup_date']) ? $data['client_followup_date'] : '')) ?: null,
        'vendor_followup_date' => sanitize_text_field((isset($data['vendor_followup_date']) ? $data['vendor_followup_date'] : '')) ?: null,
        'priority'             => in_array((isset($data['priority']) ? $data['priority'] : ''),['low','medium','high']) ? $data['priority'] : 'medium',
        'lead_temperature'     => in_array((isset($data['lead_temperature']) ? $data['lead_temperature'] : ''),['cold','warm','hot']) ? $data['lead_temperature'] : 'warm',
        'work_started'         => intval((isset($data['work_started']) ? $data['work_started'] : 0)),
        'next_action_type'     => sanitize_text_field((isset($data['next_action_type']) ? $data['next_action_type'] : '')),
        'remarks'              => sanitize_textarea_field((isset($data['remarks']) ? $data['remarks'] : '')),
        'final_remarks'        => sanitize_textarea_field((isset($data['final_remarks']) ? $data['final_remarks'] : '')),
        'vendor_id'            => intval((isset($data['vendor_id']) ? $data['vendor_id'] : 0)) ?: null,
        'total_quote'          => (float)((isset($data['total_quote']) ? $data['total_quote'] : 0)),
        'pmt_received'         => (float)((isset($data['pmt_received']) ? $data['pmt_received'] : 0)),
        'vendor_charge'        => (float)((isset($data['vendor_charge']) ? $data['vendor_charge'] : 0)),
        'pmt_to_vendor'        => (float)((isset($data['pmt_to_vendor']) ? $data['pmt_to_vendor'] : 0)),
    ];
    // Only include assigned_to when column exists — checked against live DB schema
    if ( lcrm_col_exists('assigned_to') ) {
        $row['assigned_to'] = intval((isset($data['assigned_to']) ? $data['assigned_to'] : 0)) ?: null;
    }
    return $row;
}

/* ── Assignment helpers ─────────────────────────────────── */
function lcrm_log_assignment($lead_id, $action, $from_user_id, $to_user_id, $note = '') {
    global $wpdb;
    $t = $wpdb->prefix . 'lcrm_lead_assignments';
    if ( $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t ) return;
    $wpdb->insert($t, [
        'lead_id'      => intval($lead_id),
        'action'       => $action,
        'from_user_id' => $from_user_id ? intval($from_user_id) : null,
        'to_user_id'   => $to_user_id   ? intval($to_user_id)   : null,
        'done_by'      => get_current_user_id(),
        'note'         => sanitize_text_field($note),
        'created_at'   => current_time('mysql'),
    ]);
}

function lcrm_get_assignment_history($lead_id) {
    global $wpdb;
    $t = $wpdb->prefix . 'lcrm_lead_assignments';
    if ( $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t ) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT h.*, 
                fu.display_name AS from_name, 
                tu.display_name AS to_name,
                du.display_name AS done_by_name
         FROM {$t} h
         LEFT JOIN {$wpdb->users} fu ON fu.ID=h.from_user_id
         LEFT JOIN {$wpdb->users} tu ON tu.ID=h.to_user_id
         LEFT JOIN {$wpdb->users} du ON du.ID=h.done_by
         WHERE h.lead_id=%d ORDER BY h.created_at DESC",
        intval($lead_id)
    ));
    return (array)$rows;
}

/*  Lookup helpers  */
function lcrm_get_stages() {
    static $c=null; if($c!==null) return $c;
    $c=get_transient('lcrm_cache_stages'); if($c!==false) return $c;
    global $wpdb; $c=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_stages WHERE is_active=1 ORDER BY sort_order ASC");
    set_transient('lcrm_cache_stages',$c,600); return $c;
}
function lcrm_get_stage($id)    { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lcrm_stages WHERE id=%d",$id)); }
function lcrm_get_vendors() {
    static $c=null; if($c!==null) return $c;
    $c=get_transient('lcrm_cache_vendors'); if($c!==false) return $c;
    global $wpdb; $c=$wpdb->get_results("SELECT v.*,vc.name AS category_name FROM {$wpdb->prefix}lcrm_vendors v LEFT JOIN {$wpdb->prefix}lcrm_vendor_categories vc ON vc.id=v.category_id WHERE v.is_active=1 ORDER BY v.name ASC");
    set_transient('lcrm_cache_vendors',$c,600); return $c;
}
function lcrm_get_vendor($id)   { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT v.*,vc.name AS category_name FROM {$wpdb->prefix}lcrm_vendors v LEFT JOIN {$wpdb->prefix}lcrm_vendor_categories vc ON vc.id=v.category_id WHERE v.id=%d",$id)); }
function lcrm_get_brands() {
    static $c=null; if($c!==null) return $c;
    $c=get_transient('lcrm_cache_brands'); if($c!==false) return $c;
    global $wpdb; $c=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_brands WHERE is_active=1 ORDER BY name ASC");
    set_transient('lcrm_cache_brands',$c,600); return $c;
}
function lcrm_get_vendor_cats() {
    static $c=null; if($c!==null) return $c;
    $c=get_transient('lcrm_cache_vendor_cats'); if($c!==false) return $c;
    global $wpdb; $c=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_vendor_categories ORDER BY name ASC");
    set_transient('lcrm_cache_vendor_cats',$c,600); return $c;
}
function lcrm_get_work_types() {
    static $c=null; if($c!==null) return $c;
    $c=get_transient('lcrm_cache_work_types'); if($c!==false) return $c;
    global $wpdb; $c=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_work_types WHERE is_active=1 ORDER BY sort_order ASC");
    set_transient('lcrm_cache_work_types',$c,600); return $c;
}
function lcrm_get_lead_sources() {
    static $c=null; if($c!==null) return $c;
    $c=get_transient('lcrm_cache_lead_sources'); if($c!==false) return $c;
    global $wpdb; $c=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_lead_sources WHERE is_active=1 ORDER BY sort_order ASC");
    set_transient('lcrm_cache_lead_sources',$c,600); return $c;
}
function lcrm_get_remarks_grouped() {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    $cache = get_transient('lcrm_cache_remarks');
    if ( $cache !== false ) return $cache;
    global $wpdb;
    $table = $wpdb->prefix . 'lcrm_remarks';
    // Ensure table exists — create if missing
    if ( $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) ) !== $table ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE IF NOT EXISTS {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, category VARCHAR(100) NOT NULL DEFAULT 'General', remark TEXT NOT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), KEY category (category)) $c;");
    }
    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE is_active=1 ORDER BY category,sort_order ASC");
    // Seed if empty
    if ( empty($rows) && function_exists('lcrm_seed_full_remarks') ) {
        lcrm_seed_full_remarks();
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE is_active=1 ORDER BY category,sort_order ASC");
    }
    $out = [];
    foreach ( (array)$rows as $r ) $out[$r->category][] = $r;
    $cache = $out;
    set_transient('lcrm_cache_remarks', $out, 600);
    return $out;
}
function lcrm_get_all_remarks() { global $wpdb; return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_remarks ORDER BY category,sort_order ASC"); }

/* ── Column existence check (cached, with auto-migrate) ─── */
function lcrm_col_exists($col) {
    // Use the option set by admin_init hook — avoids SHOW COLUMNS on every AJAX request
    // If option not yet set (first load), fall back to live check
    static $cache = [];
    if (isset($cache[$col])) return $cache[$col];
    if ($col === 'assigned_to' && get_option('lcrm_cols_v34', 0)) {
        return ($cache[$col] = true);
    }
    global $wpdb;
    $wpdb->hide_errors();
    $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lcrm_leads", 0);
    $wpdb->show_errors();
    return ($cache[$col] = in_array($col, $cols, true));
}

/* ── Conversations table check (cached) ────────────────── */
function lcrm_conv_table_exists() {
    static $e = null;
    if ($e !== null) return $e;
    // Fast path: if migration ran, table definitely exists
    if ( get_option('lcrm_cols_v34', 0) ) return ($e = true);
    global $wpdb;
    $t = $wpdb->prefix . 'lcrm_conversations';
    $e = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t);
    return $e;
}

/* ── Conversation CRUD ──────────────────────────────────── */
function lcrm_add_conversation($lead_id, $message) {
    if (!lcrm_conv_table_exists()) return 0;
    global $wpdb;
    $u = wp_get_current_user();
    $role = current_user_can('manage_options') ? 'Admin' : (current_user_can('lcrm_manager') ? 'Manager' : (current_user_can('lcrm_agent') ? 'Agent' : 'Staff'));
    $wpdb->insert($wpdb->prefix.'lcrm_conversations', [
        'lead_id'   => intval($lead_id),
        'user_id'   => $u->ID,
        'user_name' => sanitize_text_field($u->ID ? $u->display_name : 'System'),
        'user_role' => $role,
        'direction' => 'staff',
        'message'   => sanitize_textarea_field($message),
        'created_at'=> current_time('mysql'),
    ]);
    return $wpdb->insert_id;
}
function lcrm_get_conversations($lead_id) {
    if (!lcrm_conv_table_exists()) return [];
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lcrm_conversations WHERE lead_id=%d ORDER BY created_at ASC", intval($lead_id)));
    return (array)$rows;
}

/* ── Conversation summary for leads table (no prepare — ints only) */
function lcrm_get_conv_summaries(array $ids) {
    if (empty($ids) || !lcrm_conv_table_exists()) return [];
    global $wpdb;
    $ct  = $wpdb->prefix . 'lcrm_conversations';
    $in  = implode(',', array_map('intval', $ids));
    // SUBSTR avoids % signs inside GROUP_CONCAT which break $wpdb->prepare
    $sql = "SELECT c.lead_id,
                   GROUP_CONCAT(CONCAT('[',SUBSTR(c.created_at,12,5),' ',c.user_name,'] ',c.message) ORDER BY c.id ASC SEPARATOR ' | ') AS last_day_conv,
                   DATE(c.created_at) AS last_conv_date
            FROM {$ct} c
            INNER JOIN (SELECT lead_id,MAX(DATE(created_at)) AS md FROM {$ct} WHERE lead_id IN ({$in}) GROUP BY lead_id) d
                    ON d.lead_id=c.lead_id AND DATE(c.created_at)=d.md
            WHERE c.lead_id IN ({$in})
            GROUP BY c.lead_id";
    $map = [];
    foreach ((array)$wpdb->get_results($sql) as $r) {
        $map[intval($r->lead_id)] = ['last_day_conv'=>$r->last_day_conv,'last_conv_date'=>$r->last_conv_date];
    }
    return $map;
}

/* ── User helpers ───────────────────────────────────────── */
function lcrm_get_crm_users() {
    static $u = null;
    if ($u !== null) return $u;
    $u = get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name']]);
    return $u;
}
function lcrm_can_see_all_leads() {
    return current_user_can('manage_options') || current_user_can('lcrm_view_all_leads');
}
function lcrm_can_assign_leads() {
    return current_user_can('manage_options') || current_user_can('lcrm_assign_leads');
}
/**
 * Confidential finance: vendor_charge, pmt_to_vendor, profit.
 * ADMIN ONLY — these expose business margin.
 */
function lcrm_can_see_confidential_finance() {
    return current_user_can('manage_options');
}
/**
 * Client finance: total_quote, pmt_received, balance_payment.
 * Staff CAN see these — they need to chase client payments.
 */
function lcrm_can_see_client_finance() {
    return true; // any logged-in CRM user
}
/**
 * Revenue dashboard: aggregate totals (total revenue, profit, vendor spend).
 * ADMIN ONLY — reveals overall business performance.
 */
function lcrm_can_see_revenue_dashboard() {
    return current_user_can('manage_options');
}

/* ── Tags helpers ───────────────────────────────────────── */
function lcrm_get_all_tags() {
    global $wpdb;
    return (array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_tags ORDER BY name ASC");
}
function lcrm_get_lead_tags($lead_id) {
    global $wpdb;
    return (array)$wpdb->get_results($wpdb->prepare(
        "SELECT t.* FROM {$wpdb->prefix}lcrm_tags t
         INNER JOIN {$wpdb->prefix}lcrm_lead_tags lt ON lt.tag_id=t.id
         WHERE lt.lead_id=%d ORDER BY t.name ASC", intval($lead_id)
    ));
}
function lcrm_set_lead_tags($lead_id, array $tag_ids) {
    global $wpdb;
    $lid = intval($lead_id);
    $wpdb->delete("{$wpdb->prefix}lcrm_lead_tags", ['lead_id'=>$lid]);
    foreach (array_unique(array_map('intval', $tag_ids)) as $tid) {
        if ($tid) $wpdb->replace("{$wpdb->prefix}lcrm_lead_tags", ['lead_id'=>$lid,'tag_id'=>$tid]);
    }
}

/* ── Detailed activity log ──────────────────────────────── */
function lcrm_log_field_change($lead_id, $field_label, $old_val, $new_val, $user_name = '') {
    if ($old_val === $new_val) return;
    global $wpdb;
    if (!$user_name) {
        $u = wp_get_current_user();
        $user_name = $u->display_name ?: 'System';
    }
    $old_disp = ($old_val === '' || $old_val === null) ? '(empty)' : $old_val;
    $new_disp = ($new_val === '' || $new_val === null) ? '(empty)' : $new_val;
    $msg = "{$field_label}: \"{$old_disp}\" → \"{$new_disp}\"";
    $wpdb->insert("{$wpdb->prefix}lcrm_activity", [
        'lead_id'    => intval($lead_id),
        'user_id'    => get_current_user_id(),
        'type'       => 'field_change',
        'message'    => sanitize_textarea_field($msg),
        'created_at' => current_time('mysql'),
    ]);
}

/*  Activity Log  */
function lcrm_log_activity($lead_id,$type,$message) {
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}lcrm_activity",['lead_id'=>intval($lead_id),'user_id'=>get_current_user_id(),'type'=>sanitize_text_field($type),'message'=>sanitize_textarea_field($message),'created_at'=>current_time('mysql')]);
}
function lcrm_get_activity($lead_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT a.*,u.display_name AS user_name FROM {$wpdb->prefix}lcrm_activity a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE a.lead_id=%d ORDER BY a.created_at DESC LIMIT 100",$lead_id));
}

/*  Vendor Logs  */
function lcrm_get_vendor_logs($lead_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT vl.*,v.name AS vendor_name FROM {$wpdb->prefix}lcrm_vendor_logs vl LEFT JOIN {$wpdb->prefix}lcrm_vendors v ON v.id=vl.vendor_id WHERE vl.lead_id=%d ORDER BY vl.created_at DESC",$lead_id));
}

/*  Dashboard Stats  */
function lcrm_get_dashboard_stats() {
    // 2-minute transient cache — dashboard loads fast, data is fresh enough
    $cache_key = 'lcrm_dash_stats_' . get_current_user_id();
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    global $wpdb;
    $today    = date('Y-m-d');
    $month    = date('Y-m-01');
    $last_mon = date('Y-m-01', strtotime('-1 month'));
    $last_mon_end = date('Y-m-t', strtotime('-1 month'));

    $stats = [
        'total_leads'       => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads"),
        'today_leads'       => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date=%s",$today)),
        'month_leads'       => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date>=%s",$month)),
        'last_month_leads'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date BETWEEN %s AND %s",$last_mon,$last_mon_end)),
        'follow_up_today'   => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE next_action_date=%s",$today)),
        'client_fu_today'   => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE client_followup_date=%s",$today)),
        'vendor_fu_today'   => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE vendor_followup_date=%s",$today)),
        'hot_leads'         => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_temperature='hot'"),
        'payment_no_work'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE pmt_received>0 AND work_started=0"),
        'high_priority'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE priority='high'"),
        // Admin-only financials
        'total_revenue'     => (float)$wpdb->get_var("SELECT SUM(pmt_received) FROM {$wpdb->prefix}lcrm_leads"),
        'month_revenue'     => (float)$wpdb->get_var($wpdb->prepare("SELECT SUM(pmt_received) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date>=%s",$month)),
        'last_month_revenue'=> (float)$wpdb->get_var($wpdb->prepare("SELECT SUM(pmt_received) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date BETWEEN %s AND %s",$last_mon,$last_mon_end)),
        'total_quote'       => (float)$wpdb->get_var("SELECT SUM(total_quote) FROM {$wpdb->prefix}lcrm_leads"),
        'total_profit'      => (float)$wpdb->get_var("SELECT SUM(profit) FROM {$wpdb->prefix}lcrm_leads"),
        'month_profit'      => (float)$wpdb->get_var($wpdb->prepare("SELECT SUM(profit) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date>=%s",$month)),
        'pending_balance'   => (float)$wpdb->get_var("SELECT SUM(balance_payment) FROM {$wpdb->prefix}lcrm_leads WHERE balance_payment>0"),
        // Pipeline
        'by_stage'          => $wpdb->get_results("SELECT s.name,s.color,COUNT(l.id) AS cnt,SUM(l.total_quote) AS stage_quote,SUM(l.pmt_received) AS stage_revenue FROM {$wpdb->prefix}lcrm_stages s LEFT JOIN {$wpdb->prefix}lcrm_leads l ON l.stage_id=s.id GROUP BY s.id ORDER BY s.sort_order ASC"),
        // Revenue by source (top 5)
        'by_source'         => $wpdb->get_results("SELECT lead_source, COUNT(*) AS cnt, SUM(pmt_received) AS revenue FROM {$wpdb->prefix}lcrm_leads WHERE lead_source!='' GROUP BY lead_source ORDER BY revenue DESC LIMIT 5"),
        // Staff performance (for admin)
        'by_staff'          => [],
        // Conversion: leads with pmt_received > 0 / total
        'conversion_rate'   => 0,
    ];

    // Conversion rate
    $converted = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE pmt_received>0");
    $stats['conversion_rate'] = $stats['total_leads'] > 0 ? round($converted / $stats['total_leads'] * 100, 1) : 0;

    // Staff performance (only if assigned_to column exists)
    if (lcrm_col_exists('assigned_to')) {
        $stats['by_staff'] = $wpdb->get_results(
            "SELECT u.display_name AS name, COUNT(l.id) AS total_leads,
                    SUM(CASE WHEN l.pmt_received>0 THEN 1 ELSE 0 END) AS converted,
                    SUM(l.pmt_received) AS revenue
             FROM {$wpdb->prefix}lcrm_leads l
             LEFT JOIN {$wpdb->users} u ON u.ID=l.assigned_to
             WHERE l.assigned_to IS NOT NULL AND COALESCE(l.is_access_revoked,0)=0
             GROUP BY l.assigned_to
             ORDER BY revenue DESC LIMIT 10"
        );
    }

    set_transient($cache_key, $stats, 120); // 2 min
    return $stats;
}

/*  CSV Export  */
function lcrm_export_csv($args=[]) {
    $leads = lcrm_get_leads(array_merge($args,['limit'=>9999,'offset'=>0]));
    $canConf = lcrm_can_see_confidential_finance();
    $cols  = ['ID','Date','Client','Phone','Email','Work Type','Source','Stage','Brand','Priority','Temperature','Work Started','Client Follow-up','Vendor Follow-up','Vendor','Quote','PMT Received','Balance'];
    if ($canConf) $cols = array_merge($cols, ['Vendor Charge','PMT to Vendor','Profit']);
    $cols = array_merge($cols, ['Remarks','Final Remarks','Created At']);
    $rows  = [$cols];
    foreach ((array)$leads as $l) {
        $row = [$l->id,$l->lead_date,$l->client_name,$l->client_phone,$l->client_email,$l->work_type,$l->lead_source,$l->stage_name,$l->brand_name,$l->priority,$l->lead_temperature,$l->work_started,$l->client_followup_date,$l->vendor_followup_date,$l->vendor_name,$l->total_quote,$l->pmt_received,$l->balance_payment];
        if ($canConf) $row = array_merge($row, [$l->vendor_charge,$l->pmt_to_vendor,$l->profit]);
        $row = array_merge($row, [$l->remarks,$l->final_remarks,$l->created_at]);
        $rows[] = $row;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-'.date('Y-m-d').'.csv"');
    $f=fopen('php://output','w');
    foreach((array)$rows as $row) fputcsv($f,$row);
    fclose($f); exit;
}

function lcrm_import_csv($file_path) {
    if(!file_exists($file_path)) return new WP_Error('file','File not found.');
    $handle=fopen($file_path,'r');
    $headers=fgetcsv($handle);
    if(!$headers) return new WP_Error('csv','Invalid CSV.');
    $headers=array_map('strtolower',array_map('trim',$headers));
    $imported=0;$errors=[];
    while(($row=fgetcsv($handle))!==false) {
        $data=array_combine($headers,$row);
        $lead=['lead_date'=>(isset($data['date']) ? $data['date'] : date('Y-m-d')),'client_name'=>(isset($data['client name']) ? $data['client name'] : (isset($data['name']) ? $data['name'] : '')),'client_phone'=>(isset($data['phone']) ? $data['phone'] : ''),'client_email'=>(isset($data['email']) ? $data['email'] : ''),'work_type'=>(isset($data['work type']) ? $data['work type'] : ''),'lead_source'=>(isset($data['lead source']) ? $data['lead source'] : ''),'remarks'=>(isset($data['remarks']) ? $data['remarks'] : ''),'stage_id'=>1,'priority'=>(isset($data['priority']) ? $data['priority'] : 'medium')];
        if(empty($lead['client_name'])){$errors[]='Row skipped: missing client name.';continue;}
        $r=lcrm_create_lead($lead);
        if(is_wp_error($r))$errors[]=$r->get_error_message();
        else $imported++;
    }
    fclose($handle);
    return ['imported'=>$imported,'errors'=>$errors];
}

/*  Formatting  */
function lcrm_currency($val)     { return 'Rs.'.number_format((float)$val,2); }
function lcrm_date_display($d)   { return $d?date('d M Y',strtotime($d)):'-'; }
function lcrm_temperature_badge($t){
    $map=['hot'=>' Hot','warm'=>' Warm','cold'=>' Cold'];
    $cls=['hot'=>'lcrm-badge-red','warm'=>'lcrm-badge-amber','cold'=>'lcrm-badge-gray'];
    return '<span class="lcrm-badge '.(isset($cls[$t]) ? $cls[$t] : 'lcrm-badge-gray').'">'.( isset($map[$t]) ? $map[$t] : $t ).'</span>';
}
function lcrm_priority_badge($p){
    $map=['high'=>' High','medium'=>' Medium','low'=>' Low'];
    $cls=['high'=>'lcrm-badge-red','medium'=>'lcrm-badge-amber','low'=>'lcrm-badge-gray'];
    return '<span class="lcrm-badge '.(isset($cls[$p]) ? $cls[$p] : '').'">'.( isset($map[$p]) ? $map[$p] : $p ).'</span>';
}

/* ── Custom field helpers ── */
function lcrm_extract_custom_fields($data) {
    global $wpdb;
    $fields = $wpdb->get_results("SELECT field_key FROM {$wpdb->prefix}lcrm_fields WHERE is_active=1");
    $values = [];
    foreach ($fields as $f) {
        $key = $f->field_key;
        if (isset($data[$key])) {
            $values[$key] = sanitize_textarea_field($data[$key]);
        }
    }
    return $values;
}

function lcrm_get_active_fields($tab = '') {
    global $wpdb;
    if ($tab) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lcrm_fields WHERE is_active=1 AND tab_name=%s ORDER BY sort_order ASC, id ASC", $tab
        ));
    }
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_fields WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
}

function lcrm_get_list_fields() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}lcrm_fields WHERE is_active=1 AND show_in_list=1 ORDER BY sort_order ASC, id ASC"
    );
}

/* =========================================================
   DYNAMIC FORM LAYOUT ENGINE
   All built-in fields defined here so they can be
   dragged/reordered in the layout editor just like custom fields.
   ========================================================= */

function lcrm_get_builtin_field_defs() {
    return [
        'lead_date'            => ['label'=>'Date',               'type'=>'date',         'required'=>true,  'default_tab'=>'basic',     'default_order'=>10,  'placeholder'=>''],
        'client_name'          => ['label'=>'Client Name',        'type'=>'text',         'required'=>true,  'default_tab'=>'basic',     'default_order'=>20,  'placeholder'=>'Full name'],
        'client_phone'         => ['label'=>'Phone',              'type'=>'text',         'required'=>false, 'default_tab'=>'basic',     'default_order'=>30,  'placeholder'=>'+91 9876543210'],
        'client_email'         => ['label'=>'Email',              'type'=>'email',        'required'=>false, 'default_tab'=>'basic',     'default_order'=>40,  'placeholder'=>''],
        'work_type'            => ['label'=>'Work Type',          'type'=>'wt_select',    'required'=>false, 'default_tab'=>'basic',     'default_order'=>50,  'placeholder'=>''],
        'lead_source'          => ['label'=>'Lead Source',        'type'=>'src_select',   'required'=>false, 'default_tab'=>'basic',     'default_order'=>60,  'placeholder'=>''],
        'brand_id'             => ['label'=>'Brand',              'type'=>'brand_select', 'required'=>false, 'default_tab'=>'basic',     'default_order'=>70,  'placeholder'=>''],
        'stage_id'             => ['label'=>'Pipeline Stage',     'type'=>'stage_select', 'required'=>false, 'default_tab'=>'basic',     'default_order'=>80,  'placeholder'=>''],
        'priority'             => ['label'=>'Priority',           'type'=>'priority_sel', 'required'=>false, 'default_tab'=>'basic',     'default_order'=>90,  'placeholder'=>''],
        'lead_temperature'     => ['label'=>'Temperature',        'type'=>'temp_sel',     'required'=>false, 'default_tab'=>'basic',     'default_order'=>100, 'placeholder'=>''],
        'internal_status'      => ['label'=>'Internal Status',    'type'=>'text',         'required'=>false, 'default_tab'=>'basic',     'default_order'=>110, 'placeholder'=>'Detailed status'],
        'work_started'         => ['label'=>'Work Started?',      'type'=>'checkbox',     'required'=>false, 'default_tab'=>'basic',     'default_order'=>120, 'placeholder'=>''],
        'next_action_date'     => ['label'=>'Next Action Date',   'type'=>'date',         'required'=>false, 'default_tab'=>'follow',    'default_order'=>10,  'placeholder'=>''],
        'next_action_type'     => ['label'=>'Next Action Type',   'type'=>'action_sel',   'required'=>false, 'default_tab'=>'follow',    'default_order'=>20,  'placeholder'=>''],
        'client_followup_date' => ['label'=>'Client Follow-up',   'type'=>'date',         'required'=>false, 'default_tab'=>'follow',    'default_order'=>30,  'placeholder'=>''],
        'vendor_followup_date' => ['label'=>'Vendor Follow-up',   'type'=>'date',         'required'=>false, 'default_tab'=>'follow',    'default_order'=>40,  'placeholder'=>''],
        'total_quote'          => ['label'=>'Total Quote (Rs.)',   'type'=>'money',        'required'=>false, 'default_tab'=>'financial', 'default_order'=>10,  'placeholder'=>'', 'gate'=>'financial_client'],
        'pmt_received'         => ['label'=>'PMT Received (Rs.)', 'type'=>'money',        'required'=>false, 'default_tab'=>'financial', 'default_order'=>20,  'placeholder'=>'', 'gate'=>'financial_client'],
        'balance_payment'      => ['label'=>'Balance (Rs.)',      'type'=>'calc',         'required'=>false, 'default_tab'=>'financial', 'default_order'=>30,  'placeholder'=>'', 'gate'=>'financial_client'],
        'vendor_charge'        => ['label'=>'Vendor Charge (Rs.)','type'=>'money',        'required'=>false, 'default_tab'=>'financial', 'default_order'=>40,  'placeholder'=>'', 'gate'=>'financial_conf'],
        'pmt_to_vendor'        => ['label'=>'PMT to Vendor (Rs.)','type'=>'money',        'required'=>false, 'default_tab'=>'financial', 'default_order'=>50,  'placeholder'=>'', 'gate'=>'financial_conf'],
        'profit'               => ['label'=>'Profit (Rs.)',       'type'=>'calc',         'required'=>false, 'default_tab'=>'financial', 'default_order'=>60,  'placeholder'=>'', 'gate'=>'financial_conf'],
        'vendor_id'            => ['label'=>'Assigned Vendor',    'type'=>'vendor_sel',   'required'=>false, 'default_tab'=>'vendor',    'default_order'=>10,  'placeholder'=>'', 'gate'=>'vendor'],
        'remarks'              => ['label'=>'Remarks',            'type'=>'remarks_ta',   'required'=>false, 'default_tab'=>'remarks',   'default_order'=>10,  'placeholder'=>''],
        'final_remarks'        => ['label'=>'Final Remarks',      'type'=>'textarea',     'required'=>false, 'default_tab'=>'remarks',   'default_order'=>20,  'placeholder'=>'Final notes or closure remarks...'],
    ];
}

function lcrm_get_form_layout() {
    $saved = get_option('lcrm_form_layout', '');
    if (!$saved) return [];
    $decoded = json_decode($saved, true);
    return is_array($decoded) ? $decoded : [];
}

function lcrm_build_form_fields_by_tab() {
    global $wpdb;
    $layout   = lcrm_get_form_layout();
    $valid_tabs = ['basic','follow','financial','vendor','remarks'];
    $tabs     = array_fill_keys($valid_tabs, []);

    // Built-in fields
    foreach (lcrm_get_builtin_field_defs() as $key => $def) {
        // remarks & final_remarks are replaced by the Conversation panel — exclude from grid
        if ( in_array($key, ['remarks','final_remarks'], true) ) continue;
        $ov    = isset($layout[$key]) ? $layout[$key] : [];
        $tab   = (isset($ov['tab']) && in_array($ov['tab'], $valid_tabs)) ? $ov['tab'] : $def['default_tab'];
        $order = isset($ov['order']) ? (int)$ov['order'] : $def['default_order'];
        $tabs[$tab][] = array_merge($def, ['key'=>$key,'tab'=>$tab,'order'=>$order,'source'=>'builtin']);
    }

    // Custom fields
    $custom = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_fields WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
    foreach ($custom as $i => $cf) {
        $key   = $cf->field_key;
        $ov    = isset($layout[$key]) ? $layout[$key] : [];
        $def_tab   = (isset($cf->tab_name) && in_array($cf->tab_name, $valid_tabs)) ? $cf->tab_name : 'basic';
        $def_order = isset($cf->sort_order) ? (int)$cf->sort_order : (1000 + $i * 10);
        $tab   = (isset($ov['tab']) && in_array($ov['tab'], $valid_tabs)) ? $ov['tab'] : $def_tab;
        $order = isset($ov['order']) ? (int)$ov['order'] : $def_order;
        $tabs[$tab][] = [
            'key'           => $key,
            'label'         => $cf->field_label,
            'type'          => 'cf_'.$cf->field_type,
            'required'      => (bool)$cf->is_required,
            'default_tab'   => $def_tab,
            'default_order' => $def_order,
            'tab'           => $tab,
            'order'         => $order,
            'source'        => 'custom',
            'cf_data'       => $cf,
            'placeholder'   => '',
        ];
    }

    foreach ($tabs as &$fields) {
        usort($fields, function($a,$b){ return $a['order'] - $b['order']; });
    }
    return $tabs;
}

function lcrm_render_form_field_html($field, $render_data) {
    $key   = $field['key'];
    $type  = $field['type'];
    $label = $field['label'];
    $req   = !empty($field['required']);
    $ph    = isset($field['placeholder']) ? esc_attr($field['placeholder']) : '';
    $ra    = $req ? ' required' : '';
    $rs    = $req ? ' <span class="req">*</span>' : '';
    $id    = 'lf-'.esc_attr($key);
    $name  = esc_attr($key);

    // Permission gates
    if (!empty($field['gate'])) {
        if ($field['gate']==='financial_conf'   && !lcrm_can_see_confidential_finance()) return '';
        if ($field['gate']==='financial_client' && !lcrm_can_see_client_finance()) return '';
        if ($field['gate']==='financial' && !lcrm_can_see_confidential_finance()) return ''; // legacy fallback
        if ($field['gate']==='vendor'    && function_exists('lcrm_can_see_vendor')    && !lcrm_can_see_vendor())    return '';
        if ($field['gate']==='assign'    && function_exists('lcrm_can_assign_leads')  && !lcrm_can_assign_leads())  return '';
    }

    $wrap_open  = '<div class="lcrm-form-group">';
    $wrap_close = '</div>';
    $lbl        = '<label>'.esc_html($label).$rs.'</label>';

    switch ($type) {
        case 'text':
        case 'email':
            $t = ($type==='email')?'email':'text';
            return $wrap_open.$lbl.'<input type="'.$t.'" name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"'.($ph?' placeholder="'.$ph.'"':'').$ra.'>'.$wrap_close;
        case 'date':
            return $wrap_open.$lbl.'<input type="date" name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"'.$ra.'>'.$wrap_close;
        case 'money':
            return $wrap_open.$lbl.'<input type="number" step="0.01" name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full lcrm-calc" placeholder="0.00">'.$wrap_close;
        case 'calc':
            return '<div class="lcrm-form-group lcrm-readonly"><label>'.esc_html($label).' <span class="lcrm-auto">auto</span></label><input type="text" id="'.$id.'" class="lcrm-input lcrm-input-full" readonly placeholder="0.00"></div>';
        case 'checkbox':
            return $wrap_open.$lbl.'<div class="lcrm-toggle-wrap"><input type="checkbox" name="'.$name.'" id="'.$id.'" value="1" class="lcrm-toggle"><label for="'.$id.'" class="lcrm-toggle-lbl">'.esc_html($label).'</label></div>'.$wrap_close;
        case 'wt_select':
            $opts = '<option value="">- Select -</option>';
            foreach ($render_data['wt'] as $w) $opts .= '<option value="'.esc_attr($w->name).'">'.esc_html($w->name).'</option>';
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full">'.$opts.'</select>'.$wrap_close;
        case 'src_select':
            $opts = '<option value="">- Select -</option>';
            foreach ($render_data['sources'] as $s) $opts .= '<option value="'.esc_attr($s->name).'">'.esc_html($s->name).'</option>';
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full">'.$opts.'</select>'.$wrap_close;
        case 'brand_select':
            $opts = '<option value="">- No Brand -</option>';
            foreach ($render_data['brands'] as $b) $opts .= '<option value="'.esc_attr($b->id).'">'.esc_html($b->name).'</option>';
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full">'.$opts.'</select>'.$wrap_close;
        case 'stage_select':
            $opts = '';
            foreach ($render_data['stages'] as $s) $opts .= '<option value="'.esc_attr($s->id).'">'.esc_html($s->name).'</option>';
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full">'.$opts.'</select>'.$wrap_close;
        case 'priority_sel':
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select>'.$wrap_close;
        case 'temp_sel':
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"><option value="cold">&#10052; Cold</option><option value="warm" selected>&#9728; Warm</option><option value="hot">&#128293; Hot</option></select>'.$wrap_close;
        case 'action_sel':
            $opts = '<option value="">- Select -</option>';
            foreach (['Call','WhatsApp','Email','Meeting','Follow-up','Send Docs'] as $o) $opts .= '<option value="'.esc_attr($o).'">'.esc_html($o).'</option>';
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full">'.$opts.'</select>'.$wrap_close;
        case 'vendor_sel':
            $opts = '<option value="">- Unassigned -</option>';
            foreach ($render_data['vendors'] as $v) $opts .= '<option value="'.esc_attr($v->id).'">'.esc_html($v->name).' ('.esc_html(isset($v->category_name)?$v->category_name:'').')</option>';
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full">'.$opts.'</select>'.$wrap_close;
        case 'remarks_ta':
            // Full-width with remark pills above
            $html = '<div class="lcrm-form-group" style="grid-column:1/-1;">'.$lbl;
            if (!empty($render_data['remarks'])) {
                $html .= '<div class="lcrm-remarks-pills">';
                foreach ($render_data['remarks'] as $cat => $items) {
                    $html .= '<div class="lcrm-remark-cat"><div class="lcrm-remark-cat-label">'.esc_html($cat).'</div><div class="lcrm-remark-pills-row">';
                    foreach ($items as $r) $html .= '<button type="button" class="lcrm-remark-pill" data-text="'.esc_attr($r->remark).'">'.esc_html($r->remark).'</button>';
                    $html .= '</div></div>';
                }
                $html .= '</div>';
            }
            $html .= '<textarea name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full lcrm-textarea" rows="4" placeholder="Click a remark above or type..."></textarea></div>';
            return $html;
        case 'textarea':
            return $wrap_open.$lbl.'<textarea name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full lcrm-textarea" rows="2"'.($ph?' placeholder="'.$ph.'"':'').$ra.'></textarea>'.$wrap_close;
        // Custom field types
        case 'cf_text':
            return $wrap_open.$lbl.'<input type="text" name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"'.$ra.'>'.$wrap_close;
        case 'cf_textarea':
            return $wrap_open.$lbl.'<textarea name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full lcrm-textarea" rows="2"'.$ra.'></textarea>'.$wrap_close;
        case 'cf_number':
            return $wrap_open.$lbl.'<input type="number" name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"'.$ra.'>'.$wrap_close;
        case 'cf_date':
            return $wrap_open.$lbl.'<input type="date" name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"'.$ra.'>'.$wrap_close;
        case 'cf_checkbox':
            return $wrap_open.$lbl.'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;"><input type="checkbox" name="'.$name.'" id="'.$id.'" value="1"> Yes</label>'.$wrap_close;
        case 'cf_select':
            $cf_data = isset($field['cf_data']) ? $field['cf_data'] : null;
            $opts = '<option value="">— Select —</option>';
            if ($cf_data && !empty($cf_data->field_opts)) {
                foreach (array_filter(array_map('trim', explode(',', $cf_data->field_opts))) as $o)
                    $opts .= '<option value="'.esc_attr($o).'">'.esc_html($o).'</option>';
            }
            return $wrap_open.$lbl.'<select name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"'.$ra.'>'.$opts.'</select>'.$wrap_close;
        default:
            return $wrap_open.$lbl.'<input type="text" name="'.$name.'" id="'.$id.'" class="lcrm-input lcrm-input-full"'.$ra.'>'.$wrap_close;
    }
}
