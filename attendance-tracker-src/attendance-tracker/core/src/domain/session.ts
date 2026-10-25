/**
 * Attendance sessions, break intervals, and the transparency records that
 * make this a disclosed-monitoring system rather than covert surveillance.
 *
 * See docs/transparency.md for the policy this encodes. The short version:
 * an agent install is not considered valid, and its data is not considered
 * admissible by the reporting layer, unless a NoticeAcknowledgement exists
 * for that employee/device pair.
 */

export interface AttendanceSession {
  sessionId: string;
  employeeId: string;
  deviceId: string;
  /** UTC. Set from session.signin or pc.startup (whichever starts the session). */
  startUtc: string;
  /** Null while session is open. */
  endUtc: string | null;
  /** How the session ended, once it has. */
  endReason: "signout" | "shutdown" | "crash_recovered" | "unresolved" | null;
  isEndEstimated: boolean;
}

export interface BreakInterval {
  breakId: string;
  sessionId: string;
  employeeId: string;
  /** Backdated to the last meaningful input timestamp, per the 5-minute rule. */
  startUtc: string;
  /** Set when activity resumes; null while break is ongoing. */
  endUtc: string | null;
  /** The event that caused the break to be detected (the inactivity-threshold trip). */
  detectedByEventId: string;
  /** The event that ended it (first resumed input), once it has. */
  endedByEventId: string | null;
}

/**
 * Required before an agent is permitted to collect any activity data for an
 * employee/device pair. The agent and backend both enforce this: no
 * acknowledgement row -> the collection pipeline refuses to ingest events
 * for that pair, and the admin dashboard shows the device as
 * "awaiting employee notice" instead of a status.
 *
 * This is intentionally a hard gate, not a checkbox an admin can tick on
 * the employee's behalf: `acknowledgedAt` is only ever set by the notice
 * screen that actually displayed on that employee's own device.
 */
export interface NoticeAcknowledgement {
  employeeId: string;
  deviceId: string;
  /** The exact notice text version shown (policy text may change over time; keep history). */
  policyVersion: string;
  /** Null until the employee has actually dismissed the on-device notice screen. */
  acknowledgedAtUtc: string | null;
  /** First time the notice was shown, even if not yet dismissed. */
  firstShownAtUtc: string;
}

export type WorkClassification = "productive" | "neutral" | "unproductive" | "unclassified" | "excluded";

export interface ClassificationRule {
  ruleId: string;
  /** "app:<executableName>" or "domain:<domain>" */
  target: string;
  classification: WorkClassification;
  effectiveFromUtc: string;
  effectiveToUtc: string | null;
  createdByAdminId: string;
}
