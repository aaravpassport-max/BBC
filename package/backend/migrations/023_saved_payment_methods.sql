-- Saved payment methods for faster checkout (card/UPI tokens)

BEGIN;

CREATE TABLE saved_payment_methods (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider        VARCHAR(20) NOT NULL DEFAULT 'razorpay',
    method_type     VARCHAR(20) NOT NULL CHECK (method_type IN ('card', 'upi')),
    display_label   VARCHAR(80) NOT NULL,
    token_ref       VARCHAR(200) NOT NULL,
    is_default      BOOLEAN NOT NULL DEFAULT false,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_saved_payment_methods_user ON saved_payment_methods (user_id, created_at DESC);

COMMIT;
