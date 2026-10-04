# Width & Layout Management

Centralized public-site width control for Services2NRI **4.7.0+**.

## Inheritance

```
Global widths (Design System)
  → Page type (home, service, blog, city, …)
    → Page slug (about, contact, …)
      → Service-page defaults (all /service/*)
        → Section override (hero, faq, wizard, …)
          → Element (hero_settings.container_max on one service)
```

Unset levels **inherit** from the parent. Section overrides apply only when configured in admin.

## Admin

1. **Admin Portal → Design System → Width & Layout**
2. Choose scope: **global**, **page type**, **page**, **service section**, or **section**
3. Edit responsive values (desktop / laptop / tablet / mobile)
4. **Publish** design system
5. Hard-refresh the public site

Labels show **Inherited** vs **Overridden**. Use **Reset to inherited** to remove a layer.

## CSS tokens (public site)

| Token | Typical use |
|-------|-------------|
| `--s2-width-page-max` | `.s2-container`, page shell |
| `--s2-width-content-max` | Article/blog body, content columns |
| `--s2-width-inner-max` | Subcopy, forms, narrow prose |
| `--s2-width-section-standard` | Default section inner |
| `--s2-width-section-wide` | Compare tables, wide marketing |
| `--s2-width-section-narrow` | FAQ, legal prose |
| `--s2-width-section-compact` | Search fields, subtitles |
| `--s2-width-padding-x` | Horizontal page padding |
| `--s2-width-sec-{section}-max` | Per-section override from admin |

Utilities: `s2-width-narrow`, `s2-width-wide`, `s2-width-standard`, `s2-width-content`, `s2-width-inner`, `s2-width-compact`, `s2-width-full`.

## React / SPA

- `PageWidthScope` on public `<main>` sets `data-s2-page-type` and `data-s2-page-slug`.
- `PublicSection` accepts `sectionKey` and `width` for `data-s2-section` + inner utility class.
- Route changes re-resolve tokens from `S2NRI_CONFIG.design.widths`.

## Service hero width

Per-service JSON **`hero_settings`**:

- `container_max` or `content_max` → `--s2-width-sec-hero-max` on the hero block.

Also configurable globally under **Width & Layout → service section → hero**.

## Page type detection

Path → type mapping lives in `DesignSystem::pageContextFromPath()` and `width-layout.ts` (keep in sync).

## Files

- `src/Design/WidthLayout.php` — server resolver + inline CSS
- `assets/public-width-layout.css` — utilities
- `src/lib/width-layout.ts` — client resolver
- `src/pages/admin/width-layout-panel.tsx` — admin UI
