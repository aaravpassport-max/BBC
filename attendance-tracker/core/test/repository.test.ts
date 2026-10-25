import { test } from "node:test";
import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import { runMigrations } from "../src/db/migrate.js";
import {
  upsertEmployee,
  upsertDevice,
  recordNoticeShown,
  recordNoticeAcknowledged,
  hasAcknowledgedNotice,
  ingestRawEvent,
  ingestRawEvents,
  getEventsForSession,
  recomputeSession,
  getStateIntervals,
  getBreakIntervals,
  recomputeUsage,
  getAppForegroundIntervals,
  getBrowserUrlIntervals,
  addClassificationRule,
  endClassificationRule,
  getAllClassificationRules,
  getSessionById,
  TransparencyGateError,
} from "../src/db/repository.js";
import { ev, t } from "./helpers.js";

function freshDb() {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  runMigrations(db);
  return db;
}

function seedEmployeeDeviceAndNotice(db: ReturnType<typeof freshDb>, employeeId = "emp-1", deviceId = "dev-1") {
  upsertEmployee(db, { employeeId, displayName: "Test Employee" });
  upsertDevice(db, { deviceId, hostname: "TEST-PC" });
  recordNoticeShown(db, employeeId, deviceId, "policy-v1");
  recordNoticeAcknowledged(db, employeeId, deviceId);
}

test("migrations are idempotent: running them twice applies nothing the second time", () => {
  const db = freshDb();
  const second = runMigrations(db);
  assert.deepEqual(second.applied, [], "no migration should re-apply on a second call");
  db.close();
});

test("transparency gate: ingestion is refused when no notice has been acknowledged", () => {
  const db = freshDb();
  upsertEmployee(db, { employeeId: "emp-1", displayName: "Test Employee" });
  upsertDevice(db, { deviceId: "dev-1" });
  // Deliberately do NOT call recordNoticeShown/recordNoticeAcknowledged.

  assert.throws(
    () => ingestRawEvent(db, ev("input.activity", t("09:00")) as any),
    TransparencyGateError,
    "ingestion must be refused with no acknowledgement row at all",
  );

  db.close();
});

test("transparency gate: ingestion is refused when notice was shown but not yet acknowledged", () => {
  const db = freshDb();
  upsertEmployee(db, { employeeId: "emp-1", displayName: "Test Employee" });
  upsertDevice(db, { deviceId: "dev-1" });
  recordNoticeShown(db, "emp-1", "dev-1", "policy-v1");
  // Shown, but employee has not dismissed/acknowledged it yet.

  assert.throws(
    () => ingestRawEvent(db, ev("input.activity", t("09:00")) as any),
    TransparencyGateError,
    "a merely-shown (not acknowledged) notice must still block ingestion",
  );
  assert.equal(hasAcknowledgedNotice(db, "emp-1", "dev-1"), false);

  db.close();
});

test("transparency gate: ingestion succeeds once the notice is acknowledged", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);
  assert.equal(hasAcknowledgedNotice(db, "emp-1", "dev-1"), true);

  const result = ingestRawEvent(db, ev("input.activity", t("09:00")) as any);
  assert.equal(result.inserted, true);

  db.close();
});

test("acknowledging a notice twice without a new recordNoticeShown call throws rather than silently succeeding", () => {
  const db = freshDb();
  upsertEmployee(db, { employeeId: "emp-1", displayName: "Test Employee" });
  upsertDevice(db, { deviceId: "dev-1" });
  recordNoticeShown(db, "emp-1", "dev-1", "policy-v1");
  recordNoticeAcknowledged(db, "emp-1", "dev-1");

  assert.throws(() => recordNoticeAcknowledged(db, "emp-1", "dev-1"));
  db.close();
});

test("ingestion is idempotent: inserting the same event twice results in exactly one stored row", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  const event = ev("input.activity", t("09:00"), { eventId: "fixed-id-1" });
  const first = ingestRawEvent(db, event as any);
  const second = ingestRawEvent(db, event as any);

  assert.equal(first.inserted, true);
  assert.equal(second.inserted, false, "re-ingesting the same eventId must be a no-op, not a duplicate");

  const stored = getEventsForSession(db, "sess-1");
  assert.equal(stored.length, 1);

  db.close();
});

test("batch ingestion of out-of-order-arrival events still stores all distinct events", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  const events = [
    ev("session.signin", t("09:00"), { eventId: "a" }),
    ev("input.activity", t("09:00"), { eventId: "b" }),
    ev("session.signout", t("17:00"), { eventId: "c" }),
  ];
  const results = ingestRawEvents(db, events as any);
  assert.equal(results.filter((r) => r.inserted).length, 3);

  db.close();
});

test("REPRODUCIBILITY GUARANTEE: recomputing a session twice from the same raw events yields byte-identical derived rows", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  // The spec's own worked example, persisted and recomputed through the
  // real DB layer this time, not just the in-memory engine directly.
  // Deliberately sparse input after 10:12 (nothing until end of day) —
  // this means the 10:12->17:59 gap is ITSELF correctly a second, much
  // longer break. That's realistic and correct, not a bug; this test
  // isn't claiming the whole day has only one break, only that (a) the
  // specific 12-minute break from the spec's worked example round-trips
  // correctly through the DB, and (b) recomputation is deterministic.
  const events = [
    ev("session.signin", t("09:55"), { eventId: "e1" }),
    ev("input.activity", t("10:00"), { eventId: "e2" }),
    ev("input.activity", t("10:12"), { eventId: "e3" }),
    ev("input.activity", t("17:59"), { eventId: "e3b" }),
    ev("session.signout", t("18:00"), { eventId: "e4" }),
  ];
  ingestRawEvents(db, events as any);

  const firstRun = recomputeSession(db, "sess-1");
  const firstStates = getStateIntervals(db, "sess-1");
  const firstBreaks = getBreakIntervals(db, "sess-1");

  // Recompute again — nothing in raw_events changed.
  const secondRun = recomputeSession(db, "sess-1");
  const secondStates = getStateIntervals(db, "sess-1");
  const secondBreaks = getBreakIntervals(db, "sess-1");

  assert.deepEqual(firstRun, secondRun, "recompute summary counts must match exactly");
  assert.deepEqual(firstStates, secondStates, "state_intervals must be identical after a second recompute");
  assert.deepEqual(firstBreaks, secondBreaks, "break_intervals must be identical after a second recompute");

  // And the actual values must be correct, not just self-consistent —
  // re-assert the spec example's 12-minute break specifically survived
  // the DB round trip, among whatever other (correctly detected) breaks exist.
  assert.equal(firstBreaks.length, 2, "the 10:00-10:12 break and the 10:12-17:59 gap are both genuine breaks");
  const specExampleBreak = firstBreaks.find((b) => b.startUtc === t("10:00"));
  assert.ok(specExampleBreak, "the spec example's break must be present");
  assert.equal(specExampleBreak!.endUtc, t("10:12"));

  db.close();
});

test("recomputing after a NEW event is ingested correctly changes the derived output (not stuck on stale cache)", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  ingestRawEvent(db, ev("session.signin", t("09:00"), { eventId: "e1" }) as any);
  ingestRawEvent(db, ev("input.activity", t("09:00"), { eventId: "e2" }) as any);
  recomputeSession(db, "sess-1");
  const beforeSignout = getStateIntervals(db, "sess-1");
  assert.ok(beforeSignout.every((s) => s.state !== "signed_out" || s.endUtc === null), "session should still be open");

  ingestRawEvent(db, ev("session.signout", t("17:00"), { eventId: "e3" }) as any);
  recomputeSession(db, "sess-1");
  const afterSignout = getStateIntervals(db, "sess-1");
  const signedOutIntervals = afterSignout.filter((s) => s.state === "signed_out");
  assert.equal(signedOutIntervals.length, 1);
  assert.equal(signedOutIntervals[0]!.startUtc, t("17:00"));

  db.close();
});

// ---------------------------------------------------------------------------
// Stage 2: application foreground / browser URL persistence
// ---------------------------------------------------------------------------

test("STAGE 2 REPRODUCIBILITY GUARANTEE: recomputing usage twice from the same raw events yields byte-identical derived rows", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  const events = [
    ev("app.foreground_start", t("09:00"), { eventId: "u1", payload: { executableName: "outlook.exe" } }),
    ev("app.foreground_start", t("09:20"), { eventId: "u2", payload: { executableName: "chrome.exe" } }),
    ev("browser.tab_active_start", t("09:20"), { eventId: "u3", payload: { browserName: "chrome", tabId: "tab-1", url: "https://mail.google.com/", domain: "mail.google.com" } }),
    ev("browser.navigation", t("09:30"), { eventId: "u4", payload: { browserName: "chrome", tabId: "tab-1", url: "https://docs.google.com/doc1", domain: "docs.google.com" } }),
    ev("app.foreground_start", t("09:45"), { eventId: "u5", payload: { executableName: "code.exe" } }),
  ];
  ingestRawEvents(db, events as any);

  const firstRun = recomputeUsage(db, "sess-1");
  const firstApp = getAppForegroundIntervals(db, "sess-1");
  const firstBrowser = getBrowserUrlIntervals(db, "sess-1");

  const secondRun = recomputeUsage(db, "sess-1");
  const secondApp = getAppForegroundIntervals(db, "sess-1");
  const secondBrowser = getBrowserUrlIntervals(db, "sess-1");

  assert.deepEqual(firstRun, secondRun, "recompute summary counts must match exactly");
  assert.deepEqual(firstApp, secondApp, "app_foreground_intervals must be identical after a second recompute");
  assert.deepEqual(firstBrowser, secondBrowser, "browser_url_intervals must be identical after a second recompute");

  assert.equal(firstApp.length, 3);
  assert.equal(firstApp[0]!.executableName, "outlook.exe");
  assert.equal(firstApp[0]!.endUtc, t("09:20"));
  assert.equal(firstApp[2]!.executableName, "code.exe");
  assert.equal(firstApp[2]!.endUtc, null, "the last foreground app is still open-ended");

  assert.equal(firstBrowser.length, 2, "the navigation at 09:30 must split tab-1 into two URL intervals");
  assert.equal(firstBrowser[0]!.url, "https://mail.google.com/");
  assert.equal(firstBrowser[0]!.endUtc, t("09:30"));
  assert.equal(firstBrowser[1]!.url, "https://docs.google.com/doc1");
  assert.equal(firstBrowser[1]!.endUtc, null);

  db.close();
});

test("recomputing usage after a NEW event is ingested correctly changes the derived output (not stuck on stale cache)", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  ingestRawEvent(db, ev("app.foreground_start", t("09:00"), { eventId: "u1", payload: { executableName: "outlook.exe" } }) as any);
  recomputeUsage(db, "sess-1");
  const before = getAppForegroundIntervals(db, "sess-1");
  assert.equal(before.length, 1);
  assert.equal(before[0]!.endUtc, null);

  ingestRawEvent(db, ev("app.foreground_end", t("09:30"), { eventId: "u2", payload: { executableName: "outlook.exe" } }) as any);
  recomputeUsage(db, "sess-1");
  const after = getAppForegroundIntervals(db, "sess-1");
  assert.equal(after.length, 1);
  assert.equal(after[0]!.endUtc, t("09:30"));

  db.close();
});

// ---------------------------------------------------------------------------
// Stage 2b: classification rules persistence
// ---------------------------------------------------------------------------

test("classification rules: adding and reading back round-trips correctly, and ending a rule preserves its history rather than deleting it", () => {
  const db = freshDb();

  addClassificationRule(db, { ruleId: "r1", target: "app:chrome.exe", classification: "unproductive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-1" });
  let rules = getAllClassificationRules(db);
  assert.equal(rules.length, 1);
  assert.equal(rules[0]!.classification, "unproductive");
  assert.equal(rules[0]!.effectiveToUtc, null);

  endClassificationRule(db, "r1", t("08:00"));
  addClassificationRule(db, { ruleId: "r2", target: "app:chrome.exe", classification: "productive", effectiveFromUtc: t("08:00"), createdByAdminId: "admin-1" });

  rules = getAllClassificationRules(db);
  assert.equal(rules.length, 2, "ending a rule must not delete it");
  const r1 = rules.find((r) => r.ruleId === "r1")!;
  assert.equal(r1.effectiveToUtc, t("08:00"));
  const r2 = rules.find((r) => r.ruleId === "r2")!;
  assert.equal(r2.classification, "productive");

  db.close();
});

test("recomputeSession closes the attendance_sessions row when a session.signout event exists — a real gap caught by actually running the API against seeded data and finding end_utc stuck at null", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  ingestRawEvent(db, ev("session.signin", t("09:00"), { eventId: "e1" }) as any);
  recomputeSession(db, "sess-1");
  assert.equal(getSessionById(db, "sess-1")!.endUtc, null, "an open session must stay open");

  ingestRawEvent(db, ev("session.signout", t("18:00"), { eventId: "e2" }) as any);
  recomputeSession(db, "sess-1");
  const closed = getSessionById(db, "sess-1")!;
  assert.equal(closed.endUtc, t("18:00"));
  assert.equal(closed.endReason, "signout");
  assert.equal(closed.isEndEstimated, false);

  db.close();
});

test("recomputeSession prefers pc.shutdown over session.signout as the closing event when pc.shutdown is later", () => {
  const db = freshDb();
  seedEmployeeDeviceAndNotice(db);

  ingestRawEvents(db, [
    ev("session.signin", t("09:00"), { eventId: "e1" }),
    ev("session.signout", t("17:00"), { eventId: "e2" }),
    ev("pc.shutdown", t("17:05"), { eventId: "e3" }),
  ] as any);
  recomputeSession(db, "sess-1");
  const closed = getSessionById(db, "sess-1")!;
  assert.equal(closed.endUtc, t("17:05"), "the LATER terminal event wins, regardless of which type it is");
  assert.equal(closed.endReason, "shutdown");

  db.close();
});

test("classification rules: ending an already-ended (or nonexistent) rule throws rather than silently succeeding", () => {
  const db = freshDb();
  addClassificationRule(db, { ruleId: "r1", target: "app:chrome.exe", classification: "unproductive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-1" });
  endClassificationRule(db, "r1", t("08:00"));
  assert.throws(() => endClassificationRule(db, "r1", t("09:00")));
  assert.throws(() => endClassificationRule(db, "does-not-exist", t("09:00")));
  db.close();
});
