-- Production readiness: loyalty points, call logs, invoice numbering

BEGIN;

CREATE TABLE loyalty_points (
    user_id         UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    balance         INTEGER NOT NULL DEFAULT 0 CHECK (balance >= 0),
    lifetime_earned INTEGER NOT NULL DEFAULT 0,
    tier            VARCHAR(20) NOT NULL DEFAULT 'bronze'
                    CHECK (tier IN ('bronze', 'silver', 'gold', 'platinum')),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE loyalty_transactions (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id             UUID NOT NULL REFERENCES users(id),
    points              INTEGER NOT NULL,
    reason              VARCHAR(30) NOT NULL,
    linked_booking_id   UUID REFERENCES bookings(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_loyalty_txn_user ON loyalty_transactions (user_id, created_at DESC);
CREATE UNIQUE INDEX uq_loyalty_trip_credit ON loyalty_transactions (linked_booking_id, reason)
    WHERE linked_booking_id IS NOT NULL AND reason = 'trip_complete';

CREATE TABLE call_logs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id      UUID NOT NULL REFERENCES bookings(id),
    caller_id       UUID NOT NULL REFERENCES users(id),
    callee_id       UUID NOT NULL REFERENCES users(id),
    provider_ref    VARCHAR(100),
    masked_number   VARCHAR(30),
    status          VARCHAR(20) NOT NULL DEFAULT 'initiated'
                    CHECK (status IN ('initiated', 'connected', 'completed', 'failed')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_call_logs_booking ON call_logs (booking_id, created_at DESC);

CREATE TABLE invoice_sequences (
    prefix          VARCHAR(10) NOT NULL,
    financial_year  VARCHAR(9) NOT NULL,
    last_number     INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (prefix, financial_year)
);

INSERT INTO permissions (resource, action) VALUES
  ('finance', 'review'),
  ('finance', 'approve')
ON CONFLICT (resource, action) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'ops_admin' AND p.resource = 'finance'
ON CONFLICT DO NOTHING;

COMMIT;
