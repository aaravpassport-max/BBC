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
  addClassificationRule,
} from "../src/db/repository.js";
import { createAdminUser, loginAdmin } from "../src/db/auth-repository.js";
import { ev, t } from "./helpers.js";

/** Every route but /api/health and /api/auth/* requires a session — these tests are proving the DATA layer, so they authenticate as an admin (unrestricted) up front rather than re-proving auth in every single case; auth itself is covered by auth-and-rbac.test.ts. */
function authHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}` };
}
function adminToken(db: DatabaseSync): string {
  createAdminUser(db, { adminId: "admin-1", displayName: "Test Admin", email: "admin@example.com", password: "correct-horse-battery-staple" });
  return loginAdmin(db, "admin@example.com", "correct-horse-battery-staple").token;
}

/**
 * These tests exercise the real HTTP layer (a real socket, real fetch
 * calls) against the real router in api/server.ts — not a mocked
 * request/response pair — because the manual curl session that first
 * validated this endpoint caught two real bugs (the seed script's
 * off-by-one month, and recomputeSession never closing the session row)
 * that a handler-level unit test calling functions directly might not
 * have exercised the same way. This is that same proof, made repeatable.
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

function seed(db: DatabaseSync) {
  upsertEmployee(db, { employeeId: "emp-1", displayName: "Test Employee", department: "Ops" });
  upsertDevice(db, { deviceId: "dev-1" });
  recordNoticeShown(db, "emp-1", "dev-1", "policy-v1");
  recordNoticeAcknowledged(db, "emp-1", "dev-1");
}

test("GET /api/health returns ok", async () => {
  await withTestServer(async (base) => {
    const res = await fetch(`${base}/api/health`);
    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), { ok: true });
  });
});

test("GET /api/employees returns seeded employees", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const token = adminToken(db);
    const res = await fetch(`${base}/api/employees`, { headers: authHeaders(token) });
    assert.equal(res.status, 200);
    const body = (await res.json()) as any[];
    assert.equal(body.length, 1);
    assert.equal(body[0].employeeId, "emp-1");
    assert.equal(body[0].department, "Ops");
  });
});

test("GET /api/employees/:id/sessions requires a well-formed date query param", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const token = adminToken(db);
    const missing = await fetch(`${base}/api/employees/emp-1/sessions`, { headers: authHeaders(token) });
    assert.equal(missing.status, 400);
    const malformed = await fetch(`${base}/api/employees/emp-1/sessions?date=19-10-2026`, { headers: authHeaders(token) });
    assert.equal(malformed.status, 400);
  });
});

test("GET /api/sessions/:id returns 404 for an unknown session", async () => {
  await withTestServer(async (base, db) => {
    const token = adminToken(db);
    const res = await fetch(`${base}/api/sessions/does-not-exist`, { headers: authHeaders(token) });
    assert.equal(res.status, 404);
  });
});

test("GET /api/sessions/:id returns the real recomputed intervals and usage summary — proves the full pipeline end to end over real HTTP", async () => {
  await withTestServer(async (base, db) => {
    seed(db);
    const token = adminToken(db);
    const events = [
      ev("session.signin", t("09:00"), { eventId: "e1", employeeId: "emp-1", deviceId: "dev-1", sessionId: "s1" }),
      ev("input.activity", t("09:00"), { eventId: "e2", employeeId: "emp-1", deviceId: "dev-1", sessionId: "s1" }),
      ev("app.foreground_start", t("09:00"), { eventId: "e3", employeeId: "emp-1", deviceId: "dev-1", sessionId: "s1", payload: { executableName: "code.exe" } }),
      ev("input.activity", t("17:00"), { eventId: "e4", employeeId: "emp-1", deviceId: "dev-1", sessionId: "s1" }),
      ev("session.signout", t("17:30"), { eventId: "e5", employeeId: "emp-1", deviceId: "dev-1", sessionId: "s1" }),
    ];
    ingestRawEvents(db, events as any);
    recomputeSession(db, "s1");
    recomputeUsage(db, "s1");
    addClassificationRule(db, { ruleId: "r1", target: "app:code.exe", classification: "productive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-1" });

    const sessionsRes = await fetch(`${base}/api/employees/emp-1/sessions?date=2026-01-01`, { headers: authHeaders(token) });
    assert.equal(sessionsRes.status, 200);
    const sessions = (await sessionsRes.json()) as any[];
    assert.equal(sessions.length, 1);
    assert.equal(sessions[0].endUtc, t("17:30"), "the session-close fix must be reflected over real HTTP, not just in-process");
    assert.equal(sessions[0].endReason, "signout");

    const detailRes = await fetch(`${base}/api/sessions/s1`, { headers: authHeaders(token) });
    assert.equal(detailRes.status, 200);
    const detail = (await detailRes.json()) as any;
    assert.ok(detail.stateIntervals.length > 0);
    assert.equal(detail.appIntervals.length, 1);
    assert.equal(detail.appIntervals[0].executableName, "code.exe");
    // code.exe's interval is open-ended (one foreground_start, no end), so
    // it's summed as running until the SESSION's end (17:30, the
    // session.signout time) — not until the last input.activity (17:00).
    assert.equal(detail.usageSummary.byClassification.productive, (17.5 - 9) * 3600000);
  });
});

test("GET /api/classification-rules returns what was added", async () => {
  await withTestServer(async (base, db) => {
    const token = adminToken(db);
    addClassificationRule(db, { ruleId: "r1", target: "domain:github.com", classification: "productive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-1" });
    const res = await fetch(`${base}/api/classification-rules`, { headers: authHeaders(token) });
    const body = (await res.json()) as any[];
    assert.equal(body.length, 1);
    assert.equal(body[0].target, "domain:github.com");
  });
});

test("an unsupported HTTP method returns 405, an unauthenticated request returns 401, and an unknown route returns 404", async () => {
  await withTestServer(async (base, db) => {
    const putRes = await fetch(`${base}/api/employees`, { method: "PUT" });
    assert.equal(putRes.status, 405);

    const noAuthRes = await fetch(`${base}/api/employees`);
    assert.equal(noAuthRes.status, 401);

    const token = adminToken(db);
    const unknownRes = await fetch(`${base}/api/not-a-real-route`, { headers: authHeaders(token) });
    assert.equal(unknownRes.status, 404);
  });
});
