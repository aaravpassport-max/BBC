<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Section 9 — "no scheduled/emailed reports anywhere in
 * the platform"). Every metric an owner would want week-over-week already
 * lives in the admin dashboard, but only if someone remembers to log in and
 * look — there was no push mechanism at all. This generates a plain-text
 * weekly ops summary (new orders, completions, revenue, pending complaints,
 * pending vendor KYC) and emails it to the configured admin recipient, on
 * the same wp_mail() path NotificationService already uses so deliverability
 * behaves identically to every other outbound email this plugin sends.
 */
class OpsReportService
{
    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    /**
     * Recipient resolution mirrors SettingsController's 'notifications' tab
     * (rtoflow_admin_email) — the field already exists there but nothing
     * ever consumed it; this is the first real reader of that setting.
     */
    private function recipient(): string
    {
        $configured = get_option('rtoflow_admin_email', '');
        return $configured ?: get_option('admin_email');
    }

    public function runWeekly(): bool
    {
        $to = $this->recipient();
        if (!$to) {
            error_log('RTOFLOW OpsReportService: no admin recipient configured (rtoflow_admin_email / admin_email both empty) — weekly report not sent.');
            return false;
        }

        $since = date('Y-m-d H:i:s', strtotime('-7 days'));

        $newLeads = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_leads WHERE created_at >= %s AND deleted_at IS NULL", $since
        ));
        $completed = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_leads WHERE status='completed' AND updated_at >= %s AND deleted_at IS NULL", $since
        ));
        $revenue = (float)$this->db->get_var($this->db->prepare(
            "SELECT COALESCE(SUM(paid_amount),0) FROM {$this->p}rto_leads WHERE updated_at >= %s AND deleted_at IS NULL", $since
        ));
        $openComplaints = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->p}rto_complaints WHERE status IN ('open','under_review')"
        );
        $pendingKyc = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->p}rto_vendor_documents WHERE status='pending'"
        );
        $activeVendors = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->p}rto_vendors WHERE status='active'"
        );

        $company = get_option('rtoflow_company_name', 'RTOFLOW');
        $subject = "[{$company}] Weekly Ops Summary — " . date('d M Y');

        $body  = "Weekly summary for the 7 days ending " . date('d M Y') . "\n\n";
        $body .= "New orders:          {$newLeads}\n";
        $body .= "Completed orders:    {$completed}\n";
        $body .= "Revenue collected:   " . rto_format_inr($revenue) . "\n";
        $body .= "Open complaints:     {$openComplaints}\n";
        $body .= "Pending vendor KYC:  {$pendingKyc}\n";
        $body .= "Active vendors:      {$activeVendors}\n\n";
        $body .= "View the full dashboard: " . home_url('/rto-admin/') . "\n";

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $company . ' <' . \RTOFLOW\Config\Env::string('MAIL_FROM_ADDRESS', get_option('admin_email')) . '>',
        ];

        $sent = wp_mail($to, $subject, $body, $headers);

        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): wp_mail()'s return value is captured
        // and logged on failure rather than discarded — a silently-failing
        // report is worse than no report, since an admin who never
        // configured this would at least know to check logs.
        if (!$sent) {
            error_log("RTOFLOW OpsReportService: wp_mail() returned false sending weekly report to {$to}.");
        }
        return $sent;
    }
}
