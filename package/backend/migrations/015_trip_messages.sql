-- Migration 015: In-app chat between a booking's customer and driver
-- (P0 gap analysis item). A trip has exactly two participants who
-- legitimately need to talk to each other while it's active — this table
-- is intentionally scoped to a single booking, not a general messaging
-- system, matching how the actual product need is bounded.

BEGIN;

CREATE TABLE trip_messages (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id          UUID NOT NULL REFERENCES bookings(id),
    sender_id           UUID NOT NULL REFERENCES users(id),
    sender_role         VARCHAR(20) NOT NULL CHECK (sender_role IN ('customer', 'driver')),
    body                TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 1000),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Serves both "get this booking's full thread, oldest first" (the read
-- path polled by both apps) and "has anything new arrived since I last
-- checked" without a full table scan as trip_messages grows.
CREATE INDEX idx_trip_messages_booking ON trip_messages (booking_id, created_at);

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS trip_messages;
-- COMMIT;
