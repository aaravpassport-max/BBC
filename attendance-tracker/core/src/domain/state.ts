/**
 * The employee activity state machine.
 *
 * This is the single source of truth for "what was the employee doing" at
 * any instant. Every other part of the system (reports, dashboards,
 * exports) must derive from transitions through these states — never from
 * ad-hoc logic scattered elsewhere.
 *
 * IMPORTANT ASYMMETRY (this is the core design point of the whole system):
 * the employee state machine and the "is monitoring visible to the
 * employee" concern are orthogonal. This file only models what the
 * employee was doing. The visible-indicator / notice requirement lives in
 * the agent and dashboard layers and is NOT optional there — see
 * docs/transparency.md. Nothing in this engine depends on whether
 * disclosure happened; disclosure is enforced structurally elsewhere
 * (the agent cannot run without having shown the notice at least once per
 * install, tracked in agent_install_acknowledgement).
 */

export type EmployeeState =
  | "signed_out" // no authenticated employee session on this device
  | "active" // recent meaningful input or verified activity
  | "idle_break" // no meaningful input for >= inactivity threshold
  | "locked" // workstation locked
  | "sleep" // device suspended/hibernating
  | "monitoring_gap" // agent could not reliably observe activity
  | "unverified"; // time that cannot be confidently categorized

/** Edges allowed in the state machine. Used to validate transitions and catch bugs. */
export const ALLOWED_TRANSITIONS: Record<EmployeeState, EmployeeState[]> = {
  signed_out: ["active", "unverified"],
  active: ["idle_break", "locked", "sleep", "signed_out", "monitoring_gap"],
  idle_break: ["active", "locked", "sleep", "signed_out", "monitoring_gap"],
  locked: ["active", "idle_break", "sleep", "signed_out", "monitoring_gap"],
  sleep: ["active", "idle_break", "locked", "signed_out", "monitoring_gap"],
  monitoring_gap: ["active", "idle_break", "locked", "sleep", "signed_out", "unverified"],
  unverified: ["active", "idle_break", "locked", "sleep", "signed_out", "monitoring_gap"],
};

export function isTransitionAllowed(from: EmployeeState, to: EmployeeState): boolean {
  return ALLOWED_TRANSITIONS[from]?.includes(to) ?? false;
}

/**
 * A single state interval: the employee was in `state` from `startUtc` to
 * `endUtc` (null endUtc means "still ongoing"). These are DERIVED from raw
 * events by the engine, never hand-authored except via an audited admin
 * correction (see domain/correction.ts).
 */
export interface StateInterval {
  employeeId: string;
  deviceId: string;
  sessionId: string;
  state: EmployeeState;
  startUtc: string;
  endUtc: string | null;
  /** The raw event ID(s) that caused this interval's start, for auditability. */
  causedByEventIds: string[];
  isEstimated: boolean;
}
