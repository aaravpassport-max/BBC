import { test } from "node:test";
import assert from "node:assert/strict";
import { detectSyntheticInputRuns, DEFAULT_ANOMALY_CONFIG } from "../src/engine/anomaly-detection.js";
import { ev } from "./helpers.js";

/**
 * Synthetic (not "mouse-jiggler" synthetic — test-fixture synthetic)
 * helper: builds N mouse-kind input.activity events exactly
 * `intervalSeconds` apart starting at `startIso`, with each timestamp
 * perturbed by `jitterSeconds` (0 = perfectly regular, the jiggler case;
 * a few seconds of randomness = ordinary human mouse movement).
 */
function mouseRun(startIso: string, count: number, intervalSeconds: number, jitterSeconds: number, seed = 1): ReturnType<typeof ev>[] {
  const out: ReturnType<typeof ev>[] = [];
  let t = Date.parse(startIso);
  let s = seed;
  for (let i = 0; i < count; i++) {
    out.push(ev("input.activity", new Date(t).toISOString(), { payload: { kind: "mouse" } }));
    // a tiny deterministic pseudo-random jitter, not Math.random() — keeps the test reproducible
    s = (s * 48271) % 2147483647;
    const jitter = jitterSeconds === 0 ? 0 : (s % (jitterSeconds * 2)) - jitterSeconds;
    t += (intervalSeconds + jitter) * 1000;
  }
  return out;
}

test("detectSyntheticInputRuns: flags a long, near-perfectly-regular mouse-only run with no corroborating signal", () => {
  const events = mouseRun("2026-10-05T09:00:00.000Z", 60, 15, 0); // 60 events, 15s apart, zero jitter, 14.75 minutes
  const flags = detectSyntheticInputRuns(events);
  assert.equal(flags.length, 1);
  assert.equal(flags[0]!.confidence, "medium");
  assert.ok(flags[0]!.eventIds.length >= DEFAULT_ANOMALY_CONFIG.minRunEvents);
});

test("detectSyntheticInputRuns: a long run flagged at high confidence once it clears 20 minutes", () => {
  const events = mouseRun("2026-10-05T09:00:00.000Z", 90, 15, 0); // 90 * 15s ≈ 22.25 minutes
  const flags = detectSyntheticInputRuns(events);
  assert.equal(flags.length, 1);
  assert.equal(flags[0]!.confidence, "high");
});

test("detectSyntheticInputRuns: ordinary human mouse activity (jittered timing) is NOT flagged", () => {
  const events = mouseRun("2026-10-05T09:00:00.000Z", 60, 15, 10); // real human-scale variation
  const flags = detectSyntheticInputRuns(events);
  assert.equal(flags.length, 0);
});

test("detectSyntheticInputRuns: a regular run WITH a corroborating app switch in the window is NOT flagged — context matters", () => {
  const events: ReturnType<typeof ev>[] = [
    ...mouseRun("2026-10-05T09:00:00.000Z", 60, 15, 0),
    ev("app.foreground_start", "2026-10-05T09:07:00.000Z", { payload: { executableName: "excel.exe", processId: 1 } }),
  ];
  const flags = detectSyntheticInputRuns(events);
  assert.equal(flags.length, 0);
});

test("detectSyntheticInputRuns: a regular run WITH keyboard input in the window is NOT flagged", () => {
  const events: ReturnType<typeof ev>[] = [
    ...mouseRun("2026-10-05T09:00:00.000Z", 60, 15, 0),
    ev("input.activity", "2026-10-05T09:07:00.000Z", { payload: { kind: "keyboard" } }),
  ];
  const flags = detectSyntheticInputRuns(events);
  assert.equal(flags.length, 0);
});

test("detectSyntheticInputRuns: a short regular run below the minimum duration is NOT flagged", () => {
  const events = mouseRun("2026-10-05T09:00:00.000Z", 10, 15, 0); // 10 * 15s = 2.5 minutes, well under the 10-minute floor
  const flags = detectSyntheticInputRuns(events);
  assert.equal(flags.length, 0);
});

test("detectSyntheticInputRuns: a gap longer than maxIntraRunGapSeconds splits one long pattern into two separate (each-too-short) runs", () => {
  const first = mouseRun("2026-10-05T09:00:00.000Z", 20, 15, 0); // 5 minutes
  const second = mouseRun("2026-10-05T09:10:00.000Z", 20, 15, 0); // another 5 minutes, 5-minute gap from the first (> 90s maxIntraRunGapSeconds)
  const flags = detectSyntheticInputRuns([...first, ...second]);
  assert.equal(flags.length, 0); // each half is below minRunDurationSeconds on its own
});

test("detectSyntheticInputRuns: fewer than minRunEvents mouse events overall never gets evaluated", () => {
  const events = mouseRun("2026-10-05T09:00:00.000Z", 3, 15, 0);
  assert.equal(detectSyntheticInputRuns(events).length, 0);
});

test("detectSyntheticInputRuns: keyboard-kind input is never itself treated as part of a mouse run, even with identical timing", () => {
  const events: ReturnType<typeof ev>[] = [];
  let t = Date.parse("2026-10-05T09:00:00.000Z");
  for (let i = 0; i < 60; i++) {
    events.push(ev("input.activity", new Date(t).toISOString(), { payload: { kind: "keyboard" } }));
    t += 15000;
  }
  assert.equal(detectSyntheticInputRuns(events).length, 0);
});
