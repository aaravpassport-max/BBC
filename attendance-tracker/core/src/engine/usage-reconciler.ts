/**
 * Stage 2: application foreground and browser URL reconciliation.
 *
 * Same pure-function, single-source-of-truth discipline as
 * activity-reconciler.ts: given a session's raw events (already filtered
 * to one sessionId and sorted ascending), emit non-overlapping intervals,
 * never hand-edited, fully reproducible by replay.
 *
 * REAL-WORLD ROBUSTNESS NOTE: Windows' foreground-window change
 * notification (SetWinEventHook) reliably tells you "app X just became
 * foreground" but does NOT reliably give you a matching "app Y just lost
 * foreground" event for the PREVIOUS app — so a real agent's event stream
 * may consist almost entirely of foreground_start events with few or no
 * foreground_end events. This engine is built around that reality: a new
 * foreground_start for a different app implicitly closes whatever was
 * previously foreground, and an explicit foreground_end is handled too,
 * but neither is required for correctness. The same logic applies to
 * browser tab activation.
 */

import type { AnyRawEvent } from "../domain/events.js";
import type { AppForegroundInterval, BrowserUrlInterval } from "../domain/usage.js";

function toMs(iso: string): number {
  const ms = Date.parse(iso);
  if (Number.isNaN(ms)) throw new Error(`Invalid ISO timestamp: ${iso}`);
  return ms;
}
function toIso(ms: number): string {
  return new Date(ms).toISOString();
}

export interface UsageAnomaly {
  type: "stale_foreground_end" | "stale_tab_end" | "out_of_order_event";
  message: string;
  atUtc: string;
  eventIds: string[];
}

// ---------------------------------------------------------------------------
// Application foreground
// ---------------------------------------------------------------------------

interface AppCursor {
  executableName: string;
  windowTitle: string | null;
  startMs: number;
  causedByEventIds: string[];
}

export function reconcileAppForeground(events: AnyRawEvent[]): {
  intervals: AppForegroundInterval[];
  anomalies: UsageAnomaly[];
} {
  const intervals: AppForegroundInterval[] = [];
  const anomalies: UsageAnomaly[] = [];
  if (events.length === 0) return { intervals, anomalies };

  const sessionId = events[0]!.sessionId ?? "";
  const employeeId = events[0]!.employeeId;
  const deviceId = events[0]!.deviceId;

  let cursor: AppCursor | null = null;

  function close(endMs: number): void {
    if (!cursor) return;
    if (cursor.startMs === endMs) return; // zero-length — nothing to record, same rationale as activity-reconciler
    intervals.push({
      employeeId,
      deviceId,
      sessionId,
      executableName: cursor.executableName,
      windowTitle: cursor.windowTitle,
      startUtc: toIso(cursor.startMs),
      endUtc: toIso(endMs),
      causedByEventIds: [...cursor.causedByEventIds],
    });
  }

  let prevMs = -Infinity;
  for (const ev of events) {
    const evMs = toMs(ev.timestampUtc);
    if (evMs < prevMs) {
      anomalies.push({ type: "out_of_order_event", message: `Event ${ev.eventId} precedes previous event`, atUtc: ev.timestampUtc, eventIds: [ev.eventId] });
      continue;
    }
    prevMs = evMs;

    if (ev.type === "app.foreground_start") {
      const payload = ev.payload as { executableName: string; windowTitle?: string };
      if (cursor && cursor.executableName === payload.executableName && cursor.windowTitle === (payload.windowTitle ?? null)) {
        cursor.causedByEventIds.push(ev.eventId); // redundant start for the already-current app — just attribute
        continue;
      }
      close(evMs);
      cursor = { executableName: payload.executableName, windowTitle: payload.windowTitle ?? null, startMs: evMs, causedByEventIds: [ev.eventId] };
    } else if (ev.type === "app.foreground_end") {
      const payload = ev.payload as { executableName: string };
      if (!cursor || cursor.executableName !== payload.executableName) {
        anomalies.push({ type: "stale_foreground_end", message: `foreground_end for ${payload.executableName} but current foreground is ${cursor?.executableName ?? "none"}`, atUtc: ev.timestampUtc, eventIds: [ev.eventId] });
        continue;
      }
      cursor.causedByEventIds.push(ev.eventId);
      close(evMs);
      cursor = null;
    }
    // app.launch/app.exit and anything else don't move the cursor.
  }

  if (cursor) {
    intervals.push({
      employeeId, deviceId, sessionId,
      executableName: cursor.executableName,
      windowTitle: cursor.windowTitle,
      startUtc: toIso(cursor.startMs),
      endUtc: null,
      causedByEventIds: [...cursor.causedByEventIds],
    });
  }

  return { intervals, anomalies };
}

// ---------------------------------------------------------------------------
// Browser URL / active-tab tracking
// ---------------------------------------------------------------------------

interface TabCursor {
  browserName: string;
  tabId: string;
  url: string;
  domain: string;
  pageTitle: string | null;
  startMs: number;
  causedByEventIds: string[];
}

export function reconcileBrowserActivity(events: AnyRawEvent[]): {
  intervals: BrowserUrlInterval[];
  anomalies: UsageAnomaly[];
} {
  const intervals: BrowserUrlInterval[] = [];
  const anomalies: UsageAnomaly[] = [];
  if (events.length === 0) return { intervals, anomalies };

  const sessionId = events[0]!.sessionId ?? "";
  const employeeId = events[0]!.employeeId;
  const deviceId = events[0]!.deviceId;

  let cursor: TabCursor | null = null;

  function close(endMs: number): void {
    if (!cursor) return;
    if (cursor.startMs === endMs) return;
    intervals.push({
      employeeId, deviceId, sessionId,
      browserName: cursor.browserName,
      tabId: cursor.tabId,
      url: cursor.url,
      domain: cursor.domain,
      pageTitle: cursor.pageTitle,
      startUtc: toIso(cursor.startMs),
      endUtc: toIso(endMs),
      causedByEventIds: [...cursor.causedByEventIds],
    });
  }

  let prevMs = -Infinity;
  for (const ev of events) {
    const evMs = toMs(ev.timestampUtc);
    if (evMs < prevMs) {
      anomalies.push({ type: "out_of_order_event", message: `Event ${ev.eventId} precedes previous event`, atUtc: ev.timestampUtc, eventIds: [ev.eventId] });
      continue;
    }
    prevMs = evMs;

    if (ev.type === "browser.tab_active_start") {
      const p = ev.payload as { browserName: string; tabId: string; url: string; domain: string; pageTitle?: string };
      if (cursor && cursor.tabId === p.tabId) {
        cursor.causedByEventIds.push(ev.eventId); // redundant activation of the already-active tab
        continue;
      }
      close(evMs);
      cursor = { browserName: p.browserName, tabId: p.tabId, url: p.url, domain: p.domain, pageTitle: p.pageTitle ?? null, startMs: evMs, causedByEventIds: [ev.eventId] };
    } else if (ev.type === "browser.tab_active_end") {
      const p = ev.payload as { tabId: string };
      if (!cursor || cursor.tabId !== p.tabId) {
        anomalies.push({ type: "stale_tab_end", message: `tab_active_end for ${p.tabId} but current active tab is ${cursor?.tabId ?? "none"}`, atUtc: ev.timestampUtc, eventIds: [ev.eventId] });
        continue;
      }
      cursor.causedByEventIds.push(ev.eventId);
      close(evMs);
      cursor = null;
    } else if (ev.type === "browser.navigation") {
      const p = ev.payload as { tabId: string; url: string; domain: string; pageTitle?: string; browserName: string };
      if (!cursor || cursor.tabId !== p.tabId) {
        // Navigation in a tab that ISN'T currently active (a background
        // tab navigating) — correctly ignored for active-URL tracking;
        // not an anomaly, just not something this interval list covers.
        continue;
      }
      close(evMs);
      cursor = { browserName: p.browserName, tabId: p.tabId, url: p.url, domain: p.domain, pageTitle: p.pageTitle ?? null, startMs: evMs, causedByEventIds: [ev.eventId] };
    }
  }

  if (cursor) {
    intervals.push({
      employeeId, deviceId, sessionId,
      browserName: cursor.browserName, tabId: cursor.tabId, url: cursor.url, domain: cursor.domain, pageTitle: cursor.pageTitle,
      startUtc: toIso(cursor.startMs), endUtc: null, causedByEventIds: [...cursor.causedByEventIds],
    });
  }

  return { intervals, anomalies };
}
