<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

class EmailTemplateController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $channel = Sanitiser::text($_GET['channel'] ?? '');
        $where   = ['1=1']; $params = [];
        if ($channel) { $where[] = 'channel=%s'; $params[] = $channel; }
        $ws = implode(' AND ', $where);

        if (empty($params)) {
            $templates = $this->db->get_results(
                "SELECT * FROM {$this->p}rto_notification_templates WHERE {$ws} ORDER BY channel, slug", ARRAY_A
            ) ?: [];
        } else {
            $templates = $this->db->get_results(
                $this->db->prepare("SELECT * FROM {$this->p}rto_notification_templates WHERE {$ws} ORDER BY channel, slug", $params), ARRAY_A
            ) ?: [];
        }

        rto_view('admin.email-templates.index', compact('templates', 'channel'));
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    public function edit(int $id): void
    {
        $template = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->p}rto_notification_templates WHERE id=%d", $id), ARRAY_A
        );
        if (!$template) wp_die('Template not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        $vars = $this->getAvailableVars($template['slug']);
        rto_view('admin.email-templates.edit', compact('template', 'vars'));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(int $id): void
    {
        if (!check_admin_referer('rtoflow_template_save', 'rtoflow_nonce')) {
            wp_die('Security check failed.', 'Error', ['response' => 403]);
        }

        $subject   = Sanitiser::text($_POST['subject']          ?? '', 300);
        $body      = sanitize_textarea_field($_POST['body']      ?? '');
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $dltId     = Sanitiser::text($_POST['dlt_template_id']  ?? '');

        $before = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_notification_templates WHERE id=%d", $id), ARRAY_A);
        if (!$before) wp_die('Template not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        // ENTERPRISE GAP FIX (Section 9 — "no email/SMS/WhatsApp template
        // variable validation anywhere"): NotificationService::interpolate()
        // only replaces variables it recognises — any {typo'd_var} an admin
        // enters here was previously saved without complaint and then sent
        // to real clients/vendors VERBATIM, literal curly braces and all,
        // forever, until someone noticed and re-edited it. Root cause: this
        // save path never checked the entered text against the same
        // getAvailableVars() list the edit screen already shows the admin.
        // Now rejected before it ever reaches the database, with the exact
        // unrecognised token(s) named back to the admin.
        $vars = $this->getAvailableVars($before['slug']);
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $subject . ' ' . $body, $matches);
        $unknown = array_values(array_unique(array_diff($matches[0] ?? [], $vars)));

        if (!empty($unknown)) {
            $template = array_merge($before, [
                'subject' => $subject, 'body' => $body, 'is_active' => $isActive, 'dlt_template_id' => $dltId,
            ]);
            $templateError = 'Unrecognised variable(s) ' . implode(', ', $unknown) . ' — this template was NOT saved. Use only the variables listed below.';
            rto_view('admin.email-templates.edit', compact('template', 'vars', 'templateError'));
            return;
        }

        $updated = $this->db->update(
            $this->p . 'rto_notification_templates',
            ['subject' => $subject, 'body' => $body, 'is_active' => $isActive, 'dlt_template_id' => $dltId, 'updated_at' => current_time('mysql')],
            ['id' => $id]
        );
        if ($updated === false) {
            $template = array_merge($before, [
                'subject' => $subject, 'body' => $body, 'is_active' => $isActive, 'dlt_template_id' => $dltId,
            ]);
            $templateError = 'Could not save the template — a database error occurred. Please try again.';
            rto_view('admin.email-templates.edit', compact('template', 'vars', 'templateError'));
            return;
        }

        AuditService::log('email_template.updated', null, [
            'id' => $id, 'subject' => $subject, 'is_active' => $isActive, 'dlt_template_id' => $dltId,
        ], $before);

        // ENTERPRISE GAP FIX (Phase 4, item 8 — "no versioning/rollback for
        // ... Email Templates ..."): keyed per-template id, same reasoning
        // as CityServiceConfigController's per-city keying — an admin
        // rolling back "the welcome email" should only touch that one
        // template, not every template in the system as one shared blob.
        $after = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_notification_templates WHERE id=%d", $id), ARRAY_A);
        $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft(
            'email_template_' . $id, $after, get_current_user_id() ?: 0, 'Template updated'
        );
        if ($versionId) {
            \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
        }

        wp_redirect(home_url('/rto-admin/email-templates/?saved=1'));
        exit;
    }

    // ── Rollback target: called from Bootstrap.php's
    // 'rtoflow_config_published' listener when config_key matches
    // 'email_template_{id}' (the "activate an older version" path).
    public function applyVersionedPayload(int $id, array $payload): void
    {
        unset($payload['id']);
        $payload['updated_at'] = current_time('mysql');
        $this->db->update($this->p . 'rto_notification_templates', $payload, ['id' => $id]);
        AuditService::log('email_template.version_restored', null, ['id' => $id]);
    }

    // ── Send test ─────────────────────────────────────────────────────────────

    public function sendTest(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id      = Sanitiser::int($_POST['template_id'] ?? 0, 1);
        $email   = Sanitiser::email($_POST['test_email'] ?? '');

        if (!$email) rto_json_err('Please enter a valid email address.');

        $tpl = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->p}rto_notification_templates WHERE id=%d", $id), ARRAY_A
        );
        if (!$tpl) rto_json_err('Template not found.');

        // Replace vars with sample data
        $vars = [
            'lead_number'   => 'RTO-2024-000001',
            'client_name'   => 'Sample Client',
            'vendor_name'   => 'Sample Vendor',
            'service_name'  => 'RC Transfer (Same State)',
            'city'          => 'Mumbai (Central)',
            'status'        => 'In Progress',
            'amount'        => '₹2,500.00',
            'sla_deadline'  => date('d M Y', strtotime('+7 days')),
            'company_name'  => get_option('rtoflow_company_name', 'RTOFLOW OS'),
            'site_url'      => home_url(),
            'dashboard_url' => home_url('/rto-dashboard/'),
        ];
        $subject = $tpl['subject'];
        $body    = $tpl['body'];
        foreach ($vars as $k => $v) {
            $subject = str_replace('{' . $k . '}', $v, $subject);
            $body    = str_replace('{' . $k . '}', $v, $body);
        }

        $sent = wp_mail(
            $email,
            '[TEST] ' . $subject,
            nl2br(esc_html($body)),
            ['Content-Type: text/html; charset=UTF-8', 'From: ' . get_option('rtoflow_company_name', 'RTOFLOW') . ' <' . get_option('admin_email') . '>']
        );

        if ($sent) {
            rto_json_ok(null, "Test email sent to {$email}.");
        } else {
            rto_json_err('Failed to send test email. Check WordPress mail configuration.');
        }
    }

    // ── AJAX: test-send an SMS or WhatsApp template ─────────────────────────
    // Known Limitations audit fix: "No test-send capability exists for SMS
    // or WhatsApp templates ... an admin editing an SMS or WhatsApp template
    // has no way to see how it will actually render with real variable
    // substitution before it reaches a genuine customer." Both real provider
    // integrations already exist and are already used for live sends
    // (integrations/Sms.php's RTOFLOW_SMS::send(), integrations/WhatsApp.php's
    // RTOFLOW_WhatsApp::send()) — this was a genuinely missing admin-facing
    // wrapper around functionality that already worked, not a new provider
    // integration. Uses the exact same sample-variable substitution as the
    // existing email sendTest() so all three channels preview identically.
    public function sendTestMessage(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id     = Sanitiser::int($_POST['template_id'] ?? 0, 1);
        $mobile = Sanitiser::mobile($_POST['test_mobile'] ?? '');

        if (!$mobile) rto_json_err('Please enter a valid 10-digit mobile number.');

        $tpl = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->p}rto_notification_templates WHERE id=%d", $id), ARRAY_A
        );
        if (!$tpl) rto_json_err('Template not found.');
        if (!in_array($tpl['channel'], ['sms', 'whatsapp'], true)) {
            rto_json_err('This action is only for SMS/WhatsApp templates — use Test above for email templates.');
        }

        // Same sample data as EmailTemplateController::sendTest(), so a
        // preview looks identical regardless of channel.
        $vars = [
            'lead_number'   => 'RTO-2024-000001',
            'client_name'   => 'Sample Client',
            'vendor_name'   => 'Sample Vendor',
            'service_name'  => 'RC Transfer (Same State)',
            'city'          => 'Mumbai (Central)',
            'status'        => 'In Progress',
            'amount'        => '₹2,500.00',
            'sla_deadline'  => date('d M Y', strtotime('+7 days')),
            'company_name'  => get_option('rtoflow_company_name', 'RTOFLOW OS'),
            'site_url'      => home_url(),
            'dashboard_url' => home_url('/rto-dashboard/'),
        ];
        $body = $tpl['body'];
        foreach ($vars as $k => $v) {
            $body = str_replace('{' . $k . '}', $v, $body);
        }
        $body = '[TEST] ' . $body;

        if (!class_exists('RTOFLOW_SMS')) require_once RTOFLOW_DIR . 'integrations/Sms.php';
        if (!class_exists('RTOFLOW_WhatsApp')) require_once RTOFLOW_DIR . 'integrations/WhatsApp.php';

        $sent = $tpl['channel'] === 'sms'
            ? \RTOFLOW_SMS::send($mobile, $body, $tpl['dlt_template_id'] ?? '')
            : \RTOFLOW_WhatsApp::send($mobile, $body);

        if ($sent) {
            rto_json_ok(null, ucfirst($tpl['channel']) . " test sent to {$mobile}.");
        } else {
            $reason = $tpl['channel'] === 'sms'
                ? 'Check that SMS is enabled and an API key is configured under Settings.'
                : 'Check that WhatsApp is enabled and a phone ID/token is configured under Settings.';
            rto_json_err("Failed to send test {$tpl['channel']}. {$reason}");
        }
    }

    // ── Available variables per template ──────────────────────────────────────

    private function getAvailableVars(string $slug): array
    {
        $common = ['{lead_number}', '{client_name}', '{service_name}', '{city}', '{status}', '{company_name}', '{dashboard_url}', '{site_url}'];
        return match(true) {
            str_contains($slug, 'vendor') => array_merge($common, ['{vendor_name}', '{vendor_amount}']),
            str_contains($slug, 'payment') => array_merge($common, ['{amount}', '{txn_id}', '{payment_date}']),
            str_contains($slug, 'sla')    => array_merge($common, ['{sla_deadline}', '{days_remaining}']),
            default => $common,
        };
    }
}
