<?php
if (!defined('ABSPATH')) exit;

function lcrm_register_roles() {
    $roles = [
        'lcrm_staff'       => ['CRM Staff',        ['read'=>true,'lcrm_view_leads'=>true,'lcrm_edit_leads'=>true,'lcrm_add_leads'=>true]],
        'lcrm_team_leader' => ['CRM Team Leader',   ['read'=>true,'lcrm_view_leads'=>true,'lcrm_edit_leads'=>true,'lcrm_add_leads'=>true,'lcrm_delete_leads'=>true,'lcrm_view_reports'=>true]],
        'lcrm_manager'     => ['CRM Manager',       ['read'=>true,'lcrm_view_leads'=>true,'lcrm_edit_leads'=>true,'lcrm_add_leads'=>true,'lcrm_delete_leads'=>true,'lcrm_view_reports'=>true,'lcrm_manage_settings'=>true,'lcrm_view_vendor_data'=>true,'lcrm_view_financial'=>true]],
        'lcrm_vendor'      => ['CRM Vendor',        ['read'=>true,'lcrm_view_leads'=>true]],
    ];
    foreach ($roles as $slug => $r) {
        if (!get_role($slug)) add_role($slug, $r[0], $r[1]);
    }
    $admin = get_role('administrator');
    if ($admin) {
        foreach (['lcrm_view_leads','lcrm_edit_leads','lcrm_add_leads','lcrm_delete_leads','lcrm_manage_settings','lcrm_view_reports','lcrm_view_vendor_data','lcrm_view_financial'] as $cap)
            $admin->add_cap($cap);
    }
}

function lcrm_current_can($cap) {
    return current_user_can($cap) || current_user_can('manage_options');
}
function lcrm_can_see_vendor()    { return current_user_can('lcrm_view_vendor_data') || current_user_can('manage_options'); }
function lcrm_can_see_financial() { return current_user_can('lcrm_view_financial')   || current_user_can('manage_options'); }
