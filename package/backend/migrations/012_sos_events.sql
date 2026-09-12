-- Migration 012: SOS events — the defining Ops/Control Room feature (PRD
-- Section 10 / 10A.1). Previously only a reserved, non-toggleable
-- notification category existed ('sos' in notification_preferences) — no
-- actual trigger/acknowledge/resolve flow or backing table existed at all.

BEGIN;

CREATE TABLE sos_events (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id          UUID NOT NULL REFERENCES bookings(id),
    triggered_by        UUID NOT NULL REFERENCES users(id),
    triggered_by_role    VARCHAR(20) NOT NULL CHECK (triggered_by_role IN ('customer', 'driver')),
    trigger_lat         DOUBLE PRECISION,
    trigger_lng         DOUBLE PRECISION,
    status              VARCHAR(20) NOT NULL DEFAULT 'triggered'
                        CHECK (status IN ('triggered', 'acknowledged', 'resolved')),
    acknowledged_by     UUID REFERENCES users(id),
    acknowledged_at     TIMESTAMPTZ,
    resolved_by         UUID REFERENCES users(id),
    resolved_at         TIMESTAMPTZ,
    outcome_tag         VARCHAR(30),   -- e.g. false_alarm, resolved_safe, escalated_to_authorities
    resolution_note     TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- PRD 10A.1 acceptance criteria: an active SOS must surface to an available
-- operator fast — this index serves the Control Room queue query directly.
CREATE INDEX idx_sos_events_active ON sos_events (status, created_at) WHERE status != 'resolved';
CREATE INDEX idx_sos_events_booking ON sos_events (booking_id);

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS sos_events;
-- COMMIT;
