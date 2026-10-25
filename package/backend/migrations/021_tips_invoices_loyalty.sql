-- Tips, cached trip invoices, loyalty redemption tracking

BEGIN;

CREATE TABLE trip_invoices (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id      UUID NOT NULL UNIQUE REFERENCES bookings(id),
    customer_id     UUID NOT NULL REFERENCES users(id),
    invoice_number  VARCHAR(30) NOT NULL,
    amount          NUMERIC(14,2) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active', 'superseded')),
    generated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_trip_invoices_customer ON trip_invoices (customer_id, generated_at DESC);

CREATE TABLE tips (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_id      UUID NOT NULL UNIQUE REFERENCES bookings(id),
    customer_id     UUID NOT NULL REFERENCES users(id),
    driver_id       UUID NOT NULL REFERENCES users(id),
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE loyalty_redemptions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id),
    quote_id        UUID REFERENCES quotes(id),
    booking_id      UUID REFERENCES bookings(id),
    points_used     INTEGER NOT NULL CHECK (points_used > 0),
    discount_amount NUMERIC(14,2) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMIT;
