<?php
if (!defined('ABSPATH')) exit;

function lcrm_verify() {
    if (!check_ajax_referer('lcrm_nonce','nonce',false)) wp_send_json_error(['msg'=>'Security check failed.'],403);
    if (!is_user_logged_in()) wp_send_json_error(['msg'=>'Not logged in.'],401);
}
function lcrm_verify_admin() { lcrm_verify(); if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Permission denied.'],403); }

// Bust all dropdown transient caches — called after saving any lookup table
function lcrm_bust_dropdown_cache() {
    delete_transient('lcrm_cache_stages');
    delete_transient('lcrm_cache_vendors');
    delete_transient('lcrm_cache_brands');
    delete_transient('lcrm_cache_vendor_cats');
    delete_transient('lcrm_cache_work_types');
    delete_transient('lcrm_cache_lead_sources');
    delete_transient('lcrm_cache_remarks');
    if ( function_exists('lcrm_reset_lookup_statics') ) {
        lcrm_reset_lookup_statics();
    }
}

/** Clear PHP transients + SPA section HTML caches after CMS lookup changes. */
function lcrm_after_lookup_change() {
    lcrm_bust_dropdown_cache();
    if ( function_exists('lcrm_bust_section_caches') ) {
        lcrm_bust_section_caches();
    }
    do_action('lcrm_lookups_changed');
}

add_action('wp_ajax_lcrm_get_lookups', 'lcrm_ajax_get_lookups');
function lcrm_ajax_get_lookups() {
    lcrm_verify();
    $map = function($rows, $id_key = 'id') {
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = ['id' => (int) $r->$id_key, 'name' => $r->name];
        }
        return $out;
    };
    wp_send_json_success([
        'stages'      => $map(lcrm_get_stages()),
        'work_types'  => $map(lcrm_get_work_types()),
        'sources'     => $map(lcrm_get_lead_sources()),
        'vendors'     => array_map(function ($v) {
            return ['id' => (int) $v->id, 'name' => $v->name, 'category_name' => isset($v->category_name) ? $v->category_name : ''];
        }, (array) lcrm_get_vendors()),
        'brands'      => $map(lcrm_get_brands()),
    ]);
}



/*  LEADS  */
add_action('wp_ajax_lcrm_get_leads','lcrm_ajax_get_leads');
function lcrm_ajax_get_leads() {
    lcrm_verify();
    $page=$limit=50; $page=max(1,intval((isset($_POST['page']) ? $_POST['page'] : 1)));
    $args=['stage_id'=>sanitize_text_field(isset($_POST['stage_id']) ? $_POST['stage_id'] : ''),'vendor_id'=>sanitize_text_field(isset($_POST['vendor_id']) ? $_POST['vendor_id'] : ''),
           'work_type'=>sanitize_text_field(isset($_POST['work_type']) ? $_POST['work_type'] : ''),'search'=>sanitize_text_field(isset($_POST['search']) ? $_POST['search'] : ''),
           'date_from'=>sanitize_text_field(isset($_POST['date_from']) ? $_POST['date_from'] : ''),'date_to'=>sanitize_text_field(isset($_POST['date_to']) ? $_POST['date_to'] : ''),
           'priority'=>sanitize_text_field(isset($_POST['priority']) ? $_POST['priority'] : ''),'temperature'=>sanitize_text_field(isset($_POST['temperature']) ? $_POST['temperature'] : ''),
           'brand_id'=>sanitize_text_field(isset($_POST['brand_id']) ? $_POST['brand_id'] : ''),
           'internal_status'=>sanitize_text_field(isset($_POST['internal_status']) ? $_POST['internal_status'] : ''),
           'work_status'=>sanitize_text_field(isset($_POST['work_status']) ? $_POST['work_status'] : ''),
           'remarks_search'=>sanitize_text_field(isset($_POST['remarks_search']) ? $_POST['remarks_search'] : ''),
           'conv_search'=>sanitize_text_field(isset($_POST['conv_search']) ? $_POST['conv_search'] : ''),
           'tag_id'=>sanitize_text_field(isset($_POST['tag_id']) ? $_POST['tag_id'] : ''),
           'orderby'=>sanitize_text_field(isset($_POST['orderby']) ? $_POST['orderby'] : 'created_at'),'order'=>sanitize_text_field(isset($_POST['order']) ? $_POST['order'] : 'DESC'),
           'limit'=>$limit,'offset'=>($page-1)*$limit];
    // Scope to own leads for non-admins BEFORE querying — avoids double query
    if (!lcrm_can_see_all_leads() && lcrm_col_exists('assigned_to')) {
        $args['user_id'] = get_current_user_id();
    }
    $leads=lcrm_get_leads($args); $total=lcrm_count_leads($args);

    // Conversation summaries (separate query — safe, no % in GROUP_CONCAT)
    $ids     = array_map('intval', wp_list_pluck((array)$leads,'id'));
    $convmap = $ids ? lcrm_get_conv_summaries($ids) : [];

    $out=[];
    foreach((array)$leads as $l){
        $score=lcrm_calculate_lead_score($l);
        $conv = isset($convmap[$l->id]) ? $convmap[$l->id] : ['last_day_conv'=>'','last_conv_date'=>''];
        $canConfFin = lcrm_can_see_confidential_finance();
        $out[]=['id'=>$l->id,'lead_date'=>$l->lead_date,'lead_date_d'=>lcrm_date_display($l->lead_date),
            'client_name'=>$l->client_name,'client_phone'=>$l->client_phone,'client_email'=>$l->client_email,
            'work_type'=>$l->work_type,'work_types'=>lcrm_work_types_decode($l->work_type),'work_type_display'=>lcrm_work_types_display($l->work_type),'lead_source'=>$l->lead_source,'stage_id'=>$l->stage_id,
            'stage_name'=>$l->stage_name,'stage_color'=>$l->stage_color,'internal_status'=>$l->internal_status,'work_status'=>(isset($l->work_status)&&$l->work_status!=='')?$l->work_status:'Inquiry',
            'next_action_date'=>$l->next_action_date,'next_action_d'=>lcrm_date_display($l->next_action_date),
            'client_followup_date'=>$l->client_followup_date,'vendor_followup_date'=>$l->vendor_followup_date,
            'priority'=>$l->priority,'lead_temperature'=>$l->lead_temperature,'work_started'=>$l->work_started,
            'next_action_type'=>$l->next_action_type,'brand_name'=>(isset($l->brand_name) ? $l->brand_name : ''),
            'remarks'=>$l->remarks,'final_remarks'=>$l->final_remarks,'vendor_id'=>$l->vendor_id,
            'vendor_name'=>(isset($l->vendor_name) ? $l->vendor_name : '-'),'vendor_phone'=>(isset($l->vendor_phone) ? $l->vendor_phone : ''),
            // Client-facing financials — all staff can see
            'total_quote'    => number_format((float)$l->total_quote,2,'.',''),
            'pmt_received'   => number_format((float)$l->pmt_received,2,'.',''),
            'balance_payment'=> number_format((float)$l->balance_payment,2,'.',''),
            // Confidential financials — admin only; send 0 to non-admins so JS doesn't show
            'vendor_charge'  => $canConfFin ? number_format((float)$l->vendor_charge,2,'.','') : '',
            'pmt_to_vendor'  => $canConfFin ? number_format((float)$l->pmt_to_vendor,2,'.','') : '',
            'profit'         => $canConfFin ? number_format((float)$l->profit,2,'.','')         : '',
            'lead_score'=>$score,'created_at'=>$l->created_at,'custom_field_values'=>json_decode(!empty($l->custom_fields)?$l->custom_fields:'{}',true)?:[],
            'last_day_conv'       => $conv['last_day_conv'],
            'last_conv_date'      => $conv['last_conv_date'],
            'assigned_to'         => isset($l->assigned_to) ? $l->assigned_to : null,
            'assigned_user_name'  => isset($l->assigned_user_name) ? $l->assigned_user_name : '',
            'created_user_name'   => isset($l->created_user_name)  ? $l->created_user_name  : '',
            'is_access_revoked'   => isset($l->is_access_revoked)  ? intval($l->is_access_revoked) : 0,
        ];
    }
    wp_send_json_success(['leads'=>$out,'total'=>$total,'pages'=>max(1,ceil($total/$limit)),'page'=>$page]);
}

add_action('wp_ajax_lcrm_get_lead','lcrm_ajax_get_lead');
function lcrm_ajax_get_lead() {
    lcrm_verify();
    $id=$intid=intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $lead=lcrm_get_lead($id);
    if(!$lead) wp_send_json_error(['msg'=>'Lead not found.'],404);
    $lead->work_types = lcrm_work_types_decode($lead->work_type);
    $lead->work_type_display = lcrm_work_types_display($lead->work_type);
    $activity=lcrm_get_activity($id);
    $vendor_logs=lcrm_get_vendor_logs($id);
    // Decode custom field values and attach to lead object
    $custom_vals = json_decode($lead->custom_fields ?? '{}', true) ?: [];
    $lead->custom_field_values = $custom_vals;
    wp_send_json_success(['lead'=>$lead,'activity'=>$activity,'vendor_logs'=>$vendor_logs,'custom_fields'=>$custom_vals,'conversations'=>lcrm_get_conversations($id)]);
}

add_action('wp_ajax_lcrm_save_lead','lcrm_ajax_save_lead');
function lcrm_ajax_save_lead() {
    lcrm_verify();
    if(!lcrm_current_can('lcrm_add_leads')) wp_send_json_error(['msg'=>'Permission denied.'],403);
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0); $data=$_POST;
    if($id){
        lcrm_update_lead($id,$data);
        wp_send_json_success(['msg'=>'Lead updated successfully.','id'=>$id,'keep_open'=>true]);
    } else {
        if(empty($_POST['client_name'])) wp_send_json_error(['msg'=>'Client name is required.']);
        if (lcrm_col_exists('assigned_to')) {
            if (!lcrm_can_assign_leads()) {
                // Non-admin: always assign to themselves, regardless of form data
                $data['assigned_to'] = get_current_user_id();
            } elseif (empty($data['assigned_to'])) {
                // Admin with no user selected: assign to themselves
                $data['assigned_to'] = get_current_user_id();
            }
        }
        $dups=lcrm_check_duplicate(isset($_POST['client_phone']) ? $_POST['client_phone'] : '',isset($_POST['client_email']) ? $_POST['client_email'] : '');
        if(!empty($dups)&&empty($_POST['force_create'])) {
            wp_send_json_error(['msg'=>'Possible duplicate detected.','duplicates'=>array_map(function($d){return ['id'=>$d->id,'name'=>$d->client_name,'date'=>$d->lead_date,'work'=>$d->work_type];},$dups),'duplicate_warning'=>true]);
        }
        $new_id=lcrm_create_lead($data);
        if(is_wp_error($new_id)) wp_send_json_error(['msg'=>$new_id->get_error_message()]);
        wp_send_json_success(['msg'=>'Lead created. Fill in more details and save again.','id'=>$new_id,'keep_open'=>true]);
    }
}

add_action('wp_ajax_lcrm_delete_lead','lcrm_ajax_delete_lead');
function lcrm_ajax_delete_lead() {
    lcrm_verify();
    if(!lcrm_current_can('lcrm_delete_leads')) wp_send_json_error(['msg'=>'Permission denied.'],403);
    lcrm_delete_lead(intval(isset($_POST['id']) ? $_POST['id'] : 0));
    wp_send_json_success(['msg'=>'Lead deleted.']);
}

add_action('wp_ajax_lcrm_quick_update','lcrm_ajax_quick_update');
function lcrm_ajax_quick_update() {
    lcrm_verify();
    if(!lcrm_current_can('lcrm_edit_leads')) wp_send_json_error(['msg'=>'Permission denied.'],403);
    global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);$field=sanitize_key(isset($_POST['field']) ? $_POST['field'] : '');$value=sanitize_text_field(isset($_POST['value']) ? $_POST['value'] : '');
    $allowed=['stage_id','internal_status','work_status','remarks','final_remarks','next_action_date','vendor_id',
               'work_type','lead_source','priority','lead_temperature',
               'work_started','client_followup_date','vendor_followup_date','next_action_type','brand_id',
               // Client-facing financials — all staff
               'total_quote','pmt_received',
    ];
    // Confidential financials — admin only
    if (lcrm_can_see_confidential_finance()) {
        $allowed = array_merge($allowed, ['vendor_charge','pmt_to_vendor']);
    }
    if(!in_array($field,$allowed,true)) wp_send_json_error(['msg'=>'Invalid field.']);
    if($field==='work_status') $value=lcrm_clean_work_status($value);
    if($field==='work_type') $value=lcrm_work_types_encode(lcrm_work_types_decode($value));
    $wpdb->update("{$wpdb->prefix}lcrm_leads",[$field=>$value,'updated_at'=>current_time('mysql')],['id'=>$id]);
    if(in_array($field,['total_quote','pmt_received','vendor_charge'],true)){
        $l=lcrm_get_lead($id);
        if($l) $wpdb->update("{$wpdb->prefix}lcrm_leads",['balance_payment'=>max(0,$l->total_quote-$l->pmt_received),'profit'=>max(0,$l->total_quote-$l->vendor_charge),'updated_at'=>current_time('mysql')],['id'=>$id]);
    }
    if($field==='stage_id'){
        $s=lcrm_get_stage($value);
        lcrm_log_activity($id,'stage_change','Stage: '.((isset($s) ? $s->name : $value)));
        // CallMe Bot: notify admin when work marked complete
        if($s && in_array($s->name, ['Work Completed','Delivered'])) { lcrm_callme_notify_vendor_work_complete($id); }
    }
    if($field==='vendor_id'&&$value){
        $wpdb->update("{$wpdb->prefix}lcrm_leads",['last_vendor_contact'=>current_time('mysql')],['id'=>$id]);
        // CallMe Bot: notify vendor of new assignment
        lcrm_callme_notify_vendor_assigned( $id );
    }
    // CallMe Bot: notify team when payment released to vendor
    if($field==='pmt_to_vendor'&&$value>0){ lcrm_callme_notify_vendor_payment( $id, $value ); }
    wp_send_json_success(['msg'=>'Updated.']);
}

/*  ALERTS  */
add_action('wp_ajax_lcrm_get_alerts','lcrm_ajax_get_alerts');
function lcrm_ajax_get_alerts() {
    lcrm_verify();
    $priority=sanitize_text_field(isset($_POST['priority']) ? $_POST['priority'] : '');
    $unread=!empty($_POST['unread_only']);
    $alerts=lcrm_get_alerts($unread,$priority,50);
    $out=[];
    foreach((array)$alerts as $a) $out[]=['id'=>$a->id,'lead_id'=>$a->lead_id,'type'=>$a->type,'priority'=>$a->priority,'message'=>$a->message,'is_read'=>$a->is_read,'created_at'=>$a->created_at,'client_name'=>$a->client_name,'work_type'=>$a->work_type,'client_phone'=>$a->client_phone];
    wp_send_json_success(['alerts'=>$out,'unread_count'=>lcrm_count_unread_alerts()]);
}

add_action('wp_ajax_lcrm_mark_alert_read','lcrm_ajax_mark_alert_read');
function lcrm_ajax_mark_alert_read() {
    lcrm_verify(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);
    if($id) $wpdb->update("{$wpdb->prefix}lcrm_alerts",['is_read'=>1],['id'=>$id]);
    else    $wpdb->query("UPDATE {$wpdb->prefix}lcrm_alerts SET is_read=1");
    wp_send_json_success(['unread'=>lcrm_count_unread_alerts()]);
}

add_action('wp_ajax_lcrm_run_automation_now','lcrm_ajax_run_automation_now');
function lcrm_ajax_run_automation_now() {
    lcrm_verify_admin();
    lcrm_run_automation();
    wp_send_json_success(['msg'=>'Automation ran. Alerts refreshed.','alerts'=>lcrm_count_unread_alerts()]);
}

/*  VENDOR MESSAGING  */
add_action('wp_ajax_lcrm_get_vendor_message','lcrm_ajax_get_vendor_message');
function lcrm_ajax_get_vendor_message() {
    lcrm_verify();
    $lead_id=intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);$type=sanitize_text_field(isset($_POST['type']) ? $_POST['type'] : 'vendor_assignment');
    $fields=(array)(isset($_POST['fields']) ? $_POST['fields'] : []);
    $lead=lcrm_get_lead($lead_id);
    if(!$lead) wp_send_json_error(['msg'=>'Lead not found.']);
    $msg=$type==='vendor_share'?lcrm_generate_vendor_share($lead,$fields):lcrm_generate_message($lead,$type);
    $vendor=$lead->vendor_id?lcrm_get_vendor($lead->vendor_id):null;
    $wa_link='';
    if($vendor&&$vendor->phone) $wa_link=lcrm_whatsapp_link($vendor->phone,$msg);
    wp_send_json_success(['message'=>$msg,'whatsapp_link'=>$wa_link,'vendor_phone'=>(isset($vendor->phone) ? $vendor->phone : '')]);
}

add_action('wp_ajax_lcrm_log_vendor_contact','lcrm_ajax_log_vendor_contact');
function lcrm_ajax_log_vendor_contact() {
    lcrm_verify();
    $lead_id=intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);$vendor_id=intval(isset($_POST['vendor_id']) ? $_POST['vendor_id'] : 0);
    $message=sanitize_textarea_field(isset($_POST['message']) ? $_POST['message'] : '');
    lcrm_log_vendor_contact($lead_id,$vendor_id,$message,'sent');
    lcrm_log_activity($lead_id,'vendor_contact','Vendor contacted. Message: '.wp_trim_words($message,10));
    wp_send_json_success(['msg'=>'Contact logged.']);
}

add_action('wp_ajax_lcrm_update_vendor_log','lcrm_ajax_update_vendor_log');
function lcrm_ajax_update_vendor_log() {
    lcrm_verify(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);$response=sanitize_textarea_field(isset($_POST['response']) ? $_POST['response'] : '');$status=sanitize_text_field(isset($_POST['status']) ? $_POST['status'] : 'responded');
    $wpdb->update("{$wpdb->prefix}lcrm_vendor_logs",['response'=>$response,'status'=>$status],['id'=>$id]);
    // Update last_vendor_contact
    $log=$wpdb->get_row($wpdb->prepare("SELECT lead_id FROM {$wpdb->prefix}lcrm_vendor_logs WHERE id=%d",$id));
    if($log) $wpdb->update("{$wpdb->prefix}lcrm_leads",['last_vendor_contact'=>current_time('mysql')],['id'=>$log->lead_id]);
    wp_send_json_success(['msg'=>'Response logged.']);
}

/*  BRANDS  */
add_action('wp_ajax_lcrm_save_brand','lcrm_ajax_save_brand');
function lcrm_ajax_save_brand() {
    lcrm_verify_admin(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);$name=sanitize_text_field(isset($_POST['name']) ? $_POST['name'] : '');
    if(!$name) wp_send_json_error(['msg'=>'Name required.']);
    if($id) $wpdb->update("{$wpdb->prefix}lcrm_brands",['name'=>$name],['id'=>$id]);
    else $wpdb->insert("{$wpdb->prefix}lcrm_brands",['name'=>$name,'is_active'=>1,'created_at'=>current_time('mysql')]);
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Brand saved.','id'=>$id?:$wpdb->insert_id]);
}
add_action('wp_ajax_lcrm_delete_brand','lcrm_ajax_delete_brand');
function lcrm_ajax_delete_brand() {
    lcrm_verify_admin(); global $wpdb;
    $wpdb->delete("{$wpdb->prefix}lcrm_brands",['id'=>intval(isset($_POST['id']) ? $_POST['id'] : 0)]);
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Deleted.']);
}

/*  VENDOR CATEGORIES  */
add_action('wp_ajax_lcrm_save_vendor_cat','lcrm_ajax_save_vendor_cat');
function lcrm_ajax_save_vendor_cat() {
    lcrm_verify_admin(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);$name=sanitize_text_field(isset($_POST['name']) ? $_POST['name'] : '');
    if(!$name) wp_send_json_error(['msg'=>'Name required.']);
    if($id) $wpdb->update("{$wpdb->prefix}lcrm_vendor_categories",['name'=>$name],['id'=>$id]);
    else $wpdb->insert("{$wpdb->prefix}lcrm_vendor_categories",['name'=>$name]);
    lcrm_bust_dropdown_cache();
    wp_send_json_success(['msg'=>'Saved.','id'=>$id?:$wpdb->insert_id]);
}
add_action('wp_ajax_lcrm_delete_vendor_cat','lcrm_ajax_delete_vendor_cat');
function lcrm_ajax_delete_vendor_cat() {
    lcrm_verify_admin(); global $wpdb;
    $wpdb->delete("{$wpdb->prefix}lcrm_vendor_categories",['id'=>intval(isset($_POST['id']) ? $_POST['id'] : 0)]);
    lcrm_bust_dropdown_cache();
    wp_send_json_success(['msg'=>'Deleted.']);
}

/*  AUTOMATION SETTINGS  */
add_action('wp_ajax_lcrm_save_automation','lcrm_ajax_save_automation');
function lcrm_ajax_save_automation() {
    lcrm_verify_admin();
    update_option('lcrm_no_vendor_days',  intval((isset($_POST['no_vendor_days']) ? $_POST['no_vendor_days'] : 5)));
    update_option('lcrm_no_action_days',  intval((isset($_POST['no_action_days']) ? $_POST['no_action_days'] : 7)));
    update_option('lcrm_email_alerts',    intval((isset($_POST['email_alerts']) ? $_POST['email_alerts'] : 1)));
    update_option('lcrm_alert_email',     sanitize_email(isset($_POST['alert_email']) ? $_POST['alert_email'] : get_option('admin_email')));
    // CallMe Bot (v2.1.2)
    update_option('lcrm_callme_enabled',        intval(isset($_POST['callme_enabled']) ? $_POST['callme_enabled'] : 0));
    update_option('lcrm_callme_api_key',         sanitize_text_field(isset($_POST['callme_api_key']) ? $_POST['callme_api_key'] : ''));
    update_option('lcrm_callme_admin_number',    sanitize_text_field(isset($_POST['callme_admin_number']) ? $_POST['callme_admin_number'] : ''));
    update_option('lcrm_callme_staff_numbers',   sanitize_text_field(isset($_POST['callme_staff_numbers']) ? $_POST['callme_staff_numbers'] : ''));
    update_option('lcrm_vendor_escalation_hours',intval(isset($_POST['vendor_escalation_hours']) ? $_POST['vendor_escalation_hours'] : 48));
    wp_send_json_success(['msg'=>'Automation settings saved.']);
}

/*  BULK ACTIONS  */
add_action('wp_ajax_lcrm_bulk_action','lcrm_ajax_bulk_action');
function lcrm_ajax_bulk_action() {
    lcrm_verify();
    if(!lcrm_current_can('lcrm_edit_leads')) wp_send_json_error(['msg'=>'Permission denied.'],403);
    global $wpdb;
    $action=sanitize_text_field(isset($_POST['bulk_action']) ? $_POST['bulk_action'] : '');
    $ids=array_map('intval',(array)(isset($_POST['ids']) ? $_POST['ids'] : []));
    if(empty($ids)) wp_send_json_error(['msg'=>'No leads selected.']);
    $ph=implode(',',array_fill(0,count($ids),'%d'));
    switch($action){
        case 'delete':
            if(!lcrm_current_can('lcrm_delete_leads')) wp_send_json_error(['msg'=>'Permission denied.'],403);
            foreach($ids as $id) lcrm_delete_lead($id);
            wp_send_json_success(['msg'=>count($ids).' leads deleted.']);
        case 'change_stage':
            $sid=intval(isset($_POST['stage_id']) ? $_POST['stage_id'] : 0);
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lcrm_leads SET stage_id=%d,updated_at=%s WHERE id IN ($ph)",array_merge([$sid,current_time('mysql')],$ids)));
            wp_send_json_success(['msg'=>count($ids).' leads updated.']);
        case 'assign_vendor':
            $vid=intval(isset($_POST['vendor_id']) ? $_POST['vendor_id'] : 0);
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lcrm_leads SET vendor_id=%d,updated_at=%s WHERE id IN ($ph)",array_merge([$vid,current_time('mysql')],$ids)));
            wp_send_json_success(['msg'=>count($ids).' leads assigned.']);
        case 'set_priority':
            $pri=sanitize_text_field(isset($_POST['priority']) ? $_POST['priority'] : 'medium');
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lcrm_leads SET priority=%s,updated_at=%s WHERE id IN ($ph)",array_merge([$pri,current_time('mysql')],$ids)));
            wp_send_json_success(['msg'=>count($ids).' leads priority set.']);
        default: wp_send_json_error(['msg'=>'Unknown action.']);
    }
}

/*  STAGES  */
add_action('wp_ajax_lcrm_save_stage','lcrm_ajax_save_stage');
function lcrm_ajax_save_stage() {
    lcrm_verify_admin(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);$name=sanitize_text_field(isset($_POST['name']) ? $_POST['name'] : '');$color=sanitize_hex_color(isset($_POST['color']) ? $_POST['color'] : '#6366f1')?:'#6366f1';
    if(!$name) wp_send_json_error(['msg'=>'Name required.']);
    if($id) $wpdb->update("{$wpdb->prefix}lcrm_stages",['name'=>$name,'color'=>$color],['id'=>$id]);
    else{$max=(int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_stages")+1;$wpdb->insert("{$wpdb->prefix}lcrm_stages",['name'=>$name,'color'=>$color,'sort_order'=>$max,'is_active'=>1]);}
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Stage saved.','id'=>$id?:$wpdb->insert_id]);
}
add_action('wp_ajax_lcrm_delete_stage','lcrm_ajax_delete_stage');
function lcrm_ajax_delete_stage() {
    lcrm_verify_admin(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $in_use=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE stage_id=%d",$id));
    if($in_use) wp_send_json_error(['msg'=>"Cannot delete - {$in_use} leads use this stage."]);
    $wpdb->delete("{$wpdb->prefix}lcrm_stages",['id'=>$id]);
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Stage deleted.']);
}
add_action('wp_ajax_lcrm_reorder_stages','lcrm_ajax_reorder_stages');
function lcrm_ajax_reorder_stages() {
    lcrm_verify_admin(); global $wpdb;
    foreach(array_map('intval',(array)(isset($_POST['order']) ? $_POST['order'] : [])) as $pos=>$id) $wpdb->update("{$wpdb->prefix}lcrm_stages",['sort_order'=>$pos],['id'=>$id]);
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Reordered.']);
}

/*  REMARKS  */
add_action('wp_ajax_lcrm_save_remark','lcrm_ajax_save_remark');
function lcrm_ajax_save_remark() {
    lcrm_verify_admin(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);$cat=sanitize_text_field(isset($_POST['category']) ? $_POST['category'] : 'General');$remark=sanitize_textarea_field(isset($_POST['remark']) ? $_POST['remark'] : '');
    if(!$remark) wp_send_json_error(['msg'=>'Remark text required.']);
    if($id) $wpdb->update("{$wpdb->prefix}lcrm_remarks",['category'=>$cat,'remark'=>$remark],['id'=>$id]);
    else{$max=(int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_remarks")+1;$wpdb->insert("{$wpdb->prefix}lcrm_remarks",['category'=>$cat,'remark'=>$remark,'sort_order'=>$max,'is_active'=>1]);}
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Saved.','id'=>$id?:$wpdb->insert_id]);
}
add_action('wp_ajax_lcrm_delete_remark','lcrm_ajax_delete_remark');
function lcrm_ajax_delete_remark() {
    lcrm_verify_admin(); global $wpdb;
    $wpdb->delete("{$wpdb->prefix}lcrm_remarks",['id'=>intval(isset($_POST['id']) ? $_POST['id'] : 0)]);
    wp_send_json_success(['msg'=>'Deleted.']);
}

/*  VENDORS  */
add_action('wp_ajax_lcrm_save_vendor','lcrm_ajax_save_vendor');
function lcrm_ajax_save_vendor() {
    lcrm_verify_admin(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $row=['name'=>sanitize_text_field(isset($_POST['name']) ? $_POST['name'] : ''),'phone'=>sanitize_text_field(isset($_POST['phone']) ? $_POST['phone'] : ''),'phone2'=>sanitize_text_field(isset($_POST['phone2']) ? $_POST['phone2'] : ''),'email'=>sanitize_email(isset($_POST['email']) ? $_POST['email'] : ''),'company'=>sanitize_text_field(isset($_POST['company']) ? $_POST['company'] : ''),'category_id'=>intval(isset($_POST['category_id']) ? $_POST['category_id'] : 0)?:null,'address'=>sanitize_textarea_field(isset($_POST['address']) ? $_POST['address'] : '')];
    if(!$row['name']) wp_send_json_error(['msg'=>'Name required.']);
    if($id) $wpdb->update("{$wpdb->prefix}lcrm_vendors",$row,['id'=>$id]);
    else{$row['is_active']=1;$row['created_at']=current_time('mysql');$wpdb->insert("{$wpdb->prefix}lcrm_vendors",$row);}
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Vendor saved.','id'=>$id?:$wpdb->insert_id]);
}
add_action('wp_ajax_lcrm_delete_vendor','lcrm_ajax_delete_vendor');
function lcrm_ajax_delete_vendor() {
    lcrm_verify_admin(); global $wpdb;
    $id=intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $in_use=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE vendor_id=%d",$id));
    if($in_use) wp_send_json_error(['msg'=>"Cannot delete - {$in_use} leads assigned."]);
    $wpdb->delete("{$wpdb->prefix}lcrm_vendors",['id'=>$id]);
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Deleted.']);
}

/*  DROPDOWNS / WORK TYPES / SOURCES  */
add_action('wp_ajax_lcrm_save_dropdown','lcrm_ajax_save_dropdown');
function lcrm_ajax_save_dropdown() {
    lcrm_verify_admin(); global $wpdb;
    $type=sanitize_key(isset($_POST['dtype']) ? $_POST['dtype'] : '');$id=intval(isset($_POST['id']) ? $_POST['id'] : 0);$name=sanitize_text_field(isset($_POST['name']) ? $_POST['name'] : '');
    $table_map=['work_type'=>'lcrm_work_types','lead_source'=>'lcrm_lead_sources'];
    if(!isset($table_map[$type])||!$name) wp_send_json_error(['msg'=>'Invalid.']);
    $tbl=$wpdb->prefix.$table_map[$type];
    if($id) $wpdb->update($tbl,['name'=>$name],['id'=>$id]);
    else{$max=(int)$wpdb->get_var("SELECT MAX(sort_order) FROM $tbl")+1;$wpdb->insert($tbl,['name'=>$name,'sort_order'=>$max,'is_active'=>1]);}
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Saved.','id'=>$id?:$wpdb->insert_id]);
}
add_action('wp_ajax_lcrm_delete_dropdown','lcrm_ajax_delete_dropdown');
function lcrm_ajax_delete_dropdown() {
    lcrm_verify_admin(); global $wpdb;
    $type=sanitize_key(isset($_POST['dtype']) ? $_POST['dtype'] : '');$table_map=['work_type'=>'lcrm_work_types','lead_source'=>'lcrm_lead_sources'];
    if(!isset($table_map[$type])) wp_send_json_error(['msg'=>'Invalid.']);
    $wpdb->delete($wpdb->prefix.$table_map[$type],['id'=>intval(isset($_POST['id']) ? $_POST['id'] : 0)]);
    lcrm_after_lookup_change();
    wp_send_json_success(['msg'=>'Deleted.']);
}

/*  ACTIVITY NOTE  */
add_action('wp_ajax_lcrm_add_note','lcrm_ajax_add_note');
function lcrm_ajax_add_note() {
    lcrm_verify();
    $lead_id=intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);$note=sanitize_textarea_field(isset($_POST['note']) ? $_POST['note'] : '');
    if(!$note) wp_send_json_error(['msg'=>'Note is empty.']);
    lcrm_log_activity($lead_id,'note',$note);
    $user=wp_get_current_user();
    wp_send_json_success(['msg'=>'Note added.','user'=>$user->display_name,'time'=>current_time('d M Y H:i')]);
}

/*  REMINDERS  */
add_action('wp_ajax_lcrm_save_reminder','lcrm_ajax_save_reminder');
function lcrm_ajax_save_reminder() {
    lcrm_verify(); global $wpdb;
    $lead_id=intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);$remind_at=sanitize_text_field(isset($_POST['remind_at']) ? $_POST['remind_at'] : '');$note=sanitize_textarea_field(isset($_POST['note']) ? $_POST['note'] : '');
    if(!$lead_id||!$remind_at) wp_send_json_error(['msg'=>'Lead and date required.']);
    $wpdb->insert("{$wpdb->prefix}lcrm_reminders",['lead_id'=>$lead_id,'remind_at'=>$remind_at,'note'=>$note,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')]);
    wp_send_json_success(['msg'=>'Reminder set.']);
}

/*  KANBAN  */
add_action('wp_ajax_lcrm_kanban_move','lcrm_ajax_kanban_move');
function lcrm_ajax_kanban_move() {
    lcrm_verify();
    if(!lcrm_current_can('lcrm_edit_leads')) wp_send_json_error(['msg'=>'Permission denied.'],403);
    global $wpdb;
    $lead_id=intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);$stage_id=intval(isset($_POST['stage_id']) ? $_POST['stage_id'] : 0);
    $wpdb->update("{$wpdb->prefix}lcrm_leads",['stage_id'=>$stage_id,'updated_at'=>current_time('mysql')],['id'=>$lead_id]);
    $s=lcrm_get_stage($stage_id);
    lcrm_log_activity($lead_id,'stage_change','Stage moved to: '.( isset($s->name) ? $s->name : $stage_id ));
    wp_send_json_success(['msg'=>'Moved.']);
}

/*  STATS  */
add_action('wp_ajax_lcrm_get_stats','lcrm_ajax_get_stats');
function lcrm_ajax_get_stats() {
    lcrm_verify();
    $stats = lcrm_get_dashboard_stats();
    // Strip aggregate financial data for non-admins
    if (!lcrm_can_see_revenue_dashboard()) {
        unset($stats['total_revenue'], $stats['total_quote'], $stats['total_profit'], $stats['pending_balance'], $stats['payment_no_work']);
    }
    wp_send_json_success($stats);
}

/*  IMPORT / EXPORT  */
add_action('wp_ajax_lcrm_import_csv','lcrm_ajax_import_csv');
function lcrm_ajax_import_csv() {
    lcrm_verify_admin();
    if(empty($_FILES['csv_file']['tmp_name'])) wp_send_json_error(['msg'=>'No file uploaded.']);
    $result=lcrm_import_csv($_FILES['csv_file']['tmp_name']);
    if(is_wp_error($result)) wp_send_json_error(['msg'=>$result->get_error_message()]);
    wp_send_json_success(['msg'=>"Imported {$result['imported']} leads.",'errors'=>$result['errors']]);
}
add_action('wp_ajax_lcrm_export_csv','lcrm_ajax_export_csv');
function lcrm_ajax_export_csv() {
    lcrm_verify();
    lcrm_export_csv([
        'stage_id'        => sanitize_text_field(isset($_GET['stage_id']) ? $_GET['stage_id'] : ''),
        'vendor_id'       => sanitize_text_field(isset($_GET['vendor_id']) ? $_GET['vendor_id'] : ''),
        'brand_id'        => sanitize_text_field(isset($_GET['brand_id']) ? $_GET['brand_id'] : ''),
        'work_type'       => sanitize_text_field(isset($_GET['work_type']) ? $_GET['work_type'] : ''),
        'work_status'     => sanitize_text_field(isset($_GET['work_status']) ? $_GET['work_status'] : ''),
        'priority'        => sanitize_text_field(isset($_GET['priority']) ? $_GET['priority'] : ''),
        'temperature'     => sanitize_text_field(isset($_GET['temperature']) ? $_GET['temperature'] : ''),
        'search'          => sanitize_text_field(isset($_GET['search']) ? $_GET['search'] : ''),
        'date_from'       => sanitize_text_field(isset($_GET['date_from']) ? $_GET['date_from'] : ''),
        'date_to'         => sanitize_text_field(isset($_GET['date_to']) ? $_GET['date_to'] : ''),
        'internal_status' => sanitize_text_field(isset($_GET['internal_status']) ? $_GET['internal_status'] : ''),
        'remarks_search'  => sanitize_text_field(isset($_GET['remarks_search']) ? $_GET['remarks_search'] : ''),
    ]);
}

/* ============================================================
   CUSTOM FIELDS — CRUD + REORDER
   ============================================================ */
add_action('wp_ajax_lcrm_get_fields',    'lcrm_ajax_get_fields');
function lcrm_ajax_get_fields() {
    lcrm_verify();
    global $wpdb;
    $fields = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_fields ORDER BY sort_order ASC, id ASC");
    wp_send_json_success(['fields' => $fields]);
}

add_action('wp_ajax_lcrm_save_field', 'lcrm_ajax_save_field');
function lcrm_ajax_save_field() {
    lcrm_verify_admin();
    global $wpdb;
    $id    = intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $label = sanitize_text_field(isset($_POST['field_label']) ? $_POST['field_label'] : '');
    $key   = sanitize_key(isset($_POST['field_key']) ? $_POST['field_key'] : '');
    $type  = sanitize_text_field(isset($_POST['field_type']) ? $_POST['field_type'] : 'text');
    $opts  = sanitize_textarea_field(isset($_POST['field_opts']) ? $_POST['field_opts'] : '');
    $tab   = sanitize_text_field(isset($_POST['tab_name']) ? $_POST['tab_name'] : 'basic');
    $req   = intval(isset($_POST['is_required']) ? $_POST['is_required'] : 0);
    $list  = intval(isset($_POST['show_in_list']) ? $_POST['show_in_list'] : 0);

    if (!$label) wp_send_json_error(['msg' => 'Field label is required.']);

    // Auto-generate key if not provided
    if (!$key) $key = 'cf_' . sanitize_key(str_replace(' ', '_', strtolower($label)));

    $allowed_tabs = ['basic','follow','financial','vendor','remarks'];
    if (!in_array($tab, $allowed_tabs)) $tab = 'basic';

    $data = [
        'field_label' => $label,
        'field_key'   => $key,
        'field_type'  => $type,
        'field_opts'  => $opts,
        'tab_name'    => $tab,
        'is_required' => $req,
        'show_in_list'=> $list,
        'is_active'   => 1,
    ];

    if ($id) {
        $wpdb->update("{$wpdb->prefix}lcrm_fields", $data, ['id' => $id]);
        wp_send_json_success(['msg' => 'Field updated.', 'id' => $id]);
    } else {
        // Set sort_order to end
        $max = (int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_fields");
        $data['sort_order'] = $max + 1;

        // Check duplicate key
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}lcrm_fields WHERE field_key=%s", $key));
        if ($exists) wp_send_json_error(['msg' => 'A field with this key already exists. Use a different label or key.']);

        $wpdb->insert("{$wpdb->prefix}lcrm_fields", $data);
        wp_send_json_success(['msg' => 'Field created.', 'id' => $wpdb->insert_id]);
    }
}

add_action('wp_ajax_lcrm_delete_field', 'lcrm_ajax_delete_field');
function lcrm_ajax_delete_field() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
    if (!$id) wp_send_json_error(['msg' => 'Invalid ID.']);
    $wpdb->delete("{$wpdb->prefix}lcrm_fields", ['id' => $id]);
    wp_send_json_success(['msg' => 'Field deleted.']);
}

add_action('wp_ajax_lcrm_reorder_fields', 'lcrm_ajax_reorder_fields');
function lcrm_ajax_reorder_fields() {
    lcrm_verify_admin();
    global $wpdb;
    $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
    if (empty($ids)) wp_send_json_error(['msg' => 'No IDs provided.']);
    foreach ($ids as $order => $id) {
        $wpdb->update("{$wpdb->prefix}lcrm_fields", ['sort_order' => $order + 1], ['id' => $id]);
    }
    wp_send_json_success(['msg' => 'Order saved.']);
}

add_action('wp_ajax_lcrm_toggle_field', 'lcrm_ajax_toggle_field');
function lcrm_ajax_toggle_field() {
    lcrm_verify_admin();
    global $wpdb;
    $id     = intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $active = intval(isset($_POST['is_active']) ? $_POST['is_active'] : 0);
    $wpdb->update("{$wpdb->prefix}lcrm_fields", ['is_active' => $active], ['id' => $id]);
    wp_send_json_success(['msg' => 'Updated.']);
}

/* ============================================================
   CUSTOM FIELD VALUES — save/load per lead
   ============================================================ */
// Custom field values are stored in the lcrm_leads row via JSON in a
// dedicated column. We add it via migration and read/write it here.
function lcrm_get_custom_field_values($lead_id) {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT custom_fields FROM {$wpdb->prefix}lcrm_leads WHERE id=%d", $lead_id
    ));
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function lcrm_save_custom_field_values($lead_id, $values) {
    global $wpdb;
    $wpdb->update(
        "{$wpdb->prefix}lcrm_leads",
        ['custom_fields' => json_encode($values)],
        ['id' => $lead_id]
    );
}

/* ============================================================
   FORM LAYOUT EDITOR — move field to different tab
   ============================================================ */
add_action('wp_ajax_lcrm_move_field_tab', 'lcrm_ajax_move_field_tab');
function lcrm_ajax_move_field_tab() {
    lcrm_verify_admin();
    global $wpdb;
    $id    = intval(isset($_POST['id'])    ? $_POST['id']    : 0);
    $tab   = sanitize_text_field(isset($_POST['tab'])   ? $_POST['tab']   : 'basic');
    $order = intval(isset($_POST['order']) ? $_POST['order'] : 0);
    $allowed = ['basic','follow','financial','vendor','remarks'];
    if (!$id || !in_array($tab, $allowed)) wp_send_json_error(['msg'=>'Invalid parameters.']);
    $wpdb->update("{$wpdb->prefix}lcrm_fields", ['tab_name'=>$tab,'sort_order'=>$order], ['id'=>$id]);
    wp_send_json_success(['msg'=>'Field moved.']);
}

/* ============================================================
   USER PREFERENCES (column/filter visibility per user)
   ============================================================ */
add_action('wp_ajax_lcrm_save_pref', 'lcrm_ajax_save_pref');
function lcrm_ajax_save_pref() {
    lcrm_verify();
    $key  = sanitize_key(isset($_POST['pref_key'])   ? $_POST['pref_key']   : '');
    $val  = isset($_POST['pref_val']) ? wp_json_encode(json_decode(stripslashes($_POST['pref_val']), true)) : '{}';
    $allowed_keys = ['lcrm_col_prefs','lcrm_filter_prefs'];
    if (!in_array($key, $allowed_keys)) wp_send_json_error(['msg'=>'Invalid key.']);
    update_user_meta(get_current_user_id(), $key, $val);
    wp_send_json_success(['msg'=>'Preferences saved.']);
}

add_action('wp_ajax_lcrm_get_prefs', 'lcrm_ajax_get_prefs');
function lcrm_ajax_get_prefs() {
    lcrm_verify();
    $uid = get_current_user_id();
    $col_prefs    = get_user_meta($uid, 'lcrm_col_prefs',    true);
    $filter_prefs = get_user_meta($uid, 'lcrm_filter_prefs', true);
    wp_send_json_success([
        'col_prefs'    => $col_prefs    ? json_decode($col_prefs,    true) : null,
        'filter_prefs' => $filter_prefs ? json_decode($filter_prefs, true) : null,
    ]);
}

/* =========================================================
   FULL FORM LAYOUT SAVE — saves tab+order for ALL fields
   ========================================================= */
add_action('wp_ajax_lcrm_save_form_layout', 'lcrm_ajax_save_form_layout');
function lcrm_ajax_save_form_layout() {
    lcrm_verify_admin();
    $raw    = isset($_POST['layout']) ? stripslashes($_POST['layout']) : '';
    $layout = json_decode($raw, true);
    if (!is_array($layout)) wp_send_json_error(['msg'=>'Invalid layout data.']);
    $valid_tabs = ['basic','follow','financial','vendor','remarks'];
    $clean = [];
    foreach ($layout as $key => $pos) {
        $key = sanitize_key($key);
        if (!$key) continue;
        $tab   = (isset($pos['tab']) && in_array($pos['tab'],$valid_tabs)) ? $pos['tab'] : 'basic';
        $order = isset($pos['order']) ? intval($pos['order']) : 10;
        $clean[$key] = ['tab'=>$tab,'order'=>$order];
    }
    update_option('lcrm_form_layout', json_encode($clean));
    wp_send_json_success(['msg'=>'Layout saved.']);
}

add_action('wp_ajax_lcrm_reset_form_layout', 'lcrm_ajax_reset_form_layout');
function lcrm_ajax_reset_form_layout() {
    lcrm_verify_admin();
    delete_option('lcrm_form_layout');
    wp_send_json_success(['msg'=>'Layout reset to defaults.']);
}

/* ── Conversation AJAX ──────────────────────────────────── */
add_action('wp_ajax_lcrm_add_conversation','lcrm_ajax_add_conversation');
function lcrm_ajax_add_conversation() {
    lcrm_verify();
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    $message = sanitize_textarea_field(isset($_POST['message']) ? $_POST['message'] : '');
    if (!$lead_id) wp_send_json_error(['msg'=>'Lead ID required.']);
    if (!$message) wp_send_json_error(['msg'=>'Message cannot be empty.']);
    $iid = lcrm_add_conversation($lead_id, $message);
    if (!$iid) wp_send_json_error(['msg'=>'Could not save. Please try again.']);
    global $wpdb;
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lcrm_conversations WHERE id=%d", $iid));
    wp_send_json_success(['entry'=>$entry]);
}
add_action('wp_ajax_lcrm_get_conversations','lcrm_ajax_get_conversations');
function lcrm_ajax_get_conversations() {
    lcrm_verify();
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    if (!$lead_id) wp_send_json_error(['msg'=>'Lead ID required.']);
    wp_send_json_success(['conversations'=>lcrm_get_conversations($lead_id)]);
}

/* ── Quick Replies AJAX ─────────────────────────────────── */
add_action('wp_ajax_lcrm_get_quick_replies','lcrm_ajax_get_quick_replies');
function lcrm_ajax_get_quick_replies() {
    lcrm_verify();
    $grouped = lcrm_get_remarks_grouped();
    $flat = [];
    foreach ($grouped as $cat => $items) {
        foreach ((array)$items as $r) $flat[] = ['id'=>$r->id,'cat'=>$cat,'text'=>$r->remark];
    }
    wp_send_json_success(['replies'=>$flat]);
}
add_action('wp_ajax_lcrm_save_quick_reply','lcrm_ajax_save_quick_reply');
function lcrm_ajax_save_quick_reply() {
    lcrm_verify();
    $cat    = sanitize_text_field(isset($_POST['category']) ? $_POST['category'] : 'General') ?: 'General';
    $remark = sanitize_textarea_field(isset($_POST['remark']) ? $_POST['remark'] : '');
    if (!$remark) wp_send_json_error(['msg'=>'Remark text is required.']);
    global $wpdb;
    $max = (int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_remarks") + 1;
    $wpdb->insert("{$wpdb->prefix}lcrm_remarks",['category'=>$cat,'remark'=>$remark,'sort_order'=>$max,'is_active'=>1]);
    $id = $wpdb->insert_id;
    wp_send_json_success(['reply'=>['id'=>$id,'cat'=>$cat,'text'=>$remark]]);
}
add_action('wp_ajax_lcrm_bulk_add_remarks','lcrm_ajax_bulk_add_remarks');
function lcrm_ajax_bulk_add_remarks() {
    lcrm_verify();
    $raw = isset($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '';
    if (!$raw) wp_send_json_error(['msg'=>'No text provided.']);
    global $wpdb;
    $max = (int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_remarks");
    $added = 0;
    foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
        $cat = 'General'; $remark = $line;
        if (preg_match('/^([^|:]{1,60})(?:[|:])(.+)$/', $line, $m)) { $cat=trim($m[1]); $remark=trim($m[2]); }
        if (!$remark) continue;
        $wpdb->insert("{$wpdb->prefix}lcrm_remarks",['category'=>$cat,'remark'=>$remark,'sort_order'=>++$max,'is_active'=>1]);
        $added++;
    }
    if (!$added) wp_send_json_error(['msg'=>'No remarks added. Check format.']);
    wp_send_json_success(['msg'=>$added.' remark'.($added===1?'':'s').' added.','added'=>$added]);
}

/* ================================================================
   LEAD ASSIGNMENT — Admin actions
   ================================================================ */

/**
 * Assign a lead to a user (admin only).
 * action: assign | reassign
 */
add_action('wp_ajax_lcrm_assign_lead','lcrm_ajax_assign_lead');
function lcrm_ajax_assign_lead() {
    lcrm_verify_admin();
    global $wpdb;
    $lead_id  = intval(isset($_POST['lead_id'])  ? $_POST['lead_id']  : 0);
    $user_id  = intval(isset($_POST['user_id'])  ? $_POST['user_id']  : 0);
    if (!$lead_id || !$user_id) wp_send_json_error(['msg'=>'Lead ID and User ID are required.']);
    $lead = lcrm_get_lead($lead_id);
    if (!$lead) wp_send_json_error(['msg'=>'Lead not found.']);
    $prev = $lead->assigned_to ? intval($lead->assigned_to) : null;
    $action = $prev ? 'reassigned' : 'assigned';
    $wpdb->update("{$wpdb->prefix}lcrm_leads",
        ['assigned_to'=>$user_id,'is_access_revoked'=>0],
        ['id'=>$lead_id]
    );
    lcrm_log_assignment($lead_id, $action, $prev, $user_id);
    // Email the newly assigned user
    lcrm_send_assignment_email($lead_id, $user_id, $action);
    $user = get_userdata($user_id);
    wp_send_json_success(['msg'=>'Lead assigned to '.($user?$user->display_name:'user').'.',
        'assigned_to'=>$user_id,
        'assigned_user_name'=>$user?$user->display_name:'',
    ]);
}

/**
 * Revoke a user's access to a lead (admin only).
 * Sets is_access_revoked=1 and clears assigned_to.
 */
add_action('wp_ajax_lcrm_revoke_lead','lcrm_ajax_revoke_lead');
function lcrm_ajax_revoke_lead() {
    lcrm_verify_admin();
    global $wpdb;
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    if (!$lead_id) wp_send_json_error(['msg'=>'Lead ID required.']);
    $lead = lcrm_get_lead($lead_id);
    if (!$lead) wp_send_json_error(['msg'=>'Lead not found.']);
    $prev = $lead->assigned_to ? intval($lead->assigned_to) : null;
    $wpdb->update("{$wpdb->prefix}lcrm_leads",
        ['assigned_to'=>null,'is_access_revoked'=>1],
        ['id'=>$lead_id]
    );
    lcrm_log_assignment($lead_id, 'revoked', $prev, null);
    wp_send_json_success(['msg'=>'Access revoked. Lead is now admin-only.','revoked'=>1]);
}

/**
 * Admin takes ownership of any lead.
 */
add_action('wp_ajax_lcrm_take_lead','lcrm_ajax_take_lead');
function lcrm_ajax_take_lead() {
    lcrm_verify_admin();
    global $wpdb;
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    if (!$lead_id) wp_send_json_error(['msg'=>'Lead ID required.']);
    $lead = lcrm_get_lead($lead_id);
    if (!$lead) wp_send_json_error(['msg'=>'Lead not found.']);
    $prev = $lead->assigned_to ? intval($lead->assigned_to) : null;
    $me   = get_current_user_id();
    $wpdb->update("{$wpdb->prefix}lcrm_leads",
        ['assigned_to'=>$me,'is_access_revoked'=>0],
        ['id'=>$lead_id]
    );
    lcrm_log_assignment($lead_id, 'taken', $prev, $me);
    $me_user = get_userdata($me);
    wp_send_json_success(['msg'=>'Lead ownership taken.','assigned_to'=>$me,'assigned_user_name'=>$me_user?$me_user->display_name:'']);
}

/**
 * Get assignment history for a lead.
 */
add_action('wp_ajax_lcrm_get_assignment_history','lcrm_ajax_get_assignment_history');
function lcrm_ajax_get_assignment_history() {
    lcrm_verify_admin();
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    if (!$lead_id) wp_send_json_error(['msg'=>'Lead ID required.']);
    wp_send_json_success(['history'=>lcrm_get_assignment_history($lead_id)]);
}

/**
 * Bulk assign leads to a user (admin only).
 */
add_action('wp_ajax_lcrm_bulk_assign','lcrm_ajax_bulk_assign');
function lcrm_ajax_bulk_assign() {
    lcrm_verify_admin();
    global $wpdb;
    $ids     = array_map('intval', isset($_POST['ids'])     ? (array)$_POST['ids']     : []);
    $user_id = intval(isset($_POST['user_id']) ? $_POST['user_id'] : 0);
    if (empty($ids) || !$user_id) wp_send_json_error(['msg'=>'IDs and User ID required.']);
    $in = implode(',', $ids);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lcrm_leads SET assigned_to=%d, is_access_revoked=0 WHERE id IN ({$in})", $user_id));
    foreach ($ids as $lid) lcrm_log_assignment($lid, 'assigned', null, $user_id);
    $user = get_userdata($user_id);
    wp_send_json_success(['msg'=>count($ids).' leads assigned to '.($user?$user->display_name:'user').'.']);
}

/**
 * Get users list for assignment dropdowns.
 */
add_action('wp_ajax_lcrm_get_users_list','lcrm_ajax_get_users_list');
function lcrm_ajax_get_users_list() {
    lcrm_verify();
    $users = get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name','user_email']]);
    $out = [];
    foreach ((array)$users as $u) {
        $roles = (array)(get_userdata($u->ID)->roles ?? []);
        $out[] = ['id'=>$u->ID,'name'=>$u->display_name,'email'=>$u->user_email,'roles'=>$roles];
    }
    wp_send_json_success(['users'=>$out]);
}

/* ── Assignments Page Data ─────────────────────────────── */
// Registered twice to guarantee it fires even if add_action hooks are deferred
add_action('wp_ajax_lcrm_get_assignments_data','lcrm_ajax_get_assignments_data');
if(!has_action('wp_ajax_lcrm_get_assignments_data','lcrm_ajax_get_assignments_data')){
    add_action('wp_loaded',function(){ add_action('wp_ajax_lcrm_get_assignments_data','lcrm_ajax_get_assignments_data'); });
}
function lcrm_ajax_get_assignments_data() {
    lcrm_verify_admin();
    global $wpdb;

    $search       = sanitize_text_field(isset($_POST['search'])       ? $_POST['search']       : '');
    $filter_user  = intval(isset($_POST['filter_user'])               ? $_POST['filter_user']   : 0);
    $filter_stage = intval(isset($_POST['filter_stage'])              ? $_POST['filter_stage']  : 0);
    $filter_status= sanitize_text_field(isset($_POST['filter_status'])? $_POST['filter_status'] : '');
    $orderby      = sanitize_key(isset($_POST['orderby'])             ? $_POST['orderby']       : 'created_at');
    $order        = strtoupper(sanitize_key(isset($_POST['order'])    ? $_POST['order']         : 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $page         = max(1, intval(isset($_POST['page'])               ? $_POST['page']          : 1));
    $limit        = 50;
    $offset       = ($page - 1) * $limit;

    // Check which optional columns exist
    $cols        = (array)$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lcrm_leads", 0);
    $has_assigned = in_array('assigned_to',      $cols, true);
    $has_revoked  = in_array('is_access_revoked', $cols, true);

    $where = ['1=1']; $vals = [];
    if ($search) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(l.client_name LIKE %s OR l.client_phone LIKE %s OR l.work_type LIKE %s)';
        $vals[] = $like; $vals[] = $like; $vals[] = $like;
    }
    if ($filter_user  && $has_assigned)  { $where[] = 'l.assigned_to=%d';  $vals[] = $filter_user; }
    if ($filter_stage)                    { $where[] = 'l.stage_id=%d';     $vals[] = $filter_stage; }
    if ($has_revoked && $has_assigned) {
        if ($filter_status === 'revoked')    $where[] = 'l.is_access_revoked=1';
        if ($filter_status === 'unassigned') $where[] = '(l.assigned_to IS NULL AND COALESCE(l.is_access_revoked,0)=0)';
        if ($filter_status === 'assigned')   $where[] = '(l.assigned_to IS NOT NULL AND COALESCE(l.is_access_revoked,0)=0)';
    }

    $allowed_ob  = ['id','lead_date','client_name','work_type','created_at'];
    $ob          = in_array($orderby, $allowed_ob, true) ? 'l.'.$orderby : 'l.created_at';

    $sel_assigned = $has_assigned ? 'l.assigned_to,'                            : 'NULL AS assigned_to,';
    $sel_revoked  = $has_revoked  ? 'COALESCE(l.is_access_revoked,0) AS is_access_revoked,' : '0 AS is_access_revoked,';
    $sel_names    = $has_assigned
        ? 'au.display_name AS assigned_user_name, cu.display_name AS created_by_name'
        : 'NULL AS assigned_user_name, cu.display_name AS created_by_name';
    $join_users   = $has_assigned
        ? "LEFT JOIN {$wpdb->users} au ON au.ID=l.assigned_to LEFT JOIN {$wpdb->users} cu ON cu.ID=l.created_by"
        : "LEFT JOIN {$wpdb->users} cu ON cu.ID=l.created_by";

    $where_sql = implode(' AND ', $where);

    // Count query
    $cnt_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads l WHERE {$where_sql}";
    $total   = empty($vals) ? (int)$wpdb->get_var($cnt_sql) : (int)$wpdb->get_var($wpdb->prepare($cnt_sql, ...$vals));

    // Data query
    $vals_data = $vals; $vals_data[] = $limit; $vals_data[] = $offset;
    $sql = "SELECT l.id, l.client_name, l.client_phone, l.work_type, l.lead_date, l.created_by,
                   l.priority, l.lead_temperature,
                   {$sel_assigned} {$sel_revoked}
                   s.name AS stage_name, s.color AS stage_color,
                   {$sel_names}
            FROM {$wpdb->prefix}lcrm_leads l
            LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id=l.stage_id
            {$join_users}
            WHERE {$where_sql} ORDER BY {$ob} {$order} LIMIT %d OFFSET %d";
    $leads = empty($vals_data) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, ...$vals_data));

    $out = [];
    foreach ((array)$leads as $l) {
        $out[] = [
            'id'                  => $l->id,
            'client_name'         => $l->client_name,
            'client_phone'        => $l->client_phone,
            'work_type'           => $l->work_type,
            'lead_date'           => $l->lead_date,
            'priority'            => $l->priority,
            'stage_name'          => $l->stage_name ?: '',
            'stage_color'         => $l->stage_color ?: '',
            'assigned_to'         => $l->assigned_to,
            'assigned_user_name'  => $l->assigned_user_name ?: '',
            'created_by_name'     => $l->created_by_name ?: '',
            'is_access_revoked'   => intval($l->is_access_revoked),
        ];
    }
    wp_send_json_success(['leads'=>$out,'total'=>$total,'pages'=>max(1,ceil($total/$limit)),'page'=>$page]);
}

/* ================================================================
   LEAD TAGS
   ================================================================ */
add_action('wp_ajax_lcrm_get_tags','lcrm_ajax_get_tags');
function lcrm_ajax_get_tags() {
    lcrm_verify();
    wp_send_json_success(['tags'=>lcrm_get_all_tags()]);
}
add_action('wp_ajax_lcrm_save_tag','lcrm_ajax_save_tag');
function lcrm_ajax_save_tag() {
    lcrm_verify_admin(); global $wpdb;
    $id    = intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $name  = sanitize_text_field(isset($_POST['name']) ? $_POST['name'] : '');
    $color = sanitize_hex_color(isset($_POST['color']) ? $_POST['color'] : '#6366f1') ?: '#6366f1';
    if (!$name) wp_send_json_error(['msg'=>'Tag name required.']);
    if ($id) {
        $wpdb->update("{$wpdb->prefix}lcrm_tags",['name'=>$name,'color'=>$color],['id'=>$id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}lcrm_tags",['name'=>$name,'color'=>$color,'created_at'=>current_time('mysql')]);
        $id = $wpdb->insert_id;
    }
    wp_send_json_success(['tag'=>['id'=>$id,'name'=>$name,'color'=>$color]]);
}
add_action('wp_ajax_lcrm_delete_tag','lcrm_ajax_delete_tag');
function lcrm_ajax_delete_tag() {
    lcrm_verify_admin(); global $wpdb;
    $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $wpdb->delete("{$wpdb->prefix}lcrm_tags",['id'=>$id]);
    $wpdb->delete("{$wpdb->prefix}lcrm_lead_tags",['tag_id'=>$id]);
    wp_send_json_success(['msg'=>'Tag deleted.']);
}
add_action('wp_ajax_lcrm_set_lead_tags','lcrm_ajax_set_lead_tags');
function lcrm_ajax_set_lead_tags() {
    lcrm_verify();
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    $tag_ids = isset($_POST['tag_ids']) ? (array)$_POST['tag_ids'] : [];
    if (!$lead_id) wp_send_json_error(['msg'=>'Lead ID required.']);
    lcrm_set_lead_tags($lead_id, $tag_ids);
    wp_send_json_success(['tags'=>lcrm_get_lead_tags($lead_id)]);
}
add_action('wp_ajax_lcrm_get_lead_tags','lcrm_ajax_get_lead_tags');
function lcrm_ajax_get_lead_tags() {
    lcrm_verify();
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    wp_send_json_success(['tags'=>lcrm_get_lead_tags($lead_id)]);
}

/* ================================================================
   ACTIVITY LOG
   ================================================================ */
add_action('wp_ajax_lcrm_get_activity','lcrm_ajax_get_activity');
function lcrm_ajax_get_activity() {
    lcrm_verify();
    $lead_id = intval(isset($_POST['lead_id']) ? $_POST['lead_id'] : 0);
    if (!$lead_id) wp_send_json_error(['msg'=>'Lead ID required.']);
    wp_send_json_success(['activity'=>lcrm_get_activity($lead_id)]);
}

/* ================================================================
   EMAIL NOTIFICATIONS — Assignment & Reminder
   ================================================================ */
function lcrm_send_assignment_email($lead_id, $assigned_to_user_id, $action = 'assigned') {
    $user = get_userdata($assigned_to_user_id);
    if (!$user || !$user->user_email) return;
    global $wpdb;
    $lead = lcrm_get_lead($lead_id);
    if (!$lead) return;
    $by       = wp_get_current_user();
    $by_name  = $by->display_name ?: 'Administrator';
    $link     = admin_url('admin.php?page=leadcrm-leads');
    $action_label = $action === 'assigned' ? 'assigned to you' : 'reassigned to you';
    $subject  = "[LeadCRM] Lead #{$lead_id} has been {$action_label}";
    $body     = "Hello {$user->display_name},\n\n"
              . "Lead #{$lead_id} ({$lead->client_name}) has been {$action_label} by {$by_name}.\n\n"
              . "Client: {$lead->client_name}\n"
              . "Phone: {$lead->client_phone}\n"
              . "Work Type: {$lead->work_type}\n"
              . "Stage: {$lead->stage_name}\n\n"
              . "View your leads: {$link}\n\n"
              . "— LeadCRM";
    wp_mail($user->user_email, $subject, $body);
}

/* ================================================================
   v4.0 — WA FOLLOW-UP SETTINGS SAVE
   ================================================================ */
add_action('wp_ajax_lcrm_save_followup_settings','lcrm_ajax_save_followup_settings');
function lcrm_ajax_save_followup_settings() {
    lcrm_verify_admin();
    update_option('lcrm_wa_auto_followup',    intval( $_POST['wa_auto_followup']    ?? 0 ));
    update_option('lcrm_wa_followup_template',sanitize_textarea_field( $_POST['wa_followup_template'] ?? '' ));
    wp_send_json_success(['msg'=>'Follow-up settings saved.']);
}
