-- Migration 006: Corporate accounts, subscriptions, support tickets
-- (PRD Section 11, 14, 14A.1, 14B.1, 19A.1, 11A.1)

BEGIN;

CREATE TABLE corporate_accounts (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name            VARCHAR(150) NOT NULL,
    credit_limit    NUMERIC(14,2) NOT NULL DEFAULT 0,
    committed_spend NUMERIC(14,2) NOT NULL DEFAULT 0,   -- finalized (post-completion) charges this billing period
    reserved_spend  NUMERIC(14,2) NOT NULL DEFAULT 0,   -- in-flight bookings' reserved-but-not-finalized amounts (PRD 14A.1)
    rate_card_override JSONB,   -- contract rate card, overrides standard formula per PRD Section 5
    surge_exempt    BOOLEAN NOT NULL DEFAULT false,
    version         INTEGER NOT NULL DEFAULT 1,   -- optimistic lock for the atomic reservation flow (PRD 14A.1)
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_corporate_spend_within_limit CHECK (committed_spend + reserved_spend <= credit_limit)
);

ALTER TABLE bookings ADD CONSTRAINT fk_bookings_corporate FOREIGN KEY (corporate_account_id) REFERENCES corporate_accounts(id);

-- Reservation ledger, distinct rows so a cancelled/completed booking's reservation
-- release is itself an auditable event (PRD 14A.1 lifecycle: reserved -> finalized | released).
CREATE TABLE corporate_reservations (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    corporate_account_id UUID NOT NULL REFERENCES corporate_accounts(id),
    booking_id           UUID NOT NULL REFERENCES bookings(id) UNIQUE,
    reserved_amount        NUMERIC(14,2) NOT NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'reserved'
                            CHECK (status IN ('reserved', 'finalized', 'released')),
    final_amount             NUMERIC(14,2),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at                TIMESTAMPTZ
);

CREATE TABLE corporate_employees (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    corporate_account_id UUID NOT NULL REFERENCES corporate_accounts(id),
    user_id               UUID REFERENCES users(id),   -- null until invite accepted
    email                  VARCHAR(255) NOT NULL,
    role                    VARCHAR(20) NOT NULL DEFAULT 'employee' CHECK (role IN ('employee', 'account_admin')),
    per_user_monthly_cap    NUMERIC(14,2),
    status                  VARCHAR(20) NOT NULL DEFAULT 'invited' CHECK (status IN ('invited', 'active', 'removed')),
    invited_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    removed_at               TIMESTAMPTZ,

    CONSTRAINT uq_corporate_employee_email UNIQUE (corporate_account_id, email)
);
-- Guard-rail support for PRD 14B.1 "last remaining Account Admin cannot self-demote":
-- application layer counts active account_admin rows for the account inside the same
-- transaction as any role-change, using this index for the fast path.
CREATE INDEX idx_corporate_employees_admins ON corporate_employees (corporate_account_id)
    WHERE role = 'account_admin' AND status = 'active';

CREATE TABLE recurring_bookings (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    corporate_account_id UUID NOT NULL REFERENCES corporate_accounts(id),
    owner_employee_id     UUID NOT NULL REFERENCES corporate_employees(id),   -- auto-transferred on employee removal (PRD 14A.1/14B.1)
    pickup_address_ref      UUID,
    drop_address_ref         UUID,
    recurrence_pattern         JSONB NOT NULL,   -- days of week + time window
    exceptions_skip_dates       DATE[],
    status                       VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'cancelled')),
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------- Subscriptions (PRD 19A.1) ----------

CREATE TABLE subscriptions (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id             UUID NOT NULL REFERENCES users(id),
    plan_id              VARCHAR(30) NOT NULL,
    status                VARCHAR(20) NOT NULL DEFAULT 'active'
                          CHECK (status IN ('active', 'grace_period', 'lapsed', 'cancelled')),
    current_period_start   TIMESTAMPTZ NOT NULL,
    current_period_end      TIMESTAMPTZ NOT NULL,
    grace_period_ends_at      TIMESTAMPTZ,
    lapsed_at                  TIMESTAMPTZ,
    retry_count                 SMALLINT NOT NULL DEFAULT 0,
    payment_method_id             VARCHAR(100),
    created_at                     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_subscriptions_user ON subscriptions (user_id) WHERE status IN ('active', 'grace_period');
CREATE INDEX idx_subscriptions_renewal_due ON subscriptions (current_period_end) WHERE status = 'active';

-- ---------- Support Tickets (PRD 11A.1, 11B.1) ----------

CREATE TABLE support_tickets (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id             UUID NOT NULL REFERENCES users(id),
    category             VARCHAR(30) NOT NULL,
    linked_booking_id      UUID REFERENCES bookings(id),
    priority                VARCHAR(10) NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    status                  VARCHAR(20) NOT NULL DEFAULT 'open'
                            CHECK (status IN ('open', 'in_progress', 'pending_customer', 'escalated', 'closed')),
    assigned_agent_id        UUID REFERENCES users(id),
    sla_due_at                 TIMESTAMPTZ NOT NULL,
    sla_breached                 BOOLEAN NOT NULL DEFAULT false,
    resolution_category            VARCHAR(30),
    resolution_note                  TEXT,
    reopen_of_ticket_id                 UUID REFERENCES support_tickets(id),   -- links a post-window reopen to the original (PRD 11A.1)
    idempotency_key                       VARCHAR(100),
    created_at                              TIMESTAMPTZ NOT NULL DEFAULT now(),
    closed_at                                 TIMESTAMPTZ,

    CONSTRAINT uq_ticket_idempotency UNIQUE (user_id, idempotency_key)
);
CREATE INDEX idx_tickets_queue ON support_tickets (status, priority, sla_due_at) WHERE status NOT IN ('closed');
CREATE INDEX idx_tickets_user ON support_tickets (user_id, created_at DESC);

CREATE TABLE support_ticket_messages (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    ticket_id       UUID NOT NULL REFERENCES support_tickets(id),
    sender_id       UUID NOT NULL REFERENCES users(id),
    sender_role     VARCHAR(10) NOT NULL CHECK (sender_role IN ('customer', 'agent', 'system')),
    body            TEXT NOT NULL,
    attachments     TEXT[],
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ticket_messages_ticket ON support_ticket_messages (ticket_id, created_at);

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS support_ticket_messages;
-- DROP TABLE IF EXISTS support_tickets;
-- DROP TABLE IF EXISTS subscriptions;
-- DROP TABLE IF EXISTS recurring_bookings;
-- DROP TABLE IF EXISTS corporate_employees;
-- DROP TABLE IF EXISTS corporate_reservations;
-- ALTER TABLE bookings DROP CONSTRAINT IF EXISTS fk_bookings_corporate;
-- DROP TABLE IF EXISTS corporate_accounts;
-- COMMIT;
