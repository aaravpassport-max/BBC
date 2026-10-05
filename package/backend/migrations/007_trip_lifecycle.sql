-- Migration 007: Trip lifecycle support (PRD 2.2.7, 3B.1)
-- Adds what's needed to actually progress a booking through
-- driver_assigned -> in_progress -> completed, which no prior migration
-- provided storage for (booking_stops existed since migration 003 but was
-- never populated by any code path until this migration's paired feature work).

BEGIN;

ALTER TABLE bookings
  ADD COLUMN pickup_otp VARCHAR(4),
  ADD COLUMN pickup_otp_attempts SMALLINT NOT NULL DEFAULT 0,
  ADD COLUMN started_at TIMESTAMPTZ;

-- Per-stop OTP as plaintext (like pickup_otp) rather than hashed like the
-- login OTP in migration 001 — this code is read aloud / shown on-screen for
-- a real-time in-person handoff verification, not a secret credential a
-- server must protect against offline brute force the way an auth OTP is;
-- the meaningful protection here is the attempt counter, not the hash.
ALTER TABLE booking_stops
  ADD COLUMN otp_code VARCHAR(4),
  ADD COLUMN otp_attempts SMALLINT NOT NULL DEFAULT 0;

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- ALTER TABLE booking_stops DROP COLUMN IF EXISTS otp_code, DROP COLUMN IF EXISTS otp_attempts;
-- ALTER TABLE bookings DROP COLUMN IF EXISTS pickup_otp, DROP COLUMN IF EXISTS pickup_otp_attempts, DROP COLUMN IF EXISTS started_at;
-- COMMIT;
