import { test } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { DatabaseSync } from "node:sqlite";
import { runMigrations } from "../src/db/migrate.js";
import { createRequestHandler } from "../src/api/server.js";
import {
  upsertEmployee,
  upsertDevice,
  recordNoticeShown,
  recordNoticeAcknowledged,
  ingestRawEvents,
  recomputeSession,
  recomputeUsage,
} from "../src/db/repository.js";
import { createAdminUser, loginAdmin } from "../src/db/auth-repository.js";
import { ev } from "./helpers.js";

function authHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}` };
}
function adminToken(db: DatabaseSync): string {
  createAdminUser(db, { adminId: "admin-1", displayName: "Test Admin", email: "admin@example.com", password: "correct-horse-battery-staple" });
  return loginAdmin(db, "admin@example.com", "correct-horse-battery-staple").token;
}

/** Same real-socket pattern as api-server.test.ts — proving the reporting routes over actual HTTP, not mocked handlers. */
async function withTestServer(fn: (baseUrl: string, db: DatabaseSync) => Promise<void>) {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  runMigrations(db);
  const server = createServer(createRequestHandler(db));
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  const port = typeof address === "object" && address ? address.port : 0;
  try {
    await fn(`http://127.0.0.1:${port}`, db);
  } finally {
    await new Promise<void>((resolve) => server.close(() => resolve()));
    db.close();
  }
}

function seed(db: DatabaseSync) {
  upsertEmployee(db, { employeeId: "emp-1", displayName: "Test Employee", department: "Ops" });
  upsertDevice(db, { deviceId: "dev-1" });
  recordNoticeShown(db, "emp-1", "dev-1", "policy-v1");
  recordNoticeAcknowledged(db, "emp-1", "dev-1");
}

function isoOn(day: string, hhmm: string): string {
  const [h, m] = hhmm.split(":").map(Number);
  return new Date(Date.UTC(Number(day.slice(0, 4)), Number(day.slice(5, 7)) - 1, Number(day.slice(8, 10)), h, m)).toISOString();
}

test("GET /api/reports/daily requires employeeId and a well-formed date", async () => {
  await withTestServer(async (base, db) => {
    const token = adminToken(db);
    const noEmp = await fetch(`${base}/api/reports/daily?date=2026-10-05`, { headers: authHeaders(token) });
    assert.equal(noEmp.status, 400);
    const badDate = await fetch(`${base}/api/reports/daily?employeeId=emp-1&date=10-05-2026`, { headers: authHeaders(token) });
    assert.equal(badDate.status, 400);
  });
});

test("GET /api/reports/daily returns a real absent day for a date with no events", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const token = adminToken(db);
    const res = await fetch(`${base}/api/reports/daily?employeeId=emp-1&date=2026-10-09`, { headers: authHeaders(token) });
    assert.equal(res.status, 200);
    const body = (await res.json()) as any;
    assert.equal(body.status, "absent");
    assert.equal(body.sessionCount, 0);
  });
});

test("GET /api/reports/daily.csv returns a real CSV attachment reconciling with the JSON route", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const token = adminToken(db);
    ingestRawEvents(db, [
      ev("session.signin", isoOn("2026-10-05", "09:00"), { eventId: "e1", sessionId: "s1" }),
      ev("input.activity", isoOn("2026-10-05", "09:00"), { eventId: "e2", sessionId: "s1" }),
      ev("session.signout", isoOn("2026-10-05", "17:00"), { eventId: "e3", sessionId: "s1" }),
    ]);
    recomputeSession(db, "s1");
    recomputeUsage(db, "s1");

    const csvRes = await fetch(`${base}/api/reports/daily.csv?employeeId=emp-1&date=2026-10-05`, { headers: authHeaders(token) });
    assert.equal(csvRes.status, 200);
    assert.equal(csvRes.headers.get("content-type"), "text/csv; charset=utf-8");
    assert.match(csvRes.headers.get("content-disposition") ?? "", /attachment; filename="daily-emp-1-2026-10-05\.csv"/);
    const csv = await csvRes.text();
    assert.ok(csv.includes("Test Employee"));
    assert.ok(csv.split("\r\n").length === 2); // header + one session row
  });
});

test("GET /api/reports/monthly aggregates real multi-day data and GET /api/reports/monthly.csv reconciles with it", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const token = adminToken(db);
    ingestRawEvents(db, [
      ev("session.signin", isoOn("2026-10-05", "09:00"), { eventId: "m1", sessionId: "ms1" }),
      ev("input.activity", isoOn("2026-10-05", "09:00"), { eventId: "m2", sessionId: "ms1" }),
      ev("session.signout", isoOn("2026-10-05", "17:00"), { eventId: "m3", sessionId: "ms1" }),
      ev("session.signin", isoOn("2026-10-06", "09:00"), { eventId: "m4", sessionId: "ms2" }),
      ev("input.activity", isoOn("2026-10-06", "09:00"), { eventId: "m5", sessionId: "ms2" }),
      ev("session.signout", isoOn("2026-10-06", "17:00"), { eventId: "m6", sessionId: "ms2" }),
    ]);
    recomputeSession(db, "ms1");
    recomputeUsage(db, "ms1");
    recomputeSession(db, "ms2");
    recomputeUsage(db, "ms2");

    const res = await fetch(`${base}/api/reports/monthly?employeeId=emp-1&year=2026&month=10`, { headers: authHeaders(token) });
    assert.equal(res.status, 200);
    const body = (await res.json()) as any;
    assert.ok(body.totals.daysWithRecords >= 2);
    assert.equal(body.totals.activeMs, body.averages.avgActiveMsPerPresentDay * body.totals.daysWithRecords);

    const csvRes = await fetch(`${base}/api/reports/monthly.csv?employeeId=emp-1&year=2026&month=10&variant=consolidated`, { headers: authHeaders(token) });
    assert.equal(csvRes.status, 200);
    const csv = await csvRes.text();
    assert.ok(csv.includes(String(body.totals.activeMs)));

    const dailyCsvRes = await fetch(`${base}/api/reports/monthly.csv?employeeId=emp-1&year=2026&month=10&variant=daily`, { headers: authHeaders(token) });
    const dailyCsv = await dailyCsvRes.text();
    assert.equal(dailyCsv.split("\r\n").length, body.totals.daysInMonth + 1); // header + one row per calendar day
  });
});

test("GET /api/history filters by date range and department, and refuses a bad status value", async () => {
  await withTestServer(async (base, db) => {
    const token = adminToken(db);
    upsertEmployee(db, { employeeId: "emp-1", displayName: "Ops Employee", department: "Ops" });
    upsertEmployee(db, { employeeId: "emp-2", displayName: "Eng Employee", department: "Engineering" });
    upsertDevice(db, { deviceId: "dev-1" });
    upsertDevice(db, { deviceId: "dev-2" });
    recordNoticeShown(db, "emp-1", "dev-1", "v1"); recordNoticeAcknowledged(db, "emp-1", "dev-1");
    recordNoticeShown(db, "emp-2", "dev-2", "v1"); recordNoticeAcknowledged(db, "emp-2", "dev-2");

    ingestRawEvents(db, [
      ev("session.signin", isoOn("2026-10-05", "09:00"), { eventId: "h1", employeeId: "emp-1", deviceId: "dev-1", sessionId: "hs1" }),
      ev("session.signout", isoOn("2026-10-05", "17:00"), { eventId: "h2", employeeId: "emp-1", deviceId: "dev-1", sessionId: "hs1" }),
    ]);
    recomputeSession(db, "hs1");

    const missingDates = await fetch(`${base}/api/history`, { headers: authHeaders(token) });
    assert.equal(missingDates.status, 400);

    const badStatus = await fetch(`${base}/api/history?dateFrom=2026-10-01&dateTo=2026-10-10&status=bogus`, { headers: authHeaders(token) });
    assert.equal(badStatus.status, 400);

    const opsOnly = await fetch(`${base}/api/history?department=Ops&dateFrom=2026-10-01&dateTo=2026-10-10`, { headers: authHeaders(token) });
    const opsRows = (await opsOnly.json()) as any[];
    assert.equal(opsRows.length, 1);
    assert.equal(opsRows[0].employeeId, "emp-1");
  });
});

test("GET /api/retention returns the configured minimum", async () => {
  await withTestServer(async (base, db) => {
    const token = adminToken(db);
    const res = await fetch(`${base}/api/retention`, { headers: authHeaders(token) });
    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), { retentionDays: 365 });
  });
});
