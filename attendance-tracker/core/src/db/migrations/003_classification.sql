-- Stage 2b: admin-managed classification rules. Unlike state_intervals/
-- break_intervals/app_foreground_intervals/browser_url_intervals, this
-- table is NOT derived from raw_events — it's authored directly by an
-- admin and read by the classification engine (core/src/engine/
-- classification.ts) at report time. Never deleted-and-reinserted by a
-- recompute; rows are only ever added/ended via the admin console.

CREATE TABLE IF NOT EXISTS classification_rules (
  rule_id            TEXT PRIMARY KEY,
  target             TEXT NOT NULL, -- "app:<executableName>" or "domain:<domain>"
  classification     TEXT NOT NULL CHECK (classification IN ('productive', 'neutral', 'unproductive', 'unclassified', 'excluded')),
  effective_from_utc TEXT NOT NULL,
  effective_to_utc   TEXT, -- NULL = still in effect
  created_by_admin_id TEXT NOT NULL,
  created_at_utc     TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_classification_rules_target ON classification_rules(target, effective_from_utc);
