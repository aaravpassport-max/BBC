// Real, executed proof for the locally-served employee dashboard
// (public/local-dashboard.html, served by GET /local-dashboard in
// src/api/server.ts) — not a unit test of handler logic, but an actual
// headless browser loading the actual compiled server's actual HTML/JS
// over a real socket, authenticating with a real device token, and
// rendering real seeded data. This is deliberately NOT part of `npm
// test`: Playwright is not a devDependency of this package (the project
// keeps a small dependency surface on purpose — see auth.ts's own note
// on avoiding a JWT library) and is only available in this interactive
// dev sandbox at /opt/npm-tools/node_modules/playwright, pre-pointed at
// a prebuilt Chromium via PLAYWRIGHT_BROWSERS_PATH. Run manually with:
//   node --import tsx scripts/verify-local-dashboard.mjs
// after `npm run build` (it needs dist/public/local-dashboard.html to
// exist, exactly like a real deployment would).
import { createRequire } from "node:module";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const require = createRequire(import.meta.url);
// Loaded by absolute path, not by name, because this package itself has
// no "playwright" dependency — see the file header for why.
const { chromium } = require("/opt/npm-tools/node_modules/playwright");

const { startServer } = await import("../dist/api/server.js");
const { openDatabase, upsertEmployee, upsertDevice, recordNoticeShown, recordNoticeAcknowledged, ingestRawEvents, recomputeSession, recomputeUsage, addClassificationRule, createAdminUser, loginAdmin, enrollDevice } = await import("../dist/index.js");

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exitCode = 1;
}
function check(label, actual, expected) {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  console.log(`${ok ? "OK  " : "FAIL"} ${label}: actual=${JSON.stringify(actual)} expected=${JSON.stringify(expected)}`);
  if (!ok) process.exitCode = 1;
}

const dbDir = mkdtempSync(join(tmpdir(), "local-dashboard-verify-"));
const dbPath = join(dbDir, "test.sqlite");
let handle, browser;

try {
  handle = await startServer(dbPath, 0);
  const base = `http://127.0.0.1:${handle.port}`;
  console.log(`Server listening at ${base}`);

  // ---- Seed real data: one employee, one enrolled device, today's session ----
  const db = openDatabase(dbPath);
  upsertEmployee(db, { employeeId: "emp-local-1", displayName: "Priya Sharma", department: "Engineering" });
  upsertDevice(db, { deviceId: "dev-local-1" });
  recordNoticeShown(db, "emp-local-1", "dev-local-1", "policy-v1");
  recordNoticeAcknowledged(db, "emp-local-1", "dev-local-1");

  const today = new Date();
  const y = today.getUTCFullYear(), m = String(today.getUTCMonth() + 1).padStart(2, "0"), d = String(today.getUTCDate()).padStart(2, "0");
  const todayIso = `${y}-${m}-${d}`;
  function at(hh, mm) { return new Date(Date.UTC(y, today.getUTCMonth(), today.getUTCDate(), hh, mm)).toISOString(); }

  function rawEv(type, timestampUtc, overrides = {}) {
    return { eventId: overrides.eventId, employeeId: "emp-local-1", deviceId: "dev-local-1", sessionId: "lv-s1", type, timestampUtc, source: "windows-agent", isEstimated: false, payload: overrides.payload ?? {} };
  }
  ingestRawEvents(db, [
    rawEv("session.signin", at(9, 0), { eventId: "lv-1" }),
    rawEv("input.activity", at(9, 0), { eventId: "lv-2", payload: { kind: "keyboard" } }),
    rawEv("app.foreground_start", at(9, 0), { eventId: "lv-3", payload: { executableName: "code.exe" } }),
    rawEv("input.activity", at(10, 30), { eventId: "lv-4", payload: { kind: "keyboard" } }),
  ]);
  recomputeSession(db, "lv-s1");
  recomputeUsage(db, "lv-s1");
  addClassificationRule(db, { ruleId: "lv-rule-1", target: "app:code.exe", classification: "productive", effectiveFromUtc: at(0, 0), createdByAdminId: "seed" });

  // ---- Mint the admin session + device token the same way an admin would at enrollment ----
  createAdminUser(db, { adminId: "admin-verify", displayName: "Verify Admin", email: "verify-admin@example.com", password: "correct-horse-battery-staple" });
  const adminSession = loginAdmin(db, "verify-admin@example.com", "correct-horse-battery-staple");
  const deviceToken = enrollDevice(db, "emp-local-1", "dev-local-1");
  db.close();

  // ---- Independently fetch the SAME data via the admin API, as the ground truth to compare the rendered page against ----
  const adminHeaders = { Authorization: `Bearer ${adminSession.token}` };
  const dailyRes = await fetch(`${base}/api/reports/daily?employeeId=emp-local-1&date=${todayIso}`, { headers: adminHeaders });
  const daily = await dailyRes.json();
  console.log("Ground-truth daily report (via admin API):", JSON.stringify({ status: daily.status, activeMs: daily.activeMs, breakMs: daily.breakMs, totalSessionDurationMs: daily.totalSessionDurationMs }));

  function fmtMs(ms) {
    const totalMin = Math.round(ms / 60000);
    const h = Math.floor(totalMin / 60), mm2 = totalMin % 60;
    return h > 0 ? `${h}h ${mm2}m` : `${mm2}m`;
  }

  // ---- Real headless browser: load the page with the device token the ONLY time it's ever passed, exactly like the tray app would ----
  browser = await chromium.launch({ executablePath: "/opt/pw-browsers/chromium-1194/chrome-linux/chrome" });
  const page = await browser.newPage();
  const consoleErrors = [];
  page.on("pageerror", (err) => consoleErrors.push(String(err)));
  page.on("console", (msg) => { if (msg.type() === "error") consoleErrors.push(msg.text()); });

  await page.goto(`${base}/local-dashboard?deviceToken=${encodeURIComponent(deviceToken)}`);
  await page.waitForSelector("#app:not([hidden])", { timeout: 10000 });

  const urlAfterLoad = page.url();
  check("deviceToken stripped from the URL after first use", urlAfterLoad.includes("deviceToken"), false);

  const rendered = await page.evaluate(() => ({
    name: document.getElementById("employeeName").textContent,
    status: document.getElementById("todayStatus").textContent,
    active: document.getElementById("todayActive").textContent,
    brk: document.getElementById("todayBreak").textContent,
    span: document.getElementById("todaySession").textContent,
    usageRows: Array.from(document.querySelectorAll("#usageTable tbody tr")).map((tr) => tr.textContent.trim()),
    historyRowCount: document.querySelectorAll("#historyBody tr").length,
  }));
  console.log("Rendered page values:", JSON.stringify(rendered));

  check("employee name rendered from /api/me, not the raw employeeId", rendered.name, "Priya Sharma");
  check("today's status matches the admin API's ground truth", rendered.status, daily.status);
  check("active time text matches the admin API's activeMs, independently formatted", rendered.active, fmtMs(daily.activeMs));
  check("break time text matches the admin API's breakMs", rendered.brk, fmtMs(daily.breakMs));
  check("session span text matches the admin API's totalSessionDurationMs", rendered.span, fmtMs(daily.totalSessionDurationMs));
  check("code.exe shows up in the rendered usage table (real classification data, not a stub)", rendered.usageRows.some((r) => r.includes("code.exe")), true);
  check("history table hides absent days by default, showing only today's real present day", rendered.historyRowCount, 1);
  check("no JS errors during load", consoleErrors, []);

  const withAbsent = await page.evaluate(() => {
    document.getElementById("showAbsentToggle").checked = true;
    document.getElementById("showAbsentToggle").dispatchEvent(new Event("change"));
    return document.querySelectorAll("#historyBody tr").length;
  });
  check("checking 'show absent days' reveals the full ~366-day range", withAbsent > 300, true);

  // ---- Reload with NO token in the URL at all: proves "no repeated login" — trust comes from localStorage, not a fresh password/token prompt ----
  await page.goto(`${base}/local-dashboard`);
  await page.waitForSelector("#app:not([hidden])", { timeout: 10000 });
  const secondLoadName = await page.evaluate(() => document.getElementById("employeeName").textContent);
  check("second load with a bare URL (no deviceToken param) still authenticates via localStorage", secondLoadName, "Priya Sharma");

  // ---- A revoked/unknown device token must show a clear message, not a crash or a blank page ----
  const page2 = await browser.newPage();
  await page2.goto(`${base}/local-dashboard?deviceToken=not-a-real-token-at-all`);
  await page2.waitForSelector("#error:not([hidden])", { timeout: 10000 });
  const errorText = await page2.evaluate(() => document.getElementById("error").textContent);
  console.log("Error message shown for an invalid device token:", errorText);
  check("invalid device token shows a human-readable error, not a blank page", errorText.length > 0, true);
  await page2.close();

  console.log(process.exitCode ? "\n=== SOME CHECKS FAILED ===" : "\n=== ALL CHECKS PASSED ===");
} catch (err) {
  fail(`unhandled error: ${err.stack ?? err}`);
} finally {
  if (browser) await browser.close();
  if (handle) await handle.close();
  rmSync(dbDir, { recursive: true, force: true });
}
