/**
 * Stage 2 domain types: application foreground tracking and browser URL
 * tracking. Same rule as everywhere else in this codebase — these are
 * DERIVED from raw_events by a pure engine function, never hand-authored.
 */

export interface AppForegroundInterval {
  employeeId: string;
  deviceId: string;
  sessionId: string;
  executableName: string;
  windowTitle: string | null;
  startUtc: string;
  /** Null while this app is still the foreground app (session ongoing). */
  endUtc: string | null;
  causedByEventIds: string[];
}

export interface BrowserUrlInterval {
  employeeId: string;
  deviceId: string;
  sessionId: string;
  browserName: string;
  tabId: string;
  url: string;
  domain: string;
  pageTitle: string | null;
  startUtc: string;
  endUtc: string | null;
  causedByEventIds: string[];
}

export type WorkClassificationTarget =
  | { kind: "app"; executableName: string }
  | { kind: "domain"; domain: string };
