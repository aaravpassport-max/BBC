# Logistics Super App — Driver Partner App

React + TypeScript + Vite reference frontend implementing the Driver App's
core PRD flow: sign in -> get KYC-approved -> register a vehicle -> go
online -> receive and accept a job -> verify pickup/drop -> earn.

## Requirements

- Node.js 20+
- The backend running locally (see `../backend/README.md`)

## Setup

```bash
npm install
cp .env.example .env
npm run dev        # http://localhost:5175
```

## What's implemented

- **Login / Verify** - same phone+OTP pattern as the Customer app.
- **KYC** (`/kyc`) - registers as a driver, shows each required document's
  review status, and lets you submit/resubmit a document link. Real
  document upload (file storage) is out of scope for this reference
  frontend - it accepts a URL string, matching the backend's
  `document_url` field.
- **Training** (`/training`) - the mandatory video + quiz gate (PRD 3.2),
  enforced exactly as strictly as document KYC: a driver who passes KYC but
  hasn't passed training is routed here, not to Home. Video progress is
  resumable and monotonically increasing; the quiz has a real pass
  threshold, a retake cooldown, and routes to manual review after the max
  attempts rather than a dead end with no path forward.
- **Home** (`/home`) - online/offline toggle; while online, polls for a
  pending job offer or an already-active job and routes to the right
  screen automatically (so closing and reopening the app mid-job resumes
  correctly). Sends real device GPS on every poll tick while online (with
  a deliberate fallback to a demo coordinate — see the note in
  `DriverHomePage.tsx` for why). Fires a real OS-level notification the
  moment a new job offer arrives.
- **Offer** (`/offer/:offerId`) - accept/decline with a live countdown ring
  matching the offer's actual server-side expiry.
- **Trip** (`/trip/:bookingId`) - shows the stop sequence, and the OTP entry
  the driver uses to confirm pickup and each drop - critically, this screen
  never displays the OTP itself, only accepts what the customer tells the
  driver (see the Security section below). Also includes a **real in-app
  chat** with the customer on this trip, and a **real "Navigate" button**
  deep-linking to the device's own maps app with the correct pickup or
  next-drop coordinates - drop-stop coordinates were always stored in the
  database but never returned to this app before this pass (a real gap,
  not a simplification - see `driver.service.ts`'s `getMyActiveJob`).
- **Earnings** (`/earnings`) - withdrawable balance (correctly excluding
  any amount currently frozen by a fraud hold), a withdrawal request, and
  a **real earnings history** list (penalties, withdrawals, and any other
  ledger entry) - reuses the wallet module's existing generic transaction
  history function, which had only ever been called for customers before.

## Verifying it against a real backend

```bash
npm run e2e
```

Drives the actual UI in a real browser through the ENTIRE flow — login,
document submission, an actual KYC reviewer approval via the Admin API,
vehicle registration, going online, a real customer booking a trip via the
API, dispatch offering it to this exact driver, accepting, and completing
both OTP steps with the real codes — then asserts the booking is genuinely
`completed` server-side. Screenshots saved to `e2e-screenshots/`.

This exact script is how a real, serious gap was found during development:
running the KYC + job flow through the actual UI (rather than the backend
test suite's SQL-fixture shortcuts) surfaced that there was no API endpoint
anywhere for an independent driver to register a vehicle — meaning a real
driver could complete onboarding, get approved, and go online, and simply
never receive a job, with no error explaining why. Fixed in the backend
(`POST /v1/driver/vehicles`) as a direct result of this script catching it.

## Security note

The Trip screen's OTP input is intentionally one-directional: the backend's
`GET /v1/driver/jobs/active` endpoint does NOT return the pickup or drop
OTP codes to the driver (an earlier version of the backend did, which
defeated the entire point of the verification — see the fix noted in the
backend's `driver.service.ts`). The driver must be told the code by the
customer in person, matching PRD 2.2.7's actual security intent.

## Android app

This same React codebase ships as a real native Android app via
[Capacitor](https://capacitorjs.com) — an installable app with its own
icon, real device GPS and camera access, and a real Play Store-ready
project structure (`android/`).

**What's already done:** Capacitor is installed and configured
(`capacitor.config.ts`, appId `com.waybill.driver`), the native Android
project is scaffolded and synced with all 6 plugins (App, Camera,
Geolocation, Push Notifications, Splash Screen, Status Bar), and two real
features are wired in, not just packaged:

- **Real GPS tracking** (`DriverHomePage.tsx`) — while online, the app
  reads the device's actual location on every poll tick instead of a fixed
  demo coordinate, falling back to that demo coordinate only if GPS is
  unavailable or permission is denied. The fallback is deliberate, not a
  shortcut: a driver's real GPS position will very often fall outside the
  backend's fixed demo service-zone polygon (any real-world testing
  location isn't Bengaluru), which would otherwise silently stop dispatch
  from ever matching them, with no visible error explaining why.
- **Real camera capture for KYC documents** (`KycPage.tsx`) — a "📷
  Camera" button next to the existing paste-a-link option opens the device
  camera (or a file picker on web) and submits the captured photo as a
  base64 data URL. This works end-to-end against the real backend with
  zero backend changes, since `document_url` accepts any string with no
  strict URL-format validation.

The required location permissions are declared in
`android/app/src/main/AndroidManifest.xml` (the Geolocation plugin's own
bundled manifest is intentionally empty — Android requires the host app to
declare these).

**What you need to actually produce an installable `.apk`:** Android
Studio (or the Android SDK command-line tools) on a real machine — this
reference package was built in a sandboxed environment with no Android SDK
and no network access to Google's Maven/Gradle servers, so the final
native compile step could not be run or verified here.

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

## Known gaps

- The Training screen's video player is a stand-in - a "mark as watched"
  button simulates completing the video, since there's no real video asset
  or player wired up. It calls the same real progress-tracking API a real
  player's timeupdate handler would.
- No real map / turn-by-turn navigation between stops.
- No document *file* upload - a URL field stands in for it.
- No penalty-dispute screen yet (the API exists in the backend).
