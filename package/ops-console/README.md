# Logistics Super App — Control Room (Ops/Control Room)

React + TypeScript + Vite reference frontend for the Ops/Control Room —
a real-time, incident-response surface, deliberately separate from the
Admin Console (`../admin-app`) since they serve different purposes: Admin
is configuration and governance (rate cards, roles, banners), Control Room
is live monitoring and intervention (SOS response, dispatch override). The
PRD (Section 10/10A.1) treats these as two distinct applications, and this
package follows that split rather than folding everything into one admin
tool.

## Requirements

- Node.js 20+
- The backend running locally (see `../backend/README.md`)

## Setup

```bash
npm install
cp .env.example .env
npm run dev        # http://localhost:5179
```

## Getting Control Room access

Sign in the same way as the other apps (phone + OTP). A brand-new account
has no operations access — every page shows "Access pending" until granted
the `control_room_operator` role (deliberately a narrower, separate role
from `ops_admin` — see `../backend/README.md` for the exact SQL — matching
the PRD's own note that Control Room is "broadly granted to on-duty
Control Room staff", a larger and differently-permissioned team than the
handful of full admins).

## What's implemented

- **SOS Queue** (`/sos`) — the defining Control Room feature (PRD 10A.1).
  Polls every 4s for new alerts. An SOS cannot be dismissed without an
  explicit **Acknowledge** step, and cannot be closed without a mandatory
  **resolution note of at least 20 characters** (the PRD's own wording:
  "forces a real note, not a one-word dismissal") — there is no code path,
  frontend or backend, that skips either. Also supports **Escalate to
  Safety Team Lead** — a genuinely separate action gated by its own
  permission (`ops.sos_escalate`), distinct from the broad
  `ops.sos_respond` every on-duty operator has, matching the PRD's explicit
  two-permission split. And a real backend job (not a UI-only countdown)
  auto-escalates any alert left unacknowledged past the PRD's 30-second
  threshold — visible on the queue as a distinct "auto-escalated" flag,
  never confused with a human's deliberate escalation.
- **Dispatch Monitor** (`/dispatch`) — look up any booking by ID, see its
  full dispatch-attempt timeline (every offer, who it went to, whether it
  was accepted/declined/expired), and **force-assign** a specific driver
  when the normal algorithm needs to be overridden (e.g. a VIP escalation).
  Force-assign bypasses only the *scoring* step — hard eligibility gates
  (KYC approval, training, no suspension, no expired documents) are
  enforced identically to normal dispatch and can never be overridden; the
  backend test suite has a dedicated test proving this.

## Verifying it against a real backend

```bash
npm run e2e
```

Drives the actual UI through the full flow: creates a real booking and
triggers a real SOS via the API (standing in for what the Customer/Driver
apps would do), signs in as an operator, confirms the Access-Pending gate,
grants the role, watches the real alert appear in the queue, acknowledges
and resolves it through the real UI. A second alert then proves the
escalation permission split is real, not decorative: confirms a plain
on-duty operator is rejected with 403 when attempting to escalate, grants
the same account the separate `safety_team_lead` role, and escalates
through the real UI. A third alert is artificially aged past the 30-second
threshold and left alone — the script then simply waits and confirms the
ACTUAL RUNNING BACKEND PROCESS'S OWN scheduled job auto-escalates it with
no manual action, rather than calling the sweep function directly. Finally
it looks up the first booking in Dispatch Monitor and force-assigns a
genuinely-eligible driver — every step re-verified against the database
afterward, not just checked against what the UI displays.

## Known gaps

- No live map / real-time location streaming — PRD 10A.1 calls for trip
  location to continuously stream to Control Room during an active SOS;
  this reference backend has no WebSocket/streaming layer (see the backend
  README's dev-only-substitutes list), so the SOS queue shows the trigger
  coordinates and the booking's stored pickup location, not a live-updating
  map.
- The PRD's "distinct louder/broader alert tier" on auto-escalation (e.g.
  an SMS to a safety-team distribution list) isn't implemented — there's
  no SMS provider in this reference backend (see the dev-only-substitutes
  list). The `auto_escalated` flag itself is real and enforced; the extra
  notification channel on top of it is not.
- No Emergency Contacts / Trip Share management screens (PRD 10A.1's
  supporting features) — those are Customer-app-side features with their
  own APIs, out of scope for this Control Room frontend.
