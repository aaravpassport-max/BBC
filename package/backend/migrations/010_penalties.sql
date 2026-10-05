-- Migration 010: Driver penalties with structured dispute tracking
-- (PRD Section A.2 "Penalties/Violations List / Penalty Dispute Flow").
-- wallet_transactions already has a 'penalty' reason tag (migration 004) but
-- that's just a ledger line — it can't carry a reason code, dispute status,
-- or resolution reasoning, which the PRD requires every penalty to support.

BEGIN;

CREATE TABLE penalties (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    driver_id           UUID NOT NULL REFERENCES users(id),
    amount              NUMERIC(10,2) NOT NULL CHECK (amount > 0),
    reason_code         VARCHAR(30) NOT NULL,   -- e.g. LATE_ARRIVAL, TRIP_CANCELLED_POST_ACCEPT, DOCUMENT_VIOLATION, OTHER
    reason_note         TEXT,
    linked_booking_id   UUID REFERENCES bookings(id),
    wallet_transaction_group_id UUID,           -- links to the debit that actually moved money (PRD 3.4/A.2)
    status              VARCHAR(20) NOT NULL DEFAULT 'issued'
                        CHECK (status IN ('issued', 'disputed', 'upheld', 'reversed')),
    dispute_note        TEXT,
    dispute_submitted_at TIMESTAMPTZ,
    resolution_note     TEXT,
    resolved_by         UUID REFERENCES users(id),
    resolved_at         TIMESTAMPTZ,
    issued_by           UUID REFERENCES users(id),  -- null if system-issued
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_penalties_driver ON penalties (driver_id, created_at DESC);
CREATE INDEX idx_penalties_disputed ON penalties (status) WHERE status = 'disputed';

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS penalties;
-- COMMIT;
