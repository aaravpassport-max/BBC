# Logistics Super App — Backend

Node.js + TypeScript + Express + PostgreSQL (PostGIS) reference implementation
of the platform described in the Master PRD.

## Requirements

- Node.js 20+
- PostgreSQL 16+ with the PostGIS extension available

## Setup

```bash
npm install

# Option A — Docker (recommended when available)
docker compose up --build
# Migrations run automatically on backend start.

# Option B — native Postgres (no Docker)
./scripts/dev-setup.sh
# Creates app_user/logistics_superapp, enables PostGIS, runs migrate + seed.

# Option C — manual
createdb logistics_superapp
psql -d logistics_superapp -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"
psql -d logistics_superapp -c "CREATE EXTENSION IF NOT EXISTS postgis;"
cp .env.example .env
# edit .env — set DATABASE_URL and JWT secrets
npm run build
npm run migrate
npm run seed

npm run dev       # starts the API on http://localhost:3000
```

To grant yourself the full admin role (needed to use the Admin/RBAC/Fraud/
Analytics endpoints) after logging in once via the normal OTP flow:

```sql
INSERT INTO user_roles (user_id, role_id)
VALUES ('<your user id from the OTP verify response>',
        (SELECT id FROM roles WHERE name = 'ops_admin'));
```

To grant yourself Control Room access (SOS response and dispatch
monitoring — a separate, narrower role from `ops_admin`, matching how a
real deployment staffs a larger on-duty Control Room team distinctly from
the handful of full admins):

```sql
INSERT INTO user_roles (user_id, role_id)
VALUES ('<your user id from the OTP verify response>',
        (SELECT id FROM roles WHERE name = 'control_room_operator'));
```

To additionally grant yourself the ability to escalate an SOS event to the
safety team lead (`ops.sos_escalate` — deliberately separate from the
standard on-duty `control_room_operator` role above, per the PRD's
explicit two-permission split):

```sql
INSERT INTO user_roles (user_id, role_id)
VALUES ('<your user id from the OTP verify response>',
        (SELECT id FROM roles WHERE name = 'safety_team_lead'));
```

## Testing

```bash
# Create a SEPARATE test database — tests run destructively and should
# never point at your dev database.
createdb logistics_superapp_test
psql -d logistics_superapp_test -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"
psql -d logistics_superapp_test -c "CREATE EXTENSION IF NOT EXISTS postgis;"
for f in migrations/*.sql; do psql -d logistics_superapp_test -f "$f"; done
psql -d logistics_superapp_test -f seed/001_reference_data.sql

cp .env.example .env.test
# edit .env.test — two changes are required, not just DATABASE_URL:
#   1. DATABASE_URL must point at logistics_superapp_test, not the dev DB
#   2. OTP_RESEND_COOLDOWN_SECONDS must be 0 (not the .env.example default
#      of 30) — several tests legitimately request an OTP for the same
#      phone number twice in quick succession (e.g. verifying the same
#      phone returns the same user), and the real 30s production cooldown
#      will cause those to fail with a genuine, correctly-enforced rate
#      limit that has nothing to do with a bug in the code under test.

npm test
```

The suite is a real integration suite (Jest + Supertest) against a real
Postgres database — nothing is mocked. It includes several deliberate
concurrency stress tests (booking idempotency, coupon/corporate-credit
races, dispatch's no-double-offer guarantee) that fire genuinely concurrent
requests and assert on the database state afterward.

## Architecture notes

- Every module lives under `src/modules/<name>/` as `<name>.service.ts`
  (business logic) + `<name>.routes.ts` (HTTP layer). Tests live alongside
  in `__tests__/`.
- `POST` endpoints that create or mutate financial/booking state require an
  `Idempotency-Key` header — this is enforced, not optional.
- Financial mutations use a double-entry ledger pattern
  (`wallet_transactions`), never a single mutable balance column.
- Several routes are explicitly marked `dev-only` in code comments — they
  stand in for infrastructure this reference implementation doesn't include
  (a real event bus for dispatch triggering, a real SMS provider for OTP
  delivery, a real payment gateway webhook). OTP codes are logged to the
  console rather than sent by SMS; look for `[DEV ONLY]` in the server log.
- See `PRD.md` (in the top-level package) for the full requirements this
  implementation is built against — every service file's comments reference
  specific PRD sections.

## Real payment gateway (Razorpay)

Wallet top-ups use a real, complete Razorpay integration
(`src/modules/wallet/razorpay.provider.ts`) — genuine order creation,
genuine HMAC signature verification for both the client-side confirmation
and the server-to-server webhook, built against Razorpay's own documented
API contract. It's not wired to a live account by default: without
credentials, it falls back to the pre-existing simulated dev flow with
zero behavior change, so local development needs nothing extra.

To activate real payment processing, set these three in `.env` (get them
from your Razorpay dashboard — Settings → API Keys, and Settings →
Webhooks for the third):

```bash
RAZORPAY_KEY_ID=rzp_live_xxxxxxxxxxxx
RAZORPAY_KEY_SECRET=your_key_secret
RAZORPAY_WEBHOOK_SECRET=your_webhook_secret
```

No code changes are needed anywhere else — `initiateTopUp` checks
`isConfigured()` and switches to the real flow automatically. You'll also
need to point Razorpay's webhook configuration at
`https://your-domain.com/v1/wallet/webhook` (POST, `payment.captured`
event) for the real server-to-server confirmation path to reach your
backend.

This reference environment has no network access to api.razorpay.com and
no real Razorpay account, so the actual HTTP call to their servers could
not be exercised here — what IS verified: the request is built exactly to
their documented contract, and both signature-verification functions are
tested with real cryptographic assertions against Razorpay's own published
HMAC scheme (`src/modules/wallet/__tests__/razorpay.provider.test.ts`).

## Known gaps (see the PRD's own module-status notes for the full list)

- No real SMS provider or maps/routing provider integration — both
  stubbed with clearly-marked dev-only substitutes. (Payment gateway is
  real — see the section above.)
- Admin Console (`../admin-app`) covers Rate Cards, Drivers, Fraud Queue,
  Support, Analytics, Marketing/CMS, and RBAC role management — 7 surfaces.
- Corporate Portal (`../corporate-portal`) covers company self-service:
  account discovery, invite acceptance, and team management. No booking
  history or spend-by-employee breakdown yet — see its README's Known
  gaps.
- Ops/Control Room (`../ops-console`) covers SOS handling (including the
  full acknowledge/resolve/escalate flow and an automatic escalation sweep
  for unacknowledged alerts) and dispatch monitoring/force-assign. No live
  map/location-streaming (no WebSocket layer in this reference backend) —
  see ops-console/README's Known gaps.
