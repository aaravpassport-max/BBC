-- Role-based access: separate admin and employee login, plus the
-- "trusted device" identity a local agent/dashboard uses instead of a
-- password (the analog of "already securely established through the
-- Windows account" — a token minted once at enrollment, not a credential
-- typed in every time).
--
-- Also: correction REQUESTS. An employee can ask for a correction but
-- can never write one directly — attendance_corrections (001_init.sql)
-- stays admin-authored only. Approving a request creates exactly one
-- attendance_corrections row and links back to it; rejecting one leaves
-- no trace in the derived data at all. This is the enforcement boundary
-- between "employee flags something as wrong" and "the record changes".

CREATE TABLE IF NOT EXISTS admin_users (
  admin_id      TEXT PRIMARY KEY,
  display_name  TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at_utc TEXT NOT NULL
);

-- One row per employee who has web-dashboard login enabled. An employee
-- with no row here simply cannot log in to the web dashboard (they may
-- still be tracked, and may still use the local trusted-device path).
CREATE TABLE IF NOT EXISTS employee_credentials (
  employee_id   TEXT PRIMARY KEY REFERENCES employees(employee_id),
  password_hash TEXT NOT NULL,
  created_at_utc TEXT NOT NULL,
  updated_at_utc TEXT NOT NULL
);

-- Minted once when an employee's device is enrolled (installation time),
-- stored only on that device. The local tray app authenticates with this
-- instead of a password, which is what makes "no repeated login on your
-- own assigned PC" possible without weakening who-can-see-what: the token
-- is scoped to exactly one employee+device pair and can be revoked.
CREATE TABLE IF NOT EXISTS device_tokens (
  token_hash     TEXT PRIMARY KEY,
  employee_id    TEXT NOT NULL REFERENCES employees(employee_id),
  device_id      TEXT NOT NULL REFERENCES devices(device_id),
  created_at_utc TEXT NOT NULL,
  last_used_at_utc TEXT,
  revoked_at_utc TEXT
);

CREATE INDEX IF NOT EXISTS idx_device_tokens_employee ON device_tokens(employee_id);

-- Login sessions for both roles. A session is opaque server-side state
-- (the token itself is a random value the client holds; only its hash is
-- stored), so a logout/revoke is immediate and total.
CREATE TABLE IF NOT EXISTS sessions (
  token_hash    TEXT PRIMARY KEY,
  subject_type  TEXT NOT NULL CHECK (subject_type IN ('admin', 'employee', 'device')),
  subject_id    TEXT NOT NULL, -- admin_id, employee_id, or employee_id (for device sessions)
  device_id     TEXT,          -- set only for subject_type='device'
  created_at_utc TEXT NOT NULL,
  expires_at_utc TEXT NOT NULL,
  revoked_at_utc TEXT
);

CREATE INDEX IF NOT EXISTS idx_sessions_subject ON sessions(subject_type, subject_id);

CREATE TABLE IF NOT EXISTS correction_requests (
  request_id      TEXT PRIMARY KEY,
  employee_id      TEXT NOT NULL REFERENCES employees(employee_id),
  session_id       TEXT NOT NULL REFERENCES attendance_sessions(session_id),
  field_name       TEXT NOT NULL,
  requested_value  TEXT NOT NULL,
  reason           TEXT NOT NULL,
  status           TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
  created_at_utc   TEXT NOT NULL,
  reviewed_by_admin_id TEXT,
  reviewed_at_utc  TEXT,
  review_note      TEXT,
  resulting_correction_id TEXT REFERENCES attendance_corrections(correction_id)
);

CREATE INDEX IF NOT EXISTS idx_correction_requests_employee ON correction_requests(employee_id, created_at_utc);
CREATE INDEX IF NOT EXISTS idx_correction_requests_status ON correction_requests(status, created_at_utc);
