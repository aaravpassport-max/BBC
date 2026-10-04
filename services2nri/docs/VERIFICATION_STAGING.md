# Staging verification — Service Registry scenarios A–G

Run on a staging site with plugin **4.5.2+** activated and migrations applied.

## Prerequisites

1. WP Admin → Services2NRI → ensure at least 3 published services in different categories.
2. Open **Design System → Service Registry** and note one test service slug (e.g. `apostille`).

## A — Hide one service

1. Registry → select service → set **Publication status** = `hidden`.
2. Save visibility.
3. **Expect:** slug absent from mega-menu, homepage category cards, `/services` grid, footer links, customer service picker, and site search results.
4. **Expect:** direct URL `/service/{slug}` follows **Direct URL behavior** (default `not_found` → API 404).

## B — Restore service

1. Set status back to `published`, save.
2. **Expect:** service reappears on all surfaces allowed by `visibility_rules`.

## C — Hide parent category

1. **Categories** tab → pick category → status `hidden`, save.
2. **Expect:** category hidden from homepage tabs and directory sidebar; child services not listed publicly.

## D — Empty mega-menu column

1. Hide all services in one mega-menu column (or disable surface `nav_dropdown` per service).
2. **Expect:** column omitted from `GET navigation/public` (empty `cols` pruned).

## E — Coming soon on forms only

1. Set service `coming_soon` with surfaces: homepage on, forms off.
2. **Expect:** card visible on homepage; not in booking/quote selectors.

## F — Featured / popular sort

1. Mark two services featured; save.
2. **Expect:** `GET services?surface=homepage&featured=1` returns only featured; homepage sort prefers featured/popular.

## G — Historical data preserved

1. Create booking for service, then hide service.
2. **Expect:** booking still visible in admin and customer portal; forms no longer offer service.

## Design system smoke

1. **Design System → Colors** → change primary → Publish.
2. Switch to public home tab (or wait for live sync) → primary buttons/nav use new color (`--s2-primary`) without hard refresh.
3. **Presets** → apply Corporate → public tab / Live Site preview reflects change within seconds.

## Automated static checks (CI / dev)

```bash
cd services2nri && bash scripts/verify-design-system.sh
```
