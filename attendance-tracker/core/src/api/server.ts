/**
 * HTTP API over the proven core engine, repository and auth layers — the
 * thing that lets an Admin Dashboard and a role-restricted Employee
 * Dashboard both fetch REAL recomputed data from one centrally
 * synchronized source, instead of either one reading a local file or an
 * admin having to open an individual employee's machine.
 *
 * Deliberately dependency-free (Node's built-in http module, no
 * Express/JWT library — see engine/auth.ts's own note on why), and this
 * is where role-based access is actually enforced, not just documented:
 * every route but /api/health and /api/auth/* requires a bearer session
 * token, and every route that returns or accepts an employeeId checks
 * that an employee/device session is only ever allowed to act as ITSELF
 * — never a value taken from a query parameter or request body for a
 * non-admin caller. See resolveSession() in db/auth-repository.ts: that
 * function, not this file's routing, is the actual trust boundary.
 *
 * WHY THIS DOESN'T MAKE THE PUBLISHED DASHBOARD ARTIFACT "LIVE": a
 * claude.ai-hosted artifact runs on claude.ai's own infrastructure and
 * cannot reach a server on an admin's private company network unless
 * that server has a real, internet-reachable URL — which in an actual
 * deployment it would (this is normal SaaS architecture, not a flaw
 * specific to this project). This server is real and actually run,
 * curled, and tested over real HTTP sockets (see core/test/*.test.ts and
 * the verification commands in README.md) — proving the API layer and
 * its access control work. It is not, and cannot from here, be proof
 * that the *published* dashboard.html artifact is fetching from it.
 */

import { createServer as createHttpServer, type IncomingMessage, type ServerResponse } from "node:http";
import { DatabaseSync } from "node:sqlite";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import {
  openDatabase,
  getAllEmployees,
  getSessionsForEmployeeOnDate,
  getSessionById,
  getStateIntervals,
  getBreakIntervals,
  getAppForegroundIntervals,
  getBrowserUrlIntervals,
  getAllClassificationRules,
  getDailyReport,
  getMonthlyReport,
  queryAttendanceHistory,
  getRetentionDays,
  ingestRawEvents,
  recomputeSession,
  recomputeUsage,
  TransparencyGateError,
} from "../db/repository.js";
import type { AnyRawEvent } from "../domain/events.js";
import {
  loginAdmin,
  loginEmployee,
  loginDevice,
  loginLocalViewer,
  resolveSession,
  revokeSession,
  submitCorrectionRequest,
  listCorrectionRequests,
  reviewCorrectionRequest,
  AuthError,
  type AuthSession,
} from "../db/auth-repository.js";
import { summarizeUsage } from "../engine/classification.js";
import { dailyReportToCsv, monthlyConsolidatedCsv, monthlyDailyBreakdownCsv } from "../engine/reporting.js";

type Database = DatabaseSync;

function sendJson(res: ServerResponse, status: number, body: unknown): void {
  const json = JSON.stringify(body);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Content-Length": Buffer.byteLength(json),
    // Deliberately permissive for this demo API's CORS story — a real
    // deployment puts this behind the organization's own auth and origin
    // restrictions. The actual access control in this file is the
    // session/role check below, not this header.
    "Access-Control-Allow-Origin": "*",
  });
  res.end(json);
}

function notFound(res: ServerResponse, message: string): void {
  sendJson(res, 404, { error: message });
}

function badRequest(res: ServerResponse, message: string): void {
  sendJson(res, 400, { error: message });
}

function unauthorized(res: ServerResponse, message = "Authentication required."): void {
  sendJson(res, 401, { error: message });
}

function forbidden(res: ServerResponse, message: string): void {
  sendJson(res, 403, { error: message });
}

function sendCsv(res: ServerResponse, filename: string, csv: string): void {
  res.writeHead(200, {
    "Content-Type": "text/csv; charset=utf-8",
    "Content-Length": Buffer.byteLength(csv),
    "Content-Disposition": `attachment; filename="${filename}"`,
    "Access-Control-Allow-Origin": "*",
  });
  res.end(csv);
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function employeeDisplayName(db: Database, employeeId: string): string {
  return getAllEmployees(db).find((e) => e.employeeId === employeeId)?.displayName ?? employeeId;
}

/** Collects and JSON-parses a request body. Rejects on malformed JSON rather than letting a route crash on it. */
function readJsonBody(req: IncomingMessage): Promise<Record<string, unknown>> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    req.on("data", (c: Buffer) => chunks.push(c));
    req.on("error", reject);
    req.on("end", () => {
      const raw = Buffer.concat(chunks).toString("utf-8");
      if (!raw.trim()) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(raw) as Record<string, unknown>);
      } catch {
        reject(new Error("Malformed JSON body."));
      }
    });
  });
}

function bearerToken(req: IncomingMessage): string | null {
  const header = req.headers["authorization"];
  if (!header || !header.startsWith("Bearer ")) return null;
  return header.slice("Bearer ".length).trim() || null;
}

function isLoopbackRequest(req: IncomingMessage): boolean {
  const addr = req.socket.remoteAddress;
  return addr === "127.0.0.1" || addr === "::1" || addr === "::ffff:127.0.0.1";
}

/**
 * The locally-served employee dashboard (public/local-dashboard.html,
 * copied to dist/public by scripts/copy-public.mjs at build time). This
 * is the real, non-sample-data answer to "employees should see their own
 * activity on their own PC without repeated login": the SAME proven API
 * and reporting routes below, fetched by a static page this server
 * hosts itself. The page authenticates with a device token (see
 * POST /api/auth/device-login) rather than a password, so this route
 * itself stays unauthenticated — the page's first fetch is what proves
 * identity, exactly like /api/health and /api/auth/*.
 *
 * Read once at module load, not per-request: this file never changes
 * while the process is running, and re-reading it on every GET would be
 * pure overhead for a static asset.
 */
const __dirname = dirname(fileURLToPath(import.meta.url));
let localDashboardHtml: string | null = null;
function getLocalDashboardHtml(): string | null {
  if (localDashboardHtml !== null) return localDashboardHtml;
  // Built layout: dist/api/server.js -> ../public = dist/public (populated
  // by scripts/copy-public.mjs). Dev/test layout: the test suite runs
  // directly against src/api/server.ts via tsx (no build step), where
  // ../public resolves to src/public, which never exists — so fall back
  // to ../../public = core/public, the actual source location. Same
  // two-layout reasoning as copy-migrations.mjs, just handled at read
  // time instead of copy time, since there's no "dist" to copy into
  // during a test run.
  for (const candidate of [join(__dirname, "..", "public", "local-dashboard.html"), join(__dirname, "..", "..", "public", "local-dashboard.html")]) {
    try {
      localDashboardHtml = readFileSync(candidate, "utf-8");
      return localDashboardHtml;
    } catch {
      // try next candidate
    }
  }
  return null;
}

/**
 * Builds the request handler against one already-open database. Kept
 * separate from `startServer` so tests can exercise routing logic
 * directly against an in-memory DB without binding a real socket.
 */
export function createRequestHandler(db: Database) {
  return async function handleRequest(req: IncomingMessage, res: ServerResponse): Promise<void> {
    if (!req.url || !req.method) {
      badRequest(res, "Malformed request");
      return;
    }
    if (req.method !== "GET" && req.method !== "POST") {
      sendJson(res, 405, { error: `Method ${req.method} not allowed.` });
      return;
    }

    const url = new URL(req.url, "http://localhost");
    const parts = url.pathname.split("/").filter(Boolean); // ["api", ...]
    const isGet = req.method === "GET";
    const isPost = req.method === "POST";

    try {
      // ---- Unauthenticated routes ----
      if (isGet && parts.length === 2 && parts[0] === "api" && parts[1] === "health") {
        sendJson(res, 200, { ok: true });
        return;
      }
      if (isGet && url.pathname === "/local-dashboard") {
        const html = getLocalDashboardHtml();
        if (!html) { notFound(res, "Local dashboard page is not available on this build."); return; }
        res.writeHead(200, { "Content-Type": "text/html; charset=utf-8", "Content-Length": Buffer.byteLength(html) });
        res.end(html);
        return;
      }
      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "auth" && parts[2] === "admin-login") {
        const body = await readJsonBody(req);
        try {
          sendJson(res, 200, loginAdmin(db, String(body.email ?? ""), String(body.password ?? "")));
        } catch (err) {
          if (err instanceof AuthError) { sendJson(res, 401, { error: err.message }); return; }
          throw err;
        }
        return;
      }
      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "auth" && parts[2] === "employee-login") {
        const body = await readJsonBody(req);
        try {
          sendJson(res, 200, loginEmployee(db, String(body.employeeId ?? ""), String(body.password ?? "")));
        } catch (err) {
          if (err instanceof AuthError) { sendJson(res, 401, { error: err.message }); return; }
          throw err;
        }
        return;
      }
      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "auth" && parts[2] === "device-login") {
        const body = await readJsonBody(req);
        try {
          sendJson(res, 200, loginDevice(db, String(body.deviceToken ?? "")));
        } catch (err) {
          if (err instanceof AuthError) { sendJson(res, 401, { error: err.message }); return; }
          throw err;
        }
        return;
      }
      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "auth" && parts[2] === "local-viewer") {
        if (!isLoopbackRequest(req)) { forbidden(res, "Local viewer sign-in is only allowed from this PC."); return; }
        sendJson(res, 200, loginLocalViewer(db));
        return;
      }
      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "maintenance" && parts[2] === "recompute-open") {
        if (!isLoopbackRequest(req)) { forbidden(res, "Maintenance tasks are only allowed from this PC."); return; }
        const rows = db.prepare(`SELECT session_id FROM attendance_sessions WHERE end_utc IS NULL`).all() as { session_id: string }[];
        const sessionIds = rows.map((r) => r.session_id);
        for (const sid of sessionIds) {
          recomputeSession(db, sid);
          recomputeUsage(db, sid);
        }
        sendJson(res, 200, { recomputedSessionIds: sessionIds });
        return;
      }

      // ---- Everything past this point requires a valid session ----
      const token = bearerToken(req);
      const session: AuthSession | null = token ? resolveSession(db, token) : null;
      if (!session) {
        unauthorized(res);
        return;
      }
      const isAdmin = session.role === "admin";
      const isViewer = session.role === "viewer";
      const canViewAll = isAdmin || isViewer;
      /** For employee/device sessions, the one employeeId they're allowed to act as. Admin/viewer has no such restriction. */
      const ownEmployeeId = session.role === "employee" || session.role === "device" ? session.subjectId : null;

      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "auth" && parts[2] === "logout") {
        revokeSession(db, token!);
        sendJson(res, 200, { ok: true });
        return;
      }
      if (isGet && parts.length === 2 && parts[0] === "api" && parts[1] === "me") {
        sendJson(res, 200, {
          role: session.role,
          subjectId: session.subjectId,
          ...(session.deviceId ? { deviceId: session.deviceId } : {}),
          // Own display name only — never another employee's, and never
          // exposed for a role that can't already see this (admin has no
          // employeeId of their own to name). This is what lets the
          // locally-served employee dashboard show "Priya Sharma" instead
          // of a raw employeeId, without granting it the admin-only
          // GET /api/employees directory lookup.
          ...(ownEmployeeId ? { displayName: employeeDisplayName(db, ownEmployeeId) } : {}),
        });
        return;
      }

      // ---- Admin-only: employee directory, classification rules, retention ----
      if (isGet && parts.length === 2 && parts[0] === "api" && parts[1] === "employees") {
        if (!canViewAll) { forbidden(res, "Only administrators can list all employees."); return; }
        sendJson(res, 200, getAllEmployees(db));
        return;
      }
      if (isGet && parts.length === 2 && parts[0] === "api" && parts[1] === "classification-rules") {
        if (!canViewAll) { forbidden(res, "Only administrators can view monitoring/classification rules."); return; }
        sendJson(res, 200, getAllClassificationRules(db));
        return;
      }
      if (isGet && parts.length === 2 && parts[0] === "api" && parts[1] === "retention") {
        if (!canViewAll) { forbidden(res, "Only administrators can view the retention configuration."); return; }
        sendJson(res, 200, { retentionDays: getRetentionDays(db) });
        return;
      }

      // ---- Employee-scoped (admin unrestricted; employee/device must match their own id) ----
      if (isGet && parts.length === 4 && parts[0] === "api" && parts[1] === "employees" && parts[3] === "sessions") {
        const employeeId = decodeURIComponent(parts[2]!);
        if (!canViewAll && employeeId !== ownEmployeeId) { forbidden(res, "You can only view your own sessions."); return; }
        const date = url.searchParams.get("date");
        if (!date || !DATE_RE.test(date)) { badRequest(res, "Query parameter 'date' is required, format YYYY-MM-DD (UTC calendar day)."); return; }
        sendJson(res, 200, getSessionsForEmployeeOnDate(db, employeeId, date));
        return;
      }

      if (isGet && parts.length === 3 && parts[0] === "api" && parts[1] === "sessions") {
        const sessionId = decodeURIComponent(parts[2]!);
        const sessionRow = getSessionById(db, sessionId);
        if (!sessionRow) { notFound(res, `No session with id ${sessionId}`); return; }
        if (!canViewAll && sessionRow.employeeId !== ownEmployeeId) { forbidden(res, "You can only view your own sessions."); return; }
        const stateIntervals = getStateIntervals(db, sessionId);
        const breakIntervals = getBreakIntervals(db, sessionId);
        const appIntervals = getAppForegroundIntervals(db, sessionId);
        const browserIntervals = getBrowserUrlIntervals(db, sessionId);
        const rules = getAllClassificationRules(db);
        const asOfUtc = sessionRow.endUtc ?? new Date().toISOString();
        const usageSummary = summarizeUsage(appIntervals, browserIntervals, rules, asOfUtc);
        sendJson(res, 200, { session: sessionRow, stateIntervals, breakIntervals, appIntervals, browserIntervals, usageSummary });
        return;
      }

      // ---- Event ingest: the Windows agent's (or any device's) sync
      // path for pushing locally-queued raw_events to this central
      // server. This is the piece that was missing for the
      // "centralized data collection with offline retention then sync"
      // requirement — previously, ingestRawEvents() was only reachable
      // by calling the repository function directly (as the seed
      // script and tests do), never over HTTP, so a real deployed
      // agent had no way to get events INTO this server at all.
      //
      // A non-admin session (employee or device) can only ingest
      // events for ITS OWN employeeId/deviceId — enforced here, not
      // merely assumed of a well-behaved client, exactly like every
      // other employeeId-scoped route above. An admin session may
      // ingest on behalf of any employee/device (a bulk-import or
      // backfill tool's use case).
      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "events" && parts[2] === "ingest") {
        if (isViewer) { forbidden(res, "Changes require an administrator sign-in."); return; }
        const body = await readJsonBody(req);
        const events = body.events;
        if (!Array.isArray(events) || events.length === 0) {
          badRequest(res, "Body field 'events' (non-empty array) is required.");
          return;
        }
        if (events.length > 1000) {
          badRequest(res, "A single ingest batch is capped at 1000 events; send more batches instead.");
          return;
        }
        const required = ["eventId", "employeeId", "deviceId", "type", "timestampUtc", "source"];
        for (const [i, e] of events.entries()) {
          if (typeof e !== "object" || e === null) { badRequest(res, `events[${i}] is not an object.`); return; }
          for (const field of required) {
            if (!(field in (e as Record<string, unknown>))) { badRequest(res, `events[${i}] is missing required field '${field}'.`); return; }
          }
          const ev = e as Record<string, unknown>;
          if (!isAdmin && ev.employeeId !== ownEmployeeId) { forbidden(res, `events[${i}].employeeId does not match your own employeeId — you can only ingest your own events.`); return; }
          if (session.role === "device" && ev.deviceId !== session.deviceId) { forbidden(res, `events[${i}].deviceId does not match this device's own deviceId.`); return; }
        }
        const normalized: AnyRawEvent[] = events.map((e) => {
          const ev = e as Record<string, unknown>;
          return {
            eventId: String(ev.eventId),
            employeeId: String(ev.employeeId),
            deviceId: String(ev.deviceId),
            sessionId: ev.sessionId == null ? null : String(ev.sessionId),
            type: ev.type,
            timestampUtc: String(ev.timestampUtc),
            source: ev.source,
            isEstimated: Boolean(ev.isEstimated ?? false),
            payload: (ev.payload as Record<string, unknown>) ?? {},
          } as AnyRawEvent;
        });
        try {
          const results = ingestRawEvents(db, normalized);
          // Recompute every session touched by this batch so the data
          // is immediately queryable (not just inserted) — the same
          // guarantee seed-demo-data.mjs relies on, now reachable from
          // a real device over HTTP instead of only from a local script.
          const sessionIds = Array.from(new Set(normalized.map((e) => e.sessionId).filter((id): id is string => id !== null)));
          for (const sid of sessionIds) {
            recomputeSession(db, sid);
            recomputeUsage(db, sid);
          }
          sendJson(res, 201, { ingested: results.length, insertedCount: results.filter((r) => r.inserted).length, recomputedSessionIds: sessionIds });
        } catch (err) {
          if (err instanceof TransparencyGateError) { forbidden(res, err.message); return; }
          throw err;
        }
        return;
      }

      // ---- Daily report ----
      if (isGet && parts.length === 3 && parts[0] === "api" && parts[1] === "reports" && (parts[2] === "daily" || parts[2] === "daily.csv")) {
        const employeeId = url.searchParams.get("employeeId");
        const date = url.searchParams.get("date");
        if (!employeeId) { badRequest(res, "Query parameter 'employeeId' is required."); return; }
        if (!canViewAll && employeeId !== ownEmployeeId) { forbidden(res, "You can only view your own reports."); return; }
        if (!date || !DATE_RE.test(date)) { badRequest(res, "Query parameter 'date' is required, format YYYY-MM-DD."); return; }
        const report = getDailyReport(db, employeeId, date);
        if (parts[2] === "daily.csv") {
          sendCsv(res, `daily-${employeeId}-${date}.csv`, dailyReportToCsv(report, employeeDisplayName(db, employeeId)));
        } else {
          sendJson(res, 200, report);
        }
        return;
      }

      // ---- Monthly report ----
      if (isGet && parts.length === 3 && parts[0] === "api" && parts[1] === "reports" && (parts[2] === "monthly" || parts[2] === "monthly.csv")) {
        const employeeId = url.searchParams.get("employeeId");
        const yearStr = url.searchParams.get("year");
        const monthStr = url.searchParams.get("month");
        if (!employeeId) { badRequest(res, "Query parameter 'employeeId' is required."); return; }
        if (!canViewAll && employeeId !== ownEmployeeId) { forbidden(res, "You can only view your own reports."); return; }
        const year = Number(yearStr);
        const month = Number(monthStr);
        if (!yearStr || !monthStr || !Number.isInteger(year) || !Number.isInteger(month) || month < 1 || month > 12) {
          badRequest(res, "Query parameters 'year' and 'month' (1-12) are required.");
          return;
        }
        const report = getMonthlyReport(db, employeeId, year, month);
        if (parts[2] === "monthly.csv") {
          const variant = url.searchParams.get("variant") ?? "consolidated";
          const name = employeeDisplayName(db, employeeId);
          if (variant === "daily") {
            sendCsv(res, `monthly-daily-${employeeId}-${year}-${month}.csv`, monthlyDailyBreakdownCsv(report, name));
          } else if (variant === "consolidated") {
            sendCsv(res, `monthly-summary-${employeeId}-${year}-${month}.csv`, monthlyConsolidatedCsv(report, name));
          } else {
            badRequest(res, "Query parameter 'variant' must be 'consolidated' or 'daily'.");
          }
        } else {
          sendJson(res, 200, report);
        }
        return;
      }

      // ---- Date-range / employee / department / device / status history search ----
      if (isGet && parts.length === 2 && parts[0] === "api" && parts[1] === "history") {
        const dateFrom = url.searchParams.get("dateFrom");
        const dateTo = url.searchParams.get("dateTo");
        if (!dateFrom || !DATE_RE.test(dateFrom) || !dateTo || !DATE_RE.test(dateTo)) {
          badRequest(res, "Query parameters 'dateFrom' and 'dateTo' are required, format YYYY-MM-DD.");
          return;
        }
        const status = url.searchParams.get("status");
        if (status && status !== "present" && status !== "absent" && status !== "incomplete") {
          badRequest(res, "Query parameter 'status', if given, must be one of: present, absent, incomplete.");
          return;
        }
        let employeeIdParam = url.searchParams.get("employeeId");
        if (!canViewAll) {
          // A non-admin session can never browse anyone else's history —
          // their own employeeId is enforced here, not merely assumed.
          if (employeeIdParam && employeeIdParam !== ownEmployeeId) { forbidden(res, "You can only view your own history."); return; }
          employeeIdParam = ownEmployeeId;
        }
        const departmentParam = canViewAll ? url.searchParams.get("department") : null;
        const deviceIdParam = url.searchParams.get("deviceId");
        sendJson(res, 200, queryAttendanceHistory(db, {
          ...(employeeIdParam ? { employeeId: employeeIdParam } : {}),
          ...(departmentParam ? { department: departmentParam } : {}),
          ...(deviceIdParam ? { deviceId: deviceIdParam } : {}),
          ...(status ? { status: status as "present" | "absent" | "incomplete" } : {}),
          dateFrom,
          dateTo,
        }));
        return;
      }

      // ---- Correction requests: employee/device submits, admin reviews ----
      if (isPost && parts.length === 3 && parts[0] === "api" && parts[1] === "corrections" && parts[2] === "request") {
        if (isViewer) { forbidden(res, "Changes require an administrator sign-in."); return; }
        if (isAdmin) { forbidden(res, "Admins record corrections directly; this endpoint is for employee-submitted requests."); return; }
        const body = await readJsonBody(req);
        const sessionId = String(body.sessionId ?? "");
        const fieldName = String(body.fieldName ?? "");
        const requestedValue = String(body.requestedValue ?? "");
        const reason = String(body.reason ?? "").trim();
        if (!sessionId || !fieldName || !requestedValue || !reason) {
          badRequest(res, "sessionId, fieldName, requestedValue and reason are all required.");
          return;
        }
        const sessionRow = getSessionById(db, sessionId);
        if (!sessionRow) { notFound(res, `No session with id ${sessionId}`); return; }
        if (sessionRow.employeeId !== ownEmployeeId) { forbidden(res, "You can only request a correction on your own session."); return; }
        sendJson(res, 201, submitCorrectionRequest(db, { employeeId: ownEmployeeId!, sessionId, fieldName, requestedValue, reason }));
        return;
      }
      if (isGet && parts.length === 3 && parts[0] === "api" && parts[1] === "corrections" && parts[2] === "requests") {
        if (!canViewAll) { forbidden(res, "Only administrators can review correction requests across employees."); return; }
        const status = url.searchParams.get("status");
        if (status && status !== "pending" && status !== "approved" && status !== "rejected") {
          badRequest(res, "Query parameter 'status', if given, must be one of: pending, approved, rejected.");
          return;
        }
        const employeeIdFilter = url.searchParams.get("employeeId");
        sendJson(res, 200, listCorrectionRequests(db, {
          ...(employeeIdFilter ? { employeeId: employeeIdFilter } : {}),
          ...(status ? { status: status as "pending" | "approved" | "rejected" } : {}),
        }));
        return;
      }
      if (isPost && parts.length === 5 && parts[0] === "api" && parts[1] === "corrections" && parts[2] === "requests" && parts[4] === "review") {
        if (!isAdmin) { forbidden(res, "Changes require an administrator sign-in."); return; }
        const requestId = decodeURIComponent(parts[3]!);
        const body = await readJsonBody(req);
        if (typeof body.approve !== "boolean") { badRequest(res, "Body field 'approve' (boolean) is required."); return; }
        try {
          const result = reviewCorrectionRequest(db, requestId, {
            approve: body.approve,
            adminId: session.subjectId,
            ...(typeof body.note === "string" && body.note ? { note: body.note } : {}),
          });
          sendJson(res, 200, result);
        } catch (err) {
          badRequest(res, err instanceof Error ? err.message : String(err));
        }
        return;
      }

      notFound(res, `No route for ${req.method} ${url.pathname}`);
    } catch (err) {
      // A real deployment needs structured logging here (named gap,
      // consistent with every other "no logging yet" note in this
      // codebase) — but an unhandled exception must never hang the
      // client or crash the process over one bad request.
      sendJson(res, 500, { error: err instanceof Error ? err.message : String(err) });
    }
  };
}

export interface ApiServerHandle {
  close: () => Promise<void>;
  port: number;
}

/** Opens the DB (running migrations, same as any other caller of openDatabase) and starts listening. */
export function startServer(dbPath: string, port = 0): Promise<ApiServerHandle> {
  const db = openDatabase(dbPath);
  const server = createHttpServer(createRequestHandler(db));
  return new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(port, "127.0.0.1", () => {
      const address = server.address();
      const actualPort = typeof address === "object" && address ? address.port : port;
      resolve({
        port: actualPort,
        close: () =>
          new Promise<void>((res) => {
            server.close(() => {
              db.close();
              res();
            });
          }),
      });
    });
  });
}
