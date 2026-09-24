# TradingView parity audit — FNO Lab standalone chart

**Audit date:** 2026-09-24  
**Build reference:** 16.37.49+ (`chart-tv-parity-v1`)  
**Scope:** User-facing charting, indicators, drawings, layouts, alerts vs TradingView Supercharts (not full platform: social, broker, cloud sync).

## Executive summary

FNO Lab uses a **custom Canvas chart** fed by **NSE + Kite** (`fno_fetch_chart_fn`). It is **not** TradingView’s engine. Full Pine Script, 400+ built-ins, multi-chart workspaces, and global drawing sync are **out of scope** for this WordPress plugin without a CDN/build step.

**Reasonable parity target:** single-chart technical analysis for NIFTY/BANKNIFTY/FINNIFTY with real data, indicator management, **importable custom formula scripts**, drawings, layout presets, price alerts, navigation, and persistence — each wired end-to-end and tested.

## TradingView capability map vs FNO Lab

| TV feature | TV behavior | FNO Lab (before 16.37.49) | FNO Lab (16.37.49 target) | Gap / notes |
|------------|-------------|---------------------------|---------------------------|-------------|
| Built-in indicators | 400+ | 7 registry types | 8 (+ volume panel) | No marketplace; add via custom pack |
| Custom scripts | Pine Editor, publish | Single-line “formula” only; **no upload** | **FNO Formula Script** + **import/export `.fnoind.json`** | **Not Pine**; documented honestly |
| Indicator search | Command palette | Dropdown only | Search filter on add list | No global command palette |
| Multiple indicators | Unlimited panes | Supported via instances | Same + layout presets | Panel height fixed per type |
| Parameters | Full inputs | Built-ins + formula text | Built-ins + formula **+ JSON params** in custom defs | No Pine `input()` |
| Drawings | 110+ tools | **None** | H-line, trendline, clear; **persist per symbol** | No fib/Gann/patterns |
| Chart types | Many | Candles only | Candle / line / area toggle | No Heikin/Renko |
| Timeframes | Full set | 1/5/15m | + 30m, 1h, 4h, 1D aggregation | Seconds/tick N/A |
| Layouts | Multi-chart, cloud | View localStorage only | **Named layout presets** (view + indicators) | Single chart only |
| Alerts | Price/indicator/strategy | Trade open/close only | **Price cross alerts** (browser notify) | No indicator-cross yet |
| Crosshair / zoom | Full | Implemented | Same + keyboard hints | |
| Full screen | Yes | CSS fullscreen | Same | Not browser Fullscreen API |
| Watchlists | Core product | **3-symbol `<select>` only** | Unchanged | Needs separate product decision |
| Keyboard shortcuts | Extensive | Esc, wheel hints | + `F` fit, `R` reset, `1/5/15` TF | Partial |
| Data | TV feeds | NSE/Kite; close-only fallback | Same | Daily fallback = date axis only |

## Ten-point functional checklist (chart area)

| # | Question | Pre-16.37.49 | 16.37.49 |
|---|----------|--------------|----------|
| 1 | UI works? | Partial; custom panel easy to miss | Import/script panel explicit |
| 2 | Backend implemented? | Formula engine yes; no import/drawings | Extensions module + import |
| 3 | Real market data? | Yes when fetch succeeds | Yes |
| 4 | Edge cases? | Formula errors shown | Import validation + alert errors |
| 5 | Settings change behavior? | TF/zoom yes | Chart type, volume, draw mode |
| 6 | Persistence? | View + indicators | + drawings, presets, alerts |
| 7 | Survives reload? | View/indicators | + drawings/alerts/presets |
| 8 | Responsive? | ResizeObserver | Same; mobile touch pan |
| 9 | Dead buttons? | “Pine-lite” mislabel | Renamed; import/export wired |
| 10 | Partial/stub? | Drawings/alerts/watchlist | Drawings/alerts real; watchlist still gap |

## Known stubs outside chart (trading system)

| Item | Location | Status |
|------|----------|--------|
| `playTradeEntrySound` / `playTradeExitSound` | fno-lab-core.js | Empty; real path is `notifyTradeExecution` |
| Chart price/indicator alerts in Settings copy | standalone-app.php | Text implied brain signals; only trade execution fires |
| Watchlist | — | **Not implemented** (symbol `<select>` only) |
| AI narrative | `#aiNarrativeBox` | “Not configured” until API key |
| User drawing “indicator lines” comment | core TRACE | Means overlays, not TV drawings (fixed with draw tools) |

## Verification

- Node: `chart-custom-formula`, `chart-import-export`, `chart-drawings`, `chart-layout-presets`, `chart-price-alerts`
- Browser: `tests/chart-interactive-harness.html` + `chart-interactive-browser.test.js` (Puppeteer)

## What we explicitly do **not** claim

- Pine Script compatibility or community script import  
- TradingView multi-layout (2×2 charts)  
- Cloud-synced layouts across devices  
- Full alert webhook/cloud server  

Custom scripts use **FNO Formula Script** (documented DSL). Users can **upload** packs in JSON format (`fno-indicator-pack-v1`).
