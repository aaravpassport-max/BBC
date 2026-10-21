import { test } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { DatabaseSync } from "node:sqlite";
import { runMigrations } from "../src/db/migrate.js";
import { createRequestHandler } from "../src/api/server.js";
import { upsertEmployee, upsertDevice, recordNoticeShown, recordNoticeAcknowledged } from "../src/db/repository.js";
import { createAdminUser, loginAdmin, enrollDevice, loginDevice, loginEmployee, setEmployeePassword } from "../src/db/auth-repository.js";
import { t } from "./helpers.js";

/**
 * POST /api/events/ingest — real HTTP, real socket, same pattern as
 * every other *.test.ts here. This is the route that lets a real
 * Windows agent (or anything else holding a device/employee/admin
 * session) push locally-queued raw_events to this central server —
 * previously ingestRawEvents() was only reachable by calling the
 * repository function directly, never over the network.
 */
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

function seed(db: DatabaseSync, employeeId = "emp-1", deviceId = "dev-1") {
  upsertEmployee(db, { employeeId, displayName: `Employee ${employeeId}`, department: "Ops" });
  upsertDevice(db, { deviceId });
  recordNoticeShown(db, employeeId, deviceId, "v1");
  recordNoticeAcknowledged(db, employeeId, deviceId);
}

function authHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, "Content-Type": "application/json" };
}

function rawEv(employeeId: string, deviceId: string, sessionId: string, type: string, timestampUtc: string, eventId: string, payload: Record<string, unknown> = {}) {
  return { eventId, employeeId, deviceId, sessionId, type, timestampUtc, source: "windows-agent", isEstimated: false, payload };
}

test("POST /api/events/ingest: a device session can ingest its own events, which are immediately recomputed and queryable", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const deviceToken = enrollDevice(db, "emp-1", "dev-1");
    const loginRes = await fetch(`${base}/api/auth/device-login`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ deviceToken }) });
    assert.equal(loginRes.status, 200);
    const { token } = (await loginRes.json()) as { token: string };

    const events = [
      rawEv("emp-1", "dev-1", "ing-s1", "session.signin", t("09:00"), "ing-1"),
      rawEv("emp-1", "dev-1", "ing-s1", "input.activity", t("09:00"), "ing-2", { kind: "keyboard" }),
      rawEv("emp-1", "dev-1", "ing-s1", "session.signout", t("17:00"), "ing-3"),
    ];
    const res = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: authHeaders(token), body: JSON.stringify({ events }) });
    assert.equal(res.status, 201);
    const body = (await res.json()) as any;
    assert.equal(body.ingested, 3);
    assert.equal(body.insertedCount, 3);
    assert.deepEqual(body.recomputedSessionIds, ["ing-s1"]);

    // Immediately queryable via the admin API — proves recompute actually ran, not just insertion.
    const adminToken = loginAdmin(db, (createAdminUser(db, { adminId: "a1", displayName: "A", email: "a@example.com", password: "s3cret-pass" }), "a@example.com"), "s3cret-pass").token;
    const sessionRes = await fetch(`${base}/api/sessions/ing-s1`, { headers: authHeaders(adminToken) });
    assert.equal(sessionRes.status, 200);
    const sessionBody = (await sessionRes.json()) as any;
    assert.equal(sessionBody.session.endUtc, t("17:00"));
    assert.ok(sessionBody.stateIntervals.length > 0, "recompute must have produced real state intervals, not an empty result");
  });
});

test("POST /api/events/ingest: resending the same eventIds is a safe no-op (idempotent), matching ingestRawEvent's ON CONFLICT behavior", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const deviceToken = enrollDevice(db, "emp-1", "dev-1");
    const { token } = (await (await fetch(`${base}/api/auth/device-login`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ deviceToken }) })).json()) as { token: string };
    const events = [rawEv("emp-1", "dev-1", "dup-s1", "session.signin", t("09:00"), "dup-1")];

    const first = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: authHeaders(token), body: JSON.stringify({ events }) });
    assert.equal(((await first.json()) as any).insertedCount, 1);

    const second = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: authHeaders(token), body: JSON.stringify({ events }) });
    const secondBody = (await second.json()) as any;
    assert.equal(second.status, 201);
    assert.equal(secondBody.ingested, 1);
    assert.equal(secondBody.insertedCount, 0, "a resend of an already-synced event must not double-insert");
  });
});

test("POST /api/events/ingest: a device session cannot ingest events for a different employeeId or deviceId", async () => {
  await withTestServer(async (base, db) => {
    seed(db, "emp-1", "dev-1");
    seed(db, "emp-2", "dev-2");
    const deviceToken = enrollDevice(db, "emp-1", "dev-1");
    const { token } = (await (await fetch(`${base}/api/auth/device-login`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ deviceToken }) })).json()) as { token: string };

    const foreignEmployee = await fetch(`${base}/api/events/ingest`, {
      method: "POST", headers: authHeaders(token),
      body: JSON.stringify({ events: [rawEv("emp-2", "dev-1", "s1", "session.signin", t("09:00"), "x1")] }),
    });
    assert.equal(foreignEmployee.status, 403);

    const foreignDevice = await fetch(`${base}/api/events/ingest`, {
      method: "POST", headers: authHeaders(token),
      body: JSON.stringify({ events: [rawEv("emp-1", "dev-2", "s1", "session.signin", t("09:00"), "x2")] }),
    });
    assert.equal(foreignDevice.status, 403);
  });
});

test("POST /api/events/ingest: an employee-password session (no deviceId) is scoped by employeeId but not deviceId", async () => {
  await withTestServer(async (base, db) => {
    seed(db, "emp-1", "dev-1");
    setEmployeePassword(db, "emp-1", "s3cret-pass");
    const { token } = loginEmployee(db, "emp-1", "s3cret-pass");

    const ok = await fetch(`${base}/api/events/ingest`, {
      method: "POST", headers: authHeaders(token),
      body: JSON.stringify({ events: [rawEv("emp-1", "dev-1", "s1", "session.signin", t("09:00"), "emp-x1")] }),
    });
    assert.equal(ok.status, 201);

    const foreignEmployee = await fetch(`${base}/api/events/ingest`, {
      method: "POST", headers: authHeaders(token),
      body: JSON.stringify({ events: [rawEv("emp-2", "dev-1", "s1", "session.signin", t("09:00"), "emp-x2")] }),
    });
    assert.equal(foreignEmployee.status, 403);
  });
});

test("POST /api/events/ingest: an admin session may ingest on behalf of any employee/device (bulk-import use case)", async () => {
  await withTestServer(async (base, db) => {
    seed(db, "emp-1", "dev-1");
    createAdminUser(db, { adminId: "admin-1", displayName: "Admin", email: "admin@example.com", password: "correct-horse-battery-staple" });
    const { token } = loginAdmin(db, "admin@example.com", "correct-horse-battery-staple");
    const res = await fetch(`${base}/api/events/ingest`, {
      method: "POST", headers: authHeaders(token),
      body: JSON.stringify({ events: [rawEv("emp-1", "dev-1", "admin-s1", "session.signin", t("09:00"), "admin-x1")] }),
    });
    assert.equal(res.status, 201);
  });
});

test("POST /api/events/ingest: rejects an empty/missing events array, a malformed event, and over 1000 events, each with 400", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    createAdminUser(db, { adminId: "admin-1", displayName: "Admin", email: "admin@example.com", password: "correct-horse-battery-staple" });
    const { token } = loginAdmin(db, "admin@example.com", "correct-horse-battery-staple");

    const missing = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: authHeaders(token), body: JSON.stringify({}) });
    assert.equal(missing.status, 400);

    const empty = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: authHeaders(token), body: JSON.stringify({ events: [] }) });
    assert.equal(empty.status, 400);

    const malformed = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: authHeaders(token), body: JSON.stringify({ events: [{ eventId: "bad-1" }] }) });
    assert.equal(malformed.status, 400);

    const tooMany = Array.from({ length: 1001 }, (_, i) => rawEv("emp-1", "dev-1", "s1", "session.signin", t("09:00"), `many-${i}`));
    const overLimit = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: authHeaders(token), body: JSON.stringify({ events: tooMany }) });
    assert.equal(overLimit.status, 400);
  });
});

test("POST /api/events/ingest: the transparency gate still refuses events for an employee/device pair with no acknowledged notice", async () => {
  await withTestServer(async (base, db) => {
    // Employee/device exist, but notice was never shown/acknowledged.
    upsertEmployee(db, { employeeId: "emp-gate", displayName: "Gate Employee", department: "Ops" });
    upsertDevice(db, { deviceId: "dev-gate" });
    createAdminUser(db, { adminId: "admin-1", displayName: "Admin", email: "admin@example.com", password: "correct-horse-battery-staple" });
    const { token } = loginAdmin(db, "admin@example.com", "correct-horse-battery-staple");

    const res = await fetch(`${base}/api/events/ingest`, {
      method: "POST", headers: authHeaders(token),
      body: JSON.stringify({ events: [rawEv("emp-gate", "dev-gate", "gs1", "session.signin", t("09:00"), "gate-1")] }),
    });
    assert.equal(res.status, 403);
    const body = (await res.json()) as any;
    assert.match(body.error, /no acknowledged monitoring notice/);
  });
});

test("POST /api/events/ingest requires authentication, like every other route past /api/health and /api/auth/*", async () => {
  await withTestServer(async (base) => {
    const res = await fetch(`${base}/api/events/ingest`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ events: [] }) });
    assert.equal(res.status, 401);
  });
});
