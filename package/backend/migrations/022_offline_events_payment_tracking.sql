-- Offline reason analytics + booking payment tracking for UPI/COD

BEGIN;

CREATE TABLE driver_offline_events (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    driver_id       UUID NOT NULL REFERENCES users(id),
    reason_code     VARCHAR(30) NOT NULL,
    lat             DOUBLE PRECISION,
    lng             DOUBLE PRECISION,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_driver_offline_events_driver ON driver_offline_events (driver_id, created_at DESC);

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20),
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20)
        CHECK (payment_status IS NULL OR payment_status IN ('paid', 'pending_collection', 'collected', 'failed'));

COMMIT;
