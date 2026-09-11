-- Migration 008: Per-user referral code (PRD Section 18/18A.1)
-- referrals.referral_code (migration 005) stores which code was used on a
-- specific referral record; this adds the STABLE, lookup-able code each user
-- can share, which nothing before this migration actually stored.

BEGIN;

ALTER TABLE users ADD COLUMN referral_code VARCHAR(12) UNIQUE;

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- ALTER TABLE users DROP COLUMN IF EXISTS referral_code;
-- COMMIT;
