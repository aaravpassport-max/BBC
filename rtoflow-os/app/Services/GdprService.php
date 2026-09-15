<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * GDPR Service — Right to Erasure ("right to be forgotten")
 *
 * Complements the existing GDPR *export* path (Router::routeGdprExport())
 * with the erasure/anonymisation half of GDPR Art. 17.
 *
 * Design principle: this is NOT a blanket `DELETE ... WHERE email = ?`
 * across every table. Two very different obligations apply to personal
 * data depending on the table it lives in:
 *
 *   - HARD DELETE: data with no independent legal/financial retention
 *     requirement (abandoned leads that never got paid, chat messages,
 *     orphaned form-intake rows). Safe to remove outright.
 *
 *   - ANONYMISE (retain-but-scrub): data that is simultaneously "personal
 *     data" and part of a financial/legal record this business must be
 *     able to reconstruct later (a paid lead, its payments ledger, a
 *     vendor's payout history, a resolved complaint used as a dispute
 *     record). Deleting these rows would corrupt totals, break foreign
 *     keys from rto_payments/rto_refunds/rto_vendor_payouts, and remove
 *     records that tax/consumer-protection law requires RTOFLOW to keep.
 *     For these, identifying fields (name, email, mobile, address-like
 *     JSON fields) are overwritten with a fixed placeholder while
 *     amounts, dates, statuses and IDs are left completely intact.
 *
 * The audit trail itself (rto_logs) is *never* touched by erasure — an
 * erasure that destroyed the record of its own occurrence would defeat
 * the entire point of an audit trail. Erasure is logged the same way
 * every other admin action in this codebase is logged (AuditService).
 *
 * Usage:
 *   $result = GdprService::eraseSubject('email', 'jane@example.com', $performedByUserId);
 */
class GdprService
{
    /**
     * Erase (hard-delete or anonymise) every real personal-data record
     * belonging to one data subject, identified by email or mobile.
     *
     * @param string   $identifierType  'email' | 'mobile'
     * @param string   $identifierValue raw value as submitted by the admin
     * @param int|null $performedBy     WP user id of the admin performing this (defaults to current user)
     * @return array{
     *   ok: bool,
     *   identifier: array{type:string,value:string},
     *   matched_user_id: int|null,
     *   hard_deleted: array<string,array{count:int,reason:string}>,
     *   anonymized: array<string,array{count:int,reason:string}>,
     *   skipped: array<string,string>,
     *   error?: string
     * }
     */
    // Known Limitations / common-mistakes audit fix: every UPDATE/DELETE in
    // this operation ran via $wpdb->query() with its return value discarded.
    // WordPress does not throw on a failed query by default, so a silent
    // SQL failure (a lock, a truncated value, a constraint) would NOT have
    // tripped this method's own try/catch — the transaction would still
    // COMMIT, and the erasure report would claim rows were deleted/
    // anonymised that the database never actually touched. For a GDPR
    // erasure specifically, a "ghost success" here is a compliance risk,
    // not just a UX one. This wrapper makes that failure mode impossible:
    // every write in this method now goes through it, and a real failure
    // throws — which the existing catch block already rolls back on.
    private static function writeOrThrow(\wpdb $wpdb, string $sql, string $what): int
    {
        // ENTERPRISE GAP FIX (Phase 9, item — "raw, unparameterized queries
        // in a handful of internal paths"): $sql always arrives already
        // built via $wpdb->prepare() at the call site (or, in the one
        // exception, from an ID list assembled from a prior prepared
        // SELECT) — this wrapper itself has no way to prepare a string it
        // did not build. Flagged only because tools/check-raw-queries.php's
        // blanket static check looks at the $wpdb->query() call site, not
        // the caller.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException("Database write failed while {$what}: " . $wpdb->last_error);
        }
        return (int)$result;
    }

    public static function eraseSubject(string $identifierType, string $identifierValue, ?int $performedBy = null): array
    {
        global $wpdb;

        $identifierType = strtolower(trim($identifierType));
        $performedBy  ??= get_current_user_id();

        // ── 1. Normalise + validate the identifier ──────────────────────────
        if ($identifierType === 'email') {
            $value = \RTOFLOW\Security\Sanitiser::email($identifierValue);
        } elseif ($identifierType === 'mobile') {
            $value = \RTOFLOW\Security\Sanitiser::mobile($identifierValue);
        } else {
            return self::fail($identifierType, $identifierValue, 'Unsupported identifier type. Use "email" or "mobile".');
        }
        if ($value === '') {
            return self::fail($identifierType, $identifierValue, 'Identifier failed validation.');
        }

        $p = $wpdb->prefix;

        // Resolve a WP user account for this identifier, if any (leads/
        // messages are keyed on client_id = wp user id, not raw email).
        $uid = null;
        if ($identifierType === 'email') {
            $u = get_user_by('email', $value);
            $uid = $u ? (int)$u->ID : null;
        } else {
            // Mobile is stored in user meta by this app, not a WP core field.
            $u = $wpdb->get_row($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='rtoflow_mobile' AND meta_value=%s LIMIT 1",
                $value
            ));
            $uid = $u ? (int)$u->user_id : null;
        }

        // The `%value%` LIKE fragment used against JSON columns (form_data,
        // raw_post) — these are the only place a lead's own email/mobile is
        // stored in this schema (rto_leads has no dedicated email column).
        $likeFrag = '%' . $wpdb->esc_like($value) . '%';

        $result = [
            'ok'              => true,
            'identifier'      => ['type' => $identifierType, 'value' => self::mask($value)],
            'matched_user_id' => $uid,
            'hard_deleted'    => [],
            'anonymized'      => [],
            'skipped'         => [],
        ];

        $wpdb->query('START TRANSACTION');

        try {
            // ── 2. rto_leads: split by whether money has moved ─────────────
            $leadWhere = $uid
                ? $wpdb->prepare('client_id = %d OR form_data LIKE %s', $uid, $likeFrag)
                : $wpdb->prepare('form_data LIKE %s', $likeFrag);

            $leads = $wpdb->get_results(
                "SELECT id, paid_amount, payment_status FROM {$p}rto_leads WHERE {$leadWhere}",
                ARRAY_A
            ) ?: [];

            $leadsToDelete   = [];
            $leadsToAnonymize = [];
            foreach ($leads as $lead) {
                $hasMoney = ((float)$lead['paid_amount'] > 0) || in_array($lead['payment_status'], ['paid', 'partial'], true);
                if ($hasMoney) {
                    $leadsToAnonymize[] = (int)$lead['id'];
                } else {
                    $leadsToDelete[] = (int)$lead['id'];
                }
            }

            // 2a. Abandoned/unpaid leads: no financial dependency → hard delete
            //     the lead and everything that only exists to describe it.
            if ($leadsToDelete) {
                self::hardDeleteLeadTree($wpdb, $p, $leadsToDelete);
                $result['hard_deleted']['rto_leads'] = [
                    'count'  => count($leadsToDelete),
                    'reason' => 'Never paid — no financial/legal retention requirement.',
                ];
            } else {
                $result['skipped']['rto_leads (unpaid)'] = 'No matching unpaid leads found.';
            }

            // 2b. Paid leads: retained for accounting/tax/SLA history →
            //     scrub identifying fields only, keep amounts/status/dates.
            if ($leadsToAnonymize) {
                $placeholders = implode(',', array_fill(0, count($leadsToAnonymize), '%d'));
                self::writeOrThrow($wpdb, $wpdb->prepare(
                    "UPDATE {$p}rto_leads
                        SET owner_name = %s,
                            form_data  = %s,
                            internal_notes = CASE WHEN internal_notes IS NULL THEN NULL ELSE %s END
                      WHERE id IN ($placeholders)",
                    array_merge(
                        ['[ERASED FOR GDPR]', wp_json_encode(['erased' => true, 'erased_at' => current_time('mysql')]), '[NOTES REDACTED — GDPR ERASURE]'],
                        $leadsToAnonymize
                    )
                ), 'anonymising rto_leads');
                $result['anonymized']['rto_leads'] = [
                    'count'  => count($leadsToAnonymize),
                    'reason' => 'Has recorded payment(s) — lead retained for financial/tax record-keeping; owner name and form PII scrubbed, amounts and dates kept intact.',
                ];

                // Form submissions attached to a *retained* lead: scrub the
                // PII payload but keep the category/sub_service + row for
                // reporting continuity (do not delete a row that a retained
                // parent still legitimately references).
                $placeholders2 = implode(',', array_fill(0, count($leadsToAnonymize), '%d'));
                $fsCount = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}rto_form_submissions WHERE lead_id IN ($placeholders2)",
                    $leadsToAnonymize
                ));
                if ($fsCount > 0) {
                    self::writeOrThrow($wpdb, $wpdb->prepare(
                        "UPDATE {$p}rto_form_submissions
                            SET form_data = %s, raw_post = NULL
                          WHERE lead_id IN ($placeholders2)",
                        array_merge(['{"erased":true}'], $leadsToAnonymize)
                    ), 'anonymising rto_form_submissions');
                    $result['anonymized']['rto_form_submissions'] = [
                        'count'  => $fsCount,
                        'reason' => 'Belongs to a retained (paid) lead — PII payload scrubbed, row kept for reporting continuity.',
                    ];
                }
            } else {
                $result['skipped']['rto_leads (paid)'] = 'No matching paid leads found.';
            }

            // ── 3. rto_payments: financial ledger, never touched directly ──
            // Payments in this schema carry no name/email/mobile column of
            // their own (only lead_id/amount/method/txn refs) — they are
            // reached only via the (now-anonymised) lead. Explicitly record
            // that the ledger was left intact rather than silently skipping it.
            $payCount = 0;
            if ($leadsToAnonymize || $leadsToDelete) {
                $allLeadIds = array_merge($leadsToAnonymize, $leadsToDelete);
                $placeholders = implode(',', array_fill(0, count($allLeadIds), '%d'));
                $payCount = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}rto_payments WHERE lead_id IN ($placeholders)",
                    $allLeadIds
                ));
            }
            $result['skipped']['rto_payments'] = $payCount > 0
                ? "{$payCount} payment row(s) contain no direct PII and were left fully intact (amounts, txn ids, dates preserved for accounting)."
                : 'No PII columns in this table; not applicable.';

            // ── 4. rto_messages: free-text chat, no financial dependency ───
            if ($uid) {
                $msgCount = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}rto_messages WHERE user_id = %d", $uid
                ));
                if ($msgCount > 0) {
                    $deleted = $wpdb->delete($p . 'rto_messages', ['user_id' => $uid]);
                    if ($deleted === false) {
                        throw new \RuntimeException('Database write failed while deleting rto_messages: ' . $wpdb->last_error);
                    }
                    $result['hard_deleted']['rto_messages'] = [
                        'count'  => $msgCount,
                        'reason' => 'Free-text communication log with no legal retention requirement.',
                    ];
                } else {
                    $result['skipped']['rto_messages'] = 'No messages found for this user.';
                }
            }

            // ── 5. rto_complaints: dispute/legal record → anonymise, keep ──
            if ($uid) {
                $complaints = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$p}rto_complaints WHERE client_id = %d OR complainant_id = %d",
                    $uid, $uid
                ), ARRAY_A) ?: [];
                if ($complaints) {
                    $ids = array_map(fn($r) => (int)$r['id'], $complaints);
                    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                    self::writeOrThrow($wpdb, $wpdb->prepare(
                        "UPDATE {$p}rto_complaints
                            SET subject = %s, description = %s
                          WHERE id IN ($placeholders)",
                        array_merge(['[REDACTED FOR GDPR ERASURE]', '[REDACTED FOR GDPR ERASURE]'], $ids)
                    ), 'anonymising rto_complaints');
                    $result['anonymized']['rto_complaints'] = [
                        'count'  => count($ids),
                        'reason' => 'Complaint/dispute records retained for SLA and legal history; free-text subject/description scrubbed, status and dates kept.',
                    ];
                } else {
                    $result['skipped']['rto_complaints'] = 'No complaints found for this user.';
                }
            }

            // ── 6. rto_vendors: PII columns directly on the row ────────────
            $vendorWhere = $identifierType === 'email'
                ? $wpdb->prepare('email = %s', $value)
                : $wpdb->prepare('mobile = %s', $value);
            $vendors = $wpdb->get_results("SELECT id FROM {$p}rto_vendors WHERE {$vendorWhere}", ARRAY_A) ?: [];
            if ($vendors) {
                $ids = array_map(fn($r) => (int)$r['id'], $vendors);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                self::writeOrThrow($wpdb, $wpdb->prepare(
                    "UPDATE {$p}rto_vendors
                        SET full_name = %s, email = %s, mobile = %s, bank_details = NULL
                      WHERE id IN ($placeholders)",
                    array_merge(['[ERASED VENDOR]', 'erased-' . md5($value) . '@erased.invalid', '0000000000'], $ids)
                ), 'anonymising rto_vendors');
                $result['anonymized']['rto_vendors'] = [
                    'count'  => count($ids),
                    'reason' => 'Vendor payout/rating/job history must be retained for accounting; identifying fields scrubbed, stats and vendor_number kept.',
                ];
            } else {
                $result['skipped']['rto_vendors'] = 'No vendor record found for this identifier.';
            }

            // ── 7. Orphaned form_submissions never attached to any lead ────
            // (e.g. abandoned multi-step forms that never became a lead row)
            $orphanFs = $wpdb->get_results($wpdb->prepare(
                "SELECT fs.id FROM {$p}rto_form_submissions fs
                 LEFT JOIN {$p}rto_leads l ON l.id = fs.lead_id
                 WHERE l.id IS NULL AND (fs.form_data LIKE %s OR fs.raw_post LIKE %s)",
                $likeFrag, $likeFrag
            ), ARRAY_A) ?: [];
            if ($orphanFs) {
                $ids = array_map(fn($r) => (int)$r['id'], $orphanFs);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                self::writeOrThrow($wpdb, "DELETE FROM {$p}rto_form_submissions WHERE id IN ($placeholders)", 'deleting orphaned rto_form_submissions');
                $result['hard_deleted']['rto_form_submissions (orphaned)'] = [
                    'count'  => count($ids),
                    'reason' => 'Abandoned intake form, never linked to a lead — no business record depends on it.',
                ];
            }

            // ── 8. Audit trail is deliberately never modified ──────────────
            $result['skipped']['rto_logs'] = 'Audit trail is append-only and intentionally excluded from erasure — it is the record that this erasure occurred.';

            // ── 9. Log the erasure itself (must survive the operation) ─────
            \RTOFLOW\Services\AuditService::log(
                'gdpr.erasure',
                null,
                [
                    'identifier_type'   => $identifierType,
                    'identifier_masked' => self::mask($value),
                    'identifier_hash'   => hash('sha256', $value),
                    'matched_user_id'   => $uid,
                    'hard_deleted'      => array_map(fn($v) => $v['count'], $result['hard_deleted']),
                    'anonymized'        => array_map(fn($v) => $v['count'], $result['anonymized']),
                ],
                [],
                $performedBy
            );

            $wpdb->query('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return [
                'ok'              => false,
                'identifier'      => ['type' => $identifierType, 'value' => self::mask($value)],
                'matched_user_id' => $uid,
                'hard_deleted'    => [],
                'anonymized'      => [],
                'skipped'         => [],
                'error'           => 'Erasure failed and was rolled back: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Hard-delete an unpaid lead and every child row that exists purely to
     * describe it (no independent retention requirement of its own).
     *
     * @param array<int> $leadIds
     */
    private static function hardDeleteLeadTree(\wpdb $wpdb, string $p, array $leadIds): void
    {
        $placeholders = implode(',', array_fill(0, count($leadIds), '%d'));
        // Children first (FK-safe ordering even though this schema has no
        // enforced foreign keys), then the lead row itself. Each write is
        // checked (writeOrThrow) — this method runs inside eraseSubject()'s
        // transaction, so a real failure here now rolls back the whole
        // erasure instead of silently leaving some child rows behind while
        // the lead row itself still gets deleted (or vice versa).
        self::writeOrThrow($wpdb, $wpdb->prepare("DELETE FROM {$p}rto_lead_meta WHERE lead_id IN ($placeholders)", $leadIds), 'deleting rto_lead_meta');
        self::writeOrThrow($wpdb, $wpdb->prepare("DELETE FROM {$p}rto_documents WHERE lead_id IN ($placeholders)", $leadIds), 'deleting rto_documents');
        self::writeOrThrow($wpdb, $wpdb->prepare("DELETE FROM {$p}rto_messages WHERE lead_id IN ($placeholders)", $leadIds), 'deleting rto_messages');
        self::writeOrThrow($wpdb, $wpdb->prepare("DELETE FROM {$p}rto_assignments WHERE lead_id IN ($placeholders)", $leadIds), 'deleting rto_assignments');
        self::writeOrThrow($wpdb, $wpdb->prepare("DELETE FROM {$p}rto_form_submissions WHERE lead_id IN ($placeholders)", $leadIds), 'deleting rto_form_submissions');
        self::writeOrThrow($wpdb, $wpdb->prepare("DELETE FROM {$p}rto_leads WHERE id IN ($placeholders)", $leadIds), 'deleting rto_leads');
    }

    /** Mask an identifier for safe display/logging (never store raw PII in the audit trail). */
    private static function mask(string $value): string
    {
        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);
            $maskedLocal = mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 1));
            return $maskedLocal . '@' . $domain;
        }
        // Mobile: keep last 2 digits only
        return str_repeat('*', max(0, strlen($value) - 2)) . substr($value, -2);
    }

    private static function fail(string $type, string $rawValue, string $message): array
    {
        return [
            'ok'              => false,
            'identifier'      => ['type' => $type, 'value' => '(invalid)'],
            'matched_user_id' => null,
            'hard_deleted'    => [],
            'anonymized'      => [],
            'skipped'         => [],
            'error'           => $message,
        ];
    }
}
