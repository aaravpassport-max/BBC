/**
 * Daily and monthly reporting — pure aggregation over data the repository
 * layer has already fetched. Same discipline as every other engine file
 * in this codebase: these functions take plain data in and return plain
 * data out, with no database access of their own, so they're fully
 * unit-testable without a DB and the repository layer stays a thin
 * fetch-and-assemble wrapper around them (see repository.ts's
 * getDailyReport / getMonthlyReport).
 *
 * Three things this file is deliberately careful about, because the
 * request that led to it named them explicitly:
 *   1. A day with zero sessions is "absent", never a zeroed-out "present"
 *      row — never fabricate attendance where none was recorded.
 *   2. totalSessionDurationMs and activeWorkingMs are DIFFERENT numbers:
 *      the first is session span (sign-in to sign-out), the second is
 *      only the "active" state_intervals within it. A session is not the
 *      same claim as "the employee was active the whole time".
 *   3. Usage time (app/browser) is summed once via classification.ts's
 *      own summarizeUsage, which already keeps app and browser time in
 *      separate breakdowns specifically to avoid double-counting — this
 *      file never re-sums usage intervals itself, only merges the
 *      UsageSummary objects summarizeUsage already produced per session.
 */

import type { StateInterval } from "../domain/state.js";
import type { BreakInterval, AttendanceSession } from "../domain/session.js";
import type { UsageSummary } from "./classification.js";
import type { WorkClassification } from "../domain/session.js";

export interface DailySessionDetail {
  sessionId: string;
  startUtc: string;
  endUtc: string | null;
  endReason: AttendanceSession["endReason"];
  isEndEstimated: boolean;
  stateIntervals: StateInterval[];
  breakIntervals: BreakInterval[];
  usage: UsageSummary;
}

export type DailyReportStatus = "absent" | "present" | "incomplete" | "unverified_only";

export interface DailyReport {
  date: string; // YYYY-MM-DD, UTC calendar day
  employeeId: string;
  status: DailyReportStatus;
  firstLoginUtc: string | null;
  lastLogoutUtc: string | null;
  /** True when the most recent session that day has no end_utc and the day is not "today". */
  isLogoutMissing: boolean;
  sessionCount: number;
  totalSessionDurationMs: number;
  activeMs: number;
  breakMs: number;
  breakCount: number;
  lockedMs: number;
  sleepMs: number;
  monitoringGapMs: number;
  unverifiedMs: number;
  usage: UsageSummary;
  sessions: DailySessionDetail[];
}

const EMPTY_BY_CLASSIFICATION: Record<WorkClassification, number> = {
  productive: 0,
  neutral: 0,
  unproductive: 0,
  unclassified: 0,
  excluded: 0,
};

function emptyUsageSummary(): UsageSummary {
  return { totalMs: 0, byClassification: { ...EMPTY_BY_CLASSIFICATION }, byExecutable: [], byDomain: [] };
}

function toMs(utc: string): number {
  return new Date(utc).getTime();
}

function sumStateMs(intervals: StateInterval[], state: StateInterval["state"], asOfMs: number): number {
  let total = 0;
  for (const iv of intervals) {
    if (iv.state !== state) continue;
    const endMs = iv.endUtc !== null ? toMs(iv.endUtc) : asOfMs;
    total += Math.max(0, endMs - toMs(iv.startUtc));
  }
  return total;
}

function mergeUsageSummaries(summaries: UsageSummary[]): UsageSummary {
  const byClassification: Record<WorkClassification, number> = { ...EMPTY_BY_CLASSIFICATION };
  const execMap = new Map<string, { ms: number; classification: WorkClassification }>();
  const domainMap = new Map<string, { ms: number; classification: WorkClassification }>();
  let totalMs = 0;
  for (const s of summaries) {
    totalMs += s.totalMs;
    for (const k of Object.keys(byClassification) as WorkClassification[]) {
      byClassification[k] += s.byClassification[k] ?? 0;
    }
    for (const e of s.byExecutable) {
      const existing = execMap.get(e.executableName);
      execMap.set(e.executableName, { ms: (existing?.ms ?? 0) + e.ms, classification: e.classification });
    }
    for (const d of s.byDomain) {
      const existing = domainMap.get(d.domain);
      domainMap.set(d.domain, { ms: (existing?.ms ?? 0) + d.ms, classification: d.classification });
    }
  }
  return {
    totalMs,
    byClassification,
    byExecutable: [...execMap.entries()].map(([executableName, v]) => ({ executableName, ms: v.ms, classification: v.classification })),
    byDomain: [...domainMap.entries()].map(([domain, v]) => ({ domain, ms: v.ms, classification: v.classification })),
  };
}

/**
 * Assembles one calendar day's report for one employee from already-loaded
 * session details. `isToday` controls whether a trailing open session (no
 * endUtc) counts as "missing logout" (a past day that never got a
 * sign-out event) versus simply "still ongoing" (today).
 */
export function buildDailyReport(date: string, employeeId: string, sessions: DailySessionDetail[], asOfUtc: string, isToday: boolean): DailyReport {
  const asOfMs = toMs(asOfUtc);

  if (sessions.length === 0) {
    return {
      date,
      employeeId,
      status: "absent",
      firstLoginUtc: null,
      lastLogoutUtc: null,
      isLogoutMissing: false,
      sessionCount: 0,
      totalSessionDurationMs: 0,
      activeMs: 0,
      breakMs: 0,
      breakCount: 0,
      lockedMs: 0,
      sleepMs: 0,
      monitoringGapMs: 0,
      unverifiedMs: 0,
      usage: emptyUsageSummary(),
      sessions: [],
    };
  }

  const sorted = [...sessions].sort((a, b) => toMs(a.startUtc) - toMs(b.startUtc));
  const firstLoginUtc = sorted[0]!.startUtc;
  const lastSession = sorted[sorted.length - 1]!;
  const lastLogoutUtc = lastSession.endUtc;
  const isLogoutMissing = lastLogoutUtc === null && !isToday;

  let totalSessionDurationMs = 0;
  let activeMs = 0;
  let breakMs = 0;
  let breakCount = 0;
  let lockedMs = 0;
  let sleepMs = 0;
  let monitoringGapMs = 0;
  let unverifiedMs = 0;
  const usageSummaries: UsageSummary[] = [];

  for (const s of sorted) {
    const sessionEndMs = s.endUtc !== null ? toMs(s.endUtc) : asOfMs;
    totalSessionDurationMs += Math.max(0, sessionEndMs - toMs(s.startUtc));
    activeMs += sumStateMs(s.stateIntervals, "active", asOfMs);
    lockedMs += sumStateMs(s.stateIntervals, "locked", asOfMs);
    sleepMs += sumStateMs(s.stateIntervals, "sleep", asOfMs);
    monitoringGapMs += sumStateMs(s.stateIntervals, "monitoring_gap", asOfMs);
    unverifiedMs += sumStateMs(s.stateIntervals, "unverified", asOfMs);
    breakMs += sumStateMs(s.stateIntervals, "idle_break", asOfMs);
    breakCount += s.breakIntervals.length;
    usageSummaries.push(s.usage);
  }

  let status: DailyReportStatus = "present";
  if (isLogoutMissing || monitoringGapMs > 0) status = "incomplete";
  if (activeMs === 0 && breakMs === 0 && lockedMs === 0 && sleepMs === 0 && unverifiedMs > 0) status = "unverified_only";

  return {
    date,
    employeeId,
    status,
    firstLoginUtc,
    lastLogoutUtc,
    isLogoutMissing,
    sessionCount: sorted.length,
    totalSessionDurationMs,
    activeMs,
    breakMs,
    breakCount,
    lockedMs,
    sleepMs,
    monitoringGapMs,
    unverifiedMs,
    usage: mergeUsageSummaries(usageSummaries),
    sessions: sorted,
  };
}

export interface MonthlyReportTotals {
  daysInMonth: number;
  daysWithRecords: number;
  daysAbsent: number;
  daysIncomplete: number;
  totalSessionDurationMs: number;
  activeMs: number;
  breakMs: number;
  breakCount: number;
  monitoringGapMs: number;
  unverifiedMs: number;
  usage: UsageSummary;
}

export interface MonthlyReportAverages {
  /** Denominator is daysWithRecords, never daysInMonth — an absent day must not pull the average down as if it were a short day. */
  avgActiveMsPerPresentDay: number;
  avgBreakMsPerPresentDay: number;
  avgSessionDurationMsPerPresentDay: number;
  avgBreakCountPerPresentDay: number;
}

export interface MonthlyReport {
  employeeId: string;
  year: number;
  month: number; // 1-12
  days: DailyReport[];
  totals: MonthlyReportTotals;
  averages: MonthlyReportAverages;
}

/**
 * `days` must be one DailyReport per calendar day being reported (the
 * caller decides how many days of the month to include — e.g. up to
 * "today" for the current month, all days for a past month).
 */
export function buildMonthlyReport(employeeId: string, year: number, month: number, days: DailyReport[]): MonthlyReport {
  let daysWithRecords = 0;
  let daysIncomplete = 0;
  let totalSessionDurationMs = 0;
  let activeMs = 0;
  let breakMs = 0;
  let breakCount = 0;
  let monitoringGapMs = 0;
  let unverifiedMs = 0;
  const usageSummaries: UsageSummary[] = [];

  for (const d of days) {
    if (d.status !== "absent") daysWithRecords += 1;
    if (d.status === "incomplete") daysIncomplete += 1;
    totalSessionDurationMs += d.totalSessionDurationMs;
    activeMs += d.activeMs;
    breakMs += d.breakMs;
    breakCount += d.breakCount;
    monitoringGapMs += d.monitoringGapMs;
    unverifiedMs += d.unverifiedMs;
    usageSummaries.push(d.usage);
  }

  const daysAbsent = days.length - daysWithRecords;
  const denom = Math.max(1, daysWithRecords);

  return {
    employeeId,
    year,
    month,
    days,
    totals: {
      daysInMonth: days.length,
      daysWithRecords,
      daysAbsent,
      daysIncomplete,
      totalSessionDurationMs,
      activeMs,
      breakMs,
      breakCount,
      monitoringGapMs,
      unverifiedMs,
      usage: mergeUsageSummaries(usageSummaries),
    },
    averages: {
      avgActiveMsPerPresentDay: daysWithRecords ? activeMs / denom : 0,
      avgBreakMsPerPresentDay: daysWithRecords ? breakMs / denom : 0,
      avgSessionDurationMsPerPresentDay: daysWithRecords ? totalSessionDurationMs / denom : 0,
      avgBreakCountPerPresentDay: daysWithRecords ? breakCount / denom : 0,
    },
  };
}

// ---------------------------------------------------------------------
// CSV export. Plain functions returning a CSV string — the HTTP layer
// (or a CLI script) decides content-type/headers; these just format.
// ---------------------------------------------------------------------

function csvEscape(value: unknown): string {
  return '"' + String(value ?? "").replace(/"/g, '""') + '"';
}

function toCsv(headers: string[], rows: unknown[][]): string {
  const lines = [headers.map(csvEscape).join(",")];
  for (const row of rows) lines.push(row.map(csvEscape).join(","));
  return lines.join("\r\n");
}

export function dailyReportToCsv(report: DailyReport, employeeName: string): string {
  const headers = [
    "date",
    "employee_id",
    "employee_name",
    "session_id",
    "start_utc",
    "end_utc",
    "end_reason",
    "is_end_estimated",
    "active_ms",
    "break_ms",
    "break_count",
    "monitoring_gap_ms",
    "unverified_ms",
    "productive_ms",
    "unproductive_ms",
    "neutral_ms",
    "unclassified_ms",
  ];
  if (report.sessions.length === 0) {
    return toCsv(headers, [[report.date, report.employeeId, employeeName, "(absent — no session recorded)", "", "", "", "", 0, 0, 0, 0, 0, 0, 0, 0, 0]]);
  }
  const rows = report.sessions.map((s) => {
    const activeMs = sumStateMs(s.stateIntervals, "active", s.endUtc ? toMs(s.endUtc) : Date.now());
    const breakMs = sumStateMs(s.stateIntervals, "idle_break", s.endUtc ? toMs(s.endUtc) : Date.now());
    const gapMs = sumStateMs(s.stateIntervals, "monitoring_gap", s.endUtc ? toMs(s.endUtc) : Date.now());
    const unvMs = sumStateMs(s.stateIntervals, "unverified", s.endUtc ? toMs(s.endUtc) : Date.now());
    return [
      report.date,
      report.employeeId,
      employeeName,
      s.sessionId,
      s.startUtc,
      s.endUtc ?? "(missing)",
      s.endReason ?? "",
      s.isEndEstimated ? "yes" : "no",
      activeMs,
      breakMs,
      s.breakIntervals.length,
      gapMs,
      unvMs,
      s.usage.byClassification.productive,
      s.usage.byClassification.unproductive,
      s.usage.byClassification.neutral,
      s.usage.byClassification.unclassified,
    ];
  });
  return toCsv(headers, rows);
}

export function monthlyConsolidatedCsv(report: MonthlyReport, employeeName: string): string {
  const headers = [
    "employee_id",
    "employee_name",
    "year",
    "month",
    "days_in_month",
    "days_with_records",
    "days_absent",
    "days_incomplete",
    "total_session_duration_ms",
    "active_ms",
    "break_ms",
    "break_count",
    "monitoring_gap_ms",
    "productive_ms",
    "unproductive_ms",
    "neutral_ms",
    "avg_active_ms_per_present_day",
    "avg_break_ms_per_present_day",
  ];
  const t = report.totals;
  const a = report.averages;
  return toCsv(headers, [[
    report.employeeId, employeeName, report.year, report.month,
    t.daysInMonth, t.daysWithRecords, t.daysAbsent, t.daysIncomplete,
    t.totalSessionDurationMs, t.activeMs, t.breakMs, t.breakCount, t.monitoringGapMs,
    t.usage.byClassification.productive, t.usage.byClassification.unproductive, t.usage.byClassification.neutral,
    Math.round(a.avgActiveMsPerPresentDay), Math.round(a.avgBreakMsPerPresentDay),
  ]]);
}

export function monthlyDailyBreakdownCsv(report: MonthlyReport, employeeName: string): string {
  const headers = [
    "date", "employee_id", "employee_name", "status", "first_login_utc", "last_logout_utc",
    "is_logout_missing", "session_count", "total_session_duration_ms", "active_ms", "break_ms",
    "break_count", "monitoring_gap_ms", "unverified_ms", "productive_ms", "unproductive_ms", "neutral_ms",
  ];
  const rows = report.days.map((d) => [
    d.date, d.employeeId, employeeName, d.status, d.firstLoginUtc ?? "", d.lastLogoutUtc ?? "",
    d.isLogoutMissing ? "yes" : "no", d.sessionCount, d.totalSessionDurationMs, d.activeMs, d.breakMs,
    d.breakCount, d.monitoringGapMs, d.unverifiedMs,
    d.usage.byClassification.productive, d.usage.byClassification.unproductive, d.usage.byClassification.neutral,
  ]);
  return toCsv(headers, rows);
}
