-- Migration 018: Saved addresses + GST fields on users (PRD Screens 11, 43)

BEGIN;

CREATE TABLE saved_addresses (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label           VARCHAR(50) NOT NULL DEFAULT 'Other',
    address_line    VARCHAR(255) NOT NULL,
    lat             DOUBLE PRECISION NOT NULL,
    lng             DOUBLE PRECISION NOT NULL,
    landmark        VARCHAR(100),
    contact_name    VARCHAR(100),
    contact_phone   VARCHAR(20),
    is_default      BOOLEAN NOT NULL DEFAULT false,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_saved_addresses_user ON saved_addresses (user_id);

ALTER TABLE users ADD COLUMN IF NOT EXISTS gstin VARCHAR(15);
ALTER TABLE users ADD COLUMN IF NOT EXISTS billing_address TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS business_name VARCHAR(120);

COMMIT;

-- DOWN
-- BEGIN;
-- DROP TABLE IF EXISTS saved_addresses;
-- ALTER TABLE users DROP COLUMN IF EXISTS gstin;
-- ALTER TABLE users DROP COLUMN IF EXISTS billing_address;
-- ALTER TABLE users DROP COLUMN IF EXISTS business_name;
-- COMMIT;
