-- Stage 3: data retention policy. A single-row config table (no need for
-- more than one row — retention is an org-wide policy, not per-employee)
-- holding the minimum number of days historical data must be kept.
--
-- This table does not itself delete anything: nothing in this codebase
-- currently deletes rows from raw_events, attendance_sessions, or any
-- derived/usage table. This migration exists so a future prune/archive
-- job has somewhere authoritative to check before it is allowed to run,
-- and so repository.ts can enforce the floor (setRetentionDays refuses
-- anything below 365) rather than leaving the minimum as a comment that
-- nothing actually checks.
CREATE TABLE IF NOT EXISTS retention_settings (
  id              INTEGER PRIMARY KEY CHECK (id = 1), -- single row by design
  retention_days  INTEGER NOT NULL DEFAULT 365,
  updated_at_utc  TEXT NOT NULL,
  updated_by_admin_id TEXT
);

INSERT OR IGNORE INTO retention_settings (id, retention_days, updated_at_utc, updated_by_admin_id)
VALUES (1, 365, '1970-01-01T00:00:00.000Z', NULL);
