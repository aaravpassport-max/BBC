-- Initial schema: Stage 1 (reliable attendance) tables only.
-- Application/browser tracking tables land in a later migration (Stage 2).
--
-- Design rules enforced by this schema, not just by application code:
--   1. raw_events is append-only from the application's point of view
--      (no UPDATE statements are ever issued against it elsewhere in this
--      codebase); event_id is the natural primary key so a duplicate
--      delivery (e.g. after an offline-sync retry) is a no-op, not a
--      duplicate row.
--   2. state_intervals and break_intervals are DERIVED tables: they are
--      fully deleted and recomputed from raw_events for a session, never
--      hand-edited. Admin corrections go through attendance_corrections
--      instead, which preserves the original derived value.
--   3. No employee/device pair's activity rows are queryable by the
--      reporting layer until notice_acknowledgements has a non-null
--      acknowledged_at_utc for that pair (enforced in repository.ts, not
--      just documented here).

CREATE TABLE IF NOT EXISTS schema_migrations (
  version     INTEGER PRIMARY KEY,
  applied_at_utc TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS employees (
  employee_id   TEXT PRIMARY KEY,
  display_name  TEXT NOT NULL,
  employee_code TEXT,
  department    TEXT,
  designation   TEXT,
  time_zone     TEXT NOT NULL DEFAULT 'Asia/Kolkata',
  monitoring_status TEXT NOT NULL DEFAULT 'active' CHECK (monitoring_status IN ('active', 'paused', 'offboarded')),
  created_at_utc TEXT NOT NULL,
  updated_at_utc TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS devices (
  device_id     TEXT PRIMARY KEY,
  hostname      TEXT,
  os_version    TEXT,
  agent_version TEXT,
  created_at_utc TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS employee_device_assignments (
  employee_id TEXT NOT NULL REFERENCES employees(employee_id),
  device_id   TEXT NOT NULL REFERENCES devices(device_id),
  assigned_at_utc   TEXT NOT NULL,
  unassigned_at_utc TEXT,
  PRIMARY KEY (employee_id, device_id, assigned_at_utc)
);

-- The hard transparency gate. See repository.ts: ingestRawEvent() refuses
-- to write activity-bearing events for an employee/device pair that has
-- no row here with a non-null acknowledged_at_utc.
CREATE TABLE IF NOT EXISTS notice_acknowledgements (
  employee_id        TEXT NOT NULL REFERENCES employees(employee_id),
  device_id          TEXT NOT NULL REFERENCES devices(device_id),
  policy_version     TEXT NOT NULL,
  first_shown_at_utc TEXT NOT NULL,
  acknowledged_at_utc TEXT,
  PRIMARY KEY (employee_id, device_id)
);

CREATE TABLE IF NOT EXISTS attendance_sessions (
  session_id    TEXT PRIMARY KEY,
  employee_id   TEXT NOT NULL REFERENCES employees(employee_id),
  device_id     TEXT NOT NULL REFERENCES devices(device_id),
  start_utc     TEXT NOT NULL,
  end_utc       TEXT,
  end_reason    TEXT CHECK (end_reason IN ('signout', 'shutdown', 'crash_recovered', 'unresolved') OR end_reason IS NULL),
  is_end_estimated INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_attendance_sessions_employee ON attendance_sessions(employee_id, start_utc);
CREATE INDEX IF NOT EXISTS idx_attendance_sessions_device ON attendance_sessions(device_id, start_utc);

-- Raw events: the append-only source of truth.
CREATE TABLE IF NOT EXISTS raw_events (
  event_id      TEXT PRIMARY KEY,
  employee_id   TEXT NOT NULL,
  device_id     TEXT NOT NULL,
  session_id    TEXT,
  event_type    TEXT NOT NULL,
  timestamp_utc TEXT NOT NULL,
  source        TEXT NOT NULL,
  is_estimated  INTEGER NOT NULL DEFAULT 0,
  payload_json  TEXT NOT NULL DEFAULT '{}',
  ingested_at_utc TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_raw_events_session ON raw_events(session_id, timestamp_utc);
CREATE INDEX IF NOT EXISTS idx_raw_events_employee_time ON raw_events(employee_id, timestamp_utc);

-- Derived tables. Fully replaced (DELETE then INSERT, in one transaction)
-- whenever a session is recomputed from raw_events. Never hand-edited.
CREATE TABLE IF NOT EXISTS state_intervals (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id    TEXT NOT NULL REFERENCES attendance_sessions(session_id),
  employee_id   TEXT NOT NULL,
  device_id     TEXT NOT NULL,
  state         TEXT NOT NULL,
  start_utc     TEXT NOT NULL,
  end_utc       TEXT, -- NULL while ongoing
  caused_by_event_ids_json TEXT NOT NULL DEFAULT '[]',
  is_estimated  INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_state_intervals_session ON state_intervals(session_id, start_utc);

CREATE TABLE IF NOT EXISTS break_intervals (
  break_id      TEXT PRIMARY KEY,
  session_id    TEXT NOT NULL REFERENCES attendance_sessions(session_id),
  employee_id   TEXT NOT NULL,
  start_utc     TEXT NOT NULL,
  end_utc       TEXT,
  detected_by_event_id TEXT,
  ended_by_event_id    TEXT
);

CREATE INDEX IF NOT EXISTS idx_break_intervals_session ON break_intervals(session_id, start_utc);

-- Admin corrections: original value, corrected value, reason, actor,
-- timestamp — never a silent overwrite of a raw event or derived row.
CREATE TABLE IF NOT EXISTS attendance_corrections (
  correction_id   TEXT PRIMARY KEY,
  session_id      TEXT NOT NULL REFERENCES attendance_sessions(session_id),
  field_name      TEXT NOT NULL,
  original_value  TEXT,
  corrected_value TEXT NOT NULL,
  reason          TEXT NOT NULL,
  admin_id        TEXT NOT NULL,
  created_at_utc  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS monitoring_health (
  device_id         TEXT PRIMARY KEY REFERENCES devices(device_id),
  last_heartbeat_utc TEXT,
  last_sync_utc      TEXT,
  agent_status       TEXT NOT NULL DEFAULT 'unknown' CHECK (agent_status IN ('running', 'stopped', 'crashed', 'unknown')),
  updated_at_utc      TEXT NOT NULL
);
