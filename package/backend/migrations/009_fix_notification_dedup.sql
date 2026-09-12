-- Migration 009: Fix notification_log's duplicate-delivery guard
-- (PRD 16A.2 hard requirement: duplicate event delivery on the same channel
-- must never double-send).
--
-- ROOT CAUSE: migration 005 created
--   UNIQUE INDEX uq_notification_event_channel ON notification_log (event_id, channel, fallback_of)
-- Postgres unique indexes treat NULL as distinct from NULL by default, so
-- two rows with the same (event_id, channel) and both NULL fallback_of
-- (the normal case — fallback_of is only set on an actual fallback attempt)
-- were NEVER treated as duplicates. Caught by an automated test
-- (notifications.test.ts) that fired the same event_id+channel twice and
-- found BOTH sends succeeded — exactly the bug this constraint was meant to
-- prevent, silently inert since the day it was written.
--
-- FIX: use NULLS NOT DISTINCT (same technique already applied correctly on
-- users.uq_users_phone in migration 001) so two NULL fallback_of rows on the
-- same (event_id, channel) collide as intended.

BEGIN;

DROP INDEX IF EXISTS uq_notification_event_channel;

CREATE UNIQUE INDEX uq_notification_event_channel
    ON notification_log (event_id, channel)
    NULLS NOT DISTINCT;
-- fallback_of intentionally dropped from the uniqueness key: the field
-- exists to LINK a fallback row to its originating send for audit purposes,
-- not to distinguish it for idempotency — idempotency is correctly scoped
-- to (event_id, channel) alone per PRD 16A.2's actual requirement.

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP INDEX IF EXISTS uq_notification_event_channel;
-- CREATE UNIQUE INDEX uq_notification_event_channel ON notification_log (event_id, channel, fallback_of);
-- COMMIT;
