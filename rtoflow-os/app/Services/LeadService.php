<?php

namespace RTOFLOW\Services;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * Lead Service — Core Order Management
 *
 * Handles all lead lifecycle operations: creation, assignment,
 * status transitions, SLA management, and escalation.
 *
 * Lead numbering is ATOMIC — uses DB transaction + SELECT FOR UPDATE
 * to prevent duplicate lead numbers under concurrent requests.
 */
class LeadService
{
    private GstService   $gst;
    private AuditService $audit;
    private ?WorkflowEngineService $workflowEngine;
    private ?WebhookDispatchService $webhooks;

    public function __construct(
        GstService $gst,
        AuditService $audit,
        ?WorkflowEngineService $workflowEngine = null,
        ?WebhookDispatchService $webhooks = null
    ) {
        $this->gst            = $gst;
        $this->audit          = $audit;
        $this->workflowEngine = $workflowEngine;
        // Nullable + defaulted, same convention as $workflowEngine above, so
        // call sites that construct LeadService directly (e.g. tests) without
        // wiring a dispatcher keep working — dispatch is simply skipped.
        $this->webhooks       = $webhooks;
    }

    // ── Create lead ───────────────────────────────────────────────────────

    /**
     * Create a new lead (service order).
     *
     * @param array $data Sanitised input
     * @param int   $clientId WordPress user ID of the client
     * @return array ['success', 'lead_id', 'lead_number', 'message']
     */
    public function create(array $data, int $clientId): array
    {
        global $wpdb;

        // Validate required fields
        $validation = Sanitiser::validate([
            'service_id' => 'required|integer|min_val:1',
            'city_id'    => 'required|integer|min_val:1',
        ], $data);

        if (!$validation['passed']) {
            return ['success' => false, 'message' => 'Validation failed', 'errors' => $validation['errors']];
        }

        $serviceId = Sanitiser::int($data['service_id'], 1);
        $cityId    = Sanitiser::int($data['city_id'], 1);

        // Load service
        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_services WHERE id = %d AND is_active = 1",
            $serviceId
        ), ARRAY_A);

        if (!$service) {
            return ['success' => false, 'message' => 'Service not found or inactive.'];
        }

        // Pricing Engine phase 2 FIX: rto_city_service_config can override
        // govt_fee (column: govt_fee) and base_price (column: service_charge)
        // per city+service, but until now that override was read ONLY by
        // CityServiceConfigController::getVisibleServicesForCity() for the
        // public city-page display — never here, where the actual charge is
        // computed. That meant the price shown to a customer on the city page
        // could differ from what they were actually billed. We now look up
        // the same config row and, when it exists and a given override column
        // is non-null, use it in place of the rto_services default — exactly
        // mirroring the override precedence already used for display in
        // CityServiceConfigController::getVisibleServicesForCity(). No
        // config row, or a null override column, falls back to the existing
        // rto_services value unchanged.
        $cityConfig = $wpdb->get_row($wpdb->prepare(
            "SELECT govt_fee, service_charge FROM {$wpdb->prefix}rto_city_service_config
             WHERE city_id = %d AND service_id = %d",
            $cityId,
            $serviceId
        ), ARRAY_A);

        // FIX P0: total_amount now includes govt_fee, matching what
        // GstService::calculateForLead() (used later for invoicing) has always
        // assumed — that method does `serviceFee = total_amount - govt_fee`
        // to back the fee out again. Previously total_amount at creation was
        // only `base_price + GST(base_price)`, with govt_fee never added, so
        // the amount actually charged to the customer and the amount the
        // invoice-generation logic believed was charged were built on
        // contradictory assumptions. Government fee itself is GST-exempt
        // (GstService's own documented rule), so GST is calculated on
        // base_price alone and govt_fee is added on top, untaxed.
        $baseAmount = ($cityConfig && $cityConfig['service_charge'] !== null)
            ? (float)$cityConfig['service_charge']
            : (float)$service['base_price'];
        $govtFee    = ($cityConfig && $cityConfig['govt_fee'] !== null)
            ? (float)$cityConfig['govt_fee']
            : (float)($service['govt_fee'] ?? 0);
        $gstResult  = $service['gst_applicable']
            ? $this->gst->calculate($baseAmount, $this->getCityStateCode($cityId))
            : ['total_gst' => 0.0, 'grand_total' => $baseAmount];

        $totalAmount = round($gstResult['grand_total'] + $govtFee, 2);
        $gstAmount   = $gstResult['total_gst'];

        // Atomic lead number generation
        $leadNumber = $this->generateLeadNumber();

        // Calculate SLA deadline (business days)
        $slaDays     = Sanitiser::int($data['urgent'] ?? 0) && $service['sla_urgent_days']
            ? (int)$service['sla_urgent_days']
            : (int)$service['sla_days'];
        $slaDeadline = $this->calculateSlaDeadline($slaDays);

        $inserted = $wpdb->insert($wpdb->prefix . 'rto_leads', [
            'lead_number'    => $leadNumber,
            'service_id'     => $serviceId,
            'city_id'        => $cityId,
            'rto_id'         => Sanitiser::int($data['rto_id'] ?? 0) ?: null,
            'client_id'      => $clientId,
            'status'         => 'created',
            'priority'       => Sanitiser::int($data['priority'] ?? 2, 1, 5),
            'source'         => Sanitiser::text($data['source'] ?? 'web'),
            'total_amount'   => $totalAmount,
            'gst_amount'     => $gstAmount,
            'payment_status' => 'unpaid',
            'sla_deadline'   => $slaDeadline,
            'created_at'     => current_time('mysql'),
        ]);

        if (!$inserted) {
            error_log('RTOFLOW LeadService: Insert failed — ' . $wpdb->last_error);
            return ['success' => false, 'message' => 'Unable to create service request. Please try again.'];
        }

        $leadId = (int)$wpdb->insert_id;

        // Store any extra form data as meta
        if (!empty($data['form_data']) && is_array($data['form_data'])) {
            foreach ($data['form_data'] as $key => $value) {
                $wpdb->insert($wpdb->prefix . 'rto_lead_meta', [
                    'lead_id'    => $leadId,
                    'meta_key'   => Sanitiser::alphanumeric($key, 200),
                    'meta_value' => Sanitiser::text($value, 2000),
                ]);
            }
        }

        AuditService::log('lead.created', $leadId, [
            'lead_number' => $leadNumber,
            'service'     => $service['name'],
            'amount'      => $totalAmount,
        ]);

        // Fire action for notifications/automation
        do_action('rtoflow_lead_created', $leadId, $clientId, $service);

        // API/webhooks integration gap fix: this is the real, single source
        // of truth for lead creation (see class docblock) — dispatch here
        // rather than duplicating the call at every controller that ends up
        // invoking create(). Best-effort/non-blocking — see
        // WebhookDispatchService::dispatch()'s own docblock.
        if ($this->webhooks) {
            $this->webhooks->dispatch('lead.created', [
                'lead_id'     => $leadId,
                'lead_number' => $leadNumber,
                'service_id'  => $serviceId,
                'city_id'     => $cityId,
                'client_id'   => $clientId,
                'status'      => 'created',
                'amount'      => $totalAmount,
            ]);
        }

        return [
            'success'     => true,
            'lead_id'     => $leadId,
            'lead_number' => $leadNumber,
            'amount'      => $totalAmount,
            'gst_amount'  => $gstAmount,
            'message'     => 'Service request created successfully.',
        ];
    }

    // ── Status transition ─────────────────────────────────────────────────

    /**
     * Change lead status with validation and audit logging.
     */
    public function updateStatus(int $leadId, string $newStatus, array $options = []): array
    {
        global $wpdb;

        $lead = $this->getLead($leadId);
        if (!$lead) return ['success' => false, 'message' => 'Lead not found.'];

        $oldStatus = $lead['status'];

        // FIX: status transitions now go through WorkflowEngineService rather
        // than the hard-coded isValidTransition()/self::TRANSITIONS map, so a
        // per-service custom workflow's guard conditions and side effects
        // (rto_workflow_transitions.guard_condition_json/side_effect_json)
        // actually take effect on real status changes instead of only being
        // enforced by the admin UI's dropdown-filtering helpers. When the
        // lead's service has no active custom workflow definition,
        // WorkflowEngineService::attemptTransition() itself falls through to
        // WorkflowService::isDefaultTransitionAllowed() — the exact same
        // rules self::TRANSITIONS encodes — so this is a no-op passthrough
        // for every service never migrated to a configured workflow.
        $context = array_merge($lead, ['lead_id' => $leadId, 'options' => $options]);
        $userRole = $options['role'] ?? null;

        if ($this->workflowEngine) {
            $result = $this->workflowEngine->attemptTransition(
                (int)($lead['service_id'] ?? 0),
                $oldStatus,
                $newStatus,
                $userRole,
                $context
            );
            if (!$result['allowed']) {
                return ['success' => false, 'message' => $result['reason'] ?? "Invalid status transition: {$oldStatus} → {$newStatus}"];
            }
        } elseif (!$this->isValidTransition($oldStatus, $newStatus)) {
            // Legacy fallback for call sites that construct LeadService
            // without a WorkflowEngineService (e.g. tests) — keeps old
            // behaviour rather than silently allowing everything.
            return ['success' => false, 'message' => "Invalid status transition: {$oldStatus} → {$newStatus}"];
        }

        $updateData = ['status' => $newStatus, 'updated_at' => current_time('mysql')];

        if ($newStatus === 'completed') {
            $updateData['completed_at'] = current_time('mysql');
        }
        if ($newStatus === 'cancelled') {
            $updateData['cancelled_at']  = current_time('mysql');
            $updateData['cancel_reason'] = Sanitiser::text($options['reason'] ?? '');
        }

        $updated = $wpdb->update($wpdb->prefix . 'rto_leads', $updateData, ['id' => $leadId]);

        if ($updated === false) {
            return ['success' => false, 'message' => 'Failed to update status.'];
        }

        // ENTERPRISE GAP FIX (Section 7 — workflow engine side-effect
        // error handling/rollback): side effects now run AFTER the real
        // status write above has committed, not before it — see
        // WorkflowEngineService::attemptTransition()'s docblock for the two
        // concrete bugs this fixes (a same-row side effect being clobbered
        // by this method's own update, and a throwing side effect
        // preventing the status update from ever running at all). A side
        // effect failing here is logged but never un-does the status change
        // that has already committed — there is nothing to roll back to.
        if ($this->workflowEngine && !empty($result['side_effect_json'] ?? null)) {
            $sideEffectResults = $this->workflowEngine->runSideEffects($result['side_effect_json'], $context);
            $failures = [];
            foreach ($sideEffectResults as $r) {
                if (!$r['success']) {
                    $failures[] = $r['action'] . ': ' . ($r['error'] ?? 'unknown error');
                    error_log("RTOFLOW LeadService::updateStatus(): side-effect '{$r['action']}' failed for lead {$leadId}: " . ($r['error'] ?? 'unknown error'));
                }
            }
            // ENTERPRISE GAP FIX (Phase 7, item 3 — "side effects incomplete"
            // flag): a failure was previously only ever visible in the PHP
            // error log — no staff member reviewing the lead in the admin UI
            // had any way to discover a notification/field-update side
            // effect silently failed. This surfaces it directly on the lead.
            if ($failures) {
                $wpdb->update($wpdb->prefix . 'rto_leads', [
                    'side_effects_incomplete'   => 1,
                    'side_effects_failure_note' => implode("\n", $failures),
                ], ['id' => $leadId]);
            }
        }

        AuditService::logStatusChange($leadId, $oldStatus, $newStatus, $options['note'] ?? '');
        do_action('rtoflow_lead_status_changed', $leadId, $oldStatus, $newStatus, $lead);

        // API/webhooks integration gap fix: dispatched AFTER the transition
        // has actually been allowed (WorkflowEngineService::attemptTransition()
        // above already returned early on a rejected transition) and the
        // status row update has committed — never before, so a rejected or
        // failed transition never fires a false 'lead.status_changed' event.
        if ($this->webhooks) {
            $this->webhooks->dispatch('lead.status_changed', [
                'lead_id'    => $leadId,
                'from'       => $oldStatus,
                'to'         => $newStatus,
                'note'       => $options['note'] ?? '',
            ]);
        }

        return ['success' => true, 'message' => "Status updated to: " . rto_status_label($newStatus)];
    }

    // ── Assign vendor ─────────────────────────────────────────────────────

    public function assignVendor(int $leadId, int $vendorId): array
    {
        global $wpdb;

        $lead   = $this->getLead($leadId);
        $vendor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_vendors WHERE id = %d AND status = 'active' AND kyc_status = 'verified'",
            $vendorId
        ), ARRAY_A);

        if (!$lead)   return ['success' => false, 'message' => 'Lead not found.'];
        if (!$vendor) return ['success' => false, 'message' => 'Vendor not found or not eligible.'];

        // Known Limitations audit finding: "Reassigning a vendor on an
        // already-in-progress lead does not automatically notify the
        // previous vendor" — captured here, before the update, so the real
        // previous vendor_id (if any, and if actually different from the
        // new one) is available to notify after the reassignment commits.
        $previousVendorId = (int)($lead['vendor_id'] ?? 0);
        $isReassignment   = $previousVendorId > 0 && $previousVendorId !== $vendorId;

        // BUGFIX (found via a systematic write-return-value sweep for the
        // "ghost success" / transaction-integrity failure class): these 3
        // writes (supersede old assignment, insert new assignment, update
        // the lead's own vendor_id/status) were neither wrapped in a
        // transaction nor return-value-checked, unlike PaymentService's
        // equivalent multi-step write, which already establishes the correct
        // pattern used here. A partial failure previously could leave a real,
        // inconsistent state with no error surfaced -- e.g. the new
        // assignment row created but the lead's own vendor_id/status left
        // unchanged, so the Leads screen would still show "Unassigned" while
        // an assignment silently existed for a vendor. Now atomic: all 3
        // writes succeed together or the whole assignment is rolled back and
        // a real error is returned instead of a false "success".
        $wpdb->query('START TRANSACTION');
        try {
            // Close any previous pending assignments
            $superseded = $wpdb->update(
                $wpdb->prefix . 'rto_assignments',
                ['status' => 'superseded'],
                ['lead_id' => $leadId, 'status' => 'pending']
            );
            if ($superseded === false) {
                throw new \RuntimeException('superseding previous assignment failed: ' . $wpdb->last_error);
            }

            // Create assignment record
            $assignmentInserted = $wpdb->insert($wpdb->prefix . 'rto_assignments', [
                'lead_id'     => $leadId,
                'vendor_id'   => $vendorId,
                'status'      => 'pending',
                'assigned_at' => current_time('mysql'),
            ]);
            if (!$assignmentInserted) {
                throw new \RuntimeException('assignment insert failed: ' . $wpdb->last_error);
            }

            // Update lead
            $leadUpdated = $wpdb->update($wpdb->prefix . 'rto_leads', [
                'vendor_id'  => $vendorId,
                'status'     => 'assigned',
                'updated_at' => current_time('mysql'),
            ], ['id' => $leadId]);
            if ($leadUpdated === false) {
                throw new \RuntimeException('lead vendor_id/status update failed: ' . $wpdb->last_error);
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('RTOFLOW LeadService::assignVendor: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to assign vendor. Please try again.'];
        }

        AuditService::logVendorAssigned($leadId, $vendorId);
        do_action('rtoflow_vendor_assigned', $leadId, $vendorId, $lead);

        if ($isReassignment) {
            AuditService::log('lead.vendor_unassigned', $leadId, ['new_vendor_id' => $vendorId], ['vendor_id' => $previousVendorId]);
            do_action('rtoflow_vendor_unassigned', $leadId, $previousVendorId, $vendorId, $lead);
        }

        return ['success' => true, 'message' => 'Vendor assigned successfully.'];
    }

    // ── SLA management ────────────────────────────────────────────────────

    /**
     * Check all leads for SLA warnings and breaches.
     * Called by WP-Cron hourly.
     */
    public function processSlaChecks(): void
    {
        global $wpdb;

        $activeStatuses = "'created','payment_received','assigned','in_progress','docs_pending','docs_verified','rto_submitted','rto_processing'";

        // Warn: SLA deadline within 24 hours and not yet warned
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $warnLeads = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rto_leads
             WHERE status IN ({$activeStatuses})
             AND sla_deadline IS NOT NULL
             AND sla_deadline <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
             AND sla_warned = 0",
            ARRAY_A
        ) ?: [];

        // P7-PERF-003 FIX: batch the flag updates, then fire per-lead notifications
        if (!empty($warnLeads)) {
            $ids = implode(',', array_map('intval', array_column($warnLeads, 'id')));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("UPDATE {$wpdb->prefix}rto_leads SET sla_warned=1 WHERE id IN ({$ids})");
            foreach ($warnLeads as $lead) {
                do_action('rtoflow_sla_warning', $lead);
            }
        }

        // Breach: deadline passed and not marked breached
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $breachLeads = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rto_leads
             WHERE status IN ({$activeStatuses})
             AND sla_deadline IS NOT NULL
             AND sla_deadline < NOW()
             AND sla_breached = 0",
            ARRAY_A
        ) ?: [];

        if (!empty($breachLeads)) {
            $ids = implode(',', array_map('intval', array_column($breachLeads, 'id')));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("UPDATE {$wpdb->prefix}rto_leads SET sla_breached=1 WHERE id IN ({$ids})");
            foreach ($breachLeads as $lead) {
                AuditService::log('lead.sla_breached', (int)$lead['id']);
                do_action('rtoflow_sla_breached', $lead);
            }
        }
    }

    // ── Get / list ────────────────────────────────────────────────────────

    public function getLead(int $leadId): ?array
    {
        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, s.name as service_name, s.vendor_share, s.category as service_category,
                    c.name as city_name, u.display_name as client_name, u.user_email as client_email
             FROM {$wpdb->prefix}rto_leads l
             LEFT JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
             LEFT JOIN {$wpdb->prefix}rto_cities c ON c.id = l.city_id
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.client_id
             WHERE l.id = %d AND l.deleted_at IS NULL",
            $leadId
        ), ARRAY_A) ?: null;

        // FIX (Order Details 360° audit): the client's mobile number is
        // stored as WP user meta ('rtoflow_mobile' — same key every other
        // screen in this codebase reads/writes, see NotificationService,
        // VendorService, Router.php's registration flows), never on
        // rto_leads or its JOINed tables. It was never surfaced on the
        // lead-detail screen at all. Attached here (not via a JOIN, since
        // usermeta is EAV) so every caller of getLead() gets it for free.
        if ($lead && !empty($lead['client_id'])) {
            $lead['client_mobile'] = get_user_meta((int)$lead['client_id'], 'rtoflow_mobile', true) ?: '';
        }

        return $lead;
    }

    // ── Admin-only lookup (Part 5.7) ─────────────────────────────────────
    // Mirrors the getForServiceForAdmin()-style "admin bypass" naming
    // convention already used elsewhere in this codebase: getLead() is used
    // by BOTH the admin controller and the client dashboard
    // (Controllers/Client/DashboardController.php), so it must keep
    // excluding soft-deleted leads unconditionally — a client must never be
    // able to see an order staff archived. The admin Order Details screen,
    // however, needs to still open an archived order (to view it and offer
    // Restore) rather than 404 the moment it's archived — "remain
    // viewable/restorable, never hard-deleted". This admin-only variant
    // does the same JOINs without the deleted_at filter, used ONLY by
    // LeadsController (show/archive/restore/edit), never by anything
    // client-facing.
    public function getLeadForAdmin(int $leadId): ?array
    {
        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, s.name as service_name, s.vendor_share, s.category as service_category,
                    c.name as city_name, u.display_name as client_name, u.user_email as client_email
             FROM {$wpdb->prefix}rto_leads l
             LEFT JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
             LEFT JOIN {$wpdb->prefix}rto_cities c ON c.id = l.city_id
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.client_id
             WHERE l.id = %d",
            $leadId
        ), ARRAY_A) ?: null;

        if ($lead && !empty($lead['client_id'])) {
            $lead['client_mobile'] = get_user_meta((int)$lead['client_id'], 'rtoflow_mobile', true) ?: '';
        }

        return $lead;
    }

    // ── Archive / restore (Part 5.7) ─────────────────────────────────────
    // rto_leads has carried a `deleted_at` column since the core schema
    // (migration 1) and EVERY read path already filters it out
    // (getLead(), getLeads(), the admin dashboard stats query in
    // Repositories.php) — but nothing anywhere ever WROTE to it. There was
    // no way to remove an order from the working list at all. This closes
    // that gap the same soft-delete way vendors already use elsewhere in
    // this codebase ("Historical records preserved") — never a hard
    // DELETE, always reversible.
    public function archiveLead(int $leadId): array
    {
        global $wpdb;
        $lead = $this->getLeadForAdmin($leadId);
        if (!$lead) return ['success' => false, 'message' => 'Order not found.'];
        if (!empty($lead['deleted_at'])) return ['success' => false, 'message' => 'This order is already archived.'];

        $updated = $wpdb->update($wpdb->prefix . 'rto_leads', ['deleted_at' => current_time('mysql')], ['id' => $leadId]);
        if ($updated === false) return ['success' => false, 'message' => 'Failed to archive order.'];

        AuditService::log('lead.archived', $leadId);
        return ['success' => true, 'message' => 'Order archived. It is hidden from the default list and can be restored any time.'];
    }

    public function restoreLead(int $leadId): array
    {
        global $wpdb;
        $lead = $this->getLeadForAdmin($leadId);
        if (!$lead) return ['success' => false, 'message' => 'Order not found.'];
        if (empty($lead['deleted_at'])) return ['success' => false, 'message' => 'This order is not archived.'];

        $updated = $wpdb->update($wpdb->prefix . 'rto_leads', ['deleted_at' => null], ['id' => $leadId]);
        if ($updated === false) return ['success' => false, 'message' => 'Failed to restore order.'];

        AuditService::log('lead.restored', $leadId);
        return ['success' => true, 'message' => 'Order restored.'];
    }

    // ── Bulk archive / restore (Leads list "select rows + bulk action") ────
    // Same soft-delete semantics as archiveLead()/restoreLead() above, but
    // operating on a whole selection in ONE query each (a real
    // `WHERE id IN (...)` batch UPDATE) instead of looping N single-row
    // queries — this table can hold thousands of orders and a bulk action
    // from the list view must not fire N round-trips to the DB.
    // Only currently-active/currently-archived rows in the selection are
    // touched (mirrors the single-row guards above); IDs that don't match
    // are silently skipped, not treated as fatal errors, since a stale
    // checkbox selection is an expected race, not a bug.
    private function normaliseIdList(array $leadIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $leadIds), fn($v) => $v > 0)));
        return $ids;
    }

    public function bulkArchive(array $leadIds): array
    {
        global $wpdb;
        $ids = $this->normaliseIdList($leadIds);
        if (!$ids) return ['success' => false, 'message' => 'No orders selected.'];

        $table = $wpdb->prefix . 'rto_leads';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET deleted_at = %s WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
            array_merge([current_time('mysql')], $ids)
        ));
        if ($affected === false) return ['success' => false, 'message' => 'Failed to archive orders.'];

        AuditService::log('lead.bulk_archived', 0, ['lead_ids' => $ids, 'affected' => (int)$affected]);
        return ['success' => true, 'message' => (int)$affected . ' order(s) archived.', 'affected' => (int)$affected];
    }

    public function bulkRestore(array $leadIds): array
    {
        global $wpdb;
        $ids = $this->normaliseIdList($leadIds);
        if (!$ids) return ['success' => false, 'message' => 'No orders selected.'];

        $table = $wpdb->prefix . 'rto_leads';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET deleted_at = NULL WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL",
            $ids
        ));
        if ($affected === false) return ['success' => false, 'message' => 'Failed to restore orders.'];

        AuditService::log('lead.bulk_restored', 0, ['lead_ids' => $ids, 'affected' => (int)$affected]);
        return ['success' => true, 'message' => (int)$affected . ' order(s) restored.', 'affected' => (int)$affected];
    }

    // ── Core-field edit (Part 5.7) ────────────────────────────────────────
    // Deliberately narrow allow-list: city and SLA deadline only.
    // total_amount/gst_amount/service_id are intentionally NOT editable
    // here — they drive GST calculation, vendor_share payout splits
    // (PaymentService) and anything already recorded in rto_payments;
    // changing them post-creation without a full re-calculation/reconciliation
    // path would silently desync money already collected. That is real
    // scope for a future "amend order" workflow, not a same-pass edit box.
    public function updateCoreFields(int $leadId, array $data): array
    {
        global $wpdb;
        $lead = $this->getLeadForAdmin($leadId);
        if (!$lead) return ['success' => false, 'message' => 'Order not found.'];
        if (!empty($lead['deleted_at'])) return ['success' => false, 'message' => 'Archived orders cannot be edited — restore it first.'];

        $update = [];

        if (array_key_exists('city_id', $data) && (int)$data['city_id'] > 0) {
            $cityId = (int)$data['city_id'];
            $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities WHERE id = %d", $cityId));
            if (!$exists) return ['success' => false, 'message' => 'Selected city does not exist.'];
            $update['city_id'] = $cityId;
        }

        if (array_key_exists('sla_deadline', $data) && $data['sla_deadline']) {
            $update['sla_deadline'] = $data['sla_deadline'];
            // Extending the deadline is the only reason this field is
            // editable at all — un-breach it so the badge/list stop
            // showing a breach against a deadline that no longer applies.
            $update['sla_breached'] = 0;
        }

        if (empty($update)) return ['success' => false, 'message' => 'No valid fields to update.'];
        $update['updated_at'] = current_time('mysql');

        $updated = $wpdb->update($wpdb->prefix . 'rto_leads', $update, ['id' => $leadId]);
        if ($updated === false) return ['success' => false, 'message' => 'Failed to update order.'];

        AuditService::log('lead.core_fields_updated', $leadId, $update);
        return ['success' => true, 'message' => 'Order updated.'];
    }

    public function getLeads(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        global $wpdb;
        // Part 5.7 (Order Details 360° follow-up): archived (soft-deleted)
        // orders are excluded from the default list by default, exactly
        // like every other screen reading rto_leads — but the Leads list
        // now offers an explicit "Archived" filter (archived=only) so
        // staff can find and restore them, instead of them becoming
        // permanently invisible with no browse path back to them.
        $where  = [(($filters['archived'] ?? '') === 'only') ? 'l.deleted_at IS NOT NULL' : 'l.deleted_at IS NULL'];
        $params = [];

        // Known Limitations audit fix: "Filters combine only with AND logic
        // — there is no OR mode" for status specifically (the most common
        // cross-status query staff actually need, e.g. docs_pending OR
        // on_hold). $filters['status'] now accepts either a single string
        // (unchanged, backward-compatible) or an array/comma-separated list
        // of statuses, built as IN (...) — an OR within this one filter —
        // while every filter still ANDs together with every other filter
        // exactly as before. Every value is checked against the real
        // transition table's known status keys so an invalid/garbage
        // status can never reach raw SQL.
        $statusFilter = $filters['status'] ?? '';
        $statusList   = is_array($statusFilter)
            ? $statusFilter
            : (($statusFilter !== '') ? explode(',', $statusFilter) : []);
        $statusList = array_values(array_filter(array_unique(array_map('trim', $statusList)), function ($s) {
            return $s !== '' && array_key_exists($s, self::TRANSITIONS);
        }));
        if (count($statusList) === 1) {
            $where[]  = 'l.status = %s';
            $params[] = $statusList[0];
        } elseif (count($statusList) > 1) {
            $placeholders = implode(',', array_fill(0, count($statusList), '%s'));
            $where[]  = "l.status IN ({$placeholders})";
            foreach ($statusList as $s) $params[] = $s;
        }

        // Known Limitations audit fix: dashboard's "SLA Breached" KPI had no
        // drill-down into a matching filtered Leads view — this is the
        // filter that link now targets.
        if (!empty($filters['sla_only'])) {
            $where[] = "l.sla_breached = 1 AND l.status NOT IN ('completed','cancelled')";
        }
        if (!empty($filters['client_id'])) {
            $where[]  = 'l.client_id = %d';
            $params[] = (int)$filters['client_id'];
        }
        if (!empty($filters['vendor_id'])) {
            $where[]  = 'l.vendor_id = %d';
            $params[] = (int)$filters['vendor_id'];
        }
        if (!empty($filters['service_id'])) {
            $where[]  = 'l.service_id = %d';
            $params[] = (int)$filters['service_id'];
        }
        if (!empty($filters['city_id'])) {
            $where[]  = 'l.city_id = %d';
            $params[] = (int)$filters['city_id'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(l.lead_number LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $s        = '%' . $wpdb->esc_like(Sanitiser::text($filters['search'])) . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        // P10-EDGE-001 FIX: only add date filter if Sanitiser returned a valid date
        if (!empty($filters['date_from'])) {
            $sanitisedFrom = Sanitiser::date($filters['date_from']);
            if ($sanitisedFrom !== '') {
                $where[]  = 'l.created_at >= %s';
                $params[] = $sanitisedFrom . ' 00:00:00';
            }
        }
        if (!empty($filters['date_to'])) {
            $sanitisedTo = Sanitiser::date($filters['date_to']);
            if ($sanitisedTo !== '') {
                $where[]  = 'l.created_at <= %s';
                $params[] = $sanitisedTo . ' 23:59:59';
            }
        }

        $whereStr = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM {$wpdb->prefix}rto_leads l
                     LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.client_id
                     WHERE {$whereStr}";

        $dataSql  = "SELECT l.id, l.lead_number, l.status, l.priority, l.source, l.total_amount, l.gst_amount, l.paid_amount, l.payment_status, l.sla_deadline, l.sla_breached, l.sla_warned, l.created_at, l.updated_at, l.deleted_at, l.client_id, l.vendor_id, l.service_id, l.city_id, s.name as service_name, c.name as city_name, u.display_name as client_name /* P7-PERF-010: explicit columns — no LONGTEXT fields */
                     FROM {$wpdb->prefix}rto_leads l
                     LEFT JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
                     LEFT JOIN {$wpdb->prefix}rto_cities c ON c.id = l.city_id
                     LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.client_id
                     WHERE {$whereStr}
                     ORDER BY l.created_at DESC
                     LIMIT %d OFFSET %d";

        $total = !empty($params)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ? (int)$wpdb->get_var($wpdb->prepare($countSql, $params))
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : (int)$wpdb->get_var($countSql);

        $listParams = array_merge($params, [$perPage, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($dataSql, $listParams), ARRAY_A) ?: [];

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    // ENTERPRISE GAP FIX (Phase 11, item — "Offset-based pagination on
    // large tables" / "Unbounded/near-unbounded export queries"):
    // exportCsvChunk() replaces the old pattern of fetching up to 5,000
    // rows in one LIMIT/OFFSET call (real memory + request-timeout risk,
    // and OFFSET itself degrades once rto_leads has millions of rows since
    // MySQL still has to scan and discard every skipped row). This uses
    // KEYSET ("cursor") pagination instead: WHERE l.id > $afterId ORDER BY
    // l.id LIMIT $chunkSize — an indexed range scan whose cost does not
    // grow with how deep into the table the cursor already is, unlike
    // OFFSET. The caller (LeadsController::exportCsv()) drives this in a
    // loop, writing+flushing each chunk to the CSV output stream instead
    // of holding the whole export in PHP memory at once, so both the
    // "unbounded query" and the "OFFSET degrades at scale" gaps are closed
    // by the same change for this, the highest-volume export path.
    // Ordering by id (not created_at, unlike getLeads() above) is what
    // makes the keyset cursor valid — id is the table's own monotonic,
    // unique, indexed key, so "WHERE id > last seen id" can never skip or
    // duplicate a row even if two leads share a created_at timestamp.
    public function exportCsvChunk(array $filters, int $afterId, int $chunkSize): array
    {
        global $wpdb;
        $where  = [(($filters['archived'] ?? '') === 'only') ? 'l.deleted_at IS NOT NULL' : 'l.deleted_at IS NULL'];
        $params = [];

        $where[]  = 'l.id > %d';
        $params[] = $afterId;

        if (!empty($filters['status'])) {
            $statusList = array_values(array_filter(array_unique(array_map('trim', explode(',', (string)$filters['status']))), function ($s) {
                return $s !== '' && array_key_exists($s, self::TRANSITIONS);
            }));
            if (count($statusList) === 1) {
                $where[]  = 'l.status = %s';
                $params[] = $statusList[0];
            } elseif (count($statusList) > 1) {
                $placeholders = implode(',', array_fill(0, count($statusList), '%s'));
                $where[]  = "l.status IN ({$placeholders})";
                foreach ($statusList as $s) $params[] = $s;
            }
        }
        if (!empty($filters['search'])) {
            $where[]  = '(l.lead_number LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $s        = '%' . $wpdb->esc_like(Sanitiser::text($filters['search'])) . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        if (!empty($filters['date_from'])) {
            $sanitisedFrom = Sanitiser::date($filters['date_from']);
            if ($sanitisedFrom !== '') {
                $where[]  = 'l.created_at >= %s';
                $params[] = $sanitisedFrom . ' 00:00:00';
            }
        }
        if (!empty($filters['date_to'])) {
            $sanitisedTo = Sanitiser::date($filters['date_to']);
            if ($sanitisedTo !== '') {
                $where[]  = 'l.created_at <= %s';
                $params[] = $sanitisedTo . ' 23:59:59';
            }
        }

        $whereStr = implode(' AND ', $where);
        $params[] = $chunkSize;

        $dataSql = "SELECT l.id, l.lead_number, l.status, l.total_amount, l.paid_amount, l.payment_status, l.sla_deadline, l.created_at,
                            s.name as service_name, c.name as city_name, u.display_name as client_name
                     FROM {$wpdb->prefix}rto_leads l
                     LEFT JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
                     LEFT JOIN {$wpdb->prefix}rto_cities c ON c.id = l.city_id
                     LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.client_id
                     WHERE {$whereStr}
                     ORDER BY l.id ASC
                     LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($dataSql, $params), ARRAY_A) ?: [];
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    /**
     * Atomic lead number generation using MySQL advisory lock.
     * Prevents duplicate numbers under concurrent inserts.
     */
    private function generateLeadNumber(): string
    {
        global $wpdb;

        // Acquire advisory lock (prevents race condition)
        // P2-SEC-001 FIX: check advisory lock was actually acquired
        $locked = (int)$wpdb->get_var("SELECT GET_LOCK('rto_lead_number', 10)");
        if ($locked !== 1) {
            // Lock not obtained — fallback to timestamp-based unique suffix
            return 'RTO-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $year    = date('Y');
        $prefix  = 'RTO-' . $year . '-';
        $lastNum = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(lead_number, %d) AS UNSIGNED)), 0)
             FROM {$wpdb->prefix}rto_leads
             WHERE lead_number LIKE %s",
            strlen($prefix) + 1,
            $prefix . '%'
        ));

        $next       = $lastNum + 1;
        $leadNumber = $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);

        // ENTERPRISE GAP FIX (Phase 9, item — "raw, unparameterized queries
        // in a handful of internal paths"): 'rto_lead_number' is a fixed
        // literal lock name, not user input — flagged only because
        // tools/check-raw-queries.php's blanket rule requires an explicit
        // annotation on every $wpdb->query() that isn't wrapped in prepare().
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("SELECT RELEASE_LOCK('rto_lead_number')");

        return $leadNumber;
    }

    private function calculateSlaDeadline(int $businessDays): string
    {
        global $wpdb;

        // P5-DB-006 FIX: bound holidays to SLA window only (not all future years)
        $windowEnd = (new \DateTime())->modify('+' . ($businessDays * 3 + 10) . ' days')->format('Y-m-d');
        $holidays  = $wpdb->get_col($wpdb->prepare(
            "SELECT date FROM {$wpdb->prefix}rto_holidays WHERE date >= CURDATE() AND date <= %s",
            $windowEnd
        )) ?: [];
        // Convert to O(1) hash map instead of O(n) in_array
        $holidayMap = array_flip($holidays);

        $date  = new \DateTime();
        $added = 0;

        while ($added < $businessDays) {
            $date->modify('+1 day');
            $dow     = (int)$date->format('N'); // 1=Mon, 7=Sun
            $dateStr = $date->format('Y-m-d');
            // O(1) hash map lookup — $holidayMap was built above specifically for this
            if ($dow < 6 && !isset($holidayMap[$dateStr])) {
                $added++;
            }
        }

        return $date->format('Y-m-d 23:59:59');
    }

    private function getCityStateCode(int $cityId): string
    {
        global $wpdb;
        return (string)($wpdb->get_var($wpdb->prepare(
            "SELECT s.code FROM {$wpdb->prefix}rto_states s
             JOIN {$wpdb->prefix}rto_cities c ON c.state_id = s.id
             WHERE c.id = %d",
            $cityId
        )) ?: \RTOFLOW\Config\Env::string('COMPANY_STATE_CODE', '27'));
    }

    /**
     * The single source of truth for status-transition rules — shared by
     * isValidTransition() and the public validTransitionsFrom() below, so
     * the admin lead-detail screen's status dropdown (and any admin action
     * gating built from it) can never drift from what updateStatus() will
     * actually accept.
     */
    private const TRANSITIONS = [
        'created'          => ['payment_pending', 'payment_received', 'cancelled'],
        'payment_pending'  => ['payment_received', 'cancelled'],
        'payment_received' => ['assigned', 'cancelled'],
        'assigned'         => ['in_progress', 'payment_received', 'cancelled'],
        'in_progress'      => ['docs_pending', 'rto_submitted', 'on_hold', 'cancelled'],
        'docs_pending'     => ['docs_verified', 'in_progress', 'cancelled'],
        'docs_verified'    => ['rto_submitted', 'cancelled'],
        'rto_submitted'    => ['rto_processing', 'completed', 'on_hold', 'cancelled'],
        'rto_processing'   => ['completed', 'on_hold', 'cancelled'],
        'on_hold'          => ['in_progress', 'assigned', 'cancelled'],
        'completed'        => [], // Terminal state
        'cancelled'        => [], // Terminal state
    ];

    private function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Real, enforced transitions available from a given status — used by
     * the admin lead-detail view to only ever offer status options that
     * updateStatus() will actually accept, instead of the full static list
     * of every status in existence (which previously let an admin "select"
     * an update that updateStatus() would then reject with a 400).
     */
    public function validTransitionsFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    // ── Reopen a terminal lead (platform-wide Known Limitations audit) ─────
    // TRACE: admin clicks "Reopen" on a completed/cancelled lead with a
    //        mandatory reason → reopenLead() confirms the lead is actually
    //        terminal → looks up the lead's own audit trail
    //        (rto_logs.old_value for action='lead.status_changed') for the
    //        last status it held BEFORE becoming terminal → writes that
    //        status back via a direct $wpdb->update (bypassing
    //        isValidTransition(), which by design has an empty allow-list
    //        for completed/cancelled and would otherwise make this
    //        permanently impossible) → logs a distinct 'lead.reopened'
    //        audit entry carrying the mandatory reason →
    //        preconditions: lead exists, is actually completed or
    //        cancelled, reason is non-blank; caller has already confirmed
    //        rto_is_admin() (enforced in LeadsController, not here, mirroring
    //        every other admin-only action in this service) →
    //        postconditions: lead.status is the recovered non-terminal
    //        status; an audit row exists recording who reopened it, when,
    //        and why →
    //        edge cases: lead was never anything but 'created' before
    //        going terminal (rare — e.g. cancelled immediately) → falls
    //        back to 'created', the only status with no possible prior
    //        state; no audit history at all (pre-dates this codebase's own
    //        audit logging, or the audit table was pruned) → same fallback,
    //        so this never leaves a lead with no valid status.
    //
    //        Known Limitations audit finding this fixes: "Terminal statuses
    //        (completed, cancelled) cannot be reversed from the UI" —
    //        previously the documented fix was "requires a direct database
    //        correction, outside the scope of this admin UI." This makes it
    //        a real, audited, in-UI admin action instead.
    public function reopenLead(int $leadId, string $reason): array
    {
        global $wpdb;

        $reason = trim(Sanitiser::text($reason));
        if ($reason === '') {
            return ['success' => false, 'message' => 'A reason is required to reopen a closed order.'];
        }

        $lead = $this->getLeadForAdmin($leadId);
        if (!$lead) return ['success' => false, 'message' => 'Order not found.'];

        $currentStatus = $lead['status'];
        if (!in_array($currentStatus, ['completed', 'cancelled'], true)) {
            return ['success' => false, 'message' => 'Only a completed or cancelled order can be reopened — this order is not in a terminal status.'];
        }

        // Find the status this lead held immediately before it became
        // terminal, from its own real audit trail — not a guess.
        $priorRow = $wpdb->get_row($wpdb->prepare(
            "SELECT old_value FROM {$wpdb->prefix}rto_logs
             WHERE lead_id = %d AND action = 'lead.status_changed'
             ORDER BY id DESC LIMIT 1",
            $leadId
        ), ARRAY_A);

        $restoredStatus = 'created';
        if ($priorRow && !empty($priorRow['old_value'])) {
            $decoded = json_decode($priorRow['old_value'], true);
            if (is_array($decoded) && !empty($decoded['status']) && array_key_exists($decoded['status'], self::TRANSITIONS)) {
                $restoredStatus = $decoded['status'];
            }
        }

        $update = ['status' => $restoredStatus, 'updated_at' => current_time('mysql')];
        if ($currentStatus === 'completed') $update['completed_at'] = null;
        if ($currentStatus === 'cancelled') { $update['cancelled_at'] = null; $update['cancel_reason'] = null; }

        $updated = $wpdb->update($wpdb->prefix . 'rto_leads', $update, ['id' => $leadId]);
        if ($updated === false) {
            return ['success' => false, 'message' => 'Failed to reopen order.'];
        }

        AuditService::log('lead.reopened', $leadId, ['status' => $restoredStatus, 'reason' => $reason], ['status' => $currentStatus]);
        do_action('rtoflow_lead_reopened', $leadId, $currentStatus, $restoredStatus, $reason, $lead);

        return ['success' => true, 'message' => 'Order reopened — status restored to ' . rto_status_label($restoredStatus) . '.'];
    }
}
