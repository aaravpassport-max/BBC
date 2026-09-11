# Changelog

All notable changes to InterviewAce are documented here.

## [2.6.1] — Current

### Fixed
- Zero duplicate CSS rules (was 81 duplicates causing style conflicts)
- All 288 JSX class names now have CSS rules (was 28 missing)
- `srRef` declared with `useRef(null)` — was used but never declared
- Review Queue page added to sidebar navigation
- Razorpay script lazy-loaded if not present at click time
- Plugin header version synced with `IA_VERSION` constant

### Added
- `ia_check_requirements()` gate — shows admin notice if PHP/WP version too old
- `IA_MIN_PHP`, `IA_MIN_WP`, `IA_MIN_MYSQL` constants
- README.md — full architecture, API reference, config docs
- CHANGELOG.md
- PHPUnit test suite (`tests/`)

## [2.6.0] — Public pages + Priya avatar admin

### Added
- 5 server-rendered SEO public pages (home, pricing, about, privacy, terms)
- Home page: hero, 9-feature grid, how-it-works, 6 testimonials, pricing preview, 7-FAQ (Schema.org)
- Priya avatar image manager in admin settings (WordPress media library picker)
- `ia_priya_avatar_url` wp_option + `priyaAvatarUrl` in IA_CONFIG
- `create_public_pages()` in activator — pages created on plugin activation
- Public page template routing in `template_include` filter

## [2.5.1] — Full CSS audit

### Fixed
- Complete CSS rewrite — single authoritative file, no duplicates
- All missing classes added: `ia-steps`, `ia-swave`, `ia-otp-row`, `ia-field`, `ia-label` etc.

## [2.5.0] — Layout, mobile UX, JD fix, report speed

### Fixed
- WebSocket `subprotocol '' is invalid` — DKEY guard before `new WebSocket()`
- `catch(e)` set `micOk=false` for ALL errors — now only for `NotAllowedError`
- JD "Analysis Skipped" — PHP now saves JD text regardless of Claude availability
- Report generation slow — `spawn_cron()` called immediately after scheduling
- Report email link `/app/report/` → `/report/`
- Claude token output 4000 → 2500 (faster, still comprehensive)

### Added
- Mobile bottom navigation bar (Home, History, Progress, ATS, Profile, Review)
- Dedicated mobile layout at `@media(max-width:768px)` — app-like experience
- Answer box placeholder text
- Tips panel made compact

## [2.4.0] — API key double-prefix bug fix

### Fixed
- `ia_key()` was reading `get_option('ia_ia_deepgram_key')` (double prefix) — API keys always returned empty
- Fixed: `ia_key('IA_DEEPGRAM_KEY')` now reads `get_option('ia_deepgram_key')` correctly
- All API keys (Claude, Deepgram, ElevenLabs, Razorpay) now read correctly
- `setMicOk(false)` only fires on `NotAllowedError` / `PermissionDeniedError`

## [2.3.0] — Photo Priya + admin bar fix

### Added
- Photo-based Priya avatar replacing SVG cartoon
- Audio-reactive glow ring (Web Audio API)
- Speaking/listening/thinking state overlays

### Fixed
- WordPress admin bar showing on interview page — suppressed via CSS + PHP hook
- `ia_is_root_app()` excludes public page slugs

## [2.2.0] — WebSocket + layout fixes

### Fixed
- WebSocket `subprotocol ''` crash when no Deepgram key
- Web Speech API fallback added
- Interview room CSS class mismatches

## [2.1.0] — Phase 2 features

### Added
- ATS resume analysis
- Gamification (XP, 20 badge types, SM-2 spaced repetition)
- Enhanced Answer Library with review queue
- Analytics dashboard with trend chart
- PDF report download (HMAC-gated)
- Benchmark widget

## [2.0.0] — Complete rebuild

### Added
- Full React 18 SPA replacing WordPress theme
- 16 database tables
- JWT auth with OTP verification
- AI interview room with Priya avatar
- Report generation via Claude API
- Razorpay billing
- WP-Cron report generation
