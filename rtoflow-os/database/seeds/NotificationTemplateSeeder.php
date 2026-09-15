<?php

if (!defined('ABSPATH')) exit;

/**
 * Notification Template Seeder
 *
 * Seeds all required notification templates for email, SMS, and WhatsApp.
 * These templates power all automated notifications in the platform.
 *
 * Template variable format: {variable_name}
 *
 * Available variables per event:
 * - lead.*: {lead_number}, {service_name}, {city_name}, {client_name},
 *           {vendor_name}, {status_label}, {sla_deadline}, {amount}
 * - payment.*: {payment_amount}, {txn_id}, {payment_method}
 * - document.*: {doc_type}, {reject_reason}
 * - complaint.*: {complaint_number}, {subject}
 */
class RTOFLOW_NotificationTemplateSeeder
{
    public function run(): void
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'rto_notification_templates';
        $company = get_option('rtoflow_company_name', 'RTOFLOW');

        $templates = $this->getTemplates($company);

        foreach ($templates as $tpl) {
            // Use INSERT IGNORE to avoid re-seeding duplicates
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s AND channel = %s",
                $tpl['slug'], $tpl['channel']
            ));

            if (!$existing) {
                $wpdb->insert($table, [
                    'slug'      => $tpl['slug'],
                    'channel'   => $tpl['channel'],
                    'subject'   => $tpl['subject'] ?? null,
                    'body'      => $tpl['body'],
                    'variables' => wp_json_encode($tpl['vars'] ?? []),
                    'is_active' => 1,
                ]);
            }
        }
    }

    private function getTemplates(string $company): array
    {
        return [

            // ── Lead Created ──────────────────────────────────────────────
            [
                'slug'    => 'lead_created',
                'channel' => 'email',
                'subject' => "Your {$company} Service Request #{lead_number} Received",
                'body'    => $this->emailWrap("
<h2>Service Request Received ✓</h2>
<p>Dear {client_name},</p>
<p>We have received your request for <strong>{service_name}</strong> in <strong>{city_name}</strong>.</p>
<table style='border-collapse:collapse;width:100%'>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Order Number</strong></td><td style='padding:8px;border:1px solid #ddd'>{lead_number}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Service</strong></td><td style='padding:8px;border:1px solid #ddd'>{service_name}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>City</strong></td><td style='padding:8px;border:1px solid #ddd'>{city_name}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Amount</strong></td><td style='padding:8px;border:1px solid #ddd'>₹{amount}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>SLA Deadline</strong></td><td style='padding:8px;border:1px solid #ddd'>{sla_deadline}</td></tr>
</table>
<p style='margin-top:20px'>Our team will review your request and get in touch shortly. You can track your request status using your order number at any time.</p>
<p><a href='{portal_url}' style='background:#1E3A5F;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Track Your Request</a></p>
                ", $company),
                'vars'    => ['lead_number', 'service_name', 'city_name', 'client_name', 'amount', 'sla_deadline', 'portal_url'],
            ],
            [
                'slug'    => 'lead_created',
                'channel' => 'sms',
                'body'    => "Hi {client_name}, your {$company} request {lead_number} for {service_name} has been received. Track at {tracking_url}",
                'vars'    => ['client_name', 'lead_number', 'service_name', 'tracking_url'],
            ],
            [
                'slug'    => 'lead_created',
                'channel' => 'whatsapp',
                'body'    => "Hello {client_name}! 🎉 Your RTO service request *{lead_number}* has been received.\n\n*Service:* {service_name}\n*City:* {city_name}\n*Amount:* ₹{amount}\n*SLA:* {sla_deadline}\n\nWe'll update you as we process your request.",
                'vars'    => ['client_name', 'lead_number', 'service_name', 'city_name', 'amount', 'sla_deadline'],
            ],

            // ── Payment Received ──────────────────────────────────────────
            [
                'slug'    => 'payment_received',
                'channel' => 'email',
                'subject' => "Payment Confirmed — {$company} #{lead_number}",
                'body'    => $this->emailWrap("
<h2>Payment Received ✓</h2>
<p>Dear {client_name},</p>
<p>We have received your payment of <strong>₹{payment_amount}</strong> for order <strong>{lead_number}</strong>.</p>
<table style='border-collapse:collapse;width:100%'>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Order Number</strong></td><td style='padding:8px;border:1px solid #ddd'>{lead_number}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Amount Paid</strong></td><td style='padding:8px;border:1px solid #ddd'>₹{payment_amount}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Payment Method</strong></td><td style='padding:8px;border:1px solid #ddd'>{payment_method}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Transaction ID</strong></td><td style='padding:8px;border:1px solid #ddd'>{txn_id}</td></tr>
</table>
<p>Your invoice has been attached to this email. We will now proceed with your service request.</p>
                ", $company),
                'vars'    => ['client_name', 'lead_number', 'payment_amount', 'payment_method', 'txn_id'],
            ],
            [
                'slug'    => 'payment_received',
                'channel' => 'sms',
                'body'    => "Payment of Rs.{payment_amount} received for {$company} order {lead_number}. Txn: {txn_id}. Thank you!",
                'vars'    => ['payment_amount', 'lead_number', 'txn_id'],
            ],

            // ── Vendor Assigned ───────────────────────────────────────────
            [
                'slug'    => 'vendor_assigned',
                'channel' => 'email',
                'subject' => "New Job Assignment — {$company} #{lead_number}",
                'body'    => $this->emailWrap("
<h2>New Job Assigned to You</h2>
<p>Dear {vendor_name},</p>
<p>A new service request has been assigned to you. Please review and accept or reject within <strong>2 hours</strong>.</p>
<table style='border-collapse:collapse;width:100%'>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Order Number</strong></td><td style='padding:8px;border:1px solid #ddd'>{lead_number}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Service</strong></td><td style='padding:8px;border:1px solid #ddd'>{service_name}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>City / RTO</strong></td><td style='padding:8px;border:1px solid #ddd'>{city_name}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Your Earnings</strong></td><td style='padding:8px;border:1px solid #ddd'>₹{vendor_amount}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>SLA Deadline</strong></td><td style='padding:8px;border:1px solid #ddd'>{sla_deadline}</td></tr>
</table>
<p style='margin-top:20px'>
  <a href='{accept_url}' style='background:#1A7A4A;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;margin-right:10px'>✓ Accept Job</a>
  <a href='{reject_url}' style='background:#E74C3C;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>✗ Reject Job</a>
</p>
                ", $company),
                'vars'    => ['vendor_name', 'lead_number', 'service_name', 'city_name', 'vendor_amount', 'sla_deadline', 'accept_url', 'reject_url'],
            ],
            [
                'slug'    => 'vendor_assigned',
                'channel' => 'sms',
                'body'    => "New job {lead_number}: {service_name} in {city_name}. Earnings: Rs.{vendor_amount}. SLA: {sla_deadline}. Login to {$company} portal to accept/reject.",
                'vars'    => ['lead_number', 'service_name', 'city_name', 'vendor_amount', 'sla_deadline'],
            ],
            [
                'slug'    => 'vendor_assigned',
                'channel' => 'whatsapp',
                'body'    => "🔔 *New Job Assignment*\n\nOrder: *{lead_number}*\nService: {service_name}\nCity: {city_name}\nYour Earning: *₹{vendor_amount}*\nSLA: {sla_deadline}\n\nPlease accept or reject within 2 hours.",
                'vars'    => ['lead_number', 'service_name', 'city_name', 'vendor_amount', 'sla_deadline'],
            ],

            // ── Vendor Unassigned (Known Limitations audit fix) ────────────
            // Without a real template row here, NotificationService::send()'s
            // `if (!$tpl) continue;` guard would make notifyVendorUnassigned()
            // a permanent, silent no-op — the LeadService/Bootstrap wiring
            // would fire correctly but nothing would ever actually be
            // delivered. run()'s own per-slug/channel existence check means
            // this is picked up by every existing install the next time the
            // seeder runs (on plugin activation and on every version bump —
            // see Bootstrap.php's RTOFLOW_VERSION-gated init hook), not only
            // fresh installs.
            [
                'slug'    => 'vendor_unassigned',
                'channel' => 'email',
                'subject' => "Job Reassigned — {$company} #{lead_number}",
                'body'    => $this->emailWrap("
<h2>Job Reassigned</h2>
<p>Dear {vendor_name},</p>
<p>Order <strong>{lead_number}</strong> ({service_name}, {city_name}) has been reassigned to another vendor and is no longer on your active jobs list. No action is needed from you.</p>
<p>If you believe this was done in error, please contact support.</p>
                ", $company),
                'vars'    => ['vendor_name', 'lead_number', 'service_name', 'city_name'],
            ],
            [
                'slug'    => 'vendor_unassigned',
                'channel' => 'sms',
                'body'    => "Order {lead_number} ({service_name}, {city_name}) has been reassigned to another vendor and removed from your job list. — {$company}",
                'vars'    => ['lead_number', 'service_name', 'city_name'],
            ],

            // ── Status Changed ────────────────────────────────────────────
            [
                'slug'    => 'status_changed',
                'channel' => 'email',
                'subject' => "Update on Your {$company} Request #{lead_number}",
                'body'    => $this->emailWrap("
<h2>Status Update</h2>
<p>Dear {client_name},</p>
<p>Your request <strong>{lead_number}</strong> has been updated.</p>
<p><strong>New Status:</strong> <span style='background:#1E3A5F;color:#fff;padding:4px 12px;border-radius:20px;font-size:14px'>{status_label}</span></p>
<p>{status_message}</p>
<p><a href='{portal_url}' style='background:#1E3A5F;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>View Full Details</a></p>
                ", $company),
                'vars'    => ['client_name', 'lead_number', 'status_label', 'status_message', 'portal_url'],
            ],
            [
                'slug'    => 'status_changed',
                'channel' => 'sms',
                'body'    => "Update: {$company} order {lead_number} is now '{status_label}'. {status_message}",
                'vars'    => ['lead_number', 'status_label', 'status_message'],
            ],

            // ── SLA Warning ───────────────────────────────────────────────
            [
                'slug'    => 'sla_warning',
                'channel' => 'email',
                'subject' => "⚠️ SLA Warning — Order #{lead_number} due in {hours_remaining} hours",
                'body'    => $this->emailWrap("
<h2 style='color:#E74C3C'>⚠️ SLA Deadline Approaching</h2>
<p>Order <strong>{lead_number}</strong> is due in <strong style='color:#E74C3C'>{hours_remaining} hours</strong>.</p>
<p>Please take immediate action to ensure timely completion.</p>
<p><strong>Service:</strong> {service_name}<br><strong>City:</strong> {city_name}<br><strong>Deadline:</strong> {sla_deadline}</p>
                ", $company),
                'vars'    => ['lead_number', 'hours_remaining', 'service_name', 'city_name', 'sla_deadline'],
            ],

            // ── SLA Breached ──────────────────────────────────────────────
            [
                'slug'    => 'sla_breached',
                'channel' => 'email',
                'subject' => "🚨 SLA Breached — Order #{lead_number} is overdue",
                'body'    => $this->emailWrap("
<h2 style='color:#C0392B'>🚨 SLA Breached</h2>
<p>Order <strong>{lead_number}</strong> has breached its SLA deadline of {sla_deadline}.</p>
<p>This order requires immediate escalation and client communication.</p>
<p><a href='{admin_url}' style='background:#E74C3C;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>View Order → Escalate</a></p>
                ", $company),
                'vars'    => ['lead_number', 'sla_deadline', 'admin_url'],
            ],

            // ── Document Verified ─────────────────────────────────────────
            [
                'slug'    => 'document_verified',
                'channel' => 'email',
                'subject' => "Document Verified — {$company} #{lead_number}",
                'body'    => $this->emailWrap("
<h2>Document Verified ✓</h2>
<p>Dear {client_name},</p>
<p>Your <strong>{doc_type}</strong> for order <strong>{lead_number}</strong> has been verified successfully.</p>
<p>We will proceed with processing your request.</p>
                ", $company),
                'vars'    => ['client_name', 'lead_number', 'doc_type'],
            ],

            // ── Document Rejected ─────────────────────────────────────────
            [
                'slug'    => 'document_rejected',
                'channel' => 'email',
                'subject' => "Action Required — Document Rejected for #{lead_number}",
                'body'    => $this->emailWrap("
<h2>Document Requires Attention</h2>
<p>Dear {client_name},</p>
<p>Your <strong>{doc_type}</strong> for order <strong>{lead_number}</strong> was not accepted.</p>
<p><strong>Reason:</strong> {reject_reason}</p>
<p>Please upload a corrected document as soon as possible to avoid delays.</p>
<p><a href='{upload_url}' style='background:#1E3A5F;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Upload Corrected Document</a></p>
                ", $company),
                'vars'    => ['client_name', 'lead_number', 'doc_type', 'reject_reason', 'upload_url'],
            ],
            [
                'slug'    => 'document_rejected',
                'channel' => 'sms',
                'body'    => "Action needed: Your {doc_type} for {$company} order {lead_number} was rejected. Reason: {reject_reason}. Please reupload.",
                'vars'    => ['doc_type', 'lead_number', 'reject_reason'],
            ],

            // ── Order Completed ───────────────────────────────────────────
            [
                'slug'    => 'order_completed',
                'channel' => 'email',
                'subject' => "🎉 Service Completed — {$company} #{lead_number}",
                'body'    => $this->emailWrap("
<h2>Service Completed! 🎉</h2>
<p>Dear {client_name},</p>
<p>Your service request <strong>{lead_number}</strong> for <strong>{service_name}</strong> has been completed successfully.</p>
<p>We hope you are satisfied with our service. If you have a moment, please rate your experience.</p>
<p>
  <a href='{rating_url}' style='background:#D4AC0D;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;margin-right:10px'>⭐ Rate Your Experience</a>
  <a href='{portal_url}' style='background:#1E3A5F;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>View Documents</a>
</p>
                ", $company),
                'vars'    => ['client_name', 'lead_number', 'service_name', 'rating_url', 'portal_url'],
            ],
            [
                'slug'    => 'order_completed',
                'channel' => 'sms',
                'body'    => "Congratulations! {$company} order {lead_number} for {service_name} is complete. Rate us: {rating_url}",
                'vars'    => ['lead_number', 'service_name', 'rating_url'],
            ],

            // ── Complaint Received ────────────────────────────────────────
            [
                'slug'    => 'complaint_received',
                'channel' => 'email',
                'subject' => "Complaint Registered — {$company} #{complaint_number}",
                'body'    => $this->emailWrap("
<h2>Complaint Registered</h2>
<p>Dear {client_name},</p>
<p>Your complaint <strong>{complaint_number}</strong> has been registered and is being reviewed.</p>
<p><strong>Subject:</strong> {subject}</p>
<p>Our team will respond within <strong>48 hours</strong>. You will receive updates via email.</p>
                ", $company),
                'vars'    => ['client_name', 'complaint_number', 'subject'],
            ],

            // ── Payout Processed ──────────────────────────────────────────
            [
                'slug'    => 'payout_processed',
                'channel' => 'email',
                'subject' => "Payout Processed — {$company} for {period}",
                'body'    => $this->emailWrap("
<h2>Payout Processed ✓</h2>
<p>Dear {vendor_name},</p>
<p>Your payout for <strong>{period}</strong> has been processed.</p>
<table style='border-collapse:collapse;width:100%'>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>Gross Amount</strong></td><td style='padding:8px;border:1px solid #ddd'>₹{gross_amount}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#f5f5f5'><strong>TDS (1%)</strong></td><td style='padding:8px;border:1px solid #ddd'>-₹{tds_amount}</td></tr>
  <tr><td style='padding:8px;border:1px solid #ddd;background:#1E3A5F;color:#fff'><strong>Net Payout</strong></td><td style='padding:8px;border:1px solid #ddd;background:#1E3A5F;color:#fff'><strong>₹{net_amount}</strong></td></tr>
</table>
<p>The amount will be credited to your registered bank account within 3-5 business days.</p>
<p>UTR Reference: {utr_number}</p>
                ", $company),
                'vars'    => ['vendor_name', 'period', 'gross_amount', 'tds_amount', 'net_amount', 'utr_number'],
            ],

            // ── Welcome / Registration ────────────────────────────────────
            [
                'slug'    => 'welcome_client',
                'channel' => 'email',
                'subject' => "Welcome to {$company}!",
                'body'    => $this->emailWrap("
<h2>Welcome to {$company}! 🎉</h2>
<p>Dear {client_name},</p>
<p>Your account has been created successfully. You can now track your service requests, upload documents, and communicate with our team all in one place.</p>
<p><a href='{portal_url}' style='background:#1E3A5F;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Go to My Account</a></p>
                ", $company),
                'vars'    => ['client_name', 'portal_url'],
            ],

            // ── Password Reset ────────────────────────────────────────────
            [
                'slug'    => 'password_reset',
                'channel' => 'email',
                'subject' => "Password Reset — {$company}",
                'body'    => $this->emailWrap("
<h2>Password Reset</h2>
<p>A password reset was requested for your {$company} account.</p>
<p>Click the button below to reset your password. This link is valid for 1 hour.</p>
<p><a href='{reset_url}' style='background:#1E3A5F;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Reset Password</a></p>
<p style='color:#999;font-size:13px'>If you did not request a password reset, please ignore this email.</p>
                ", $company),
                'vars'    => ['reset_url'],
            ],

            // ── Part 13-D builds: Missing notification templates ──────────────────

            // Vendor Suspended — sent to vendor on suspension
            [
                'slug'    => 'vendor_suspended',
                'channel' => 'email',
                'subject' => "Your Vendor Account Has Been Suspended — {$company}",
                'body'    => $this->emailWrap("
<h2>Account Suspended</h2>
<p>Dear {vendor_name},</p>
<p>Your vendor account with {$company} has been <strong>suspended</strong>.</p>
<p><strong>Reason:</strong> {reason}</p>
<p>If you believe this is an error or wish to appeal, please contact our support team at <a href='mailto:{admin_contact}'>{admin_contact}</a>.</p>
<p>Active job assignments may be reassigned. Pending payouts will be held until the account is reinstated.</p>
                ", $company),
                'vars' => ['vendor_name', 'reason', 'admin_contact'],
            ],

            // Vendor Status Changed (general — active/inactive)
            [
                'slug'    => 'vendor_status_changed',
                'channel' => 'email',
                'subject' => "Account Status Update — {$company}",
                'body'    => $this->emailWrap("
<h2>Account Status Updated</h2>
<p>Dear {vendor_name},</p>
<p>Your vendor account status has been changed to: <strong>{new_status}</strong>.</p>
<p>If you have questions, contact us at <a href='mailto:{admin_contact}'>{admin_contact}</a>.</p>
                ", $company),
                'vars' => ['vendor_name', 'new_status', 'admin_contact'],
            ],

            // Refund Approved — sent to client
            [
                'slug'    => 'refund_approved',
                'channel' => 'email',
                'subject' => "Refund Approved for Order {lead_number} — {$company}",
                'body'    => $this->emailWrap("
<h2>Refund Approved ✓</h2>
<p>Your refund request for order <strong>{lead_number}</strong> has been approved.</p>
<p><strong>Refund Amount:</strong> {refund_amount}</p>
<p><strong>Payment Method:</strong> {method}</p>
<p>The refund will be processed within 5–7 working days depending on your bank.</p>
{note}
                ", $company),
                'vars' => ['lead_number', 'refund_amount', 'method', 'note'],
            ],

            // Refund Rejected — sent to client (ENTERPRISE GAP FIX, Section
            // 3: pairs with refund_approved above so the refund workflow has
            // both possible outcomes covered, not just the approve path).
            [
                'slug'    => 'refund_rejected',
                'channel' => 'email',
                'subject' => "Refund Request Update for Order {lead_number} — {$company}",
                'body'    => $this->emailWrap("
<h2>Refund Request Reviewed</h2>
<p>Your refund request for order <strong>{lead_number}</strong> (amount {refund_amount}) has been reviewed and could not be approved.</p>
<p><strong>Reason:</strong> {reason}</p>
<p>If you believe this is incorrect or would like to discuss further, please contact our support team.</p>
                ", $company),
                'vars' => ['lead_number', 'refund_amount', 'reason'],
            ],

            // Lead Inactive — sent to admin when a lead is stuck
            [
                'slug'    => 'lead_inactive',
                'channel' => 'email',
                'subject' => "⚠ Lead {lead_number} Stuck for {days_stuck} Days — Action Required",
                'body'    => $this->emailWrap("
<h2>Lead Stuck — Needs Attention</h2>
<p>Order <strong>{lead_number}</strong> has been in status <strong>{status}</strong> for <strong>{days_stuck} days</strong> without any progress.</p>
<p><a href='{lead_url}' style='background:#E97B28;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Review Order</a></p>
<p style='color:#999;font-size:12px'>This is an automated alert. If the order has been updated, no action is needed.</p>
                ", $company),
                'vars' => ['lead_number', 'status', 'days_stuck', 'lead_url'],
            ],

            // ENTERPRISE GAP FIX (Phase 4, item 3 — document expiry tracking):
            // fired by Bootstrap::runDocumentExpiryCheck() daily cron.
            [
                'slug'    => 'document_expiring',
                'channel' => 'email',
                'subject' => "Your {doc_type_name} expires on {expiry_date}",
                'body'    => $this->emailWrap("
<h2>Document Expiring Soon</h2>
<p>The <strong>{doc_type_name}</strong> on file for order <strong>{lead_number}</strong> expires on <strong>{expiry_date}</strong>.</p>
<p>Please upload a renewed copy before it expires to avoid delays.</p>
<p><a href='{documents_url}' style='background:#E97B28;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Upload Renewed Document</a></p>
                ", $company),
                'vars' => ['doc_type_name', 'expiry_date', 'lead_number', 'documents_url'],
            ],
            [
                'slug'    => 'document_expiring',
                'channel' => 'sms',
                'body'    => "{$company}: Your {doc_type_name} (order {lead_number}) expires {expiry_date}. Please upload a renewed copy soon.",
                'vars'    => ['doc_type_name', 'lead_number', 'expiry_date'],
            ],

            // Payment Failed — sent to client
            [
                'slug'    => 'payment_failed',
                'channel' => 'email',
                'subject' => "Payment Failed for Order {lead_number} — {$company}",
                'body'    => $this->emailWrap("
<h2>Payment Unsuccessful</h2>
<p>We were unable to process your payment for order <strong>{lead_number}</strong>.</p>
<p><strong>Amount:</strong> {amount}</p>
<p><strong>Reason:</strong> {reason}</p>
<p><a href='{payment_url}' style='background:#1E3A5F;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Retry Payment</a></p>
<p style='color:#999;font-size:12px'>If you continue to face issues, please contact our support team.</p>
                ", $company),
                'vars' => ['lead_number', 'amount', 'reason', 'payment_url'],
            ],

            // Complaint Responded — sent to client when admin responds
            [
                'slug'    => 'complaint_responded',
                'channel' => 'email',
                'subject' => "Update on Your Complaint {complaint_number} — {$company}",
                'body'    => $this->emailWrap("
<h2>Response to Your Complaint</h2>
<p>Our team has reviewed and responded to your complaint <strong>{complaint_number}</strong>.</p>
<p><strong>Status:</strong> {status}</p>
<p><strong>Our Response:</strong></p>
<blockquote style='border-left:3px solid #1E3A5F;padding:8px 16px;margin:8px 0;color:#555'>{response}</blockquote>
<p>If you have further questions, please reply to this email or log in to your dashboard.</p>
                ", $company),
                'vars' => ['complaint_number', 'status', 'response'],
            ],
        ];
    }

    private function emailWrap(string $content, string $company): string
    {
        return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f4f7fb;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center' style='padding:30px 20px'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1)'>
  <tr><td style='background:#1E3A5F;padding:24px 32px'>
    <h1 style='color:#fff;margin:0;font-size:22px'>{$company}</h1>
  </td></tr>
  <tr><td style='padding:32px;color:#333;font-size:15px;line-height:1.6'>
    {$content}
  </td></tr>
  <tr><td style='background:#f4f7fb;padding:20px 32px;text-align:center;color:#999;font-size:12px'>
    <p>© " . date('Y') . " {$company}. All rights reserved.</p>
    <p>This is an automated notification. Please do not reply to this email.</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>";
    }
}
