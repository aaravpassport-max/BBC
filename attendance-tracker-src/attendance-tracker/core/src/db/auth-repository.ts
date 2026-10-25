/**
 * Role-based access: admin login, employee login, the "trusted device"
 * token a local agent/dashboard uses in place of a password, session
 * resolution, and employee-submitted correction REQUESTS (distinct from
 * attendance_corrections, which stays admin-authored only — see
 * 005_auth.sql's header comment for why that boundary exists).
 *
 * Every function here is a thin, "dumb" wrapper the same way
 * repository.ts is: the cryptography lives in engine/auth.ts, this file
 * only ever stores/looks up rows and enforces the access rules
 * (an employee session resolves to exactly one employeeId; it is never
 * trusted to say which employeeId it wants to act as).
 */

import { DatabaseSync } from "node:sqlite";
import { randomUUID } from "node:crypto";
import { hashPassword, verifyPassword, generateToken, hashToken } from "../engine/auth.js";

type Database = DatabaseSync;

const SESSION_TTL_MS = 12 * 60 * 60 * 1000; // 12 hours for a web login
const DEVICE_SESSION_TTL_MS = 30 * 24 * 60 * 60 * 1000; // 30 days — the local tray app shouldn't force a re-login mid-shift or even mid-month

export type Role = "admin" | "employee" | "device";

export interface AuthSession {
  role: Role;
  /** adminId for role=admin; employeeId for role=employee and role=device. */
  subjectId: string;
  /** set only for role=device — the specific device this trusted session was minted for. */
  deviceId?: string;
}

export class AuthError extends Error {}

// ---- Admin accounts ----

export function createAdminUser(db: Database, input: { adminId: string; displayName: string; email: string; password: string }): void {
  db.prepare(
    `INSERT INTO admin_users (admin_id, display_name, email, password_hash, created_at_utc) VALUES (?, ?, ?, ?, ?)`,
  ).run(input.adminId, input.displayName, input.email.toLowerCase(), hashPassword(input.password), new Date().toISOString());
}

export function loginAdmin(db: Database, email: string, password: string): { token: string; adminId: string; displayName: string } {
  const row = db.prepare(`SELECT admin_id, display_name, password_hash FROM admin_users WHERE email = ?`).get(email.toLowerCase()) as
    | { admin_id: string; display_name: string; password_hash: string }
    | undefined;
  if (!row || !verifyPassword(password, row.password_hash)) {
    throw new AuthError("Invalid email or password.");
  }
  const token = createSession(db, "admin", row.admin_id);
  return { token, adminId: row.admin_id, displayName: row.display_name };
}

// ---- Employee web-dashboard accounts ----

/** Admin-provisioned (or self-service, if the product offers that) — an employee cannot grant themselves credentials. */
export function setEmployeePassword(db: Database, employeeId: string, password: string): void {
  const now = new Date().toISOString();
  db.prepare(
    `INSERT INTO employee_credentials (employee_id, password_hash, created_at_utc, updated_at_utc)
     VALUES (?, ?, ?, ?)
     ON CONFLICT(employee_id) DO UPDATE SET password_hash = excluded.password_hash, updated_at_utc = excluded.updated_at_utc`,
  ).run(employeeId, hashPassword(password), now, now);
}

export function loginEmployee(db: Database, employeeId: string, password: string): { token: string; employeeId: string; displayName: string } {
  const cred = db.prepare(`SELECT password_hash FROM employee_credentials WHERE employee_id = ?`).get(employeeId) as
    | { password_hash: string }
    | undefined;
  if (!cred || !verifyPassword(password, cred.password_hash)) {
    throw new AuthError("Invalid employee ID or password.");
  }
  const emp = db.prepare(`SELECT display_name FROM employees WHERE employee_id = ?`).get(employeeId) as { display_name: string } | undefined;
  const token = createSession(db, "employee", employeeId);
  return { token, employeeId, displayName: emp?.display_name ?? employeeId };
}

// ---- Trusted device identity (the "no repeated login on your own PC" path) ----

/** Minted once at install/enrollment time — handed to the agent, which stores it locally. Never re-derivable from the hash alone. */
export function enrollDevice(db: Database, employeeId: string, deviceId: string): string {
  const token = generateToken();
  db.prepare(
    `INSERT INTO device_tokens (token_hash, employee_id, device_id, created_at_utc) VALUES (?, ?, ?, ?)`,
  ).run(hashToken(token), employeeId, deviceId, new Date().toISOString());
  return token;
}

export function revokeDeviceToken(db: Database, token: string): void {
  db.prepare(`UPDATE device_tokens SET revoked_at_utc = ? WHERE token_hash = ?`).run(new Date().toISOString(), hashToken(token));
}

/** The local tray app calls this (instead of a password prompt) to get a short-lived API session from its long-lived device token. */
export function loginDevice(db: Database, deviceToken: string): { token: string; employeeId: string; deviceId: string } {
  const row = db.prepare(`SELECT employee_id, device_id, revoked_at_utc FROM device_tokens WHERE token_hash = ?`).get(hashToken(deviceToken)) as
    | { employee_id: string; device_id: string; revoked_at_utc: string | null }
    | undefined;
  if (!row || row.revoked_at_utc) {
    throw new AuthError("This device is not enrolled, or its trusted identity has been revoked. Re-enroll from the admin dashboard.");
  }
  db.prepare(`UPDATE device_tokens SET last_used_at_utc = ? WHERE token_hash = ?`).run(new Date().toISOString(), hashToken(deviceToken));
  const token = createSession(db, "device", row.employee_id, row.device_id);
  return { token, employeeId: row.employee_id, deviceId: row.device_id };
}

// ---- Sessions ----

function createSession(db: Database, role: Role, subjectId: string, deviceId?: string): string {
  const token = generateToken();
  const now = Date.now();
  const ttl = role === "device" ? DEVICE_SESSION_TTL_MS : SESSION_TTL_MS;
  db.prepare(
    `INSERT INTO sessions (token_hash, subject_type, subject_id, device_id, created_at_utc, expires_at_utc) VALUES (?, ?, ?, ?, ?, ?)`,
  ).run(hashToken(token), role, subjectId, deviceId ?? null, new Date(now).toISOString(), new Date(now + ttl).toISOString());
  return token;
}

/**
 * Resolves a bearer token to a role + subject, or null if it's missing,
 * expired, or revoked. This is the ONLY function the API layer should
 * trust to say who's calling — never an employeeId/role taken from a
 * query parameter or request body.
 */
export function resolveSession(db: Database, token: string): AuthSession | null {
  const row = db.prepare(`SELECT subject_type, subject_id, device_id, expires_at_utc, revoked_at_utc FROM sessions WHERE token_hash = ?`).get(
    hashToken(token),
  ) as { subject_type: Role; subject_id: string; device_id: string | null; expires_at_utc: string; revoked_at_utc: string | null } | undefined;
  if (!row) return null;
  if (row.revoked_at_utc) return null;
  if (row.expires_at_utc < new Date().toISOString()) return null;
  return { role: row.subject_type, subjectId: row.subject_id, ...(row.device_id ? { deviceId: row.device_id } : {}) };
}

export function revokeSession(db: Database, token: string): void {
  db.prepare(`UPDATE sessions SET revoked_at_utc = ? WHERE token_hash = ?`).run(new Date().toISOString(), hashToken(token));
}

// ---- Employee-submitted correction requests ----

export interface CorrectionRequestInput {
  employeeId: string;
  sessionId: string;
  fieldName: string;
  requestedValue: string;
  reason: string;
}

export interface CorrectionRequestRow {
  requestId: string;
  employeeId: string;
  sessionId: string;
  fieldName: string;
  requestedValue: string;
  reason: string;
  status: "pending" | "approved" | "rejected";
  createdAtUtc: string;
  reviewedByAdminId: string | null;
  reviewedAtUtc: string | null;
  reviewNote: string | null;
  resultingCorrectionId: string | null;
}

/**
 * An employee asks for a correction; nothing in the derived data changes
 * yet. The caller MUST have already verified `input.sessionId` belongs
 * to `input.employeeId` (the API layer does this via resolveSession +
 * getSessionById) — this function trusts its inputs exactly as far as a
 * private module function should, not as far as an untrusted request.
 */
export function submitCorrectionRequest(db: Database, input: CorrectionRequestInput): CorrectionRequestRow {
  const requestId = randomUUID();
  const now = new Date().toISOString();
  db.prepare(
    `INSERT INTO correction_requests (request_id, employee_id, session_id, field_name, requested_value, reason, status, created_at_utc)
     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)`,
  ).run(requestId, input.employeeId, input.sessionId, input.fieldName, input.requestedValue, input.reason, now);
  return getCorrectionRequest(db, requestId)!;
}

export function getCorrectionRequest(db: Database, requestId: string): CorrectionRequestRow | null {
  const row = db.prepare(`SELECT * FROM correction_requests WHERE request_id = ?`).get(requestId) as RawCorrectionRequestRow | undefined;
  return row ? mapCorrectionRequestRow(row) : null;
}

export function listCorrectionRequests(db: Database, filters: { employeeId?: string; status?: "pending" | "approved" | "rejected" } = {}): CorrectionRequestRow[] {
  const clauses: string[] = [];
  const params: string[] = [];
  if (filters.employeeId) { clauses.push("employee_id = ?"); params.push(filters.employeeId); }
  if (filters.status) { clauses.push("status = ?"); params.push(filters.status); }
  const where = clauses.length ? `WHERE ${clauses.join(" AND ")}` : "";
  const rows = db.prepare(`SELECT * FROM correction_requests ${where} ORDER BY created_at_utc DESC`).all(...params) as unknown as RawCorrectionRequestRow[];
  return rows.map(mapCorrectionRequestRow);
}

/**
 * Approving a request is the ONLY path by which an employee's input ever
 * results in a real attendance_corrections row — and even then, it's an
 * admin's action (adminId) that creates it, preserving the existing
 * admin-authored-only invariant on that table. Rejecting leaves the
 * derived data completely untouched.
 */
export function reviewCorrectionRequest(
  db: Database,
  requestId: string,
  decision: { approve: boolean; adminId: string; note?: string },
): CorrectionRequestRow {
  const existing = getCorrectionRequest(db, requestId);
  if (!existing) throw new Error(`No correction request with id ${requestId}`);
  if (existing.status !== "pending") throw new Error(`Correction request ${requestId} has already been reviewed (status=${existing.status}).`);

  const now = new Date().toISOString();
  let resultingCorrectionId: string | null = null;
  if (decision.approve) {
    resultingCorrectionId = randomUUID();
    db.exec("BEGIN");
    try {
      db.prepare(
        `INSERT INTO attendance_corrections (correction_id, session_id, field_name, original_value, corrected_value, reason, admin_id, created_at_utc)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?)`,
      ).run(resultingCorrectionId, existing.sessionId, existing.fieldName, existing.requestedValue, `Employee-requested: ${existing.reason}`, decision.adminId, now);
      db.prepare(
        `UPDATE correction_requests SET status = 'approved', reviewed_by_admin_id = ?, reviewed_at_utc = ?, review_note = ?, resulting_correction_id = ? WHERE request_id = ?`,
      ).run(decision.adminId, now, decision.note ?? null, resultingCorrectionId, requestId);
      db.exec("COMMIT");
    } catch (err) {
      db.exec("ROLLBACK");
      throw err;
    }
  } else {
    db.prepare(
      `UPDATE correction_requests SET status = 'rejected', reviewed_by_admin_id = ?, reviewed_at_utc = ?, review_note = ? WHERE request_id = ?`,
    ).run(decision.adminId, now, decision.note ?? null, requestId);
  }
  return getCorrectionRequest(db, requestId)!;
}

interface RawCorrectionRequestRow {
  request_id: string;
  employee_id: string;
  session_id: string;
  field_name: string;
  requested_value: string;
  reason: string;
  status: "pending" | "approved" | "rejected";
  created_at_utc: string;
  reviewed_by_admin_id: string | null;
  reviewed_at_utc: string | null;
  review_note: string | null;
  resulting_correction_id: string | null;
}

function mapCorrectionRequestRow(row: RawCorrectionRequestRow): CorrectionRequestRow {
  return {
    requestId: row.request_id,
    employeeId: row.employee_id,
    sessionId: row.session_id,
    fieldName: row.field_name,
    requestedValue: row.requested_value,
    reason: row.reason,
    status: row.status,
    createdAtUtc: row.created_at_utc,
    reviewedByAdminId: row.reviewed_by_admin_id,
    reviewedAtUtc: row.reviewed_at_utc,
    reviewNote: row.review_note,
    resultingCorrectionId: row.resulting_correction_id,
  };
}
