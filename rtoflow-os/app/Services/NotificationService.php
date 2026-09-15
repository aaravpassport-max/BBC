<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Notification Service
 *
 * Dispatches notifications via email, SMS, and WhatsApp.
 * Every notification is queued in the database first, then sent.
 * Failed sends are retried with exponential backoff (3 attempts max).
 *
 * Usage:
 *   NotificationService::send('lead_created', $lead, $userId, $channels);
 *   NotificationService::processQueue(); // called by cron
 */
class NotificationService
{
    // ── Dispatch ──────────────────────────────────────────────────────────

    /**
     * Queue a notification for a user/lead.
     *
     * @param string   $slug     Template slug (matches rto_notification_templates.slug)
     * @param array    $context  Template variable values
     * @param int      $userId   Recipient WP user ID
     * @param array    $channels ['email', 'sms', 'whatsapp'] — defaults to ['email']
     * @param int|null $leadId   Associated lead (for tracking)
     */
    /**
     * Maps a notification template slug to the admin "notifications" tab
     * toggle option (SettingsController) that gates it. Slugs not listed
     * here have no admin toggle and are always sent.
     */
    private const TOGGLE_OPTION_MAP = [
        'lead_created'     => 'rtoflow_notif_lead_created',
        'vendor_assigned'  => 'rtoflow_notif_vendor_assign',
        'status_changed'   => 'rtoflow_notif_status_change',
        'payment_received' => 'rtoflow_notif_payment_done',
        'sla_warning'      => 'rtoflow_notif_sla_warning',
    ];

    /**
     * Whether the admin has this notification type enabled via the
     * Settings → Notifications tab. Options are stored as '1'/'0' strings
     * by SettingsController::save(); treat unset (never saved) as enabled
     * so existing installs keep their current behaviour by default.
     */
    private static function isEnabled(string $slug): bool
    {
        $option = self::TOGGLE_OPTION_MAP[$slug] ?? null;
        if ($option === null) return true; // no toggle defined for this slug
        return get_option($option, '1') !== '0';
    }

    public static function send(
        string $slug,
        array  $context,
        int    $userId,
        array  $channels = ['email'],
        ?int   $leadId   = null
    ): void {
        global $wpdb;

        if (!self::isEnabled($slug)) return;

        $user = get_userdata($userId);
        if (!$user) return;

        // ENTERPRISE GAP FIX (Phase 4, item 6 — notification preferences):
        // drop any channel this specific user has opted out of before
        // anything else runs (template lookup, recipient resolution, queue
        // insert) — a disabled channel behaves as if it was never
        // requested, not as a per-channel failure worth logging.
        $channels = \RTOFLOW\Services\NotificationPreferenceService::filterChannels($userId, $channels);
        if (empty($channels)) return;

        $context['client_name'] = $context['client_name'] ?? $user->display_name;
        $context['vendor_name'] = $context['vendor_name'] ?? $user->display_name;
        $context['portal_url']  = $context['portal_url']  ?? home_url('/rto-dashboard/');

        foreach ($channels as $channel) {
            // Load template
            $tpl = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rto_notification_templates
                 WHERE slug = %s AND channel = %s AND is_active = 1",
                $slug, $channel
            ), ARRAY_A);

            if (!$tpl) continue;

            // Determine recipient
            $recipient = match($channel) {
                'email'     => $user->user_email,
                'sms'       => get_user_meta($userId, 'rtoflow_mobile', true) ?: '',
                'whatsapp'  => get_user_meta($userId, 'rtoflow_mobile', true) ?: '',
                default     => '',
            };
            if (!$recipient) continue;

            // Substitute template variables
            $body    = self::interpolate($tpl['body'], $context);
            $subject = self::interpolate($tpl['subject'] ?? '', $context);

            // Queue for delivery
            $wpdb->insert($wpdb->prefix . 'rto_notifications', [
                'lead_id'     => $leadId,
                'user_id'     => $userId,
                'channel'     => $channel,
                'template_id' => $slug,
                'recipient'   => $recipient,
                'message'     => $body,
                'status'      => 'pending',
                'attempts'    => 0,
                'created_at'  => current_time('mysql'),
            ]);
        }
    }

    // ── Process queue (called by WP-Cron every 5 minutes) ────────────────

    public static function processQueue(int $batchSize = 30): void
    {
        global $wpdb;

        // P7-PERF-007 FIX: fetch without heavy message field for routing only
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, lead_id, user_id, channel, template_id, recipient,
                        status, attempts, next_retry, created_at
                 FROM {$wpdb->prefix}rto_notifications
                 WHERE status = 'pending'
                 AND (next_retry IS NULL OR next_retry <= %s)
                 AND attempts < 3
                 ORDER BY created_at ASC
                 LIMIT %d",
                current_time('mysql'),
                $batchSize
            ),
            ARRAY_A
        ) ?: [];

        if (empty($rows)) return;

        // P5-DB-001 FIX: pre-load ALL needed templates in ONE query (not N queries)
        $slugs = array_unique(array_column($rows, 'template_id'));
        $placeholders = implode(',', array_fill(0, count($slugs), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $templates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slug, channel, subject FROM {$wpdb->prefix}rto_notification_templates
                 WHERE slug IN ({$placeholders})",
                ...$slugs
            ),
            ARRAY_A
        ) ?: [];

        // Build lookup map: "slug.channel" => subject
        $tplMap = [];
        foreach ($templates as $t) {
            $tplMap[$t['slug'] . '.' . $t['channel']] = $t['subject'];
        }

        foreach ($rows as $notification) {
            self::dispatch($notification, $tplMap);
        }
    }

    // ── Dispatch a single queued notification ─────────────────────────────

    private static function dispatch(array $n, array $tplMap = []): void
    {
        global $wpdb;

        $id      = (int)$n['id'];
        $success = false;

        try {
            $success = match($n['channel']) {
                'email'    => self::sendEmail($n, $tplMap),
                'sms'      => self::sendSms($n),
                'whatsapp' => self::sendWhatsApp($n),
                default    => false,
            };
        } catch (\Throwable $e) {
            error_log("RTOFLOW Notification dispatch error [{$n['channel']}]: " . $e->getMessage());
        }

        // BUGFIX (ghost-success sweep): neither branch's status update was
        // checked. If the channel send genuinely succeeded but this "mark as
        // sent" write then silently failed, the row would remain 'pending'
        // with attempts < 3 -- so the very next processQueue() run would send
        // the SAME email/SMS to the user again, and again, up to the retry
        // cap, purely because a status write (not the actual delivery) kept
        // failing. On the failure branch, if the retry-bookkeeping update
        // itself failed, attempts/next_retry would never advance, so a
        // genuinely failing channel could be hammered on every single cron
        // tick instead of backing off as designed. Both are now logged so a
        // persistent DB-write problem here is visible instead of silently
        // manifesting as duplicate notifications or a retry storm.
        if ($success) {
            $sentUpdated = $wpdb->update($wpdb->prefix . 'rto_notifications', [
                'status'  => 'sent',
                'sent_at' => current_time('mysql'),
            ], ['id' => $id]);
            if ($sentUpdated === false) {
                error_log("RTOFLOW NotificationService: failed to mark notification {$id} as sent (message was delivered; risk of duplicate re-send on next queue run) — " . $wpdb->last_error);
            }
        } else {
            $attempts = (int)$n['attempts'] + 1;
            $nextRetry = $attempts >= 3
                ? null // No more retries
                : date('Y-m-d H:i:s', time() + (60 * (2 ** $attempts))); // 2m, 4m backoff

            $retryUpdated = $wpdb->update($wpdb->prefix . 'rto_notifications', [
                'status'     => $attempts >= 3 ? 'failed' : 'pending',
                'attempts'   => $attempts,
                'next_retry' => $nextRetry,
                'error_msg'  => 'Delivery failed on attempt ' . $attempts,
            ], ['id' => $id]);
            if ($retryUpdated === false) {
                error_log("RTOFLOW NotificationService: failed to record retry bookkeeping for notification {$id} (risk of immediate re-attempt without backoff on next queue run) — " . $wpdb->last_error);
            }
        }
    }

    // ── Channel senders ───────────────────────────────────────────────────

    private static function sendEmail(array $n, array $tplMap = []): bool
    {
        // FIX (10-workstream integration pass, feature-flag wiring):
        // 'email_notifications' had nothing gating it — this is the one real
        // email-send call site (processQueue() dispatches here per row).
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('email_notifications')) return false;

        // P5-DB-001 FIX: use pre-loaded template map, avoiding per-notification DB query
        $key     = $n['template_id'] . '.email';
        $subject = $tplMap[$key] ?? 'Notification from ' . get_option('rtoflow_company_name', 'RTOFLOW');

        // Fetch message body only when actually sending (not in batch select)
        if (empty($n['message'])) {
            global $wpdb;
            $n['message'] = (string)$wpdb->get_var($wpdb->prepare(
                "SELECT message FROM {$wpdb->prefix}rto_notifications WHERE id = %d",
                (int)$n['id']
            ));
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('rtoflow_company_name', 'RTOFLOW') . ' <' . \RTOFLOW\Config\Env::string('MAIL_FROM_ADDRESS', get_option('admin_email')) . '>',
        ];

        return wp_mail($n['recipient'], $subject, $n['message'], $headers);
    }

    // Known Limitations audit fix (found while building the SMS/WhatsApp
    // test-send feature for the Email Templates screen, then checked
    // platform-wide per the standing "review whether the same underlying
    // issue exists elsewhere" instruction — this is the ROOT of that
    // underlying issue, not a symptom of it):
    //
    // RTOFLOW_SMS and RTOFLOW_WhatsApp (integrations/Sms.php,
    // integrations/WhatsApp.php) are both declared in the GLOBAL namespace
    // (no `namespace` statement in either file), but this file itself is
    // namespace RTOFLOW\Services. PHP does NOT fall back to the global
    // namespace for an unqualified CLASS reference the way it does for an
    // unqualified FUNCTION call — `RTOFLOW_SMS::send(...)` here resolved to
    // `RTOFLOW\Services\RTOFLOW_SMS`, a class that has never existed,
    // throwing an uncaught "Class not found" Error at the moment either
    // method actually ran. Confirmed with a minimal reproduction of this
    // exact global-class-from-namespaced-file shape (identical Fatal
    // error). Because every real SMS/WhatsApp send in this codebase goes
    // through these two private methods, this meant SMS and WhatsApp
    // notifications have never actually been deliverable in production —
    // not throttled, not silently no-op, but a hard fatal thrown and
    // caught nowhere on this path, independent of whatever
    // Sanitiser/FeatureFlags/API-key configuration was otherwise correct.
    // Fixed by fully qualifying both references with a leading backslash.
    private static function sendSms(array $n): bool
    {
        if (!class_exists('RTOFLOW_SMS')) {
            require_once RTOFLOW_DIR . 'integrations/Sms.php';
        }
        return \RTOFLOW_SMS::send($n['recipient'], $n['message']);
    }

    private static function sendWhatsApp(array $n): bool
    {
        if (!class_exists('RTOFLOW_WhatsApp')) {
            require_once RTOFLOW_DIR . 'integrations/WhatsApp.php';
        }
        return \RTOFLOW_WhatsApp::send($n['recipient'], $n['message']);
    }

    // ── Template interpolation ────────────────────────────────────────────

    private static function interpolate(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{' . $key . '}', (string)$value, $template);
        }
        return $template;
    }

    // ── Convenience methods ───────────────────────────────────────────────

    public static function notifyLeadCreated(array $lead, int $clientId): void
    {
        self::send('lead_created', [
            'lead_number'  => $lead['lead_number'],
            'service_name' => $lead['service_name'] ?? '',
            'city_name'    => $lead['city_name'] ?? '',
            'amount'       => rto_format_inr((float)$lead['total_amount']),
            'sla_deadline' => rto_date($lead['sla_deadline'] ?? ''),
        ], $clientId, ['email', 'sms', 'whatsapp'], (int)$lead['id']);
    }

    public static function notifyVendorAssigned(array $lead, int $vendorUserId, float $vendorAmount): void
    {
        self::send('vendor_assigned', [
            'lead_number'   => $lead['lead_number'],
            'service_name'  => $lead['service_name'] ?? '',
            'city_name'     => $lead['city_name'] ?? '',
            'vendor_amount' => rto_format_inr($vendorAmount),
            'sla_deadline'  => rto_date($lead['sla_deadline'] ?? ''),
            'accept_url'    => home_url('/rto-dashboard/jobs/?action=accept&lead=' . $lead['id']),
            'reject_url'    => home_url('/rto-dashboard/jobs/?action=reject&lead=' . $lead['id']),
        ], $vendorUserId, ['email', 'sms', 'whatsapp'], (int)$lead['id']);
    }

    // Known Limitations audit fix: previously a vendor who was reassigned
    // away from a lead had no notification path at all — they would only
    // discover it by checking their own dashboard. Mirrors
    // notifyVendorAssigned()'s shape exactly; 'vendor_unassigned' has no
    // entry in TOGGLE_OPTION_MAP, so isEnabled() correctly treats it as
    // always-on (consistent with how a removal notice should not be
    // silently switchable off the way a new-assignment notice can be).
    public static function notifyVendorUnassigned(array $lead, int $vendorUserId): void
    {
        self::send('vendor_unassigned', [
            'lead_number'  => $lead['lead_number'],
            'service_name' => $lead['service_name'] ?? '',
            'city_name'    => $lead['city_name'] ?? '',
        ], $vendorUserId, ['email', 'sms'], (int)$lead['id']);
    }

    public static function notifyStatusChanged(array $lead, int $clientId, string $newStatus): void
    {
        self::send('status_changed', [
            'lead_number'    => $lead['lead_number'],
            'status_label'   => rto_status_label($newStatus),
            'status_message' => self::statusMessage($newStatus),
        ], $clientId, ['email', 'sms'], (int)$lead['id']);
    }

    public static function notifyPaymentReceived(array $lead, array $payment, int $clientId): void
    {
        self::send('payment_received', [
            'lead_number'    => $lead['lead_number'],
            'payment_amount' => rto_format_inr((float)$payment['amount']),
            'payment_method' => ucfirst($payment['method']),
            'txn_id'         => $payment['txn_id'] ?? 'N/A',
        ], $clientId, ['email', 'sms'], (int)$lead['id']);
    }

    private static function statusMessage(string $status): string
    {
        return match($status) {
            'payment_received' => 'Your payment has been confirmed. We will now proceed with your request.',
            'assigned'         => 'A verified agent has been assigned to your request.',
            'in_progress'      => 'Your request is currently being processed by our team.',
            'docs_pending'     => 'Additional documents are required. Please log in to upload them.',
            'rto_submitted'    => 'Your documents have been submitted to the RTO office.',
            'rto_processing'   => 'The RTO office is processing your request.',
            'completed'        => 'Your service request has been completed successfully!',
            'cancelled'        => 'Your service request has been cancelled.',
            default            => 'Your request status has been updated.',
        };
    }
}
