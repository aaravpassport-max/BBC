-- Migration 018: corporate_invoices. Real enterprise invoicing
-- (P2 gap-analysis item) — corporate_accounts.committed_spend has
-- accumulated indefinitely since migration 006, despite its own comment
-- calling it "this billing period"'s spend; nothing ever closed a period
-- out into an actual invoice or reset it for the next one. This table is
-- an invoice HEADER only — line items are sourced live from `bookings`
-- for the invoice's own date range at view time (single source of truth,
-- no duplicated/driftable copy of fare data), matching the same "never
-- store what you can derive from the real record" principle used by
-- receipts on the customer side.

BEGIN;

CREATE TABLE corporate_invoices (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    corporate_account_id UUID NOT NULL REFERENCES corporate_accounts(id),
    invoice_number      VARCHAR(30) NOT NULL UNIQUE,
    period_start        TIMESTAMPTZ NOT NULL,
    period_end          TIMESTAMPTZ NOT NULL,
    total_amount        NUMERIC(14,2) NOT NULL,
    booking_count        INTEGER NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'paid')),
    generated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_corporate_invoice_period UNIQUE (corporate_account_id, period_start, period_end)
);

CREATE INDEX idx_corporate_invoices_account ON corporate_invoices (corporate_account_id, period_start DESC);

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS corporate_invoices;
-- COMMIT;
