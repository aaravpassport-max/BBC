# Logistics Super App — Customer Web App

React + TypeScript + Vite reference frontend implementing the Customer App's
core PRD flow: phone login -> book -> track. This is the first frontend
surface built against the backend in this package; Driver App and Admin
Dashboard UIs are not yet built (their APIs exist in the backend, see its
README's "Known gaps" section).

## Requirements

- Node.js 20+
- The backend running locally (see `../backend/README.md`) - this app talks
  to it directly, there is no mock/offline mode.

## Setup

```bash
npm install
cp .env.example .env
# edit .env if your backend isn't on the default http://localhost:3000

npm run dev        # http://localhost:5173
```

## What's implemented

- **Login** (`/login`) - phone entry, real OTP request against the backend.
- **Verify** (`/verify`) - 6-digit code entry with auto-advance, paste
  support, resend, and lockout handling.
- **Home** (`/home`) - pickup/drop selection (a fixed set of real, working
  coordinates inside the backend's seeded service zone - see the note in
  `src/pages/HomePage.tsx` for why this isn't a full maps integration yet)
  and item details, then real, live prices across **5 genuinely different
  vehicle categories** (two-wheeler through large truck, matching Porter's
  actual published lineup) - tap one to proceed. Also supports a real "Use
  my current location" pickup option via device GPS (Capacitor's
  Geolocation plugin - same code path on web and native Android), and a
  real **"Schedule for later"** option (P1 gap-analysis item) - the
  backend enforces genuine lead-time (30 min minimum) and advance-window
  (7 days maximum) rules, and a scheduled booking is provably not
  dispatched until its own real backend sweep job decides it's time - see
  the top-level README for how that was verified.
- **Confirm** (`/confirm`) - the fare breakdown for the vehicle category
  you chose, using the app's signature "Waybill" component
  (`src/components/Waybill.tsx`) - a perforated cargo-manifest-styled card
  - then confirms the booking.
- **Track** (`/track/:bookingId`) - polls the booking every 3s, shows the
  pickup/drop OTP codes for the customer to read aloud, lets you cancel
  while cancellable, and shows a receipt + star rating once completed.
  Once a driver is assigned, also shows a **real live map** (OpenStreetMap/
  Leaflet - no API key needed) with the driver's actual position updating
  as they move, and a **real in-app chat** with that driver — both driven
  by real backend endpoints, not placeholders. Fires a real OS-level
  notification (native on Android, browser notification on web) on every
  genuine status change, not on every poll tick.
- **Wallet** (`/wallet`) - real balance, real transaction history, and a
  real payment gateway integration (Razorpay) for adding money - genuine
  order creation and signature verification when the backend has real
  credentials configured, falling back to a clearly-marked simulated flow
  otherwise so this screen is fully testable without one. See the
  top-level README for how to activate real payment processing.
- **History** (`/history`) - the real trip list (backend already
  supported this via `listBookings`; it just had never been wired to any
  screen) - tap a trip to jump back into its real tracking page.
- **Receipt** (`/receipt/:bookingId`) - a real, printable/downloadable
  invoice (P2 gap-analysis item) - previously only an inline fare summary
  existed on the tracking screen itself, with nothing standalone or
  shareable. Uses the browser's own native print pipeline
  (`window.print()` with print-specific CSS) rather than a client-side PDF
  library - "Save as PDF" already exists in every browser's print dialog,
  on every platform, including inside the Android app's WebView, so this
  needed zero new dependencies to work everywhere.

## Verifying it against a real backend

```bash
npm run e2e
```

This drives the actual running app in a real (Playwright-controlled)
browser through the full login -> book -> track flow against your locally
running backend, and saves screenshots to `e2e-screenshots/`. It reads the
OTP code from the backend's console log (`../backend/server.log` by
default - override with `E2E_BACKEND_LOG` if your layout differs), the same
way the backend's own Jest test helpers do, since there's no real SMS
provider in dev. Requires both the backend (`npm run dev` in `../backend`)
and this app (`npm run dev` here) to already be running.

## Design notes

Dark, warm-amber palette (`src/index.css` holds every token) built around a
"shipping waybill" visual identity - perforated card edges, monospace
figures for every number/code (fare lines, OTP digits), Space Grotesk for
headings. The Waybill card is the one deliberately bold element in an
otherwise restrained UI, per the design brief's own guidance to spend
boldness in one place.

## Android app

This same React codebase ships as a real native Android app via
[Capacitor](https://capacitorjs.com) — not a bookmarked website, an
installable app with its own icon, real device GPS access, and a real
Play Store-ready project structure (`android/`).

**What's already done:** Capacitor is installed and configured
(`capacitor.config.ts`, appId `com.waybill.customer`), the native Android
project is scaffolded and synced, the required location permissions are
declared in `android/app/src/main/AndroidManifest.xml`, and the "Use my
current location" pickup feature (`HomePage.tsx`) uses Capacitor's
Geolocation plugin — the exact same code path works on web (delegates to
the browser) and on native Android (real GPS with a permission prompt), no
platform branching required.

**What you need to actually produce an installable `.apk`:** Android
Studio (or just the Android SDK command-line tools) on a real machine —
this reference package was built in a sandboxed environment with no
Android SDK and no network access to Google's Maven/Gradle servers, so the
final native compile step could not be run or verified here. Everything
up to that step (the web build, the Capacitor project, the plugin wiring,
the manifest) is real and has been verified as far as this environment
allows.

To build the real app, on a machine with Android Studio installed:

```bash
# 1. Point the build at a backend the Android emulator/device can reach —
#    "localhost" means something different from inside an emulator than on
#    your dev machine. See .env.android.example for the details.
cp .env.android.example .env

npm install
npm run build          # builds the web app into dist/
npx cap sync android   # copies the web build + plugin config into android/

# 2a. Open in Android Studio and run/build from there, OR:
cd android
./gradlew assembleDebug          # produces app/build/outputs/apk/debug/app-debug.apk
# ./gradlew bundleRelease        # for a signed Play Store release build
```

That's the entire remaining step — a normal Android build, taking the
usual few minutes Gradle needs, not a rewrite of anything. The web app
inside it is exactly what you've already been testing against the backend
all session.

## Known gaps

- Address entry uses a fixed preset list, not a real Places/maps
  autocomplete (PRD 2.2.4) - flagged directly in `HomePage.tsx`. Real
  device GPS is supported as an alternative pickup method, though (see
  above).
- No push notifications wired to a real server-push provider (Firebase) -
  real OS-level notifications ARE implemented, triggered by this app's own
  polling rather than server push; see the top-level README for why.
