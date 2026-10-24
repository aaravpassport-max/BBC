-- Allow local read-only viewer sessions (unified desktop app on employee PCs).
CREATE TABLE sessions_new (
  token_hash      TEXT PRIMARY KEY,
  subject_type    TEXT NOT NULL CHECK (subject_type IN ('admin', 'employee', 'device', 'viewer')),
  subject_id      TEXT NOT NULL,
  device_id       TEXT,
  created_at_utc  TEXT NOT NULL,
  expires_at_utc  TEXT NOT NULL,
  revoked_at_utc  TEXT
);

INSERT INTO sessions_new SELECT * FROM sessions;
DROP TABLE sessions;
ALTER TABLE sessions_new RENAME TO sessions;

CREATE INDEX IF NOT EXISTS idx_sessions_subject ON sessions(subject_type, subject_id);
