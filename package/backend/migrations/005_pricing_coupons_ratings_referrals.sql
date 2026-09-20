-- Migration 005: Rate cards, coupons, ratings, referrals, notifications, fraud, background jobs
-- (PRD Section 5, 9A.1, 15A.1, 17B.1, 18A.1, 16, 17A.1, 25)

BEGIN;

-- ---------- Pricing / Rate Cards (PRD 9A.1) ----------

CREATE TABLE rate_cards (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    city_id             UUID NOT NULL REFERENCES cities(id),
    vehicle_category_id UUID NOT NULL REFERENCES vehicle_categories(id),
    base_fare           NUMERIC(10,2) NOT NULL CHECK (base_fare > 0),
    per_km_rate         NUMERIC(10,2) NOT NULL CHECK (per_km_rate > 0),
    per_min_rate        NUMERIC(10,2) NOT NULL DEFAULT 0,
    waiting_free_min    SMALLINT NOT NULL DEFAULT 5,
    waiting_per_min_rate NUMERIC(10,2) NOT NULL DEFAULT 0,
    night_surcharge_pct NUMERIC(5,2) NOT NULL DEFAULT 0,
    night_window_start  TIME,
    night_window_end    TIME,
    minimum_fare        NUMERIC(10,2) NOT NULL,
    platform_fee        NUMERIC(10,2) NOT NULL DEFAULT 0,
    tax_rate_pct        NUMERIC(5,2) NOT NULL DEFAULT 0,
    surge_tiers         JSONB NOT NULL DEFAULT '[1.0, 1.2, 1.5, 2.0]'::jsonb,   -- discrete steps only (PRD Section 5)
    surge_cap           NUMERIC(3,2) NOT NULL DEFAULT 3.0,
    version             INTEGER NOT NULL DEFAULT 1,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'scheduled', 'published', 'superseded')),
    effective_from      TIMESTAMPTZ NOT NULL DEFAULT now(),
    published_by        UUID REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_min_fare_gte_base CHECK (minimum_fare >= base_fare)
);
CREATE INDEX idx_rate_cards_active ON rate_cards (city_id, vehicle_category_id, effective_from DESC)
    WHERE status = 'published';

-- Quotes are short-lived and TTL-bound (PRD 2.2.5) — locking the exact rate card
-- version and coefficients used, so a later rate-card publish never re-prices
-- an already-issued quote.
CREATE TABLE quotes (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    rate_card_id        UUID NOT NULL REFERENCES rate_cards(id),
    rate_card_version   INTEGER NOT NULL,
    customer_id         UUID NOT NULL REFERENCES users(id),
    pickup_geo          GEOGRAPHY(POINT, 4326) NOT NULL,
    drops_geo           JSONB NOT NULL,
    vehicle_category_id UUID NOT NULL REFERENCES vehicle_categories(id),
    surge_multiplier    NUMERIC(3,2) NOT NULL DEFAULT 1.0,
    fare_breakdown       JSONB NOT NULL,
    coupon_id            UUID,
    expires_at           TIMESTAMPTZ NOT NULL,
    consumed_at          TIMESTAMPTZ,   -- set once used by a booking; single-use (PRD 2.2.5)
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_quotes_customer_recent ON quotes (customer_id, created_at DESC);

ALTER TABLE bookings ADD CONSTRAINT fk_bookings_quote FOREIGN KEY (quote_id) REFERENCES quotes(id);

-- ---------- Coupons (PRD 15A.1) ----------

CREATE TABLE coupons (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code                VARCHAR(20) NOT NULL UNIQUE,
    discount_type       VARCHAR(10) NOT NULL CHECK (discount_type IN ('flat', 'percent')),
    discount_value      NUMERIC(10,2) NOT NULL CHECK (discount_value > 0),
    max_discount_cap    NUMERIC(10,2),   -- required if discount_type = 'percent', enforced at application layer
    min_order_value     NUMERIC(10,2) DEFAULT 0,
    per_user_limit      INTEGER,
    global_limit        INTEGER,
    global_redeemed_count INTEGER NOT NULL DEFAULT 0,   -- atomically incremented; see uq below
    segment_id          UUID,
    applicable_categories UUID[],
    applicable_zones     UUID[],
    stacking_allowed     BOOLEAN NOT NULL DEFAULT false,
    valid_from            TIMESTAMPTZ NOT NULL,
    valid_to              TIMESTAMPTZ NOT NULL,
    status                VARCHAR(20) NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft', 'active', 'paused', 'expired', 'usage_cap_reached')),
    created_by            UUID REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_valid_dates CHECK (valid_to > valid_from)
);

CREATE TABLE coupon_redemptions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    coupon_id       UUID NOT NULL REFERENCES coupons(id),
    user_id         UUID NOT NULL REFERENCES users(id),
    booking_id      UUID NOT NULL REFERENCES bookings(id),
    discount_amount NUMERIC(10,2) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Enforces per_user_limit=1 case atomically at the DB layer (PRD 15A.1 concurrency
-- requirement). For per_user_limit > 1, application layer counts rows against the limit
-- inside the same transaction that inserts this row, using SELECT ... FOR UPDATE
-- on the coupon row to serialize concurrent redemption attempts.
CREATE UNIQUE INDEX uq_coupon_redemption_single_use
    ON coupon_redemptions (coupon_id, user_id)
    WHERE true;  -- narrow this to a partial index keyed off coupons.per_user_limit = 1 in application migration if mixed limits are needed simultaneously

-- ---------- Ratings (PRD 17B.1) ----------

CREATE TABLE ratings (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id      UUID NOT NULL REFERENCES bookings(id),
    rater_id        UUID NOT NULL REFERENCES users(id),
    ratee_id        UUID NOT NULL REFERENCES users(id),
    stars           SMALLINT NOT NULL CHECK (stars BETWEEN 1 AND 5),
    tags            TEXT[],
    comment         TEXT,
    is_late         BOOLEAN NOT NULL DEFAULT false,   -- submitted after the rating window (PRD 17B.1) — excluded from live dispatch scoring
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    -- One rating per rater per booking (PRD 17B.1 "attempt second rating" rule —
    -- deployment choice here is reject-on-duplicate; switch to an updatable row
    -- with an edit-window if the product decision is edit-in-place instead).
    CONSTRAINT uq_rating_once_per_rater UNIQUE (booking_id, rater_id)
);
CREATE INDEX idx_ratings_ratee ON ratings (ratee_id, created_at DESC);

-- ---------- Referrals (PRD 18A.1) ----------

CREATE TABLE referrals (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    referrer_id         UUID NOT NULL REFERENCES users(id),
    referee_id          UUID NOT NULL REFERENCES users(id) UNIQUE,   -- a referee can only ever be referred once
    referral_code        VARCHAR(20) NOT NULL,
    status                VARCHAR(20) NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'pending_review', 'fulfilled', 'void')),
    qualifying_booking_id UUID REFERENCES bookings(id),
    fraud_hold_reason      TEXT,
    resolved_by             UUID REFERENCES users(id),
    resolved_at              TIMESTAMPTZ,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),

    -- Idempotent reward issuance: FULFILLED can only be reached once per referral (PRD 18A.1).
    CONSTRAINT uq_referral_fulfilled_once CHECK (status <> 'fulfilled' OR qualifying_booking_id IS NOT NULL)
);
CREATE INDEX idx_referrals_referrer ON referrals (referrer_id);

-- ---------- Fraud Queue (PRD 17A.1) ----------

CREATE TABLE fraud_flags (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    subject_type    VARCHAR(20) NOT NULL CHECK (subject_type IN ('user', 'driver', 'booking', 'referral')),
    subject_id      UUID NOT NULL,
    signal_types    TEXT[] NOT NULL,   -- e.g. {'gps_spoofing', 'device_cluster'} — merged flags per PRD 17A.1
    evidence        JSONB NOT NULL,
    severity        VARCHAR(10) NOT NULL DEFAULT 'low' CHECK (severity IN ('low', 'medium', 'high')),
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'escalated', 'cleared', 'held', 'suspended')),
    max_silent_hold_until TIMESTAMPTZ,   -- past this, must surface to a human reviewer (PRD 17/17A.1 rule)
    resolved_by      UUID REFERENCES users(id),
    resolution_note  TEXT,
    resolved_at      TIMESTAMPTZ,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_fraud_flags_pending ON fraud_flags (status, created_at) WHERE status IN ('pending', 'escalated', 'held');
CREATE INDEX idx_fraud_flags_subject ON fraud_flags (subject_type, subject_id);

-- ---------- Notifications (PRD Section 16) ----------

CREATE TABLE notification_preferences (
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category    VARCHAR(30) NOT NULL,
    channel     VARCHAR(10) NOT NULL,
    enabled     BOOLEAN NOT NULL DEFAULT true,
    PRIMARY KEY (user_id, category, channel),
    -- OTP and SOS categories are never inserted here — enforced at application layer,
    -- not representable as a settable row for those categories at all.
    CONSTRAINT chk_no_critical_category CHECK (category NOT IN ('otp', 'sos'))
);

CREATE TABLE notification_log (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id),
    event_id        UUID NOT NULL,   -- source domain event id, used for idempotent dedup (PRD 16A.2)
    category        VARCHAR(30) NOT NULL,
    channel         VARCHAR(10) NOT NULL,
    template_id     VARCHAR(50) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'queued'
                    CHECK (status IN ('queued', 'sent', 'delivered', 'failed', 'bounced')),
    fallback_of      UUID REFERENCES notification_log(id),   -- set when this row is a fallback send
    provider_ref      VARCHAR(100),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_notification_event_channel ON notification_log (event_id, channel, fallback_of);
CREATE INDEX idx_notification_log_user ON notification_log (user_id, created_at DESC);

-- ---------- Background job monitoring (PRD Section 25/26) ----------

CREATE TABLE background_job_runs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    job_name        VARCHAR(100) NOT NULL,   -- e.g. 'ledger_integrity_check', 'document_expiry_scanner'
    status          VARCHAR(20) NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'succeeded', 'failed')),
    started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at     TIMESTAMPTZ,
    error_detail    TEXT,
    rows_affected   INTEGER
);
CREATE INDEX idx_job_runs_name_recent ON background_job_runs (job_name, started_at DESC);
-- Alerting rule (implemented in the monitoring layer, PRD Section 28): page on-call if
-- MAX(started_at) for any expected job_name is older than that job's configured cadence.

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS background_job_runs;
-- DROP TABLE IF EXISTS notification_log;
-- DROP TABLE IF EXISTS notification_preferences;
-- DROP TABLE IF EXISTS fraud_flags;
-- DROP TABLE IF EXISTS referrals;
-- DROP TABLE IF EXISTS ratings;
-- DROP TABLE IF EXISTS coupon_redemptions;
-- DROP TABLE IF EXISTS coupons;
-- ALTER TABLE bookings DROP CONSTRAINT IF EXISTS fk_bookings_quote;
-- DROP TABLE IF EXISTS quotes;
-- DROP TABLE IF EXISTS rate_cards;
-- COMMIT;
