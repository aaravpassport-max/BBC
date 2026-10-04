# Portal/Admin Bundle — Recovered Structure

**Source file:** `dist/assets/s2nri-portal.v3.js` (504,891 bytes, minified, no
source map — `//# sourceMappingURL=` absent, confirmed by checking the file
tail). Serves BOTH `/s2nri-admin/` (staff) and `/portal/` (customer), switched
at runtime by `window.S2NRI_CFG.isAdmin`.

**What this document is:** a verified, evidence-based map of every route to
its component and actual on-screen purpose — extracted by locating each
component's function body and pulling real UI strings from inside it (button
labels, headings, error messages), not guessed from names alone. Every
mapping below has the extracted strings as evidence, right next to it.

**What this document is NOT:** a full reconstruction of readable source code
(renamed variables, rebuilt JSX, a buildable project). That remains real
future work — see "What's still not done" at the bottom. This document is
the navigational map that makes that future work tractable: instead of
searching blind through 28,000 lines, you go straight to the right ~500-line
block.

**Companion file:** `portal-beautified.js` — the same bundle, mechanically
reformatted with proper indentation (via `js-beautify`) so it's actually
readable line-by-line. Variable/function names are still the original
minified ones (`Ka`, `$a`, `_a`, etc.) — beautifying doesn't rename anything,
it only re-indents. Use the line numbers below to jump directly to each
component in that file.

---

## Route map — `/s2nri-admin/` (staff, `isAdmin === true`)

| Route | Component | Beautified line | What it actually is (evidence) |
|-------|-----------|:---:|---|
| `/` (index) | `Ka` | 18338 | **Admin Dashboard** — `"Active"`, `"Completed"`, `"Customers"`, `"Dashboard"`, `"Failed to load dashboard"` |
| `requests` | `$a` | 18932 | **Requests list** — `"Requests"`, `"Choose a status"`, `"Export failed"` |
| `requests/:id` | `U` | 21323 | **Request detail** — `"Service Request"`, `"Documents & Order History"`, `"Form Details & Export"`, `"Permanently deleted"` |
| `bookings` | `$a` | 18932 | Same component as `requests` — `"Bookings"` also appears as a string, so this list view is shared/reused for both tabs |
| `bookings/:id` | `U` | 21323 | Same component as `requests/:id` |
| `customers` | `ho` | 21552 | **Customer management** — `"Customers"`, `"Customer data erased"`, `"Data exported"` (GDPR-style erase/export) |
| `analytics` | `So` | 22068 | **Analytics** — `"Analytics"`, `"Business performance overview"`, `"Avg order value"`, `"Last 12 months"` / `"Last 30 days"` |
| `services` | `Ao` | 24071 | **Services & Form Fields management** — `"Add Service"`, `"Service created/updated/deleted"` |
| `settings` | `Ho` | 25866 | **Platform Settings** — `"Configure platform behaviour"`, `"SMTP test failed"`, `"Save changes"` |
| `health` | `Ko` | 26447 | **System Health / Diagnostics** — `"System Health"`, `"All systems operational"`, `"Re-run checks"` |
| `form-submissions` | `Zo` | 26811 | **Form Submissions** — `"Form Submissions"`, `"Form Data"`, `"Export"` |
| `vendors` | `ts` | 27321 | **Vendor management** — `"Add Vendor"`, `"Manage service delivery partners"` |
| `request-types` | `rs` | 27604 | **Request Type configuration** — `"Add Type"`, `"Edit Request Type"` |
| `*` (catch-all) | — | — | Redirects to `/` |

## Route map — `/portal/` (customer, `isAdmin === false`)

| Route | Component | Beautified line | What it actually is (evidence) |
|-------|-----------|:---:|---|
| `/login` (shared by both modes) | `fa` | 14149 | **Login** — `"Services2NRI"`, `"Please enter email and password"`, `"Login failed"` |
| `/` (index) | `_a` | 14753 | **Customer Dashboard** — `"Good "` (greeting), `"Action needed"`, `"New Request"`, status labels (`Active`/`Completed`/`Awaiting Payment`) |
| `bookings` | `Ta` | 15134 | **My Requests list** — `"My Requests"`, `"New Request"` |
| `bookings/:id` | `Na` | 16002 | **Booking detail** — `"Order Status"`, `"Document"`, `"File exceeds 10 MB"`, `"Login / Sign Up"` (guest-booking flow tie-in) |
| `new-request` | `Ra` | 17542 | **New Request flow** — `"Submit Another"`, `"View My Request"`, `"Failed to load services"` |
| `profile` | `Ha` | 17964 | **My Profile** — `"My Profile"`, `"My Data"`, `"Notifications"`, `"Notification preferences saved"`, `"Data exported"` |
| `*` (catch-all) | — | — | Redirects to `/` |

---

## What's still not done (honest, not glossed over)

- **No renamed variables, no rebuilt JSX.** `Ka`, `$a`, `_a`, etc. are still
  the literal minified names inside `portal-beautified.js` — this map tells
  you *where* to look and *what* you'll find, not a clean rewritten
  component you can drop into `src/`.
- **Sub-components, hooks, and shared utilities inside each page are not
  individually mapped yet.** Each route above is a several-hundred-line
  block; what's documented here is "this block is the Analytics page,"
  not "line 22150 is the chart-rendering function."
- **No component-level testing was possible.** There is no source map, no
  staging environment to compare against, and no way from this environment
  to click through the live `/s2nri-admin/` or `/portal/` to confirm these
  mappings render pixel-for-pixel as described — the evidence here is the
  actual UI text strings found inside each function body, which is strong
  but not the same as visual confirmation.
- **The live `dist/assets/s2nri-portal.v3.js` was NOT modified, replaced, or
  touched in any way to produce this document** — it remains byte-for-byte
  what it was before this work started. Zero risk to current functionality,
  by construction: nothing here can affect what's actually running.

**Realistic next step, if this is worth continuing:** pick ONE route (e.g.
`analytics`, since it's self-contained and lower-risk than something like
the booking detail flow) and do a full manual reconstruction of just that
one component — real variable names, real JSX, checked line-by-line against
`portal-beautified.js` — as a proof of the process, before committing to all
19 routes.
