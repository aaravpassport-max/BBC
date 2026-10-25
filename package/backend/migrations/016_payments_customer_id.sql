-- Migration 016: payments.customer_id. A real gateway webhook is a
-- server-to-server call from Razorpay's own infrastructure — it carries
-- no client auth context, no session, nothing that identifies "which
-- customer initiated this." The pre-existing dev-only webhook simulator
-- worked around this by having the AUTHENTICATED CLIENT supply customerId
-- directly, which is fine for a simulator but structurally impossible for
-- a genuine webhook. This column is what lets the real webhook handler
-- look up "whose wallet does this gateway_ref belong to" from data WE
-- persisted at initiation time, rather than trusting anything the webhook
-- payload itself claims about identity.

BEGIN;

ALTER TABLE payments ADD COLUMN customer_id UUID REFERENCES users(id);
CREATE INDEX idx_payments_customer ON payments (customer_id);

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- ALTER TABLE payments DROP COLUMN customer_id;
-- COMMIT;
