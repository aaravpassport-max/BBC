-- Migration 002: Driver profiles, Vehicles, KYC documents (PRD Section 24, Section 3.2, Section 7)

BEGIN;

CREATE TABLE driver_profiles (
    user_id             UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    kyc_status          VARCHAR(20) NOT NULL DEFAULT 'incomplete'
                        CHECK (kyc_status IN ('incomplete', 'pending_review', 'approved', 'rejected')),
    training_status     VARCHAR(20) NOT NULL DEFAULT 'not_started'
                        CHECK (training_status IN ('not_started', 'in_progress', 'passed')),
    rating_avg          NUMERIC(3,2) DEFAULT NULL,   -- server-computed rolling average (PRD 17)
    rating_count        INTEGER NOT NULL DEFAULT 0,
    online_status       BOOLEAN NOT NULL DEFAULT false,
    current_lat         DOUBLE PRECISION,
    current_lng         DOUBLE PRECISION,
    last_ping_at        TIMESTAMPTZ,
    suspended_at        TIMESTAMPTZ,
    suspension_reason    VARCHAR(50),                 -- fixed taxonomy per PRD 9A.2
    fleet_owner_id      UUID REFERENCES users(id),    -- null if independent owner-driver
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Job-eligibility is derived, not stored directly: a driver is eligible only if
-- kyc_status = 'approved' AND training_status = 'passed' AND suspended_at IS NULL
-- AND has no expired required document (see driver_documents below). Application
-- layer enforces this on every dispatch-eligibility check (PRD Section 4, 9A.2).

CREATE TABLE vehicles (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_type      VARCHAR(10) NOT NULL CHECK (owner_type IN ('driver', 'fleet')),
    owner_id        UUID NOT NULL REFERENCES users(id),
    category        VARCHAR(30) NOT NULL,
    make            VARCHAR(50),
    model           VARCHAR(50),
    plate_number    VARCHAR(20) NOT NULL,
    rc_number       VARCHAR(50),
    insurance_expiry DATE,
    permit_expiry    DATE,
    puc_expiry       DATE,
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_vehicles_plate UNIQUE (plate_number)
);
CREATE INDEX idx_vehicles_owner ON vehicles (owner_type, owner_id);

CREATE TABLE driver_vehicle_assignment (
    driver_id       UUID NOT NULL REFERENCES users(id),
    vehicle_id      UUID NOT NULL REFERENCES vehicles(id),
    is_active       BOOLEAN NOT NULL DEFAULT true,
    effective_from  TIMESTAMPTZ NOT NULL DEFAULT now(),
    effective_to    TIMESTAMPTZ,          -- null while current; set when reassigned (PRD 13A.1)
    scheduled_reassignment_to UUID REFERENCES users(id),  -- pending "on-next-trip-completion" reassignment
    PRIMARY KEY (driver_id, vehicle_id, effective_from)
);
-- Ensures a vehicle has at most one *active* assignment at a time.
CREATE UNIQUE INDEX uq_vehicle_active_assignment
    ON driver_vehicle_assignment (vehicle_id)
    WHERE is_active = true;

CREATE TABLE kyc_documents (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    subject_type        VARCHAR(20) NOT NULL CHECK (subject_type IN ('driver', 'vehicle', 'corporate_account')),
    subject_id          UUID NOT NULL,
    doc_type            VARCHAR(30) NOT NULL,   -- identity, driving_license, rc, insurance, permit, puc, bank_details
    status              VARCHAR(20) NOT NULL DEFAULT 'pending_review'
                        CHECK (status IN ('pending_review', 'approved', 'rejected', 'expired')),
    rejection_reason    VARCHAR(30),            -- fixed taxonomy: DOC_BLURRY, DOC_EXPIRED, NAME_MISMATCH, FACE_MISMATCH, OTHER
    rejection_note      TEXT,
    document_url        TEXT NOT NULL,          -- S3-class object store reference, encrypted at rest (PRD Section 27)
    ocr_extracted       JSONB,
    manual_entry        JSONB,
    expiry_date         DATE,
    version             INTEGER NOT NULL DEFAULT 1,   -- resubmissions increment version; prior versions retained
    superseded_by       UUID REFERENCES kyc_documents(id),
    reviewed_by         UUID REFERENCES users(id),
    reviewed_at         TIMESTAMPTZ,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_kyc_documents_subject ON kyc_documents (subject_type, subject_id, doc_type);
CREATE INDEX idx_kyc_documents_pending ON kyc_documents (status, created_at) WHERE status = 'pending_review';
CREATE INDEX idx_kyc_documents_expiry ON kyc_documents (expiry_date) WHERE status = 'approved';

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS kyc_documents;
-- DROP TABLE IF EXISTS driver_vehicle_assignment;
-- DROP TABLE IF EXISTS vehicles;
-- DROP TABLE IF EXISTS driver_profiles;
-- COMMIT;
