-- Stage 2: application foreground and browser URL tracking.
-- Same derived-table discipline as state_intervals/break_intervals in
-- 001_init.sql: these two tables are fully DELETEd and reinserted from
-- raw_events by recomputeUsage() in repository.ts, never hand-edited.

CREATE TABLE IF NOT EXISTS app_foreground_intervals (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id    TEXT NOT NULL REFERENCES attendance_sessions(session_id),
  employee_id   TEXT NOT NULL,
  device_id     TEXT NOT NULL,
  executable_name TEXT NOT NULL,
  window_title  TEXT,
  start_utc     TEXT NOT NULL,
  end_utc       TEXT, -- NULL while this app is still foreground (session ongoing)
  caused_by_event_ids_json TEXT NOT NULL DEFAULT '[]'
);

CREATE INDEX IF NOT EXISTS idx_app_foreground_session ON app_foreground_intervals(session_id, start_utc);
CREATE INDEX IF NOT EXISTS idx_app_foreground_exe ON app_foreground_intervals(employee_id, executable_name, start_utc);

CREATE TABLE IF NOT EXISTS browser_url_intervals (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id    TEXT NOT NULL REFERENCES attendance_sessions(session_id),
  employee_id   TEXT NOT NULL,
  device_id     TEXT NOT NULL,
  browser_name  TEXT NOT NULL,
  tab_id        TEXT NOT NULL,
  url           TEXT NOT NULL,
  domain        TEXT NOT NULL,
  page_title    TEXT,
  start_utc     TEXT NOT NULL,
  end_utc       TEXT, -- NULL while this tab/URL is still active (session ongoing)
  caused_by_event_ids_json TEXT NOT NULL DEFAULT '[]'
);

CREATE INDEX IF NOT EXISTS idx_browser_url_session ON browser_url_intervals(session_id, start_utc);
CREATE INDEX IF NOT EXISTS idx_browser_url_domain ON browser_url_intervals(employee_id, domain, start_utc);
