import { test } from "node:test";
import assert from "node:assert/strict";
import { reconcileSession, DEFAULT_RECONCILER_CONFIG } from "../src/engine/activity-reconciler.js";
import { ev, t, minutesBetween } from "./helpers.js";

test("spec example: 5-minute inactivity rule backdates break start and ends it on first resumed input", () => {
  // Sign in at 09:55, active input at 10:00, nothing until first resumed
  // input at 10:12. Per spec: break = 10:00-10:12 = 12 minutes, NOT
  // 10:05-10:12 (7 min) and NOT ending exactly at threshold.
  const events = [
    ev("session.signin", t("09:55")),
    ev("input.activity", t("10:00"), { payload: { kind: "keyboard" } }),
    // No events between 10:00 and 10:12 — the engine must detect the gap
    // when it next sees an event at/after 10:05, by comparing to lastInputMs.
    ev("input.activity", t("10:12"), { payload: { kind: "keyboard" } }),
  ];

  const result = reconcileSession(events as any);

  assert.equal(result.anomalies.length, 0, "no anomalies expected in this clean case");
  assert.equal(result.breakIntervals.length, 1, "exactly one break should be detected");

  const brk = result.breakIntervals[0]!;
  assert.equal(brk.startUtc, t("10:00"), "break must be backdated to last meaningful input, not to +5min");
  assert.equal(brk.endUtc, t("10:12"), "break must end at the first resumed interaction");
  assert.equal(minutesBetween(brk.startUtc, brk.endUtc!), 12, "total break duration must be exactly 12 minutes per the spec example");

  // Verify the active state interval was closed BACKDATED at 10:00, not
  // left running until 10:05 or 10:12 — this is the mechanism that makes
  // the "extra" apparent active time disappear from the active bucket.
  const activeIntervals = result.stateIntervals.filter((s) => s.state === "active");
  assert.ok(activeIntervals.length >= 1);
  const firstActive = activeIntervals[0]!;
  assert.equal(firstActive.startUtc, t("09:55"));
  assert.equal(firstActive.endUtc, t("10:00"), "active interval must end exactly at the backdated break start");
});

test("no break is inserted when input arrives before the threshold elapses", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00")),
    ev("input.activity", t("09:04")), // only 4 min gap, under 5-min threshold
    ev("input.activity", t("09:07")), // another 3 min gap
  ];

  const result = reconcileSession(events as any);
  assert.equal(result.breakIntervals.length, 0, "sub-threshold gaps must never produce a break");
});

test("exactly-at-threshold gap does trigger a break (>= threshold, not > threshold)", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00")),
    ev("input.activity", t("09:05")), // exactly 300s gap
  ];
  const result = reconcileSession(events as any, { inactivityThresholdSeconds: 300 });
  assert.equal(result.breakIntervals.length, 1, "a gap exactly equal to the threshold must count as a break");
  assert.equal(result.breakIntervals[0]!.startUtc, t("09:00"));
  assert.equal(result.breakIntervals[0]!.endUtc, t("09:05"));
});

test("locking the PC mid-gap ends the break at lock time, not at the backdated point, and excludes locked time", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00")),
    ev("session.lock", t("09:20")), // 20 min after last input — way past threshold
    ev("session.unlock", t("09:45")),
    ev("input.activity", t("09:46")),
  ];

  const result = reconcileSession(events as any);

  // The break must be 09:00-09:20 (backdated start, ends at lock), not
  // extend through the locked period.
  assert.equal(result.breakIntervals.length, 1);
  assert.equal(result.breakIntervals[0]!.startUtc, t("09:00"));
  assert.equal(result.breakIntervals[0]!.endUtc, t("09:20"));

  const lockedIntervals = result.stateIntervals.filter((s) => s.state === "locked");
  assert.equal(lockedIntervals.length, 1);
  assert.equal(lockedIntervals[0]!.startUtc, t("09:20"));
  assert.equal(lockedIntervals[0]!.endUtc, t("09:45"), "locked interval must end at unlock, not merge into surrounding states");
});

test("sleep time is never classified as active or idle_break — it is its own exclusive state", () => {
  // Input arrives shortly (1 min) before sleep — well under the 5-minute
  // threshold — so there is no legitimate idle gap preceding sleep. This
  // isolates "does sleep itself spuriously create a break" from the
  // separate, correct case where a real idle gap precedes sleep.
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:09")),
    ev("pc.sleep", t("09:10")),
    ev("pc.wake", t("13:00")), // long sleep
    ev("input.activity", t("13:01")),
  ];
  const result = reconcileSession(events as any);
  const sleepIntervals = result.stateIntervals.filter((s) => s.state === "sleep");
  assert.equal(sleepIntervals.length, 1);
  assert.equal(minutesBetween(sleepIntervals[0]!.startUtc, sleepIntervals[0]!.endUtc!), 230);
  assert.equal(result.breakIntervals.length, 0, "a sub-threshold gap followed by sleep must not spuriously create a break");
});

test("a real idle gap that precedes sleep is correctly recorded as its own break, distinct from (not merged into) the sleep interval", () => {
  // Input at 09:00, then nothing until sleep kicks in at 09:10 — a
  // genuine 10-minute idle gap BEFORE the OS decided to sleep. The break
  // (09:00-09:10) and the sleep (09:10-13:00) are two distinct,
  // non-overlapping intervals; this is correct, not double-counting.
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00")),
    ev("pc.sleep", t("09:10")),
    ev("pc.wake", t("13:00")),
  ];
  const result = reconcileSession(events as any);
  assert.equal(result.breakIntervals.length, 1);
  assert.equal(result.breakIntervals[0]!.startUtc, t("09:00"));
  assert.equal(result.breakIntervals[0]!.endUtc, t("09:10"));
  const sleepIntervals = result.stateIntervals.filter((s) => s.state === "sleep");
  assert.equal(sleepIntervals.length, 1);
  assert.equal(sleepIntervals[0]!.startUtc, t("09:10"), "sleep must start exactly where the break ended, no overlap and no gap");
});

test("multiple consecutive breaks within one session are each recorded distinctly", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00")),
    ev("input.activity", t("09:10")), // gap >5min -> break #1: 09:00-09:10
    ev("input.activity", t("09:30")), // gap >5min -> break #2: 09:10-09:30
  ];
  const result = reconcileSession(events as any);
  assert.equal(result.breakIntervals.length, 2);
  assert.equal(result.breakIntervals[0]!.startUtc, t("09:00"));
  assert.equal(result.breakIntervals[0]!.endUtc, t("09:10"));
  assert.equal(result.breakIntervals[1]!.startUtc, t("09:10"));
  assert.equal(result.breakIntervals[1]!.endUtc, t("09:30"));
});

test("signing out mid-break closes the break at signout, never leaves it open", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00")),
    ev("session.signout", t("09:30")), // 30 min after last input, no intervening event
  ];
  const result = reconcileSession(events as any);
  assert.equal(result.breakIntervals.length, 1);
  assert.equal(result.breakIntervals[0]!.endUtc, t("09:30"));
  const signedOut = result.stateIntervals.filter((s) => s.state === "signed_out");
  assert.equal(signedOut.length, 1);
});

test("out-of-order events are flagged as an anomaly and excluded rather than corrupting the cursor", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:05")),
    ev("input.activity", t("09:02")), // out of order!
    ev("input.activity", t("09:10")),
  ];
  const result = reconcileSession(events as any);
  const outOfOrder = result.anomalies.filter((a) => a.type === "out_of_order_event");
  assert.equal(outOfOrder.length, 1);
});

test("an invalid state transition is surfaced as an anomaly, not silently accepted or dropped", () => {
  // signed_out -> locked directly is not in ALLOWED_TRANSITIONS
  const events = [
    ev("session.signout", t("09:00")),
    { ...ev("session.lock", t("09:05")), sessionId: null },
  ];
  const result = reconcileSession(events as any);
  assert.ok(result.anomalies.some((a) => a.type === "invalid_transition"));
});

test("configurable threshold: a shorter threshold detects breaks the default would miss", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00")),
    ev("input.activity", t("09:02")), // 2 min gap
  ];
  const defaultResult = reconcileSession(events as any, DEFAULT_RECONCILER_CONFIG);
  assert.equal(defaultResult.breakIntervals.length, 0, "2min gap is under the 5min default");

  const shortThreshold = reconcileSession(events as any, { inactivityThresholdSeconds: 60 });
  assert.equal(shortThreshold.breakIntervals.length, 1, "2min gap exceeds a 1min threshold");
});

test("suspected synthetic mouse input does NOT reset the inactivity timer or end an existing break", () => {
  // Real input at 09:00, then nothing genuine for the rest of the day
  // EXCEPT a long, perfectly-regular mouse-only heartbeat starting right
  // as the 5-minute threshold would otherwise trip — classic jiggler
  // shape. It must not prevent idle_break from firing, and it must not
  // end the break once it has.
  const events = [
    ev("session.signin", "2026-10-05T09:00:00.000Z"),
    ev("input.activity", "2026-10-05T09:00:00.000Z", { payload: { kind: "keyboard" } }),
  ];
  let t = Date.parse("2026-10-05T09:05:00.000Z"); // exactly at the 5-minute threshold from the last real input
  for (let i = 0; i < 80; i++) {
    events.push(ev("input.activity", new Date(t).toISOString(), { payload: { kind: "mouse" } }));
    t += 15000; // 15s, perfectly regular — 80 * 15s = 20 minutes
  }
  // One genuine keystroke at the very end, well after the jiggler run.
  events.push(ev("input.activity", new Date(t + 60000).toISOString(), { payload: { kind: "keyboard" } }));

  const result = reconcileSession(events as any);

  const breaks = result.stateIntervals.filter((s) => s.state === "idle_break");
  assert.equal(breaks.length, 1, "the jiggler run must not have ended the break partway through");
  assert.equal(breaks[0]!.startUtc, "2026-10-05T09:00:00.000Z", "backdated to the last GENUINE input, not a mouse heartbeat");
  assert.ok(result.anomalies.some((a) => a.type === "synthetic_input_suspected"));

  const activeAfterBreak = result.stateIntervals.filter((s) => s.state === "active");
  assert.equal(activeAfterBreak.length, 1, "only the real keystroke at the end re-activates the session");
});

test("suspected synthetic mouse input is still recorded in the audit trail, just not treated as meaningful input", () => {
  const events = [
    ev("session.signin", "2026-10-05T09:00:00.000Z"),
    ev("input.activity", "2026-10-05T09:00:00.000Z", { payload: { kind: "keyboard" }, eventId: "real-1" }),
  ];
  let t = Date.parse("2026-10-05T09:05:00.000Z");
  const jigglerIds: string[] = [];
  for (let i = 0; i < 70; i++) {
    const id = `jiggler-${i}`;
    jigglerIds.push(id);
    events.push(ev("input.activity", new Date(t).toISOString(), { payload: { kind: "mouse" }, eventId: id }));
    t += 15000;
  }
  const result = reconcileSession(events as any);
  const breakInterval = result.stateIntervals.find((s) => s.state === "idle_break")!;
  // The jiggler events are attributed to the break interval's audit trail even though they didn't end it.
  assert.ok(jigglerIds.every((id) => breakInterval.causedByEventIds.includes(id)));
});

test("ordinary, humanly-jittered mouse input during a genuine break is NOT flagged and behaves exactly as before", () => {
  const events = [
    ev("session.signin", t("09:00")),
    ev("input.activity", t("09:00"), { payload: { kind: "keyboard" } }),
    ev("input.activity", t("09:20"), { payload: { kind: "mouse" } }), // resumes after a real 20-minute break
  ];
  const result = reconcileSession(events as any);
  assert.equal(result.anomalies.filter((a) => a.type === "synthetic_input_suspected").length, 0);
  assert.equal(result.breakIntervals.length, 1);
  assert.equal(result.breakIntervals[0]!.endUtc, t("09:20"));
});
