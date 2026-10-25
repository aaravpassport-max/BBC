import { test } from "node:test";
import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import { runMigrations } from "../src/db/migrate.js";
import { upsertEmployee, upsertDevice, recordNoticeShown, recordNoticeAcknowledged, hasAcknowledgedNotice, ingestRawEvents, recomputeSession } from "../src/db/repository.js";
import { hashPassword, verifyPassword } from "../src/engine/auth.js";
import {
  createAdminUser,
  loginAdmin,
  setEmployeePassword,
  loginEmployee,
  enrollDevice,
  revokeDeviceToken,
  loginDevice,
  resolveSession,
  revokeSession,
  submitCorrectionRequest,
  getCorrectionRequest,
  listCorrectionRequests,
  reviewCorrectionRequest,
  AuthError,
} from "../src/db/auth-repository.js";
import { ev, t } from "./helpers.js";

/**
 * Direct, no-HTTP tests against auth-repository.ts (and the pure crypto
 * helpers it builds on) for things that are awkward or impossible to
 * isolate at the HTTP layer: hash round-tripping, tampered-hash
 * rejection, and session-expiry enforcement via direct row manipulation.
 * The HTTP-level RBAC behavior lives in auth-and-rbac.test.ts — this file
 * is the "prove the trust boundary itself is sound" half.
 */

function freshDb(): DatabaseSync {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  runMigrations(db);
  return db;
}

function seedEmployee(db: DatabaseSync, employeeId = "emp-1") {
  upsertEmployee(db, { employeeId, displayName: "Test Employee", department: "Ops" });
}

// ---- engine/auth.ts: pure crypto helpers ----

test("hashPassword/verifyPassword round-trips correctly and rejects the wrong password", () => {
  const hash = hashPassword("correct-horse-battery-staple");
  assert.equal(verifyPassword("correct-horse-battery-staple", hash), true);
  assert.equal(verifyPassword("wrong-password", hash), false);
});

test("verifyPassword rejects a tampered hash instead of throwing", () => {
  const hash = hashPassword("correct-horse-battery-staple");
  const [scheme, salt, derived] = hash.split("$");
  // Flip the last hex character of the derived key — a bit-for-bit
  // different stored hash, the same shape a corrupted/edited DB row
  // would have. This must fail closed, not throw an unhandled error.
  const tamperedChar = derived!.at(-1) === "0" ? "1" : "0";
  const tampered = `${scheme}$${salt}$${derived!.slice(0, -1)}${tamperedChar}`;
  assert.equal(verifyPassword("correct-horse-battery-staple", tampered), false);
});

test("verifyPassword rejects a malformed stored value without throwing", () => {
  assert.equal(verifyPassword("anything", "not-a-real-hash"), false);
  assert.equal(verifyPassword("anything", ""), false);
});

// ---- Admin login ----

test("loginAdmin succeeds with correct credentials and fails with a wrong password or unknown email", () => {
  const db = freshDb();
  createAdminUser(db, { adminId: "admin-1", displayName: "Root Admin", email: "Admin@Example.com", password: "s3cret-pass" });

  const result = loginAdmin(db, "admin@example.com", "s3cret-pass"); // email lookup is case-insensitive by design
  assert.equal(result.adminId, "admin-1");
  assert.equal(result.displayName, "Root Admin");
  assert.ok(result.token.length > 0);

  assert.throws(() => loginAdmin(db, "admin@example.com", "wrong-pass"), AuthError);
  assert.throws(() => loginAdmin(db, "nobody@example.com", "s3cret-pass"), AuthError);
});

// ---- Employee login ----

test("loginEmployee succeeds/fails analogously, and an employee with no credentials row cannot log in at all", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1");
  seedEmployee(db, "emp-2"); // never given a password
  setEmployeePassword(db, "emp-1", "emp-pass-1");

  const result = loginEmployee(db, "emp-1", "emp-pass-1");
  assert.equal(result.employeeId, "emp-1");
  assert.equal(result.displayName, "Test Employee");

  assert.throws(() => loginEmployee(db, "emp-1", "wrong-pass"), AuthError);
  assert.throws(() => loginEmployee(db, "emp-2", "anything"), AuthError, "emp-2 has no employee_credentials row");
  assert.throws(() => loginEmployee(db, "no-such-employee", "anything"), AuthError);
});

// ---- Device enrollment / device login ----

test("loginDevice accepts a valid token, rejects a revoked one, and rejects unknown/garbage tokens", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1");
  upsertDevice(db, { deviceId: "dev-1" });

  const deviceToken = enrollDevice(db, "emp-1", "dev-1");
  const result = loginDevice(db, deviceToken);
  assert.equal(result.employeeId, "emp-1");
  assert.equal(result.deviceId, "dev-1");

  revokeDeviceToken(db, deviceToken);
  assert.throws(() => loginDevice(db, deviceToken), AuthError, "revoked device tokens must stop working");

  assert.throws(() => loginDevice(db, "complete-garbage-not-a-real-token"), AuthError);
});

// ---- Session resolution: role/subject correctness and expiry enforcement ----

test("resolveSession reports the correct role/subjectId/deviceId for each of the three login types", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1");
  upsertDevice(db, { deviceId: "dev-1" });
  createAdminUser(db, { adminId: "admin-1", displayName: "Root Admin", email: "admin@example.com", password: "s3cret-pass" });
  setEmployeePassword(db, "emp-1", "emp-pass-1");
  const deviceToken = enrollDevice(db, "emp-1", "dev-1");

  const adminLogin = loginAdmin(db, "admin@example.com", "s3cret-pass");
  const adminSession = resolveSession(db, adminLogin.token);
  assert.deepEqual(adminSession, { role: "admin", subjectId: "admin-1" });

  const employeeLogin = loginEmployee(db, "emp-1", "emp-pass-1");
  const employeeSession = resolveSession(db, employeeLogin.token);
  assert.deepEqual(employeeSession, { role: "employee", subjectId: "emp-1" });

  const deviceLogin = loginDevice(db, deviceToken);
  const deviceSession = resolveSession(db, deviceLogin.token);
  assert.deepEqual(deviceSession, { role: "device", subjectId: "emp-1", deviceId: "dev-1" });
});

test("resolveSession returns null for a revoked session and for an unknown token", () => {
  const db = freshDb();
  createAdminUser(db, { adminId: "admin-1", displayName: "Root Admin", email: "admin@example.com", password: "s3cret-pass" });
  const { token } = loginAdmin(db, "admin@example.com", "s3cret-pass");

  assert.ok(resolveSession(db, token) !== null);
  revokeSession(db, token);
  assert.equal(resolveSession(db, token), null);
  assert.equal(resolveSession(db, "garbage-token-never-issued"), null);
});

test("resolveSession returns null once expires_at_utc has passed — proves expiry is enforced, not schema decoration", () => {
  const db = freshDb();
  createAdminUser(db, { adminId: "admin-1", displayName: "Root Admin", email: "admin@example.com", password: "s3cret-pass" });
  const { token } = loginAdmin(db, "admin@example.com", "s3cret-pass");
  assert.ok(resolveSession(db, token) !== null);

  // Directly age the session's expiry into the past, the same way the
  // HTTP-level expiry test does — this isolates the DB-layer behavior
  // from any HTTP routing concerns.
  db.prepare(`UPDATE sessions SET expires_at_utc = ? WHERE token_hash = (SELECT token_hash FROM sessions LIMIT 1)`).run(
    "2000-01-01T00:00:00.000Z",
  );
  assert.equal(resolveSession(db, token), null);
});

// ---- Correction-request lifecycle (DB layer only) ----

function seedSessionForCorrection(db: DatabaseSync, employeeId: string, deviceId: string, sessionId: string) {
  upsertDevice(db, { deviceId });
  if (!hasAcknowledgedNotice(db, employeeId, deviceId)) {
    recordNoticeShown(db, employeeId, deviceId, "v1");
    recordNoticeAcknowledged(db, employeeId, deviceId);
  }
  ingestRawEvents(db, [
    ev("session.signin", t("09:00"), { eventId: `${sessionId}-in`, employeeId, deviceId, sessionId }),
    ev("session.signout", t("17:00"), { eventId: `${sessionId}-out`, employeeId, deviceId, sessionId }),
  ] as any);
  recomputeSession(db, sessionId);
}

test("correction-request lifecycle: submit, list with filters, approve creates a resulting correction, reject does not", () => {
  const db = freshDb();
  seedEmployee(db, "emp-1");
  seedSessionForCorrection(db, "emp-1", "dev-1", "corr-s1");
  seedSessionForCorrection(db, "emp-1", "dev-1", "corr-s2");

  const submitted = submitCorrectionRequest(db, {
    employeeId: "emp-1",
    sessionId: "corr-s1",
    fieldName: "endReason",
    requestedValue: "signout",
    reason: "Forgot to sign out properly, this was a real end-of-shift.",
  });
  assert.equal(submitted.status, "pending");
  assert.equal(getCorrectionRequest(db, submitted.requestId)?.status, "pending");

  const rejectedCandidate = submitCorrectionRequest(db, {
    employeeId: "emp-1",
    sessionId: "corr-s2",
    fieldName: "endReason",
    requestedValue: "shutdown",
    reason: "My laptop actually crashed here.",
  });

  const pendingList = listCorrectionRequests(db, { status: "pending" });
  assert.equal(pendingList.length, 2);
  const forEmp1 = listCorrectionRequests(db, { employeeId: "emp-1", status: "pending" });
  assert.equal(forEmp1.length, 2);

  const approved = reviewCorrectionRequest(db, submitted.requestId, { approve: true, adminId: "admin-1", note: "Looks right." });
  assert.equal(approved.status, "approved");
  assert.ok(approved.resultingCorrectionId, "approving must produce a real attendance_corrections row id");
  assert.equal(approved.reviewedByAdminId, "admin-1");

  const rejected = reviewCorrectionRequest(db, rejectedCandidate.requestId, { approve: false, adminId: "admin-1", note: "Not enough evidence." });
  assert.equal(rejected.status, "rejected");
  assert.equal(rejected.resultingCorrectionId, null, "rejecting must leave derived data untouched");

  // Re-reviewing either one must be refused, not silently re-applied.
  assert.throws(() => reviewCorrectionRequest(db, submitted.requestId, { approve: true, adminId: "admin-1" }));
  assert.throws(() => reviewCorrectionRequest(db, rejectedCandidate.requestId, { approve: true, adminId: "admin-1" }));

  // listCorrectionRequests' status filter must reflect the new statuses, not just "pending" at submit time.
  assert.equal(listCorrectionRequests(db, { status: "pending" }).length, 0);
  assert.equal(listCorrectionRequests(db, { status: "approved" }).length, 1);
  assert.equal(listCorrectionRequests(db, { status: "rejected" }).length, 1);
});
