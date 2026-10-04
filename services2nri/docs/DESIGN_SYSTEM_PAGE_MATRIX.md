# §17 — Public page → section → component → token matrix

Each row maps a user-visible block to its implementation and design tokens. **Registry** = `ServiceRegistry::forSurface()` unless noted.

## Global shell (all React public routes)

| Section | Component | File | Tokens / classes | Registry |
|---------|-----------|------|------------------|----------|
| Root wrapper | `#s2nri-root.s2-ds` | `SEO.php` | `--s2-font-body`, `--s2-color-body` | — |
| Inline design bundle | `#s2nri-design-system` | `DesignSystem::renderInlineCss()` | colors, spacing, typography utilities | — |
| Top bar | Layout top strip | `Layout.tsx` | `resolvePrimary()`, WhatsApp/phone settings | — |
| Header / mega menu | Layout header | `Layout.tsx` | nav typography, `--s2-primary` | `GET navigation/public`, `surface=nav_dropdown` |
| Footer service links | Layout footer | `Layout.tsx` | footer heading tokens | `services?surface=footer` |
| Mobile bottom nav | `BottomNav.tsx` | `BottomNav.tsx` | primary, breakpoints | — |

## Home (`/`)

| Section | Component | File | Tokens / classes | Registry |
|---------|-----------|------|------------------|----------|
| Hero carousel | Swiper fade | `HomePage.tsx` | `.s2-hero-section` (layout) | — |
| Notice bar | Alert strip | `HomePage.tsx` | `.s2-notice-bar`, `.s2-home-notice` | — |
| Services block | Category tabs + cards | `HomePage.tsx` | `.s2-section`, `.s2-container`, `.s2-t-*`, `.s2-card`, `.s2-btn` | `categories?surface=homepage`, `services?surface=homepage&per_page=4` |
| Cities grid | City cards | `HomePage.tsx` | `.s2-home-city-card`, surface alt bg | `GET cities` (active only) |
| Stats bar | Animated counters | `HomePage.tsx` | `.s2-hero-stat-bar`, `.s2-stats-bar__*` | — |
| Tagline | Quote strip | `HomePage.tsx` | primary via `resolvePrimary()` | — |
| Why choose us | Feature grid | `HomePage.tsx` | `.s2-feat-grid`, section spacing | — |
| Testimonials | Swiper | `HomePage.tsx` | card shadow tokens | `GET testimonials` |
| How it works | Steps | `HomePage.tsx` | `.s2-how-grid` | — |
| Featured in / partners | Logo rows | `HomePage.tsx` | muted text | — |
| About / awards / FAQ / newsletter | Mixed | `HomePage.tsx` | partial `.s2-t-*` migration ongoing | — |

## Services directory (`/services`, `/services/:cat`)

| Section | Component | File | Tokens / classes | Registry |
|---------|-----------|------|------------------|----------|
| Hero + search | Gradient hero | `ServicesPage.tsx` | `.s2-services-hero`, `.s2-container` | — |
| Sidebar categories | Desktop nav | `ServicesPage.tsx` | sidebar theme CSS | `categories?surface=directory` |
| Service cards | Grid | `ServicesPage.tsx` | `.s2-card`, badges | `services?surface=directory` (server `surface=search` when searching) |
| Empty / error | States | `ServicesPage.tsx` | `.s2-empty` | — |

## Service detail (`/service/:slug`)

| Section | Component | File | Tokens / classes | Registry |
|---------|-----------|------|------------------|----------|
| Direct URL gate | Redirect / 404 | `ServiceController::show` | — | `resolveDirectUrl()` |
| Hero | Service hero | `ServiceDetailPage.tsx` | primary, hero settings JSON | — |
| Dynamic sections | CMS sections | `ServiceDetailPage.tsx` | per-section `device_visibility` | related slugs → `filterSlugs(..., 'related')` |
| Booking CTA | Forms | `ServiceDetailPage.tsx` | `.s2-btn`, `.s2-input` | `getBookableService()` / forms surface |

## Static marketing pages (`index.tsx`)

| Page | Route | Hero | Body tokens | Registry |
|------|-------|------|-------------|----------|
| About | `/about` | `PageHero` | `.s2-t-body`, primary | — |
| Contact | `/contact` | `PageHero` | `.s2-input`, form fields | — |
| How it works | `/how-it-works` | `PageHero` | `.s2-how-grid` | — |
| FAQ | `/faq` | `PageHero` | accordion (legacy inline) | `GET faqs` |
| Pricing | `/pricing` | `PageHero` | plan cards (primary accents) | plans API |
| Terms / Privacy | `/terms`, `/privacy` | minimal | `.s2-t-body` | — |
| City landing | `/cities/:slug` | `CityPage` | layout tokens | cities table |

## Blog (PHP)

| Section | File | Tokens | Registry |
|---------|------|--------|----------|
| Listing / detail | `Blog.php` | `#s2-design-system` inline + `$brand` from `DesignSystem::resolve()` | — |

## Portal SPAs

| App | Shell | Design injection |
|-----|-------|------------------|
| Customer `/portal` | `Portal.php` | fonts + `renderInlineCss`, `S2NRI_CFG.design` |
| Admin `/s2nri-admin` | `Portal.php` | same + admin chunks |
| Design admin | `/admin/design` | full config via `GET admin/design` |

## Forms & shortcodes

| Surface | Consumer | Registry |
|---------|----------|----------|
| Quote form shortcode | `Shortcodes.php` | `forSurface('forms')` |
| Customer new booking | `customer/index.tsx` | `services?surface=forms` |
| Booking API | `BookingController.php` | `getBookableService()` |

## Admin — design & visibility

| Tab | Purpose |
|-----|---------|
| Global / Typography / Colors / … | Token editors |
| Overrides | page_type / page / section JSON |
| Navigation | `PUT admin/navigation` structure |
| Service Registry | per-surface matrix + featured/popular |
| Categories | category-level visibility |

## Scenarios A–G (staging)

See `docs/VERIFICATION_STAGING.md` for click-path verification on a live WordPress install.
