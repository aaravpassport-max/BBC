/**
 * Password hashing and opaque session-token generation. Deliberately
 * dependency-free (node:crypto's scrypt, same "small dependency surface
 * on purpose" rule the rest of this codebase follows — see
 * db/repository.ts's own history with node:sqlite vs better-sqlite3).
 *
 * Pure utility functions only — no database access here. The
 * db/auth-repository.ts module is what actually stores/checks these
 * against a database; this file just does the cryptography correctly:
 *   - scrypt with a random per-password salt, constant-time comparison
 *     on verify (timingSafeEqual) so verification time doesn't leak
 *     anything about how much of the password matched.
 *   - session tokens are 256 bits of randomness; only their SHA-256 hash
 *     is ever stored, so a stolen database dump does not hand over usable
 *     session tokens (the same reason passwords are hashed, not stored
 *     plain).
 */

import { randomBytes, scryptSync, timingSafeEqual, createHash } from "node:crypto";

const SCRYPT_KEYLEN = 64;

/** `scrypt$<saltHex>$<hashHex>` — self-describing so a future algorithm change can coexist with old hashes during a rotation. */
export function hashPassword(password: string): string {
  const salt = randomBytes(16);
  const derived = scryptSync(password, salt, SCRYPT_KEYLEN);
  return `scrypt$${salt.toString("hex")}$${derived.toString("hex")}`;
}

export function verifyPassword(password: string, stored: string): boolean {
  const parts = stored.split("$");
  if (parts.length !== 3 || parts[0] !== "scrypt") return false;
  const salt = Buffer.from(parts[1]!, "hex");
  const expected = Buffer.from(parts[2]!, "hex");
  const actual = scryptSync(password, salt, expected.length);
  if (actual.length !== expected.length) return false;
  return timingSafeEqual(actual, expected);
}

/** A random opaque token to hand to the client. Never stored anywhere — only hashToken()'s output is. */
export function generateToken(): string {
  return randomBytes(32).toString("base64url");
}

export function hashToken(token: string): string {
  return createHash("sha256").update(token).digest("hex");
}
