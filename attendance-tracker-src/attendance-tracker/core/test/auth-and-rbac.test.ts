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
} from "../src/db/repository.js";
import { createAdminUser, loginAdmin, setEmployeePassword, enrollDevice } from "../src/db/auth-repository.js";
import { ev, t } from "./helpers.js";

/**
 * HTTP-level proof that role-based access control is actually enforced
 * by api/server.ts over real sockets — not merely documented in a
 * comment. Every pre-existing test file authenticates as an admin up
 * front and leaves auth itself to this file; this file is where the
 * "an employee session can never act as anyone but itself" invariant
 * gets exercised from the outside, the same way a real attacker (or a
 * buggy frontend) would try it: wrong passwords, foreign employeeIds in
 * query params, wrong roles on role-gated routes, and tokens that are
 * missing, garbage, or expired.
 */

function authHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}` };
}

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

/** Seeds one employee with a real, recomputed session on a fixed day so report/history/correction routes have real data to return. */
function seedEmployeeWithSession(db: DatabaseSync, employeeId: string, deviceId: string, sessionId: string) {
  upsertEmployee(db, { employeeId, displayName: `Employee ${employeeId}`, department: "Ops" });
  upsertDevice(db, { deviceId });
  recordNoticeShown(db, employeeId, deviceId, "v1");
  recordNoticeAcknowledged(db, employeeId, deviceId);
  ingestRawEvents(db, [
    ev("session.signin", t("09:00"), { eventId: `${sessionId}-in`, employeeId, deviceId, sessionId }),
    ev("input.activity", t("09:00"), { eventId: `${sessionId}-act`, employeeId, deviceId, sessionId }),
    ev("session.signout", t("17:00"), { eventId: `${sessionId}-out`, employeeId, deviceId, sessionId }),
  ] as any);
  recomputeSession(db, sessionId);
}

function seedAdmin(db: DatabaseSync, adminId = "admin-1") {
  createAdminUser(db, { adminId, displayName: "Root Admin", email: `${adminId}@example.com`, password: "s3cret-pass" });
  return { email: `${adminId}@example.com`, password: "s3cret-pass" };
}

async function adminLoginToken(base: string, db: DatabaseSync, adminId = "admin-1"): Promise<string> {
  const { email, password } = seedAdmin(db, adminId);
  const res = await fetch(`${base}/api/auth/admin-login`, { method: "POST", body: JSON.stringify({ email, password }) });
  assert.equal(res.status, 200);
  return ((await res.json()) as any).token;
}

async function employeeLoginToken(base: string, db: DatabaseSync, employeeId: string, password = "emp-pass"): Promise<string> {
  setEmployeePassword(db, employeeId, password);
  const res = await fetch(`${base}/api/auth/employee-login`, { method: "POST", body: JSON.stringify({ employeeId, password }) });
  assert.equal(res.status, 200);
  return ((await res.json()) as any).token;
}

async function deviceLoginToken(base: string, db: DatabaseSync, employeeId: string, deviceId: string): Promise<string> {
  const deviceToken = enrollDevice(db, employeeId, deviceId);
  const res = await fetch(`${base}/api/auth/device-login`, { method: "POST", body: JSON.stringify({ deviceToken }) });
  assert.equal(res.status, 200);
  return ((await res.json()) as any).token;
}

// ---- 1. Admin login ----

test("POST /api/auth/admin-login succeeds with correct credentials, fails with wrong password, fails for unknown email", async () => {
  await withTestServer(async (base, db) => {
    const { email, password } = seedAdmin(db);

    const ok = await fetch(`${base}/api/auth/admin-login`, { method: "POST", body: JSON.stringify({ email, password }) });
    assert.equal(ok.status, 200);
    const body = (await ok.json()) as any;
    assert.equal(body.adminId, "admin-1");
    assert.ok(body.token);

    const wrongPass = await fetch(`${base}/api/auth/admin-login`, { method: "POST", body: JSON.stringify({ email, password: "nope" }) });
    assert.equal(wrongPass.status, 401);

    const unknown = await fetch(`${base}/api/auth/admin-login`, { method: "POST", body: JSON.stringify({ email: "ghost@example.com", password }) });
    assert.equal(unknown.status, 401);
  });
});

// ---- 2. Employee login ----

test("POST /api/auth/employee-login succeeds/fails analogously, and an employee with no credentials row cannot log in", async () => {
  await withTestServer(async (base, db) => {
    upsertEmployee(db, { employeeId: "emp-1", displayName: "Has Password", department: "Ops" });
    upsertEmployee(db, { employeeId: "emp-2", displayName: "No Password", department: "Ops" });
    setEmployeePassword(db, "emp-1", "emp-pass-1");

    const ok = await fetch(`${base}/api/auth/employee-login`, { method: "POST", body: JSON.stringify({ employeeId: "emp-1", password: "emp-pass-1" }) });
    assert.equal(ok.status, 200);

    const wrongPass = await fetch(`${base}/api/auth/employee-login`, { method: "POST", body: JSON.stringify({ employeeId: "emp-1", password: "nope" }) });
    assert.equal(wrongPass.status, 401);

    const noCreds = await fetch(`${base}/api/auth/employee-login`, { method: "POST", body: JSON.stringify({ employeeId: "emp-2", password: "anything" }) });
    assert.equal(noCreds.status, 401);
  });
});

// ---- 3. Device enrollment + device login ----

test("POST /api/auth/device-login accepts a valid device token, rejects a revoked one, and rejects garbage", async () => {
  await withTestServer(async (base, db) => {
    upsertEmployee(db, { employeeId: "emp-1", displayName: "Device Owner", department: "Ops" });
    upsertDevice(db, { deviceId: "dev-1" });
    const deviceToken = enrollDevice(db, "emp-1", "dev-1");

    const ok = await fetch(`${base}/api/auth/device-login`, { method: "POST", body: JSON.stringify({ deviceToken }) });
    assert.equal(ok.status, 200);
    const body = (await ok.json()) as any;
    assert.equal(body.employeeId, "emp-1");
    assert.equal(body.deviceId, "dev-1");

    const { revokeDeviceToken } = await import("../src/db/auth-repository.js");
    revokeDeviceToken(db, deviceToken);
    const revoked = await fetch(`${base}/api/auth/device-login`, { method: "POST", body: JSON.stringify({ deviceToken }) });
    assert.equal(revoked.status, 401);

    const garbage = await fetch(`${base}/api/auth/device-login`, { method: "POST", body: JSON.stringify({ deviceToken: "complete-garbage" }) });
    assert.equal(garbage.status, 401);
  });
});

// ---- 4. GET /api/me per role ----

test("GET /api/me reports the correct role and subjectId for admin, employee, and device sessions", async () => {
  await withTestServer(async (base, db) => {
    seedEmployeeWithSession(db, "emp-1", "dev-1", "s1");

    const adminToken = await adminLoginToken(base, db);
    const adminMe = await fetch(`${base}/api/me`, { headers: authHeaders(adminToken) });
    // Admin has no employeeId of its own, so no displayName is attached —
    // only an employee/device session's OWN name is ever exposed here
    // (see server.ts's /api/me handler), never another employee's.
    assert.deepEqual(await adminMe.json(), { role: "admin", subjectId: "admin-1" });

    const empToken = await employeeLoginToken(base, db, "emp-1");
    const empMe = await fetch(`${base}/api/me`, { headers: authHeaders(empToken) });
    assert.deepEqual(await empMe.json(), { role: "employee", subjectId: "emp-1", displayName: "Employee emp-1" });

    const deviceToken = await deviceLoginToken(base, db, "emp-1", "dev-1");
    const deviceMe = await fetch(`${base}/api/me`, { headers: authHeaders(deviceToken) });
    assert.deepEqual(await deviceMe.json(), { role: "device", subjectId: "emp-1", deviceId: "dev-1", displayName: "Employee emp-1" });
  });
});

// ---- 5. Report route scoping ----

test("GET /api/reports/daily: an employee session gets 403 for someone else's employeeId and 200 with real data for their own", async () => {
  await withTestServer(async (base, db) => {
    seedEmployeeWithSession(db, "emp-1", "dev-1", "s1");
    seedEmployeeWithSession(db, "emp-2", "dev-2", "s2");
    const empToken = await employeeLoginToken(base, db, "emp-1");

    const foreign = await fetch(`${base}/api/reports/daily?employeeId=emp-2&date=2026-01-01`, { headers: authHeaders(empToken) });
    assert.equal(foreign.status, 403);

    const own = await fetch(`${base}/api/reports/daily?employeeId=emp-1&date=2026-01-01`, { headers: authHeaders(empToken) });
    assert.equal(own.status, 200);
    const body = (await own.json()) as any;
    assert.equal(body.sessionCount, 1, "must be real recomputed data, not an empty/stubbed body");
  });
});

// ---- 6. Admin-only full employee listing ----

test("GET /api/employees: 403 for an employee session, 200 for an admin session", async () => {
  await withTestServer(async (base, db) => {
    seedEmployeeWithSession(db, "emp-1", "dev-1", "s1");
    const empToken = await employeeLoginToken(base, db, "emp-1");
    const empRes = await fetch(`${base}/api/employees`, { headers: authHeaders(empToken) });
    assert.equal(empRes.status, 403);

    const adminToken = await adminLoginToken(base, db);
    const adminRes = await fetch(`${base}/api/employees`, { headers: authHeaders(adminToken) });
    assert.equal(adminRes.status, 200);
    assert.equal(((await adminRes.json()) as any[]).length, 1);
  });
});

// ---- 7. GET /api/history is forced server-side, not merely validated ----

test("GET /api/history as an employee session only ever returns their own rows, even when no employeeId is passed at all", async () => {
  await withTestServer(async (base, db) => {
    seedEmployeeWithSession(db, "emp-1", "dev-1", "s1");
    seedEmployeeWithSession(db, "emp-2", "dev-2", "s2");
    const empToken = await employeeLoginToken(base, db, "emp-1");

    // No employeeId at all: must still be scoped to emp-1, not "all employees" or an error.
    const noParam = await fetch(`${base}/api/history?dateFrom=2025-12-01&dateTo=2026-01-31`, { headers: authHeaders(empToken) });
    assert.equal(noParam.status, 200);
    const noParamRows = (await noParam.json()) as any[];
    assert.ok(noParamRows.length >= 1);
    assert.ok(noParamRows.every((r) => r.employeeId === "emp-1"), "an employee session must never see another employee's row, even unasked");

    // Explicitly asking for someone else's employeeId must be refused, not silently ignored-and-scoped or silently honored.
    const foreignParam = await fetch(`${base}/api/history?dateFrom=2025-12-01&dateTo=2026-01-31&employeeId=emp-2`, { headers: authHeaders(empToken) });
    assert.equal(foreignParam.status, 403);
  });
});

// ---- 8. Correction-request lifecycle end-to-end over real HTTP ----

test("correction-request lifecycle: submit (own/foreign/wrong-role), admin lists and reviews, re-review is refused", async () => {
  await withTestServer(async (base, db) => {
    seedEmployeeWithSession(db, "emp-1", "dev-1", "s1");
    seedEmployeeWithSession(db, "emp-2", "dev-2", "s2");
    const empToken = await employeeLoginToken(base, db, "emp-1");
    const adminToken = await adminLoginToken(base, db);

    const requestBody = { sessionId: "s1", fieldName: "endReason", requestedValue: "signout", reason: "Actually signed out on time, agent missed it." };

    // Admin hitting the employee-submission endpoint is the wrong role for it.
    const adminSubmit = await fetch(`${base}/api/corrections/request`, { method: "POST", headers: authHeaders(adminToken), body: JSON.stringify(requestBody) });
    assert.equal(adminSubmit.status, 403);

    // An employee requesting a correction on a session that isn't theirs.
    const foreignSubmit = await fetch(`${base}/api/corrections/request`, {
      method: "POST",
      headers: authHeaders(empToken),
      body: JSON.stringify({ ...requestBody, sessionId: "s2" }),
    });
    assert.equal(foreignSubmit.status, 403);

    // The real, legitimate submission on the employee's own session.
    const ownSubmit = await fetch(`${base}/api/corrections/request`, { method: "POST", headers: authHeaders(empToken), body: JSON.stringify(requestBody) });
    assert.equal(ownSubmit.status, 201);
    const submitted = (await ownSubmit.json()) as any;
    assert.equal(submitted.status, "pending");
    assert.equal(submitted.employeeId, "emp-1");

    // Submitting a second request, to be rejected rather than approved below.
    const secondSubmit = await fetch(`${base}/api/corrections/request`, {
      method: "POST",
      headers: authHeaders(empToken),
      body: JSON.stringify({ sessionId: "s1", fieldName: "endReason", requestedValue: "shutdown", reason: "Second, separate request." }),
    });
    assert.equal(secondSubmit.status, 201);
    const secondSubmitted = (await secondSubmit.json()) as any;

    // Employee/device role cannot review or list requests at all.
    const empList = await fetch(`${base}/api/corrections/requests`, { headers: authHeaders(empToken) });
    assert.equal(empList.status, 403);

    // Admin lists pending and sees both.
    const pendingList = await fetch(`${base}/api/corrections/requests?status=pending`, { headers: authHeaders(adminToken) });
    assert.equal(pendingList.status, 200);
    const pendingRows = (await pendingList.json()) as any[];
    assert.ok(pendingRows.some((r) => r.requestId === submitted.requestId));
    assert.ok(pendingRows.some((r) => r.requestId === secondSubmitted.requestId));

    // Admin approves the first: a real resultingCorrectionId must come back.
    const approveRes = await fetch(`${base}/api/corrections/requests/${submitted.requestId}/review`, {
      method: "POST",
      headers: authHeaders(adminToken),
      body: JSON.stringify({ approve: true, note: "Confirmed against the badge log." }),
    });
    assert.equal(approveRes.status, 200);
    const approved = (await approveRes.json()) as any;
    assert.equal(approved.status, "approved");
    assert.ok(approved.resultingCorrectionId, "approving must produce a real attendance_corrections row id");

    // Admin rejects the second: resultingCorrectionId must stay null.
    const rejectRes = await fetch(`${base}/api/corrections/requests/${secondSubmitted.requestId}/review`, {
      method: "POST",
      headers: authHeaders(adminToken),
      body: JSON.stringify({ approve: false, note: "Not enough evidence." }),
    });
    assert.equal(rejectRes.status, 200);
    const rejected = (await rejectRes.json()) as any;
    assert.equal(rejected.status, "rejected");
    assert.equal(rejected.resultingCorrectionId, null);

    // Re-reviewing an already-reviewed request must be refused, not silently re-applied.
    const reReview = await fetch(`${base}/api/corrections/requests/${submitted.requestId}/review`, {
      method: "POST",
      headers: authHeaders(adminToken),
      body: JSON.stringify({ approve: true }),
    });
    assert.equal(reReview.status, 400);

    // An employee/device session cannot approve/reject either, regardless of whose request it is.
    const empReview = await fetch(`${base}/api/corrections/requests/${secondSubmitted.requestId}/review`, {
      method: "POST",
      headers: authHeaders(empToken),
      body: JSON.stringify({ approve: true }),
    });
    assert.equal(empReview.status, 403);
  });
});

// ---- 9. Missing / malformed auth ----

test("a protected route with no Authorization header returns 401, and a malformed/unknown bearer token returns 401 (not a crash)", async () => {
  await withTestServer(async (base, db) => {
    seedEmployeeWithSession(db, "emp-1", "dev-1", "s1");

    const noHeader = await fetch(`${base}/api/me`);
    assert.equal(noHeader.status, 401);

    const unknownToken = await fetch(`${base}/api/me`, { headers: authHeaders("totally-made-up-token") });
    assert.equal(unknownToken.status, 401);

    const malformedHeader = await fetch(`${base}/api/me`, { headers: { Authorization: "NotBearer abc123" } });
    assert.equal(malformedHeader.status, 401);

    const emptyBearer = await fetch(`${base}/api/me`, { headers: { Authorization: "Bearer " } });
    assert.equal(emptyBearer.status, 401);
  });
});

// ---- 10. Expiry is enforced, not schema decoration ----

test("an expired session token is rejected with 401 on the very next request", async () => {
  await withTestServer(async (base, db) => {
    const adminToken = await adminLoginToken(base, db);

    const stillValid = await fetch(`${base}/api/me`, { headers: authHeaders(adminToken) });
    assert.equal(stillValid.status, 200);

    // Age the session's expiry into the past directly on the sessions
    // table — the same manipulation a real clock-forward would cause —
    // to prove resolveSession's expiry check is actually consulted by
    // the HTTP layer on every request, not just at login time.
    db.prepare(`UPDATE sessions SET expires_at_utc = ?`).run("2000-01-01T00:00:00.000Z");

    const expired = await fetch(`${base}/api/me`, { headers: authHeaders(adminToken) });
    assert.equal(expired.status, 401);
  });
});
