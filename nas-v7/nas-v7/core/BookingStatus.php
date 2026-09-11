<?php
namespace NAS\Core;
/**
 * Canonical booking status vocabulary.
 * EVERY place in the codebase that reads or writes bookings.status MUST use these constants.
 * This class is the single source of truth — no status string literals elsewhere.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class BookingStatus {
    // ── Lifecycle statuses ────────────────────────────────────────────────────
    const BOOKING_RECEIVED   = 'booking_received';
    const UNDER_REVIEW       = 'under_review';       // canonical; replaces 'ad_under_review'
    const READY_TO_PROCESS   = 'ready_to_process';
    const DOCUMENTS_RECEIVED = 'documents_received';
    const QUOTATION_SENT     = 'quotation_sent';
    const PAYMENT_RECEIVED   = 'payment_received';   // canonical; replaces 'payment_done'
    const MATERIAL_UPLOADED  = 'material_uploaded';
    const AD_PROCESSING      = 'ad_processing';
    const PROOF_READY        = 'proof_ready';
    const SUBMITTED_TO_PUB   = 'submitted_to_pub';
    const PUBLISHED          = 'published';
    const COMPLETED          = 'completed';

    // ── Terminal negative statuses ────────────────────────────────────────────
    const REJECTED              = 'rejected';
    const CANCELLED             = 'cancelled';
    const NOT_ABLE_TO_PROCESS   = 'not_able_to_process';
    const NOT_ELIGIBLE          = 'not_eligible';

    // TRACE: Called by validation gates anywhere a booking status is written or compared.
    //        Precondition: none.
    //        Postcondition: returns 16-element array of all valid status strings.
    //        Edge cases: none — pure constant return.
    /** All valid status values. Any value outside this set is invalid. */
    // TRACE: all() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function all(): array {
        return [
            self::BOOKING_RECEIVED, self::UNDER_REVIEW, self::READY_TO_PROCESS,
            self::DOCUMENTS_RECEIVED, self::QUOTATION_SENT, self::PAYMENT_RECEIVED,
            self::MATERIAL_UPLOADED, self::AD_PROCESSING, self::PROOF_READY,
            self::SUBMITTED_TO_PUB, self::PUBLISHED, self::COMPLETED,
            self::REJECTED, self::CANCELLED, self::NOT_ABLE_TO_PROCESS, self::NOT_ELIGIBLE,
        ];
    }

    // TRACE: Used by AdminModule and BookingModule pending-count queries WHERE status IN(...).
    //        Postcondition: returns all statuses that represent a booking in flight.
    /** Statuses counted as "pending/active" in dashboard metrics. */
    // TRACE: active() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function active(): array {
        return [
            self::BOOKING_RECEIVED, self::UNDER_REVIEW, self::READY_TO_PROCESS,
            self::DOCUMENTS_RECEIVED, self::QUOTATION_SENT, self::PAYMENT_RECEIVED,
            self::MATERIAL_UPLOADED, self::AD_PROCESSING, self::PROOF_READY,
            self::SUBMITTED_TO_PUB,
        ];
    }

    /** Statuses that are terminal successes. */
    // TRACE: completed() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function completed(): array {
        return [ self::PUBLISHED, self::COMPLETED ];
    }

    /** Statuses that allow client cancellation. */
    // TRACE: cancellable() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function cancellable(): array {
        return [ self::BOOKING_RECEIVED, self::UNDER_REVIEW, self::QUOTATION_SENT ];
    }

    // TRACE: Called by EnterpriseModule::force_state() before accepting a new status.
    //        Input: any string → Output: true if in all(), false otherwise. Never throws.
    // TRACE: is_valid() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function is_valid( string $status ): bool {
        return in_array( $status, self::all(), true );
    }
}
