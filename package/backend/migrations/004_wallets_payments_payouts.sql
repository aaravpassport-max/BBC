-- Migration 004: Wallets, double-entry ledger, payments, payout batches (PRD Section 6, 12A.1)
-- This is the most integrity-critical migration in the schema — see the CHECK
-- constraints and the invariant note at the bottom before modifying anything here.

BEGIN;

CREATE TABLE wallets (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_type      VARCHAR(20) NOT NULL CHECK (owner_type IN ('customer', 'driver', 'corporate_account', 'platform')),
    owner_id        UUID NOT NULL,
    currency        VARCHAR(3) NOT NULL,
    -- Customer wallets must never go negative (PRD Section 6 hard rule); driver
    -- wallets may go negative pending a payout offset/recovery, which is why this
    -- constraint is conditional rather than blanket. Enforced further by the
    -- application layer's spend-block logic for customer wallets.
    real_balance_cache      NUMERIC(14,2) NOT NULL DEFAULT 0,   -- cached for read speed; source of truth is wallet_transactions
    promo_balance_cache     NUMERIC(14,2) NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_wallets_owner UNIQUE (owner_type, owner_id, currency),
    CONSTRAINT chk_customer_wallet_non_negative
        CHECK (NOT (owner_type = 'customer' AND real_balance_cache < 0))
);

-- Double-entry ledger. Every financial event is (at minimum) one debit + one
-- credit row sharing a transaction_group_id, per PRD Section 6's hard requirement
-- that this platform never uses a single mutable balance field.
CREATE TABLE wallet_transactions (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    wallet_id               UUID NOT NULL REFERENCES wallets(id),
    transaction_group_id    UUID NOT NULL,   -- links the paired debit/credit rows of one economic event
    entry_type              VARCHAR(6) NOT NULL CHECK (entry_type IN ('debit', 'credit')),
    balance_type            VARCHAR(10) NOT NULL DEFAULT 'real' CHECK (balance_type IN ('real', 'promo')),
    amount                  NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    balance_after           NUMERIC(14,2) NOT NULL,
    reason                  VARCHAR(30) NOT NULL,  -- topup, trip_charge, refund, promo_credit, tip, payout, referral, penalty
    linked_booking_id       UUID,
    linked_ticket_id        UUID,
    linked_referral_id      UUID,
    linked_gateway_ref      VARCHAR(100),          -- idempotency anchor for payment-gateway-originated entries
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_wallet_txn_wallet ON wallet_transactions (wallet_id, created_at DESC);
CREATE INDEX idx_wallet_txn_group ON wallet_transactions (transaction_group_id);
CREATE INDEX idx_wallet_txn_booking ON wallet_transactions (linked_booking_id) WHERE linked_booking_id IS NOT NULL;

-- Idempotency: the same gateway_ref can only ever produce one credit entry,
-- preventing duplicate-webhook double-crediting (PRD Section 6 rule).
CREATE UNIQUE INDEX uq_wallet_txn_gateway_ref
    ON wallet_transactions (linked_gateway_ref)
    WHERE linked_gateway_ref IS NOT NULL AND entry_type = 'credit';

CREATE TABLE payments (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id      UUID REFERENCES bookings(id),
    gateway_ref     VARCHAR(100) NOT NULL UNIQUE,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded')),
    amount          NUMERIC(14,2) NOT NULL,
    method          VARCHAR(20) NOT NULL,   -- card, upi, netbanking, wallet
    webhook_received_at TIMESTAMPTZ,        -- balance/status only ever finalized on this being set (PRD Section 6)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_payments_booking ON payments (booking_id);
CREATE INDEX idx_payments_pending_aged ON payments (created_at) WHERE status = 'pending';   -- reconciliation job target (PRD Section 25/6)

CREATE TABLE payout_batches (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'proposed'
                    CHECK (status IN ('proposed', 'reviewing', 'approved', 'submitting', 'partially_failed', 'completed')),
    total_amount    NUMERIC(14,2) NOT NULL DEFAULT 0,
    approved_by     UUID REFERENCES users(id),
    approved_at     TIMESTAMPTZ,
    version         INTEGER NOT NULL DEFAULT 1,   -- optimistic lock against double-approval (PRD 12A.1)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE payout_batch_lines (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    batch_id        UUID NOT NULL REFERENCES payout_batches(id),
    driver_id       UUID NOT NULL REFERENCES users(id),
    gross_earnings  NUMERIC(14,2) NOT NULL,
    deductions      NUMERIC(14,2) NOT NULL DEFAULT 0,
    net_payout      NUMERIC(14,2) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'eligible'
                    CHECK (status IN ('eligible', 'held', 'excluded', 'submitted', 'succeeded', 'failed')),
    hold_reason     VARCHAR(30),   -- DISPUTE_PENDING, FRAUD_REVIEW, BANK_DETAILS_INVALID, OTHER (PRD 12A.1)
    hold_note       TEXT,
    provider_txn_ref VARCHAR(100),
    failure_reason  TEXT,
    retry_count     SMALLINT NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_payout_batch_driver UNIQUE (batch_id, driver_id)
);
CREATE INDEX idx_payout_lines_batch ON payout_batch_lines (batch_id, status);
CREATE INDEX idx_payout_lines_driver ON payout_batch_lines (driver_id, created_at DESC);

COMMIT;

-- ============================== INTEGRITY INVARIANT ==============================
-- The following query must return zero rows at all times except for the
-- platform's own fee/revenue wallet's expected net-positive balance
-- (PRD Section 6 acceptance criteria — run this as a scheduled daily job,
-- referenced in migration 005's background_jobs table and PRD Section 25):
--
--   SELECT wallet_id, SUM(CASE WHEN entry_type = 'debit' THEN -amount ELSE amount END) AS net
--   FROM wallet_transactions
--   GROUP BY wallet_id
--   HAVING SUM(CASE WHEN entry_type = 'debit' THEN -amount ELSE amount END)
--          <> (SELECT real_balance_cache + promo_balance_cache FROM wallets w WHERE w.id = wallet_id);
--
-- A non-empty result means the cached balance has drifted from the ledger's
-- actual sum — page Finance ops immediately (PRD Section 6/28).

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS payout_batch_lines;
-- DROP TABLE IF EXISTS payout_batches;
-- DROP TABLE IF EXISTS payments;
-- DROP TABLE IF EXISTS wallet_transactions;
-- DROP TABLE IF EXISTS wallets;
-- COMMIT;
