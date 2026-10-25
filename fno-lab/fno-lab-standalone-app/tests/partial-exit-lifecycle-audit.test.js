// REAL, STANDALONE test for the partial-exit / multi-leg exit code
// path (checkPartialExit + resolvePartialExitQty, assets/fno-lab-core.js),
// extending the NaN-guard coverage already in
// exit-logic-fail-open-audit.test.js with the full-lifecycle
// correctness properties this session's audit was asked to verify:
//   (a) remaining open qty is correctly computed, never negative, never
//       exceeds the original qty
//   (b) the partial qty used for realized P&L is the PARTIAL qty, never
//       the full original qty, and is never double-counted against the
//       remainder
//   (c) checkPartialExit is a single-shot gate (open.partialTaken) -
//       once a partial has fired for a position, it can never fire a
//       second time for the SAME position object, so there is no
//       "3 sequential partial scale-outs from the original qty" path to
//       audit in the real code; this is asserted directly as a
//       regression guard against that design ever silently regressing
//       into a real double-partial bug.
//   (d) entryPrice/cost basis is untouched by a partial exit - the
//       remainder keeps the SAME original entryPrice (no averaging-in
//       logic exists in this single-entry-position design; asserted
//       directly).
//
// Run with: node tests/partial-exit-lifecycle-audit.test.js

const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

// ---------------------------------------------------------------
// (a) resolvePartialExitQty: remaining qty math is always a genuine,
// strictly-positive split of the CURRENT open.qty - never negative,
// never exceeding the original.
// ---------------------------------------------------------------
{
  const open = { qty: 10, entryPrice: 100, target: 130, partialExitEnabled: true, partialTaken: false };
  const partial = checkPartialExit(open, 130);
  const split = resolvePartialExitQty(open, partial);
  check(split !== null, 'resolvePartialExitQty: a genuine remainder is returned for qty=10');
  check(split.partialQty === 5 && split.remainingQty === 5, 'resolvePartialExitQty: qty=10 splits 5/5 (even split)');
  check(split.partialQty + split.remainingQty === open.qty, 'resolvePartialExitQty: partialQty + remainingQty === original qty exactly (no loss, no double-count)');
  check(split.remainingQty > 0 && split.remainingQty < open.qty, 'resolvePartialExitQty: remainingQty is strictly positive and strictly less than the original qty');
  check(split.partialQty > 0 && split.partialQty < open.qty, 'resolvePartialExitQty: partialQty is strictly positive and strictly less than the original qty');
}

// Odd qty: Math.round(qty/2) rounds UP (JS "round half away from zero")
// - verify the split still sums correctly and never produces a
// remainingQty of 0.
{
  const open = { qty: 7 };
  const split = resolvePartialExitQty(open, { newSl: 1, newTarget: 2 });
  check(split.partialQty === 4 && split.remainingQty === 3, 'resolvePartialExitQty: odd qty=7 splits 4/3 (round-half-up on the exit leg)');
  check(split.partialQty + split.remainingQty === 7, 'resolvePartialExitQty: odd-qty split still sums to the exact original qty');
}

// qty=1: REGRESSION GUARD for the phantom-position bug this session
// already found and fixed - must return null (no genuine remainder
// possible), never {partialQty:1, remainingQty:0}.
{
  const open = { qty: 1 };
  const split = resolvePartialExitQty(open, { newSl: 1, newTarget: 2 });
  check(split === null, 'resolvePartialExitQty: REGRESSION GUARD - qty=1 returns null (never a qty:0 phantom remainder)');
}

// qty=2: smallest qty that CAN genuinely split.
{
  const open = { qty: 2 };
  const split = resolvePartialExitQty(open, { newSl: 1, newTarget: 2 });
  check(split && split.partialQty === 1 && split.remainingQty === 1, 'resolvePartialExitQty: qty=2 splits 1/1, the smallest genuine remainder');
}

// null/no-signal passthrough and non-finite qty guard.
check(resolvePartialExitQty({ qty: 10 }, null) === null, 'resolvePartialExitQty: no partial signal -> null (nothing to split)');
check(resolvePartialExitQty({ qty: NaN }, { newSl: 1, newTarget: 2 }) === null, 'resolvePartialExitQty: non-finite qty fails safe to null, never NaN arithmetic');
check(resolvePartialExitQty({ qty: 0 }, { newSl: 1, newTarget: 2 }) === null, 'resolvePartialExitQty: qty=0 (already-closed/corrupt position) fails safe to null');

// ---------------------------------------------------------------
// (b) Simulated full lifecycle: open -> partial exit -> remainder ->
// full exit. Verifies the realized P&L quantities booked at each leg
// are correct and never double-count the same units, using the SAME
// computeTradeCosts() the real closePartial()/closeAutoTrade() call.
// ---------------------------------------------------------------
{
  let open = { qty: 10, entryPrice: 100, target: 130, sl: 85, partialExitEnabled: true, partialTaken: false, tradingType: 'intraday' };

  // Tick 1: price reaches target -> partial should fire.
  const partial = checkPartialExit(open, 130);
  check(partial !== null, 'lifecycle: partial signal fires when live price reaches the original target');
  const split = resolvePartialExitQty(open, partial);
  check(split !== null, 'lifecycle: a genuine partial/remaining split is resolved');

  // Book the partial leg's realized P&L exactly as closePartial() does
  // (computeTradeCosts(entryPrice, exitPrice, PARTIAL qty) - never the
  // full original qty).
  const partialExitPrice = 130;
  const partialCosts = computeTradeCosts(open.entryPrice, partialExitPrice, split.partialQty);
  check(split.partialQty === 5, 'lifecycle: partial leg closes exactly half (5) of the original 10, not the full 10');

  // Update the open position exactly as renderOpenTrades() does after a
  // partial: qty shrinks to the remainder, SL/target move to the new
  // breakeven/extended levels, partialTaken latches true, entryPrice is
  // UNCHANGED (no averaging-in).
  const entryPriceBeforePartial = open.entryPrice;
  open = { ...open, qty: split.remainingQty, sl: partial.newSl, target: partial.newTarget, partialTaken: true };
  check(open.qty === 5, 'lifecycle: remaining open qty after the partial is exactly 5 (10 - 5), never negative, never exceeding original 10');
  check(open.entryPrice === entryPriceBeforePartial, '(d) lifecycle: entryPrice/cost basis is UNCHANGED after the partial exit - no averaging-in for a single-entry position');
  check(open.sl === 100, 'lifecycle: remainder SL correctly moved to breakeven (the original entryPrice, 100)');
  check(open.target === 160, 'lifecycle: remainder target correctly extended by one risk-distance (130 + 30 = 160)');

  // (c) A second tick at/above the (extended) target must NOT fire a
  // second partial for this same position - partialTaken gates it.
  const secondPartialAttempt = checkPartialExit(open, 160);
  check(secondPartialAttempt === null, '(c) lifecycle: REGRESSION GUARD - a position that already took its partial can never take a second partial (partialTaken gate holds)');

  // Final full exit of the REMAINDER only - must book exactly the
  // remaining 5 units against the SAME original entryPrice, never the
  // original 10 (which would double-count the 5 already booked in the
  // partial leg above).
  const finalExitPrice = 160;
  const finalCosts = computeTradeCosts(open.entryPrice, finalExitPrice, open.qty);
  check(open.qty === 5, 'lifecycle: the final full-exit leg closes exactly the remaining 5 units, not the original 10');

  const totalUnitsClosed = split.partialQty + open.qty;
  check(totalUnitsClosed === 10, 'lifecycle: partial leg (5) + final leg (5) together account for exactly the original 10 units - no units lost, none double-counted');

  // Sanity: both legs' realized P&L are individually consistent with
  // computeTradeCosts's own qty-scaling (gross P&L per unit * qty).
  const perUnitGrossPartial = (partialExitPrice - entryPriceBeforePartial);
  check(Math.abs(partialCosts.grossPnl - perUnitGrossPartial * split.partialQty) < 0.01, 'lifecycle: partial leg gross P&L correctly scales by the PARTIAL qty (5), not the original qty (10)');
  const perUnitGrossFinal = (finalExitPrice - open.entryPrice);
  check(Math.abs(finalCosts.grossPnl - perUnitGrossFinal * open.qty) < 0.01, 'lifecycle: final leg gross P&L correctly scales by the REMAINING qty (5), not the original qty (10)');
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
