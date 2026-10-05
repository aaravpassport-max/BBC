-- Migration 026: Rebook snapshots, address favourites/recents, booking templates

BEGIN;

ALTER TABLE saved_addresses
  ADD COLUMN IF NOT EXISTS is_favourite BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS last_used_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS usage_count INT NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_saved_addresses_recent
  ON saved_addresses (user_id, last_used_at DESC NULLS LAST);

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS rebook_snapshot JSONB;

CREATE TABLE IF NOT EXISTS booking_templates (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name            VARCHAR(120) NOT NULL,
    snapshot        JSONB NOT NULL,
    is_favourite    BOOLEAN NOT NULL DEFAULT true,
    last_used_at    TIMESTAMPTZ,
    usage_count     INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_booking_templates_user ON booking_templates (user_id);
CREATE INDEX IF NOT EXISTS idx_booking_templates_recent
  ON booking_templates (user_id, last_used_at DESC NULLS LAST);

COMMIT;
