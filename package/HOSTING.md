# Getting Your App Live on the Internet — A Plain-Language Guide

*Written for Waybill's owner, August 2026*

---

## First: what "hosting" actually means

Everything I've built so far runs perfectly — but only while I'm actively
working on it in this chat. The moment our conversation ends, it switches
off. That's normal for how I work; it's a temporary workspace, not a
permanent home for your app.

**Hosting means renting a computer that never turns off**, so your app
is reachable by real customers, all day, every day, whether you're
looking at it or not. That's the entire idea — nothing more mysterious
than that.

Your app actually needs **two** things running permanently:

1. **The app itself** (the part that handles bookings, payments, driver
   matching, and so on)
2. **A database** (where every customer, driver, trip, and payment
   record actually lives)

Good news: I already built the real, tested instructions (a "Dockerfile"
and "docker-compose" setup, if you ever hear those words) that tell a
hosting company's computers exactly how to run both of these correctly.
That work is done. What's left is picking *where* those instructions
actually run, and paying for it.

---

## Picking a hosting company — your real options

This is genuinely a business decision, not a technical one — think of it
like choosing an electricity provider for a store: several good options
exist, they all keep the lights on, and the right one depends on budget
and how hands-on you want to be.

### The simple option (recommended to start)

**Render** — a hosting company built for exactly this kind of app. You
connect it to your code, and it handles the technical details.

- **Real, current cost estimate for your app: roughly $20–30/month** to
  start (this covers running the app itself plus the database,
  always-on, for real but modest traffic — a few dozen to a few hundred
  bookings a day). Costs are usage-based and grow with real traffic, but
  they don't jump unpredictably.
- **Important technical detail I checked specifically for your app**:
  your app uses real map/location features (matching pickup and drop
  points, calculating distances) which need a specific database
  capability called PostGIS. I confirmed directly that Render supports
  this — it's not a guess.
- Setup is mostly point-and-click, with real documentation.

### The "more control, more moving parts" option

**Amazon Web Services (AWS), Google Cloud, or Microsoft Azure** — the
large, well-known cloud providers most bigger companies eventually use.

- More powerful and more customizable, but genuinely more complex to set
  up correctly and securely — this is realistically a job for a
  developer, not a first-time solo setup.
- Costs can start similar to Render for a small app, but pricing is far
  less predictable and mistakes here (like leaving something
  misconfigured) can be expensive.
- Worth moving to later, once you have real, meaningful traffic — not a
  sensible starting point.

### My honest recommendation

**Start with Render.** It's the option built for exactly your situation
(a real, working app that needs to go live without a large technical
team), the cost is predictable, and I already confirmed it genuinely
supports the specific location-features your app needs — which isn't
true of every hosting company.

---

## What actually needs to happen, step by step

Here is the realistic sequence, in plain terms:

1. **Create a Render account** and connect it to wherever your app's
   code lives (a code-hosting service called GitHub is the standard
   place for this — if you don't have your code there yet, that's a
   real, separate small step).
2. **Tell Render to create a database** — a few clicks in their
   dashboard.
3. **Tell Render to run the app** — using the exact instructions I
   already built and tested (the Dockerfile). This step is genuinely
   "point Render at the code, click deploy."
4. **Run the one-time setup commands** I built and personally tested —
   these create all the real tables (customers, drivers, bookings, and
   so on) and load in the starting reference data (like vehicle types
   and service areas). I proved these work by wiping a test database
   completely and rebuilding it from nothing.
5. **Add your real business information** once you have it — the
   payment company details and text-message company details from the
   earlier pieces of this project. Nothing about hosting depends on
   having those yet; the app runs and can be tested without them, just
   like it has been throughout this whole project.
6. **Point your domain name at it** (if you have or buy one, like
   `waybill.com`) — an optional, separate small step for later, so
   customers see a proper web address instead of a technical one Render
   generates automatically.

None of this requires touching the app's underlying code — that part is
finished.

---

## Production environment variables (for your developer)

Set these in Render (or your host) before going live with real customers:

| Variable | Required for | Notes |
|----------|--------------|-------|
| `ALLOW_TEST_OTP` | Security | Set to `false` in production |
| `CORS_ORIGIN` | Web apps | Comma-separated frontend URLs |
| `RAZORPAY_KEY_ID/SECRET/WEBHOOK_SECRET` | Payments | Wallet top-ups + trip card/UPI payments |
| `MSG91_*` | OTP SMS | DLT-approved template required |
| `FCM_PROJECT_ID` + `FCM_SERVICE_ACCOUNT_JSON` | Push | Firebase service account JSON |
| `GOOGLE_PLACES_API_KEY` | Address search | Server-side proxy; Nominatim fallback if unset |
| `EXOTEL_*` | Masked calling | Customer ↔ driver calls without exposing numbers |
| `PLATFORM_GSTIN` + `PLATFORM_LEGAL_NAME` | GST invoices | Server-generated PDF invoices |

Configure the Razorpay webhook URL to `https://your-api.com/v1/wallet/webhook` — the backend verifies signatures using the raw request body.

---

## If you hire a developer for this part

Hand them exactly this, and it should be a short, well-scoped job:

> "The backend is a Node.js/TypeScript app with a PostgreSQL database
> (PostGIS extension required). A working Dockerfile and
> docker-compose.yml already exist in the `backend/` folder, along with
> tested database migration and seed scripts (`npm run migrate`,
> `npm run seed`). Please deploy this to Render (or your recommended
> equivalent), connect the real environment variables, and confirm the
> `/health` endpoint responds and a full booking can be created
> end-to-end."

A developer reading that sentence will immediately know exactly what
exists, what's tested, and what's left — which is the whole point of
handing it off cleanly instead of vaguely.

---

## One thing worth setting expectations on

Even once this is "live," it will genuinely be a small, real,
functioning version of your app — not yet ready for thousands of
customers on day one. That's normal and correct for this stage. Real
products almost always start small, get tested with real (small-scale)
usage, and grow the hosting power behind them as real demand grows. You
are not behind by starting this way — it's the standard path.
