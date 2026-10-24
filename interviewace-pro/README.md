# InterviewAce — AI Interview Preparation Platform

**Version:** 2.6.1  
**Requires:** PHP 7.4+, WordPress 6.0+, MySQL 5.7+  
**License:** Proprietary

---

## Overview

InterviewAce is a WordPress plugin that delivers a full-stack AI-powered mock interview preparation platform for Indian job seekers. It replaces the WordPress theme with a React 18 SPA served from the root domain, while using WordPress as the auth/DB/REST backend.

---

## Architecture

```
interviewace/
├── interviewace.php          # Plugin bootstrap, routing, IA_CONFIG injection
├── uninstall.php             # Clean uninstall (removes all tables and options)
│
├── includes/                 # Core PHP classes
│   ├── class-activator.php   # DB table creation (16 tables), page setup, cron registration
│   ├── class-deactivator.php # Cron cleanup, scheduled cleanup jobs
│   ├── class-jwt.php         # Pure PHP HMAC-SHA256 JWT (no dependencies)
│   ├── class-auth-middleware.php  # Bearer token validation for REST routes
│   ├── class-plan-enforcer.php    # Quota enforcement, rate limiting
│   ├── class-claude.php      # Claude API client (generate_plan, turn, generate_report, parse_jd, parse_resume, parse_ats)
│   ├── class-cost-tracker.php    # Per-user API cost tracking (paise)
│   └── class-pdf-report.php  # PDF report generation + HMAC-gated download
│
├── api/                      # REST API controllers (all at /wp-json/ia/v1/*)
│   ├── class-api-auth.php    # /auth/* — register, login, OTP, refresh, Google OAuth
│   ├── class-api-profile.php # /profile — CRUD, JD parse, resume upload, stats
│   ├── class-api-interviews.php  # /interviews — create, GET, turn, end, report-cost
│   ├── class-api-reports.php # /reports — by-interview, ideal answer
│   ├── class-api-history.php # /history, /library CRUD
│   ├── class-api-billing.php # /billing — Razorpay subscriptions, webhook, plans
│   ├── class-api-gamification.php  # /gamification — XP, badges, SM-2 review queue
│   ├── class-api-resume.php  # /resume — parse, ATS score
│   └── class-api-ats.php     # /ats/score
│
├── cron/
│   ├── class-report-generator.php  # ia_generate_report WP-Cron job (triggered + spawn_cron)
│   └── class-cron-manager.php      # Cleanup cron jobs (tokens, OTPs, usage)
│
├── admin/
│   ├── class-settings-page.php     # API keys, Plan Builder, Quota Settings, Priya avatar
│   └── class-cost-dashboard.php    # Real-time API cost tracking dashboard
│
├── templates/
│   ├── app-page.php          # React SPA shell (admin bar suppressed)
│   └── public/               # Server-rendered SEO pages
│       ├── header.php / footer.php
│       ├── home.php           # Full landing page with hero, features, testimonials, FAQ
│       ├── pricing.php
│       ├── about.php
│       ├── privacy.php        # DPDP Act 2023 compliant
│       └── terms.php
│
├── react-app/
│   └── dist/
│       ├── app.js            # Compiled React SPA (esbuild IIFE, ~260kb)
│       └── app.css           # Complete stylesheet (522 lines)
│
└── assets/
    └── priya-photo.jpg       # Default Priya avatar (changeable from admin)
```

---

## Database Tables (prefix: `ia_`)

| Table | Purpose |
|---|---|
| `ia_profiles` | User profiles, resume text, JD text, parsed JSON |
| `ia_interviews` | Interview sessions (type, status, scores, timing) |
| `ia_turns` | Individual Q&A turns (role, transcript, WPM, fillers) |
| `ia_reports` | Generated AI reports (JSON blob + individual score columns) |
| `ia_saved_answers` | Answer Library entries (question, user answer, ideal answer) |
| `ia_subscriptions` | Razorpay subscriptions (plan, status, period dates) |
| `ia_payments` | Payment records (amount, currency, Razorpay IDs) |
| `ia_usage` | Monthly usage tracking per user |
| `ia_refresh_tokens` | JWT refresh token store |
| `ia_otp_codes` | OTP codes for email verification |
| `ia_signup_attempts` | Rate limiting for registration |
| `ia_score_aggregates` | Benchmark percentile data per role |
| `ia_badges` | Earned badges per user |
| `ia_xp_events` | XP history log |
| `ia_review_queue` | SM-2 spaced repetition queue |
| `ia_api_costs` | Per-call API cost log (Claude, Deepgram, ElevenLabs) |

---

## REST API Reference

All endpoints: `/wp-json/ia/v1/`  
Authentication: `Authorization: Bearer <jwt_token>` header  
Error format: `{"code":"ia_xxx","message":"Human readable","data":{"status":4xx}}`

### Auth (`/auth/*`) — Public

| Method | Path | Description |
|---|---|---|
| POST | `/auth/register` | Register + send OTP email |
| POST | `/auth/verify-otp` | Verify OTP, issue JWT pair |
| POST | `/auth/resend-otp` | Resend OTP (rate limited) |
| POST | `/auth/login` | Login — returns `{token, refresh_token, user}` |
| POST | `/auth/logout` | Invalidate refresh token |
| POST | `/auth/refresh` | Exchange refresh for new access token |
| POST | `/auth/forgot-password` | Send password reset email |
| GET | `/auth/me` | Get current user (🔒 auth) |
| GET | `/auth/google` | Start Google OAuth flow |
| GET | `/auth/google-callback` | OAuth callback handler |

### Profile (`/profile/*`) — Auth required

| Method | Path | Description |
|---|---|---|
| GET | `/profile` | Get profile |
| PUT | `/profile` | Update profile |
| POST | `/profile/jd` | Parse job description (saves text; Claude parse optional) |
| POST | `/profile/resume` | Upload + parse resume PDF/DOCX |
| GET | `/profile/stats` | Dashboard stats (total, avg score, streak, trend) |

### Interviews — Auth required

| Method | Path | Description |
|---|---|---|
| POST | `/interviews` | Create interview (checks plan quota) |
| GET | `/interviews/{id}` | Get interview details |
| POST | `/interviews/{id}/start` | Mark interview started |
| POST | `/interviews/{id}/turn` | Submit answer, get AI response |
| POST | `/interviews/{id}/end` | End interview, trigger report generation |
| GET | `/interviews/{id}/turns` | Get all turns |
| POST | `/interviews/{id}/report-cost` | Report Deepgram/ElevenLabs costs |
| POST | `/interviews/{id}/ideal` | Generate ideal answer for a turn |

### Reports — Auth required

| Method | Path | Description |
|---|---|---|
| GET | `/reports/by-interview/{id}` | Get report (polls until ready) |
| GET | `/reports/{id}/pdf` | Download PDF (HMAC token gated) |

### Billing — Mixed

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/billing/plans` | Public | Get plan config (for pricing page) |
| GET | `/billing/status` | Auth | Current subscription status |
| POST | `/billing/create-subscription` | Auth | Create Razorpay subscription |
| POST | `/billing/cancel` | Auth | Cancel subscription |
| GET | `/billing/invoices` | Auth | Payment history |
| POST | `/billing/webhook` | Public+HMAC | Razorpay webhook handler |

### Gamification — Auth required

| Method | Path | Description |
|---|---|---|
| GET | `/gamification/profile` | XP, level, badges, recent events |
| GET | `/gamification/review-queue` | SM-2 cards due today |
| POST | `/gamification/review` | Submit card review (quality 1–5) |
| POST | `/gamification/enqueue/{id}` | Add library entry to review queue |

---

## Configuration — WP Admin → InterviewAce

### API Keys tab
| Setting | Description |
|---|---|
| `ia_claude_key` | Anthropic Claude API key (required for interviews) |
| `ia_deepgram_key` | Deepgram API key (optional — falls back to Web Speech API) |
| `ia_elevenlabs_key` | ElevenLabs API key (optional — falls back to browser TTS) |
| `ia_elevenlabs_voice` | ElevenLabs voice ID |
| `ia_razorpay_key_id` | Razorpay key ID (required for paid plans) |
| `ia_razorpay_key_secret` | Razorpay secret key |
| `ia_razorpay_plan_pro` | Razorpay Plan ID for Pro tier |
| `ia_razorpay_plan_premium` | Razorpay Plan ID for Premium tier |
| `ia_razorpay_webhook_secret` | Razorpay webhook signature secret |
| `ia_google_client_id` | Google OAuth client ID |
| `ia_google_client_secret` | Google OAuth client secret |
| `ia_priya_avatar_url` | Priya avatar image URL (media picker) |

### Plan Builder tab
Edit plan names, prices, descriptions, and feature lists. All values stored as `ia_plan_{tier}_{field}` wp_options.

### Quota Settings tab
Configure interview limits per plan tier. Stored as `ia_quota_*` wp_options.

---

## Installation

1. Upload and extract `interviewace-plugin.zip` to `wp-content/plugins/interviewace/`
2. Activate plugin in **WP Admin → Plugins**
3. Plugin creates 16 database tables and 5 public pages automatically on activation
4. Go to **WP Admin → InterviewAce** and enter your Claude API key
5. Set homepage: WP Admin → Settings → Reading → Front page → `InterviewAce Root`
6. Visit your domain — the React SPA loads automatically

**Required on clean reinstall:** Delete the old plugin folder completely before uploading. WordPress does not delete old files on update.

---

## Environment Requirements

| Requirement | Minimum | Notes |
|---|---|---|
| PHP | 7.4 | No union types or typed static props from 8.0+ |
| WordPress | 6.0 | Uses `wp_send_json_error` and REST API features |
| MySQL | 5.7 | Uses `ON UPDATE CURRENT_TIMESTAMP`, `JSON` columns |
| SSL | Required | Web Speech API and microphone require HTTPS |
| Browser | Chrome/Edge latest | Firefox, Safari also work with degraded STT |

---

## Key Design Decisions

**No Composer:** The plugin ships zero PHP dependencies. JWT is implemented in ~60 lines of pure PHP HMAC-SHA256. This ensures it works on shared hosting without Composer.

**No npm in production:** The React app is pre-compiled to a single `app.js` IIFE via esbuild. No build step needed on the server.

**API key fallbacks:** Every external API has a graceful fallback:
- No Deepgram → Web Speech API (browser built-in)
- No ElevenLabs → Web Speech Synthesis (browser built-in)  
- No Claude → Default question plan, no AI feedback

**WP-Cron + spawn_cron:** Reports trigger `spawn_cron()` immediately after scheduling to avoid the "only fires on page visit" problem.

**ia_key() function:** Reads WP options directly with `strtolower($name)` — e.g. `ia_key('IA_CLAUDE_KEY')` reads `get_option('ia_claude_key')`. No double-prefix bug.

---

## Security Model

- **JWT access tokens:** 15-minute TTL, HMAC-SHA256, stored in memory (not localStorage)
- **Refresh tokens:** 30-day TTL, stored in `ia_refresh_tokens` table, single-use rotation
- **OTP:** 6-digit numeric, 10-minute TTL, hashed in DB
- **Login rate limit:** 5 failed attempts → 15-minute lockout per email+IP
- **Signup rate limit:** 1 signup per device per day, 3 per IP total
- **Razorpay webhook:** HMAC-SHA256 signature verification before processing
- **PDF downloads:** HMAC-SHA256 token, 1-hour TTL, user-scoped
- **API permissions:** Every authenticated endpoint validates JWT on every request
- **Public endpoints:** `/billing/plans` (pricing page) and `/billing/webhook` (Razorpay) only

