# NAS Portal — Enterprise Redesign Roadmap

**Version:** 3.9.0 (Phase 2 complete)  
**Benchmark:** Homepage (`templates/public/home.php` + `assets/css/nas-homepage.css`)  
**Goal:** Every portal page should feel like the same premium, mature, enterprise-level platform.

---

## Executive Summary

The homepage redesign (v3.5–v3.7) established a strong design language: navy + gold marketplace aesthetic, trust ribbons, premium cards, process steps, FAQ accordion, and a light glass header with gradient accent bar. Inner pages still use a separate `nas-top-nav` system, load conflicting dashboard CSS, and many SEO/marketing templates output invalid nested HTML documents with inline styles — resulting in **broken navigation**, **invisible icons**, and **thin, unfinished pages**.

This roadmap defines a portal-wide unification: one shell, one design system, page-appropriate enterprise sections, and phased rollout without losing existing features.

---

## 1. Global Design System

### Navigation
| Element | Homepage (benchmark) | Target (all public pages) |
|---------|---------------------|---------------------------|
| Top utility bar | `.nhp-topbar` — phone, email, track, WhatsApp | Shared `portal-topbar.php` |
| Main header | `.nhp-header--light` — logo, nav links, login, CTA | Shared `portal-header.php` |
| Mobile nav | `.nhp-mobile-nav` panel | Same JS as homepage (`nas-homepage.js`) |
| Scroll state | `.nhp-header--scrolled` shadow | `nas-core.js` scroll listener |
| Dashboard nav | `.cd-topnav` / sidebar (client) | **Separate** — scoped dark styles |

**Phase 1 fix:** Remove unscoped `.nas-top-nav` dark rules from `nas-dashboard.css` and `nas-enterprise.css`. Public pages load `nas-homepage.css` for header/footer only.

### Header / Footer
- **Header partial:** `templates/partials/portal-header.php` (topbar + header + mobile drawer)
- **Footer partial:** `templates/partials/portal-footer.php` (4-column footer, payments row, legal)
- **Shell wrapper:** `nas-page-template.php` auto-wraps public shortcode output

### Typography
- **Display:** Poppins 700–800 for headings
- **Body:** Inter 400–600
- **Scale:** Hero `clamp(1.75rem, 4vw, 2.5rem)` → section titles `1.5–1.75rem` → body `1rem` → captions `0.8125rem`
- **Tokens:** `--nas-font`, `--nas-font-display` in `nas-core.css`

### Colors
| Token | Value | Usage |
|-------|-------|-------|
| `--nas-primary` | `#1A3A5C` | Navy brand, headings |
| `--nas-gold` / gradient | `#F59E0B → #F97316` | CTAs, accents |
| `--nas-teal` | `#0D9488` | Trust, secondary accent |
| `--nas-violet` | `#7C3AED` | Category accents |
| `--nas-text` | `#0f172a` | Body text |
| `--nas-text-muted` | `#64748b` | Secondary text |
| `--nas-border` | `#e2e8f0` | Cards, dividers |
| `--nas-bg` | `#f8fafc` | Section backgrounds |

### Buttons
| Class | Use |
|-------|-----|
| `.nhp-btn--primary` / `.nas-btn-primary` | Gold gradient, pill shape — primary CTA |
| `.nhp-btn--white` | Dark CTA bands |
| `.nhp-btn--ghost` | Secondary on dark backgrounds |
| `.nas-btn-ghost` | Login / secondary on light |

### Cards
| Component | Class | Use |
|-----------|-------|-----|
| Portal card | `.nas-portal-card` | Generic content blocks |
| Pricing card | `.nas-portal-pricing-card` | Pricing tiers |
| Feature card | `.nas-portal-feature` | Benefits grid |
| Stat card | `.nas-portal-stat` | Metrics |
| Newspaper card | `.nhp-paper-card` | Marketplace listings |
| FAQ item | `.nas-faq-item` / `.nhp-faq-item` | Accordion |

### Forms
- `.nas-field`, `.nas-submit-btn` (portal.css)
- Select2 for dropdowns in booking/dashboard only
- Consistent focus ring: teal `box-shadow: 0 0 0 4px rgba(13,148,136,.12)`

### Icons
- Font Awesome 6.5 via CDN preload
- Category icons: gradient circles with FA or emoji
- **Light header:** dark icon colors on `.nas-public-portal`

### Spacing
- Section padding: `clamp(48px, 8vw, 96px)` vertical
- Container max-width: `1100px` (content), `1200px` (grids), `1400px` (header)
- Grid gaps: `24–32px` desktop, `16px` mobile

### Responsive Behavior
- Mobile-first grids: `repeat(auto-fill, minmax(260px, 1fr))`
- Header: hamburger below `900px`, hide desktop nav
- FAQ sidebar: stacks above accordion on mobile
- Sticky mobile CTA on homepage; optional on booking funnel

### Animations / Interactions
- Scroll reveal: `.nhp-reveal` (opacity 1 by default — JS enhances)
- Header scroll shadow
- Card hover: `translateY(-4px)` + shadow lift
- FAQ accordion: chevron rotation
- Toast notifications: `nasToast` in `nas-core.js`

---

## 2. Page Inventory & Audit

### Legend
- ✅ Strong — matches homepage quality
- ⚠️ Medium — functional but inconsistent / inline styles
- ❌ Thin — sparse content, weak hierarchy, broken shell
- 🔧 Broken — nav/CSS/HTML issues

| Page / Template | Route / Slug | Status | Issues |
|-----------------|--------------|--------|--------|
| Homepage | `/` (front page) | ✅ | Benchmark — do not regress |
| FAQ | `/faq/` | ⚠️ | Good portal layout; needs shared shell + footer |
| Contact | `/contact-us/` | ⚠️ | Good portal layout; needs shell + footer |
| About | `/about/` | ❌🔧 | Nested HTML, inline styles, no footer |
| Pricing | `/pricing/` | ❌🔧 | Nested HTML, thin pricing cards |
| Support | `/support/` | ❌🔧 | Nested HTML, basic FAQ inline |
| Cities index | `/newspaper-ads/` or `/cities/` | ❌🔧 | Nested HTML, search only |
| Newspapers index | `/newspapers/` | ❌🔧 | Nested HTML, basic grid |
| City landing | `/newspaper-ads/{city}/` | ⚠️ | Has nav; needs footer + section polish |
| City page (SEO) | dynamic | ⚠️ | Inline styles |
| Category page | dynamic | ⚠️ | Thin SEO template |
| Newspaper detail | dynamic | ⚠️ | Missing sidebar booking widget |
| State page | dynamic | ❌ | Minimal content |
| Booking wizard | `/book-newspaper-ad/` | ⚠️ | Separate `nas-topnav` — unify in Phase 2 |
| Confirmation | `/booking-confirmation/` | ⚠️ | Needs trust row + footer |
| Track order | `/track-order/` | ❌ | Manual CSS link, thin card |
| Login | `/newspaper-ad-login/` | ❌ | No portal shell, no trust badges |
| Payment checkout | `/payment/` | ❌ | No nav, no trust row |
| Blog index / post | `/blog/` | ⚠️ | Nested HTML in index |
| Vendor register | `/vendor-register/` | ⚠️ | Basic form |
| Client dashboard | `/client-dashboard/` | ✅ | App UI — keep separate |
| Admin / Staff / Mod / Vendor dash | various | ✅ | App UI — token alignment only |
| PWA shell | — | ⚠️ | Out of scope Phase 1 |

### Global Broken Elements (root causes)
1. **CSS cascade war:** `nas-dashboard.css` line 679 forces dark `.nas-top-nav` on all non-home pages
2. **Icon colors:** `nas-core.css` styles avatar/bell/hamburger white (for dark nav)
3. **Invalid HTML:** `templates/pages/*.php` output `<!DOCTYPE html>` inside `nas-page-template.php` body
4. **Three header systems:** `nhp-header`, `nas-top-nav`, `nas-topnav` (booking)
5. **Asset bloat:** All pages load dashboard + enterprise + admin CSS unnecessarily
6. **Missing footer:** Inner pages have no shared footer or payments trust row

---

## 3. Page-by-Page Section Roadmap

### About (`/about/`)
| # | Section | Component | Reusable? |
|---|---------|-----------|-----------|
| 1 | Hero | `nas-portal-hero` — eyebrow + mission statement | Yes |
| 2 | Mission + stat highlight | 2-col grid + stat card | Yes |
| 3 | Platform stats | `nas-portal-stat-grid` (papers, cities, clients) | Yes |
| 4 | Why choose us | `nas-portal-feature-grid` (4 benefits) | Yes |
| 5 | Process overview | `nas-portal-process` (5 steps, condensed) | Yes |
| 6 | Trust bar | Logo strip / trust ribbon | Yes |
| 7 | CTA band | `nas-portal-cta-band` | Yes |

### Pricing (`/pricing/`)
| # | Section | Content |
|---|---------|---------|
| 1 | Hero | Transparent pricing + GST note |
| 2 | Pricing cards | Classified / Display / Display-classified tiers |
| 3 | Category rate table | Dynamic from DB categories |
| 4 | What's included | Bullet feature list per tier |
| 5 | FAQ mini | 4 pricing-specific questions |
| 6 | CTA | "Get instant quote" → booking wizard |

### Support (`/support/`)
| # | Section | Content |
|---|---------|---------|
| 1 | Hero | Help center intro |
| 2 | Contact channels | Email, chat, phone cards |
| 3 | Quick links | Track order, FAQ, contact |
| 4 | FAQ accordion | Top 8 support questions |
| 5 | CTA | Contact form link |

### Cities Index (`/cities/` or `/newspaper-ads/`)
| # | Section | Content |
|---|---------|---------|
| 1 | Hero + search | City count, state count |
| 2 | Top cities grid | Tier-1 featured cards |
| 3 | All cities by state | Searchable accordion/grid |
| 4 | Coverage stats | Pan-India map placeholder / stats |
| 5 | How it works | 3-step city booking |
| 6 | CTA | Book now |

### Newspapers Index (`/newspapers/`)
| # | Section | Content |
|---|---------|---------|
| 1 | Hero + search | Publication count |
| 2 | Featured papers | Logo strip / top cards |
| 3 | By language groups | Searchable grid |
| 4 | Rate preview | Starting prices |
| 5 | CTA | Start booking |

### City Landing / SEO pages
| # | Section | Content |
|---|---------|---------|
| 1 | City hero | Local headline + book CTA |
| 2 | Available newspapers | Filtered paper cards |
| 3 | Popular categories | Category grid |
| 4 | Local stats | Circulation, editions |
| 5 | Process | How booking works in this city |
| 6 | FAQ | City-specific questions |
| 7 | Related cities | Nearby links |

### Booking Wizard
| # | Section | Content |
|---|---------|---------|
| 1 | Unified portal header | Match homepage (Phase 2) |
| 2 | Progress stepper | Keep existing 11-step |
| 3 | Trust sidebar | Secure payment, verified pubs |
| 4 | Help strip | Phone, WhatsApp, FAQ link |

### Track Order
| # | Section | Content |
|---|---------|---------|
| 1 | Portal hero | Track your ad |
| 2 | Search form | Booking ID + email |
| 3 | Timeline result | Status stages |
| 4 | Help sidebar | FAQ + contact |
| 5 | CTA | Book another ad |

### Login
| # | Section | Content |
|---|---------|---------|
| 1 | Split layout | Form left, trust panel right |
| 2 | Trust badges | Secure, verified, 10k+ clients |
| 3 | Quick links | Book without login |

### Payment Checkout
| # | Section | Content |
|---|---------|---------|
| 1 | Minimal header | Logo + secure badge |
| 2 | Order summary | Existing checkout |
| 3 | Payments trust row | Razorpay, UPI, cards |
| 4 | Support link | Help during payment |

### Blog
| # | Section | Content |
|---|---------|---------|
| 1 | Hero | Latest insights |
| 2 | Post grid | Cards with image, date, excerpt |
| 3 | Categories sidebar | Filter |
| 4 | Newsletter CTA | Subscribe |

---

## 4. Enterprise Content Architecture

### Section types (when to use)

| Section type | Purpose | Pages |
|--------------|---------|-------|
| Hero | Page intent + primary CTA | All marketing pages |
| Service explanation | What the page offers | Pricing, city, category |
| Benefits | Why use platform | About, landing pages |
| How-it-works | Process timeline | Home, about, booking |
| Trust & credibility | Logos, stats, badges | All public pages |
| Statistics | Social proof numbers | About, home, cities |
| Testimonials | Reviews (future DB) | Home, about |
| FAQ | Reduce support load | All major pages |
| Related services | Cross-link newspapers/cities | Detail pages |
| Resources | Blog, guides | Support, footer |
| CTA bands | Conversion | End of every page |
| Payments trust | Razorpay row | Footer, checkout |

### Content density guidelines
- Minimum 4 sections per marketing page (hero + 2 content + CTA)
- Hero must answer: what, why, next action
- No section shorter than 120px content height on desktop
- White space intentional — use cards/grids to fill, not empty `<div>` padding

---

## 5. Consistency & Component Strategy

### Globally controlled (single source of truth)

| Component | File | Used by |
|-----------|------|---------|
| Portal shell open/close | `newspaper-ads-saas.php` helpers | `nas-page-template.php` |
| Header + topbar | `portal-header.php` | All public pages |
| Footer | `portal-footer.php` | All public pages |
| Design tokens | `nas-core.css` `:root` | Everything |
| Portal sections | `nas-portal.css` | Marketing pages |
| Homepage sections | `nas-homepage.css` | Home + header/footer |
| Buttons | `nas-core.css` + portal | All |
| FAQ accordion | `nas-portal.css` + JS | FAQ, support, landing |
| Toast / modal | `nas-core.js` | Dashboard, forms |

### Page-specific (allowed variation)
- Booking wizard step content
- Dashboard data tables
- City/newspaper SEO body copy
- Admin panels

### Anti-patterns to eliminate
- Inline `style=""` blocks in page templates
- Per-page `<!DOCTYPE html>` documents
- Duplicate nav link arrays in every template
- Loading `nas-dashboard.css` on FAQ/contact pages

---

## 6. Responsive & Mobile Experience

### Breakpoints
| Breakpoint | Behavior |
|------------|----------|
| `> 1100px` | Full nav, multi-column grids |
| `768–1100px` | 2-column grids, hamburger nav |
| `< 768px` | Single column, sticky CTA, stacked hero |

### Per-section checks
- [ ] Header: no overflow, hamburger works, CTA visible
- [ ] Hero: title doesn't clip, CTA full-width on mobile
- [ ] Grids: `minmax` prevents horizontal scroll
- [ ] FAQ: sidebar stacks above list
- [ ] Footer: 1-column stack, tap targets 44px+
- [ ] Forms: inputs `font-size: 16px` (no iOS zoom)
- [ ] Track order timeline: readable on narrow screens

### Phase 1 mobile fixes
- Unified mobile nav from homepage JS
- Remove manual viewport meta duplication in child templates
- Portal wrap padding via `clamp()`

---

## 7. Quality-Control Checklist

### Navigation
- [ ] Same header on FAQ, contact, about, pricing, cities, newspapers
- [ ] No invisible white icons on light header
- [ ] Mobile drawer opens/closes on all pages
- [ ] Active nav state highlights current section
- [ ] Login / Book CTA visible when logged out

### Visual consistency
- [ ] Navy + gold on all public pages
- [ ] No random `#2563eb` blue from old inline styles
- [ ] Card border-radius 12–20px consistent
- [ ] Section headings use Poppins

### Content completeness
- [ ] Every marketing page has hero + CTA + footer
- [ ] No empty white bands between sections
- [ ] Trust elements on conversion pages

### Functionality
- [ ] All shortcodes still render
- [ ] Booking wizard 11 steps intact
- [ ] Track order AJAX works
- [ ] FAQ load from DB
- [ ] Contact form submits
- [ ] Dashboard login gates preserved

### Responsiveness
- [ ] Test 375px, 768px, 1280px on each major page

### Accessibility
- [ ] `aria-expanded` on FAQ buttons
- [ ] Focus states on interactive elements
- [ ] Alt text on newspaper logos
- [ ] Skip link (Phase 3)

### Performance
- [ ] Public pages don't load admin/chart.js
- [ ] Defer non-critical JS
- [ ] Font preload via preconnect

### Empty / error / loading states
- [ ] FAQ "Loading…" → content or empty message
- [ ] City search "No results" message
- [ ] Track order invalid ID error
- [ ] Form validation errors styled

### Cross-page consistency
- [ ] Footer links match header nav
- [ ] Phone/email from Config everywhere
- [ ] WhatsApp float on public pages (if configured)

---

## Implementation Phases

### Phase 1 — Foundation (v3.8.0) ← **CURRENT**
- [x] This roadmap document
- [ ] Portal shell helpers + header/footer partials
- [ ] Fix CSS cascade (scope dashboard nav)
- [ ] Split Enqueue public vs dashboard assets
- [ ] `nas-page-template.php` auto-wrap
- [ ] Strip nested HTML from `templates/pages/*.php`
- [ ] Upgrade about, support, pricing with portal components
- [ ] Scroll JS for header
- [ ] Remove duplicate `top-nav.php` includes from FAQ/contact/track

### Phase 2 — Marketing pages (v3.9.0)
- [x] Cities + newspapers index premium redesign
- [x] City landing / category / newspaper detail sections
- [x] Booking wizard header unification (duplicate nav removed; portal shell provides header)
- [x] Login split layout + trust panel
- [x] Track order full redesign
- [x] Payment checkout trust row + portal sections

### Phase 3 — Polish (v4.0.0)
- [ ] Testimonials module integration
- [x] Blog premium layout
- [x] Vendor register enterprise form
- [x] Confirmation page celebration + upsell
- [ ] Accessibility audit + skip links
- [ ] Performance budget (< 200KB CSS public)

### Phase 4 — QC & launch
- [ ] Full responsive pass all pages
- [ ] Cross-browser test
- [ ] User acceptance on staging
- [ ] Update zip + PR

---

## Files Changed in Phase 1

| File | Change |
|------|--------|
| `docs/PORTAL-REDESIGN-ROADMAP.md` | This document |
| `templates/partials/portal-header.php` | New unified header |
| `templates/partials/portal-footer.php` | New shared footer |
| `templates/partials/nas-page-template.php` | Auto shell wrap |
| `templates/partials/top-nav.php` | Delegates to portal-header |
| `newspaper-ads-saas.php` | Shell helper functions, v3.8.0 |
| `core/Enqueue.php` | Public vs dashboard asset split |
| `assets/css/nas-dashboard.css` | Scope dark nav to dash shells |
| `assets/css/nas-enterprise.css` | Remove public nav override |
| `assets/css/nas-portal.css` | Enterprise section components |
| `assets/css/nas-core.css` | Light portal icon overrides |
| `assets/js/nas-core.js` | Header scroll listener |
| `templates/pages/*.php` | Content fragments only |
| `templates/public/faq.php` | Remove duplicate nav |
| `templates/public/contact.php` | Remove duplicate nav |
| `templates/public/track-order.php` | Portal redesign |

---

## Files Changed in Phase 2 (v3.9.0)

| File | Change |
|------|--------|
| `templates/partials/portal-blocks.php` | Reusable trust/process/FAQ/CTA blocks |
| `templates/pages/about.php` … `state-page.php` | Deep enterprise sections |
| `templates/public/contact.php`, `faq.php`, `track-order.php`, `login*.php` | Portal redesign |
| `templates/payment/checkout.php` | Full portal checkout with trust/process sections |
| `templates/booking/confirmation.php` | Navy/gold confirmation + portal blocks |
| `templates/booking/wizard.php` | Remove duplicate nav; portal-page wrapper |
| `templates/public/blog-index.php`, `blog-post.php` | Portal blog layout |
| `templates/public/vendor-register.php` | Split layout + partner FAQ/CTA |
| `templates/city-pages/city-landing.php` | Match city.php depth |
| `templates/partials/nas-city-wrapper.php` | Portal shell integration |
| `assets/css/nas-portal.css` | Checkout, confirmation, blog, vendor CSS |

*Last updated: Phase 2 implementation — September 2026*
