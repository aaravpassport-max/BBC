/**
 * Detects suspected synthetic/superficial input — repetitive, highly
 * regular mouse "heartbeat" events that keep a session awake without any
 * corroborating sign of real work (no foreground-app switch, no browser
 * navigation, no keyboard input, no approved exception).
 *
 * DESIGN CONSTRAINTS (deliberate, privacy- and fairness-driven):
 *  - Works ONLY off the event shape the agent already emits — event
 *    timestamps and `payload.kind` ("keyboard" | "mouse" | "exception").
 *    No cursor coordinates, keystroke content, or any new raw-event field
 *    is introduced. This function could not identify a specific pixel
 *    path even if it wanted to — it only ever sees "a mouse heartbeat
 *    occurred at time T", which is exactly what's safe to collect.
 *  - It FLAGS, it never CONVICTS. The output is "suspected", with a
 *    confidence level and a human-readable reason — never a boolean
 *    "employee cheated". See activity-reconciler.ts's use of this: a flag
 *    stops the input from resetting the inactivity timer, it does not by
 *    itself write anything into an employee's record as misconduct.
 *  - Corroboration always wins: if anything resembling real engagement
 *    (an app switch, a browser navigation, a keyboard event, an approved
 *    exception) falls inside the suspicious window, the run is NOT
 *    flagged. The goal is catching a mouse-jiggler running with nothing
 *    else happening on the machine, not penalizing someone reading a long
 *    document or in a meeting with occasional mouse nudges.
 *  - This is explicitly NOT a claim that every form of synthetic input is
 *    detectable this way — a jiggler with intentionally randomized
 *    timing, or one combined with occasional real clicks, will not always
 *    trip this. The spec this implements is explicit that no such
 *    guarantee should be made; this is one honest, bounded signal among
 *    several, not a proof mechanism.
 */

import type { AnyRawEvent, InputActivityEvent } from "../domain/events.js";

export interface AnomalyDetectionConfig {
  /** A run shorter than this many consecutive mouse-only heartbeats is never evaluated — too little data to say anything. */
  minRunEvents: number;
  /** Consecutive mouse events further apart than this (seconds) are considered separate runs, not one continuous pattern. */
  maxIntraRunGapSeconds: number;
  /** A run must span at least this long (seconds) before it's even eligible to be flagged — a short burst of regular clicks is ordinary. */
  minRunDurationSeconds: number;
  /** Coefficient of variation (stdev / mean) of inter-event gaps at or below this is "suspiciously regular" for human input. */
  maxCoefficientOfVariation: number;
}

export const DEFAULT_ANOMALY_CONFIG: AnomalyDetectionConfig = {
  minRunEvents: 6,
  maxIntraRunGapSeconds: 90,
  minRunDurationSeconds: 600, // 10 minutes
  maxCoefficientOfVariation: 0.08,
};

export interface SyntheticInputFlag {
  startUtc: string;
  endUtc: string;
  eventIds: string[];
  reason: string;
  confidence: "medium" | "high";
}

function isMouseInput(ev: AnyRawEvent): ev is InputActivityEvent {
  return ev.type === "input.activity" && (ev as InputActivityEvent).payload.kind === "mouse";
}

function isCorroboratingSignal(ev: AnyRawEvent): boolean {
  if (ev.type === "app.foreground_start" || ev.type === "app.foreground_end") return true;
  if (ev.type === "browser.tab_active_start" || ev.type === "browser.navigation") return true;
  if (ev.type === "input.activity") {
    const kind = (ev as InputActivityEvent).payload.kind;
    return kind === "keyboard" || kind === "exception";
  }
  return false;
}

/**
 * Pure function: raw events for ONE session in, suspected-synthetic
 * mouse-input runs out. Does not mutate or need anything beyond the event
 * list — same reproducibility guarantee as the reconciler it feeds.
 */
export function detectSyntheticInputRuns(
  events: AnyRawEvent[],
  config: AnomalyDetectionConfig = DEFAULT_ANOMALY_CONFIG,
): SyntheticInputFlag[] {
  const sorted = [...events].sort((a, b) => Date.parse(a.timestampUtc) - Date.parse(b.timestampUtc));
  const mouseEvents = sorted.filter(isMouseInput);
  if (mouseEvents.length < config.minRunEvents) return [];

  const maxGapMs = config.maxIntraRunGapSeconds * 1000;
  const runs: InputActivityEvent[][] = [];
  let current: InputActivityEvent[] = [mouseEvents[0]!];
  for (let i = 1; i < mouseEvents.length; i++) {
    const gapMs = Date.parse(mouseEvents[i]!.timestampUtc) - Date.parse(mouseEvents[i - 1]!.timestampUtc);
    if (gapMs <= maxGapMs) {
      current.push(mouseEvents[i]!);
    } else {
      runs.push(current);
      current = [mouseEvents[i]!];
    }
  }
  runs.push(current);

  const flags: SyntheticInputFlag[] = [];
  for (const run of runs) {
    if (run.length < config.minRunEvents) continue;

    const startMs = Date.parse(run[0]!.timestampUtc);
    const endMs = Date.parse(run[run.length - 1]!.timestampUtc);
    const durationSeconds = (endMs - startMs) / 1000;
    if (durationSeconds < config.minRunDurationSeconds) continue;

    const intervals: number[] = [];
    for (let i = 1; i < run.length; i++) {
      intervals.push(Date.parse(run[i]!.timestampUtc) - Date.parse(run[i - 1]!.timestampUtc));
    }
    const mean = intervals.reduce((a, b) => a + b, 0) / intervals.length;
    if (mean <= 0) continue;
    const variance = intervals.reduce((a, b) => a + (b - mean) ** 2, 0) / intervals.length;
    const coefficientOfVariation = Math.sqrt(variance) / mean;
    // Ordinary human mouse activity varies far more than this; only an
    // almost metronomic pattern trips the threshold.
    if (coefficientOfVariation > config.maxCoefficientOfVariation) continue;

    const corroborated = sorted.some((ev) => {
      const t = Date.parse(ev.timestampUtc);
      return t >= startMs && t <= endMs && isCorroboratingSignal(ev);
    });
    if (corroborated) continue;

    flags.push({
      startUtc: run[0]!.timestampUtc,
      endUtc: run[run.length - 1]!.timestampUtc,
      eventIds: run.map((ev) => ev.eventId),
      reason:
        `Mouse input roughly every ${Math.round(mean / 1000)}s for ${Math.round(durationSeconds / 60)} min ` +
        `with almost no timing variation (coefficient of variation ${coefficientOfVariation.toFixed(3)}) and no ` +
        `corroborating application switch, browser navigation, keyboard input, or approved exception in that window.`,
      confidence: durationSeconds >= 1200 ? "high" : "medium",
    });
  }
  return flags;
}
