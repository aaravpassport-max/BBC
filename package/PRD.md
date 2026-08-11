# Logistics Super App — Master Product Requirements Document (PRD)
**Version 1.0 — Production Build Specification**
**Scope:** Full-stack on-demand goods transport platform (Customer App, Driver App, Admin/Ops, Support, Finance, Corporate/B2B, Marketing, Analytics) — feature parity + beyond Porter-class platforms.

---

## HOW TO USE THIS DOCUMENT

This PRD is organized in three tiers of depth so it stays usable instead of collapsing under its own scope:

- **Tier 1 — Deep spec** (full screen inventory, state machines, field-level validation, API contracts, DB schema, edge cases): Customer App core booking flow, Driver App core job flow, Dispatch Engine, Pricing/Surge Engine, Wallet & Payments, KYC, Live Tracking/GPS. These are the modules where an ambiguous spec produces the most expensive rework.
- **Tier 2 — Functional spec** (business rules, screen list, key states, API list, DB tables, edge cases, acceptance criteria — without exhaustive field-by-field UI copy for every micro-state): Admin Dashboard, Ops/Control Room, Support Portal, Finance/Settlement, Fleet Management, Corporate Portal, Marketing/Coupons, Notifications, Ratings, Referral, Loyalty, Analytics, Fraud, RBAC.
- **Tier 3 — Framework** (cross-cutting engineering requirements applied uniformly): API standards, event architecture, background jobs, security, performance/scale, QA methodology, design system, deployment, monitoring, DR, roadmap.

**Section 20, "Universal Screen Spec Template,"** is the exact template used for every Tier‑1 screen. Your developer should apply it to every remaining screen in the Tier‑2 modules' screen inventories before building them — that is how you get pixel-level rigor everywhere without a 500-page static document that goes stale on day one.

Every module ends with an **Acceptance Criteria** checklist and a **QA Test Matrix** stub. Nothing ships against this PRD without both signed off.

---

## 0. PRODUCT VISION & PRINCIPLES

**Vision:** A single logistics super-app connecting customers who need goods moved with a verified driver network, spanning instant on-demand delivery, scheduled bookings, and corporate contract logistics — operating at Porter/Porter+ parity on day one, with wallet, loyalty, and B2B monetization layered in as differentiators.

**Non-negotiable principles:**
1. Every state-changing action has an idempotent server-side handler — no client-only success.
2. Every price shown to a user is server-computed and re-validated at booking confirmation (never trust a client-cached quote past its TTL).
3. Every driver-customer interaction is number-masked (no raw phone numbers exchanged).
4. Every financial transaction is double-entry logged before it is user-visible as "complete."
5. Every screen has a defined empty, loading, error, and permission-denied state — "blank" is never an acceptable state.
6. Every mutation is auditable: who, what, when, from-state, to-state.

---

## 1. SYSTEM ARCHITECTURE OVERVIEW

**Applications:** Customer App (iOS/Android, React Native or Flutter), Driver App (iOS/Android), Admin Dashboard (web), Ops/Control Room (web, real-time), Support Portal (web), Corporate/B2B Portal (web), Marketing CMS (web), Analytics Dashboards (web, embedded BI), Public API Gateway.

**Backend shape:** API Gateway → domain services (Booking, Dispatch, Pricing, Wallet, KYC, Notification, Tracking, Fraud, Analytics-ingest) → event bus (Kafka/SNS-SQS class) → data stores (PostgreSQL primary OLTP, Redis for hot state/geo, TimescaleDB or ClickHouse for tracking/analytics, S3-class object store for documents/media, Elasticsearch for search/support).

**Why service-oriented, not monolith-only:** Dispatch and Pricing must scale and fail independently of, e.g., the Marketing CMS. A dispatch outage must never be caused by a coupon-engine bug. Services communicate over the internal API contract in Section 21 and the event schema in Section 22.

---

## 2. CUSTOMER MOBILE APP — TIER 1 DEEP SPEC

### 2.1 Full Screen Inventory

| # | Screen | Module |
|---|--------|--------|
| 1 | Splash | Onboarding |
| 2 | Language Select | Onboarding |
| 3 | Phone Entry | Auth |
| 4 | OTP Verification | Auth |
| 5 | Name/Email Capture (first-time) | Auth |
| 6 | Location Permission Primer | Onboarding |
| 7 | Home / Vehicle Category Selector | Core |
| 8 | Address Picker (Pickup) | Core |
| 9 | Address Picker (Drop, incl. multi-stop) | Core |
| 10 | Map Pin Adjust ("Confirm exact location") | Core |
| 11 | Saved Addresses (Home/Work/Custom) | Core |
| 12 | Item Details (goods type, weight, helper needed) | Core |
| 13 | Vehicle/Fare Comparison Card List | Core |
| 14 | Fare Breakup Detail Sheet | Core |
| 15 | Schedule vs Instant Toggle | Core |
| 16 | Date/Time Picker (scheduled) | Core |
| 17 | Coupon Entry / Apply | Core |
| 18 | Payment Method Selector | Payments |
| 19 | Add/Manage Payment Method | Payments |
| 20 | Booking Confirmation (searching driver) | Core |
| 21 | Driver Search — No Driver Found (retry/expand radius) | Core |
| 22 | Driver Assigned / Trip Accepted | Core |
| 23 | Live Tracking Map (driver en route to pickup) | Tracking |
| 24 | Chat Screen (masked) | Comms |
| 25 | Call Screen (masked, in-app or PSTN proxy) | Comms |
| 26 | OTP-at-Pickup Verification | Core |
| 27 | Trip In-Progress Tracking | Tracking |
| 28 | Multi-stop Sequence View | Core |
| 29 | Trip Completion / OTP-at-Drop | Core |
| 30 | Fare Summary / Final Invoice | Payments |
| 31 | Rate Driver | Ratings |
| 32 | Tip Driver | Payments |
| 33 | Trip History List | Core |
| 34 | Trip Detail (past trip) | Core |
| 35 | Cancel Trip (pre-pickup) | Core |
| 36 | Cancellation Reason Picker | Core |
| 37 | Cancellation Fee Notice | Core |
| 38 | Profile | Account |
| 39 | Edit Profile | Account |
| 40 | Wallet Home | Wallet |
| 41 | Wallet Add Money | Wallet |
| 42 | Wallet Transaction History | Wallet |
| 43 | GST Invoice Details Entry | Account |
| 44 | Invoice List / Download | Account |
| 45 | Referral Home | Referral |
| 46 | Referral Share Sheet | Referral |
| 47 | Notifications Inbox | Notifications |
| 48 | Notification Preferences | Notifications |
| 49 | Support Home (FAQ + ticket) | Support |
| 50 | Support Ticket Create | Support |
| 51 | Support Ticket Thread | Support |
| 52 | Live Chat with Support Agent | Support |
| 53 | SOS / Safety Center | Safety |
| 54 | Emergency Contacts Setup | Safety |
| 55 | Trip Share (live location to contact) | Safety |
| 56 | Favourites (drivers/vehicle presets) | Core |
| 57 | Corporate Account Linking | B2B |
| 58 | Loyalty/Subscription Home | Loyalty |
| 59 | Subscription Plan Purchase | Loyalty |
| 60 | Settings | Account |
| 61 | Delete Account Flow | Account |
| 62 | App Update Force/Soft Prompt | System |
| 63 | Network Offline State | System |
| 64 | Maintenance Mode Screen | System |

### 2.2 Deep Spec — Critical Path Screens

#### 2.2.1 Phone Entry (Screen 3)

**Business requirement:** Single point of identity. Phone number is the unique account key (E.164, India +91 default, extensible to other country codes).

**Fields:**
| Field | Type | Validation | Error copy |
|---|---|---|---|
| Country code | Dropdown, default +91 | Must be in supported-country list | "We don't currently operate in this country" |
| Phone number | Numeric input | 10 digits (IN), regex per country; no leading 0; reject sequences flagged by fraud denylist | "Enter a valid 10-digit mobile number" |

**States:** default → typing (live digit-count indicator) → valid (CTA enabled) → submitting (spinner on CTA, CTA disabled, no double-submit) → OTP-sent (navigate) → error (rate-limited / blocked number → inline banner, CTA stays enabled to retry after cooldown).

**Edge cases:**
- Same number re-registering after account deletion within cooldown window → show "This number was recently removed. Try again in X days" (see 2.6 Delete Account).
- VOIP/landline numbers → reject via carrier-lookup API if available; otherwise allow, rely on OTP delivery failure to self-select.
- Rate limiting: max 5 OTP requests per number per hour, max 20 per device per day → 429 with human copy, not raw error code.
- Number already exists on another account with different device → proceed normally (OTP-based login, not device-lock).

**API:** `POST /v1/auth/otp/request { phone, country_code, device_id, app_version }` → `202 { otp_id, expires_in_seconds, resend_after_seconds }`.

**Acceptance criteria:** OTP delivered within 15s p95; duplicate rapid taps produce exactly one OTP send (client debounce + server idempotency key = device_id+phone+60s window); blocked/fraud numbers get generic decline copy (never reveal fraud-list membership).

#### 2.2.2 OTP Verification (Screen 4)

**Fields:** 6-digit OTP, auto-read via SMS Retriever/Autofill where OS supports it.

**States:** awaiting input → auto-filled (if SMS retriever fires) → verifying → success (navigate) → wrong-otp (shake animation, error text, attempts remaining counter) → expired (resend CTA active, input disabled) → locked (5 wrong attempts → 15 min lockout, explicit countdown shown).

**Validation:** exactly 6 digits, numeric only; server is source of truth for correctness (never validate client-side against a locally cached value).

**Edge cases:** resend before expiry disabled with visible countdown; resend after expiry issues a **new** otp_id and invalidates the old one server-side; app backgrounded mid-verification → on foreground, re-check otp_id validity before allowing submit; SIM swap / OTP arriving to wrong device → no special handling needed beyond standard expiry, but log for fraud signal.

**API:** `POST /v1/auth/otp/verify { otp_id, code, device_id }` → `200 { access_token, refresh_token, is_new_user, user_id }` or `401 { attempts_remaining }` or `423 { locked_until }`.

**Acceptance criteria:** token issuance is atomic with user creation (new users) — no state where OTP verified but no user record exists; lockout is enforced server-side even if client is modified/reverse-engineered.

#### 2.2.3 Home / Vehicle Category Selector (Screen 7)

**Business requirement:** This is the highest-traffic screen; it must load usable content in <2s on 4G and degrade gracefully on 3G/offline.

**Layout blocks:** current-location bar (tap → address picker), vehicle category horizontal cards (2-wheeler, mini-truck, pickup, large-truck — each with base fare "starting at" indicator), promo banner carousel (marketing-driven, CMS-controlled), recent destinations shortcut list, wallet balance chip, notification bell with unread badge.

**States:** loading (skeleton shimmer, not spinner-only), loaded, location-permission-denied (banner: "Enable location for accurate pricing" + manual address entry still works), no-service-in-area (banner + waitlist CTA), server-error (retry card, cached last-known content shown behind a "showing saved data" banner if available).

**Edge cases:** GPS drift/inaccuracy >100m → show accuracy warning, still allow manual pin adjustment; app opened via deep link with a pre-filled destination (from web/ads) → skip to address picker with drop pre-populated; returning user with an active trip in progress → home screen redirects to Live Tracking Map instead, no way to start a second trip while one is active (unless multi-cart is explicitly supported — out of scope v1).

**Acceptance criteria:** cold start to interactive home ≤2.5s p95 on mid-tier Android; category list reflects real-time service availability per geofence (a truck category hidden entirely if zero eligible drivers online in city, not shown-then-erroring at booking).

#### 2.2.4 Address Picker + Map Pin Adjust (Screens 8–10)

**Fields:** search input (autocomplete via Places API), "Use current location" shortcut, saved address chips, map with draggable pin, "Add landmark/instructions" free-text (optional, 100 char max), contact name + phone for this address (optional per-address override, used by driver).

**Validation:** address must resolve to a lat/lng inside a serviceable geofence; if search result is outside geofence → "We don't deliver to this area yet" with waitlist capture; pin drag beyond a serviceable boundary → soft warning, does not hard-block (geofence enforced at quote time server-side, not just client map bounds).

**Multi-stop:** up to N stops (config-driven, default max 5 including final drop); each stop reorderable via drag; per-stop instructions field; removing a stop recalculates fare live.

**Edge cases:** pickup and drop identical or <50m apart → block with "Pickup and drop are too close" (config threshold); address autocomplete API failure → fall back to manual pin-drop only, don't block booking; saved address deleted while mid-booking on another device → resolve to raw lat/lng snapshot, not a dangling reference.

**API:** `GET /v1/geo/autocomplete?q=...&session_token=...`, `POST /v1/geo/reverse?lat&lng`, `GET /v1/geo/serviceability?lat&lng` → `{ serviceable: bool, city_id, zone_id }`.

#### 2.2.5 Fare Comparison + Fare Breakup (Screens 13–14)

**Business requirement:** Every fare shown must be a live server quote, TTL'd (default 90s), and re-quoted transparently on expiry — never let a user book a stale price.

**Fare card fields per vehicle category:** vehicle icon + name, capacity descriptor ("up to 500kg"), ETA to pickup, total fare (bold), strike-through original fare if a coupon/discount is pre-applied, "recommended" badge logic (based on item weight vs capacity fit).

**Fare breakup sheet line items:** base fare, distance charge, time charge, waiting charge (if applicable to category), toll/parking pass-through, night surcharge (time-window driven), surge multiplier (shown as "+X% high demand" with a one-line "why" explainer, never a bare multiplier with no context), platform fee, GST, coupon discount, final payable.

**States:** quoting (skeleton cards), quoted (interactive), quote-expired (banner: "Prices updated" + auto-refresh, do not silently re-price without telling the user), category-unavailable (grey out card + "No drivers nearby" — never let it be selectable), server-error (retry).

**Edge cases:** surge active on one category but not another shown side-by-side — must be visually distinct per-card, not a global banner that misattributes; coupon becomes invalid between selection and confirmation (expired, min-order not met after item edit) → block confirmation with specific reason, don't silently drop it; user backgrounds app during quote TTL and returns after expiry → force re-quote before allowing the Confirm tap to submit.

**API:** `POST /v1/pricing/quote { pickup, drops[], vehicle_category, scheduled_at?, item_details }` → `200 { quotes: [{category, fare_breakup{}, quote_id, expires_at, surge_multiplier}] }`.

**Acceptance criteria:** quote_id is single-use and bound server-side to the exact pickup/drop/category/coupon combination at confirmation — booking with a mismatched quote_id is rejected with a re-quote prompt, never silently accepted at a different price.

#### 2.2.6 Booking Confirmation / Driver Search (Screens 20–22)

**Flow:** Confirm tap → `POST /v1/bookings` (idempotency key = client-generated request UUID) → server validates quote, locks the fare, enters `SEARCHING` state → Dispatch Engine (Section 9) attempts assignment → on success, booking transitions to `DRIVER_ASSIGNED` and pushes to client via WebSocket/FCM; on timeout (config, default 90s), client shown "No drivers found nearby" with options: expand radius, switch vehicle category, schedule for later, cancel.

**Edge cases:** user backgrounds/kills app during search → search continues server-side; push notification delivers assignment regardless; user cancels during search → in-flight dispatch offer to a driver must be revoked atomically (driver app immediately shows "offer withdrawn," not left dangling); duplicate booking from double-tap → idempotency key collision returns the existing booking, never creates two.

**Acceptance criteria:** zero possibility of two active bookings created from one Confirm tap under any network retry condition (verified via idempotency key uniqueness constraint at the DB layer, not just app-level debounce).

#### 2.2.7 OTP-at-Pickup / OTP-at-Drop (Screens 26, 29)

**Business requirement:** Prevents wrong-party pickup/drop and driver-reported-completion fraud.

**Flow:** Driver taps "Arrived" → customer app surfaces a 4-digit OTP (never sent via SMS at this stage — it's already on-screen since booking confirmation, driver asks customer to read it aloud or shows in driver app if customer shares) → driver enters it in driver app → server validates → trip transitions state.

**Edge cases:** customer unreachable/not present at pickup → driver app has a "Customer not available" flow with a mandatory wait-timer (config, e.g., 5 min) before cancellation-with-fee is enabled, photo-optional; OTP mismatch 3x → soft lock, escalation to support chat surfaced automatically in both apps; unattended drop (no OTP, goods left at address per customer instruction) → requires photo-proof capture instead of OTP, explicitly toggled at booking time as a delivery preference.

**Acceptance criteria:** trip cannot transition to IN_PROGRESS or COMPLETED without either a valid OTP match or an explicitly logged override by a support agent with a reason code (never a silent driver-only override).

### 2.3 Validation & Error Handling — Global Rules for Customer App

- All server error responses map to a finite, pre-defined error-code → human-copy table (never render raw `error.message` from the API in production UI).
- Network loss mid-flow: every screen with a pending mutation shows an explicit "reconnecting" state; on reconnect, the client re-checks server state before resuming (never assumes its last local action succeeded).
- Every irreversible action (cancel, delete account, remove payment method) requires a confirm step naming the specific item affected.

### 2.4 Customer App Acceptance Criteria (module-level)

- [ ] Every screen in 2.1 has documented empty/loading/error/permission states before dev sign-off.
- [ ] No fare is ever displayed without a `quote_id` and `expires_at`.
- [ ] No booking can be created twice from one user action (idempotency verified under load test with simulated retries).
- [ ] OTP flows (auth, pickup, drop) are server-authoritative; client-side OTP validation logic does not exist anywhere in the codebase.
- [ ] All masked-comms screens never expose raw phone numbers in logs, analytics events, or client storage.
- [ ] Full regression suite (Section 26) passes on iOS + Android reference devices before each release.

### 2.5 Customer App QA Test Matrix (excerpt — replicate pattern per screen)

| Scenario | Expected Result |
|---|---|
| Book with expired quote_id | 409, re-quote prompt, no charge |
| Double-tap Confirm on slow network | One booking created |
| Kill app during driver search | Push notification on assignment; app resumes to correct state |
| Enter wrong pickup OTP 3x | Soft lock + support escalation surfaced |
| Coupon expires between apply and confirm | Confirmation blocked with specific reason |
| GPS permission denied | Manual address entry still functions |

---

## 3. DRIVER MOBILE APP — TIER 1 DEEP SPEC

### 3.1 Full Screen Inventory

Splash → Language → Phone/OTP Auth (shared pattern with Customer App, Section 2.2.1–2.2.2) → **KYC Onboarding Wizard** (multi-step, see 3.2) → Application Under Review → Vehicle Details Entry → Bank/Payout Details → Training Module (video/quiz) → Approval Pending → Approved/Activated → **Home/Online-Offline Toggle** → Job Offer Card (modal, timed) → Navigation-to-Pickup → Arrived-at-Pickup / OTP Entry → Loading Confirmation (photo optional) → Navigation-to-Drop (multi-stop sequence) → Arrived-at-Drop / OTP or Photo Proof → Trip Summary/Earnings-for-trip → Rate Customer → Earnings Home (daily/weekly) → Earnings Detail/Payout History → Incentives/Missions → Heatmap (demand zones) → Wallet → Withdraw Funds → Document Center (expiry tracking) → Document Re-upload Flow → Penalties/Violations List → Penalty Dispute Flow → SOS → Profile → Vehicle Management (multi-vehicle drivers) → Support → Ratings Received → Offline Reason Capture → App Update Prompt.

### 3.2 KYC Onboarding Wizard — Deep Spec

**Steps (linear, resumable — driver can exit and return to the exact step):**

1. **Personal details:** full name (as per ID), DOB (18+ validation, hard block under legal minimum), gender (optional), address.
2. **Identity document:** type selector (Aadhaar/Passport/Voter ID/Driving License per market), front photo capture, back photo capture, OCR-assisted auto-fill with manual override, live-ness selfie match against ID photo.
3. **Driving license:** number, expiry date (must be future-dated; reject expired at submission, not just at review), category match against vehicle type selected, photo capture.
4. **Vehicle documents:** RC (registration certificate), insurance (with expiry), permit/fitness certificate (category-dependent, e.g., commercial permit for trucks), PUC/emissions certificate. Each: photo capture + expiry date field + OCR-assist.
5. **Bank details:** account number, IFSC/routing, account holder name — validated via penny-drop verification API before acceptance, not just format regex.
6. **Vehicle photos:** front, back, side, number plate close-up.
7. **Background check consent:** explicit checkbox, links to policy text, required before submission.
8. **Review & submit.**

**Validation per field:** every document has (a) format validation, (b) expiry-date-in-future validation, (c) OCR-cross-check against manually entered fields with a mismatch warning (non-blocking but flagged for reviewer), (d) image quality gate (blur/glare detection reject with re-capture prompt before upload even completes, saving bandwidth and reviewer time).

**States:** each step: incomplete → in-progress → complete-pending-review → approved → rejected (with a specific, structured rejection reason from a fixed taxonomy, e.g., `DOC_BLURRY`, `DOC_EXPIRED`, `NAME_MISMATCH`, `FACE_MISMATCH` — never free-text-only rejections that a driver can't act on).

**Edge cases:** document expires *during* the review queue wait → auto re-flag before approval; driver resubmits a rejected document → previous version retained in audit trail, not overwritten; driver has an existing rejected application and tries to re-register with the same ID number on a new phone number → flagged for fraud review, not silently allowed to bypass a rejection.

**API:** `POST /v1/driver/kyc/{step}` (per-step upload with presigned S3 URL pattern), `GET /v1/driver/kyc/status` → `{ steps: [{step, status, rejection_reason?}], overall_status }`.

**Acceptance criteria:** no driver can go online/accept jobs while overall_status ≠ APPROVED; a document expiring while the driver is already active auto-suspends job-eligibility (not the whole account) within one polling cycle (max 15 min) of expiry, with an in-app + push + SMS alert sent 30/15/7/1 days before expiry.

### 3.3 Job Offer Card — Deep Spec

**Trigger:** Dispatch Engine assigns a candidate booking to this driver.

**Content:** pickup distance/ETA, drop distance (city-level, not exact address, until accepted — privacy + prevents cherry-picking by exact destination in some models; **configurable by ops policy**), estimated earnings for this job, item type/weight, countdown timer (config, default 15s) with a visual ring depleting.

**States:** offered (ringing/vibrating alert + countdown) → accepted (navigate to pickup) → declined (explicit decline, logged) → expired (timeout, auto-declined, logged distinctly from explicit decline for driver-behavior scoring) → offer-revoked (customer cancelled mid-offer — card dismisses with a distinct "no longer available" toast, not a silent disappearance that confuses the driver).

**Edge cases:** driver has poor connectivity and the accept tap doesn't reach the server before another driver accepts the same job (should be structurally impossible if Dispatch offers to one driver at a time — see Section 9 — but if a batch/broadcast model is used instead, the loser must get an immediate, clear "Job taken" state, never a spinner that hangs); driver accepts while mid-navigation on a previous job (should be blocked entirely — one active job at a time in v1, multi-job batching is a v2 roadmap item, Section 30).

**Acceptance criteria:** accept-confirmation round-trip ≤2s p95; declined/expired offers never re-offered to the same driver for the same booking within a cooldown window (prevents offer-spam loops).

### 3.4 Earnings & Wallet — Deep Spec

**Earnings Home:** today's trips count, today's gross earnings, incentive progress bar (if an active incentive scheme applies, e.g., "Complete 3 more trips before 6 PM for ₹150 bonus"), breakdown toggle (trip fare vs tips vs incentives vs penalties/deductions).

**Payout logic:** T+1 or instant-payout (fee-bearing) option, config-driven per market; every payout is a ledger entry with a status machine `PENDING → PROCESSING → PAID / FAILED`; failed payouts must retry automatically with exponential backoff and alert both driver and finance ops after N failures (see Finance module, Section 6).

**Edge cases:** trip fare disputed/adjusted post-completion (e.g., customer support issues a partial refund) → driver earnings statement reflects the adjustment as a distinct, explained line item on a *future* statement, never a silent retroactive balance change without an explanation entry.

**Acceptance criteria:** sum of all driver ledger entries for a period reconciles exactly to the Finance module's settlement report for that driver (Section 6) — this is a hard financial-integrity requirement, not a UI nicety.

### 3.5 Driver App Acceptance Criteria (module-level)

- [ ] No driver can be assigned a job while KYC status ≠ APPROVED or while any required document is expired.
- [ ] Job offer, accept, decline, and expiry are all server-timestamped and immutable audit events.
- [ ] Earnings shown in-app always match the Finance ledger (reconciliation job, Section 6.4).
- [ ] SOS screen functions with zero network dependency degradation beyond a hard offline state (cached emergency numbers, local dial fallback).

---

## 4. DISPATCH ENGINE — TIER 1 DEEP SPEC

**Purpose:** Match a confirmed booking to the best available driver within SLA.

**Inputs:** booking (pickup geo, vehicle category, scheduled vs instant, item weight), live driver pool (location, online status, current job state, acceptance-rate score, rating, vehicle category, document validity).

**Algorithm (v1 — nearest-eligible, weighted):**
1. Filter drivers: online, idle (or soon-to-be-idle if batching enabled), correct vehicle category, KYC valid, not currently suspended, within max search radius (config per city, expanding in rings if no match: e.g., 2km → 4km → 6km).
2. Score eligible drivers: primarily ETA-to-pickup, secondarily a blended factor of acceptance rate and rating (prevents always flooding the single closest driver and burning them out; also deprioritizes chronic decliners without fully excluding them).
3. Offer to top-scored driver only (sequential offer model for v1 — see 3.3 edge case note); on decline/timeout, offer to next; repeat until pool exhausted or timeout.
4. On exhaustion: booking → `NO_DRIVERS_FOUND`, customer notified with retry options (2.2.6).

**Reassignment (driver cancels post-accept, or goes offline mid-trip-to-pickup):** immediately re-enter dispatch with the same booking, customer notified transparently ("Finding you a new driver," not a silent re-search that looks like a hang), original driver's cancellation logged against their reliability score.

**SLA monitoring:** every booking tracked against a max-time-to-assignment SLA (config per city/category); breaches surfaced live to Ops/Control Room (Section 7) for manual intervention capability (force-assign, escalate, contact customer).

**Edge cases:** two bookings simultaneously eligible for the same single available driver → strict server-side locking (DB row lock / distributed lock on driver availability) — a driver can never receive two simultaneous offers; driver goes offline exactly as an offer is being sent → offer must fail closed (not sent) rather than sent-to-nowhere; city/geofence boundary bookings where the nearest driver is technically in an adjacent operational zone → configurable per-market whether cross-zone dispatch is allowed.

**API (internal):** `dispatch.assign(booking_id)` event-triggered, not directly client-callable; emits `DriverOffered`, `DriverAccepted`, `DriverDeclined`, `DispatchExhausted` events (Section 22).

**Acceptance criteria:** load-tested to city-scale concurrent booking volume with zero double-offers under the DB lock; reassignment on driver drop-out completes end-to-end (new offer sent) within 5s p95 of the drop-out event.

---

## 5. PRICING & SURGE ENGINE — TIER 1 DEEP SPEC

**Fare formula (per vehicle category, per city, config-driven coefficients):**

```
base_fare
+ (distance_km × per_km_rate)
+ (duration_min × per_min_rate, if time-based component enabled for category)
+ waiting_charge (chargeable_waiting_min × per_min_wait_rate, after free-wait grace period)
+ toll_pass_through (actual, capped, or flat per route-config)
+ night_surcharge (flat or % during configured time window)
× surge_multiplier
+ platform_fee
+ applicable_taxes (GST per jurisdiction rules)
− coupon_discount (capped per coupon rules)
= final_fare
```

**Minimum fare floor:** applied after all additive components, before surge, ensuring surge never produces a fare below the category minimum in reverse (i.e., discount can't push below floor unless coupon explicitly allows it).

**Surge calculation:** demand/supply ratio computed per geofenced zone on a rolling short window (e.g., 3–5 min); multiplier tiers are discrete steps (e.g., 1.0x, 1.2x, 1.5x, 2.0x — never an unbounded continuous multiplier) with a hard cap (config, e.g., 3.0x max) and a cap on how fast multiplier can change zone-to-zone-adjacent-time (prevents jarring price whiplash between quote and confirm).

**Subscription/B2B pricing override:** corporate accounts and subscription-plan customers can have contract rate cards that override the standard formula entirely per SLA (Section 11), including surge-exemption flags.

**Edge cases:** quote requested exactly at a surge-tier boundary → the quote_id locks the multiplier for its TTL regardless of subsequent zone changes; multi-stop trips → distance is computed on optimized route order (Section 9's routing) not raw straight-line sum of unordered stops; scheduled (future) bookings → priced at confirmation using a rate-card snapshot valid at scheduled time if surge is time-predictable, otherwise flagged as "final fare confirmed at pickup time" — this must be explicit to the customer, never silently re-priced without disclosure.

**Acceptance criteria:** every fare component is independently auditable via a stored fare_breakdown JSON attached to the booking record (Section 23 schema) — support and finance must be able to explain any historical fare down to the coefficient without re-deriving it from formulas.

---

## 6. WALLET & PAYMENTS — TIER 1 DEEP SPEC

**Wallet model:** every user (customer, driver, corporate account) has exactly one wallet per currency. All wallet mutations are double-entry ledger transactions (`debit` + `credit` rows referencing a common `transaction_id`), never a single mutable balance field updated in place.

**Customer-side flows:** add money (via payment gateway — card/UPI/netbanking), pay-for-trip-from-wallet, refunds-to-wallet (support-issued), coupon-credit-to-wallet (promotional, tagged as non-withdrawable and expiry-bound distinctly from real money).

**Driver-side flows:** trip-earning-credit, incentive-credit, penalty-debit, payout-withdrawal-debit (to linked bank account).

**Payment gateway integration requirements:** PCI-DSS-aware (platform never stores raw card PAN — tokenization via gateway, e.g., Razorpay/Stripe-class provider), webhook-driven confirmation (never trust a client-reported "payment succeeded" without server-side webhook or verify-call confirmation), idempotent webhook handling (gateway retries must not double-credit).

**Refund logic:** automated refund triggers (e.g., driver-cancelled-after-pickup-fee-charged) vs support-manual refunds; every refund traceable to an originating transaction; partial refunds supported with itemized reason.

**Edge cases:** payment gateway timeout where charge may have succeeded upstream but confirmation didn't reach the app → reconciliation job (Section 6.4-equivalent, background jobs Section 25) polls gateway status for any `PENDING` transaction older than a threshold and resolves it, rather than leaving a permanently ambiguous state; wallet balance goes negative (e.g., disputed reversal) → must be structurally impossible for customer wallets (block spend at zero) but *can* occur for driver wallets pending payout offset — this must be a deliberate, visible "negative balance / recovery" state, not a silent debt.

**Acceptance criteria:** ledger sum across all accounts nets to zero at all times except for the platform's own fee/revenue account (standard double-entry invariant) — a scheduled integrity job (Section 25) verifies this daily and pages finance ops on any mismatch.

---

## 7. KYC & DOCUMENT VERIFICATION — TIER 1 (shared spec, see 3.2 for full detail)

Applies identically to Driver onboarding (3.2) and additionally to: Corporate account signatory verification (Section 11), high-value refund approval identity checks (fraud module, Section 17), and vehicle-owner-vs-driver mismatch checks for fleet-operator accounts (Section 8).

**Reviewer (Admin) side:** queue of pending KYC submissions, side-by-side document + OCR-extracted-field view, one-click approve, structured-reason reject (taxonomy from 3.2), SLA timer per submission (config, e.g., 24h), auto-escalation of aged-out submissions to a supervisor queue.

**Acceptance criteria:** every approve/reject action is attributed to a specific admin user_id with timestamp; rejection reasons are always from the fixed taxonomy (free text is a supplementary note, never the primary machine-readable reason).

---

## 8. LIVE TRACKING / GPS & MAPS/GEOFENCING — TIER 1 DEEP SPEC

**Location ingestion:** driver app pushes location pings at a config interval (e.g., every 4s while on an active job, every 15–30s while idle-online to conserve battery/data), batched-and-flushed if connectivity drops, never silently dropped (queued locally, replayed on reconnect, timestamped at capture not at send).

**Live tracking map (customer + admin views):** driver marker interpolated smoothly between pings (client-side dead-reckoning/animation, not a jump-cut per ping), ETA recalculated on each ping against live routing (not a static straight-line estimate), route-so-far polyline, geofence zone overlays where relevant (service area boundary, surge zone boundary in admin view).

**Geofencing engine:** polygon-based zone definitions (city, sub-zone, surge-zone, no-go-zone) stored server-side; used by Serviceability check (2.2.4), Surge calculation (Section 5), Dispatch cross-zone rules (Section 4), and Fraud (Section 17 — e.g., flagging GPS-spoofed trips that never actually traverse the claimed route).

**Edge cases:** GPS signal loss in tunnels/underground parking → last-known-position held with a "signal lost" indicator rather than freezing silently or teleporting on reacquisition; driver's device clock skew → server timestamps pings on receipt, not solely trusting device-reported timestamps, to prevent replay/spoofing; extremely high-frequency ping storms from a misbehaving client → server-side rate limiting per driver to protect the tracking pipeline.

**Acceptance criteria:** tracking data pipeline sustains city-scale concurrent active-trip volume with p95 ping-to-customer-map-update latency under 3s; geofence checks are authoritative server-side even if a client's cached serviceability flag is stale.

---

## 9. ADMIN DASHBOARD — TIER 2

**Business requirement:** Single console for platform operators to configure, monitor, and intervene across every module below, gated entirely by RBAC (Section 18).

**Modules within Admin:**
- **City/Zone Management:** create/edit service cities, zones, geofence polygons (map-drawing UI), per-zone operating hours, per-zone vehicle-category availability toggles.
- **Pricing Configuration:** per-city/per-category rate cards (base, per-km, per-min, waiting, night, minimum fare), surge tier thresholds and caps, effective-date scheduling (future rate-card changes, not just immediate overwrite).
- **Vehicle Category Management:** create/edit categories, capacity descriptors, icon/media, eligibility rules (license class required, permit requirements).
- **Dispatch Override:** view any in-search booking, force-assign to a specific driver, cancel-and-refund, view dispatch attempt history/log for a booking.
- **Driver Management:** search/filter driver roster, view KYC status, suspend/reinstate with reason code, view violation/penalty history, manually adjust document expiry flags (with audit log).
- **Coupon & Fraud Tools:** create/edit coupons (Section 12), view fraud-flagged accounts/trips (Section 17) queue, approve/reject fraud holds.
- **Refunds & Disputes:** queue of pending refund requests above auto-approval threshold, approve/reject/partial-approve with reason.
- **CMS:** banners, in-app announcements, FAQ content, legal/policy page content — versioned, with a preview-before-publish step.
- **Audit Log Viewer:** searchable/filterable log of every admin action platform-wide (who/what/when/before-after values).

**Screen list pattern:** each entity above (City, Zone, Driver, Coupon, etc.) gets: List (search/filter/sort/paginate/bulk-action), Detail (view + edit tabs), Create/Edit form, and — where destructive — a Confirm dialog naming the specific record.

**RBAC gating example:** a Support-role admin can view a driver's KYC status but cannot edit pricing config; a Finance-role admin can approve refunds but cannot force-dispatch. Full matrix in Section 18.

**Edge cases:** two admins editing the same rate card simultaneously → optimistic-lock/version-conflict UI (Section audit rules from your own delivery standard — surfaced, not silently overwritten); a zone geofence edit that would strand active in-progress trips outside the new boundary → warn before save, never silently orphan active trips from their zone-scoped configs.

**Acceptance criteria:** every configuration change is versioned and revertible; every destructive/high-impact action requires a named confirmation; RBAC enforced at the API layer independent of what the UI hides (Section 18, Section 27 security).

---

## 10. OPERATIONS / CONTROL ROOM — TIER 2

**Purpose:** Real-time live-ops view distinct from the general Admin Dashboard — optimized for monitoring and rapid intervention during active operating hours, not configuration.

**Core view:** live map of all active trips + online driver supply, color-coded by SLA-risk (on-time, at-risk, breached), a real-time feed of key events (new booking, dispatch exhausted, cancellation, SOS triggered), zone-level supply/demand imbalance indicators feeding the surge engine's inputs for human sanity-check/override.

**Intervention actions available:** force-reassign a stuck booking, broadcast a push notification to online drivers in a zone (e.g., incentive nudge during a demand spike), manually trigger/adjust a surge zone (bounded by config caps — ops cannot override the hard multiplier cap), acknowledge/escalate an SOS event with a direct line to the safety team.

**SOS handling flow (critical path):** driver or customer SOS trigger → immediate event to Control Room (highest-priority queue, sound/visual alert, cannot be dismissed without an explicit acknowledgment + resolution note) → parallel automated actions (emergency contact notified if configured, trip location continuously streamed to Control Room regardless of the rider's normal tracking-sharing preference for the duration of the SOS).

**Acceptance criteria:** SOS event reaches an available Control Room operator's screen within 5s p95 of trigger; every SOS event has a mandatory resolution note and timestamp before it can be closed; force-reassign and broadcast actions are fully audit-logged identically to Admin Dashboard actions (Section 9).

---

## 11. CUSTOMER SUPPORT PORTAL — TIER 2

**Core objects:** Ticket (linked to a user_id and optionally a booking_id), Conversation thread (omnichannel — in-app chat, email import, call-log notes), Macro/canned-response library, SLA timers per ticket priority tier.

**Agent workflow screens:** Queue (filterable by priority/SLA-risk/category/assigned-agent), Ticket Detail (full user + booking context panel alongside the conversation — agent should never need to tab out to another system to see trip history, wallet balance, or KYC status), Resolution/Close flow (mandatory category + resolution-note before close), Escalation flow (to supervisor or to Fraud/Finance queues directly, carrying full context).

**Refund/compensation actions available to agents:** bounded by role-based limits (e.g., frontline agent can auto-approve refunds under ₹500, above that routes to Finance approval queue per Section 9's Refunds module) — every action agent-side writes directly into the Wallet ledger (Section 6), never a manual side-channel adjustment.

**Edge cases:** user has multiple open tickets for the same booking (e.g., opened one via chat and one via a callback request) → auto-merge/link suggestion surfaced to the agent, not two silently duplicate threads; ticket SLA breach → auto-escalate visibility to supervisor dashboard, not just a color change the agent can ignore.

**Acceptance criteria:** every refund/compensation action is traceable from Support Portal → Wallet ledger → Finance reconciliation (Section 6) with no manual off-system step anywhere in the chain.

---

## 12. FINANCE & SETTLEMENT MODULE — TIER 2

**Core function:** reconcile every money movement across customer payments, platform fees, driver earnings/payouts, corporate invoicing, refunds, and taxes into an auditable, exportable ledger.

**Screens:** Settlement Dashboard (per-period platform revenue/payout summary), Driver Payout Batch (review a payout run before release, hold/exclude individual drivers with reason, e.g., pending dispute), Corporate Invoicing (generate/send monthly invoices against contract rate cards, Section 11 B2B), Tax Reports (GST liability by jurisdiction), Reconciliation Job Monitor (Section 25 background jobs — surfaces any ledger-mismatch alerts from the daily integrity job in Section 6).

**Payout batch flow:** system proposes a batch (all drivers with payout-eligible balance above threshold) → finance reviews/adjusts holds → approves → batch submitted to a bank-transfer/payout-provider API → webhook-driven status updates per line item → failures auto-flagged for retry or manual finance review (never silently dropped from the batch).

**Edge cases:** a driver flagged mid-batch for a fraud hold (Section 17) → their payout line is automatically excluded and the batch total recalculated, not manually caught after the fact; corporate invoice generated against a rate card that changed mid-billing-period → invoice line items must reflect the rate-card-at-time-of-trip for each trip, never a blanket current-rate reapplication.

**Acceptance criteria:** platform-wide ledger balances to zero (Section 6 invariant) verified before any payout batch is allowed to submit; every payout/invoice is regenerable byte-for-byte from the underlying ledger for audit purposes.

---

## 13. FLEET MANAGEMENT — TIER 2

**Purpose:** Support fleet-operator accounts that manage multiple vehicles/drivers under one owner entity (distinct from independent owner-driver accounts).

**Core objects:** Fleet Owner account, Vehicle roster (linked to owner, can be reassigned between the owner's drivers), Driver roster under fleet (owner can onboard drivers into their fleet with a streamlined KYC flow that references the fleet's own verified business entity).

**Screens:** Fleet Dashboard (aggregate earnings/utilization across all fleet vehicles), Vehicle List/Detail (assign/unassign driver, document status per vehicle), Driver Roster (invite/remove drivers from fleet), Payout Split Configuration (owner-vs-driver earning split rules, per-vehicle or per-driver override).

**Edge cases:** a driver reassigned from Vehicle A to Vehicle B mid-day → in-progress trip on Vehicle A must complete under the original vehicle/insurance record, reassignment takes effect only for new trips; fleet owner deactivates a driver who has a pending payout balance → balance settlement flow triggers independent of the deactivation (funds owed are never forfeited by deactivation alone).

**Acceptance criteria:** every trip's earnings record correctly attributes the owner/driver split at the rate active at trip time, immune to later split-config changes.

---

## 14. CORPORATE / B2B PORTAL — TIER 2

**Core objects:** Corporate Account (billing entity), Contract Rate Card (Section 5 override), Employee/User roster under the account (who can book on the company's account), Credit Limit, Recurring/Scheduled Pickup rules.

**Screens:** Company Dashboard (spend-to-date vs credit limit, active bookings across the org), Employee Management (invite/remove users, set per-user booking limits), Recurring Booking Setup (define a repeating pickup/drop pattern — e.g., daily warehouse-to-store run — with auto-booking and exception handling for holidays/skips), Invoice History/Download, API Key Management (for corporate accounts integrating booking programmatically — see Section 21 public API).

**Credit limit enforcement:** every booking against a corporate account checks remaining credit before confirming; a booking that would exceed the limit is blocked with a clear message to the requesting employee and a notification to the account admin, never silently allowed to overdraw.

**Edge cases:** an employee removed from the roster mid-scheduled-recurring-setup they created → the recurring booking must transfer ownership to an account admin automatically, not silently stop or orphan; invoice dispute raised by the corporate account → routes into the same Support Portal ticket system (Section 11) with corporate-context flagging for prioritized handling.

**Acceptance criteria:** no corporate booking can ever be confirmed while exceeding the account's live credit limit, verified under concurrent-booking load (two employees booking simultaneously near the limit boundary must not both succeed if only one fits).

---

## 15. MARKETING & COUPON ENGINE — TIER 2

**Core objects:** Coupon (code, discount type flat/percent, min-order value, max-discount cap, usage-limit-per-user, usage-limit-total, valid-from/to, applicable-categories/zones/user-segments), Campaign (groups coupons + push/banner content + a target segment), Referral program config (Section 16), Push/Banner CMS content (feeds Customer App Home carousel, Section 2.2.3).

**Coupon validation logic (server-side, at both apply-time and confirm-time — see 2.2.5 edge case):** user eligibility (segment/first-trip-only/etc.), order-value threshold, category/zone applicability, per-user and global usage caps, stacking rules (can this coupon combine with an active subscription discount? — explicit allow/deny, never ambiguous).

**Segment builder:** rule-based user segmentation (e.g., "no trips in last 30 days," "corporate account," "city = X") feeding both coupon targeting and push-notification campaign targeting (Section 19).

**Edge cases:** coupon usage cap reached exactly as two concurrent requests both check availability → hard DB-level counter/lock, not a read-then-write race that allows over-redemption; coupon retroactively deactivated by marketing after being applied to in-flight (not-yet-completed) bookings → those specific bookings honor their already-locked fare (quote_id, Section 2.2.5), never retroactively re-priced mid-trip.

**Acceptance criteria:** coupon spend is fully attributable in Finance reporting (Section 12) as a distinct discount-liability line, reconciled against actual redemption counts.

---

## 16. NOTIFICATIONS (SMS, WHATSAPP, EMAIL, PUSH) — TIER 2

**Architecture:** a single Notification Service consumes domain events (Section 22) and routes to the appropriate channel(s) per a template + user-preference matrix — individual services (Booking, Dispatch, Wallet) never call SMS/push providers directly.

**Channel routing rules (example):** OTP → SMS only, always (never suppressible by preference, for security-critical codes); trip-status updates → push primary, SMS fallback if push token invalid/app uninstalled-signal; promotional → push + WhatsApp, fully opt-out-able per user preference (Screen 48); transactional receipts/invoices → email always, regardless of marketing opt-out (transactional exemption).

**Template management:** versioned templates per channel per locale, admin-editable (CMS, Section 9) with variable-substitution validation (a template referencing `{driver_name}` must fail a lint check if that variable isn't guaranteed present on the triggering event).

**Delivery tracking:** every send attempt logged with provider-reported delivery status (sent/delivered/failed/bounced); failed critical sends (e.g., OTP) trigger an automatic fallback channel attempt, not a silent drop.

**Edge cases:** user changes phone number → in-flight notifications queued to the old number must not fire post-change; a notification burst (e.g., mass promotional campaign) must be rate-limited against provider throughput and against per-user daily-notification caps to avoid spam-fatigue and provider throttling.

**Acceptance criteria:** OTP delivery success rate monitored as a first-class SLA metric (Section 28 monitoring); no user-facing notification is ever sent with an unresolved template variable (renders literal `{driver_name}`) — caught by template lint in CI, not discovered in production.

---

## 17. RATINGS & REVIEWS — TIER 2

**Model:** bidirectional (customer rates driver, driver rates customer) post-trip, 1–5 stars + optional free-text + optional tag selection (e.g., "Late," "Rude," "Damaged goods" for low ratings; "On time," "Careful handling" for high).

**Aggregation:** rolling average (e.g., last 100 trips or last 90 days, config) drives the driver's public rating shown to customers pre-booking is *not* shown (avoids bias toward already-popular drivers in dispatch) but *is* used as a dispatch-scoring input (Section 4) and as a suspension trigger below a floor threshold.

**Low-rating escalation:** a rating below a config threshold (e.g., ≤2 stars) auto-creates a review flag for Ops/Support (Section 11) rather than silently averaging in — repeated low ratings with tag patterns (e.g., multiple "unsafe driving" tags) feed the Fraud/Safety queue (Section 17.5 below / Section 20).

**Edge cases:** rating submitted after the rating window closes (config, e.g., 48h post-trip) → accepted but flagged as late, excluded from real-time dispatch-scoring recalculation to prevent gaming; a user attempting to rate a trip that isn't theirs (tampered request) → server validates rater is a genuine party to the specific booking_id before accepting.

**Acceptance criteria:** rating average is server-computed and tamper-evident (every individual rating retained, never just an incrementally-updated mutable average field with no audit trail).

---

## 18. REFERRAL SYSTEM — TIER 2

**Model:** unique referral code per user, shareable link/code, reward triggers on referee's qualifying action (config, e.g., "referee completes first trip," not just signup — reduces fraud from fake-signup farming).

**Reward flow:** referee completes qualifying trip → both referrer and referee credited (amounts config-driven, can differ) → credited to Wallet (Section 6) as a tagged promotional-credit transaction.

**Fraud controls (ties into Section 17 Fraud module):** device-fingerprint and payment-instrument-fingerprint checks to detect self-referral or referral-ring abuse (same device/card used across many "distinct" referred accounts) → suspicious clusters held for review before reward payout, not auto-paid then clawed back after the fact where avoidable.

**Edge cases:** referee's qualifying trip is later cancelled/refunded → reward reversal logic must be explicit and disclosed (don't silently claw back without notification) with a grace/appeal path via Support (Section 11).

**Acceptance criteria:** referral reward issuance is idempotent per referee (cannot be triggered twice even under duplicate event delivery) and fully traceable in the Wallet ledger with a `referral_id` tag.

---

## 19. LOYALTY & SUBSCRIPTIONS — TIER 2

**Model:** tiered loyalty (points accrual per trip spend, tier thresholds unlocking perks like priority dispatch or fee waivers) plus an optional paid subscription plan (flat monthly fee for benefits like waived platform fee, surge-exemption up to a cap, or a bundled trip-credit allotment).

**Screens:** Loyalty Home (current tier, progress bar to next tier, perk list), Subscription Plan Comparison/Purchase (Screen 59), Subscription Management (renew/cancel/change plan), Points/Benefit Usage History.

**Billing integration:** subscription is a recurring payment against the user's default payment method (Section 6), with dunning logic on failed renewal (retry schedule, grace period before benefits lapse, clear in-app + notification messaging at each stage — never a silent benefit cutoff).

**Edge cases:** a subscriber's benefit (e.g., surge-exemption) interacting with an active coupon on the same booking → explicit stacking rule (Section 15 pattern) must resolve which benefit applies, never double-apply or silently drop one without explanation in the fare breakup (2.2.5).

**Acceptance criteria:** every subscription benefit applied to a fare is itemized in the fare_breakdown (Section 5) exactly like a coupon discount, for full auditability.

---

## 20. ANALYTICS DASHBOARDS — TIER 2

**Consumers:** Admin/Ops leadership (city-level KPIs), Finance (revenue/settlement trends), Marketing (campaign performance, segment sizes), Product (funnel/retention).

**Core dashboards:** Demand heatmap (by zone/time), Cohort retention curves, CAC/LTV by acquisition channel, Cancellation-rate breakdown (by reason/zone/time — feeds directly back into Dispatch and Support process improvements), Booking funnel (Home view → address entered → quote shown → confirmed → completed, with drop-off rate per step), Driver utilization (online-hours vs trip-hours ratio), Revenue dashboards (gross bookings, net revenue, take-rate, by category/zone/time).

**Architecture:** analytics is fed by the event bus (Section 22) into an OLAP-friendly store (ClickHouse/BigQuery-class), never queried directly against the OLTP production database — dashboards must not risk production transactional performance.

**Acceptance criteria:** every dashboard metric has a documented definition (e.g., exact "cancellation rate" denominator) versioned alongside the dashboard so numbers don't silently drift in meaning between releases; data freshness SLA per dashboard displayed to the viewer (e.g., "as of 5 minutes ago").

---

## 21. FRAUD DETECTION — TIER 2

**Signal sources:** GPS-spoofing detection (Section 8), device/payment-instrument fingerprint clustering (Section 18 referral abuse pattern, generalized), abnormal cancellation patterns (driver or customer), rating-pattern anomalies (Section 17), promo/coupon abuse (Section 15), collusive-trip detection (driver and customer accounts trading fake trips to farm incentives — detected via repeated-pairing + implausible-route/duration signals).

**Response tiers (config, escalating):** silent flag for review (no user-facing impact) → soft hold (e.g., payout delayed pending review, account otherwise functional) → hard suspension (account frozen, routed to Support/Ops for manual resolution) — the engine should default to the least-disruptive tier that still contains the risk, escalating only on confirmed pattern strength, to minimize false-positive harm to legitimate users.

**Admin/Ops surface:** Fraud Queue (Section 9's Admin module) showing flagged entities, the specific signal(s) that triggered the flag, and a one-click clear/escalate/suspend action set — every decision attributed and logged (Section 9 audit pattern).

**Acceptance criteria:** every automated hold has a defined maximum silent-hold duration before it must surface to a human reviewer (no indefinite algorithmic limbo); false-positive rate tracked as a first-class metric alongside catch-rate, reviewed periodically to retune thresholds.

---

## 22. ROLE & PERMISSION MANAGEMENT (RBAC) — TIER 2

**Model:** Role → set of Permissions (resource + action, e.g., `pricing.edit`, `refund.approve_over_limit`, `driver.suspend`) → Users assigned one or more Roles, optionally scoped (e.g., "Ops Manager, City = Mumbai only").

**Default roles (extensible):** Super Admin, City Ops Manager, Support Agent, Support Supervisor, Finance Analyst, Finance Approver, Marketing Manager, Fraud Analyst, Read-Only Auditor.

**Enforcement:** every API endpoint declares its required permission(s); enforcement happens server-side on every request regardless of what the calling UI displays or hides (a hidden button in the Admin UI is a UX nicety, never the actual security boundary).

**Screens:** Role List/Edit (permission checkbox matrix), User-Role Assignment, Permission Audit View (who can do what, queryable both by user and by permission).

**Edge cases:** a user's role is downgraded while they have an active session/open browser tab → the next API call re-validates their current permission set server-side (session doesn't grandfather stale elevated access); scoped roles (city-limited) must be enforced at the query layer for every list/search endpoint (a City Ops Manager for Mumbai must never see or edit a Delhi driver record via a scope-bypass in a filter parameter).

**Acceptance criteria:** a permission matrix test suite verifies, for every role, both the positive case (can do what they should) and the negative case (cannot do what they shouldn't) at the API layer directly — not just via UI click-testing.

---

## 23. API SPECIFICATIONS — FRAMEWORK

**Standards:** REST over HTTPS, JSON payloads, versioned via URL path (`/v1/...`), authentication via short-lived JWT access tokens + refresh tokens, idempotency keys required on all POST endpoints that create or mutate financial/booking state, consistent error envelope:

```json
{ "error": { "code": "QUOTE_EXPIRED", "message": "human-readable, safe to display", "details": {} } }
```

**Core endpoint groups (representative — full contract lives in the API spec repo, not duplicated line-by-line here):**
- Auth: `/v1/auth/otp/request`, `/v1/auth/otp/verify`, `/v1/auth/refresh`, `/v1/auth/logout`
- Geo: `/v1/geo/autocomplete`, `/v1/geo/reverse`, `/v1/geo/serviceability`
- Pricing: `/v1/pricing/quote`
- Booking: `/v1/bookings` (POST create, GET list/detail), `/v1/bookings/{id}/cancel`, `/v1/bookings/{id}/rate`
- Dispatch (internal): `/internal/v1/dispatch/*`
- Driver: `/v1/driver/kyc/*`, `/v1/driver/status` (online/offline), `/v1/driver/jobs/{id}/accept|decline`
- Wallet: `/v1/wallet`, `/v1/wallet/add-money`, `/v1/wallet/transactions`
- Corporate: `/v1/corporate/{account_id}/*`
- Admin (RBAC-gated): `/admin/v1/*`

**Public developer API (Corporate self-serve booking, Section 11):** subset of Booking + Pricing endpoints exposed via API keys with per-key rate limiting and scoped to the issuing corporate account only.

**Acceptance criteria:** every endpoint has an OpenAPI/Swagger definition kept in sync with implementation via contract testing in CI; breaking changes require a new version path, never an in-place contract change on `/v1`.

---

## 24. DATABASE SCHEMA — FRAMEWORK (core entities)

**Representative core tables (full DDL lives in the migrations repo):**

- `users (id, phone, email, role, status, created_at, ...)`
- `driver_profiles (user_id FK, kyc_status, rating_avg, vehicle_category, ...)`
- `vehicles (id, owner_type[driver|fleet], owner_id, category, rc_number, insurance_expiry, ...)`
- `kyc_documents (id, subject_type, subject_id, doc_type, status, rejection_reason, version, ...)`
- `bookings (id, customer_id, status, pickup_geo, drop_geo[], vehicle_category, quote_id, fare_breakdown JSONB, created_at, ...)`
- `booking_stops (id, booking_id FK, sequence, geo, instructions, status)`
- `dispatch_offers (id, booking_id FK, driver_id FK, status, offered_at, responded_at)`
- `wallets (id, owner_type, owner_id, currency)`
- `wallet_transactions (id, wallet_id FK, type[debit|credit], amount, transaction_group_id, reason, created_at)`
- `payments (id, booking_id FK, gateway_ref, status, amount, method)`
- `coupons (id, code, rules JSONB, valid_from, valid_to, usage_limits JSONB)`
- `coupon_redemptions (id, coupon_id FK, user_id FK, booking_id FK, created_at)` — unique constraint (coupon_id, user_id) where single-use-per-user
- `ratings (id, booking_id FK, rater_id, ratee_id, stars, tags[], comment, created_at)`
- `corporate_accounts (id, name, credit_limit, rate_card_id FK, ...)`
- `roles (id, name)`, `permissions (id, resource, action)`, `role_permissions`, `user_roles (user_id, role_id, scope JSONB)`
- `audit_log (id, actor_id, action, resource_type, resource_id, before JSONB, after JSONB, created_at)`

**Design rules:** every financial table is append-only where possible (ledger pattern, Section 6) rather than mutable-balance; every table that feeds an audit or dispute process retains soft-delete/versioning rather than hard-delete; all geo columns use a proper geospatial type (PostGIS) with spatial indexes, not raw lat/lng floats with app-side distance math for anything performance-sensitive (Dispatch, Section 4).

**Acceptance criteria:** every schema migration is reversible; every foreign key has an explicit on-delete policy decided deliberately (never left to database default); financial tables have DB-level constraints preventing negative-balance-where-disallowed (Section 6) rather than relying solely on application logic.

---

## 25. EVENT ARCHITECTURE — FRAMEWORK

**Pattern:** domain services publish immutable events to a shared bus on every significant state change; downstream consumers (Notification, Analytics-ingest, Fraud, Dispatch) subscribe rather than being directly called synchronously — decouples failure domains (Section 1 principle).

**Representative event catalog:** `BookingCreated`, `BookingCancelled`, `QuoteGenerated`, `DriverOffered`, `DriverAccepted`, `DriverDeclined`, `DispatchExhausted`, `TripStarted` (pickup OTP confirmed), `TripCompleted`, `PaymentSucceeded`, `PaymentFailed`, `RefundIssued`, `KYCApproved`, `KYCRejected`, `DocumentExpiringSoon`, `RatingSubmitted`, `FraudFlagRaised`, `SOSTriggered`.

**Schema requirements:** every event carries a schema version, an `event_id` (for consumer-side idempotent processing), a `occurred_at` server timestamp, and the minimal payload needed (consumers fetch fresh detail via API if they need more than the event carries — events are notifications, not a data-replication mechanism).

**Acceptance criteria:** every event schema is versioned and backward-compatible-by-default (new optional fields only; breaking changes get a new event type); every consumer handles duplicate delivery idempotently (at-least-once delivery assumed, never exactly-once assumed).

---

## 26. BACKGROUND JOBS — FRAMEWORK

**Representative job catalog:** ledger-integrity-check (daily, Section 6), document-expiry-scanner (hourly, Section 3.2/7), driver-payout-batch-generator (per payout cycle, Section 12), dispatch-SLA-breach-scanner (near-real-time, feeds Section 10), coupon-expiry-sweeper, stale-quote-cleanup, notification-retry-worker (Section 16 failed-send fallback), tracking-data-archival-to-cold-storage (for trips older than the hot-query window), fraud-signal-batch-scorer (periodic re-scoring of accounts against updated fraud models, Section 17).

**Reliability requirements:** every job is idempotent (safe to re-run on failure/retry), every job has a max-runtime guard and alerting on overrun, every job's failure is visible in an ops-facing job-monitor screen (Section 12's Reconciliation Job Monitor pattern, generalized) — never a silently-failed cron with no signal.

**Acceptance criteria:** no background job can leave the system in a partially-applied state on failure (transactional boundaries or explicit compensating-action logic); every job's last-successful-run timestamp is monitorable and alertable if stale beyond its expected cadence.

---

## 27. SECURITY REQUIREMENTS — FRAMEWORK

- **Auth:** short-lived JWTs, refresh-token rotation, device-binding for refresh tokens (a stolen refresh token from another device is detectable/revocable).
- **Transport:** TLS everywhere, certificate pinning on mobile apps for the API host.
- **Data at rest:** encryption for PII columns and all document/media storage (KYC docs, Section 3.2/7); encryption key management via a dedicated KMS, never app-embedded secrets.
- **Input handling:** server-side validation on every field regardless of client-side validation (client validation is UX, never the security boundary); parameterized queries only, no raw SQL string interpolation anywhere.
- **AuthZ:** RBAC enforced at the API layer on every endpoint (Section 22), independent of UI.
- **Rate limiting:** per-endpoint, per-user and per-IP, especially on Auth (2.2.1 OTP) and any endpoint touching payment or refund actions.
- **Secrets management:** no secrets/API keys in source control; environment-injected via a secrets manager.
- **PCI scope minimization:** payment card data never touches platform servers directly — gateway-tokenized flows only (Section 6).
- **Audit logging:** every privileged action logged immutably (Section 24 `audit_log`), retained per compliance requirements.
- **Vulnerability management:** dependency scanning in CI, scheduled penetration testing cadence, a defined responsible-disclosure process.

**Acceptance criteria:** a security review/pen-test sign-off is a hard release gate before production launch and before any release touching Auth, Wallet, or KYC modules.

---

## 28. PERFORMANCE & SCALABILITY REQUIREMENTS — FRAMEWORK

- Customer Home (2.2.3) interactive within 2.5s p95 on mid-tier Android over 4G.
- Quote generation (2.2.5) within 1.5s p95.
- Dispatch offer round-trip (Section 4) within 2s p95, city-scale concurrency.
- Tracking ping-to-map-update (Section 8) within 3s p95 at city-scale concurrent active trips.
- All list/search APIs paginated by default (no unbounded result sets); indexes verified against actual query patterns, not assumed.
- Read-heavy paths (Home, Trip History, Admin lists) use appropriate caching (Redis) with explicit invalidation on writes — never a stale-cache bug silently shipped.
- Load testing performed at a defined target scale (e.g., 10x current/expected peak concurrent bookings) before each major release, with results reviewed against the SLAs above.

**Acceptance criteria:** every SLA above is instrumented and dashboarded (Section 20/28-monitoring) so a regression is caught by monitoring, not by user complaints.

---

## 29. QA TEST METHODOLOGY & UI COMPONENT LIBRARY / DESIGN SYSTEM — FRAMEWORK

**QA methodology:** every feature ships with (a) unit tests on business logic (pricing formula, dispatch scoring, ledger math), (b) integration tests on API contracts (Section 23), (c) end-to-end tests on the critical paths (booking creation through completion, KYC approval through first job, payout batch through bank confirmation), (d) manual exploratory QA against the acceptance-criteria checklist per module in this document, (e) regression suite run before every release.

**Design system:** a shared component library (buttons, form fields, cards, modals, empty-states, error-states, loading-skeletons) used identically across Customer App, Driver App, and all web portals — the Universal Screen Spec Template (Section 20 wait — see numbering note below) ensures every new screen reuses this library rather than one-off styling. Design tokens (color, spacing, typography scale) defined once and consumed everywhere, dark-mode-ready if in scope.

**Acceptance criteria:** no screen ships with ad hoc, non-tokenized styling; every reusable component has documented states (default/hover/disabled/error/loading) in the library itself, not re-invented per screen.

---

## 30. DEPLOYMENT ARCHITECTURE, MONITORING/LOGGING, DISASTER RECOVERY — FRAMEWORK

**Deployment:** containerized services, environment parity (dev/staging/prod), blue-green or canary release strategy for backend services, mobile app staged rollout (percentage-based) with kill-switch/feature-flag capability to disable a broken feature without an app-store resubmission cycle.

**Monitoring/logging:** structured logging (no unstructured print-debugging in production), centralized log aggregation, error-rate and latency dashboards per service (feeding the SLAs in Section 28), alerting thresholds defined per critical path (Dispatch, Payments, Auth) with on-call escalation.

**Disaster recovery:** defined RPO/RTO targets per data store tier (OLTP database is the tightest — near-zero data loss tolerance; analytics store can tolerate more), automated backups with tested restore procedures (a backup that's never been restored in a drill is not a verified backup), multi-AZ/region failover plan for the core booking/dispatch/payment path specifically (these cannot go down even if secondary systems like Marketing CMS are temporarily degraded — ties back to Section 1's service-independence principle).

**Acceptance criteria:** a DR drill is executed and its results documented before production launch, not merely planned on paper; every critical-path service has a documented, tested rollback procedure for a bad deploy.

---

## 31. FUTURE ROADMAP (explicitly out of v1 scope — tracked, not built)

- Multi-job batching for drivers (accept and sequence multiple concurrent bookings, referenced as a v2 item in Section 3.3).
- Route-optimized multi-drop for a single driver across multiple customers (distinct from the v1 multi-stop-for-one-customer feature in 2.2.4).
- Predictive/ML-driven surge forecasting beyond the v1 rule-based tiered model (Section 5).
- In-app driver-training gamification beyond the v1 video/quiz gate (Section 3.2).
- International multi-currency wallet support beyond the v1 single-currency-per-market model (Section 6).
- White-label/franchise city-operator model.

---

## 9A. ADMIN DASHBOARD — TIER 1 DEEP DIVE (selected screens, applying Section 20 template)

### 9A.1 Pricing Configuration — Rate Card Editor

```
SCREEN: Rate Card Edit
MODULE: Admin — Pricing Configuration
PURPOSE: Let an authorized admin define/update the fare formula coefficients (Section 5) for one vehicle category in one city, with a future effective date rather than instant overwrite.

LAYOUT: City selector (locked if admin is city-scoped, Section 22) → Category selector → Coefficient form (base_fare, per_km_rate, per_min_rate, waiting_free_min, waiting_per_min_rate, night_surcharge_pct, night_window_start/end, minimum_fare, platform_fee, tax_rate) → Effective-date picker (immediate or future) → Diff preview panel (old vs new, per field) → Save (draft) / Publish (goes live at effective date) actions.

FIELDS:
| Field | Type | Validation rule | Error copy |
|---|---|---|---|
| base_fare | currency | > 0, ≤ configured sanity ceiling | "Base fare must be a positive amount under [ceiling]" |
| per_km_rate | currency | > 0 | "Per-km rate must be positive" |
| minimum_fare | currency | ≥ base_fare | "Minimum fare cannot be less than base fare" |
| night_window_start/end | time | start ≠ end; valid 24h format | "Enter a valid time window" |
| effective_date | date/time | ≥ now (or now if immediate) | "Effective date must be in the future for scheduled changes" |

STATES: loading-current-card / editing (dirty-state indicator) / diff-preview / saving / saved-draft / publish-conflict (another admin published a newer version since this edit started — version-token mismatch) / published.

EDGE CASES:
- Two admins open the same rate card concurrently; second Save fails with a version-conflict state showing what changed and forcing a re-diff before overwrite (never silent last-write-wins).
- A future-dated change is edited again before it takes effect — the second edit replaces the pending scheduled change, does not stack two competing scheduled changes.
- Publishing a change that would make minimum_fare > any active coupon's max-discount-implied floor is a soft warning, not a hard block (marketing may intentionally allow it), but it is surfaced explicitly.
- An in-flight quote (quote_id, Section 2.2.5) generated under the old rate card must honor the old rate through its TTL even if a new card is published mid-TTL — never re-price an already-issued quote.

API(S) CALLED: `GET /admin/v1/pricing/rate-cards/{city_id}/{category_id}`, `PUT /admin/v1/pricing/rate-cards/{id}` (with `If-Match` version header), `POST /admin/v1/pricing/rate-cards/{id}/publish`.

PERMISSIONS REQUIRED: `pricing.edit` (city-scoped where applicable, Section 22).

ANALYTICS EVENTS FIRED: `RateCardEdited`, `RateCardPublished` → Section 22 catalog.

ACCEPTANCE CRITERIA:
- [ ] A publish with a stale version token is rejected with a diff, never silently overwritten.
- [ ] Every published rate card is retained (versioned), never hard-deleted, so historical fares remain explainable (Section 5 acceptance criteria).
- [ ] Scheduled future changes visibly appear in a "Pending Changes" list before taking effect.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Two admins edit same card, second saves after first publishes | Version-conflict state, forced re-diff |
| Publish with minimum_fare < base_fare | Blocked with inline validation error |
| Quote issued just before a new rate card takes effect | Quote honors old rate through its TTL |
```

### 9A.2 Driver Suspend/Reinstate

```
SCREEN: Driver Detail — Suspend Action
MODULE: Admin — Driver Management
PURPOSE: Let an authorized admin immediately remove a driver's job-eligibility with a structured, auditable reason, and reinstate later.

LAYOUT: Driver profile header (name, rating, KYC status, current online/offline state) → Action bar (Suspend / Adjust Documents / View Violations / View Payout History) → Suspend modal: reason-code dropdown (fixed taxonomy: FRAUD_SUSPECTED, DOCUMENT_EXPIRED, SAFETY_COMPLAINT, LOW_RATING, OTHER), free-text note (required if OTHER), duration (indefinite / until-date), confirm button naming the driver.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| reason_code | dropdown | required, from fixed taxonomy | "Select a reason" |
| note | text | required if reason_code = OTHER, max 500 chars | "Add a note explaining this suspension" |
| duration | radio + date | if until-date, must be future | "Select a valid reinstatement date" |

STATES: active → suspend-modal-open → confirming → suspended (badge visible, action bar shows Reinstate) → reinstate-modal-open → reinstate-confirming → active.

EDGE CASES:
- Driver has an active in-progress trip at the moment of suspension — suspension takes effect for future job eligibility only; the current trip is allowed to complete (never strand a customer's in-progress goods mid-transit), but the driver cannot accept any new job the instant suspension is confirmed.
- Auto-suspension from a background job (e.g., document expiry, Section 26) must appear in this same view identically to a manual admin suspension, with actor = SYSTEM rather than a blank/missing actor.
- Reinstating a driver whose KYC document expired during the suspension is blocked — reinstate flow re-checks KYC validity and forces document re-verification before allowing reinstatement.

API(S) CALLED: POST /admin/v1/drivers/{id}/suspend, POST /admin/v1/drivers/{id}/reinstate.

PERMISSIONS REQUIRED: driver.suspend.

ANALYTICS EVENTS FIRED: DriverSuspended, DriverReinstated.

ACCEPTANCE CRITERIA:
- [ ] A suspended driver cannot be offered a new job by Dispatch within one dispatch cycle of suspension.
- [ ] Every suspend/reinstate action appears in the driver's audit trail with actor, reason, and timestamp.
- [ ] In-progress trips at time of suspension are unaffected through completion.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Suspend driver mid-trip | Trip completes normally; no new jobs offered after |
| Reinstate driver with expired document | Blocked, redirected to document re-verification |
| Auto-suspend via expiry job | Appears in audit trail as actor=SYSTEM with matching reason |
```

---

## 16A. NOTIFICATIONS — TIER 1 DEEP DIVE

### 16A.1 Notification Preferences

```
SCREEN: Notification Preferences
MODULE: Notifications
PURPOSE: Let users control which non-critical channels/categories they receive, without disabling security- or transaction-critical sends.

LAYOUT: Category list, each with per-channel toggles (Push / SMS / WhatsApp / Email) — categories: Trip Updates, Promotions & Offers, Account Activity, Product News. OTP and legal/transactional receipts are never listed here (not user-toggleable).

FIELDS: toggle per (category × channel) cell — boolean.

STATES: loading current prefs / loaded / saving (per-toggle optimistic update with rollback-on-failure) / save-failed (toggle reverts, inline error).

EDGE CASES:
- User disables all channels for "Trip Updates" — a warning surfaces about missing arrival alerts, but it is allowed; OTP/SOS unaffected regardless.
- Preference change mid-active-trip does not affect notifications already queued for that trip's current state.
- User with no valid push token — system silently falls back to SMS for critical-enough items server-side regardless of toggle state; this fallback logic doesn't need to be exposed on this screen but must exist.

API(S) CALLED: GET /v1/notifications/preferences, PUT /v1/notifications/preferences.

PERMISSIONS REQUIRED: none (self-service).

ANALYTICS EVENTS FIRED: NotificationPreferenceChanged.

ACCEPTANCE CRITERIA:
- [ ] OTP and SOS notifications are structurally impossible to disable from this screen, including via crafted API calls.
- [ ] Preference changes take effect on the next send attempt, not retroactively on already-queued sends.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Disable all Trip Update channels | Warning shown, saved; OTP still delivered |
| Toggle save fails (network) | Toggle visually reverts, error shown |
| Attempt to disable OTP via crafted API call | 400 rejected |
```

### 16A.2 Delivery Failure & Fallback Flow (system-level)

```
FLOW: Critical notification delivery with fallback
TRIGGER: A domain event requires a transactional notification.
STEP 1: Notification Service resolves template + channel per preference and criticality tier.
STEP 2: Primary channel send attempt.
STEP 3: Provider webhook/status callback awaited up to a timeout (config, e.g., 10s).
STEP 4: If failed/bounced/timed out AND criticality ≥ "important" → automatic fallback to next channel, logged distinctly.
STEP 5: Final delivery status persisted per attempt for audit and SLA reporting.

EDGE CASES:
- Fallback itself fails — goes to a dead-letter queue for ops review; for OTP specifically, triggers an immediate in-app alternate-verification prompt rather than leaving the user stuck.
- Duplicate event delivery must not double-send — de-duplicated via event_id idempotency.

ACCEPTANCE CRITERIA:
- [ ] OTP delivery success rate (including fallback) is a monitored SLA metric, alertable if it drops below threshold.
- [ ] No domain event ever results in a duplicate user-facing notification.
```

---

## 17A. FRAUD DETECTION — TIER 1 DEEP DIVE

### 17A.1 Fraud Queue (Admin/Ops)

```
SCREEN: Fraud Queue
MODULE: Fraud Detection (Admin-facing)
PURPOSE: Give a Fraud Analyst a triaged, evidence-attached list of flagged entities/trips to clear, escalate, or suspend — never a black-box auto-action above the lowest response tier.

LAYOUT: Filterable/sortable queue (signal type, severity, age, city) → Row expands to Detail panel: entity summary, the specific signal(s) with underlying evidence → Action bar: Clear (with note) / Escalate to Supervisor / Apply Soft Hold / Apply Suspension (routes into 9A.2 for drivers, or an equivalent customer-hold flow).

FIELDS (Action modal):
| Field | Type | Validation | Error copy |
|---|---|---|---|
| resolution_note | text | required, max 1000 chars | "Add a note explaining this decision" |
| action | radio | required | "Select an action" |

STATES: queue-loading / queue-loaded / row-expanded / action-confirming / action-applied (moved to Resolved history) / escalated (visible in Supervisor's queue with full evidence chain).

EDGE CASES:
- A flagged driver has an active in-progress trip at review time — Suspend follows the same "don't strand an in-progress trip" rule as 9A.2.
- The same entity flagged by two independent signals simultaneously must merge into a single queue item, never two competing rows resolvable differently by two analysts.
- An analyst clears a flag that reflags on the same pattern within a short window — the new flag references the prior clear decision in its evidence panel.

API(S) CALLED: GET /admin/v1/fraud/queue, GET /admin/v1/fraud/queue/{id}, POST /admin/v1/fraud/queue/{id}/resolve.

PERMISSIONS REQUIRED: fraud.review (Clear/Escalate); fraud.suspend (Hold/Suspend, distinct higher-trust permission).

ANALYTICS EVENTS FIRED: FraudFlagRaised (system), FraudFlagResolved.

ACCEPTANCE CRITERIA:
- [ ] No fraud hold above the lowest tier persists beyond its configured maximum silent duration without surfacing to a human reviewer.
- [ ] Every resolution is attributed to a specific analyst with a mandatory note.
- [ ] False-positive rate is trackable per signal type to support threshold retuning.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Suspend flagged driver mid-active-trip | Trip completes; no new jobs after |
| Same entity flagged by two signals | Single merged queue item |
| Silent-hold entity ages past max duration with no reviewer action | Auto-escalates to Supervisor queue with alert |
```

---

## 11A. SUPPORT PORTAL — TIER 1 DEEP DIVE

### 11A.1 Ticket Detail / Resolution Flow

```
SCREEN: Ticket Detail
MODULE: Support Portal
PURPOSE: Give an agent everything needed to resolve a user's issue without leaving the screen, and force a structured close so resolution data feeds back into product/ops metrics.

LAYOUT: Left panel — user context (profile, recent trips, wallet balance, KYC status if driver, open/past tickets). Center — conversation thread (chat bubbles, timestamped, channel-tagged if omnichannel). Right panel — ticket metadata (priority, SLA countdown, category, linked booking_id) + Action bar (Refund, Escalate, Apply Macro, Close).

FIELDS (Close modal):
| Field | Type | Validation | Error copy |
|---|---|---|---|
| resolution_category | dropdown | required, fixed taxonomy | "Select a resolution category" |
| resolution_note | text | required, max 1000 chars | "Add a resolution note" |
| csat_request | toggle | default on | — |

STATES: open-unassigned / open-assigned / in-progress (agent typing/replying) / pending-customer-response (SLA clock may pause per policy) / escalated / closing / closed / reopened (customer replies after close within reopen window).

EDGE CASES:
- Agent attempts to close with an unresolved linked refund request still pending Finance approval (Section 12A) — close is blocked until the refund sub-flow reaches a terminal state, or the agent explicitly closes with "refund pending, tracked separately" only if policy allows split-tracking.
- Customer has two open tickets referencing the same booking_id (opened via chat and via a missed-call callback) — system surfaces a merge suggestion; agent can merge into one thread with both histories preserved, not silently discard one.
- SLA breach occurs while agent is actively typing a reply — breach still fires (server-side timer, not dependent on client state) and escalates visibility to the supervisor dashboard; the agent isn't blocked from finishing their reply.
- Ticket reopened after close, past the reopen window (config, e.g., 7 days) — a new ticket is created instead, linked to the old one for context, rather than reopening indefinitely.

API(S) CALLED: GET /support/v1/tickets/{id}, POST /support/v1/tickets/{id}/reply, POST /support/v1/tickets/{id}/refund (delegates to Wallet ledger, Section 6), POST /support/v1/tickets/{id}/escalate, POST /support/v1/tickets/{id}/close.

PERMISSIONS REQUIRED: support.ticket.manage; refund amount above per-role limit requires support.refund.escalate → routes to Finance approval queue (Section 12A.1) rather than being available directly to a frontline agent.

ANALYTICS EVENTS FIRED: TicketOpened, TicketEscalated, TicketClosed, RefundIssuedFromSupport.

ACCEPTANCE CRITERIA:
- [ ] No ticket can be closed with an unresolved dependent refund unless explicitly split-tracked per policy.
- [ ] SLA timers are server-authoritative and independent of agent client state.
- [ ] Every refund issued from this screen produces a corresponding Wallet ledger entry (Section 6) with a linked ticket_id — no refund exists that isn't traceable back to the ticket that authorized it.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Close with pending Finance-approval refund | Blocked unless split-tracking explicitly chosen |
| Two open tickets, same booking_id | Merge suggestion surfaced |
| SLA breach while agent mid-reply | Breach fires, supervisor dashboard updates, agent unblocked |
| Reopen after reopen window expired | New linked ticket created instead of reopening |
```

---

## 12A. FINANCE & SETTLEMENT — TIER 1 DEEP DIVE

### 12A.1 Driver Payout Batch Review

```
SCREEN: Payout Batch Review
MODULE: Finance — Settlement
PURPOSE: Let Finance review a system-proposed payout run before funds are released, with the ability to hold individual drivers without blocking the whole batch.

LAYOUT: Batch summary header (total drivers, total amount, period covered, ledger-integrity-check status from Section 6's daily job — batch cannot be approved if the integrity check for this period failed) → Driver line-item table (driver name, gross earnings, deductions/penalties, net payout, status: eligible/held/excluded) → Per-row Hold action with reason → Batch-level Approve & Submit action.

FIELDS (Hold modal):
| Field | Type | Validation | Error copy |
|---|---|---|---|
| hold_reason | dropdown | required (DISPUTE_PENDING, FRAUD_REVIEW, BANK_DETAILS_INVALID, OTHER) | "Select a hold reason" |
| note | text | required if OTHER | "Add a note" |

STATES: batch-proposed (system-generated, awaiting review) / reviewing (per-row holds being applied) / integrity-check-failed (batch approval blocked, links to Section 6 reconciliation alert) / approved-pending-submission / submitting (per-line-item calls to payout provider) / partially-failed (some line items failed at the provider — visible per-row, auto-queued for retry per Section 26) / completed.

EDGE CASES:
- A driver is flagged by the Fraud Queue (Section 17A.1) mid-batch-review, after the batch was proposed but before submission — their line item auto-transitions to held with hold_reason=FRAUD_REVIEW and the batch total recalculates; Finance is notified of the automatic change rather than discovering it silently.
- Provider-side payout failure for one driver (e.g., invalid account) does not block the other line items in the batch from completing — failures are isolated per line item, auto-retried per Section 26's background-job reliability rules, and if still failing after N attempts, escalated to a manual Finance follow-up with the driver notified in-app.
- Two Finance users attempt to approve the same batch simultaneously — second approval is rejected with "already approved by [user] at [time]," never a double-submission to the payout provider.
- A held driver's hold is lifted after the batch has already been submitted — they are not retroactively added to the completed batch; they roll into the next cycle automatically.

API(S) CALLED: GET /admin/v1/finance/payout-batches/{id}, POST /admin/v1/finance/payout-batches/{id}/hold-line/{driver_id}, POST /admin/v1/finance/payout-batches/{id}/approve, GET /admin/v1/finance/payout-batches/{id}/status (polls provider webhook-driven per-line status).

PERMISSIONS REQUIRED: finance.payout.review (hold), finance.payout.approve (approve & submit — may be a distinct, higher-trust permission requiring two-person approval above a config threshold amount).

ANALYTICS EVENTS FIRED: PayoutBatchApproved, PayoutLineHeld, PayoutLineFailed, PayoutBatchCompleted.

ACCEPTANCE CRITERIA:
- [ ] A batch cannot be approved while the ledger-integrity-check for its period is failing (Section 6 invariant enforced as a hard gate here, not just a background alert).
- [ ] A driver auto-held by a mid-review fraud flag is never included in a submitted batch.
- [ ] Every completed payout line item is reconcilable byte-for-byte against the driver's wallet ledger (Section 6, Section 12 acceptance criteria).
- [ ] Double-approval of the same batch is structurally prevented (version/state check), not just a UI disable.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Fraud flag raised on a driver mid-batch-review | Line auto-held, batch total recalculated, Finance notified |
| One line item fails at provider | Other lines complete; failed line retried per background-job policy |
| Two Finance users approve simultaneously | Second rejected with attribution message |
| Held driver's hold lifted post-submission | Rolls to next cycle, not retroactively added |
```

---

## 14A. CORPORATE / B2B PORTAL — TIER 1 DEEP DIVE

### 14A.1 Credit Limit Enforcement at Booking Time

```
FLOW: Corporate-account booking against a live credit limit
MODULE: Corporate Portal ↔ Booking (Customer App / Corporate self-serve API, Section 23)
PURPOSE: Guarantee a corporate account can never be overdrawn beyond its configured credit limit, even under concurrent bookings from multiple employees.

TRIGGER: An employee under a corporate account confirms a booking (Section 2.2.6) with "bill to company" selected as payment method.

STEP 1: Booking confirmation request includes corporate_account_id.
STEP 2: Server acquires a row-level lock (or equivalent atomic reservation) on the account's live balance-vs-limit state.
STEP 3: Reserves the quoted fare amount against the limit atomically (reservation, not yet a final charge — final charge happens at trip completion since fare can adjust for waiting/tolls).
STEP 4: If reservation would exceed remaining limit → booking rejected with a specific error (CREDIT_LIMIT_EXCEEDED), employee sees a clear message, account admin(s) notified.
STEP 5: If reservation succeeds → booking proceeds through normal dispatch; the reservation converts to a final ledger charge at trip completion (Section 6), releasing any difference between reserved and actual fare back to available limit.

EDGE CASES:
- Two employees near the limit boundary book simultaneously, and only one fits — the atomic reservation (Step 2–3) ensures exactly one succeeds and one gets CREDIT_LIMIT_EXCEEDED, never both succeeding and overdrawing (this is the concurrency-safety requirement called out in Section 14's original acceptance criteria).
- A reserved-but-not-yet-completed trip is cancelled — its reservation is released back to available limit immediately, not held until some batch job runs.
- Account admin raises the credit limit while an employee's booking is mid-rejection-flow on the client — the client should re-check on retry rather than caching the old rejected state, since the limit may now accommodate it.
- A recurring/scheduled booking (Section 14) that would push the account over its limit at execution time (limit may have changed since the recurring rule was set up) — auto-execution fails gracefully with an alert to the account admin, not a silent skip with no record.

ACCEPTANCE CRITERIA:
- [ ] No corporate booking can be confirmed while it would push committed-plus-reserved spend beyond the account's live credit limit, verified under concurrent-load testing at the limit boundary.
- [ ] Every reservation has a defined lifecycle (reserved → finalized or reserved → released) with no state where a reservation can be silently lost, permanently locking up limit that was never actually spent.
- [ ] Account admins are notified both on a hard rejection and on any auto-execution failure of a recurring booking due to limit.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Two employees book simultaneously, combined cost exceeds remaining limit by one booking's worth | Exactly one succeeds, one gets CREDIT_LIMIT_EXCEEDED |
| Reserved trip cancelled before completion | Limit released back immediately |
| Recurring booking fails at execution due to limit | Admin alerted, no silent skip |
| Admin raises limit after a rejection | Retry succeeds without stale-cache false rejection |
```

---

## 13A. FLEET MANAGEMENT — TIER 1 DEEP DIVE

### 13A.1 Vehicle Reassignment Between Drivers

```
SCREEN: Fleet Vehicle Detail — Reassign Driver
MODULE: Fleet Management
PURPOSE: Let a fleet owner move a vehicle from one of their drivers to another without disrupting an in-progress trip or losing document/insurance traceability.

LAYOUT: Vehicle header (plate, category, document status chips) → Current driver card → "Reassign" action → Driver picker (filtered to owner's own roster, KYC-approved only) → Effective-time selector (immediate / on-next-trip-completion) → Confirm.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| new_driver_id | select | must be KYC-approved and belong to this fleet owner | "Select an approved driver from your fleet" |
| effective_mode | radio | required | "Choose when this reassignment takes effect" |

STATES: idle / driver-picker-open / confirming / reassignment-scheduled (if effective_mode = on-completion, vehicle shows a "pending reassignment" badge) / reassigned.

EDGE CASES:
- Vehicle has an active in-progress trip at the moment "immediate" reassignment is requested — request is downgraded automatically to on-next-trip-completion with the owner explicitly informed, never forcibly reassigned mid-trip (the in-progress trip must complete under its original driver/vehicle/insurance record of truth, matching the Section 9A.2 principle for driver suspension).
- Target driver has a document expiring before the reassignment's effective time — reassignment is blocked with a specific message naming the expiring document, not a generic failure.
- Vehicle is reassigned away from a driver who has a pending payout balance tied to trips on that vehicle — payout settlement (Section 6/12A) is entirely independent of the vehicle assignment and is unaffected by this action.

API(S) CALLED: GET /fleet/v1/vehicles/{id}, POST /fleet/v1/vehicles/{id}/reassign { new_driver_id, effective_mode }.

PERMISSIONS REQUIRED: fleet.vehicle.reassign, scoped to the requesting owner's own fleet only (an owner can never reassign another fleet's vehicle — enforced server-side, not just hidden in the UI).

ANALYTICS EVENTS FIRED: VehicleReassigned, VehicleReassignmentScheduled.

ACCEPTANCE CRITERIA:
- [ ] An in-progress trip is never interrupted by a reassignment request; the request is queued to take effect on completion.
- [ ] A reassignment to a driver with an expiring/expired required document is blocked with a specific, actionable reason.
- [ ] Every trip's earnings record retains the driver/vehicle pairing that was actually active at trip time, immune to later reassignment.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Immediate reassignment requested mid-trip | Auto-downgraded to on-completion, owner informed |
| Target driver's license expires before effective time | Blocked with specific document-expiry message |
| Reassignment after trip completes | Vehicle now shows new driver; old trip's record unchanged |
```

---

## 15A. MARKETING & COUPON ENGINE — TIER 1 DEEP DIVE

### 15A.1 Coupon Creation & Redemption-Cap Enforcement

```
SCREEN: Coupon Create/Edit
MODULE: Marketing — Coupon Engine
PURPOSE: Let Marketing define a coupon with hard, server-enforced usage caps that cannot be exceeded even under concurrent redemption attempts.

LAYOUT: Code + description → Discount type (flat/percent) + value + max-discount-cap (for percent type) → Eligibility rules (min order value, applicable categories/zones, user segment, first-trip-only toggle) → Usage limits (per-user cap, global cap) → Validity window → Stacking rule (can combine with subscription discount: yes/no) → Preview-fare-impact panel (shows a sample fare before/after) → Save/Activate.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| code | text | unique, alphanumeric, 4–20 chars | "This code is already in use" |
| discount_value | number | > 0; if percent, ≤ 100 | "Enter a valid discount value" |
| max_discount_cap | currency | required if type=percent | "Set a maximum discount cap" |
| global_usage_cap | integer | ≥ 1 if set | "Cap must be at least 1" |
| valid_from / valid_to | date | to > from | "End date must be after start date" |

STATES: draft / active / paused / expired / usage-cap-reached (auto-transitions here the instant the global cap hits, no separate "deactivate" step needed).

EDGE CASES (redemption-time, ties to Section 2.2.5's client-facing edge case):
- Two customers redeem the last remaining global-cap slot simultaneously — a DB-level atomic counter (not a read-then-write check in application code) ensures exactly one succeeds; the other gets a clear "This coupon has just reached its usage limit" at apply-time, not a confirmed-then-reversed booking.
- A coupon is paused by Marketing while a customer has it applied to an in-progress (not yet confirmed) quote — the already-generated quote_id honors the coupon through its TTL (Section 2.2.5 rule); only new quote requests are denied the paused coupon.
- Stacking rule conflict: a subscription-exempt-from-surge benefit (Section 19) and a coupon both apply to the same booking — the explicit stacking flag on this coupon resolves it; if the flag says "no stacking," the system applies whichever benefit is more valuable to the customer by default (never silently picks the one more favorable to the platform) unless Marketing has explicitly configured otherwise.

API(S) CALLED: POST /admin/v1/coupons, PUT /admin/v1/coupons/{id}, GET /admin/v1/coupons/{id}/usage-stats.

PERMISSIONS REQUIRED: marketing.coupon.manage.

ANALYTICS EVENTS FIRED: CouponCreated, CouponActivated, CouponUsageCapReached, CouponRedeemed (fired at actual redemption, consumed by Finance Section 12 for discount-liability reporting).

ACCEPTANCE CRITERIA:
- [ ] Global and per-user usage caps are enforced by an atomic DB constraint, never a race-prone application-level check (verified under concurrent-redemption load test).
- [ ] Coupon spend is fully attributable in Finance reporting (Section 12) as a distinct discount-liability line, reconciled against actual redemption counts (Section 15 original acceptance criteria).
- [ ] Pausing/expiring a coupon never retroactively invalidates an already-locked quote.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Two customers redeem last global-cap slot simultaneously | Exactly one succeeds |
| Coupon paused while a quote using it is still within TTL | Quote still honors it; new quotes denied |
| Coupon reaches global cap | Auto-transitions to usage-cap-reached, no manual step needed |
```

---

## 17B. RATINGS & REVIEWS — TIER 1 DEEP DIVE

### 17B.1 Rate Driver / Low-Rating Escalation

```
SCREEN: Rate Driver (Customer App, post-trip)
MODULE: Ratings & Reviews
PURPOSE: Capture a trustworthy, tamper-evident rating and automatically route low ratings into a human-reviewable queue rather than letting them silently average away a safety signal.

LAYOUT: Star selector (1–5, required to proceed) → Conditional tag chips (surfaced only below a threshold, e.g., ≤3 stars: "Late," "Rude," "Unsafe driving," "Damaged goods," "Overcharged"; above threshold: "On time," "Careful handling," "Friendly") → Optional free-text comment → Submit.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| stars | 1–5 integer | required | "Select a rating to continue" |
| tags | multi-select | optional, max 3 | — |
| comment | text | optional, max 500 chars | — |

STATES: unrated / stars-selected (tags reveal) / submitting / submitted (thank-you state) / already-rated (screen re-entered after already rating — shows the submitted rating read-only, not a blank form again) / rating-window-closed (trip too old, Section 17 config) → still accepts but flags as late.

EDGE CASES:
- Rating submitted after the rating window closes — accepted but tagged late, excluded from real-time dispatch-scoring recalculation (Section 4/17 rule) so it can't be used to game live matching, but still visible to the driver's historical record and to Support/Fraud if patterns emerge.
- A ≤2-star rating (config threshold) with a safety-related tag ("Unsafe driving") auto-creates a review flag routed to the Fraud/Safety queue (Section 17A.1) in addition to the normal Support low-rating flag — these are distinct downstream consumers of the same event, not a single path that might miss the safety escalation.
- User attempts to rate a booking_id that isn't theirs (tampered client request) — server validates the rater is a genuine party to that specific booking before accepting, rejecting otherwise with no information leak about whether the booking_id even exists.
- User attempts to submit a second rating for the same trip — rejected (or treated as a no-op update within an edit window, per product decision — must be explicit either way, not silently creating a duplicate rating row that skews the average).

API(S) CALLED: POST /v1/bookings/{id}/rate { stars, tags[], comment }.

PERMISSIONS REQUIRED: none beyond being a genuine party to the booking (server-validated).

ANALYTICS EVENTS FIRED: RatingSubmitted, LowRatingFlagRaised (conditional), SafetyTagFlagRaised (conditional, feeds Section 17A.1).

ACCEPTANCE CRITERIA:
- [ ] Every individual rating is retained (never just an incrementally-updated mutable average with no per-rating audit trail, Section 17 rule).
- [ ] A rating for a booking the rater isn't party to is rejected server-side regardless of client tampering.
- [ ] Safety-tagged low ratings reach the Fraud/Safety queue (17A.1) within one processing cycle of submission.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Submit 1-star with "Unsafe driving" tag | Routes to both Support low-rating flag and Fraud/Safety queue |
| Submit rating after window closes | Accepted, tagged late, excluded from live dispatch scoring |
| Crafted request rating a booking not belonging to rater | Rejected, no information leak |
| Attempt second rating on same trip | Rejected or explicit edit-in-window, never a silent duplicate |
```

---

## 18A. REFERRAL SYSTEM — TIER 1 DEEP DIVE

### 18A.1 Referral Reward Trigger & Fraud-Ring Hold

```
FLOW: Referral qualifying-action reward issuance
MODULE: Referral System ↔ Fraud Detection (Section 17)
PURPOSE: Reward genuine referrals promptly while holding suspicious clusters for review before payout, per Section 18's fraud-control requirement.

TRIGGER: Referee completes their qualifying action (config: first completed trip, not just signup).

STEP 1: TripCompleted event (Section 22) checked for an associated pending referral_id on the referee's account.
STEP 2: Fraud pre-check runs synchronously before reward issuance: device-fingerprint and payment-instrument-fingerprint cross-reference against the referrer's own fingerprints and against any existing cluster of "distinct" referred accounts sharing signals.
STEP 3: If clean → reward credited to both referrer and referee wallets (Section 6) immediately as tagged promotional-credit transactions, referral marked FULFILLED.
STEP 4: If flagged → reward issuance held (referral marked PENDING_REVIEW), routed to the Fraud Queue (Section 17A.1) with the specific cluster evidence attached, referrer/referee not notified of a decline yet (avoid tipping off active fraud rings while under review) but also not left in indefinite silence — subject to the same max-silent-hold-duration rule as any other fraud hold.
STEP 5: Fraud analyst clears → reward issues on clearance with the original trigger timestamp preserved (referee doesn't need to take any new action); Fraud analyst confirms abuse → referral marked VOID, no reward issued, both accounts' cluster membership logged for future cross-referencing.

EDGE CASES:
- Referee's qualifying trip is later cancelled/refunded after the reward already issued — a reward-reversal flow triggers, explicitly disclosed to the affected users (never a silent wallet debit with no explanation entry) with an appeal path via Support (Section 11A).
- Duplicate TripCompleted event delivery (Section 22 at-least-once) must not double-issue the reward — idempotent on referral_id, enforced by a unique constraint (one FULFILLED transition per referral_id).
- Referrer's own account is later found fraudulent after having already earned rewards from multiple genuine referees — those referees' own rewards are not automatically clawed back (they did nothing wrong); only the referrer's ill-gotten rewards are subject to reversal.

ACCEPTANCE CRITERIA:
- [ ] Reward issuance is idempotent per referral_id under duplicate event delivery (Section 18 original acceptance criteria).
- [ ] No referral hold exceeds its configured maximum silent duration without surfacing to a human reviewer (same rule as Section 17).
- [ ] Every reward and every reversal is traceable in the Wallet ledger with a referral_id tag.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Duplicate TripCompleted event for same referral | Only one reward issued |
| Referee's qualifying trip refunded post-reward | Explicit reversal with disclosed reason, appeal path available |
| Fraud-ring cluster detected pre-issuance | Held, routed to Fraud Queue, not silently declined |
| Referrer later found fraudulent | Referrer's rewards reversible; innocent referees' own rewards untouched |
```

---

## 19A. LOYALTY & SUBSCRIPTIONS — TIER 1 DEEP DIVE

### 19A.1 Subscription Renewal & Dunning

```
FLOW: Subscription auto-renewal with failed-payment handling
MODULE: Loyalty & Subscriptions ↔ Wallet & Payments (Section 6)
PURPOSE: Attempt renewal against the user's default payment method with clear, staged communication before benefits lapse — never a silent cutoff.

STEP 1: Renewal date reached → charge attempt against default payment method (Section 6 gateway integration).
STEP 2 (success): subscription period extended, receipt sent (Section 16, transactional-exempt from opt-out), benefits continue uninterrupted.
STEP 2 (failure): subscription enters a grace period (config, e.g., 3 days) during which benefits remain active; user notified immediately via push + email with a direct "update payment method" deep link.
STEP 3: Retry attempts per a defined schedule (e.g., day 1, day 2, day 3 of grace period) — each failure sends a reminder, escalating in urgency copy but never in channel-spam frequency beyond the defined cadence.
STEP 4: Grace period expires with no successful charge → benefits lapse (surge-exemption, fee waiver, etc. stop applying to new bookings from this point), subscription status set to LAPSED (not silently cancelled — user can still reactivate by updating payment method, distinct flow from a fresh purchase).
STEP 5: If user updates payment method and manually retries within a secondary window (config, e.g., 14 days from lapse) → subscription reinstated retroactive to the original renewal date (no gap in perceived continuity) rather than starting a fresh period from the reactivation date, unless the elapsed time makes that impractical (config threshold).

EDGE CASES:
- A booking is made during the grace period (benefits still active) but completes after the grace period has since expired mid-trip due to a failed retry — the benefit that applied at booking-quote time (Section 5's quote_id-locks-the-terms rule) still honors for that specific booking; only bookings quoted after the lapse lose the benefit.
- User has an active coupon stacking rule (Section 15A) that assumed an active subscription — if the subscription lapses between coupon-apply and booking-confirm, the stacking recalculates at confirm-time using current subscription status, and the customer is shown the updated total transparently before final confirmation (never silently charged more than what was shown).
- User cancels subscription proactively (not a payment failure) — no dunning flow triggers; benefits continue through the already-paid period end, then stop cleanly, no grace/retry logic applies since there's no failed charge to recover.

ACCEPTANCE CRITERIA:
- [ ] No subscription benefit is ever cut off without the staged grace-period notification sequence having completed (except proactive cancellation, which is a different, cleaner path).
- [ ] Every subscription benefit applied to a fare is itemized in the fare_breakdown exactly like a coupon discount (Section 19 original acceptance criteria).
- [ ] Reactivation within the secondary window restores continuity without a perceptible gap to the user, and this behavior is explicit in copy shown at reactivation.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Renewal charge fails | Grace period starts, staged notifications sent, benefits continue |
| Grace period expires with no successful retry | Benefits lapse, status=LAPSED, reactivation still possible |
| Booking quoted during grace, completes after lapse | Original quote's benefit still honored for that booking |
| User reactivates within secondary window | Continuity restored, no perceived gap |
```

---

## 20A. ANALYTICS DASHBOARDS — TIER 1 DEEP DIVE

### 20A.1 Booking Funnel Dashboard

```
SCREEN: Booking Funnel Dashboard
MODULE: Analytics
PURPOSE: Give Product/Ops a trustworthy, precisely-defined view of where customers drop off between opening the app and completing a trip, without querying production OLTP directly.

LAYOUT: Date-range + city/zone filter → Funnel visualization (Home Viewed → Address Entered → Quote Shown → Booking Confirmed → Driver Assigned → Trip Completed), each stage showing absolute count and conversion-from-previous-stage % → Drop-off detail drill-down per stage (e.g., clicking "Quote Shown → Booking Confirmed" drop-off shows top reasons: no-driver-found, price-abandonment inferred from time-on-screen, app-backgrounded).

FIELDS: date_range (required, max range config e.g. 90 days to protect query cost), city_filter (optional), vehicle_category_filter (optional).

STATES: loading / loaded / no-data-for-range / stale-data-warning (if the underlying analytics pipeline's last successful ingest is older than its freshness SLA, Section 20 rule — shown explicitly, never silently serving old data as if current).

EDGE CASES:
- A user who opens the app, gets a quote, backgrounds the app, and returns hours later to complete the same booking flow — funnel stage attribution uses a defined session-window (config, e.g., 30 min of inactivity ends a funnel session) so this doesn't get miscounted as two separate funnel entries or artificially inflate a single session's duration metrics.
- Definition change (e.g., "Quote Shown" redefined to require a minimum 1s render time to exclude flash-and-abandon renders) — the dashboard must version this definition and never silently apply the new definition retroactively to historical data without a visible annotation on the chart marking the definition-change date (Section 20's "documented, versioned metric definition" rule).
- Multi-stop bookings — funnel counts the booking once regardless of stop count, not once per stop, to avoid skewing conversion rates for multi-stop-heavy zones.

API(S) CALLED: GET /analytics/v1/funnels/booking?from&to&city&category (reads from the OLAP store, Section 20, never the OLTP database directly).

PERMISSIONS REQUIRED: analytics.view (read-only role available broadly; underlying raw event access more restricted per Section 22 RBAC granularity).

ANALYTICS EVENTS FIRED: n/a (this screen consumes events, Section 22 catalog, rather than firing new ones — though DashboardViewed may be logged for internal usage analytics).

ACCEPTANCE CRITERIA:
- [ ] Every funnel stage has a documented, versioned definition visible from the dashboard itself (e.g., a tooltip or linked definitions page), not tribal knowledge.
- [ ] Data freshness is displayed on-screen and the dashboard clearly flags when the underlying pipeline is stale beyond its SLA (Section 20 rule).
- [ ] Dashboard queries never hit the production OLTP database (verified via query-source audit, protecting Dispatch/Booking performance per Section 1's service-independence principle).

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| User resumes an abandoned quote after >30 min | Counted as a new funnel session, not double-counted within one |
| Underlying pipeline ingest is stale | Dashboard shows explicit stale-data warning, not silent old data |
| Multi-stop booking | Counted once in funnel, not once per stop |
```

---

## 22A. ROLE & PERMISSION MANAGEMENT — TIER 1 DEEP DIVE

### 22A.1 Role Edit — Permission Matrix

```
SCREEN: Role Edit
MODULE: RBAC (Admin)
PURPOSE: Let a Super Admin define exactly which permissions a role grants, with changes taking effect immediately and consistently across every already-logged-in session holding that role.

LAYOUT: Role name/description → Permission matrix (resource rows × action columns, e.g., pricing.[view|edit], driver.[view|suspend|reinstate], refund.[approve_under_limit|approve_over_limit]) checkboxes → Scope configuration (global vs city-scoped — if city-scoped, a city-multi-select appears) → Save.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| role_name | text | unique, required | "This role name is already in use" |
| permissions | checkbox matrix | at least one permission for a non-empty role | "Select at least one permission" |
| scope_cities | multi-select | required if scope_type = city-scoped | "Select at least one city for a city-scoped role" |

STATES: loading / editing (dirty-state) / saving / saved / validation-error (e.g., attempting to remove a permission that would leave zero Super Admins able to manage roles at all — a hard-block guard rail, Section 22).

EDGE CASES:
- Editing the Super Admin role itself to remove role-management permissions entirely — blocked with an explicit guard-rail error, preventing platform lockout (there must always be at least one role capable of role management).
- A user currently logged in has their role's permissions reduced mid-session — the next API call they make re-validates against the current (reduced) permission set server-side; their existing session token remains valid for authentication, but authorization is re-checked per-request, so they lose access to the removed capability on their very next action, not just on next login (Section 22 original edge case).
- A city-scoped role's city list is edited to remove a city where that role's user currently has in-progress work (e.g., an open Support ticket queue filtered to that city) — access to already-open records in the removed city is revoked going forward; in-progress items they were assigned are automatically flagged for reassignment to a remaining in-scope admin rather than left orphaned with no one able to act on them.

API(S) CALLED: GET /admin/v1/roles/{id}, PUT /admin/v1/roles/{id}, GET /admin/v1/roles/{id}/audit.

PERMISSIONS REQUIRED: rbac.role.manage (typically restricted to Super Admin only).

ANALYTICS EVENTS FIRED: RolePermissionsChanged (with before/after diff — feeds Section 24 audit_log).

ACCEPTANCE CRITERIA:
- [ ] It is structurally impossible to save a role configuration that leaves zero users capable of managing roles.
- [ ] Permission reduction takes effect on the affected user's very next API call, not merely on next login (Section 22 original acceptance criteria — a permission-matrix test suite verifies both the positive and negative case at the API layer).
- [ ] Removing a city from a scoped role's coverage triggers reassignment surfacing for any in-progress work orphaned by the change, rather than leaving it silently stuck.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Attempt to strip role-management from the last capable role | Blocked with guard-rail error |
| User's role permission reduced mid-session | Next API call reflects reduced access, not next login |
| City removed from a scoped role with in-progress assigned work there | Work flagged for reassignment, not orphaned |
```

---

## 2A. CUSTOMER APP — ADDITIONAL TIER 1 DEEP DIVES

### 2A.1 Cancel Trip + Cancellation Fee (Screens 35–37)

```
SCREEN: Cancel Trip Flow
MODULE: Customer App — Core
PURPOSE: Let a customer cancel pre-completion while enforcing a fair, transparent, config-driven cancellation fee that depends on trip stage — never a surprise charge.

LAYOUT: Cancel entry point (visible from Search/Assigned/EnRoute/Arrived states, Section 2.2.6/2.2.7) → Reason picker (fixed taxonomy: "Booked by mistake," "Price too high," "Driver taking too long," "Found another option," "Other") → Fee notice panel (shown BEFORE final confirm, computed live from current trip stage — e.g., free if within grace window post-booking, free if driver hasn't moved toward pickup yet per config, fee applies if driver is already en route past a distance/time threshold, higher fee if driver has arrived and waited past the free-wait grace period) → Confirm Cancel (names the fee amount explicitly if non-zero).

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| reason_code | radio | required | "Select a reason to continue" |
| note | text | optional if reason=Other is not selected; required if Other, max 300 chars | "Add a note" |

STATES: cancel-entry → reason-selection → fee-computed (server-quoted, not client-estimated) → confirming → cancelled (fee charged to original payment method / wallet if applicable) → cancel-failed (network/server error — trip remains active, customer explicitly told cancellation did NOT go through, never left ambiguous).

EDGE CASES:
- Customer taps Cancel at the exact moment the driver taps "Arrived" server-side — the fee computation must use the server's authoritative trip-stage state at the instant the cancel request is processed, not a client-cached stage that might be a few seconds stale; if this produces a fee the customer didn't see quoted, the confirm step must re-show the updated fee before finalizing (never silently charge a different amount than what was shown).
- Driver cancels first (Section 3, driver-side cancellation) while the customer's cancel request is in flight — server resolves this as a race with a defined precedence rule (whichever cancellation reaches the server first wins; the other party's request is met with "This trip was already cancelled" rather than a duplicate cancellation or double fee).
- Cancellation fee charge itself fails (e.g., expired card, insufficient wallet balance) — trip cancellation still proceeds (the trip is not held hostage to fee collection succeeding), but the fee becomes an outstanding balance on the account that blocks new bookings until settled or is retried per Section 6's payment-reconciliation job, communicated clearly to the customer.
- Repeated last-minute cancellations by the same customer (pattern) — feeds a fraud/abuse signal (Section 17) for review, not a hard block on this screen itself.

API(S) CALLED: POST /v1/bookings/{id}/cancel { reason_code, note? } → 200 { fee_charged, fee_amount } or 409 { already_cancelled_by: "driver" }.

PERMISSIONS REQUIRED: none beyond being the booking's customer (server-validated).

ANALYTICS EVENTS FIRED: TripCancelledByCustomer (with reason_code, trip_stage_at_cancel, fee_amount).

ACCEPTANCE CRITERIA:
- [ ] The fee shown immediately before final confirm is always server-computed from the authoritative trip stage at that moment, never a stale client estimate silently charged.
- [ ] A race between customer- and driver-initiated cancellation resolves deterministically with no double-fee and a clear message to the losing party.
- [ ] A failed fee charge never blocks the cancellation itself from completing.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Cancel exactly as driver marks Arrived | Fee reflects Arrived-stage rate, re-confirmed before charge |
| Customer and driver cancel simultaneously | One wins, other sees "already cancelled," no double fee |
| Fee charge fails (expired card) | Trip still cancels; outstanding balance flagged, blocks new bookings until resolved |
```

### 2A.2 Wallet Add Money

```
SCREEN: Wallet Add Money
MODULE: Wallet & Payments (Customer-facing)
PURPOSE: Let a customer top up their wallet via a gateway-tokenized payment method, with the credit only ever applied on server-confirmed success — never on a client-reported success.

LAYOUT: Current balance header → Preset amount chips (config-driven, e.g., ₹100/₹500/₹1000) + custom amount input → Payment method selector (saved methods + "Add new") → Pay button.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| amount | currency | ≥ config minimum, ≤ config maximum per single top-up | "Enter an amount between [min] and [max]" |
| payment_method | select | required | "Select a payment method" |

STATES: idle → amount-entered → method-selected → initiating (creates a PENDING wallet_transaction + calls gateway) → gateway-redirect/3DS-challenge (if applicable) → awaiting-webhook-confirmation (spinner with explicit "confirming your payment" copy, not a bare spinner) → success (balance updates, only after webhook/verify-call confirms, per Section 6 rule) → failed (clear reason if available, e.g., "Payment declined by your bank") → ambiguous-pending (gateway response unclear/timed out — see edge case below).

EDGE CASES:
- App is killed/backgrounded during the gateway redirect (common on mobile UPI intents) and the user returns later — on next app open, the client checks the PENDING transaction's current status via a status-poll endpoint rather than assuming failure or re-initiating a duplicate charge; if the webhook already confirmed while the app was backgrounded, balance reflects it immediately on return.
- Webhook is delayed beyond a reasonable UI-wait threshold (config, e.g., 15s) — UI transitions to an explicit "still confirming, we'll notify you" state rather than an indefinite spinner or a false failure message; a background reconciliation job (Section 6/25) resolves any transaction still PENDING past a longer threshold by directly polling the gateway.
- Duplicate webhook delivery for the same gateway transaction — idempotent on gateway_ref, must not double-credit the wallet (Section 6 rule).
- User initiates a second add-money attempt while a first is still PENDING — allowed (not blocked), but both are tracked as distinct transaction_ids so neither confirmation can be misattributed to the other.

API(S) CALLED: POST /v1/wallet/add-money { amount, payment_method_id } → { transaction_id, gateway_session }, GET /v1/wallet/transactions/{id}/status (client polling fallback), webhook (server-to-server, not client-visible) POST /internal/v1/payments/webhook.

PERMISSIONS REQUIRED: none (self wallet only).

ANALYTICS EVENTS FIRED: WalletTopUpInitiated, WalletTopUpSucceeded, WalletTopUpFailed.

ACCEPTANCE CRITERIA:
- [ ] Wallet balance only ever increases on a server-side confirmed (webhook or verify-call) success — never on a client-reported "payment succeeded" callback alone (Section 6 rule, hard requirement).
- [ ] Duplicate webhook delivery for one transaction cannot double-credit (idempotent on gateway_ref).
- [ ] Any transaction left PENDING beyond the reconciliation threshold is resolved automatically (Section 6/25), never left ambiguous indefinitely.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| App killed mid-UPI-redirect, reopened later | Status polled, correct outcome reflected, no duplicate charge |
| Duplicate webhook for same gateway_ref | Wallet credited exactly once |
| Webhook delayed past UI threshold | "Still confirming" state shown, not false failure |
```

---

## 3A. DRIVER APP — ADDITIONAL TIER 1 DEEP DIVE

### 3A.1 Document Center — Expiry Alert & Re-upload

```
SCREEN: Document Center
MODULE: Driver App — Documents
PURPOSE: Give the driver clear, escalating visibility into document expiry status and a frictionless re-upload path, with job-eligibility automatically gated by document validity (Section 3.2 rule).

LAYOUT: Document list (RC, Insurance, Permit/Fitness, PUC, Driving License), each row showing status chip (Valid / Expiring Soon [color-coded by days remaining] / Expired) and expiry date → Tap row → Re-upload flow (same capture pattern as onboarding, Section 3.2 step 4) → Submission confirmation → Pending-review state.

FIELDS: (per document) new_document_photo (required), new_expiry_date (required, must be future-dated, must be ≥ old expiry date unless explicitly a renewal-with-different-term document type).

STATES: valid / expiring-soon (30/15/7/1-day tiers, each tier triggering a fresh push+SMS alert per Section 16, not just a re-display of the same one) / expired (job-eligibility auto-suspended the moment this state is entered, Section 3.2 acceptance criteria) / re-upload-in-progress / pending-review (server-side, same reviewer queue as onboarding KYC, Section 7) / re-approved (status returns to valid, job-eligibility auto-restored) / re-rejected (structured reason from the same taxonomy, Section 3.2).

EDGE CASES:
- Document expires while the driver has an active in-progress trip — job-eligibility suspension applies to *new* job offers only; the current trip is allowed to complete (identical principle to Section 9A.2's suspension rule), the driver is alerted but not stranded mid-delivery.
- Driver re-uploads a document that gets rejected again for the same reason twice in a row — the third submission auto-escalates to a supervisor-tier reviewer rather than looping the driver through an indefinite reject cycle with no path forward.
- Driver's document expiry date entered at re-upload doesn't match what OCR extracts from the uploaded photo — flagged as a mismatch for reviewer attention (non-blocking to submission, per Section 3.2's OCR-cross-check pattern) but the driver-facing status still shows pending-review, not a false valid state, until a reviewer resolves the mismatch.

API(S) CALLED: GET /v1/driver/documents, POST /v1/driver/documents/{type}/reupload, GET /v1/driver/documents/{type}/status.

PERMISSIONS REQUIRED: none (self documents only).

ANALYTICS EVENTS FIRED: DocumentExpiringSoon (per tier), DocumentExpired, DocumentReuploaded, DocumentReapproved, DocumentReRejected.

ACCEPTANCE CRITERIA:
- [ ] Job-eligibility suspension on expiry occurs within one polling cycle (Section 3.2, max 15 min) and reinstates automatically on re-approval with no manual admin step required for the standard path.
- [ ] An in-progress trip is never interrupted by a mid-trip document expiry.
- [ ] Repeated same-reason rejections auto-escalate rather than looping indefinitely.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Document expires mid-active-trip | Trip completes; new job offers blocked after |
| Document re-approved | Job-eligibility restored automatically, no admin step needed |
| Same document rejected twice for same reason | Third submission auto-escalates to supervisor reviewer |
```

---

## 10A. OPERATIONS / CONTROL ROOM — TIER 1 DEEP DIVE

### 10A.1 SOS Event Handling

```
SCREEN: SOS Alert (Control Room)
MODULE: Ops / Control Room — Safety
PURPOSE: Guarantee an SOS trigger from either app reaches a human operator within a hard latency bound and cannot be dismissed without a documented resolution — this is the single highest-priority screen in the entire platform.

LAYOUT: Dedicated always-visible alert rail (never buried in a general notification feed) — on trigger: full-screen-takeover-style alert (sound + visual, cannot be dismissed by a stray click) showing triggering party (customer/driver), live location (continuously streamed for the SOS duration regardless of normal tracking-sharing preferences, Section 10 rule), trip/booking context, one-tap actions (Call Triggering Party via masked line, Call Emergency Services info panel, Notify Emergency Contact [if configured, Section 2.1 Screen 54], Escalate to Safety Team Lead) → Mandatory Resolution panel (cannot be closed without it): resolution_note (required) + outcome tag (False Alarm / Resolved-Safe / Escalated-to-Authorities / Other).

FIELDS (Resolution):
| Field | Type | Validation | Error copy |
|---|---|---|---|
| outcome_tag | radio | required | "Select an outcome before closing this alert" |
| resolution_note | text | required, min 20 chars (forces a real note, not a one-word dismissal) | "Add a detailed resolution note" |

STATES: triggered (unacknowledged — pages the on-duty operator, escalates to a secondary operator if unacknowledged past a hard threshold, e.g., 30s) → acknowledged (operator viewing, live location streaming) → in-progress (operator taking action, e.g., on a call) → resolution-pending (actions taken, awaiting mandatory note) → closed.

EDGE CASES:
- No operator acknowledges within the hard threshold — auto-escalates to a secondary on-call operator AND triggers a distinct louder/broader alert tier (e.g., SMS to a safety-team distribution list), never silently waiting indefinitely for the first operator.
- SOS triggered mid-trip by the customer, and the driver's app independently also shows signs of an issue (e.g., trip stalled, no GPS movement) — these are correlated and shown together in one incident view rather than as two disconnected alerts an operator might handle inconsistently.
- SOS falsely triggered (e.g., accidental tap) — the False Alarm outcome tag still requires the full resolution note (a false alarm is still logged with the same rigor, both to build a false-positive-rate metric per Section 17's pattern and because a "false alarm" claim itself should be reviewable later if a pattern of same-user false alarms emerges).
- Trigger party's connectivity drops immediately after triggering (location stream stops) — the last-known location remains pinned and clearly timestamped/flagged as stale in the operator view, never silently disappearing from the map.

API(S) CALLED: (server-pushed to Control Room via WebSocket, not polled) SOSTriggered event consumed live; POST /ops/v1/sos/{id}/acknowledge, POST /ops/v1/sos/{id}/resolve { outcome_tag, resolution_note }.

PERMISSIONS REQUIRED: ops.sos.respond (broadly granted to on-duty Control Room staff); ops.sos.escalate for secondary-tier actions.

ANALYTICS EVENTS FIRED: SOSTriggered, SOSAcknowledged, SOSAutoEscalated (if threshold missed), SOSResolved.

ACCEPTANCE CRITERIA:
- [ ] An SOS event reaches an available operator's screen within 5s p95 of trigger (Section 10 original acceptance criteria) and auto-escalates if unacknowledged within the hard threshold.
- [ ] No SOS event can be closed without a mandatory resolution note and outcome tag, regardless of how the situation was actually resolved.
- [ ] Location streaming for an active SOS is never gated by the triggering user's normal privacy/tracking-sharing preference — safety overrides that setting for the SOS duration only.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| No operator acknowledges within 30s | Auto-escalates to secondary operator + broader alert |
| Attempt to close alert with no resolution note | Blocked |
| Triggering party's connectivity drops | Last-known location stays pinned, flagged stale |
| False-alarm outcome selected | Full resolution note still required |
```

---

## 9B. ADMIN DASHBOARD — CMS DEEP DIVE

### 9B.1 CMS Banner Publish (Preview-Before-Publish)

```
SCREEN: CMS Banner Editor
MODULE: Admin — CMS
PURPOSE: Let Marketing/Admin manage the Customer App Home promo carousel (Section 2.2.3) content safely, with a mandatory preview step so a broken/misconfigured banner never reaches production users directly from a save action.

LAYOUT: Banner list (draft/scheduled/live/expired, sortable by start date) → Create/Edit form (image upload, headline, CTA text + deep-link target, target segment [Section 15's segment builder] or "all users," start/end date-time) → Preview panel (renders exactly as it will appear on Customer App Home, at actual device aspect ratios, before any publish action) → Publish (goes live at start date, immediately if start date = now).

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| image | file | required, max size, min resolution per device-density requirements | "Upload an image meeting the minimum resolution" |
| headline | text | required, max 60 chars (enforced against actual card layout constraints, not arbitrary) | "Headline is too long for the banner layout" |
| cta_deep_link | url/deep-link | must resolve to a valid in-app route or an allow-listed external URL | "This link doesn't resolve to a valid destination" |
| start_date/end_date | date-time | end > start | "End date must be after start date" |

STATES: draft / preview-open / scheduled (future start_date) / live / expired / publish-blocked (validation failure, e.g., broken deep link caught by an automated link-check before publish is even allowed, not discovered after users start tapping a dead banner).

EDGE CASES:
- CTA deep-link targets an in-app screen that requires the user to be in a specific state to make sense (e.g., a "Track your trip" banner shown to a user with no active trip) — the deep-link resolution must gracefully redirect to a sensible fallback (e.g., Home) rather than crashing or showing a broken/blank screen if the target state doesn't apply to the viewing user.
- Two banners are scheduled with overlapping date ranges and both target "all users" — the carousel ordering/priority rule (config: explicit priority field, or most-recently-created-wins) must be deterministic and visible to the editor at scheduling time, not an undefined collision.
- A banner is published, then the linked coupon it promotes (Section 15A) is separately paused by Marketing — the banner itself doesn't auto-detect this; a lint/health-check job (Section 26 background jobs) periodically verifies live banners' linked entities (coupons, deep-link targets) are still valid and flags broken ones to the CMS editor rather than silently leaving a dead promotion live.

API(S) CALLED: POST /admin/v1/cms/banners, PUT /admin/v1/cms/banners/{id}, POST /admin/v1/cms/banners/{id}/publish, POST /admin/v1/cms/banners/{id}/validate-link (pre-publish check).

PERMISSIONS REQUIRED: marketing.cms.manage.

ANALYTICS EVENTS FIRED: BannerPublished, BannerLinkValidationFailed.

ACCEPTANCE CRITERIA:
- [ ] A banner with a broken/unresolvable deep-link cannot be published — caught by automated validation, not discovered via user complaints.
- [ ] Every published banner is previewed at actual rendering dimensions before publish is possible (no blind-publish path exists).
- [ ] A periodic health-check flags live banners whose linked entities (coupons, targets) have since become invalid.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Publish with a broken deep-link | Blocked by pre-publish validation |
| Two overlapping "all users" banners scheduled | Deterministic priority order shown at scheduling time |
| Linked coupon paused after banner goes live | Health-check flags the banner for editor attention |
```

---

## 14B. CORPORATE / B2B PORTAL — EMPLOYEE MANAGEMENT DEEP DIVE

### 14B.1 Employee Roster — Invite/Remove & Per-User Booking Limits

```
SCREEN: Employee Management
MODULE: Corporate Portal
PURPOSE: Let a corporate account admin control who can book against the company account and cap individual exposure, independent of the account-wide credit limit (Section 14A.1).

LAYOUT: Employee list (name, email, status [Invited/Active/Removed], per-user monthly booking cap, role [Employee/Account Admin]) → Invite form (email, per-user cap, role) → Row actions (edit cap, promote to admin, remove).

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| email | email | required, valid format, not already on this account's roster | "This email is already part of your team" |
| per_user_monthly_cap | currency | optional; if set, ≤ account's overall credit limit | "Per-user cap cannot exceed the account credit limit" |
| role | radio | required | "Select a role" |

STATES: list-loaded / invite-modal-open / inviting (email sent, status=Invited) / invite-accepted (employee completes signup/linking on their end, status=Active) / editing-cap / removing-confirm / removed.

EDGE CASES:
- An employee is removed from the roster while they have a recurring/scheduled booking (Section 14) set up under their own creation — ownership must transfer automatically to an account admin (Section 14 original edge case) rather than the recurring booking silently stopping or orphaning with no owner.
- An employee is removed mid-active-trip (booked before removal) — the in-progress trip is unaffected and completes normally; removal only prevents *future* bookings from that employee against the company account.
- The last remaining Account Admin attempts to demote themselves to Employee role or remove their own admin access — blocked with a guard-rail identical in spirit to Section 22A.1's "can't lock out all role managers" rule, since a corporate account with zero admins has no one able to manage it going forward.
- Per-user cap is set below what an employee has already committed this month via in-flight reservations (Section 14A.1) — the new cap applies only to *future* bookings; already-reserved amounts are honored through completion, never retroactively invalidated.

API(S) CALLED: GET /corporate/v1/{account_id}/employees, POST /corporate/v1/{account_id}/employees/invite, PUT /corporate/v1/{account_id}/employees/{id}, DELETE /corporate/v1/{account_id}/employees/{id}.

PERMISSIONS REQUIRED: corporate.account.manage (Account Admin role within that specific corporate account — scoped identically in principle to Section 22's city-scoping pattern, but scoped to account_id here).

ANALYTICS EVENTS FIRED: EmployeeInvited, EmployeeActivated, EmployeeRemoved, PerUserCapChanged.

ACCEPTANCE CRITERIA:
- [ ] Removing an employee who owns a recurring booking auto-transfers ownership rather than orphaning it.
- [ ] The last Account Admin cannot remove their own admin access, preventing an unmanageable account.
- [ ] A lowered per-user cap never retroactively invalidates already-reserved (Section 14A.1) spend for the current period.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Remove employee who owns a recurring booking | Ownership transfers to an account admin |
| Remove employee mid-active-trip | Trip completes normally |
| Last admin attempts self-demotion | Blocked with guard-rail error |
| Per-user cap lowered below already-reserved spend | Existing reservation honored; only future bookings capped |
```

---

## 3B. DRIVER APP — NAVIGATION & MULTI-STOP DEEP DIVE

### 3B.1 Navigation-to-Pickup/Drop with Multi-Stop Sequencing

```
SCREEN: Trip Navigation
MODULE: Driver App — Core Job Flow
PURPOSE: Guide the driver through an ordered sequence of stops (pickup, then N drops per Section 2.2.4's multi-stop) with unambiguous "what's next" state at every point, and correct handling when the driver deviates from the suggested route.

LAYOUT: Map (turn-by-turn or embedded external nav handoff, config per market) → Current-stop card (address, contact name/phone [masked], instructions/landmark note, distance/ETA) → Stop-sequence strip (all stops, current highlighted, completed checked off, upcoming greyed) → Primary action button (context-sensitive: "Arrived at Pickup" / "Start Trip" / "Arrived at Stop 2" / "Complete Delivery") → Reorder-stops action (if the platform allows driver-suggested reordering — config; if the route is fixed by the customer/dispatch, this is disabled and greyed with an explanatory tooltip, never silently missing with no explanation).

FIELDS: none directly editable by default beyond the primary action buttons; reorder (if enabled) is drag-and-drop with server re-validation of the resulting route before acceptance.

STATES: navigating-to-pickup → arrived-at-pickup (triggers OTP flow, Section 2.2.7) → loaded/trip-started → navigating-to-stop-N (for each stop in sequence) → arrived-at-stop-N (OTP or photo-proof per that stop's delivery preference, Section 2.2.7) → stop-N-complete → [repeat for remaining stops] → all-stops-complete → trip-summary (Screen "Trip Completion," Section 2.1).

EDGE CASES:
- Driver's live GPS position deviates significantly from the suggested route (e.g., takes a different road due to local knowledge/traffic) — the app must not treat this as an error state; ETA recalculates against actual position continuously (Section 8 rule), no blocking "off route" modal that interrupts driving.
- Driver attempts to mark a stop complete out of sequence (e.g., taps "Arrived at Stop 3" before Stop 2 is done) — blocked with a clear message; sequence integrity is enforced server-side, not just by hiding the button (a client with a modified request could otherwise skip a stop).
- A stop's address is found to be unreachable/inaccessible on arrival (e.g., gated community with no access, wrong address entirely) — driver has an explicit "Can't reach this location" flow distinct from a normal cancellation, which contacts the customer (masked chat/call, Section 2.2 comms) and, if unresolved within a grace period, escalates to Support (Section 11A) rather than leaving the driver stuck with no path forward.
- Connectivity loss mid-navigation — last-instruction/last-map-state persists locally so the driver isn't left with a blank screen; stop-completion actions taken offline queue and sync on reconnect, server-validated against sequence integrity on receipt (never blindly accepted out of order just because it was captured offline).

API(S) CALLED: GET /v1/driver/jobs/{id}/route, POST /v1/driver/jobs/{id}/stops/{stop_id}/arrive, POST /v1/driver/jobs/{id}/stops/{stop_id}/complete { otp? | photo_proof? }, POST /v1/driver/jobs/{id}/stops/reorder (if enabled).

PERMISSIONS REQUIRED: none beyond being the assigned driver for this job (server-validated).

ANALYTICS EVENTS FIRED: StopArrived, StopCompleted, RouteDeviationObserved (passive telemetry, not user-facing), UnreachableLocationReported.

ACCEPTANCE CRITERIA:
- [ ] Stop-completion sequence integrity is enforced server-side; no stop can be marked complete before its predecessors regardless of client state or tampering.
- [ ] Route deviation never blocks or interrupts the driver's ability to continue the job — it only affects ETA display.
- [ ] Offline-captured stop actions sync and validate correctly against sequence on reconnect, never silently lost or silently accepted out of order.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Attempt to complete Stop 3 before Stop 2 | Blocked server-side |
| Driver deviates from suggested route | ETA recalculates smoothly, no blocking modal |
| Stop marked complete while offline, synced later | Validated against sequence on reconnect |
| Unreachable address reported | Contact-customer flow triggers, escalates to Support if unresolved |
```

---

## 11B. SUPPORT PORTAL — CUSTOMER-FACING DEEP DIVE

### 11B.1 Support Ticket Create (Customer/Driver App, Screen 50)

```
SCREEN: Support Ticket Create
MODULE: Support (customer/driver-facing entry point)
PURPOSE: Pre-populate context automatically so the user never has to manually explain what booking/issue they mean, reducing back-and-forth and agent handling time.

LAYOUT: Issue category picker (fixed taxonomy: "Trip issue," "Payment/Wallet," "Account," "Driver/Vehicle complaint" [driver-app: "Customer complaint"], "Other") → Conditional linked-booking picker (auto-suggests the most recent relevant trip if category = Trip issue, but allows picking a different one or "not related to a specific trip") → Description text (required) → Photo/attachment upload (optional, e.g., damaged goods evidence) → Submit.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| category | radio | required | "Select a category" |
| linked_booking_id | select | required if category=Trip issue | "Select the related trip" |
| description | text | required, min 10 chars, max 2000 | "Add a bit more detail so we can help" |
| attachments | file[] | optional, max N files, max size each | "File is too large" |

STATES: category-selection → context-loading (fetching recent trips for the linked-booking suggestion) → composing → submitting → submitted (ticket_id shown, deep-links to Thread screen, Section 11A.1) → submit-failed (draft preserved locally, retry available — never lose a typed description on a network blip).

EDGE CASES:
- User selects "Trip issue" but has no recent trips (e.g., first-time user with only an in-progress booking) — the linked-booking picker gracefully offers the in-progress trip or falls back to "not related to a specific trip" rather than showing an empty, dead-end picker.
- User submits a ticket while genuinely mid-active-trip with a safety concern miscategorized as a normal support ticket (e.g., typed "driver is driving dangerously right now") — a lightweight keyword/urgency heuristic on submission surfaces a prominent "Is this an emergency? Use SOS instead" prompt before final submit, without blocking the normal ticket path if the user proceeds anyway (never force a re-route, since false positives on urgency detection are worse than a redundant SOS suggestion).
- Duplicate submission from a slow network double-tap — idempotency key (client-generated) ensures one ticket, not two, identical to the booking-creation pattern (Section 2.2.6).

API(S) CALLED: GET /support/v1/context/recent-bookings, POST /support/v1/tickets { category, linked_booking_id?, description, attachments[], idempotency_key }.

PERMISSIONS REQUIRED: none (self-service).

ANALYTICS EVENTS FIRED: TicketCreated (feeds Section 11A.1's Ticket Detail as the entry event).

ACCEPTANCE CRITERIA:
- [ ] Duplicate-tap submission produces exactly one ticket, verified under idempotency-key testing (same pattern as Section 2.2.6).
- [ ] A typed description is never lost on a submission failure — local draft persistence with retry.
- [ ] Safety-urgency language in the description surfaces an SOS suggestion without blocking normal submission.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Double-tap submit on slow network | One ticket created |
| Submission fails (network) | Draft preserved, retry available |
| Description contains urgent-safety language | SOS suggestion shown, normal submit still available |
```

---

## 20B. ANALYTICS — HEATMAP & COHORT RETENTION DEEP DIVE

### 20B.1 Demand Heatmap

```
SCREEN: Demand Heatmap
MODULE: Analytics
PURPOSE: Give Ops a live-enough, precisely-defined view of demand-vs-supply imbalance per zone, feeding both human surge-override judgment (Section 10) and longer-term city-planning decisions — must never be confused with, or silently diverge from, the actual live surge-calculation inputs (Section 5) it's meant to reflect.

LAYOUT: Map with zone-polygon overlay, color intensity = demand/supply ratio (same underlying metric definition as the live Surge Engine's input, Section 5 — explicitly the same calculation, not a separately-derived approximation that could show a different picture than what customers are actually being charged) → Time-scrubber (live / last hour / historical date-range) → Zone click → detail panel (open bookings, online-idle drivers, current surge multiplier if any, SLA-breach count).

FIELDS: time_range, city_filter, category_filter (optional — some categories may have distinct supply pools worth viewing separately).

STATES: live-mode (auto-refreshing on a short interval, explicit "updated Xs ago" indicator) / historical-mode (static, cache Aviv-friendly, explicit date-range shown) / loading / stale-data-warning (Section 20 rule, identical pattern to 20A.1's funnel dashboard).

EDGE CASES:
- Live-mode heatmap and the actual Surge Engine (Section 5) momentarily disagree due to pipeline lag (the heatmap reads from the analytics OLAP store with some ingest delay, Section 20's architecture note, while live pricing reads from a hotter real-time source) — the heatmap must display its own freshness timestamp prominently so an Ops viewer never mistakes a few-seconds-stale heatmap reading for the exact live pricing input, avoiding a false "why doesn't the map match what the customer was just charged" confusion.
- A zone with very low absolute booking volume shows a misleadingly extreme ratio (e.g., 1 booking / 0 idle drivers = technically infinite demand signal) — small-sample zones are visually distinguished (e.g., muted/hatched styling) rather than rendered with the same visual intensity as a high-volume zone showing the same raw ratio, to prevent Ops from over-reacting to noise.

API(S) CALLED: GET /analytics/v1/heatmap/demand?time_range&city&category (OLAP store, Section 20, never OLTP).

PERMISSIONS REQUIRED: analytics.view (same broad read role as 20A.1) plus ops.heatmap.view for the live-mode real-time refresh tier if that's gated more tightly than historical view.

ANALYTICS EVENTS FIRED: n/a (consumer screen).

ACCEPTANCE CRITERIA:
- [ ] The heatmap's demand/supply ratio uses the identical metric definition as the live Surge Engine's input (Section 5) — verified by a shared calculation library/service, not two independently-maintained implementations that can drift.
- [ ] Freshness is always visibly timestamped in live-mode.
- [ ] Low-sample zones are visually distinguished from high-confidence high-ratio zones.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Heatmap ratio vs live Surge Engine multiplier for same zone/time | Same underlying metric, consistent (accounting only for disclosed freshness lag) |
| Zone with 1 booking, 0 idle drivers | Rendered with low-sample visual distinction, not full-intensity alarm color |
| Ingest pipeline lags | Stale-data warning shown with explicit freshness timestamp |
```

### 20B.2 Cohort Retention Dashboard

```
SCREEN: Cohort Retention
MODULE: Analytics
PURPOSE: Track whether customers acquired in a given period keep booking over time, with a precisely versioned "retained" definition so retention trends are comparable across periods and not silently redefined mid-stream.

LAYOUT: Cohort-by-acquisition-period table/chart (rows = signup month/week, columns = period-since-signup, cells = % of that cohort with ≥1 completed booking in that period) → Segment filter (acquisition channel, city) → Definition tooltip on every cell explaining exactly what "retained" means for this view and which version of the definition is active.

FIELDS: cohort_granularity (weekly/monthly), segment_filter (optional).

STATES: loading / loaded / insufficient-data (a cohort too recent to have reached a given period-since-signup shows an explicit "not yet reached" cell rather than a blank or a misleading 0%).

EDGE CASES:
- Retention definition changes (e.g., "completed booking" redefined to exclude cancelled-and-refunded trips that were originally counted) — historical cohort cells computed under the old definition must be visibly annotated as such (e.g., a footnote/marker on the affected date range) rather than silently recalculated to look consistent with new data, which would misrepresent whether an actual behavior change happened or just a metric-definition change (same principle as Section 20A.1's funnel-definition versioning rule).
- A cohort acquired via a since-deprecated acquisition channel — the channel filter still shows historical data correctly attributed, not silently dropped or bucketed into "Unknown."

API(S) CALLED: GET /analytics/v1/cohorts/retention?granularity&segment.

PERMISSIONS REQUIRED: analytics.view.

ANALYTICS EVENTS FIRED: n/a (consumer screen).

ACCEPTANCE CRITERIA:
- [ ] Every retention percentage is computed under a versioned, documented definition, with historical periods clearly marked if the definition changed since they were computed (Section 20 rule, same as 20A.1).
- [ ] Cohorts too recent to have reached a given period show an explicit "not yet reached" state, never a misleading 0%.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Retention definition changes mid-history | Old cells visibly annotated as computed under prior definition |
| Cohort too recent for a given period column | Shows "not yet reached," not 0% |
```

---

## 13B. FLEET MANAGEMENT — PAYOUT SPLIT DEEP DIVE

### 13B.1 Payout Split Configuration

```
SCREEN: Fleet Payout Split Config
MODULE: Fleet Management
PURPOSE: Let a fleet owner define the owner-vs-driver earning split, per-vehicle or per-driver override, with every trip's actual split locked at trip time (Section 13 acceptance criteria) so later config changes never retroactively alter already-earned amounts.

LAYOUT: Default fleet-wide split (owner % / driver %) → Per-driver override table (optional overrides layered on top of the default) → Per-vehicle override (rarer, e.g., a specific high-value vehicle with a different arrangement) → Effective-date selector (immediate or scheduled, same pattern as Section 9A.1's rate card) → Preview panel showing a sample trip's split before/after.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| default_owner_pct | number | 0–100, driver_pct auto-computed as remainder | "Enter a valid percentage" |
| override_pct (per driver/vehicle) | number | 0–100 | "Enter a valid percentage" |
| effective_date | date | ≥ now for scheduled changes | "Effective date must be in the future" |

STATES: loading / editing / diff-preview / saved-scheduled / active.

EDGE CASES:
- A trip is in progress at the moment a new split configuration takes effect — the split applied to that trip's earnings is the one active at trip *completion* time is explicitly NOT the rule; per Section 13's original acceptance criteria the split is locked at the rate active at trip time (interpreted as trip-start/booking-acceptance time, to avoid a driver being able to game timing by delaying completion past a favorable-to-them pending change, or conversely the owner doing the same) — this specific locking instant must be an explicit, documented decision on this screen (shown in the preview panel copy), not left ambiguous for engineering to interpret differently than product intended.
- A per-driver override is removed (reverting that driver to the fleet default) — same locking rule applies; only future trips are affected.
- Owner sets a split that a specific market's regulatory minimum driver-earning-share rules would violate (if such a config exists per market) — blocked with a specific message referencing the regulatory floor, not a generic validation error.

API(S) CALLED: GET /fleet/v1/payout-split, PUT /fleet/v1/payout-split { default, overrides[], effective_date }.

PERMISSIONS REQUIRED: fleet.payout.configure, scoped to the requesting owner's own fleet only.

ANALYTICS EVENTS FIRED: PayoutSplitChanged.

ACCEPTANCE CRITERIA:
- [ ] Every trip's earnings record correctly attributes the split active at the documented locking instant (Section 13 original criteria), immune to later split-config changes — verified by a test that changes config mid-trip and asserts the original split is preserved on that trip's ledger entry.
- [ ] A split violating a configured regulatory floor is blocked with a specific, actionable message.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Split config changes while a trip is in progress | Trip's earnings locked to the split active at the documented locking instant |
| Per-driver override removed | Only future trips revert to fleet default |
| Split set below regulatory floor | Blocked with specific regulatory-floor message |
```

---

## 9C. ADMIN DASHBOARD — GEOFENCE EDITOR & REFUNDS QUEUE

### 9C.1 Zone Geofence Editor

```
SCREEN: Zone Geofence Editor
MODULE: Admin — City/Zone Management
PURPOSE: Let Admin draw/edit the polygon boundaries that drive serviceability (Section 2.2.4), surge zoning (Section 5), and dispatch cross-zone rules (Section 4), with a hard safeguard against silently stranding active trips outside a newly-shrunk boundary (Section 9's original edge case).

LAYOUT: Map with existing zone polygons overlaid (color-coded by zone type: service-area, surge-zone, no-go-zone) → Draw/edit tool (add/move/delete polygon vertices) → Zone metadata form (name, type, operating hours, applicable vehicle categories) → Impact-preview panel (before save: "N active trips currently reference this zone; M would fall outside the new boundary") → Save.

FIELDS:
| Field | Type | Validation | Error copy |
|---|---|---|---|
| zone_name | text | required, unique within city | "This zone name already exists in this city" |
| polygon | geo-shape | must be a valid, non-self-intersecting closed polygon | "The drawn boundary is invalid — check for crossing lines" |
| zone_type | select | required | "Select a zone type" |

STATES: loading-existing-zones / drawing / metadata-editing / impact-preview (blocking — cannot save past this without acknowledging the count) / saving / saved.

EDGE CASES:
- Impact-preview shows M > 0 active trips would fall outside the new boundary — save is not blocked outright (zones legitimately need to shrink sometimes) but requires an explicit secondary confirmation naming the count, and those specific active trips are grandfathered to complete under the old boundary's rules (serviceability/surge context) rather than having their in-progress pricing or eligibility logic suddenly reference a boundary that no longer includes them (Section 9's original rule, made concrete here).
- Two zones are edited to now overlap where they previously didn't (e.g., two adjacent service areas both expanded) — flagged as a warning (not necessarily an error, since overlapping zone types like a surge-zone nested inside a larger service-area is often intentional) but same-type overlaps (e.g., two service-areas overlapping) are flagged more strongly since that implies an ambiguous serviceability answer for addresses in the overlap.
- A no-go-zone is drawn that would make a previously-serviceable saved address (Section 2.2.4) suddenly unreachable — this doesn't retroactively invalidate the saved address record, but new quote requests to that address correctly reflect the new no-go status going forward.

API(S) CALLED: GET /admin/v1/geo/zones/{city_id}, POST /admin/v1/geo/zones, PUT /admin/v1/geo/zones/{id}, GET /admin/v1/geo/zones/{id}/impact-preview.

PERMISSIONS REQUIRED: pricing.edit-adjacent permission ops.zone.manage (city-scoped, Section 22).

ANALYTICS EVENTS FIRED: ZoneBoundaryChanged.

ACCEPTANCE CRITERIA:
- [ ] Active trips referencing a zone whose boundary is about to shrink are explicitly surfaced before save, and are grandfathered to their original zone context through completion.
- [ ] An invalid (self-intersecting) polygon cannot be saved.
- [ ] Same-type zone overlaps are flagged distinctly from expected intentional overlaps (e.g., surge-zone within service-area).

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Shrink a zone boundary with active trips inside the removed area | Explicit count shown, confirmation required, those trips grandfathered |
| Draw a self-intersecting polygon | Blocked with validation error |
| Two same-type zones now overlap | Flagged strongly for review |
```

### 9C.2 Refunds & Disputes Queue

```
SCREEN: Refunds Queue
MODULE: Admin/Finance — Refunds & Disputes
PURPOSE: Route refund requests above the frontline agent auto-approval threshold (Section 11A.1) to a Finance approver, with full context carried over so nothing needs re-explaining.

LAYOUT: Queue (filterable by amount, age, requesting agent, linked ticket) → Detail panel (original ticket thread read-only embed, Section 11A.1, linked booking's full fare_breakdown for reference, Section 5, requested amount + agent's stated reason) → Approve / Partial-Approve (amount-adjustable, requires a note explaining the adjustment) / Reject (requires a note) actions.

FIELDS (Partial-Approve/Reject):
| Field | Type | Validation | Error copy |
|---|---|---|---|
| approved_amount | currency | if partial, > 0 and < originally requested amount | "Enter an amount less than the original request" |
| decision_note | text | required | "Add a note explaining this decision" |

STATES: pending / reviewing / approving / approved (triggers Wallet ledger credit, Section 6, and notifies the original requesting agent + the customer) / partially-approved / rejected (notifies agent, who must communicate the decision to the customer via the original ticket, Section 11A.1 — the queue doesn't message the customer directly, keeping a single coherent conversation thread).

EDGE CASES:
- The underlying booking/trip is later found to be part of a fraud-flagged cluster (Section 17A.1) after the refund request was submitted but before Finance reviews it — the queue item is auto-flagged with a fraud-warning banner, and approval requires an explicit acknowledgment of the flag rather than proceeding as if it were a routine request.
- Two refund requests exist for the same booking from two different tickets (Section 11A.1's merge-suggestion scenario, if the merge wasn't applied before both reached Finance) — the second one to be reviewed shows a clear duplicate-warning referencing the first, preventing an accidental double-refund on the same trip.
- Approved refund's Wallet ledger credit fails at the payment/gateway layer (e.g., original payment method now invalid, so it needs to route to wallet credit instead as a fallback) — this is surfaced back to the Finance approver as a distinct "approved but not yet settled" state rather than silently marking it complete when the money hasn't actually moved.

API(S) CALLED: GET /admin/v1/finance/refunds, POST /admin/v1/finance/refunds/{id}/approve { amount?, note }, POST /admin/v1/finance/refunds/{id}/reject { note }.

PERMISSIONS REQUIRED: finance.refund.approve_over_limit.

ANALYTICS EVENTS FIRED: RefundApproved, RefundPartiallyApproved, RefundRejected.

ACCEPTANCE CRITERIA:
- [ ] Every approved refund produces a Wallet ledger entry (Section 6) traceable back to both the originating ticket (Section 11A.1) and this approval action.
- [ ] A refund request on a since-fraud-flagged booking cannot be approved without an explicit flag acknowledgment.
- [ ] Duplicate refund requests on the same booking are detected and warned before a second approval can proceed.

QA TEST CASES:
| Scenario | Expected Result |
|---|---|
| Booking flagged for fraud after refund request submitted | Warning banner, explicit acknowledgment required to approve |
| Two refund requests for the same booking | Second shows duplicate warning |
| Approved refund's ledger credit fails to settle | Shown as "approved but not yet settled," not silently complete |
```

---

## APPENDIX A — FULL SCREEN-BY-SCREEN SPECIFICATIONS

This appendix works through every remaining screen in every module's inventory (Sections 2.1, 3.1, and each Tier-2 module's screen list) that wasn't already given a full deep-dive block in the main body. Format is the Section 20 template, compacted for volume: **Purpose | Fields/Validation | States | Edge Cases | API | Acceptance Criteria.** Nothing is skipped; screens that are genuinely simple get a shorter but still complete entry rather than a placeholder.

### A.1 Customer App — Remaining Screens

**Screen 1 — Splash**
Purpose: brand moment + silent bootstrap (token refresh check, minimum-version check, remote-config fetch). States: booting → token-valid (route to Home) / token-expired-refreshable (silent refresh, then Home) / token-invalid (route to Phone Entry) / force-update-required (blocking screen, Section 62) / maintenance-mode (Section 64). Edge cases: bootstrap network call times out (config, e.g., 5s) → proceed to cached-last-known-state rather than hang indefinitely, with a background retry; splash must never be the terminal state — a bootstrap failure always resolves to *some* usable screen, even if degraded. API: `GET /v1/bootstrap { app_version, device_id }` → `{ min_supported_version, maintenance_mode, feature_flags }`. Acceptance: splash never exceeds a hard max display time (config, e.g., 3s) regardless of network condition — always resolves forward.

**Screen 2 — Language Select**
Purpose: set locale before any copy renders. Fields: language list (config-driven, flag+native-name). States: default (device-locale pre-selected) → selecting → confirmed (persists to profile once authenticated, to device-local pref before that). Edge cases: user changes language mid-session later (Section 60 Settings) — in-flight screens re-render, but content already fetched in the old language (e.g., a support ticket thread) is not retroactively translated. API: none (local pref) until authenticated, then `PUT /v1/profile { locale }`. Acceptance: every user-facing string on every screen in this document resolves through the locale system — no hardcoded-language strings anywhere in the client.

**Screen 5 — Name/Email Capture (first-time)**
Purpose: minimal profile completion post-OTP for new users. Fields: name (required, 2–50 chars, no numeric-only), email (optional at v1 unless a market requires it for GST invoicing, Section 43; if provided, format-validated, uniqueness not enforced — one email can appear on multiple accounts, only phone is the unique key). States: entering → valid → submitting → complete (→ Home) → skip-available if email is optional. Edge cases: user backgrounds app before submitting — on return, resumes exactly here (not re-sent to OTP), since the account already exists post-verification (2.2.2). API: `PUT /v1/profile { name, email? }`. Acceptance: an account can reach Home without an email if email is optional per market config, never hard-blocked on a field the market doesn't require.

**Screen 6 — Location Permission Primer**
Purpose: explain *why* before the OS system prompt, improving grant rates (a bare OS prompt with no context has materially worse opt-in). Fields: none (informational + "Allow"/"Not now" CTAs that trigger the real OS prompt or skip). States: primer-shown → OS-prompt-triggered → granted/denied/denied-permanently (OS-level "don't ask again"). Edge cases: denied-permanently — subsequent app sessions do not re-show this primer nagging the user every launch; a persistent but non-blocking banner on Home (2.2.3) offers a deep link to OS settings instead. Acceptance: manual address entry (2.2.4) remains fully functional with location permission denied at every stage — this is never a hard gate to using the app.

**Screen 11 — Saved Addresses (Home/Work/Custom)**
Purpose: manage the address book independent of an active booking flow. Fields: label (Home/Work/Custom-name), address (map-pin + text), contact override (name/phone for this address, optional). Validation: same geofence-serviceability check as 2.2.4; label uniqueness not enforced for Custom (a user can have two "Office" entries if they choose) but Home/Work are singleton-per-account (setting a new Home replaces, doesn't duplicate). States: list / add / edit / delete-confirm (names the specific address). Edge cases: deleting an address currently referenced by an active recurring/scheduled booking (not applicable to Customer App v1's own scheduling, but relevant if Corporate recurring bookings [14] reference a personal saved address, which they should not — corporate addresses are account-level, not personal — this cross-reference is explicitly disallowed by design, not just an edge case to handle). API: `GET/POST/PUT/DELETE /v1/addresses`. Acceptance: deleting a saved address never affects any already-completed historical trip's stored address snapshot (Section 24 — bookings store their own geo/address copy at booking time, not a live FK to the saved-address record).

**Screen 12 — Item Details (goods type, weight, helper needed)**
Purpose: capture what's being moved to drive category recommendation (2.2.3) and driver prep. Fields: goods category (dropdown, e.g., Furniture/Electronics/Boxes/Other), approximate weight (numeric or a banded picker — bands are more reliable than a precise-sounding number nobody actually knows), helper-needed toggle (adds a helper-fee line to the fare breakup, 2.2.5), fragile-handling toggle (informational to driver, no fare impact by default unless market config ties it to a surcharge). Validation: weight band required if the selected vehicle category has a capacity ceiling relevant to eligibility (e.g., a 2-wheeler category hard-caps at a weight band — selecting an incompatible category+weight combo blocks progression with a specific message, not a silent mismatch discovered by the driver on arrival). States: entering → valid → carried into the fare comparison screen. Edge cases: user selects "Other" goods category with no further description — a free-text field becomes required in that case rather than leaving it fully unspecified, since "Other" alone gives dispatch/driver zero prep information. API: carried as part of the `POST /v1/pricing/quote` payload (Section 2.2.5). Acceptance: a weight/category combo that would exceed the selected vehicle's rated capacity is blocked before quote generation, not discovered as a failed pickup.

**Screens 15–16 — Schedule vs Instant Toggle / Date-Time Picker**
Purpose: let a customer book for a future pickup window rather than immediate dispatch. Fields: toggle (Instant default / Scheduled), if Scheduled: date + time-window picker (config-driven slot granularity, e.g., 30-min windows, not an exact-minute promise — Dispatch cannot guarantee a driver arrives at the literal second, and overpromising erodes trust). Validation: scheduled time must be within a configured future window (min lead time, e.g., 1 hour ahead; max lead time, e.g., 14 days ahead) and within the destination zone's operating hours (Section 9's zone operating-hours config). States: instant (default flow, 2.2.6) / scheduled-picking / scheduled-confirmed (booking enters a `SCHEDULED` status distinct from `SEARCHING`, and Dispatch (Section 4) only begins actively searching within a pre-pickup lead window, e.g., 30 min before the slot, rather than holding a driver assignment for hours in advance which would strand that driver from taking instant jobs in the meantime). Edge cases: a scheduled booking's pre-pickup dispatch search fails to find a driver (Section 4's exhaustion case) at the lead-window trigger — customer is proactively notified well before the promised window (not silently discovered at the missed pickup time) with rebooking/refund options. API: quote (2.2.5) carries `scheduled_at`; `POST /v1/bookings { scheduled_at }`. Acceptance: a scheduled booking's dispatch attempt begins automatically at the correct lead-time offset with no manual trigger required, and failure to assign is surfaced to the customer with enough lead time to act.

**Screens 17 — Coupon Entry/Apply** (detailed redemption-cap logic is in Section 15A.1; this entry covers the client-side screen itself)
Purpose: let the customer enter/select an applicable coupon before confirming. Fields: code entry (text, uppercase-normalized) or a "View available offers" list (segment-filtered, per Section 15). Validation: full server-side check per Section 15A.1's rules on apply, not just format. States: entering → applying (server round-trip, not applied optimistically client-side, since eligibility/caps are server-authoritative) → applied (fare breakup 2.2.5 recalculates live) → invalid (specific reason shown — expired/min-order-not-met/segment-ineligible/cap-reached — never a generic "invalid code"). Edge cases: applying a second coupon while one is already applied — replaces it (single-coupon-per-booking in v1, stacking with subscription benefits is the only combination Section 15A/19A address) rather than silently stacking two coupons unless explicitly designed otherwise. API: covered by Section 15A.1's redemption-check, invoked from the quote endpoint. Acceptance: the applied coupon's discount is always visible as its own line item in the fare breakup, never folded invisibly into the total.

**Screens 18–19 — Payment Method Selector / Add-Manage Payment Method**
Purpose: choose how to pay and manage saved instruments, all via gateway tokenization (Section 6 — platform never stores raw card data). Fields (Add): card number/UPI-ID/etc. per method type, entered directly into the gateway's tokenizing SDK component (not the app's own form fields touching raw PAN). States: list (saved methods + wallet balance shown as a selectable "pay from wallet" option) → add-new (gateway SDK flow) → default-method-set → remove-confirm. Edge cases: removing a payment method that has a pending/in-flight authorization against it (e.g., a scheduled booking's fare hold) — removal is blocked until that hold resolves, with a specific explanation, rather than silently orphaning an in-flight charge attempt; wallet balance insufficient and selected as the sole method at booking-confirm time — booking confirmation is blocked with a specific low-balance message and a direct "Add Money" deep link (2A.2), never allowed to proceed into a negative wallet balance (Section 6 rule: customer wallets cannot go negative). API: `GET/POST/DELETE /v1/payment-methods` (tokenized references only). Acceptance: no raw card/bank data ever transits or is stored on platform servers — verified via a PCI-scope audit (Section 27).

**Screens 23–25 — Live Tracking Map / Chat / Call (masked)**
Purpose: real-time visibility + safe communication during an active trip. Live Tracking Map: covered in depth at Section 8 (architecture) — this entry is the screen wrapper: driver marker, ETA, trip-stage banner, Chat/Call/SOS quick-actions, Cancel entry point (2A.1). Chat: masked message thread (Section 1 principle 3 — no raw numbers ever exchanged), delivery/read receipts, canned quick-replies for common needs ("I'm at the gate," "5 more minutes"). Call: taps into a masked-proxy call (telecom masking API) or in-app VoIP per market config; call initiation is logged (duration, timestamp) for support/dispute purposes but content is never recorded without explicit legal basis/consent per jurisdiction. Edge cases: chat/call attempted after trip completion — allowed for a limited grace window (config, e.g., 1 hour post-completion, for "I left something in the vehicle" scenarios) then the masked channel is torn down and further contact must route through Support (11A/11B) rather than an indefinitely-open direct line between two parties who no longer have an active relationship. API: `GET /v1/bookings/{id}/chat`, `POST /v1/bookings/{id}/chat/send`, `POST /v1/bookings/{id}/call/initiate` (returns a masked proxy number/session). Acceptance: masked numbers/session tokens are invalidated at the grace-window boundary and cannot be reused to contact the other party afterward.

**Screens 27–28 — Trip In-Progress Tracking / Multi-stop Sequence View**
Purpose: customer-facing mirror of the driver's navigation state (Section 3B.1) — shows current stage, next stop (for the customer's own multi-stop booking), live position. Largely a read-only reflection of server state already covered by 3B.1 and Section 8; the customer-specific addition is per-stop ETA-to-customer's-own-stop (relevant when a driver has other stops, if the platform's dispatch model batches — v1 is single-customer-per-trip per Section 4's "one active job at a time" rule, so in v1 multi-stop always belongs to the *same* customer's own multiple drop points, never another customer's stop interleaved). Edge cases: identical to 3B.1's server-side sequence-integrity rule — the customer view can never show a stop as complete before the server confirms it, regardless of any client-side optimistic assumption. Acceptance: customer-visible stage always matches server-authoritative state; no client-side "looks about done" inference.

**Screens 30–32 — Fare Summary/Final Invoice / Rate Driver / Tip Driver**
Purpose: close out the trip financially and socially. Fare Summary: final `fare_breakdown` (Section 5) including any waiting/toll adjustments realized during the actual trip vs the original quote estimate, with each adjustment itemized and explained (never a bare "final amount differs from quote" with no breakdown). Rate Driver: see Section 17B.1 (full deep dive). Tip Driver: post-rating, optional, preset amounts + custom, charged as a separate transaction to the original payment method or wallet, credited to the driver's wallet distinctly tagged as a tip (not blended into trip-fare earnings, since tips may have different tax/reporting treatment per market). Edge cases (Tip): tip attempted after the tipping window closes (config, e.g., 24h post-trip) — the option simply isn't offered anymore on that trip's detail view, not a broken/error state. API: `GET /v1/bookings/{id}/final-fare`, `POST /v1/bookings/{id}/tip { amount }`. Acceptance: every fare adjustment between quoted and final amount is individually itemized and traceable to a specific cause (waiting time, toll, route change) in the stored `fare_breakdown` JSON (Section 24).

**Screens 33–34 — Trip History List / Trip Detail**
Purpose: browsable record of past trips. List: reverse-chronological, paginated, filter by date-range/status (completed/cancelled). Detail: full read-only reconstruction of a past trip — route map, fare breakdown, driver (if rated, shows the rating given), invoice download link (43–44), "Book similar" shortcut (pre-fills a new booking with the same pickup/drop). Edge cases: a past trip's driver has since been suspended/deactivated (Section 9A.2) — historical detail still displays that trip's data as it was at the time (driver name, vehicle), never scrubbed or broken just because the driver's current account status changed. API: `GET /v1/bookings?status&from&to`, `GET /v1/bookings/{id}`. Acceptance: trip history is immutable once a trip reaches a terminal state — no later action anywhere in the system (driver suspension, KYC changes, coupon edits) alters a historical trip's displayed record.

**Screens 38–40 — Profile / Edit Profile / Wallet Home**
Purpose: account self-management + wallet entry point. Profile: name, phone (read-only — changing the account's identity phone number is a distinct, more heavily-verified flow than a simple profile edit, requiring re-OTP on the *new* number before it takes effect, not a silent field edit), email, profile photo. Wallet Home: balance, quick add-money (2A.2) and view-transactions (42) entry points, any active promotional-credit balance shown distinctly from real-money balance (Section 6 rule) with its own expiry indicator if applicable. Edge cases (phone change): the new number must not already belong to another active account (same uniqueness rule as original registration, 2.2.1); until the new-number OTP is verified, the account's identity phone remains the old number — never a state where the change is "half-applied." API: `PUT /v1/profile`, `POST /v1/profile/phone/change-request`, `POST /v1/profile/phone/change-verify`. Acceptance: a phone-number change is atomic and OTP-gated on the new number exactly like original signup — no path exists to change the account's identity number without re-verification.


**Screens 42–44 — Wallet Transaction History / GST Invoice Details Entry / Invoice List-Download**
Purpose: financial self-service transparency. Transaction History: every wallet ledger entry (Section 6) visible to the owning user, filterable by type (top-up/trip-charge/refund/promo-credit/tip), each entry showing running balance-after for auditability. GST Invoice Details: business-name, GSTIN (format-validated per country tax-ID rules, e.g., India's 15-char GSTIN checksum pattern), billing address — saved once, applied to future invoices going forward only (not retroactive to past trips already invoiced without it, since re-issuing historical tax documents has its own compliance process outside a simple profile edit). Invoice List/Download: per-trip PDF invoice generation, cached after first generation (not regenerated on every download click) unless the underlying trip's `fare_breakdown` is amended (e.g., a post-trip refund) — in which case a new invoice version is generated and the old is marked superseded, both retained. Edge cases: GSTIN entered that fails checksum validation is blocked with a specific format error, never silently accepted and only discovered as invalid when a real tax authority rejects it later. API: `GET /v1/wallet/transactions`, `PUT /v1/profile/gst-details`, `GET /v1/bookings/{id}/invoice.pdf`. Acceptance: every wallet transaction is permanently visible to the account holder (append-only ledger, Section 6) with no "vanishing" history regardless of account age.

**Screens 45–46 — Referral Home / Referral Share Sheet**
Purpose: surface the referral program (full mechanics in Section 18A.1) and make sharing frictionless. Referral Home: user's unique code/link, running count of successful referrals, earned-so-far total (pending vs credited, distinguishing rewards still in fraud-review, Section 18A.1, from confirmed ones — never showing a PENDING_REVIEW reward as if it were already usable balance). Share Sheet: native OS share targets (WhatsApp/SMS/etc.) pre-filled with a templated message + the code/link. Edge cases: a referral reward that was PENDING_REVIEW and later VOIDed (Section 18A.1's fraud-confirmed outcome) — the Referral Home's "earned" total must correctly reflect the void (removed from pending, never counted), with no discrepancy versus the Wallet's actual ledger total. API: `GET /v1/referral/summary`. Acceptance: the "earned" figure shown here always reconciles exactly to the sum of `referral_id`-tagged Wallet ledger credits (Section 18A.1 acceptance criteria) — never an independently-computed number that can drift from the ledger.

**Screens 47–48 — Notifications Inbox / Notification Preferences**
Purpose: in-app notification center + preferences (preferences fully specced at 16A.1). Inbox: reverse-chronological list of all past notifications regardless of channel they were delivered through, read/unread state, tap-through to the relevant screen (trip detail, promo target, ticket thread) via the same deep-link resolution used by CMS banners (9B.1) — including the same graceful-fallback rule if the target context no longer applies to the current user state. Edge cases: an inbox item referencing a since-deleted or since-expired entity (e.g., a promo banner notification for a coupon that has since expired) — tapping it shows a clear "this offer has ended" state rather than a broken deep-link error. API: `GET /v1/notifications/inbox`, `POST /v1/notifications/{id}/read`. Acceptance: inbox read/unread state is server-persisted (syncs across a user's multiple devices if applicable), not purely local-device state that resets on reinstall.

**Screens 49–52 — Support Home / Ticket Create / Ticket Thread / Live Chat with Agent**
Purpose: self-serve FAQ + escalation path. Support Home: searchable FAQ (CMS-managed, Section 9B pattern extended to FAQ content type), prominent "Contact Support" CTA routing to Ticket Create (full spec at 11B.1). Ticket Thread: mirrors the agent-side conversation view (11A.1) from the customer's perspective — same message history, read receipts, ability to reply and attach follow-up files. Live Chat: real-time variant when an agent is actively online/assigned versus the async ticket-thread default; a queued-for-live-agent state shows an honest estimated-wait rather than a spinner with no information. Edge cases: customer sends a message to a ticket that has already been closed (11A.1) within the reopen window — automatically reopens per the rule already specified there, with the customer-facing thread reflecting the reopened state clearly (not silently appearing as if it were never closed, since transparency about the reopen matters for setting response-time expectations). API: `GET /support/v1/tickets/{id}/messages`, `POST /support/v1/tickets/{id}/messages`. Acceptance: the customer-facing Ticket Thread and the agent-facing Ticket Detail (11A.1) are always the same single source of truth conversation — never two divergent copies that could show different histories to each party.

**Screens 53–55 — SOS/Safety Center / Emergency Contacts Setup / Trip Share**
Purpose: safety tooling, feeding directly into the Control Room SOS flow (10A.1). Safety Center: prominent SOS trigger (large, hard-to-misfire but fast-to-use — a deliberate press-and-hold or double-tap pattern, config, to prevent accidental triggers while still being fast in a real emergency), quick access to Emergency Contacts and Trip Share. Emergency Contacts Setup: up to N contacts (name + phone), used both for the Trip Share feature and as the auto-notify target during an active SOS (10A.1's "Notify Emergency Contact" action). Trip Share: generates a time-limited, revocable read-only link showing live trip location to a non-app-user recipient (via SMS/WhatsApp) — automatically expires at trip completion, and is immediately revocable manually before that. Edge cases: a Trip Share link accessed after it has expired/been revoked shows a clean "this link is no longer active" page, never a broken map or stale last-known location presented as if live. API: `GET/POST/DELETE /v1/emergency-contacts`, `POST /v1/bookings/{id}/share-link` (returns a signed, time-bound token), `DELETE /v1/bookings/{id}/share-link` (revoke). Acceptance: SOS trigger reaches the Control Room pipeline (Section 10A.1) with the exact same latency guarantee regardless of which specific screen the user triggered it from within the app (SOS access must be consistently fast platform-wide, not just from one entry point).

**Screen 56 — Favourites (drivers/vehicle presets)**
Purpose: let a customer save a preferred driver (if that driver is available at next booking) or a preset vehicle-category+item-details combo for faster rebooking. Fields: favourite driver (added post-trip from Trip Detail, 33–34), favourite preset (name + saved category/item-details combo). Edge cases: a favourited driver is offline, suspended, or outside range at the time of a new booking — the favourite is shown but clearly marked unavailable-right-now, and normal Dispatch (Section 4) proceeds without artificially forcing a wait for that specific driver (a "wait for my favourite driver" option can exist as an explicit opt-in with its own timeout, but is never the silent default behavior, since forcing dispatch to wait indefinitely for one driver would break the platform's SLA guarantees). API: `GET/POST/DELETE /v1/favourites/drivers`, `GET/POST/DELETE /v1/favourites/presets`. Acceptance: favouriting a driver never creates a dispatch-priority side-channel that bypasses normal scoring (Section 4) unless the customer explicitly opts into a "wait for favourite" flow with its own disclosed timeout.

**Screen 57 — Corporate Account Linking**
Purpose: let an individual user link their personal app account to a corporate account they've been invited to (Section 14B.1's invite flow), enabling "bill to company" as a payment option (2.2.5/18–19) without needing a separate corporate-only app. Fields: invite code or accept-via-email-link. States: unlinked → invite-pending (received an invite, not yet accepted) → linked (company appears as a payment-method option) → unlink (self-service, removes the billing option but doesn't affect the user's personal trip history or wallet). Edge cases: user is linked to a corporate account and later removed by an admin (Section 14B.1) — the "bill to company" option disappears from their payment method list immediately (next app foreground/API call, same enforcement immediacy principle as Section 22A.1's permission-reduction rule), any in-flight trip already reserved against the company (Section 14A.1) is unaffected through completion. API: `POST /v1/corporate/link { invite_code }`, `DELETE /v1/corporate/link`. Acceptance: an unlinked/removed user immediately loses the corporate billing option on their very next relevant action, never lagging behind the admin-side removal.

**Screen 60 — Settings**
Purpose: central hub linking to Notification Preferences (48), Language (2/60-accessible-again), Payment Methods (18–19), Emergency Contacts (54), Delete Account (61), Privacy/Legal links, App Version display, Logout. Edge cases: Logout while a trip is active — blocked with an explanation ("You have an active trip — you'll stay signed in until it completes") rather than silently logging out and orphaning the user's ability to track/communicate on an in-progress delivery of their own goods. API: `POST /v1/auth/logout`. Acceptance: logout is blocked (not silently allowed then confusingly still receiving trip notifications) while any booking is in a non-terminal state for that user.

**Screen 61 — Delete Account Flow**
Purpose: honor account-deletion requests (privacy/compliance requirement, Section 14D's GDPR-pattern applied to the consumer side too) while protecting financial-record integrity. Fields: reason (optional feedback), confirmation step naming the consequence explicitly (wallet balance handling — must be withdrawn/refunded before deletion or explicitly forfeited with clear consent, never silently zeroed), password/OTP re-verification before executing (a destructive, hard-to-reverse action requires reproving identity even for an already-authenticated session). States: requesting → wallet-settlement-required (if balance > 0, blocks until resolved) → re-verifying → confirmed → deletion-processing (may be async — PII scrubbed per policy, but financial/audit records retained per legal retention requirements as anonymized/pseudonymized entries, Section 27, rather than a hard delete that would break historical ledger integrity, Section 6). Edge cases: user has an active in-progress trip — deletion is blocked entirely until it completes (can't delete an account mid-transaction). Same phone-number re-registration cooldown noted at Section 2.2.1 applies here as the deletion's consequence. API: `POST /v1/account/delete-request`, `POST /v1/account/delete-confirm`. Acceptance: a deleted account's historical trips remain in Finance/audit records (Section 6/24) as immutable, non-PII-leaking entries — deletion removes personal profile data, never the financial ledger trail.

**Screens 62–64 — App Update Prompt / Network Offline State / Maintenance Mode**
Purpose: system-level states that must be handled gracefully everywhere, not just as one screen. Force-update: blocking full-screen when `app_version < min_supported_version` (Screen 1's bootstrap check) — no bypass, since older versions may have incompatible API contracts (Section 23's versioning rule) or unpatched security issues. Soft-update: dismissible banner, doesn't block usage. Network Offline: a global state (not just one screen) — every screen's data-dependent components show a consistent offline treatment (cached content if available with a "showing saved data" indicator, or a clear offline message with auto-retry on reconnect) rather than each screen inventing its own inconsistent offline handling. Maintenance Mode: full-screen block with an estimated-back-online time if known, triggered by the same bootstrap flag (Screen 1) — critical distinction: an in-progress trip already underway when maintenance mode activates must not be abandoned mid-delivery; maintenance mode blocks *new* bookings/app-entry, never severs an already-active trip's tracking/comms (which should route through a maintained minimal-availability path, or maintenance windows should be scheduled to avoid periods with active trips, per Ops policy). Acceptance: maintenance mode never strands a trip that was already in progress when it activated.

---

### A.2 Driver App — Remaining Screens

**Application Under Review / Approval Pending**
Purpose: hold state between KYC submission (3.2) and reviewer decision. States: pending (shows per-step status from 3.2's taxonomy, estimated review SLA) → approved (routes to Home/Online toggle) → rejected (specific step + reason, re-upload deep link). Edge cases: driver checks status days after submission with no reviewer action yet — if the SLA (Section 7, e.g., 24h) has been breached, this is visible to the driver honestly ("This is taking longer than expected — we're on it") rather than a static unchanging "pending" message that erodes trust; this same breach also auto-escalates on the reviewer side (Section 7). API: `GET /v1/driver/kyc/status` (same endpoint as 3.2). Acceptance: an SLA-breached review is visibly different in-app from a normally-progressing one, not indistinguishable.

**Vehicle Details Entry / Bank-Payout Details**
Purpose: covered substantively within the KYC wizard (3.2 steps 4–5) — this entry documents them as their own addressable screens for navigation/resumability purposes. Vehicle Details: category, make/model, plate number (format-validated per market), linked to the vehicle documents already speced in 3.2. Bank Details: penny-drop-verified before acceptance (3.2 rule) — if penny-drop fails (name mismatch, invalid account), the driver sees the specific failure reason, not a generic error, and can retry with corrected details without restarting the entire KYC wizard from step 1. Acceptance: a bank-details failure never forces the driver to redo already-completed, already-approved earlier KYC steps.

**Training Module (video/quiz)**
Purpose: mandatory onboarding education (safety, platform policy, app usage) gating approval alongside document KYC. Fields: video completion tracking (must-watch-to-completion, not skippable past a config minimum watch percentage), quiz (multiple choice, pass threshold e.g. 80%, retakeable with a cooldown if failed). States: not-started → in-progress (resumable, doesn't restart from zero if the app is closed mid-video) → quiz-available → passed / failed-retry-available. Edge cases: driver fails the quiz repeatedly beyond a config max-attempts — routed to a manual review/support contact rather than permanently locked out with no path forward. API: `GET /v1/driver/training/modules`, `POST /v1/driver/training/{module}/quiz-submit`. Acceptance: training completion is a hard gate on `overall_status = APPROVED` identical in enforcement strength to document KYC (Section 3.2) — a driver cannot go online having passed documents but skipped training.

**Home / Online-Offline Toggle**
Purpose: the driver's primary control surface. Layout: large online/offline toggle, today's earnings summary (3.4), current location indicator, incoming-job-offer overlay (3.3) appears here when triggered. Edge cases: driver taps Online while a required document has just expired (3A.1) mid-session — toggle is blocked with the specific expired-document reason inline, not a silent failure to actually go online while the UI shows it as if successful; driver taps Online with no GPS/location permission — blocked with a direct re-permission prompt, since Dispatch (Section 4) cannot function without live position. API: `POST /v1/driver/status { online: true|false }`. Acceptance: the online toggle's actual server-side state always matches what's displayed — no UI-optimistic "looks online" state that isn't backed by a confirmed server transition (a driver believing they're online and receiving no jobs because of a silent server-side rejection is a critical trust failure).

**Loading Confirmation (photo optional)**
Purpose: driver-side proof-of-pickup beyond the OTP (2.2.7), for goods condition/quantity disputes. Fields: photo capture (optional unless the specific booking's item category requires it per config, e.g., high-value electronics), item-count confirmation checklist if multi-item. Edge cases: photo capture fails/is skipped on a booking where it's configured as required — blocks proceeding to navigation-to-drop with a specific prompt, not silently allowed through. API: `POST /v1/driver/jobs/{id}/loading-confirm { photo? }`. Acceptance: for item categories configured as photo-required, no trip can progress past pickup without one attached to the trip record.

**Rate Customer**
Purpose: driver-side mirror of Section 17B.1, symmetric mechanics (stars, tags relevant to a customer — e.g., "Item not as described," "Unsafe pickup location," "Great communication"), feeding the same low-rating/safety-escalation pipeline (17A.1) when applicable to customer behavior. Acceptance: identical to 17B.1's acceptance criteria, applied to the customer-rated-by-driver direction.

**Incentives/Missions**
Purpose: surfaces active incentive schemes (referenced in 3.4) — e.g., "Complete 5 trips today for a ₹200 bonus," progress bar, terms. Edge cases: an in-progress mission's terms change (Marketing/Ops edits the scheme mid-period) — drivers already partway through the old terms are grandfathered to what was active when they started the mission period, never retroactively changed to a less favorable version mid-stream (same principle as rate-card version-locking, Section 9A.1, applied to incentives). API: `GET /v1/driver/incentives/active`. Acceptance: a driver's mission progress and payout terms are locked to what was active at mission-period start, immune to later scheme edits for that period.

**Heatmap (demand zones)**
Purpose: driver-facing simplified view of Section 20B.1's demand heatmap, helping drivers position themselves in high-demand zones. Same underlying data source/freshness rules as 20B.1 apply — must not show a different picture than what's actually driving live surge pricing. Acceptance: identical metric-consistency requirement as 20B.1.

**Withdraw Funds**
Purpose: driver-initiated instant/standard payout request against available wallet balance (3.4/Section 6), distinct from the scheduled batch payout (12A.1) — this is the on-demand path. Fields: amount (≤ available balance, config min/max per request), instant (fee-bearing) vs standard (free, T+1) choice. Edge cases: withdrawal requested for an amount that includes funds currently held by a fraud flag (Section 17A.1) — only the unheld portion is available to withdraw, shown explicitly as "available" vs "held" balance, never allowing a withdrawal that would include frozen funds. API: `POST /v1/driver/wallet/withdraw { amount, mode }`. Acceptance: withdrawable amount is always computed as available-minus-held in real time, verified consistent with the Finance ledger (Section 6/12).

**Penalties/Violations List / Penalty Dispute Flow**
Purpose: transparency into deductions (3.4's "penalties/deductions" line) with a structured appeal path rather than a silent debit the driver can't contest. List: each penalty with reason code, amount, linked trip/incident if applicable, dispute-status. Dispute Flow: submit a reason/evidence, routes to Support (11A.1) with a `penalty_dispute` category pre-tagged for prioritized Ops/Finance handling. Edge cases: a disputed penalty is upheld — the driver is shown the specific resolution reasoning (from the taxonomy pattern used throughout this document), not just a bare "dispute denied." API: `GET /v1/driver/penalties`, `POST /v1/driver/penalties/{id}/dispute`. Acceptance: every penalty has a disputable path with a structured, reasoned resolution — no penalty is ever a permanently unexplained/uncontested deduction.

**Profile / Vehicle Management (multi-vehicle drivers) / Support / Ratings Received / Offline Reason Capture**
Purpose (grouped, each a straightforward variant of already-established patterns): Profile mirrors Customer App's 38–39 pattern with driver-specific fields. Vehicle Management for drivers operating multiple vehicles (independent of Fleet accounts, Section 13) lets a driver switch their "active vehicle for today" — validated against that specific vehicle's own document-validity state (3A.1) independently per vehicle, since one vehicle's expired insurance shouldn't block a driver's other, validly-documented vehicle. Support mirrors 11B.1's flow, driver-context-aware. Ratings Received is a read-only history mirroring 34's trip-detail pattern applied to driver ratings. Offline Reason Capture (optional prompt when a driver goes offline, e.g., "Taking a break" / "Done for the day" / "Vehicle issue") feeds Section 20 analytics on driver utilization patterns — always optional/skippable, never a blocking gate on going offline (a driver must always be able to go offline immediately for safety/personal reasons without first answering a survey). Acceptance (Vehicle Management specifically): switching active vehicle mid-session while one has expired documents blocks selecting *that* vehicle specifically while leaving the driver's other valid vehicles selectable — document validity is evaluated per-vehicle, not account-wide.

---

### A.3 Admin Dashboard — Remaining Screens

**City Management (List/Detail/Create)**
Purpose: root entity above Zones (9C.1) — a city is the top-level operating boundary. Fields: name, country, timezone (drives Section 5's night-surcharge window and Section 22M's UTC-storage-local-display rules for every trip in that city), currency, launch-status (pre-launch/active/paused). Edge cases: pausing an active city — blocks new bookings platform-wide for that city but must not interrupt trips already in progress (same "never strand an active trip" principle applied at the city level now, consistent with the zone-level rule in 9C.1). Acceptance: pausing a city is enforced at the Booking-creation API layer, not just hidden from the Customer App UI — a stale cached client attempting to book in a paused city gets a clear server-side rejection.

**Vehicle Category Management**
Purpose: define the category catalog referenced throughout (2.2.3, 5, 9A.1). Fields: name, icon/media, capacity descriptor, eligibility rules (license class, permit requirement — feeds 3.2's KYC validation), per-city availability toggle. Edge cases: deactivating a category that has active in-progress trips currently using it — the category disappears from new-booking options immediately but in-progress trips are unaffected (consistent pattern), and drivers currently online under that category retain their current session's job-eligibility for jobs already offered but see the category greyed for future availability. Acceptance: category deactivation is a forward-only change, never retroactively invalidating an active trip's category reference.

**Dispatch Override (Live Booking View)**
Purpose: give Admin (distinct from the real-time Control Room, Section 10A, this is the slower configuration-and-investigation-oriented Admin view) the ability to inspect and intervene on any specific booking's dispatch history. Layout: booking search/lookup → full dispatch-attempt timeline (every `DriverOffered`/`DriverAccepted`/`DriverDeclined`/`DispatchExhausted` event, Section 22, with timestamps) → Force-Assign action (select a specific driver, bypassing the normal scoring algorithm, Section 4 — reserved for genuine exceptions, e.g., a VIP corporate escalation) → Cancel-and-Refund action. Edge cases: force-assigning a driver who is not actually eligible (wrong vehicle category, expired document) — blocked with the specific ineligibility reason even in an override flow, since Force-Assign overrides the *scoring/matching* algorithm, never the hard eligibility gates (Section 3.2's KYC-approved requirement is never bypassable by any admin action, full stop). API: `GET /admin/v1/bookings/{id}/dispatch-log`, `POST /admin/v1/bookings/{id}/force-assign { driver_id }`. Acceptance: Force-Assign can never assign a KYC-non-approved or document-expired driver — hard eligibility gates apply identically in override mode as in normal Dispatch.

**Audit Log Viewer**
Purpose: searchable, filterable view of every privileged action platform-wide (Section 24's `audit_log` table, referenced throughout this document as the universal accountability mechanism). Fields (filter): actor, action-type, resource-type, date-range, resource-id. Layout: list (timestamp, actor, action, resource, before→after summary) → detail (full before/after JSON diff). Edge cases: searching for actions on a resource that has since been hard-deleted (rare, since most entities in this platform are soft-delete/versioned per Section 24's design rule) — the audit entry itself is never deleted even if its subject resource later is, since the audit trail's integrity must survive the deletion of what it describes. Acceptance: the audit log itself is append-only and has no admin-facing delete/edit capability whatsoever — not even a Super Admin can alter or remove an audit entry, since a mutable audit trail defeats its entire purpose.

### A.4 Ops/Control Room — Remaining Views

**Live Map (Full Fleet View)** — covered architecturally at Section 8/10; the specific screen adds: filter by vehicle-category/zone/SLA-status, click-through from a map marker directly to that trip's Dispatch Override detail (A.3) for one-click investigation without re-searching. Acceptance: every marker's underlying data freshness matches the 3s p95 ping-latency guarantee (Section 8), with the same explicit staleness indicator pattern used throughout (20A.1/20B.1) if a specific driver's last-ping exceeds a stale threshold.

**Broadcast/Zone Nudge**
Purpose: the specific screen behind Section 10's "broadcast a push notification to online drivers in a zone" intervention. Fields: target zone(s), message (templated or free-text within a length limit), optional incentive attachment (links to an Incentives/Missions scheme, driver-app-visible per the pattern above). Edge cases: a broadcast sent to a zone with zero currently-online drivers — a pre-send count-preview ("0 drivers currently online in this zone") prevents sending a message into the void with no visibility that it did nothing. API: `POST /ops/v1/broadcast { zone_ids, message, incentive_id? }`. Acceptance: broadcast send is preceded by a live recipient-count preview, never a blind send with no feedback on reach.

### A.5 Support Portal — Remaining Screens

**Queue View**
Purpose: the entry screen before Ticket Detail (11A.1) — filterable by priority/SLA-risk/category/assigned-agent, with SLA-risk color-coding matching the same urgency-signaling pattern used in Control Room's live map (A.4). Edge cases: a ticket ages past its SLA while sitting unassigned in the queue (no agent has picked it up at all, distinct from 11A.1's assigned-but-breaching case) — auto-assignment kicks in per a round-robin or load-balancing rule rather than an unassigned ticket sitting indefinitely with no owner. Acceptance: no ticket can remain unassigned past a configured max-unassigned-duration without triggering auto-assignment.

**Macro/Canned-Response Library**
Purpose: agent-facing reusable response templates (referenced at Section 11), admin-manageable (category-organized, variable-substitution-capable like Section 16's notification templates, with the same template-lint requirement so an agent never sends a macro with an unresolved `{variable}` literal to a customer). Acceptance: identical lint-before-use requirement as Section 16's notification templates.

### A.6 Finance & Settlement — Remaining Screens

**Settlement Dashboard**
Purpose: period-level rollup (gross bookings, net revenue, driver payouts, refund liability, coupon discount liability, tax collected) — every figure here must trace back to the same underlying ledger (Section 6) referenced throughout, never an independently-computed shadow number. Acceptance: every dashboard figure is spot-checkable against a raw ledger query and matches exactly — this dashboard is a view over the ledger, never a separate source of truth.

**Corporate Invoicing (Generate/Send)**
Purpose: the screen behind Section 11's corporate invoicing description. Fields: account, billing period, auto-populated line items (per-trip, at the rate-card-at-time-of-trip per Section 11's original rule) → preview → send. Edge cases: an invoice generated for a period where a trip's fare was later adjusted (support-issued partial refund, Section 11A.1, after the original trip but before this invoice run) — the adjustment is reflected as its own line item on the invoice it falls within the *billing period of the adjustment itself* (typically the current/next invoice), not retroactively rewriting an already-sent invoice, consistent with the "never re-invoice something already sent" financial-integrity pattern. Acceptance: an already-sent invoice is never regenerated/altered in place; corrections always appear as new line items on a subsequent invoice, fully traceable back to the original trip and the adjustment's cause.

**Tax Reports**
Purpose: jurisdiction-level GST/tax liability rollup for compliance filing. Fields: jurisdiction, period, computed liability (sum of `tax_rate`-derived amounts from every trip's `fare_breakdown`, Section 5). Acceptance: tax liability figures are derived exclusively from the stored per-trip `fare_breakdown`, never recomputed from current rate-card coefficients (which may have since changed, Section 9A.1's versioning) — historical tax must always reflect what was actually charged at the time.

### A.7 Fleet Management — Remaining Screens

**Fleet Dashboard**
Purpose: aggregate view across the owner's vehicles/drivers — utilization %, aggregate earnings, document-status summary (count of vehicles with expiring/expired documents, linking through to 3A.1's per-vehicle detail). Acceptance: aggregate figures reconcile exactly to the sum of the fleet's individual vehicle/driver ledger entries (Section 6/12), same reconciliation discipline applied at every rollup level throughout this document.

**Driver Roster (Invite/Remove)**
Purpose: fleet-scoped variant of Section 14B.1's employee-roster pattern, applied to drivers instead of corporate employees. Same core rules apply: removing a driver mid-active-trip doesn't interrupt the trip; removing a driver who has a pending payout balance doesn't forfeit it (Section 13's original rule) — independent settlement continues regardless of roster removal. Acceptance: identical to 14B.1's acceptance criteria, applied to the fleet-driver relationship.

### A.8 Corporate/B2B Portal — Remaining Screens

**Company Dashboard**
Purpose: spend-to-date vs credit limit (live view of the Section 14A.1 reservation mechanics), active bookings across the org. Acceptance: the displayed "available credit" figure is always computed live from committed-plus-reserved spend (Section 14A.1), never a cached/stale figure that could show available credit that a concurrent booking has already consumed.

**Recurring Booking Setup**
Purpose: the screen behind Section 11's recurring-pickup description. Fields: pickup/drop (from account-level saved addresses, distinct from personal saved addresses per Section 11's note at Screen 11), recurrence pattern (days of week, time window), exception handling (skip specific dates — holidays — without needing to delete and recreate the whole recurring rule). Edge cases: the employee who created the rule is later removed (14B.1) — ownership auto-transfers per that section's rule; a specific occurrence's auto-booking attempt would exceed the live credit limit (14A.1) at execution time — that single occurrence fails gracefully with an admin alert, the recurring rule itself continues for future occurrences rather than being cancelled outright by one failed instance. Acceptance: a single failed occurrence never disables the entire recurring rule — each occurrence is evaluated independently at its own execution time.

**API Key Management**
Purpose: corporate self-serve API access (Section 23's public developer API) — generate/revoke keys, scoped to the issuing account only, view usage/rate-limit status. Edge cases: a revoked key used in an in-flight request that was already authenticated before revocation propagated — acceptable brief overlap window (config, e.g., a few seconds of cache TTL on key validation) is disclosed in the API documentation rather than promising instantaneous revocation that the actual caching architecture can't truthfully guarantee. Acceptance: key revocation takes effect within the disclosed propagation window, and that window is documented, not an unstated assumption.

### A.9 Marketing — Remaining Screens

**Campaign Builder**
Purpose: groups a coupon (15A.1) + push/banner content (9B.1) + a target segment (below) into one trackable initiative. Acceptance: campaign-level performance reporting (redemption rate, reach) reconciles to the same underlying coupon-redemption and notification-delivery data already governed by Sections 15A.1 and 16 — never a separately-tracked, potentially-divergent number.

**Segment Builder**
Purpose: rule-based user segmentation (Section 15's description) feeding both coupon targeting and campaign targeting. Fields: rule conditions (attribute + operator + value, combinable with AND/OR), live-preview of matching user count before saving. Edge cases: a segment definition that would match zero users (e.g., an overly narrow AND-chain) — flagged with the zero-count preview before the segment is used in a live campaign, preventing a campaign silently launched to nobody. Acceptance: every segment shows a live matching-count preview before it can be attached to an active campaign or coupon.

### A.10 Analytics — Remaining Dashboards

**CAC/LTV Dashboard**
Purpose: acquisition-cost vs lifetime-value by channel, feeding Marketing budget decisions. Fields: acquisition channel filter, cohort period. Acceptance: LTV calculation uses the same versioned-definition discipline as every other metric in this document (20A.1/20B.2's pattern) — the exact revenue-attribution window and what counts toward "lifetime value" is documented and stable across report periods.

**Cancellation-Rate Breakdown**
Purpose: cancellation rate by reason/zone/time, directly consuming the `reason_code` taxonomy from 2A.1 (customer-side) and the driver-side cancellation equivalent. Acceptance: every cancellation reason shown in this dashboard traces to an actual `reason_code` value from the fixed taxonomy used at cancellation time (2A.1) — never a free-text bucket that can't be reliably aggregated.

**Driver Utilization Dashboard**
Purpose: online-hours vs trip-hours ratio, per driver/zone/time — consumes the Online/Offline toggle events (Home screen, A.2) and trip-completion events (Section 22). Acceptance: utilization percentage is computed from the same authoritative online/offline state transitions the Driver App itself relies on (per the Home-screen acceptance criteria above), never a separately-inferred approximation.

**Revenue Dashboards**
Purpose: gross bookings, net revenue, take-rate by category/zone/time — the top-level business dashboard, drawing on the same ledger (Section 6) as the Finance Settlement Dashboard (A.6) but sliced for a broader leadership audience. Acceptance: identical to A.6's rule — every figure reconciles exactly to the underlying ledger; this and the Finance dashboard must never show different numbers for the same period (verified by a cross-dashboard consistency check as part of QA, Section 26/29).

### A.11 RBAC — Remaining Screens

**User-Role Assignment**
Purpose: assign one or more roles (22A.1) to a specific admin/ops/support/finance user, with scope (e.g., city-list for a scoped role). Edge cases: assigning a second role to a user whose permission sets conflict or overlap in an unexpected way (e.g., one role explicitly denies an action a second role grants) — the resolution rule (most-permissive-wins, or explicit-deny-always-wins) must be a documented, deterministic platform-wide policy, not an ambiguous per-case outcome. Acceptance: multi-role permission resolution follows one documented, tested rule applied consistently everywhere, verified by the same permission-matrix test suite referenced in Section 22's original acceptance criteria.

**Permission Audit View**
Purpose: queryable both by user ("what can this person do") and by permission ("who can approve refunds over the limit") — a reporting layer over the same role/permission/user tables (Section 24) rather than a separately-maintained mapping. Acceptance: this view's output is always derived live from the actual `role_permissions`/`user_roles` tables (Section 24), never a cached/manually-maintained document that can drift from the real enforced state.

---

## 20. UNIVERSAL SCREEN SPEC TEMPLATE

Apply this exact structure to every remaining Tier-2 screen not individually detailed above, before it goes into a sprint:

```
SCREEN: [name]
MODULE: [module]
PURPOSE: [one sentence — why this screen exists]

LAYOUT: [key blocks/components, top to bottom]

FIELDS (if applicable):
| Field | Type | Validation rule | Error copy |

STATES: default / loading / empty / error / permission-denied / [any domain-specific states]

EDGE CASES:
- [list every non-happy-path scenario specific to this screen]

API(S) CALLED: [endpoint(s), method, request/response shape]

PERMISSIONS REQUIRED: [role/permission, if admin-side]

ANALYTICS EVENTS FIRED: [event name(s) → Section 22 event catalog]

ACCEPTANCE CRITERIA:
- [ ] [specific, testable statement]
- [ ] [specific, testable statement]

QA TEST CASES:
| Scenario | Expected Result |
```

---

## 32. GLOBAL DEVELOPER ACCEPTANCE CRITERIA (applies to every module in this document)

- [ ] No feature is considered done without the module's Acceptance Criteria checklist fully passing.
- [ ] No financial mutation (wallet, payout, refund, invoice) exists outside the double-entry ledger pattern (Section 6).
- [ ] No price is ever shown or charged without a traceable `fare_breakdown` (Section 5).
- [ ] No privileged action (Admin/Ops/Support/Finance) exists without RBAC enforcement at the API layer (Section 22) and an audit log entry (Section 24).
- [ ] No screen ships without defined empty/loading/error/permission states (Section 20 template).
- [ ] No background job or async flow can leave the system in a silently-inconsistent state (Section 26).
- [ ] Every module's edge cases listed in this document are covered by an explicit test case before release, not assumed handled.

---

*End of Master PRD v1.0. This document is the requirements specification your team builds against — implementation-specific technical design docs (exact schema DDL, exact API OpenAPI files, exact component library Storybook) are companion artifacts that should be generated from and kept traceable back to this PRD, not maintained as a separate source of truth.*
