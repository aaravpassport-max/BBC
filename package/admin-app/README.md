# Logistics Super App — Ops Console (Admin Dashboard)

React + TypeScript + Vite reference frontend for the Admin/Ops surface:
Rate Cards, Drivers (suspend/reinstate), Fraud Queue, Support ticket queue,
Analytics, Marketing/CMS banners, and RBAC role management.

## Requirements

- Node.js 20+
- The backend running locally (see `../backend/README.md`)

## Setup

```bash
npm install
cp .env.example .env
npm run dev        # http://localhost:5177
```

## Getting admin access

Signing in works the same way as the other two apps (phone + OTP), but a
brand-new account has no operations permissions — every page will show
"Access pending" until you're granted the `ops_admin` role (see
`../backend/README.md` for the exact SQL). This is intentional: the
gate is enforced server-side (every admin route requires the permission
independently), not just hidden in the UI.

## What's implemented

- **Rate Cards** (`/rate-cards`) — list, create a draft, publish (with
  optimistic-lock version handling — a stale publish attempt is rejected
  with the current version, never silently overwritten).
- **Drivers** (`/drivers`) — search by phone, suspend (with a required
  reason code, and a note if the reason is OTHER), reinstate.
- **Fraud Queue** (`/fraud-queue`) — review flags with their evidence,
  resolve as clear / escalate / hold / suspend with a required note.
- **Support** (`/support`) — the agent queue, a ticket's full message
  thread, replying, escalating, and closing (which requires a resolution
  category and note — there's no code path that skips it).
- **Analytics** (`/analytics`) — revenue metrics (gross revenue, platform
  fee revenue, take rate), the booking funnel (confirmed -> assigned ->
  completed, with real conversion percentages), a cancellation-reason
  breakdown, and per-driver utilization (trip-hours — see the note on the
  page itself about why this is a proxy metric, not true online-hours).
- **Marketing** (`/marketing`) — list and create promotional banners
  (headline, image, CTA deep link, schedule window) and publish them.
- **Roles & Access** (`/rbac`) — create roles, edit a role's permission
  set with a checkbox grid, look up any account by phone (any account
  type, not just drivers), and grant/revoke roles. The last-role-manager
  guard-rail (you can never strip `rbac.role_manage` from every manager at
  once) is enforced server-side, not just in this UI.

## Verifying it against a real backend

```bash
npm run e2e
```

Drives the actual UI through the entire flow: sign in, confirms the
Access-Pending gate is genuinely enforced before any role is granted,
grants `ops_admin` directly in the database (mirroring how a real
deployment bootstraps its first admin), then creates and publishes a rate
card, searches for and suspends a driver, and resolves a fraud flag — every
action independently re-verified with a direct database query afterward,
not just by checking what the UI displays. Screenshots saved to
`e2e-screenshots/`.

## Known gaps

- Rate Cards page targets the one seeded reference city/category rather
  than a full city/category picker — see the note in `RateCardsPage.tsx`.
- No Corporate account screens in THIS app, by design — Corporate is a
  self-service surface for a company's own admin (not ops-console-gated),
  so it has its own frontend instead: `../corporate-portal`.
- Marketing's audience targeting (target_segment) isn't exposed in the
  create form yet — every banner created here targets all users.
- No date-range filtering on Analytics — every view covers all-time data.
- No role/permission self-service — granting the first admin requires
  direct database access, matching how most real systems bootstrap their
  very first admin account.
