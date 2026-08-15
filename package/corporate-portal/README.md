# Logistics Super App — Corporate Portal

React + TypeScript + Vite reference frontend for a company's own self-service
account management (PRD 14A.1/14B.1) — a company's admin manages their team
and sees their credit/spend, deliberately separate from the internal
Admin Console (`../admin-app`) and Ops/Control Room (`../ops-console`):
this is a customer-facing surface for the company itself, not an internal
operations tool.

## Requirements

- Node.js 20+
- The backend running locally (see `../backend/README.md`)

## Setup

```bash
npm install
cp .env.example .env
npm run dev        # http://localhost:5181
```

## How a company gets onboarded

There's no self-service "create a company account" flow — the same way most
real B2B platforms onboard a new corporate customer through sales/ops, not
a public signup form. A corporate account and its first admin's invite are
created directly (see the SQL a real ops team would run, mirrored in this
app's `e2e-check.ts`). From there, everything is genuinely self-service:
the first admin logs in with their phone, accepts the invite by confirming
the email it was sent to, and can invite/remove their own team from there.

## What's implemented

- **Login / Verify** — same phone+OTP pattern as the other apps.
- **My Accounts** (`/accounts`) — for a user with no corporate membership
  yet, an "accept an invite" form. For a user with one or more, a picker.
  This screen exists because of a genuine gap found and fixed while
  building this app: every corporate API endpoint required already knowing
  the account ID, with no way for a user to discover it, and no endpoint
  ever linked an invited employee's account at all — see the top-level
  README's "Honesty about scope" section for the details.
- **Account Dashboard** (`/accounts/:accountId`) — the credit summary
  (limit, committed spend, in-flight reserved spend, and the live available
  balance — never a cached figure), **recent bookings across the whole
  org** (PRD 14A.1's "active bookings across the org" requirement — shows
  which teammate booked what, not just the requester's own trips), and the
  team roster. Account admins can invite and remove teammates, and edit
  each employee's per-user monthly booking cap — a real gap found while
  building this: the cap column existed and could be set at invite time,
  but nothing anywhere ever enforced it or let you change it afterward.
  It's now a real, working limit (see the backend README's Honesty-about-
  scope section), with the PRD's explicit guarantee that lowering someone's
  cap never retroactively invalidates spend they'd already reserved. A
  plain employee sees the same dashboard without the admin controls, and
  the backend enforces the same restriction independently of what the UI
  shows (verified directly in `npm run e2e`).
- **Invoices** (`/accounts/:accountId/invoices`) and **Invoice Detail**
  (`/accounts/:accountId/invoices/:invoiceId`) — real enterprise invoicing
  (P2 gap-analysis item). `corporate_accounts.committed_spend` had
  accumulated indefinitely since this app's very first migration despite
  its own comment calling it "this billing period"'s spend — nothing ever
  actually closed a period into a real invoice. Account admins pick a
  date range and generate one; line items are real completed trips for
  that exact period, re-derived live every time the invoice is viewed
  (never a stored, driftable copy). Invoice Detail is a genuinely
  printable document — reuses the same `window.print()` pattern proven on
  the customer app's own receipt screen, so "Save as PDF" works
  everywhere with zero new dependencies. A plain employee can view
  invoices (membership is enough); only an admin can generate a new one.

## Verifying it against a real backend

```bash
npm run e2e
```

Drives the actual UI through the realistic onboarding flow: an account and
a pending first-admin invite are created directly (as ops/sales would),
the admin logs in and accepts it through the real UI, books a real
corporate-billed trip via the API and confirms it shows up on their own
dashboard (not the empty state), invites a real teammate, the teammate
logs in on a separate browser session and accepts their own invite, and
then two independent security checks run — the non-admin teammate's UI
correctly hides the Invite button, AND a raw API call attempting to bypass
the UI and invite someone anyway is independently rejected by the backend.
The admin then edits the teammate's monthly cap through the real UI,
confirms it's genuinely persisted server-side, and confirms a
raw-API attempt to set an over-limit cap is rejected. Finally the admin
removes the teammate through the UI, and the script confirms the teammate
genuinely loses account access server-side, not just that a button
disappeared.

## Known gaps

- The bookings list shows the org-wide recent trips (PRD 14A.1's actual
  requirement) but not a spend-by-employee aggregate/chart view.
- No account settings (surge-exemption status, rate card overrides) —
  those are set directly on the backend/by ops, not self-service here.
- Invoice periods must be entered manually each time — no automatic
  monthly billing-cycle scheduling (e.g. "generate on the 1st of every
  month"), and no payment/reconciliation tracking beyond the `issued` /
  `paid` status column that exists on the backend but isn't editable from
  this UI yet.
