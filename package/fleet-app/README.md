# Fleet Owner App

A web app for a fleet owner — someone who manages multiple driver-partners
and vehicles, as distinct from an owner-operator who drives their own
single vehicle. Matches Porter's own "Owner Assist" product category from
the original gap-analysis comparison, which flagged this as the single
largest gap in the platform.

**Worth being upfront about, since it changes how this section reads:**
the original gap-analysis report framed this as needing "a new entity in
the data model" before any UI would be meaningful. That turned out to be
wrong on closer inspection — `driver_profiles.fleet_owner_id` and
`vehicles.owner_type='fleet'` already existed in the schema from an
earlier pass, along with real, tested vehicle-reassignment logic
(`backend/src/modules/fleet/`). What was genuinely missing, and what this
app and its backend work actually built: a way to see a fleet's drivers
at all, add a driver to a fleet through a real endpoint (previously only
a raw-SQL test helper could do it), see fleet-wide earnings, see one
driver's own detail, and the app itself.

## Requirements

- Node.js 20+
- The backend running locally (`../backend`) with its database seeded —
  see `../backend/README.md`

## Setup

```bash
npm install
npm run dev
```

## What's implemented

- **Login / Verify** (`/login`, `/verify`) — the same real phone + OTP flow
  every app in this platform shares.
- **Dashboard** (`/home`) — today's real fleet-wide earnings (summed live
  from every driver's own wallet ledger, not a separately-maintained
  balance that could drift — see the note on `getFleetEarningsSummary`
  in `backend/src/modules/fleet/fleet.service.ts`) and the real driver
  roster, each with a live, derived status (online / offline / **on
  trip** — computed from whether that driver currently has an
  active booking, not a separately-stored flag).
- **Add driver** (`/add-driver`) — links an *existing* driver account to
  this fleet by phone number. Deliberately not a way to create a driver
  out of nothing — the driver must already have registered and gone
  through their own onboarding on the Driver app, matching how recruiting
  an existing platform driver into a fleet actually works. A driver
  already in a different fleet can't be silently poached.
- **Driver detail** (`/driver/:driverId`) — a single fleet driver's own
  wallet balance and transaction history, and a way to remove them from
  the fleet (their own account, KYC status, and earnings history are
  completely untouched by removal — only the fleet link is cleared).
- **Vehicles** (`/vehicles`) — the fleet's vehicles with a real
  reassignment control. Reuses vehicle-reassignment logic that already
  existed and was already tested: reassigning a vehicle whose current
  driver has an active trip doesn't interrupt it — it's automatically
  downgraded to "apply on next trip completion" instead.

## A real gap found and fixed while building the earnings dashboard

Building `getFleetEarningsSummary` surfaced something more fundamental
than a missing report: **nothing anywhere in this codebase ever credited
a driver's wallet for completing a trip.** The fleet earnings dashboard
would have silently always shown ₹0 — not because of a bug in the
reporting query, but because the money was never being paid out in the
first place, for any trip, for any driver, ever. Fixed in
`backend/src/modules/wallet/wallet.service.ts`'s new
`creditDriverTripEarnings` (payout = final fare minus the platform's own
fee, idempotent per booking) and wired into trip completion in
`backend/src/modules/driver/trip.service.ts`. Verified with a real,
complete trip (real quote → real booking → real pickup OTP → real drop
OTP) whose payout was checked against the exact fare breakdown from that
same trip, not a hardcoded number.

## Verifying it against a real backend

`npx tsx e2e-check.ts` runs a real, complete browser test — logs in as a
fleet owner through the real UI, adds a real, independently-created
driver account by phone, confirms they appear on the dashboard with the
correct live status, opens their detail screen, removes them, and
confirms server-side that removal cleared only the fleet link and left
their own KYC status untouched. Needs the backend and its database
running and seeded first.

## Known gaps

- No self-service "become a fleet owner" flow — any authenticated user
  can act as one simply by adding drivers to their own fleet (matching
  how this reference implementation treats every account type via the
  same phone+OTP login, not a separate registration step). A real
  product would likely gate this behind a business-verification step.
- No fleet-wide analytics beyond today's earnings (e.g. per-driver trend
  charts, weekly/monthly rollups) — the backend aggregate is intentionally
  simple; a real deployment's reporting needs would likely warrant more.
- No push notifications for fleet-relevant events (a driver going
  offline unexpectedly, a vehicle's scheduled reassignment completing).
