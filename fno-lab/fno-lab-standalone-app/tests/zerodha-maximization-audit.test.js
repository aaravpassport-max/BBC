// ZERODHA-MAXIMIZATION AUDIT FIXES - real regression tests for the
// changes made in response to the user's explicit directive: Zerodha/
// Kite Connect is the ONLY real market-data source available (no NSE
// API, no premium provider) - maximize real use of it, fix what's
// broken, never fabricate data. Loads the real fno-lab-core.js source
// (same eval-slice pattern every other test in this repo uses).
//
// Run with: node tests/zerodha-maximization-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
const { fnoNormCdf, fnoNormPdf, bsGreeks, bsGreeksAtDays, buildGreeksSnapshot, FNO_RISK_FREE_RATE, TRADING_HOURS_PER_DAY, solveImpliedVolatility } = ge;

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) { console.error('FATAL: render() boundary marker not found'); process.exit(1); }
eval(coreSource.slice(0, end));

console.log('\n=== backfillChainIVFromPremium (chain-wide Kite IV backfill) ===\n');

{
  const spot = 23200;
  // REAL, DETERMINISTIC BUG FOUND AND FIXED THIS PASS IN THIS TEST'S
  // OWN CONSTRUCTION (unrelated to this session's chart work - traced
  // directly by hand-computing the real numbers, see
  // docs/PENDING_REQUIREMENTS.md v100 entry for the full story - this
  // was NOT a timing flake, it was a genuine, consistent day-count
  // mismatch). `futureExpiry` is built as `now + 15 days` then
  // truncated to a bare date string via `.toISOString().slice(0,10)` -
  // exactly what a real expiryDate field looks like. The REAL
  // production code (`backfillChainIVFromPremium`, unchanged, correctly
  // re-parses that date string as UTC midnight and measures real
  // elapsed time from `now`) therefore computes a rowDaysExp that is
  // SHORTER than a clean 15.0 by however much of the current UTC day
  // has already elapsed (e.g. ~14.26 days if now is 17:46 UTC) - this
  // test's old code fed calculateDecay a fixed `15` regardless, so the
  // premium it built and the DTE the solver later re-derived from the
  // SAME expiryDate string were never actually consistent, a real
  // ~0.5% IV gap. REAL FIX: compute the row's real effective DTE the
  // exact same way production does, and use THAT (not a fixed 15) to
  // build the target premium - now genuinely, provably consistent
  // regardless of what time of day this test runs.
  const nowMs = Date.now();
  const futureExpiry = new Date(nowMs + 15 * 86400000).toISOString().slice(0, 10);
  const effectiveDte = Math.max(0.1, (new Date(futureExpiry).getTime() - nowMs) / (24 * 60 * 60 * 1000));
  // Build a real, theoretically-consistent premium via calculateDecay
  // itself (never a hand-picked/arbitrary price) so the solver has a
  // real, solvable target.
  const realIv = 16;
  const realPremiumCE = calculateDecay(spot, spot, effectiveDte, realIv, 100, 50, 'CE').snapshot.now.price;
  const realPremiumPE = calculateDecay(spot, spot, effectiveDte, realIv, 100, 50, 'PE').snapshot.now.price;

  const rows = [
    { strikePrice: spot, expiryDate: futureExpiry, CE: { lastPrice: realPremiumCE, impliedVolatility: null, openInterest: 1000 }, PE: { lastPrice: realPremiumPE, impliedVolatility: null, openInterest: 900 } },
    // Already has a real IV (e.g. NSE-sourced row mixed into a partial
    // refresh) - must be left untouched, never overwritten.
    { strikePrice: spot + 100, expiryDate: futureExpiry, CE: { lastPrice: 80, impliedVolatility: 22.5, openInterest: 500 }, PE: null },
    // No real lastPrice - must stay null, never guessed.
    { strikePrice: spot + 500, expiryDate: futureExpiry, CE: { lastPrice: null, impliedVolatility: null, openInterest: 0 }, PE: { lastPrice: 0, impliedVolatility: null, openInterest: 0 } },
    // No parseable expiryDate - must stay null, never guessed with a fallback DTE.
    { strikePrice: spot - 100, expiryDate: null, CE: { lastPrice: 120, impliedVolatility: null, openInterest: 400 }, PE: { lastPrice: 60, impliedVolatility: null, openInterest: 350 } },
  ];

  const result = backfillChainIVFromPremium(rows, spot, nowMs);

  check(typeof rows[0].CE.impliedVolatility === 'number' && Math.abs(rows[0].CE.impliedVolatility - realIv) < 0.5, `ATM CE IV solved back close to the real ${realIv}% used to build the premium (got ${rows[0].CE.impliedVolatility})`);
  check(typeof rows[0].PE.impliedVolatility === 'number' && Math.abs(rows[0].PE.impliedVolatility - realIv) < 0.5, `ATM PE IV solved back close to the real ${realIv}% used to build the premium (got ${rows[0].PE.impliedVolatility})`);
  check(rows[1].CE.impliedVolatility === 22.5, 'a leg that already has a real IV is never overwritten');
  check(rows[2].CE.impliedVolatility === null, 'a leg with no real lastPrice stays honestly null, never guessed');
  check(rows[2].PE.impliedVolatility === null, 'a leg with lastPrice:0 stays honestly null (0 is not a real tradeable premium)');
  check(rows[3].CE.impliedVolatility === null && rows[3].PE.impliedVolatility === null, 'a row with no parseable expiryDate stays honestly null - never guesses a fallback DTE');
  check(result.backfilled === 2, `backfilled count is exactly 2 (the two real, solvable legs) - got ${result.backfilled}`);
}

{
  const result = backfillChainIVFromPremium([{ strikePrice: 23200, expiryDate: '2099-01-01', CE: { lastPrice: 100, impliedVolatility: null } }], null, Date.now());
  check(result.backfilled === 0, 'missing/non-finite spot -> no mutation attempted, honestly returns backfilled:0');
}

{
  const result = backfillChainIVFromPremium([], 23200, Date.now());
  check(result.backfilled === 0, 'empty rows array -> no crash, backfilled:0');
}

{
  const result = backfillChainIVFromPremium(null, 23200, Date.now());
  check(result.backfilled === 0, 'null rows -> no crash, backfilled:0 (defensive - real call site always passes a real array, but never trust blindly)');
}

console.log(`\n=== computeDocumentedGapFactors(ctx) real ctx.fiiLongShort wiring ===\n`);

{
  // Mirrors fno-lab.php:2150's real shape: {long, short} as PERCENTAGES.
  const rowsLong = computeDocumentedGapFactors({ fiiLongShort: { long: 68, short: 32 } });
  const fiiRow = rowsLong.find(r => r.factor === 'FII Long/Short Ratio Index Futures');
  check(fiiRow && fiiRow.pass === true && fiiRow.score === 0.5, `FII Long/Short Ratio Index Futures genuinely scores true/+0.5 on a real long-skewed (68%) reading (got pass=${fiiRow && fiiRow.pass}, score=${fiiRow && fiiRow.score})`);

  const rowsShort = computeDocumentedGapFactors({ fiiLongShort: { long: 25, short: 75 } });
  const fiiRowShort = rowsShort.find(r => r.factor === 'FII Long/Short Ratio Index Futures');
  check(fiiRowShort && fiiRowShort.pass === false && fiiRowShort.score === -0.5, `FII Long/Short Ratio Index Futures genuinely scores false/-0.5 on a real short-skewed (25%) reading (got pass=${fiiRowShort && fiiRowShort.pass})`);

  const rowsNeutral = computeDocumentedGapFactors({ fiiLongShort: { long: 50, short: 50 } });
  const fiiRowNeutral = rowsNeutral.find(r => r.factor === 'FII Long/Short Ratio Index Futures');
  check(fiiRowNeutral && fiiRowNeutral.pass === null, 'FII Long/Short Ratio Index Futures honestly stays null on a genuinely neutral (50/50) reading, not forced to true/false');

  const rowsAbsent = computeDocumentedGapFactors({});
  const fiiRowAbsent = rowsAbsent.find(r => r.factor === 'FII Long/Short Ratio Index Futures');
  check(fiiRowAbsent && fiiRowAbsent.pass === null && /unconfigured/i.test(fiiRowAbsent.reason), 'FII Long/Short Ratio Index Futures honestly stays null with the real "unconfigured" explanation when ctx.fiiLongShort is genuinely absent');

  const rowsNoCtx = computeDocumentedGapFactors();
  check(Array.isArray(rowsNoCtx) && rowsNoCtx.length === 3, `computeDocumentedGapFactors() called with NO arguments at all (the locked greeks-engine.test.js call shape) still returns exactly 3 rows, no crash - got ${rowsNoCtx && rowsNoCtx.length}`);

  const cats = new Set(rowsNoCtx.map(r => r.cat));
  check(cats.has('Regulatory') && cats.has('Microstructure') && cats.has('Fundamental'), 'the 3 documented-gap rows still span exactly Regulatory/Microstructure/Fundamental as before this session\'s changes');
}

console.log(`\n=== computeMarketFactors real ctx.fiiLongShort wiring (FII Net Buy/Sell Yesterday) ===\n`);

{
  const candles = []; let c = 23000;
  for (let i = 0; i < 30; i++) { c += 5; candles.push({ c: Math.round(c*100)/100, t: 1700000000 + i*300 }); }
  const rowsLong = computeMarketFactors(candles, { day: 'Wednesday', time: '11:00', fiiLongShort: { long: 65, short: 35 } });
  const fiiMkt = rowsLong.find(r => r.factor === 'FII Net Buy/Sell Yesterday');
  check(fiiMkt && fiiMkt.pass === true && fiiMkt.cat === 'Market', `FII Net Buy/Sell Yesterday (Market cat) genuinely scores true on a real 65% long reading (got pass=${fiiMkt && fiiMkt.pass})`);

  const rowsAbsent = computeMarketFactors(candles, { day: 'Wednesday', time: '11:00' });
  const fiiMktAbsent = rowsAbsent.find(r => r.factor === 'FII Net Buy/Sell Yesterday');
  check(fiiMktAbsent && fiiMktAbsent.pass === null, 'FII Net Buy/Sell Yesterday honestly stays null when ctx.fiiLongShort is genuinely absent');

  const diiRow = rowsAbsent.find(r => r.factor === 'DII Net Buy/Sell');
  check(diiRow && diiRow.pass === null && /no real DII data/i.test(diiRow.reason), 'DII Net Buy/Sell correctly, honestly stays permanently null - traced and confirmed no real DII data exists anywhere in this app\'s data model (deliberately NOT wired to ctx.fiiLongShort, which only ever carries FII data)');
}

console.log(`\n=== Kite-fallback option-chain rows now carry expiryDate per row ===\n`);
{
  // Read the real PHP source and confirm the fix textually (no PHP
  // runtime in this JS test file) - the real functional PHP-side
  // behavior is covered by this session's php -l syntax check and the
  // full PHP test suite re-run, not duplicated here.
  const phpSource = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
  check(/'strikePrice' => \$strike, 'expiryDate' => \$nearestExpiry, 'CE' => null, 'PE' => null/.test(phpSource), 'Kite-fallback $byStrike rows include expiryDate (real fix - previously only present at the chain-level expiryDates array)');
  check(/'averagePrice' => isset\(\$q\['average_price'\]\)/.test(phpSource), 'Kite-fallback rows now carry averagePrice from the same already-fetched quote response');
  check(/'depth' => isset\(\$q\['depth'\]\)/.test(phpSource), 'Kite-fallback rows now carry real 5-level depth from the same already-fetched quote response');
  check(/'oiDayHigh' => isset\(\$q\['oi_day_high'\]\)/.test(phpSource), 'Kite-fallback rows now carry real oiDayHigh/oiDayLow from the same already-fetched quote response');
  check(/\$histCacheKey = 'fno_kite_hist_'/.test(phpSource), 'Kite historical-candle fallback now has a real, day-boundary-keyed transient cache (previously none)');
  check((phpSource.match(/urlencode\('NSE:NIFTY 50'\)|urlencode\('NSE:NIFTY BANK'\)|urlencode\('NSE:NIFTY FIN SERVICE'\)/g) || []).length <= 3, 'the duplicate index-spot quote call inside fno_fetch_oc_fn was removed (spot is now batched into the same request as the option legs)');
}

console.log(`\n=== computeCostsFactors' Margin Blocked factor - real Kite /margins/orders wiring ===\n`);
{
  const spot = 23200, optPrice = 100, lotSize = 50;

  // Scenario 1: no ctx.kiteMarginEstimate at all (e.g. this refresh's
  // margin fetch never resolved, or an older ctx from before this
  // feature existed) - must honestly fall back to the static proxy,
  // never crash on the missing field.
  const rowsNone = computeCostsFactors(spot, optPrice, lotSize, {});
  const marginNone = rowsNone.find(r => r.factor === 'Margin Blocked');
  check(marginNone && marginNone.pass === null && /Rough SPAN\+exposure proxy/.test(marginNone.reason), 'no ctx.kiteMarginEstimate at all -> honestly falls back to the static proxy text, no crash');

  // Scenario 2: ctx.kiteMarginEstimate present but tier:'unavailable'
  // (the real, honest failure shape fetchKiteOrderMargin/the PHP
  // backend both return on any failure) - must still fall back to the
  // proxy, never treat 'unavailable' as a real figure.
  const rowsUnavail = computeCostsFactors(spot, optPrice, lotSize, { kiteMarginEstimate: { margin: null, tier: 'unavailable' } });
  const marginUnavail = rowsUnavail.find(r => r.factor === 'Margin Blocked');
  check(marginUnavail && /Rough SPAN\+exposure proxy/.test(marginUnavail.reason), 'ctx.kiteMarginEstimate.tier === "unavailable" -> correctly falls back to the static proxy, never treated as a real live figure');

  // Scenario 3: a real, successful own_kite_session figure - must be
  // used verbatim, never recalculated/altered, and must clearly cite
  // the real tradingsymbol and SPAN/exposure breakdown.
  const realMargin = { margin: { total: 15230.5, span: 12000, exposure: 3230.5, tradingsymbol: 'NIFTY24AUG23200CE' }, tier: 'own_kite_session' };
  const rowsReal = computeCostsFactors(spot, optPrice, lotSize, { kiteMarginEstimate: realMargin });
  const marginReal = rowsReal.find(r => r.factor === 'Margin Blocked');
  check(marginReal && /Real live Kite order-margin figure/.test(marginReal.reason), 'a real own_kite_session margin figure -> factor reason correctly reports it as a REAL live figure, not a proxy');
  check(marginReal && /Rs15231|Rs15230/.test(marginReal.reason), 'the real total (Rs15230.5) is genuinely reflected in the reason text, not recalculated from notional');
  check(marginReal && /NIFTY24AUG23200CE/.test(marginReal.reason), 'the real, matched tradingsymbol is genuinely cited in the reason text');
  check(marginReal && marginReal.score === 0 && marginReal.pass === null, 'Margin Blocked (real-figure branch) still correctly stays unscored/informational, matching the original factor\'s own design (never silently starts affecting directionalScore)');

  // Scenario 4: an own_kite_session tier but a genuinely malformed
  // margin payload (e.g. missing/non-numeric total) - must not crash
  // and must not silently print "undefined"/NaN; falls back to proxy.
  const rowsMalformed = computeCostsFactors(spot, optPrice, lotSize, { kiteMarginEstimate: { margin: { span: 12000 }, tier: 'own_kite_session' } });
  const marginMalformed = rowsMalformed.find(r => r.factor === 'Margin Blocked');
  check(marginMalformed && /Rough SPAN\+exposure proxy/.test(marginMalformed.reason), 'own_kite_session tier but genuinely missing/non-numeric total -> honestly falls back to the proxy rather than printing NaN/undefined');
}

console.log(`\n=== fetchKiteOrderMargin wiring present in refreshBrain (textual, since it's DOM/browser-dependent code) ===\n`);
{
  check(/async function fetchKiteOrderMargin\(sym, strike, optionType, lotSize\)/.test(coreSource), 'fetchKiteOrderMargin() exists as a real, throttled/cached client-side fetch function');
  check(/kiteMarginEstimate = await fetchKiteOrderMargin\(sym, strike, optionType, lotSize\)/.test(coreSource), 'refreshBrain() genuinely awaits the real fetchKiteOrderMargin call with the real, already-resolved sym/strike/optionType/lotSize for this refresh');
  check(/kiteMarginEstimate,\n/.test(coreSource), 'refreshBrain()\'s ctx object genuinely carries the real kiteMarginEstimate through to evaluateBrain/computeCostsFactors');
}

console.log(`\n=== parseCandles: real d.ohlcvData carried through to candles[].o/h/l/v (Kite daily-fallback path) ===\n`);
{
  // Real, internally-consistent 30-day OHLCV series (synthetic PRICES
  // built for a controlled test - this test's job is to verify the
  // real WIRING/plumbing carries genuine per-field data through
  // correctly, not to re-derive real market data).
  const grapthData = [], ohlcvData = [];
  let close = 23000;
  for (let i=0;i<30;i++){
    const ts = 1700000000000 + i*86400000;
    const open = close;
    const high = open + 80 + (i%3)*10;
    const low = open - 60 - (i%2)*10;
    close = open + (i%4===0 ? -40 : 25);
    const vol = 1000000 + (i===29 ? 3000000 : i*5000); // real, deliberate volume spike on the last (most recent) real day
    grapthData.push([ts, close]);
    ohlcvData.push([ts, open, high, low, close, vol]);
  }
  const candlesKite = parseCandles({grapthData, ohlcvData});
  check(candlesKite.length === 30, `parseCandles returns all 30 real points (got ${candlesKite.length})`);
  check(candlesKite[10].o === ohlcvData[10][1] && candlesKite[10].h === ohlcvData[10][2] && candlesKite[10].l === ohlcvData[10][3] && candlesKite[10].v === ohlcvData[10][5], 'a real mid-series candle carries its real o/h/l/v through exactly, not the synthetic h=l=o=c/v=null placeholder');
  check(candlesKite.every(c => c.v !== null), 'every real candle this refresh has a genuine, non-null volume (real Kite OHLCV path)');

  const candlesNse = parseCandles({grapthData}); // real, honest NSE-direct shape - no ohlcvData at all
  check(candlesNse.every(c => c.v === null && c.h === c.c && c.l === c.c && c.o === c.c), 'with no real d.ohlcvData (ordinary NSE-direct path) parseCandles still correctly falls back to the honest synthetic h=l=o=c/v=null placeholder - zero regression');

  // Mismatched-length/malformed ohlcvData - must never fabricate a
  // wrong OHLCV row onto the wrong real close point.
  const candlesMismatch = parseCandles({grapthData, ohlcvData: ohlcvData.slice(0, 5)});
  check(candlesMismatch[10].v === null && candlesMismatch[10].h === candlesMismatch[10].c, 'a real point past the end of a genuinely shorter ohlcvData array honestly falls back to the synthetic placeholder, never reuses a mismatched row');
  check(candlesMismatch[2].v === ohlcvData[2][5], 'the real points that DO have a genuine matching ohlcvData row still use it correctly even when the array is short overall');

  console.log(`\n=== computeTechFactors: the 6 real-daily-OHLCV-computable H/L/V factors fire for real when candles carry real h/l/v ===\n`);
  const rowsKite = computeTechFactors(candlesKite, {});
  ['Support Previous Day Low','Resistance Previous Day High','ATR Stop Distance','Rejection Wick','Volume Confirmation','Supertrend Direction'].forEach(name=>{
    const row = rowsKite.find(r=>r.factor===name);
    check(row && row.pass !== null, `${name} is genuinely computed (pass!==null) on real Kite daily OHLCV candles, not stuck at the old permanent NOT COMPUTABLE null`);
    check(row && !/NOT COMPUTABLE/.test(row.reason), `${name}'s reason text no longer says NOT COMPUTABLE when real h/l/v is genuinely available`);
  });
  ['Opening Range 15m High/Low','OR Range Size'].forEach(name=>{
    const row = rowsKite.find(r=>r.factor===name);
    check(row && row.pass === null && /INTRADAY/.test(row.reason), `${name} correctly, honestly stays null even with real daily OHLCV - genuinely needs intraday-interval bars, which this data source still does not provide`);
  });

  console.log(`\n=== computeTechFactors: zero regression on the ordinary NSE-direct (close-only) path ===\n`);
  const rowsNse = computeTechFactors(candlesNse, {});
  ['Support Previous Day Low','Resistance Previous Day High','ATR Stop Distance','Rejection Wick','Volume Confirmation','Supertrend Direction'].forEach(name=>{
    const row = rowsNse.find(r=>r.factor===name);
    check(row && row.pass === null && /NOT COMPUTABLE/.test(row.reason), `${name} correctly stays honestly null on the ordinary close-only NSE path - unchanged behavior, zero regression`);
  });

  console.log(`\n=== computeRealDailyHLVFactors: real, hand-verified geometry cases ===\n`);
  // A real, explicit rejection-from-top candle: small body, long upper wick.
  const wickCandles = candlesKite.slice(0, -1).concat([{t: candlesKite[29].t, o: 23000, c: 23010, h: 23120, l: 22995, v: 1200000}]);
  const wickRows = computeRealDailyHLVFactors(wickCandles);
  const wickRow = wickRows.find(r=>r.factor==='Rejection Wick');
  check(wickRow.pass === false && /top \(bearish\)/.test(wickRow.reason), `a real, hand-built long-upper-wick/small-body last candle is correctly detected as a real bearish rejection from the top (got pass=${wickRow.pass})`);

  // Real too-few-candles cases - must honestly degrade, never crash.
  const shortRows = computeRealDailyHLVFactors(candlesKite.slice(0,1));
  check(shortRows.find(r=>r.factor==='Support Previous Day Low').pass === null, 'with only 1 real candle, Support Previous Day Low honestly stays null (no real prior day to compare)');
  check(shortRows.find(r=>r.factor==='ATR Stop Distance').pass === null, 'with only 1 real candle, ATR Stop Distance honestly stays null (need 15+)');
  check(shortRows.find(r=>r.factor==='Volume Confirmation').pass === null, 'with only 1 real candle, Volume Confirmation honestly stays null (need 21+)');
  check(shortRows.find(r=>r.factor==='Supertrend Direction').pass === null, 'with only 1 real candle, Supertrend Direction honestly stays null (need 12+)');
}

console.log(`\n=== fno_fetch_chart_fn: real Kite daily-fallback now emits ohlcvData too (textual PHP-source check) ===\n`);
{
  const phpSource = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
  check(/'ohlcvData' => \$ohlcvData,/.test(phpSource), 'the real Kite-historical fallback response genuinely includes ohlcvData alongside the existing grapthData, not a replacement (backward compatible)');
  check(/isset\(\$c\[1\]\) \? \(float\) \$c\[1\] : null/.test(phpSource), 'the real per-day open value is genuinely extracted from Kite\'s own real candle response, honestly null if genuinely absent rather than guessed');
}

console.log(`\n=== VIX/Spot/Futures previous-close day-change% (Zerodha-maximization audit, final backlog item) ===\n`);
{
  console.log(`--- computeMarketFactors: real "VIX Day Change %" / "Spot Day Change %" wiring ---`);
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, h:23000+i, l:23000+i, o:23000+i, v:1000}));

  const rowsFull = computeMarketFactors(candles, {day:'Wednesday', time:'11:00', vix: 14.5, vixChangePct: 3.2, vixPrevClose: 14.05, spot: 23059, spotPrevClose: 23000});
  const vixDayRow = rowsFull.find(r=>r.factor==='VIX Day Change %');
  check(vixDayRow && vixDayRow.pass === true && /\+3\.20%/.test(vixDayRow.reason), `a real vixChangePct correctly produces a real, informational VIX Day Change % row (got pass=${vixDayRow&&vixDayRow.pass}, reason="${vixDayRow&&vixDayRow.reason}")`);
  check(vixDayRow && vixDayRow.score === 0, 'VIX Day Change % stays informational (score 0), never silently starts voting directionally');
  const spotDayRow = rowsFull.find(r=>r.factor==='Spot Day Change %');
  check(spotDayRow && spotDayRow.pass === true && /\+0\.26%/.test(spotDayRow.reason), `a real ctx.spotPrevClose correctly produces a real Spot Day Change % row (got reason="${spotDayRow&&spotDayRow.reason}")`);

  const rowsVixOnly = computeMarketFactors(candles, {day:'Wednesday', time:'11:00', vix: 14.5});
  const vixDayRowNoChange = rowsVixOnly.find(r=>r.factor==='VIX Day Change %');
  check(vixDayRowNoChange && vixDayRowNoChange.pass === null && /genuinely was not returned/.test(vixDayRowNoChange.reason), 'a real VIX level with NO real vixChangePct this refresh (e.g. a premium provider active) honestly stays null, never guessed');

  const rowsNoVix = computeMarketFactors(candles, {day:'Wednesday', time:'11:00'});
  const vixDayRowAbsent = rowsNoVix.find(r=>r.factor==='VIX Day Change %');
  check(vixDayRowAbsent && vixDayRowAbsent.pass === null && /VIX feed unavailable/.test(vixDayRowAbsent.reason), 'VIX genuinely unavailable this refresh -> VIX Day Change % honestly stays null too');
  const spotDayRowAbsent = rowsNoVix.find(r=>r.factor==='Spot Day Change %');
  check(spotDayRowAbsent && spotDayRowAbsent.pass === null && /NSE-direct/.test(spotDayRowAbsent.reason), 'no real ctx.spotPrevClose (ordinary NSE-direct path) -> Spot Day Change % honestly stays null, explains why');

  console.log(`\n--- computeFuturesFactors: real "Futures Day Change %" wiring ---`);
  const allFuturesWithPrevClose = [{expiryDate:'27-Aug-2026', lastPrice:23260, previousClose: 23100}];
  const futRows = computeFuturesFactors(23200, 23260, 5, 0.065, allFuturesWithPrevClose);
  const futChangeRow = futRows.find(r=>r.factor==='Futures Day Change %');
  check(futChangeRow && futChangeRow.pass === true && /\+0\.69%/.test(futChangeRow.reason), `a real allFutures[0].previousClose correctly produces a real Futures Day Change % (got reason="${futChangeRow&&futChangeRow.reason}")`);
  check(futChangeRow && futChangeRow.score === 0, 'Futures Day Change % stays informational, never scored directionally');

  const allFuturesNoPrevClose = [{expiryDate:'27-Aug-2026', lastPrice:23260, previousClose: null}];
  const futRowsNoPrevClose = computeFuturesFactors(23200, 23260, 5, 0.065, allFuturesNoPrevClose);
  const futChangeRowNull = futRowsNoPrevClose.find(r=>r.factor==='Futures Day Change %');
  check(futChangeRowNull && futChangeRowNull.pass === null && /never verified/.test(futChangeRowNull.reason), 'genuinely null previousClose (e.g. NSE-direct futures path, unverified field name) -> Futures Day Change % honestly stays null, explains why');

  const futRowsNoFutures = computeFuturesFactors(23200, null, 5, 0.065);
  const futChangeRowUnavail = futRowsNoFutures.find(r=>r.factor==='Futures Day Change %');
  check(futChangeRowUnavail && futChangeRowUnavail.pass === null, 'futures price itself unavailable -> Futures Day Change % correctly stays unavailable too, not a crash');

  console.log(`\n--- refreshBrain: real ctx wiring (textual PHP+JS source checks) ---`);
  check(/vixChangePct: typeof status\.vixChangePct==='number'/.test(coreSource), 'refreshBrain() genuinely threads status.vixChangePct into ctx');
  check(/spotPrevClose: \(rec && rec\.underlyingOhlc/.test(coreSource), 'refreshBrain() genuinely reads the real, previously-unused rec.underlyingOhlc into ctx.spotPrevClose');
  const phpSource2 = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
  check(/vixChangePct = \(float\) \$row\['pChange'\]/.test(phpSource2), "the real, NSE-side VIX resolver genuinely captures NSE's own pChange field (the same field name this codebase already trusts elsewhere for this exact response shape)");
  check(/'previousClose' => \(isset\(\$q\['ohlc'\]\['close'\]\)/.test(phpSource2), 'the real Kite-fallback futures path genuinely captures ohlc.close (previously fetched, never read) as previousClose');
}

console.log(`\n=== RESULT: ${passed} passed, ${failed} failed ===`);
process.exit(failed > 0 ? 1 : 0);
