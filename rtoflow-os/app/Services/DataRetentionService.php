<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Data Retention Service — policy-driven, automatic purge
 *
 * ENTERPRISE GAP FIX (Phase 9, item — "data retention is reactive, not
 * policy-driven"): GdprService::eraseSubject() is a thoughtfully-built,
 * on-demand mechanism, but nothing previously purged records automatically
 * once they aged past a defined retention period — someone had to manually
 * invoke erasure per subject, every time. This service is the automatic
 * side: a daily cron (see Bootstrap::init(), 'rtoflow_data_retention')
 * calls runDaily(), which reuses the exact same
 * hard-delete-if-unpaid / anonymise-if-paid reasoning GdprService already
 * applies to a single subject's leads, but applies it in bulk to whatever
 * qualifies by age — driven entirely by the three admin-configurable
 * periods on Settings → Data Retention (SettingsController's 'privacy'
 * tab), never a hardcoded number. Every purge is logged via AuditService
 * so what actually happened is auditable, matching this codebase's
 * "insert-only evidentiary trail" convention used everywhere else
 * (AuditService itself, ConsentService).
 */
class DataRetentionService
{
    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'closed', 'rejected'];

    public function runDaily(): void
    {
        $summary = [
            'leads_anonymized'  => $this->purgeStaleLeads(),
            'drafts_deleted'    => $this->purgeExpiredDrafts(),
            'logs_deleted'      => $this->purgeOldLogs(),
        ];

        if (array_sum($summary) > 0) {
            AuditService::log('retention.purged', null, $summary);
            error_log('RTOFLOW DataRetentionService: ' . wp_json_encode($summary));
        }
    }

    /**
     * Leads in a terminal status older than rtoflow_retention_days_leads:
     * unpaid ones are hard-deleted (no financial/legal retention need,
     * exactly GdprService::eraseSubject()'s own "unpaid → hard delete"
     * reasoning), paid ones are anonymised in place (amounts/dates kept
     * for accounting, owner_name/form_data scrubbed) — the same split
     * GdprService already applies to one subject's leads, applied here by
     * age across all terminal leads instead of by identifier.
     */
    private function purgeStaleLeads(): int
    {
        $days = (int)get_option('rtoflow_retention_days_leads', 0);
        if ($days <= 0) return 0;

        global $wpdb;
        $p   = $wpdb->prefix;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $statusPlaceholders = implode(',', array_fill(0, count(self::TERMINAL_STATUSES), '%s'));

        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT id, paid_amount, payment_status FROM {$p}rto_leads
             WHERE status IN ($statusPlaceholders)
               AND COALESCE(updated_at, created_at) < %s
               AND owner_name != '[ERASED FOR GDPR]'",
            array_merge(self::TERMINAL_STATUSES, [$cutoff])
        ), ARRAY_A) ?: [];

        if (!$leads) return 0;

        $toDelete = [];
        $toAnonymize = [];
        foreach ($leads as $lead) {
            $hasMoney = ((float)$lead['paid_amount'] > 0) || in_array($lead['payment_status'], ['paid', 'partial'], true);
            if ($hasMoney) {
                $toAnonymize[] = (int)$lead['id'];
            } else {
                $toDelete[] = (int)$lead['id'];
            }
        }

        if ($toDelete) {
            $placeholders = implode(',', array_fill(0, count($toDelete), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}rto_lead_meta WHERE lead_id IN ($placeholders)", $toDelete));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}rto_documents WHERE lead_id IN ($placeholders)", $toDelete));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}rto_messages WHERE lead_id IN ($placeholders)", $toDelete));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}rto_assignments WHERE lead_id IN ($placeholders)", $toDelete));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}rto_form_submissions WHERE lead_id IN ($placeholders)", $toDelete));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}rto_leads WHERE id IN ($placeholders)", $toDelete));
        }

        if ($toAnonymize) {
            $placeholders = implode(',', array_fill(0, count($toAnonymize), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$p}rto_leads
                    SET owner_name = %s,
                        form_data  = %s,
                        internal_notes = CASE WHEN internal_notes IS NULL THEN NULL ELSE %s END
                  WHERE id IN ($placeholders)",
                array_merge(
                    ['[ERASED FOR GDPR]', wp_json_encode(['erased' => true, 'erased_at' => current_time('mysql'), 'reason' => 'retention_policy']), '[NOTES REDACTED — RETENTION POLICY]'],
                    $toAnonymize
                )
            ));
        }

        return count($toDelete) + count($toAnonymize);
    }

    /**
     * Delegates to the already-built FormDraftService::cleanupExpired() —
     * that method existed with a docblock saying it was "intended to be
     * called from the same scheduled-cleanup path" but nothing ever
     * actually called it. Gated on rtoflow_retention_days_drafts (a
     * checkbox, not a day count — each draft carries its own per-row
     * expires_at set at save time).
     */
    private function purgeExpiredDrafts(): int
    {
        if (get_option('rtoflow_retention_days_drafts', '0') === '0') return 0;
        /** @var \RTOFLOW\Services\FormDraftService $drafts */
        $drafts = \RTOFLOW\Bootstrap::container()->make(FormDraftService::class);
        return $drafts->cleanupExpired();
    }

    /** Old rto_logs rows past rtoflow_retention_days_logs. 0 (default) = keep forever. */
    private function purgeOldLogs(): int
    {
        $days = (int)get_option('rtoflow_retention_days_logs', 0);
        if ($days <= 0) return 0;

        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rto_logs WHERE created_at < %s",
            $cutoff
        ));
        return (int)$deleted;
    }
}
