<?php

if (!defined('ABSPATH')) exit;

use RTOFLOW\Services\PayoutService;
use RTOFLOW\Services\TdsService;

// FIX P0-6: this file used to run its OWN monthly payout calculation —
// `amount = paid_amount * vendor_share/100`, written to a plain `amount`
// column — completely independent of PayoutService::generatePayout(),
// which computes `gross_amount`/`tds_amount`/`net_amount` from
// `total_amount` (not `paid_amount`) and applies TDS. Because both ran
// on the same `rtoflow_daily` cron hook against the same
// `rto_vendor_payouts` table, a vendor's payout row could be created or
// silently overwritten by whichever one ran, with no TDS split and no
// audit trail, while the admin Payouts screen (PayoutsController) used
// the other schema entirely. There is now exactly one payout-generation
// code path — PayoutService::generatePayout() — called here for the
// same "1st of the month, prior month's completed+paid leads" trigger
// this cron always had, so scheduled generation and the admin's manual
// "Generate Payouts" button can never disagree about a vendor's numbers.
//
// TRACE: WP-Cron fires 'rtoflow_daily' → on the 1st of the month only →
//        for every active vendor → PayoutService::generatePayout() for
//        the prior month → each call is itself idempotent (it refuses
//        to create a second payout for a vendor+period that already has
//        one, see PayoutService::generatePayout()) → logged via
//        AuditService inside the service, same as the admin-triggered path.
//        Postconditions: at most one rto_vendor_payouts row per
//        vendor+period is created by this job; running it twice for the
//        same month is a no-op the second time.
add_action('rtoflow_daily', function (): void {
    if (date('j') !== '1') {
        return;
    }
    global $wpdb;
    $p      = $wpdb->prefix;
    $period = date('Y-m', strtotime('last month'));

    $vendors = $wpdb->get_col("SELECT id FROM {$p}rto_vendors WHERE status='active'") ?: [];
    if (empty($vendors)) {
        return;
    }

    $svc     = new PayoutService(new TdsService());
    $created = 0;
    foreach ($vendors as $vendorId) {
        $result = $svc->generatePayout((int)$vendorId, $period);
        if (!empty($result['success'])) {
            $created++;
        }
        // A false result here is expected and not an error whenever the
        // vendor has no completed+paid leads for the period, or a payout
        // for this vendor+period already exists — both are checked inside
        // generatePayout() itself.
    }

    \RTOFLOW\Services\AuditService::log('payout.cron_run', null, [
        'period'          => $period,
        'vendors_checked' => count($vendors),
        'payouts_created' => $created,
    ]);
});
