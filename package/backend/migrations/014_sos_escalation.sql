-- Migration 014: SOS escalation tracking (PRD 10A.1). Two distinct paths
-- both write to these columns: a manual "Escalate to Safety Team Lead"
-- action (gated by the new ops.sos_escalate permission, deliberately
-- separate from ops.sos_respond per the PRD's explicit permissions line),
-- and an automatic escalation when no operator acknowledges within the
-- hard threshold (PRD: "auto-escalates to a secondary on-call operator...
-- never silently waiting indefinitely").

BEGIN;

ALTER TABLE sos_events
  ADD COLUMN escalated_at TIMESTAMPTZ,
  ADD COLUMN escalated_by UUID REFERENCES users(id),  -- NULL for an automatic escalation
  ADD COLUMN auto_escalated BOOLEAN NOT NULL DEFAULT false;

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- ALTER TABLE sos_events DROP COLUMN escalated_at, DROP COLUMN escalated_by, DROP COLUMN auto_escalated;
-- COMMIT;
