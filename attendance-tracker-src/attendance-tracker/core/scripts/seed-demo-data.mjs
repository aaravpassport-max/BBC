// Seeds a demo SQLite file with a few employees, realistic events over a
// workday, runs the real reconciler/usage recompute (not hand-authored
// derived rows), and adds a couple of classification rules — so
// api/server.ts has genuine recomputed data to serve, not sample arrays.
import { DatabaseSync } from "node:sqlite";
import {
  openDatabase,
  upsertEmployee,
  upsertDevice,
  recordNoticeShown,
  recordNoticeAcknowledged,
  ingestRawEvents,
  recomputeSession,
  recomputeUsage,
  addClassificationRule,
} from "../dist/index.js";
import { existsSync, unlinkSync } from "node:fs";

const dbPath = process.argv[2] ?? "/tmp/attendance-demo.db";
if (existsSync(dbPath)) unlinkSync(dbPath);

const db = openDatabase(dbPath);

function t(hhmm, day = "2026-10-19") {
  const [h, m] = hhmm.split(":").map(Number);
  const [year, month, date] = day.split("-").map(Number);
  // Date.UTC's month argument is 0-indexed (0 = January) while the
  // "YYYY-MM-DD" string is 1-indexed — caught by actually running this
  // script and querying the API for "2026-10-19", which came back empty
  // because every event had silently been dated into November instead.
  return new Date(Date.UTC(year, month - 1, date, h, m, 0, 0)).toISOString();
}

function seedEmployee(employeeId, displayName, department, deviceId) {
  upsertEmployee(db, { employeeId, displayName, department, designation: "Associate" });
  upsertDevice(db, { deviceId, hostname: deviceId.toUpperCase() });
  recordNoticeShown(db, employeeId, deviceId, "policy-v1");
  recordNoticeAcknowledged(db, employeeId, deviceId);
}

let evCounter = 0;
function ev(employeeId, deviceId, sessionId, type, timestampUtc, payload = {}) {
  evCounter += 1;
  return {
    eventId: `seed-${evCounter}`,
    employeeId,
    deviceId,
    sessionId,
    type,
    timestampUtc,
    source: type.startsWith("browser.") ? "browser-extension" : "windows-agent",
    isEstimated: false,
    payload,
  };
}

// --- Employee 1: Priya — a normal day with one genuine break and real app/browser usage ---
seedEmployee("emp-priya", "Priya Sharma", "Operations", "dev-priya-01");
{
  const s = "sess-priya-1";
  const events = [
    ev("emp-priya", "dev-priya-01", s, "session.signin", t("09:02")),
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("09:02")),
    ev("emp-priya", "dev-priya-01", s, "app.foreground_start", t("09:02"), { executableName: "outlook.exe" }),
    ev("emp-priya", "dev-priya-01", s, "app.foreground_start", t("09:20"), { executableName: "chrome.exe" }),
    ev("emp-priya", "dev-priya-01", s, "browser.tab_active_start", t("09:20"), { browserName: "chrome", tabId: "t1", url: "https://erp.internal.example/orders", domain: "erp.internal.example" }),
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("09:35")),
    // Genuine 14-minute break, backdated to last real input (09:35), not to when the threshold tripped.
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("09:49")),
    ev("emp-priya", "dev-priya-01", s, "app.foreground_start", t("10:00"), { executableName: "excel.exe" }),
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("12:00")),
    ev("emp-priya", "dev-priya-01", s, "session.lock", t("13:00")),
    ev("emp-priya", "dev-priya-01", s, "session.unlock", t("13:30")),
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("13:30")),
    ev("emp-priya", "dev-priya-01", s, "browser.navigation", t("14:00"), { browserName: "chrome", tabId: "t1", url: "https://news.ycombinator.com/", domain: "news.ycombinator.com" }),
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("17:55")),
    ev("emp-priya", "dev-priya-01", s, "session.signout", t("18:00")),
  ];
  ingestRawEvents(db, events);
  recomputeSession(db, s);
  recomputeUsage(db, s);
}

// --- Employee 2: Rahul — a monitoring-gap scenario (agent heartbeat missed) ---
seedEmployee("emp-rahul", "Rahul Verma", "Sales", "dev-rahul-01");
{
  const s = "sess-rahul-1";
  const events = [
    ev("emp-rahul", "dev-rahul-01", s, "session.signin", t("09:30")),
    ev("emp-rahul", "dev-rahul-01", s, "input.activity", t("09:30")),
    ev("emp-rahul", "dev-rahul-01", s, "app.foreground_start", t("09:30"), { executableName: "chrome.exe" }),
    ev("emp-rahul", "dev-rahul-01", s, "browser.tab_active_start", t("09:30"), { browserName: "chrome", tabId: "t1", url: "https://crm.internal.example/leads", domain: "crm.internal.example" }),
    ev("emp-rahul", "dev-rahul-01", s, "agent.heartbeat_missed", t("11:00")),
    ev("emp-rahul", "dev-rahul-01", s, "input.activity", t("11:45")),
    ev("emp-rahul", "dev-rahul-01", s, "input.activity", t("17:00")),
    ev("emp-rahul", "dev-rahul-01", s, "session.signout", t("17:30")),
  ];
  ingestRawEvents(db, events);
  recomputeSession(db, s);
  recomputeUsage(db, s);
}

// --- A real multi-day history for Priya, to exercise the new reporting
// routes (daily/monthly/history) against actual stored data instead of a
// single day. Oct 13 (Tue) and Oct 14 (Wed): ordinary complete days.
// Oct 15 (Thu): intentionally left with NO events at all — a real absent
// day. Oct 16 (Fri): signed in but never signed out — a real incomplete
// day (missing logout), proving status detection against real recompute,
// not a hand-set flag. ---
// input.activity every 3 minutes is well inside the 5-minute inactivity
// threshold, so this reads as genuinely continuous activity to the real
// reconciler — not a label, an actual dense event stream — with one real
// 30-minute lunch gap deliberately left empty so idle_break still fires
// for that one window, the way an honest "ordinary day" should.
function seedOrdinaryDay(day) {
  const s = `sess-priya-${day}`;
  const events = [
    ev("emp-priya", "dev-priya-01", s, "session.signin", t("09:15", day)),
    ev("emp-priya", "dev-priya-01", s, "app.foreground_start", t("09:15", day), { executableName: "outlook.exe" }),
  ];
  for (let min = 9 * 60 + 15; min <= 17 * 60 + 40; min += 3) {
    if (min >= 13 * 60 && min < 13 * 60 + 30) continue; // lunch, 13:00-13:30 — a real break
    const hh = String(Math.floor(min / 60)).padStart(2, "0");
    const mm = String(min % 60).padStart(2, "0");
    events.push(ev("emp-priya", "dev-priya-01", s, "input.activity", t(`${hh}:${mm}`, day)));
  }
  events.push(ev("emp-priya", "dev-priya-01", s, "app.foreground_start", t("12:30", day), { executableName: "excel.exe" }));
  events.push(ev("emp-priya", "dev-priya-01", s, "session.signout", t("17:45", day)));
  ingestRawEvents(db, events);
  recomputeSession(db, s);
  recomputeUsage(db, s);
}
seedOrdinaryDay("2026-10-13");
seedOrdinaryDay("2026-10-14");
// 2026-10-15: deliberately no events — proves getDailyReport/getMonthlyReport
// report "absent" from real missing rows, not a hardcoded case.
{
  const s = "sess-priya-2026-10-16";
  const events = [
    ev("emp-priya", "dev-priya-01", s, "session.signin", t("09:10", "2026-10-16")),
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("09:10", "2026-10-16")),
    ev("emp-priya", "dev-priya-01", s, "input.activity", t("16:50", "2026-10-16")),
    // No session.signout — the agent never reported one. This is what a
    // real missed-logout looks like: recomputeSession() correctly leaves
    // end_utc null rather than guessing a sign-out time.
  ];
  ingestRawEvents(db, events);
  recomputeSession(db, s);
  recomputeUsage(db, s);
}

// --- Classification rules ---
addClassificationRule(db, { ruleId: "rule-erp", target: "domain:erp.internal.example", classification: "productive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-demo" });
addClassificationRule(db, { ruleId: "rule-crm", target: "domain:crm.internal.example", classification: "productive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-demo" });
addClassificationRule(db, { ruleId: "rule-excel", target: "app:excel.exe", classification: "productive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-demo" });
addClassificationRule(db, { ruleId: "rule-outlook", target: "app:outlook.exe", classification: "productive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-demo" });
addClassificationRule(db, { ruleId: "rule-hn", target: "domain:news.ycombinator.com", classification: "unproductive", effectiveFromUtc: t("00:00"), createdByAdminId: "admin-demo" });

db.close();
console.log(`Seeded demo data into ${dbPath}`);
