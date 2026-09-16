-- Migration 011: CMS Banners (PRD 9B.1 — Home screen promo carousel)

BEGIN;

CREATE TABLE banners (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    headline            VARCHAR(60) NOT NULL,   -- PRD 9B.1: enforced against actual card layout constraints
    image_url           TEXT NOT NULL,
    cta_text            VARCHAR(30),
    cta_deep_link       VARCHAR(200) NOT NULL,
    linked_coupon_id    UUID REFERENCES coupons(id),
    target_segment      VARCHAR(50),            -- null = all users
    priority            INTEGER NOT NULL DEFAULT 0,  -- deterministic tie-break for overlapping banners (PRD 9B.1 edge case)
    status              VARCHAR(20) NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft', 'scheduled', 'live', 'expired')),
    start_at            TIMESTAMPTZ NOT NULL,
    end_at              TIMESTAMPTZ NOT NULL,
    created_by          UUID REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_banner_dates CHECK (end_at > start_at)
);
CREATE INDEX idx_banners_live ON banners (status, start_at, end_at) WHERE status IN ('scheduled', 'live');

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS banners;
-- COMMIT;
