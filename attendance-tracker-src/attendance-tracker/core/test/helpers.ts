import type { AnyRawEvent, RawEventType } from "../src/domain/events.js";

let counter = 0;

/** Builds a minimal raw event for tests. Keeps test cases readable. */
export function ev(
  type: RawEventType,
  timestampUtc: string,
  opts: Partial<AnyRawEvent> = {},
): AnyRawEvent {
  counter += 1;
  return {
    eventId: opts.eventId ?? `ev-${counter}`,
    employeeId: opts.employeeId ?? "emp-1",
    deviceId: opts.deviceId ?? "dev-1",
    sessionId: opts.sessionId ?? "sess-1",
    type,
    timestampUtc,
    source: opts.source ?? "windows-agent",
    isEstimated: opts.isEstimated ?? false,
    payload: opts.payload ?? {},
  } as AnyRawEvent;
}

/** Minutes-from-midnight helper for readable fixture timestamps on one fixed day. */
export function t(hhmm: string): string {
  const [h, m] = hhmm.split(":").map(Number);
  const d = new Date(Date.UTC(2026, 0, 1, h, m, 0, 0));
  return d.toISOString();
}

export function minutesBetween(startIso: string, endIso: string): number {
  return (Date.parse(endIso) - Date.parse(startIso)) / 60000;
}
