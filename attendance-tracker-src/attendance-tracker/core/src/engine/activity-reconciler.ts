/**
 * The activity reconciliation engine.
 *
 * This is the single most important piece of logic in the whole system —
 * it is the thing that decides "was this person actually working right
 * now," and getting it wrong in either direction is a real harm: wrongly
 * crediting idle time misrepresents the record, and wrongly debiting
 * legitimate work (a phone call, reading, a meeting with no input) treats
 * normal human behavior as absence.
 *
 * DESIGN: there is exactly ONE source of truth produced here —
 * `stateIntervals`, a non-overlapping timeline of EmployeeState. Break
 * intervals are NOT independently tracked during the walk; they are
 * derived afterward as a pure projection ("every idle_break interval IS a
 * break"). An earlier version of this file kept a second, parallel
 * `openBreak` bookkeeping structure alongside the state timeline, and the
 * two could desynchronize (e.g. a sleep event closing an idle_break state
 * interval without the parallel structure also closing out a break
 * record). Deriving breaks from the single timeline instead of tracking
 * them twice removes that entire class of bug by construction.
 *
 * It is a PURE function over a sorted list of raw events for a single
 * session: given events in, state intervals + break intervals out, no
 * hidden state, no wall-clock reads, no I/O. That is what makes it
 * testable deterministically and auditable: anyone can re-run it over the
 * same raw events and get the same report.
 */

import type { EmployeeState, StateInterval } from "../domain/state.js";
import type { BreakInterval } from "../domain/session.js";
import { isTransitionAllowed } from "../domain/state.js";
import type { AnyRawEvent } from "../domain/events.js";
import {
  detectSyntheticInputRuns,
  DEFAULT_ANOMALY_CONFIG,
  type AnomalyDetectionConfig,
  type SyntheticInputFlag,
} from "./anomaly-detection.js";

export interface ReconcilerConfig {
  /** Default 300 seconds per spec. Admin-configurable. */
  inactivityThresholdSeconds: number;
  /** Thresholds for flagging suspected synthetic/mouse-jiggler input. Admin-configurable; see anomaly-detection.ts. */
  anomaly?: AnomalyDetectionConfig;
}

export const DEFAULT_RECONCILER_CONFIG: ReconcilerConfig = {
  inactivityThresholdSeconds: 300,
  anomaly: DEFAULT_ANOMALY_CONFIG,
};

export interface ReconciliationResult {
  stateIntervals: StateInterval[];
  breakIntervals: BreakInterval[];
  /** Any invalid transition the engine encountered — surfaced, never silently dropped. */
  anomalies: ReconciliationAnomaly[];
}

export interface ReconciliationAnomaly {
  type: "invalid_transition" | "out_of_order_event" | "synthetic_input_suspected";
  message: string;
  atUtc: string;
  eventIds: string[];
}

interface MutableCursor {
  state: EmployeeState;
  /** UTC ms of when the current state interval began. */
  stateStartMs: number;
  /** UTC ms of the last meaningful input (keyboard/mouse/exception). Null = none observed yet this session. */
  lastInputMs: number | null;
  /** The event(s) that caused/occurred during the current state interval, for audit trail. */
  causedByEventIds: string[];
  /** Whether the CURRENT interval's start time was backdated (inferred) rather than observed. */
  startIsBackdated: boolean;
}

function toMs(iso: string): number {
  const ms = Date.parse(iso);
  if (Number.isNaN(ms)) {
    throw new Error(`Invalid ISO timestamp: ${iso}`);
  }
  return ms;
}

function toIso(ms: number): string {
  return new Date(ms).toISOString();
}

/**
 * Reconciles a single session's raw events into state + break intervals.
 *
 * Events MUST already be filtered to one sessionId and sorted ascending by
 * timestampUtc before calling this. (The caller — the ingestion pipeline —
 * owns sorting/filtering so this function can stay a pure reducer.)
 */
export function reconcileSession(
  events: AnyRawEvent[],
  config: ReconcilerConfig = DEFAULT_RECONCILER_CONFIG,
): ReconciliationResult {
  const stateIntervals: StateInterval[] = [];
  const anomalies: ReconciliationAnomaly[] = [];

  if (events.length === 0) {
    return { stateIntervals, breakIntervals: [], anomalies };
  }

  const sessionId = events[0]!.sessionId ?? "";
  const employeeId = events[0]!.employeeId;
  const deviceId = events[0]!.deviceId;
  const thresholdMs = config.inactivityThresholdSeconds * 1000;

  // Suspected synthetic/mouse-jiggler input is detected up front, over the
  // FULL event list (so corroboration can see app/browser/keyboard events
  // anywhere in the run's window, not just ones already walked). Flagged
  // mouse events are still recorded in the audit trail below — they just
  // don't get to reset the inactivity clock or end a break on their own.
  const syntheticFlags: SyntheticInputFlag[] = detectSyntheticInputRuns(events, config.anomaly ?? DEFAULT_ANOMALY_CONFIG);
  const flaggedEventIds = new Set<string>();
  const flagByEventId = new Map<string, SyntheticInputFlag>();
  for (const flag of syntheticFlags) {
    for (const id of flag.eventIds) {
      flaggedEventIds.add(id);
      flagByEventId.set(id, flag);
    }
  }
  const surfacedFlagStarts = new Set<string>();

  const first = events[0]!;
  let cursor: MutableCursor = {
    state: "signed_out",
    stateStartMs: toMs(first.timestampUtc),
    // IMPORTANT: lastInputMs starts null. Signing in is proof of presence,
    // not proof of input — we must not start the inactivity clock from an
    // event that isn't itself meaningful input, or a routine gap between
    // "signed in" and "first keystroke" gets misclassified as a break.
    lastInputMs: null,
    causedByEventIds: [],
    startIsBackdated: false,
  };

  /**
   * Closes the current cursor's interval at `endMs` and pushes it to the
   * output list — UNLESS it has zero duration. A zero-length interval
   * (start === end) carries no information worth keeping as its own row
   * (it would make every downstream count — "how many break intervals,"
   * "how many signed_out periods" — wrong by however many instantaneous
   * transitions happened to land on the same timestamp, e.g. the
   * bootstrap cursor's initial "signed_out" at the exact moment the first
   * event signs the employee in). Its causedByEventIds are NOT lost,
   * though: the caller folds them into the next interval so the audit
   * trail stays complete even though the zero-length row itself doesn't
   * get a line in the timeline.
   */
  function pushClosedInterval(endMs: number): void {
    if (endMs === cursor.stateStartMs) {
      return; // zero-length: nothing to record as its own interval
    }
    let safeEnd = endMs;
    if (cursor.stateStartMs > safeEnd) {
      // Defensive: never emit a negative-duration interval.
      safeEnd = cursor.stateStartMs;
    }
    stateIntervals.push({
      employeeId,
      deviceId,
      sessionId,
      state: cursor.state,
      startUtc: toIso(cursor.stateStartMs),
      endUtc: toIso(safeEnd),
      causedByEventIds: [...cursor.causedByEventIds],
      isEstimated: cursor.startIsBackdated,
    });
  }

  /**
   * The only place the cursor's state changes. `startMs` may be earlier
   * than `atMs` (the backdating case) — callers pass the backdated time
   * explicitly rather than this function guessing.
   */
  function transitionTo(
    newState: EmployeeState,
    startMs: number,
    eventIds: string[],
    opts: { backdated?: boolean } = {},
  ): void {
    if (newState === cursor.state) {
      cursor.causedByEventIds.push(...eventIds);
      return;
    }
    if (!isTransitionAllowed(cursor.state, newState)) {
      anomalies.push({
        type: "invalid_transition",
        message: `Disallowed transition ${cursor.state} -> ${newState}`,
        atUtc: toIso(startMs),
        eventIds,
      });
      // Still apply it — a flagged-anomalous record beats silently
      // dropping time from the report.
    }
    const wasZeroLength = startMs === cursor.stateStartMs;
    const carriedForwardIds = wasZeroLength ? cursor.causedByEventIds : [];
    pushClosedInterval(startMs);
    cursor = {
      state: newState,
      stateStartMs: startMs,
      lastInputMs: cursor.lastInputMs,
      causedByEventIds: [...carriedForwardIds, ...eventIds],
      startIsBackdated: opts.backdated ?? false,
    };
  }

  /**
   * The core inactivity rule. If we are "active" and the gap since the
   * last meaningful input already exceeds the threshold as of `upToMs`,
   * transition into idle_break NOW, backdated to lastInputMs — this is
   * what makes "mark the start of the break at the last input time, not
   * five minutes later" literally true of the emitted interval.
   *
   * Called before processing every event, so a break is detected the
   * moment we observe ANY subsequent event (of any type) at/after the
   * threshold — we never wait for a same-type event to notice the gap.
   */
  function maybeInsertBackdatedBreak(upToMs: number): void {
    if (cursor.state !== "active" || cursor.lastInputMs === null) return;
    const gapMs = upToMs - cursor.lastInputMs;
    if (gapMs < thresholdMs) return;
    transitionTo("idle_break", cursor.lastInputMs, [], { backdated: true });
  }

  for (let i = 0; i < events.length; i++) {
    const ev = events[i]!;
    const evMs = toMs(ev.timestampUtc);
    const prevMs = i > 0 ? toMs(events[i - 1]!.timestampUtc) : evMs;
    if (evMs < prevMs) {
      anomalies.push({
        type: "out_of_order_event",
        message: `Event ${ev.eventId} timestamp precedes previous event`,
        atUtc: ev.timestampUtc,
        eventIds: [ev.eventId],
      });
      continue; // never let an out-of-order event corrupt the cursor
    }

    // Check whether a break should already have started before handling
    // whatever this event is (including non-input events like lock/sleep —
    // the gap is real regardless of what ends it).
    maybeInsertBackdatedBreak(evMs);

    switch (ev.type) {
      case "session.signin": {
        transitionTo("active", evMs, [ev.eventId]);
        break;
      }
      case "input.activity": {
        // Suspected synthetic mouse input (see anomaly-detection.ts) is
        // attributed to the audit trail but is deliberately NOT treated
        // as meaningful input: it does not refresh lastInputMs and does
        // not end a break. A mouse-jiggler cannot, on its own, keep the
        // inactivity clock from firing or pull a session back to active.
        if (flaggedEventIds.has(ev.eventId)) {
          cursor.causedByEventIds.push(ev.eventId);
          const flag = flagByEventId.get(ev.eventId);
          if (flag && !surfacedFlagStarts.has(flag.startUtc)) {
            surfacedFlagStarts.add(flag.startUtc);
            anomalies.push({
              type: "synthetic_input_suspected",
              message: flag.reason,
              atUtc: flag.startUtc,
              eventIds: flag.eventIds,
            });
          }
          break;
        }
        // Ending a break happens here: the first resumed interaction
        // closes idle_break (via transitionTo, at the REAL time of this
        // event — not backdated) and returns to active.
        if (cursor.state === "idle_break") {
          transitionTo("active", evMs, [ev.eventId]);
        } else if (cursor.state === "signed_out" || cursor.state === "locked" || cursor.state === "sleep") {
          // Input while signed out/locked/asleep shouldn't happen —
          // surface as an anomaly rather than silently flipping state.
          anomalies.push({
            type: "invalid_transition",
            message: `input.activity observed while state=${cursor.state}`,
            atUtc: ev.timestampUtc,
            eventIds: [ev.eventId],
          });
        } else {
          cursor.causedByEventIds.push(ev.eventId);
        }
        cursor.lastInputMs = evMs;
        break;
      }
      case "session.lock": {
        transitionTo("locked", evMs, [ev.eventId]);
        break;
      }
      case "session.unlock": {
        // Unlocking is not itself proof of input; land in "unverified"
        // and let the next input.activity (or its absence) decide.
        transitionTo("unverified", evMs, [ev.eventId]);
        break;
      }
      case "pc.sleep": {
        transitionTo("sleep", evMs, [ev.eventId]);
        break;
      }
      case "pc.wake": {
        transitionTo("unverified", evMs, [ev.eventId]);
        break;
      }
      case "session.signout":
      case "pc.shutdown": {
        transitionTo("signed_out", evMs, [ev.eventId]);
        break;
      }
      case "agent.heartbeat_missed": {
        transitionTo("monitoring_gap", evMs, [ev.eventId]);
        break;
      }
      default:
        // app.*/browser.*/agent.start etc. don't change employee state by
        // themselves — attribute them to whatever state is current.
        cursor.causedByEventIds.push(ev.eventId);
    }
  }

  // Close the final interval, open-ended ("still ongoing" — endUtc null),
  // rather than claiming a false precise end at the last event's time.
  stateIntervals.push({
    employeeId,
    deviceId,
    sessionId,
    state: cursor.state,
    startUtc: toIso(cursor.stateStartMs),
    endUtc: null,
    causedByEventIds: [...cursor.causedByEventIds],
    isEstimated: cursor.startIsBackdated,
  });

  const breakIntervals = deriveBreakIntervals(stateIntervals, sessionId, employeeId);

  return { stateIntervals, breakIntervals, anomalies };
}

/**
 * Pure projection: every idle_break StateInterval becomes exactly one
 * BreakInterval. This is the ONLY place BreakInterval records are
 * created — never during the event walk — so breaks can never drift out
 * of sync with the state timeline they came from.
 */
function deriveBreakIntervals(
  stateIntervals: StateInterval[],
  sessionId: string,
  employeeId: string,
): BreakInterval[] {
  return stateIntervals
    .filter((s) => s.state === "idle_break")
    .map((s) => ({
      breakId: `${sessionId}:${s.startUtc}`,
      sessionId,
      employeeId,
      startUtc: s.startUtc,
      endUtc: s.endUtc,
      detectedByEventId: s.causedByEventIds[0] ?? "",
      endedByEventId: s.endUtc ? s.causedByEventIds[s.causedByEventIds.length - 1] ?? null : null,
    }));
}
