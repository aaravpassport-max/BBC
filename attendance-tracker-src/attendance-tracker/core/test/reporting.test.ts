import { test } from "node:test";
import assert from "node:assert/strict";
import {
  buildDailyReport,
  buildMonthlyReport,
  dailyReportToCsv,
  monthlyConsolidatedCsv,
  monthlyDailyBreakdownCsv,
  type DailySessionDetail,
  type DailyReport,
} from "../src/engine/reporting.js";
import type { UsageSummary } from "../src/engine/classification.js";

function iso(day: string, hhmm: string): string {
  const [h, m] = hhmm.split(":").map(Number);
  return new Date(Date.UTC(
    Number(day.slice(0, 4)), Number(day.slice(5, 7)) - 1, Number(day.slice(8, 10)), h, m, 0, 0,
  )).toISOString();
}

function emptyUsage(): UsageSummary {
  return { totalMs: 0, byClassification: { productive: 0, neutral: 0, unproductive: 0, unclassified: 0, excluded: 0 }, byExecutable: [], byDomain: [] };
}

function usage(productiveMs: number, unproductiveMs: number): UsageSummary {
  return {
    totalMs: productiveMs + unproductiveMs,
    byClassification: { productive: productiveMs, neutral: 0, unproductive: unproductiveMs, unclassified: 0, excluded: 0 },
    byExecutable: productiveMs ? [{ executableName: "code.exe", ms: productiveMs, classification: "productive" }] : [],
    byDomain: unproductiveMs ? [{ domain: "news.ycombinator.com", ms: unproductiveMs, classification: "unproductive" }] : [],
  };
}

function session(day: string, opts: Partial<DailySessionDetail> & { startHhmm: string; endHhmm: string | null; states?: Array<[string, string, string | null]> }): DailySessionDetail {
  return {
    sessionId: opts.sessionId ?? "s1",
    startUtc: iso(day, opts.startHhmm),
    endUtc: opts.endHhmm ? iso(day, opts.endHhmm) : null,
    endReason: opts.endHhmm ? "signout" : null,
    isEndEstimated: false,
    stateIntervals: (opts.states ?? []).map(([state, start, end]) => ({
      employeeId: "emp-1",
      deviceId: "dev-1",
      sessionId: opts.sessionId ?? "s1",
      state: state as any,
      startUtc: iso(day, start),
      endUtc: end ? iso(day, end) : null,
      causedByEventIds: [],
      isEstimated: false,
    })),
    breakIntervals: opts.breakIntervals ?? [],
    usage: opts.usage ?? emptyUsage(),
  };
}

test("buildDailyReport: a day with zero sessions is absent, never a zeroed-out present row", () => {
  const report = buildDailyReport("2026-10-05", "emp-1", [], "2026-10-05T23:59:59.999Z", false);
  assert.equal(report.status, "absent");
  assert.equal(report.firstLoginUtc, null);
  assert.equal(report.lastLogoutUtc, null);
  assert.equal(report.sessionCount, 0);
  assert.equal(report.totalSessionDurationMs, 0);
  assert.equal(report.activeMs, 0);
});

test("buildDailyReport: present day sums active/break time from state intervals, keeps session span separate", () => {
  const day = "2026-10-06";
  const s = session(day, {
    startHhmm: "09:00", endHhmm: "17:30",
    states: [
      ["active", "09:00", "12:00"],
      ["idle_break", "12:00", "12:30"],
      ["active", "12:30", "17:30"],
    ],
  });
  const report = buildDailyReport(day, "emp-1", [s], iso(day, "17:30"), false);
  assert.equal(report.status, "present");
  assert.equal(report.firstLoginUtc, iso(day, "09:00"));
  assert.equal(report.lastLogoutUtc, iso(day, "17:30"));
  assert.equal(report.isLogoutMissing, false);
  // session span: 09:00-17:30 = 8.5h; active: (3h)+(5h)=8h; break: 0.5h — three different numbers, none derived from the others.
  assert.equal(report.totalSessionDurationMs, 8.5 * 3600000);
  assert.equal(report.activeMs, 8 * 3600000);
  assert.equal(report.breakMs, 0.5 * 3600000);
});

test("buildDailyReport: a past day whose last session never got an end event is incomplete, not silently closed", () => {
  const day = "2026-10-06";
  const s = session(day, { startHhmm: "09:00", endHhmm: null, states: [["active", "09:00", null]] });
  const report = buildDailyReport(day, "emp-1", [s], iso(day, "23:59"), /* isToday */ false);
  assert.equal(report.isLogoutMissing, true);
  assert.equal(report.status, "incomplete");
});

test("buildDailyReport: today's still-open session is NOT flagged as a missing logout", () => {
  const day = "2026-10-19";
  const s = session(day, { startHhmm: "09:00", endHhmm: null, states: [["active", "09:00", null]] });
  const report = buildDailyReport(day, "emp-1", [s], iso(day, "14:00"), /* isToday */ true);
  assert.equal(report.isLogoutMissing, false);
  assert.equal(report.status, "present");
});

test("buildDailyReport: a monitoring gap marks the day incomplete even with a clean sign-out", () => {
  const day = "2026-10-06";
  const s = session(day, {
    startHhmm: "09:00", endHhmm: "17:00",
    states: [["active", "09:00", "11:00"], ["monitoring_gap", "11:00", "13:00"], ["active", "13:00", "17:00"]],
  });
  const report = buildDailyReport(day, "emp-1", [s], iso(day, "17:00"), false);
  assert.equal(report.monitoringGapMs, 2 * 3600000);
  assert.equal(report.status, "incomplete");
});

test("buildDailyReport: merges per-session usage summaries without double-counting across sessions", () => {
  const day = "2026-10-06";
  const s1 = session(day, { sessionId: "s1", startHhmm: "09:00", endHhmm: "12:00", usage: usage(2 * 3600000, 0) });
  const s2 = session(day, { sessionId: "s2", startHhmm: "13:00", endHhmm: "17:00", usage: usage(3 * 3600000, 1800000) });
  const report = buildDailyReport(day, "emp-1", [s1, s2], iso(day, "17:00"), false);
  assert.equal(report.usage.byClassification.productive, 5 * 3600000);
  assert.equal(report.usage.byClassification.unproductive, 1800000);
  assert.equal(report.usage.byExecutable.length, 1);
  assert.equal(report.usage.byExecutable[0]!.ms, 5 * 3600000); // same exe across both sessions, summed not duplicated
});

test("buildMonthlyReport: averages are computed over days WITH records, not calendar days in the month", () => {
  const present1 = buildDailyReport("2026-10-01", "emp-1", [session("2026-10-01", { startHhmm: "09:00", endHhmm: "17:00", states: [["active", "09:00", "17:00"]] })], "2026-10-01T17:00:00.000Z", false);
  const absent = buildDailyReport("2026-10-02", "emp-1", [], "2026-10-02T23:59:59.999Z", false);
  const present2 = buildDailyReport("2026-10-03", "emp-1", [session("2026-10-03", { startHhmm: "09:00", endHhmm: "13:00", states: [["active", "09:00", "13:00"]] })], "2026-10-03T13:00:00.000Z", false);

  const monthly = buildMonthlyReport("emp-1", 2026, 10, [present1, absent, present2]);
  assert.equal(monthly.totals.daysInMonth, 3);
  assert.equal(monthly.totals.daysWithRecords, 2);
  assert.equal(monthly.totals.daysAbsent, 1);
  assert.equal(monthly.totals.activeMs, 12 * 3600000); // 8h + 4h
  // average over the 2 PRESENT days (12h/2=6h), never over 3 calendar days (which would read as 4h and understate attendance)
  assert.equal(monthly.averages.avgActiveMsPerPresentDay, 6 * 3600000);
});

test("buildMonthlyReport: an incomplete day (missing logout / monitoring gap) is counted separately from absent", () => {
  const incomplete = buildDailyReport("2026-10-01", "emp-1", [session("2026-10-01", { startHhmm: "09:00", endHhmm: null, states: [["active", "09:00", null]] })], "2026-10-01T23:59:59.999Z", false);
  const absent = buildDailyReport("2026-10-02", "emp-1", [], "2026-10-02T23:59:59.999Z", false);
  const monthly = buildMonthlyReport("emp-1", 2026, 10, [incomplete, absent]);
  assert.equal(monthly.totals.daysIncomplete, 1);
  assert.equal(monthly.totals.daysAbsent, 1);
  assert.equal(monthly.totals.daysWithRecords, 1); // the incomplete day HAS a record, it's just flagged
});

test("dailyReportToCsv: an absent day produces one explicit 'no session recorded' row, never a fabricated zero-activity row", () => {
  const report: DailyReport = buildDailyReport("2026-10-02", "emp-1", [], "2026-10-02T23:59:59.999Z", false);
  const csv = dailyReportToCsv(report, "Test Employee");
  const lines = csv.split("\r\n");
  assert.equal(lines.length, 2); // header + one row
  assert.ok(lines[1]!.includes("absent — no session recorded"));
});

test("dailyReportToCsv: one row per session, with distinct active/break/classification columns", () => {
  const day = "2026-10-06";
  const s = session(day, { startHhmm: "09:00", endHhmm: "17:00", states: [["active", "09:00", "16:00"], ["idle_break", "16:00", "17:00"]], usage: usage(1000, 500) });
  const report = buildDailyReport(day, "emp-1", [s], iso(day, "17:00"), false);
  const csv = dailyReportToCsv(report, "Test Employee");
  const lines = csv.split("\r\n");
  assert.equal(lines.length, 2);
  assert.ok(lines[1]!.includes(String(7 * 3600000))); // active_ms = 7h
  assert.ok(lines[1]!.includes("1000")); // productive_ms column
});

test("monthlyConsolidatedCsv and monthlyDailyBreakdownCsv: header + row counts are correct and reconcile with the report totals", () => {
  const present = buildDailyReport("2026-10-01", "emp-1", [session("2026-10-01", { startHhmm: "09:00", endHhmm: "17:00", states: [["active", "09:00", "17:00"]] })], "2026-10-01T17:00:00.000Z", false);
  const absent = buildDailyReport("2026-10-02", "emp-1", [], "2026-10-02T23:59:59.999Z", false);
  const monthly = buildMonthlyReport("emp-1", 2026, 10, [present, absent]);

  const consolidated = monthlyConsolidatedCsv(monthly, "Test Employee");
  const consolidatedLines = consolidated.split("\r\n");
  assert.equal(consolidatedLines.length, 2); // header + exactly one summary row
  assert.ok(consolidatedLines[1]!.includes(String(monthly.totals.activeMs)));

  const breakdown = monthlyDailyBreakdownCsv(monthly, "Test Employee");
  const breakdownLines = breakdown.split("\r\n");
  assert.equal(breakdownLines.length, 3); // header + 2 calendar days
  assert.ok(breakdownLines[2]!.includes("absent"));
});
