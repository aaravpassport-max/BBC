import { test } from "node:test";
import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import { runMigrations } from "../src/db/migrate.js";
import {
  upsertEmployee,
  upsertDevice,
  recordNoticeShown,
  recordNoticeAcknowledged,
  ingestRawEvents,
  recomputeSession,
  recomputeUsage,
  addClassificationRule,
  getDailyReport,
  getMonthlyReport,
  queryAttendanceHistory,
  getRetentionDays,
  setRetentionDays,
  assertPruneAllowed,
} from "../src/db/repository.js";
import { ev } from "./helpers.js";

/**
 * Proves the reporting layer end to end against a real SQLite DB — not
 * just the pure functions in reporting.test.ts, which never touch a
 * database. This is where a real bug (a filter that silently drops a
 * session, an off-by-one in the date-range SQL) would actually surface.
 */
function freshDb(): DatabaseSync {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  runMigrations(db);
  return db;
}

function seedEmployee(db: DatabaseSync, employeeId: string, deviceId: string, department = "Operations") {
  upsertEmployee(db, { employeeId, displayName: "Priya Sharma", department });
  upsertDevice(db, { deviceId });
  recordNoticeShown(db, employeeId, deviceId, "policy-v1");
  recordNoticeAcknowledged(db, employeeId, deviceId);
}

function isoOn(day: string, hhmm: string): string {
  const [h, m] = hhmm.split(":").map(Number);
  return new Date(Date.UTC(Number(day.slice(0, 4)), Number(day.slice(5, 7)) - 1, Number(day.slice(8, 10)), h, m)).toISOString();
}

test("getDailyReport: reconciles a real seeded day end to end, including app/domain classification", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1", "dev-1");
  const day = "2026-10-05";
  const events = [
    ev("session.signin", isoOn(day, "09:00"), { eventId: "e1", sessionId: "s1" }),
    ev("input.activity", isoOn(day, "09:00"), { eventId: "e2", sessionId: "s1" }),
    ev("app.foreground_start", isoOn(day, "09:00"), { eventId: "e3", sessionId: "s1", payload: { executableName: "code.exe" } }),
    ev("input.activity", isoOn(day, "17:00"), { eventId: "e4", sessionId: "s1" }),
    ev("session.signout", isoOn(day, "17:30"), { eventId: "e5", sessionId: "s1" }),
  ];
  ingestRawEvents(db, events);
  recomputeSession(db, "s1");
  recomputeUsage(db, "s1");
  addClassificationRule(db, { ruleId: "r1", target: "app:code.exe", classification: "productive", effectiveFromUtc: isoOn(day, "00:00"), createdByAdminId: "admin-1" });

  const report = getDailyReport(db, "emp-1", day, isoOn(day, "23:59"));
  assert.equal(report.status, "present");
  assert.equal(report.firstLoginUtc, isoOn(day, "09:00"));
  assert.equal(report.lastLogoutUtc, isoOn(day, "17:30"));
  assert.equal(report.usage.byClassification.productive, (17.5 - 9) * 3600000); // open-ended interval runs to the session's real end
  db.close();
});

test("getDailyReport: a day with no events for that employee is absent, not an error or a zeroed report", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1", "dev-1");
  const report = getDailyReport(db, "emp-1", "2026-10-09", "2026-10-09T23:59:59.999Z");
  assert.equal(report.status, "absent");
  assert.equal(report.sessionCount, 0);
  db.close();
});

test("getMonthlyReport: aggregates a real multi-day month, distinguishing present/absent/incomplete days", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1", "dev-1");

  // Oct 5: a clean, complete day.
  ingestRawEvents(db, [
    ev("session.signin", isoOn("2026-10-05", "09:00"), { eventId: "d5-1", sessionId: "s5" }),
    ev("input.activity", isoOn("2026-10-05", "09:00"), { eventId: "d5-2", sessionId: "s5" }),
    ev("session.signout", isoOn("2026-10-05", "17:00"), { eventId: "d5-3", sessionId: "s5" }),
  ]);
  recomputeSession(db, "s5");
  recomputeUsage(db, "s5");

  // Oct 6: signed in, but no sign-out event ever arrived — incomplete.
  ingestRawEvents(db, [
    ev("session.signin", isoOn("2026-10-06", "09:00"), { eventId: "d6-1", sessionId: "s6" }),
    ev("input.activity", isoOn("2026-10-06", "09:00"), { eventId: "d6-2", sessionId: "s6" }),
  ]);
  recomputeSession(db, "s6");
  recomputeUsage(db, "s6");

  // Oct 7: nothing at all — absent.

  const asOf = isoOn("2026-10-08", "09:00"); // "now" is after all three days, so Oct 6 is unambiguously a PAST day
  const monthly = getMonthlyReport(db, "emp-1", 2026, 10, asOf);

  const day5 = monthly.days.find((d) => d.date === "2026-10-05")!;
  const day6 = monthly.days.find((d) => d.date === "2026-10-06")!;
  const day7 = monthly.days.find((d) => d.date === "2026-10-07")!;
  assert.equal(day5.status, "present");
  assert.equal(day6.status, "incomplete");
  assert.equal(day6.isLogoutMissing, true);
  assert.equal(day7.status, "absent");
  assert.equal(monthly.totals.daysWithRecords, 2);
  assert.equal(monthly.totals.daysAbsent, monthly.totals.daysInMonth - 2);
  db.close();
});

test("queryAttendanceHistory: date-range + department filter returns only matching rows, using the indexed employee/start_utc scan", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1", "dev-1", "Operations");
  seedEmployee(db, "emp-2", "dev-2", "Engineering");

  ingestRawEvents(db, [
    ev("session.signin", isoOn("2026-10-05", "09:00"), { eventId: "h1", employeeId: "emp-1", deviceId: "dev-1", sessionId: "hs1" }),
    ev("session.signout", isoOn("2026-10-05", "17:00"), { eventId: "h2", employeeId: "emp-1", deviceId: "dev-1", sessionId: "hs1" }),
    ev("session.signin", isoOn("2026-10-05", "10:00"), { eventId: "h3", employeeId: "emp-2", deviceId: "dev-2", sessionId: "hs2" }),
    ev("session.signout", isoOn("2026-10-05", "18:00"), { eventId: "h4", employeeId: "emp-2", deviceId: "dev-2", sessionId: "hs2" }),
  ]);
  recomputeSession(db, "hs1");
  recomputeSession(db, "hs2");

  const opsOnly = queryAttendanceHistory(db, { department: "Operations", dateFrom: "2026-10-01", dateTo: "2026-10-10" });
  assert.equal(opsOnly.length, 1);
  assert.equal(opsOnly[0]!.employeeId, "emp-1");

  // Single-employee query synthesizes absent days across the full range — proves the recursive-CTE date series.
  const emp1History = queryAttendanceHistory(db, { employeeId: "emp-1", dateFrom: "2026-10-03", dateTo: "2026-10-06" });
  assert.equal(emp1History.length, 4); // Oct 3,4,5,6
  const byDate = Object.fromEntries(emp1History.map((r) => [r.date, r.status]));
  assert.equal(byDate["2026-10-03"], "absent");
  assert.equal(byDate["2026-10-04"], "absent");
  assert.equal(byDate["2026-10-05"], "present");
  assert.equal(byDate["2026-10-06"], "absent");

  const onlyAbsent = queryAttendanceHistory(db, { employeeId: "emp-1", dateFrom: "2026-10-03", dateTo: "2026-10-06", status: "absent" });
  assert.equal(onlyAbsent.length, 3);
  db.close();
});

test("retention: defaults to 365 days, refuses anything below it, accepts an admin raising it", () => {
  const db = freshDb();
  assert.equal(getRetentionDays(db), 365);

  assert.throws(() => setRetentionDays(db, 30, "admin-1"), /at least 365/);
  assert.equal(getRetentionDays(db), 365); // the rejected write must not have taken effect

  setRetentionDays(db, 730, "admin-1");
  assert.equal(getRetentionDays(db), 730);
  db.close();
});

test("retention: assertPruneAllowed refuses to prune anything inside the configured window", () => {
  const db = freshDb();
  const todayUtc = new Date().toISOString();
  assert.throws(() => assertPruneAllowed(db, todayUtc), /retention window/);

  const farPastUtc = "2000-01-01T00:00:00.000Z";
  assert.doesNotThrow(() => assertPruneAllowed(db, farPastUtc));
  db.close();
});
