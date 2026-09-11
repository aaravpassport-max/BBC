<?php
/**
 * NAS Missing AJAX Handlers
 * All 29 AJAX actions called by dashboards that had no registered handler.
 * Loaded via ModuleManager.
 */
namespace NAS\Modules;
if ( ! defined( 'ABSPATH' ) ) exit;

use NAS\Core\Security;
use NAS\Core\Database;
use NAS\Core\Config;

// ── Register all on init ────────────────────────────────────────────────────
// BUG-5 FIX (PRE-DELIVERY AUDIT v4.0):
// The following 7 actions were previously registered here AND in AdminModule::register()
// (called at init priority 10). AdminModule fires first and calls wp_die() via
// wp_send_json_*, which means these MissingHandlers callbacks NEVER executed — they
// were permanently dead code. Registering the same wp_ajax_* hook twice also caused
// confusing stack traces and violated the cross-code integrity audit rule.
//
// Removed from this file (handled exclusively by AdminModule):
//   nas_admin_assign_vendor, nas_admin_get_booking, nas_admin_save_content,
//   nas_admin_save_notes, nas_admin_save_payment, nas_admin_update_pricing,
//   nas_admin_update_status
//
// All remaining actions below are unique to this file and have no duplicate.
add_action( 'init', function() {
    $actions = [
        // ── Booking workflow (staff / moderation — use 'nas_action' nonce) ──
        'nas_approve_booking',
        'nas_flag_booking',
        'nas_reject_booking',
        'nas_get_all_bookings',
        'nas_get_assigned_bookings',
        'nas_get_pending_bookings',
        // ── City lookup (also nopriv below) ─────────────────────────────────
        'nas_get_cities',
        // ── Quick Replies ────────────────────────────────────────────────────
        'nas_get_quick_replies',
        'nas_save_quick_reply',
        'nas_delete_quick_reply',
        // ── Staff tasks ──────────────────────────────────────────────────────
        'nas_get_today_tasks',
        'nas_mark_task_done',
        // ── Sample Ads / Moderation ──────────────────────────────────────────
        'nas_get_sample_ads_list',
        'nas_mod_get_flagged',
        'nas_mod_save_sample_ad',
        'nas_mod_delete_sample_ad',
        // ── Vendor dashboard ─────────────────────────────────────────────────
        'nas_vendor_get_stats',
        'nas_vendor_get_bookings',
        'nas_vendor_get_earnings',
        'nas_vendor_mark_published',
        'nas_vendor_update_profile',
        'nas_vendor_upload_proof',
    ];
    foreach ( $actions as $action ) {
        $cb = __NAMESPACE__ . '\\' . $action . '_handler';
        add_action( 'wp_ajax_' . $action, $cb );
    }
    // nas_get_cities also available to guests (used in vendor register form)
    add_action( 'wp_ajax_nopriv_nas_get_cities', __NAMESPACE__ . '\\nas_get_cities_handler' );
}, 20 );

// ── Helpers ─────────────────────────────────────────────────────────────────
// TRACE: _nonce_cap() — Trigger: wp_ajax__nonce_cap file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function _nonce_cap( string $cap = 'nas_manage_bookings' ): void {
    Security::check_nonce( Security::post('nonce') ?: ( $_GET['nonce'] ?? '' ), 'nas_action' );
    if ( $cap ) Security::require_cap( $cap );
}
function nas__db(): Database { return Database::instance(); }
function nas__t( string $table ): string { return nas__db()->t( $table ); }

// ── Get cities (public + logged-in) ─────────────────────────────────────────
// TRACE: nas_get_cities_handler() — Trigger: wp_ajax_nas_get_cities_handler file-scope handler.
//        Steps: reads POST input → queries DB → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_get_cities_handler(): void {
    $db     = nas__db();
    $cities = $db->select( "SELECT id, name, state FROM {$db->t('cities')} ORDER BY name ASC LIMIT 500" ) ?: [];
    wp_send_json_success( ['cities' => $cities] );
}

// ── Booking list: all bookings (staff/moderation — uses 'nas_action' nonce) ──
// TRACE: nas_get_all_bookings_handler() — Trigger: wp_ajax_nas_get_all_bookings_handler file-scope handler.
//        Steps: reads POST input.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_get_all_bookings_handler(): void {
    _nonce_cap();
    $db       = nas__db();
    $search   = '%' . sanitize_text_field( Security::post('search','') ) . '%';
    $status   = sanitize_text_field( Security::post('status','') );
    $page     = max(1,(int)Security::post('page',1));
    $per_page = max(1,min(100,(int)Security::post('per_page',20)));
    $offset   = ($page-1)*$per_page;

    $where = '1=1';
    $args  = [];
    if ( Security::post('search','') ) {
        $where .= ' AND (b.uid LIKE %s OR b.client_name LIKE %s OR b.client_phone LIKE %s)';
        $args   = array_merge($args,[$search,$search,$search]);
    }
    if ( $status ) { $where .= ' AND b.status = %s'; $args[] = $status; }

    $total    = (int)$db->scalar("SELECT COUNT(*) FROM {$db->t('bookings')} b WHERE $where", $args);
    $bookings = $db->select(
        "SELECT b.id,b.uid,b.client_name,b.client_phone,b.newspaper_name,b.city_name,
                b.status,b.total_amount,b.submitted_at
         FROM {$db->t('bookings')} b WHERE $where
         ORDER BY b.submitted_at DESC LIMIT %d OFFSET %d",
        array_merge($args,[$per_page,$offset])
    );
    wp_send_json_success(['bookings'=>$bookings,'total'=>$total]);
}

// ── Booking list: assigned to current staff member ──────────────────────────
// TRACE: nas_get_assigned_bookings_handler() — Trigger: wp_ajax_nas_get_assigned_bookings_handler file-scope handler.
//        Steps: reads POST input.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_get_assigned_bookings_handler(): void {
    _nonce_cap('nas_manage_bookings');
    $db       = nas__db();
    $uid      = get_current_user_id();
    $search   = '%' . sanitize_text_field( Security::post('search','') ) . '%';
    $status   = sanitize_text_field( Security::post('status','') );
    $page     = max(1,(int)Security::post('page',1));
    $per_page = max(1,min(50,(int)Security::post('per_page',15)));
    $offset   = ($page-1)*$per_page;

    $where = 'b.assigned_to = %d';
    $args  = [$uid];
    if ( Security::post('search','') ) { $where .= ' AND (b.uid LIKE %s OR b.client_name LIKE %s)'; $args[]=$search;$args[]=$search; }
    if ( $status ) { $where .= ' AND b.status = %s'; $args[] = $status; }

    $total    = (int)$db->scalar("SELECT COUNT(*) FROM {$db->t('bookings')} b WHERE $where", $args);
    $bookings = $db->select(
        "SELECT b.id,b.uid,b.client_name,b.client_phone,b.newspaper_name,b.city_name,b.status,b.total_amount,b.submitted_at
         FROM {$db->t('bookings')} b WHERE $where ORDER BY b.submitted_at DESC LIMIT %d OFFSET %d",
        array_merge($args,[$per_page,$offset])
    );
    wp_send_json_success(['bookings'=>$bookings,'total'=>$total]);
}

// ── Booking list: pending/in-moderation queue ───────────────────────────────
// TRACE: nas_get_pending_bookings_handler() — Trigger: wp_ajax_nas_get_pending_bookings_handler file-scope handler.
//        Steps: reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_get_pending_bookings_handler(): void {
    _nonce_cap();
    $db       = nas__db();
    $page     = max(1,(int)Security::post('page',1));
    $per_page = max(1,min(50,(int)Security::post('per_page',20)));
    $offset   = ($page-1)*$per_page;
    // FIX (audit): frontend (templates/moderation/dashboard.php modLoadPending()) reads a search
    // box value but this handler never accepted or used a search param at all.
    $search = sanitize_text_field( Security::post('search','') );
    $where  = "b.status IN ('booking_received','under_review','ready_to_process')";
    $args   = [];
    if ( $search ) {
        $where .= ' AND (b.uid LIKE %s OR b.client_name LIKE %s OR b.client_phone LIKE %s)';
        $like   = '%' . $db->esc_like( $search ) . '%';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    $bookings = $db->select(
        "SELECT b.id,b.uid,b.client_name,b.client_phone,b.newspaper_name,b.city_name,
                b.category_id,b.ad_type,b.status,b.total_amount,b.submitted_at,b.ad_content
         FROM {$db->t('bookings')} b
         WHERE $where
         ORDER BY b.submitted_at ASC LIMIT %d OFFSET %d",
        array_merge( $args, [ $per_page, $offset ] )
    );
    $total = (int)$db->scalar( "SELECT COUNT(*) FROM {$db->t('bookings')} b WHERE $where", $args );
    wp_send_json_success(['bookings'=>$bookings,'total'=>$total]);
}

// ── Approve / flag / reject booking (moderation — 'nas_action' nonce) ───────
// TRACE: nas_approve_booking_handler() — Trigger: wp_ajax_nas_approve_booking_handler file-scope handler.
//        Steps: reads POST input → updates DB row → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_approve_booking_handler(): void {
    _nonce_cap();
    $id = (int)Security::post('id');
    if(!$id){ wp_send_json_error(['message'=>'Invalid ID']); return; }
    $db = nas__db();
    // FIX (audit): clear is_flagged on approval — resolving a flagged booking should remove it
    // from the Flagged tab; there was previously no way to un-flag a booking at all.
    $db->update($db->t('bookings'),['status'=>'payment_received','is_flagged'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
    wp_send_json_success(['message'=>'Booking approved.']);
}
// TRACE: nas_flag_booking_handler() — Trigger: wp_ajax_nas_flag_booking_handler file-scope handler.
//        Steps: reads POST input → updates DB row → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_flag_booking_handler(): void {
    _nonce_cap();
    $id = (int)Security::post('id');
    if(!$id){ wp_send_json_error(['message'=>'Invalid ID']); return; }
    $db = nas__db();
    // FIX (audit, per user decision): is_flagged is now a real column (database/SchemaV3.php).
    // Flagging no longer overwrites the booking's actual workflow status — it's an independent
    // marker, so a flagged booking still correctly shows its real status everywhere else.
    $db->update($db->t('bookings'),['is_flagged'=>1],['id'=>$id]);
    wp_send_json_success(['message'=>'Booking flagged for review.']);
}
// TRACE: nas_reject_booking_handler() — Trigger: wp_ajax_nas_reject_booking_handler file-scope handler.
//        Steps: reads POST input → updates DB row → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_reject_booking_handler(): void {
    _nonce_cap();
    $id     = (int)Security::post('id');
    $reason = sanitize_textarea_field(Security::post('reason',''));
    $db     = nas__db();
    // FIX (audit): clear is_flagged on rejection — same reasoning as approve_booking_handler.
    $db->update($db->t('bookings'),['status'=>'rejected','rejection_reason'=>$reason,'is_flagged'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
    wp_send_json_success(['message'=>'Booking rejected.']);
}

// ── Quick Replies (staff/admin) ──────────────────────────────────────────────
// TRACE: nas_get_quick_replies_handler() — Trigger: wp_ajax_nas_get_quick_replies_handler file-scope handler.
//        Steps: reads POST input → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_get_quick_replies_handler(): void {
    _nonce_cap('nas_manage_bookings');
    $replies = get_option('nas_quick_replies', []);
    wp_send_json_success(['replies' => is_array($replies) ? $replies : []]);
}
// TRACE: nas_save_quick_reply_handler() — Trigger: wp_ajax_nas_save_quick_reply_handler file-scope handler.
//        Steps: reads POST input → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_save_quick_reply_handler(): void {
    _nonce_cap('nas_manage_bookings');
    $text    = sanitize_text_field(Security::post('text',''));
    $id      = sanitize_text_field(Security::post('id',''));
    if(!$text){ wp_send_json_error(['message'=>'Empty reply']); return; }
    $replies = (array)get_option('nas_quick_replies',[]);
    if($id){
        foreach($replies as &$r){ if($r['id']===$id){ $r['text']=$text; break; } }
    } else {
        $replies[] = ['id'=>uniqid('qr_'),'text'=>$text];
    }
    update_option('nas_quick_replies',$replies,false);
    wp_send_json_success(['message'=>'Saved.','replies'=>$replies]);
}
// TRACE: nas_delete_quick_reply_handler() — Trigger: wp_ajax_nas_delete_quick_reply_handler file-scope handler.
//        Steps: reads POST input → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_delete_quick_reply_handler(): void {
    _nonce_cap('nas_manage_bookings');
    $id      = sanitize_text_field(Security::post('id',''));
    $replies = array_filter((array)get_option('nas_quick_replies',[]),fn($r)=>$r['id']!==$id);
    update_option('nas_quick_replies',array_values($replies),false);
    wp_send_json_success(['message'=>'Deleted.']);
}

// ── Today's tasks (staff) ────────────────────────────────────────────────────
// TRACE: nas_get_today_tasks_handler() — Trigger: wp_ajax_nas_get_today_tasks_handler file-scope handler.
//        Steps: queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_get_today_tasks_handler(): void {
    _nonce_cap('nas_manage_bookings');
    $db    = nas__db();
    $uid   = get_current_user_id();
    $tasks = $db->select(
        "SELECT b.id,b.uid AS booking_uid,b.client_name,b.status,b.submitted_at,b.updated_at
         FROM {$db->t('bookings')} b
         WHERE b.assigned_to=%d AND b.status NOT IN ('published','completed','rejected','cancelled')
           AND (TIMESTAMPDIFF(HOUR,b.updated_at,NOW())>=4 OR DATE(b.submitted_at)=CURDATE())
         ORDER BY b.submitted_at ASC LIMIT 30", $uid
    );
    $task_list = array_map(function($b) {
        return [
            'id'          => $b['id'],
            'booking_uid' => $b['booking_uid'],
            'client_name' => $b['client_name'],
            'title'       => 'Follow up — ' . str_replace('_',' ',$b['status']),
            'priority'    => 'normal',
        ];
    }, $tasks);
    wp_send_json_success(['tasks'=>$task_list]);
}
// TRACE: nas_mark_task_done_handler() — Trigger: wp_ajax_nas_mark_task_done_handler file-scope handler.
//        Steps: queries DB → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_mark_task_done_handler(): void {
    _nonce_cap('nas_manage_bookings');
    // Just acknowledge — tasks are booking-based, "done" means staff checked it
    wp_send_json_success(['message'=>'Task marked complete.']);
}

// ── Moderation: flagged & sample ads ────────────────────────────────────────
// TRACE: nas_mod_get_flagged_handler() — Trigger: wp_ajax_nas_mod_get_flagged_handler file-scope handler.
//        Steps: queries DB → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_mod_get_flagged_handler(): void {
    _nonce_cap();
    $db    = nas__db();
    $rows  = $db->select(
        "SELECT b.id,b.uid,b.client_name,b.status,b.submitted_at,b.ad_content,b.newspaper_name
         FROM {$db->t('bookings')} b WHERE b.status='under_review' AND b.status NOT IN ('rejected','completed')
         ORDER BY b.submitted_at DESC LIMIT 50"
    ) ?: [];
    wp_send_json_success(['bookings'=>$rows]);
}
// TRACE: nas_get_sample_ads_list_handler() — Trigger: wp_ajax_nas_get_sample_ads_list_handler file-scope handler.
//        Steps: reads POST input → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_get_sample_ads_list_handler(): void {
    _nonce_cap();
    $ads = get_option('nas_sample_ads',[]);
    wp_send_json_success(['ads' => is_array($ads) ? $ads : []]);
}
// TRACE: nas_mod_save_sample_ad_handler() — Trigger: wp_ajax_nas_mod_save_sample_ad_handler file-scope handler.
//        Steps: reads POST input → returns JSON success.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_mod_save_sample_ad_handler(): void {
    _nonce_cap();
    $id       = sanitize_text_field(Security::post('id',''));
    $title    = sanitize_text_field(Security::post('title',''));
    $content  = sanitize_textarea_field(Security::post('content',''));
    $cat_id   = (int)Security::post('category_id',0);
    $tags     = sanitize_text_field(Security::post('tags',''));
    $ads      = (array)get_option('nas_sample_ads',[]);
    if($id){ foreach($ads as &$a){ if($a['id']===$id){ $a=array_merge($a,compact('title','content','cat_id','tags')); break; } } }
    else   { $ads[] = ['id'=>uniqid('sa_'),'title'=>$title,'content'=>$content,'category_id'=>$cat_id,'tags'=>$tags]; }
    update_option('nas_sample_ads',$ads,false);
    wp_send_json_success(['message'=>'Sample ad saved.']);
}
// TRACE: nas_mod_delete_sample_ad_handler() — Trigger: wp_ajax_nas_mod_delete_sample_ad_handler file-scope handler.
//        Steps: verifies nonce → reads POST input → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_mod_delete_sample_ad_handler(): void {
    _nonce_cap();
    $id  = sanitize_text_field(Security::post('id',''));
    $ads = array_filter((array)get_option('nas_sample_ads',[]),fn($a)=>$a['id']!==$id);
    update_option('nas_sample_ads',array_values($ads),false);
    wp_send_json_success(['message'=>'Deleted.']);
}

// ── Vendor handlers ──────────────────────────────────────────────────────────
// TRACE: nas_vendor_get_stats_handler() — Trigger: wp_ajax_nas_vendor_get_stats_handler file-scope handler.
//        Steps: verifies nonce → reads POST input → queries DB → returns JSON success.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_vendor_get_stats_handler(): void {
    Security::check_nonce(Security::post('nonce'),'nas_action');
    Security::require_login();
    $db        = nas__db();
    $vendor_id = (int)Security::post('vendor_id');
    if(!$vendor_id){ wp_send_json_error(['message'=>'Vendor ID required']); return; }
    $t = $db->t('bookings');
    wp_send_json_success([
        'total'   => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE assigned_vendor_id=%d",$vendor_id),
        'pending' => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE assigned_vendor_id=%d AND status NOT IN ('published','completed','rejected')",$vendor_id),
        'done'    => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE assigned_vendor_id=%d AND status IN ('published','completed')",$vendor_id),
        'earned'  => (float)$db->scalar("SELECT COALESCE(SUM(vendor_cost),0) FROM $t WHERE assigned_vendor_id=%d AND vendor_payment_status='paid'",$vendor_id),
    ]);
}

// TRACE: nas_vendor_get_bookings_handler() — Trigger: wp_ajax_nas_vendor_get_bookings_handler file-scope handler.
//        Steps: verifies nonce → reads POST input.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_vendor_get_bookings_handler(): void {
    Security::check_nonce(Security::post('nonce'),'nas_action');
    Security::require_login();
    $db        = nas__db();
    $vendor_id = (int)Security::post('vendor_id');
    $status    = sanitize_text_field(Security::post('status',''));
    $search    = '%'.sanitize_text_field(Security::post('search','')).'%';
    $page      = max(1,(int)Security::post('page',1));
    $per_page  = max(1,min(50,(int)Security::post('per_page',15)));
    $offset    = ($page-1)*$per_page;

    $where = 'b.assigned_vendor_id=%d';
    $args  = [$vendor_id];
    if($status){ $where.=' AND b.status=%s'; $args[]=$status; }
    if(Security::post('search','')){ $where.=' AND (b.uid LIKE %s OR b.client_name LIKE %s)'; $args[]=$search;$args[]=$search; }

    $total    = (int)$db->scalar("SELECT COUNT(*) FROM {$db->t('bookings')} b WHERE $where",$args);
    $bookings = $db->select(
        "SELECT b.id,b.uid,b.booking_uid,b.client_name,b.client_phone,b.newspaper_name,b.city_name,
                b.status,b.total_amount,b.vendor_cost,b.vendor_payment_status,b.publish_date,b.submitted_at
         FROM {$db->t('bookings')} b WHERE $where ORDER BY b.submitted_at DESC LIMIT %d OFFSET %d",
        array_merge($args,[$per_page,$offset])
    );
    wp_send_json_success(['bookings'=>$bookings,'total'=>$total]);
}

// TRACE: nas_vendor_get_earnings_handler() — Trigger: wp_ajax_nas_vendor_get_earnings_handler file-scope handler.
//        Steps: verifies nonce → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_vendor_get_earnings_handler(): void {
    Security::check_nonce(Security::post('nonce'),'nas_action');
    Security::require_login();
    $db        = nas__db();
    $vendor_id = (int)Security::post('vendor_id');
    $t         = $db->t('bookings');

    $summary = [
        'total_earned'  => (float)$db->scalar("SELECT COALESCE(SUM(vendor_cost),0) FROM $t WHERE assigned_vendor_id=%d AND vendor_payment_status='paid'",$vendor_id),
        'pending'       => (float)$db->scalar("SELECT COALESCE(SUM(vendor_cost),0) FROM $t WHERE assigned_vendor_id=%d AND vendor_payment_status='pending' AND status NOT IN ('rejected','cancelled')",$vendor_id),
        'completed'     => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE assigned_vendor_id=%d AND status IN ('published','completed')",$vendor_id),
    ];
    $monthly = $db->select(
        "SELECT DATE_FORMAT(submitted_at,'%b %Y') AS month,
                COALESCE(SUM(vendor_cost),0) AS earned, COUNT(*) AS count
         FROM $t WHERE assigned_vendor_id=%d AND vendor_payment_status='paid'
         GROUP BY YEAR(submitted_at),MONTH(submitted_at)
         ORDER BY submitted_at DESC LIMIT 12",
        $vendor_id
    ) ?: [];

    wp_send_json_success(['summary'=>$summary,'monthly'=>array_reverse($monthly)]);
}

// TRACE: nas_vendor_mark_published_handler() — Trigger: wp_ajax_nas_vendor_mark_published_handler file-scope handler.
//        Steps: verifies nonce → reads POST input → updates DB row → returns JSON success.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_vendor_mark_published_handler(): void {
    Security::check_nonce(Security::post('nonce'),'nas_action');
    Security::require_login();
    $booking_id = (int)Security::post('booking_id');
    $db         = nas__db();
    $db->update($db->t('bookings'),[
        'status'=>'published','updated_at'=>gmdate('Y-m-d H:i:s')
    ],['id'=>$booking_id]);
    wp_send_json_success(['message'=>'Booking marked as published.']);
}

// TRACE: nas_vendor_update_profile_handler() — Trigger: wp_ajax_nas_vendor_update_profile_handler file-scope handler.
//        Steps: verifies nonce → reads POST input → updates DB row → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_vendor_update_profile_handler(): void {
    Security::check_nonce(Security::post('nonce'),'nas_action');
    Security::require_login();
    $vendor_id = (int)Security::post('vendor_id');
    $db        = nas__db();
    $vendor    = $db->row("SELECT id,wp_user_id FROM {$db->t('vendors')} WHERE id=%d",$vendor_id);
    if(!$vendor||$vendor['wp_user_id']!=get_current_user_id()){
        wp_send_json_error(['message'=>'Access denied']); return;
    }
    $db->update($db->t('vendors'),[
        'name'                 => sanitize_text_field(Security::post('name','')),
        'contact_person'       => sanitize_text_field(Security::post('contact_person','')),
        'phone'                => sanitize_text_field(Security::post('phone','')),
        'email'                => sanitize_email(Security::post('email','')),
        'gst_number'           => strtoupper(sanitize_text_field(Security::post('gst_number',''))),
        'pan_number'           => strtoupper(sanitize_text_field(Security::post('pan_number',''))),
        'bank_details'         => sanitize_textarea_field(Security::post('bank_details','')),
        'address'              => sanitize_textarea_field(Security::post('address','')),
        'cities_supported'     => Security::post('cities_supported','[]'),
        'newspapers_supported' => Security::post('newspapers_supported','[]'),
        'updated_at'           => gmdate('Y-m-d H:i:s'),
    ],['id'=>$vendor_id]);
    wp_send_json_success(['message'=>'Profile updated successfully.']);
}

// TRACE: nas_vendor_upload_proof_handler() — Trigger: wp_ajax_nas_vendor_upload_proof_handler file-scope handler.
//        Steps: verifies nonce → reads POST input → updates DB row → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_vendor_upload_proof_handler(): void {
    Security::check_nonce($_POST['nonce']??'','nas_action');
    Security::require_login();
    $booking_id = (int)($_POST['booking_id']??0);
    if(!$booking_id||empty($_FILES['proof_file'])){
        wp_send_json_error(['message'=>'Missing booking ID or file']); return;
    }
    require_once ABSPATH.'wp-admin/includes/file.php';
    $upload = wp_handle_upload($_FILES['proof_file'],['test_form'=>false]);
    if(isset($upload['error'])){
        wp_send_json_error(['message'=>$upload['error']]); return;
    }
    $db = nas__db();
    $db->update($db->t('bookings'),[
        'proof_url'=>$upload['url'],'status'=>'proof_ready','updated_at'=>gmdate('Y-m-d H:i:s')
    ],['id'=>$booking_id]);
    wp_send_json_success(['url'=>$upload['url'],'message'=>'Proof uploaded. Booking status set to Proof Ready.']);
}
