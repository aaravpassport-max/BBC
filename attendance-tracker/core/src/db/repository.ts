/**
 * The persistence layer. Two responsibilities only:
 *
 *   1. Append raw events idempotently, behind the transparency gate.
 *   2. Recompute a session's derived tables (state_intervals,
 *      break_intervals) from its raw events — by DELETING the existing
 *      derived rows and reinserting fresh ones computed by the pure
 *      engine. This is what makes "reports are reproducible from raw
 *      events" an enforced property rather than a design aspiration:
 *      calling recomputeSession() twice in a row on unchanged raw events
 *      must leave the derived tables byte-for-byte identical, and a test
 *      proves exactly that.
 *
 * Nothing in this file contains time-calculation logic — that all lives
 * in engine/activity-reconciler.ts and is reached only through
 * reconcileSession(). This file is intentionally "dumb": load rows, call
 * the engine, store what it returns.
 */

import { DatabaseSync } from "node:sqlite";
import { runMigrations } from "./migrate.js";
import { reconcileSession, DEFAULT_RECONCILER_CONFIG, type ReconcilerConfig } from "../engine/activity-reconciler.js";
import { reconcileAppForeground, reconcileBrowserActivity } from "../engine/usage-reconciler.js";
import type { AnyRawEvent, RawEventType } from "../domain/events.js";
import type { StateInterval } from "../domain/state.js";
import type { BreakInterval, ClassificationRule } from "../domain/session.js";
import type { AppForegroundInterval, BrowserUrlInterval } from "../domain/usage.js";
import { summarizeUsage } from "../engine/classification.js";
import { buildDailyReport, buildMonthlyReport, type DailyReport, type DailySessionDetail, type MonthlyReport } from "../engine/reporting.js";

type Database = DatabaseSync;

/**
 * node:sqlite's DatabaseSync has no built-in `.transaction()` wrapper
 * (unlike better-sqlite3, which this file originally targeted). This is
 * the single place that gap is bridged: every multi-statement write in
 * this file goes through here so a failure partway through always rolls
 * back rather than leaving derived tables half-written.
 */
function withTransaction<T>(db: Database, fn: () => T): T {
  db.exec("BEGIN");
  try {
    const result = fn();
    db.exec("COMMIT");
    return result;
  } catch (err) {
    db.exec("ROLLBACK");
    throw err;
  }
}

export class TransparencyGateError extends Error {
  constructor(employeeId: string, deviceId: string) {
    super(
      `Refusing to ingest events for employee=${employeeId} device=${deviceId}: ` +
        `no acknowledged monitoring notice on file for this pair. ` +
        `Install the notice screen and have the employee acknowledge it before any activity collection begins.`,
    );
    this.name = "TransparencyGateError";
  }
}

export function openDatabase(filePath: string): Database {
  const db = new DatabaseSync(filePath);
  db.exec("PRAGMA journal_mode = WAL");
  db.exec("PRAGMA foreign_keys = ON");
  runMigrations(db);
  return db;
}

// ---------------------------------------------------------------------------
// Employees / devices / notice acknowledgement
// ---------------------------------------------------------------------------

export function upsertEmployee(
  db: Database,
  input: { employeeId: string; displayName: string; employeeCode?: string; department?: string; designation?: string; timeZone?: string },
): void {
  const now = new Date().toISOString();
  db.prepare(
    `INSERT INTO employees (employee_id, display_name, employee_code, department, designation, time_zone, created_at_utc, updated_at_utc)
     VALUES (@employeeId, @displayName, @employeeCode, @department, @designation, @timeZone, @now, @now)
     ON CONFLICT(employee_id) DO UPDATE SET
       display_name = excluded.display_name,
       employee_code = excluded.employee_code,
       department = excluded.department,
       designation = excluded.designation,
       time_zone = excluded.time_zone,
       updated_at_utc = excluded.updated_at_utc`,
  ).run({
    employeeId: input.employeeId,
    displayName: input.displayName,
    employeeCode: input.employeeCode ?? null,
    department: input.department ?? null,
    designation: input.designation ?? null,
    timeZone: input.timeZone ?? "Asia/Kolkata",
    now,
  });
}

export function upsertDevice(db: Database, input: { deviceId: string; hostname?: string; osVersion?: string; agentVersion?: string }): void {
  db.prepare(
    `INSERT INTO devices (device_id, hostname, os_version, agent_version, created_at_utc)
     VALUES (@deviceId, @hostname, @osVersion, @agentVersion, @now)
     ON CONFLICT(device_id) DO UPDATE SET
       hostname = excluded.hostname,
       os_version = excluded.os_version,
       agent_version = excluded.agent_version`,
  ).run({
    deviceId: input.deviceId,
    hostname: input.hostname ?? null,
    osVersion: input.osVersion ?? null,
    agentVersion: input.agentVersion ?? null,
    now: new Date().toISOString(),
  });
}

/** Records that the on-device notice screen was shown (not yet necessarily acknowledged). */
export function recordNoticeShown(db: Database, employeeId: string, deviceId: string, policyVersion: string): void {
  db.prepare(
    `INSERT INTO notice_acknowledgements (employee_id, device_id, policy_version, first_shown_at_utc, acknowledged_at_utc)
     VALUES (?, ?, ?, ?, NULL)
     ON CONFLICT(employee_id, device_id) DO NOTHING`,
  ).run(employeeId, deviceId, policyVersion, new Date().toISOString());
}

/** Records that the employee actually dismissed/acknowledged the notice. This — and only this — opens the transparency gate. */
export function recordNoticeAcknowledged(db: Database, employeeId: string, deviceId: string): void {
  const result = db
    .prepare(
      `UPDATE notice_acknowledgements SET acknowledged_at_utc = ?
       WHERE employee_id = ? AND device_id = ? AND acknowledged_at_utc IS NULL`,
    )
    .run(new Date().toISOString(), employeeId, deviceId);
  if (result.changes === 0) {
    throw new Error(
      `Cannot acknowledge notice for employee=${employeeId} device=${deviceId}: no pending (unacknowledged) notice row found. Call recordNoticeShown first.`,
    );
  }
}

export function hasAcknowledgedNotice(db: Database, employeeId: string, deviceId: string): boolean {
  const row = db
    .prepare(`SELECT acknowledged_at_utc FROM notice_acknowledgements WHERE employee_id = ? AND device_id = ?`)
    .get(employeeId, deviceId) as { acknowledged_at_utc: string | null } | undefined;
  return !!row?.acknowledged_at_utc;
}

// ---------------------------------------------------------------------------
// Attendance sessions
// ---------------------------------------------------------------------------

/**
 * Ensures an attendance_sessions row exists for this sessionId, creating
 * one (open, start_utc = this event's time) if it's the first event seen
 * for that session. Idempotent: a session already on file is left alone.
 * Called automatically by ingestRawEvent whenever an event carries a
 * sessionId, so callers never have to remember to open a session
 * separately before sending events for it.
 */
export function ensureAttendanceSession(db: Database, sessionId: string, employeeId: string, deviceId: string, startUtc: string): void {
  db.prepare(
    `INSERT INTO attendance_sessions (session_id, employee_id, device_id, start_utc, end_utc, end_reason, is_end_estimated)
     VALUES (?, ?, ?, ?, NULL, NULL, 0)
     ON CONFLICT(session_id) DO NOTHING`,
  ).run(sessionId, employeeId, deviceId, startUtc);
}

export function closeAttendanceSession(
  db: Database,
  sessionId: string,
  endUtc: string,
  endReason: "signout" | "shutdown" | "crash_recovered" | "unresolved",
  isEstimated: boolean,
): void {
  db.prepare(`UPDATE attendance_sessions SET end_utc = ?, end_reason = ?, is_end_estimated = ? WHERE session_id = ?`).run(
    endUtc,
    endReason,
    isEstimated ? 1 : 0,
    sessionId,
  );
}

// ---------------------------------------------------------------------------
// Raw event ingestion
// ---------------------------------------------------------------------------

export interface IngestResult {
  inserted: boolean; // false if this eventId already existed (idempotent no-op)
}

/**
 * Idempotently inserts one raw event. Throws TransparencyGateError if no
 * acknowledged notice exists for this employee/device pair — this is a
 * hard refusal, not a warning, by design (see domain/session.ts).
 */
export function ingestRawEvent(db: Database, event: AnyRawEvent): IngestResult {
  if (!hasAcknowledgedNotice(db, event.employeeId, event.deviceId)) {
    throw new TransparencyGateError(event.employeeId, event.deviceId);
  }

  if (event.sessionId) {
    // Opens the session on first sight. A FK from state_intervals /
    // break_intervals to attendance_sessions depends on this row
    // existing before recomputeSession ever runs for this sessionId.
    ensureAttendanceSession(db, event.sessionId, event.employeeId, event.deviceId, event.timestampUtc);
  }

  const result = db
    .prepare(
      `INSERT INTO raw_events (event_id, employee_id, device_id, session_id, event_type, timestamp_utc, source, is_estimated, payload_json, ingested_at_utc)
       VALUES (@eventId, @employeeId, @deviceId, @sessionId, @type, @timestampUtc, @source, @isEstimated, @payloadJson, @ingestedAt)
       ON CONFLICT(event_id) DO NOTHING`,
    )
    .run({
      eventId: event.eventId,
      employeeId: event.employeeId,
      deviceId: event.deviceId,
      sessionId: event.sessionId,
      type: event.type,
      timestampUtc: event.timestampUtc,
      source: event.source,
      isEstimated: event.isEstimated ? 1 : 0,
      payloadJson: JSON.stringify(event.payload ?? {}),
      ingestedAt: new Date().toISOString(),
    });

  return { inserted: result.changes > 0 };
}

/** Batch variant, all-or-nothing in one transaction. */
export function ingestRawEvents(db: Database, events: AnyRawEvent[]): IngestResult[] {
  return withTransaction(db, () => events.map((e) => ingestRawEvent(db, e)));
}

function rowToEvent(row: any): AnyRawEvent {
  return {
    eventId: row.event_id,
    employeeId: row.employee_id,
    deviceId: row.device_id,
    sessionId: row.session_id,
    type: row.event_type as RawEventType,
    timestampUtc: row.timestamp_utc,
    source: row.source,
    isEstimated: !!row.is_estimated,
    payload: JSON.parse(row.payload_json),
  };
}

export function getEventsForSession(db: Database, sessionId: string): AnyRawEvent[] {
  const rows = db
    .prepare(`SELECT * FROM raw_events WHERE session_id = ? ORDER BY timestamp_utc ASC, rowid ASC`)
    .all(sessionId);
  return rows.map(rowToEvent);
}

// ---------------------------------------------------------------------------
// Derived-table recomputation — the reproducibility guarantee
// ---------------------------------------------------------------------------

export interface RecomputeResult {
  stateIntervalCount: number;
  breakIntervalCount: number;
  anomalyCount: number;
}

/**
 * Recomputes state_intervals and break_intervals for one session from its
 * raw_events, replacing whatever was there before. Runs in a single
 * transaction: either the full replace succeeds, or nothing changes.
 */
export function recomputeSession(
  db: Database,
  sessionId: string,
  config: ReconcilerConfig = DEFAULT_RECONCILER_CONFIG,
): RecomputeResult {
  const events = getEventsForSession(db, sessionId);
  const result = reconcileSession(events, config);

  // Closing the session row is itself a fact derivable from raw_events —
  // the LATEST terminal event (session.signout or pc.shutdown) decides
  // it, same "recompute from source of truth" discipline as everything
  // else here. This was a real gap until it was caught by actually
  // running the API against seeded data and finding every session's
  // end_utc/end_reason stuck at null even after a session.signout event
  // had been ingested: closeAttendanceSession existed but nothing ever
  // called it. "Latest" matters because a session could (incorrectly,
  // but the code shouldn't assume it can't) have more than one terminal
  // event; the most recent one is the one that should win.
  let latestTerminal: { timestampUtc: string; type: string; isEstimated: boolean } | null = null;
  for (const e of events) {
    if (e.type !== "session.signout" && e.type !== "pc.shutdown") continue;
    if (!latestTerminal || Date.parse(e.timestampUtc) >= Date.parse(latestTerminal.timestampUtc)) {
      latestTerminal = { timestampUtc: e.timestampUtc, type: e.type, isEstimated: e.isEstimated };
    }
  }

  withTransaction(db, () => {
    db.prepare(`DELETE FROM state_intervals WHERE session_id = ?`).run(sessionId);
    db.prepare(`DELETE FROM break_intervals WHERE session_id = ?`).run(sessionId);

    if (latestTerminal) {
      closeAttendanceSession(
        db,
        sessionId,
        latestTerminal.timestampUtc,
        latestTerminal.type === "pc.shutdown" ? "shutdown" : "signout",
        latestTerminal.isEstimated,
      );
    }

    const insertState = db.prepare(
      `INSERT INTO state_intervals (session_id, employee_id, device_id, state, start_utc, end_utc, caused_by_event_ids_json, is_estimated)
       VALUES (@sessionId, @employeeId, @deviceId, @state, @startUtc, @endUtc, @causedByEventIdsJson, @isEstimated)`,
    );
    for (const s of result.stateIntervals) {
      insertState.run({
        sessionId: s.sessionId,
        employeeId: s.employeeId,
        deviceId: s.deviceId,
        state: s.state,
        startUtc: s.startUtc,
        endUtc: s.endUtc,
        causedByEventIdsJson: JSON.stringify(s.causedByEventIds),
        isEstimated: s.isEstimated ? 1 : 0,
      });
    }

    const insertBreak = db.prepare(
      `INSERT INTO break_intervals (break_id, session_id, employee_id, start_utc, end_utc, detected_by_event_id, ended_by_event_id)
       VALUES (@breakId, @sessionId, @employeeId, @startUtc, @endUtc, @detectedByEventId, @endedByEventId)`,
    );
    for (const b of result.breakIntervals) {
      insertBreak.run({
        breakId: b.breakId,
        sessionId: b.sessionId,
        employeeId: b.employeeId,
        startUtc: b.startUtc,
        endUtc: b.endUtc,
        detectedByEventId: b.detectedByEventId,
        endedByEventId: b.endedByEventId,
      });
    }
  });

  return {
    stateIntervalCount: result.stateIntervals.length,
    breakIntervalCount: result.breakIntervals.length,
    anomalyCount: result.anomalies.length,
  };
}

function rowToStateInterval(row: any): StateInterval {
  return {
    employeeId: row.employee_id,
    deviceId: row.device_id,
    sessionId: row.session_id,
    state: row.state,
    startUtc: row.start_utc,
    endUtc: row.end_utc,
    causedByEventIds: JSON.parse(row.caused_by_event_ids_json),
    isEstimated: !!row.is_estimated,
  };
}

function rowToBreakInterval(row: any): BreakInterval {
  return {
    breakId: row.break_id,
    sessionId: row.session_id,
    employeeId: row.employee_id,
    startUtc: row.start_utc,
    endUtc: row.end_utc,
    detectedByEventId: row.detected_by_event_id,
    endedByEventId: row.ended_by_event_id,
  };
}

export function getStateIntervals(db: Database, sessionId: string): StateInterval[] {
  return db
    .prepare(`SELECT * FROM state_intervals WHERE session_id = ? ORDER BY start_utc ASC, id ASC`)
    .all(sessionId)
    .map(rowToStateInterval);
}

export function getBreakIntervals(db: Database, sessionId: string): BreakInterval[] {
  return db
    .prepare(`SELECT * FROM break_intervals WHERE session_id = ? ORDER BY start_utc ASC`)
    .all(sessionId)
    .map(rowToBreakInterval);
}

// ---------------------------------------------------------------------------
// Stage 2: application foreground / browser URL recomputation
// ---------------------------------------------------------------------------

export interface RecomputeUsageResult {
  appIntervalCount: number;
  browserIntervalCount: number;
  appAnomalyCount: number;
  browserAnomalyCount: number;
}

/**
 * Recomputes app_foreground_intervals and browser_url_intervals for one
 * session from its raw_events, replacing whatever was there before — same
 * delete-then-reinsert-in-one-transaction discipline as recomputeSession.
 * Deliberately a separate function (not folded into recomputeSession):
 * Stage 1 attendance state and Stage 2 usage are independent projections
 * of the same raw_events, computed by independent pure functions, and
 * nothing requires them to be recomputed together.
 */
export function recomputeUsage(db: Database, sessionId: string): RecomputeUsageResult {
  const events = getEventsForSession(db, sessionId);
  const appResult = reconcileAppForeground(events);
  const browserResult = reconcileBrowserActivity(events);

  withTransaction(db, () => {
    db.prepare(`DELETE FROM app_foreground_intervals WHERE session_id = ?`).run(sessionId);
    db.prepare(`DELETE FROM browser_url_intervals WHERE session_id = ?`).run(sessionId);

    const insertApp = db.prepare(
      `INSERT INTO app_foreground_intervals (session_id, employee_id, device_id, executable_name, window_title, start_utc, end_utc, caused_by_event_ids_json)
       VALUES (@sessionId, @employeeId, @deviceId, @executableName, @windowTitle, @startUtc, @endUtc, @causedByEventIdsJson)`,
    );
    for (const a of appResult.intervals) {
      insertApp.run({
        sessionId: a.sessionId,
        employeeId: a.employeeId,
        deviceId: a.deviceId,
        executableName: a.executableName,
        windowTitle: a.windowTitle,
        startUtc: a.startUtc,
        endUtc: a.endUtc,
        causedByEventIdsJson: JSON.stringify(a.causedByEventIds),
      });
    }

    const insertBrowser = db.prepare(
      `INSERT INTO browser_url_intervals (session_id, employee_id, device_id, browser_name, tab_id, url, domain, page_title, start_utc, end_utc, caused_by_event_ids_json)
       VALUES (@sessionId, @employeeId, @deviceId, @browserName, @tabId, @url, @domain, @pageTitle, @startUtc, @endUtc, @causedByEventIdsJson)`,
    );
    for (const b of browserResult.intervals) {
      insertBrowser.run({
        sessionId: b.sessionId,
        employeeId: b.employeeId,
        deviceId: b.deviceId,
        browserName: b.browserName,
        tabId: b.tabId,
        url: b.url,
        domain: b.domain,
        pageTitle: b.pageTitle,
        startUtc: b.startUtc,
        endUtc: b.endUtc,
        causedByEventIdsJson: JSON.stringify(b.causedByEventIds),
      });
    }
  });

  return {
    appIntervalCount: appResult.intervals.length,
    browserIntervalCount: browserResult.intervals.length,
    appAnomalyCount: appResult.anomalies.length,
    browserAnomalyCount: browserResult.anomalies.length,
  };
}

function rowToAppForegroundInterval(row: any): AppForegroundInterval {
  return {
    employeeId: row.employee_id,
    deviceId: row.device_id,
    sessionId: row.session_id,
    executableName: row.executable_name,
    windowTitle: row.window_title,
    startUtc: row.start_utc,
    endUtc: row.end_utc,
    causedByEventIds: JSON.parse(row.caused_by_event_ids_json),
  };
}

function rowToBrowserUrlInterval(row: any): BrowserUrlInterval {
  return {
    employeeId: row.employee_id,
    deviceId: row.device_id,
    sessionId: row.session_id,
    browserName: row.browser_name,
    tabId: row.tab_id,
    url: row.url,
    domain: row.domain,
    pageTitle: row.page_title,
    startUtc: row.start_utc,
    endUtc: row.end_utc,
    causedByEventIds: JSON.parse(row.caused_by_event_ids_json),
  };
}

export function getAppForegroundIntervals(db: Database, sessionId: string): AppForegroundInterval[] {
  return db
    .prepare(`SELECT * FROM app_foreground_intervals WHERE session_id = ? ORDER BY start_utc ASC, id ASC`)
    .all(sessionId)
    .map(rowToAppForegroundInterval);
}

export function getBrowserUrlIntervals(db: Database, sessionId: string): BrowserUrlInterval[] {
  return db
    .prepare(`SELECT * FROM browser_url_intervals WHERE session_id = ? ORDER BY start_utc ASC, id ASC`)
    .all(sessionId)
    .map(rowToBrowserUrlInterval);
}

// ---------------------------------------------------------------------------
// Stage 2b: classification rules — admin-authored, not derived
// ---------------------------------------------------------------------------

/**
 * Adds a new classification rule. Does NOT overwrite or delete any
 * existing rule for the same target — classification.ts's
 * resolveClassification() picks the most-recently-effective matching rule
 * at query time, so "reclassifying" an app/domain is just adding a new
 * rule with a later effectiveFromUtc, preserving the full history of what
 * a target was classified as and when. That history matters for a report
 * covering a date range that spans a reclassification.
 */
export function addClassificationRule(
  db: Database,
  input: { ruleId: string; target: string; classification: ClassificationRule["classification"]; effectiveFromUtc: string; createdByAdminId: string },
): void {
  db.prepare(
    `INSERT INTO classification_rules (rule_id, target, classification, effective_from_utc, effective_to_utc, created_by_admin_id, created_at_utc)
     VALUES (@ruleId, @target, @classification, @effectiveFromUtc, NULL, @createdByAdminId, @createdAt)`,
  ).run({
    ruleId: input.ruleId,
    target: input.target,
    classification: input.classification,
    effectiveFromUtc: input.effectiveFromUtc,
    createdByAdminId: input.createdByAdminId,
    createdAt: new Date().toISOString(),
  });
}

/** Ends a rule's effectiveness (sets effective_to_utc) without deleting the row — preserves history for past reports. */
export function endClassificationRule(db: Database, ruleId: string, effectiveToUtc: string): void {
  const result = db.prepare(`UPDATE classification_rules SET effective_to_utc = ? WHERE rule_id = ? AND effective_to_utc IS NULL`).run(effectiveToUtc, ruleId);
  if (result.changes === 0) {
    throw new Error(`Cannot end classification rule ${ruleId}: no currently-active rule with that id found.`);
  }
}

function rowToClassificationRule(row: any): ClassificationRule {
  return {
    ruleId: row.rule_id,
    target: row.target,
    classification: row.classification,
    effectiveFromUtc: row.effective_from_utc,
    effectiveToUtc: row.effective_to_utc,
    createdByAdminId: row.created_by_admin_id,
  };
}

/** All rules, active and expired — classification.ts's resolveClassification() does the time-window filtering itself, given a specific atUtc. */
export function getAllClassificationRules(db: Database): ClassificationRule[] {
  return db.prepare(`SELECT * FROM classification_rules ORDER BY effective_from_utc ASC`).all().map(rowToClassificationRule);
}

// ---------------------------------------------------------------------------
// Read queries for the admin API (api/server.ts) — plain SELECTs, no
// engine logic. Kept here rather than in the API layer so the API stays
// "dumb" too: load rows, serialize, nothing else.
// ---------------------------------------------------------------------------

export interface EmployeeRow {
  employeeId: string;
  displayName: string;
  employeeCode: string | null;
  department: string | null;
  designation: string | null;
  monitoringStatus: "active" | "paused" | "offboarded";
}

export function getAllEmployees(db: Database): EmployeeRow[] {
  return db
    .prepare(`SELECT employee_id, display_name, employee_code, department, designation, monitoring_status FROM employees ORDER BY display_name ASC`)
    .all()
    .map((row: any) => ({
      employeeId: row.employee_id,
      displayName: row.display_name,
      employeeCode: row.employee_code,
      department: row.department,
      designation: row.designation,
      monitoringStatus: row.monitoring_status,
    }));
}

export interface SessionRow {
  sessionId: string;
  employeeId: string;
  deviceId: string;
  startUtc: string;
  endUtc: string | null;
  endReason: "signout" | "shutdown" | "crash_recovered" | "unresolved" | null;
  isEndEstimated: boolean;
}

function rowToSessionRow(row: any): SessionRow {
  return {
    sessionId: row.session_id,
    employeeId: row.employee_id,
    deviceId: row.device_id,
    startUtc: row.start_utc,
    endUtc: row.end_utc,
    endReason: row.end_reason,
    isEndEstimated: !!row.is_end_estimated,
  };
}

/**
 * Sessions for one employee whose start_utc falls on the given calendar
 * day, in UTC (deliberately simple for this stage — a session that starts
 * 23:50 UTC and runs past midnight is attributed to the day it started,
 * not split across two days; the original spec's per-employee time zone
 * handling is a named gap, see README).
 */
export function getSessionsForEmployeeOnDate(db: Database, employeeId: string, dateUtc: string): SessionRow[] {
  const dayStart = `${dateUtc}T00:00:00.000Z`;
  const dayEnd = `${dateUtc}T23:59:59.999Z`;
  return db
    .prepare(`SELECT * FROM attendance_sessions WHERE employee_id = ? AND start_utc >= ? AND start_utc <= ? ORDER BY start_utc ASC`)
    .all(employeeId, dayStart, dayEnd)
    .map(rowToSessionRow);
}

export function getSessionById(db: Database, sessionId: string): SessionRow | null {
  const row = db.prepare(`SELECT * FROM attendance_sessions WHERE session_id = ?`).get(sessionId);
  return row ? rowToSessionRow(row) : null;
}

// ===========================================================================
// Stage 3: retention settings, daily/monthly reports, and date-range
// history queries. Thin wrappers only — all aggregation logic lives in
// engine/reporting.ts (pure, unit-tested without a DB); this section's job
// is fetching the rows those functions need and handing the result back.
// ===========================================================================

export function getRetentionDays(db: Database): number {
  const row = db.prepare(`SELECT retention_days FROM retention_settings WHERE id = 1`).get() as { retention_days: number } | undefined;
  return row ? row.retention_days : 365;
}

/**
 * Refuses anything below 365 days — the one-year floor is enforced here,
 * not left as a convention a caller could silently violate. An admin can
 * raise it, never lower it past the floor.
 */
export function setRetentionDays(db: Database, days: number, adminId: string): void {
  if (!Number.isInteger(days) || days < 365) {
    throw new Error(`Retention period must be at least 365 days (one year); refusing to set ${days}.`);
  }
  db.prepare(`
    INSERT INTO retention_settings (id, retention_days, updated_at_utc, updated_by_admin_id)
    VALUES (1, ?, ?, ?)
    ON CONFLICT(id) DO UPDATE SET
      retention_days = excluded.retention_days,
      updated_at_utc = excluded.updated_at_utc,
      updated_by_admin_id = excluded.updated_by_admin_id
  `).run(days, new Date().toISOString(), adminId);
}

/**
 * The structural half of the retention guarantee. Nothing in this
 * codebase currently deletes rows from raw_events or any derived table —
 * there is no prune job today — but a future one MUST call this before
 * deleting anything older than `beforeDateUtc`, so the one-year floor is
 * enforced by construction rather than by code-review discipline on
 * whoever writes that job later.
 */
export function assertPruneAllowed(db: Database, beforeDateUtc: string): void {
  const retentionDays = getRetentionDays(db);
  const earliestAllowedUtc = new Date(Date.now() - retentionDays * 24 * 60 * 60 * 1000).toISOString();
  if (beforeDateUtc > earliestAllowedUtc) {
    throw new Error(
      `Refusing to prune data on or after ${earliestAllowedUtc} — that is within the configured ${retentionDays}-day retention window.`,
    );
  }
}

/** One employee's sessions for one calendar day, with full state/break/usage detail loaded — the input buildDailyReport() needs. */
export function getDailySessionDetails(db: Database, employeeId: string, date: string, asOfUtc: string): DailySessionDetail[] {
  const sessions = getSessionsForEmployeeOnDate(db, employeeId, date);
  const rules = getAllClassificationRules(db);
  return sessions.map((s) => {
    const stateIntervals = getStateIntervals(db, s.sessionId);
    const breakIntervals = getBreakIntervals(db, s.sessionId);
    const appIntervals = getAppForegroundIntervals(db, s.sessionId);
    const browserIntervals = getBrowserUrlIntervals(db, s.sessionId);
    const sessionAsOfUtc = s.endUtc ?? asOfUtc;
    const usage = summarizeUsage(appIntervals, browserIntervals, rules, sessionAsOfUtc);
    return {
      sessionId: s.sessionId,
      startUtc: s.startUtc,
      endUtc: s.endUtc,
      endReason: s.endReason,
      isEndEstimated: s.isEndEstimated,
      stateIntervals,
      breakIntervals,
      usage,
    };
  });
}

export function getDailyReport(db: Database, employeeId: string, date: string, nowUtc: string = new Date().toISOString()): DailyReport {
  const todayDate = nowUtc.slice(0, 10);
  const isToday = date === todayDate;
  const asOfUtc = isToday ? nowUtc : `${date}T23:59:59.999Z`;
  const sessions = getDailySessionDetails(db, employeeId, date, asOfUtc);
  return buildDailyReport(date, employeeId, sessions, asOfUtc, isToday);
}

function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/**
 * Builds one DailyReport per calendar day of the month (capped at "today"
 * for the current month, empty for a future month — never a fabricated
 * row for a day that hasn't happened yet) and aggregates them.
 */
export function getMonthlyReport(db: Database, employeeId: string, year: number, month: number, nowUtc: string = new Date().toISOString()): MonthlyReport {
  const today = new Date(nowUtc);
  const todayYear = today.getUTCFullYear();
  const todayMonth = today.getUTCMonth() + 1;
  const todayDay = today.getUTCDate();

  let lastDay = daysInMonth(year, month);
  if (year > todayYear || (year === todayYear && month > todayMonth)) {
    lastDay = 0;
  } else if (year === todayYear && month === todayMonth) {
    lastDay = todayDay;
  }

  const days: DailyReport[] = [];
  for (let d = 1; d <= lastDay; d++) {
    const date = `${year}-${String(month).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
    days.push(getDailyReport(db, employeeId, date, nowUtc));
  }
  return buildMonthlyReport(employeeId, year, month, days);
}

export interface HistoryFilters {
  employeeId?: string;
  department?: string;
  deviceId?: string;
  dateFrom: string; // YYYY-MM-DD
  dateTo: string; // YYYY-MM-DD
  status?: "present" | "absent" | "incomplete";
}

export interface HistoryRow {
  date: string;
  employeeId: string;
  employeeName: string;
  department: string | null;
  deviceId: string | null;
  sessionCount: number;
  firstLoginUtc: string | null;
  lastActivityUtc: string | null;
  hasOpenSession: boolean;
  status: "present" | "absent" | "incomplete";
}

/**
 * Date-range / employee / department / device / status search over
 * attendance history. The present+incomplete rows come from one indexed
 * GROUP BY query (idx_attendance_sessions_employee covers the employee_id
 * + start_utc range scan) — proportionate to the number of session-days
 * in range, not to how much history the database holds overall, so a
 * year of data doesn't make a one-month query slower.
 *
 * Absent-day rows are synthesized only when a single employeeId is given:
 * filling them in for every employee across a wide date range would be an
 * O(employees x days) scan that a flat filter table rarely needs — the
 * per-employee case that always needs every day accounted for is
 * getMonthlyReport, above.
 */
export function queryAttendanceHistory(db: Database, filters: HistoryFilters): HistoryRow[] {
  const dayStart = `${filters.dateFrom}T00:00:00.000Z`;
  const dayEnd = `${filters.dateTo}T23:59:59.999Z`;
  const today = new Date().toISOString().slice(0, 10);

  const conditions = [`s.start_utc >= ?`, `s.start_utc <= ?`];
  const params: string[] = [dayStart, dayEnd];
  if (filters.employeeId) {
    conditions.push(`s.employee_id = ?`);
    params.push(filters.employeeId);
  }
  if (filters.department) {
    conditions.push(`e.department = ?`);
    params.push(filters.department);
  }
  if (filters.deviceId) {
    conditions.push(`s.device_id = ?`);
    params.push(filters.deviceId);
  }

  const rows = db
    .prepare(
      `SELECT
         substr(s.start_utc, 1, 10) AS date,
         s.employee_id AS employee_id,
         e.display_name AS employee_name,
         e.department AS department,
         s.device_id AS device_id,
         COUNT(*) AS session_count,
         MIN(s.start_utc) AS first_login_utc,
         MAX(COALESCE(s.end_utc, s.start_utc)) AS last_activity_utc,
         SUM(CASE WHEN s.end_utc IS NULL THEN 1 ELSE 0 END) AS open_sessions
       FROM attendance_sessions s
       JOIN employees e ON e.employee_id = s.employee_id
       WHERE ${conditions.join(" AND ")}
       GROUP BY date, s.employee_id, s.device_id
       ORDER BY date ASC, e.display_name ASC`,
    )
    .all(...params) as any[];

  const present: HistoryRow[] = rows.map((r) => ({
    date: r.date,
    employeeId: r.employee_id,
    employeeName: r.employee_name,
    department: r.department,
    deviceId: r.device_id,
    sessionCount: r.session_count,
    firstLoginUtc: r.first_login_utc,
    lastActivityUtc: r.last_activity_utc,
    hasOpenSession: r.open_sessions > 0,
    status: r.open_sessions > 0 && r.date < today ? "incomplete" : "present",
  }));

  let result: HistoryRow[] = present;

  if (filters.employeeId) {
    const employee = getAllEmployees(db).find((e) => e.employeeId === filters.employeeId);
    const presentDates = new Set(present.map((r) => r.date));
    const seriesRows = db
      .prepare(
        `WITH RECURSIVE dates(d) AS (
           SELECT ?
           UNION ALL
           SELECT date(d, '+1 day') FROM dates WHERE d < ?
         )
         SELECT d FROM dates`,
      )
      .all(filters.dateFrom, filters.dateTo) as { d: string }[];

    const absentRows: HistoryRow[] = seriesRows
      .map((r) => r.d)
      .filter((d) => !presentDates.has(d))
      .map((d) => ({
        date: d,
        employeeId: filters.employeeId!,
        employeeName: employee?.displayName ?? filters.employeeId!,
        department: employee?.department ?? null,
        deviceId: filters.deviceId ?? null,
        sessionCount: 0,
        firstLoginUtc: null,
        lastActivityUtc: null,
        hasOpenSession: false,
        status: "absent" as const,
      }));

    result = [...present, ...absentRows].sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
  }

  if (filters.status) {
    result = result.filter((r) => r.status === filters.status);
  }

  return result;
}
