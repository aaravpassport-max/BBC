#!/usr/bin/env python3
"""Apply Lead CRM audit fixes (navigation, settings sync, multi work type)."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def patch(path, old, new, count=1):
    p = ROOT / path
    s = p.read_text()
    if old not in s:
        raise SystemExit(f"MISSING in {path}: {old[:80]!r}")
    p.write_text(s.replace(old, new, count))

# --- ajax.php: lookup sync + get_lookups + dropdown bust + export + quick_update wt ---
patch('includes/ajax.php', """function lcrm_bust_dropdown_cache() {
    delete_transient('lcrm_cache_stages');
    delete_transient('lcrm_cache_vendors');
    delete_transient('lcrm_cache_brands');
    delete_transient('lcrm_cache_vendor_cats');
    delete_transient('lcrm_cache_work_types');
    delete_transient('lcrm_cache_lead_sources');
    delete_transient('lcrm_cache_remarks');
}
""", """function lcrm_bust_dropdown_cache() {
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
""")

patch('includes/ajax.php',
    "'work_type'=>$l->work_type,'lead_source'=>$l->lead_source,'stage_id'=>$l->stage_id,",
    "'work_type'=>$l->work_type,'work_types'=>lcrm_work_types_decode($l->work_type),'work_type_display'=>lcrm_work_types_display($l->work_type),'lead_source'=>$l->lead_source,'stage_id'=>$l->stage_id,")

patch('includes/ajax.php',
    "    if(!in_array($field,$allowed,true)) wp_send_json_error(['msg'=>'Invalid field.']);\n    if($field==='work_status') $value=lcrm_clean_work_status($value);",
    "    if(!in_array($field,$allowed,true)) wp_send_json_error(['msg'=>'Invalid field.']);\n    if($field==='work_status') $value=lcrm_clean_work_status($value);\n    if($field==='work_type') $value=lcrm_work_types_encode(lcrm_work_types_decode($value));")

patch('includes/ajax.php',
    "    lcrm_bust_dropdown_cache();\n    wp_send_json_success(['msg'=>'Stage saved.",
    "    lcrm_after_lookup_change();\n    wp_send_json_success(['msg'=>'Stage saved.")

patch('includes/ajax.php',
    "    else{$max=(int)$wpdb->get_var(\"SELECT MAX(sort_order) FROM $tbl\")+1;$wpdb->insert($tbl,['name'=>$name,'sort_order'=>$max,'is_active'=>1]);}\n    wp_send_json_success(['msg'=>'Saved.','id'=>$id?:$wpdb->insert_id]);",
    "    else{$max=(int)$wpdb->get_var(\"SELECT MAX(sort_order) FROM $tbl\")+1;$wpdb->insert($tbl,['name'=>$name,'sort_order'=>$max,'is_active'=>1]);}\n    lcrm_after_lookup_change();\n    wp_send_json_success(['msg'=>'Saved.','id'=>$id?:$wpdb->insert_id]);")

patch('includes/ajax.php',
    "    $wpdb->delete($wpdb->prefix.$table_map[$type],['id'=>intval(isset($_POST['id']) ? $_POST['id'] : 0)]);\n    wp_send_json_success(['msg'=>'Deleted.']);",
    "    $wpdb->delete($wpdb->prefix.$table_map[$type],['id'=>intval(isset($_POST['id']) ? $_POST['id'] : 0)]);\n    lcrm_after_lookup_change();\n    wp_send_json_success(['msg'=>'Deleted.']);")

patch('includes/ajax.php',
    "function lcrm_ajax_export_csv() {\n    lcrm_verify();\n    lcrm_export_csv(['stage_id'=>sanitize_text_field((isset($_GET['stage_id']) ? $_GET['stage_id'] : '')),'search'=>sanitize_text_field((isset($_GET['search']) ? $_GET['search'] : '')),'date_from'=>sanitize_text_field((isset($_GET['date_from']) ? $_GET['date_from'] : '')),'date_to'=>sanitize_text_field((isset($_GET['date_to']) ? $_GET['date_to'] : ''))]);\n}",
    """function lcrm_ajax_export_csv() {
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
}""")

# vendor/brand/remark saves -> section bust
for old in [
    "    lcrm_bust_dropdown_cache();\n    wp_send_json_success(['msg'=>'Vendor saved.",
    "    lcrm_bust_dropdown_cache();\n    wp_send_json_success(['msg'=>'Deleted.']);\n}\n\n/*  DROPDOWNS",
]:
    pass

patch('includes/ajax.php', "    lcrm_bust_dropdown_cache();\n    wp_send_json_success(['msg'=>'Vendor saved.", "    lcrm_after_lookup_change();\n    wp_send_json_success(['msg'=>'Vendor saved.")

patch('includes/ajax.php',
    "    $wpdb->delete(\"{$wpdb->prefix}lcrm_vendors\",['id'=>$id]);\n    lcrm_bust_dropdown_cache();\n    wp_send_json_success(['msg'=>'Deleted.']);\n}\n\n/*  DROPDOWNS",
    "    $wpdb->delete(\"{$wpdb->prefix}lcrm_vendors\",['id'=>$id]);\n    lcrm_after_lookup_change();\n    wp_send_json_success(['msg'=>'Deleted.']);\n}\n\n/*  DROPDOWNS")

patch('includes/ajax.php',
    "    if(!$lead) wp_send_json_error(['msg'=>'Lead not found.'],404);\n    $activity=lcrm_get_activity($id);",
    "    if(!$lead) wp_send_json_error(['msg'=>'Lead not found.'],404);\n    $lead->work_types = lcrm_work_types_decode($lead->work_type);\n    $lead->work_type_display = lcrm_work_types_display($lead->work_type);\n    $activity=lcrm_get_activity($id);")

print('ajax.php patched')
