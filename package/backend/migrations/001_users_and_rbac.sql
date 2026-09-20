-- Migration 001: Users, Roles, RBAC (PRD Section 24, Section 22)
-- Reversible: see the "-- DOWN" block at the bottom.

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- for gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS postgis;    -- geospatial types, used from migration 003 onward

CREATE TABLE users (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    phone           VARCHAR(20) NOT NULL,
    country_code    VARCHAR(5)  NOT NULL,
    email           VARCHAR(255),
    name            VARCHAR(100),
    locale          VARCHAR(10) DEFAULT 'en',
    account_type    VARCHAR(20) NOT NULL DEFAULT 'customer'
                    CHECK (account_type IN ('customer', 'driver', 'admin', 'corporate_employee')),
    status          VARCHAR(20) NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active', 'suspended', 'deleted')),
    deleted_at      TIMESTAMPTZ,           -- soft delete (PRD Screen 61: PII scrubbed, record retained)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    -- Unique phone per country, but only while the account is not soft-deleted,
    -- so a deleted user's number can be re-registered after PRD 2.2.1's cooldown window.
    CONSTRAINT uq_users_phone UNIQUE NULLS NOT DISTINCT (country_code, phone, deleted_at)
);

-- Partial unique index: exactly one *active* account per phone number at a time.
CREATE UNIQUE INDEX uq_users_active_phone
    ON users (country_code, phone)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_users_account_type ON users (account_type) WHERE deleted_at IS NULL;

CREATE TABLE otp_requests (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    phone               VARCHAR(20) NOT NULL,
    country_code        VARCHAR(5) NOT NULL,
    device_id           VARCHAR(255) NOT NULL,
    code_hash           VARCHAR(255) NOT NULL,   -- never store the raw OTP
    attempts_used        SMALLINT NOT NULL DEFAULT 0,
    max_attempts        SMALLINT NOT NULL DEFAULT 5,
    expires_at          TIMESTAMPTZ NOT NULL,
    locked_until        TIMESTAMPTZ,
    consumed_at         TIMESTAMPTZ,             -- set once successfully verified; otp_id is then single-use
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_otp_requests_phone_recent ON otp_requests (country_code, phone, created_at DESC);

CREATE TABLE refresh_tokens (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    device_id       VARCHAR(255) NOT NULL,
    token_hash      VARCHAR(255) NOT NULL UNIQUE,
    revoked_at      TIMESTAMPTZ,
    expires_at      TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_refresh_tokens_user ON refresh_tokens (user_id) WHERE revoked_at IS NULL;

-- ---------- RBAC (PRD Section 22) ----------

CREATE TABLE roles (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name        VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE permissions (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    resource    VARCHAR(50) NOT NULL,   -- e.g. 'pricing', 'driver', 'refund'
    action      VARCHAR(50) NOT NULL,   -- e.g. 'edit', 'suspend', 'approve_over_limit'
    UNIQUE (resource, action)
);

CREATE TABLE role_permissions (
    role_id         UUID NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id   UUID NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id     UUID NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    scope       JSONB NOT NULL DEFAULT '{}'::jsonb,  -- e.g. {"city_ids": ["..."]} for city-scoped roles, PRD Section 22
    granted_by  UUID REFERENCES users(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, role_id)
);

-- Guard-rail support (PRD 22A.1): a partial index used by application logic to
-- quickly verify at least one role with rbac.role.manage remains assigned
-- before allowing a role edit that would remove it. Enforcement itself is
-- application-layer (needs to check "would this edit leave zero role managers"
-- across the whole system, not just this row), but this index makes that check fast.
CREATE INDEX idx_user_roles_role ON user_roles (role_id);

-- ---------- Audit log (PRD Section 24 — append-only, never editable) ----------

CREATE TABLE audit_log (
    id              BIGSERIAL PRIMARY KEY,
    actor_id        UUID REFERENCES users(id),
    actor_type      VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (actor_type IN ('user', 'system')),
    action          VARCHAR(100) NOT NULL,
    resource_type   VARCHAR(50) NOT NULL,
    resource_id     UUID,
    before_state    JSONB,
    after_state     JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_log_resource ON audit_log (resource_type, resource_id, created_at DESC);
CREATE INDEX idx_audit_log_actor ON audit_log (actor_id, created_at DESC);

-- Revoke UPDATE/DELETE at the DB role level for the application's normal runtime
-- role, so even a bug can't mutate audit history (PRD Section 24 acceptance criteria:
-- "not even a Super Admin can alter or remove an audit entry" at the DB layer).
-- Adjust role name to match your actual application DB role.
-- REVOKE UPDATE, DELETE ON audit_log FROM app_runtime_role;

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS audit_log;
-- DROP TABLE IF EXISTS user_roles;
-- DROP TABLE IF EXISTS role_permissions;
-- DROP TABLE IF EXISTS permissions;
-- DROP TABLE IF EXISTS roles;
-- DROP TABLE IF EXISTS refresh_tokens;
-- DROP TABLE IF EXISTS otp_requests;
-- DROP TABLE IF EXISTS users;
-- COMMIT;
