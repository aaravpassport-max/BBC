-- Migration 003: Cities/Zones (geofencing), Bookings, Stops, Dispatch (PRD Section 8, 9C.1, 24, 4)

BEGIN;

CREATE TABLE cities (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name            VARCHAR(100) NOT NULL,
    country         VARCHAR(2) NOT NULL,   -- ISO 3166-1 alpha-2
    timezone        VARCHAR(50) NOT NULL,  -- IANA tz name, drives night-surcharge windows (PRD Section 5) and local-time display (Section 22M)
    currency        VARCHAR(3) NOT NULL,   -- ISO 4217
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('pre_launch', 'active', 'paused')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE zones (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    city_id         UUID NOT NULL REFERENCES cities(id),
    name            VARCHAR(100) NOT NULL,
    zone_type       VARCHAR(20) NOT NULL CHECK (zone_type IN ('service_area', 'surge_zone', 'no_go_zone')),
    boundary        GEOGRAPHY(POLYGON, 4326) NOT NULL,
    operating_hours JSONB,   -- e.g. {"mon": ["06:00-23:00"], ...}
    vehicle_categories TEXT[],   -- categories eligible in this zone; empty/null = all
    version         INTEGER NOT NULL DEFAULT 1,   -- PRD 9C.1: every boundary change is versioned
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_zones_boundary ON zones USING GIST (boundary);
CREATE INDEX idx_zones_city_type ON zones (city_id, zone_type);

-- Historical zone-boundary versions retained for auditability/grandfathering (PRD 9C.1).
CREATE TABLE zone_versions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zone_id         UUID NOT NULL REFERENCES zones(id),
    boundary        GEOGRAPHY(POLYGON, 4326) NOT NULL,
    version         INTEGER NOT NULL,
    effective_from  TIMESTAMPTZ NOT NULL,
    effective_to    TIMESTAMPTZ,
    created_by      UUID REFERENCES users(id)
);

CREATE TABLE vehicle_categories (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name                VARCHAR(50) NOT NULL,
    capacity_descriptor VARCHAR(100),
    license_class_required VARCHAR(20),
    permit_required     BOOLEAN NOT NULL DEFAULT false,
    icon_url            TEXT,
    status              VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE bookings (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    idempotency_key     VARCHAR(100) NOT NULL,
    customer_id         UUID NOT NULL REFERENCES users(id),
    corporate_account_id UUID,   -- FK added in migration 006 once corporate_accounts exists
    driver_id           UUID REFERENCES users(id),
    vehicle_id          UUID REFERENCES vehicles(id),
    status              VARCHAR(20) NOT NULL DEFAULT 'searching'
                        CHECK (status IN ('searching', 'scheduled', 'no_drivers_found', 'driver_assigned',
                                           'in_progress', 'completed', 'cancelled')),
    vehicle_category_id UUID REFERENCES vehicle_categories(id),
    pickup_geo          GEOGRAPHY(POINT, 4326) NOT NULL,
    pickup_address_snapshot JSONB NOT NULL,   -- PRD 2A/Screen 11: bookings store their own address copy, not a live FK
    quote_id            UUID,
    fare_breakdown       JSONB,               -- itemized per PRD Section 5 — the auditable source of truth for this trip's price
    scheduled_at         TIMESTAMPTZ,
    coupon_id            UUID,
    cancellation_reason_code VARCHAR(30),
    cancellation_fee     NUMERIC(10,2),
    cancelled_by         VARCHAR(20) CHECK (cancelled_by IN ('customer', 'driver', 'system')),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),

    -- Idempotency: same key from the same customer can never create two bookings (PRD 2.2.6 hard requirement).
    CONSTRAINT uq_bookings_idempotency UNIQUE (customer_id, idempotency_key)
);
CREATE INDEX idx_bookings_customer ON bookings (customer_id, created_at DESC);
CREATE INDEX idx_bookings_driver ON bookings (driver_id, created_at DESC) WHERE driver_id IS NOT NULL;
CREATE INDEX idx_bookings_status_active ON bookings (status) WHERE status IN ('searching', 'driver_assigned', 'in_progress');
CREATE INDEX idx_bookings_pickup_geo ON bookings USING GIST (pickup_geo);

CREATE TABLE booking_stops (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id      UUID NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
    sequence        SMALLINT NOT NULL,
    geo             GEOGRAPHY(POINT, 4326) NOT NULL,
    address_snapshot JSONB NOT NULL,
    instructions    VARCHAR(100),
    delivery_preference VARCHAR(10) NOT NULL DEFAULT 'otp' CHECK (delivery_preference IN ('otp', 'photo_proof')),
    status          VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'arrived', 'completed')),
    otp_hash         VARCHAR(255),
    proof_photo_url  TEXT,
    arrived_at       TIMESTAMPTZ,
    completed_at     TIMESTAMPTZ,

    -- Sequence integrity: a stop cannot be completed before an earlier-sequence stop
    -- on the same booking is completed. Enforced by application-layer transaction
    -- logic (checks max completed sequence < this sequence before allowing complete),
    -- backed by this uniqueness constraint to prevent duplicate sequence numbers.
    CONSTRAINT uq_booking_stop_sequence UNIQUE (booking_id, sequence)
);
CREATE INDEX idx_booking_stops_booking ON booking_stops (booking_id, sequence);

CREATE TABLE dispatch_offers (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id      UUID NOT NULL REFERENCES bookings(id),
    driver_id       UUID NOT NULL REFERENCES users(id),
    status          VARCHAR(20) NOT NULL DEFAULT 'offered'
                    CHECK (status IN ('offered', 'accepted', 'declined', 'expired', 'revoked')),
    offered_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    responded_at    TIMESTAMPTZ,
    expires_at      TIMESTAMPTZ NOT NULL
);
CREATE INDEX idx_dispatch_offers_booking ON dispatch_offers (booking_id, offered_at DESC);
CREATE INDEX idx_dispatch_offers_driver_active ON dispatch_offers (driver_id) WHERE status = 'offered';

-- Structural guarantee behind PRD Section 4's "a driver can never receive two
-- simultaneous offers": at most one *offered*-status row per driver at a time.
CREATE UNIQUE INDEX uq_dispatch_offers_one_active_per_driver
    ON dispatch_offers (driver_id)
    WHERE status = 'offered';

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS dispatch_offers;
-- DROP TABLE IF EXISTS booking_stops;
-- DROP TABLE IF EXISTS bookings;
-- DROP TABLE IF EXISTS vehicle_categories;
-- DROP TABLE IF EXISTS zone_versions;
-- DROP TABLE IF EXISTS zones;
-- DROP TABLE IF EXISTS cities;
-- COMMIT;
