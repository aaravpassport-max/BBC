/**
 * Raw event types collected by the desktop agent and browser extension.
 *
 * Design rule (see docs/architecture.md): raw events are the source of
 * truth. Everything else (activity segments, break intervals, reports) is
 * DERIVED from these events and must be reproducible by replaying them.
 * Never mutate or delete a raw event once persisted.
 */

export type EventSource = "windows-agent" | "browser-extension" | "admin-correction";

/** Every raw event the system can record. */
export type RawEventType =
  | "pc.startup"
  | "pc.shutdown"
  | "pc.sleep"
  | "pc.wake"
  | "session.signin"
  | "session.signout"
  | "session.lock"
  | "session.unlock"
  | "input.activity" // heartbeat: meaningful keyboard/mouse input observed
  | "app.foreground_start"
  | "app.foreground_end"
  | "app.launch"
  | "app.exit"
  | "browser.tab_active_start"
  | "browser.tab_active_end"
  | "browser.navigation"
  | "agent.start"
  | "agent.stop"
  | "agent.heartbeat_missed" // used to derive monitoring gaps
  | "network.disconnected"
  | "network.reconnected";

export interface BaseEvent {
  /** Stable, globally unique ID. Agent generates via UUID; used for idempotent writes. */
  eventId: string;
  employeeId: string;
  deviceId: string;
  /** Null for events that occur before/outside an attendance session (e.g. pc.startup before signin). */
  sessionId: string | null;
  type: RawEventType;
  /** UTC ISO-8601 timestamp. Display layer converts to local time zone. */
  timestampUtc: string;
  source: EventSource;
  /**
   * True if the agent reconstructed this timestamp after the fact (e.g. an
   * unexpected shutdown with no shutdown event — the agent infers the last
   * known-good time on next startup). Reports MUST visibly flag estimated
   * events; never render them as if precisely observed.
   */
  isEstimated: boolean;
  /** Arbitrary event-specific payload (app name, URL, etc.) */
  payload: Record<string, unknown>;
}

export interface InputActivityEvent extends BaseEvent {
  type: "input.activity";
  payload: {
    /** "keyboard" | "mouse" | "other-verified-activity" (e.g. admin-approved exception) */
    kind: "keyboard" | "mouse" | "exception";
    /** When kind === "exception", the configured exception reason (meeting, video, etc.) */
    exceptionReason?: string;
  };
}

export interface AppForegroundEvent extends BaseEvent {
  type: "app.foreground_start" | "app.foreground_end";
  payload: {
    executableName: string;
    processId: number;
    windowTitle?: string;
  };
}

export interface BrowserTabEvent extends BaseEvent {
  type: "browser.tab_active_start" | "browser.tab_active_end" | "browser.navigation";
  payload: {
    browserName: string;
    tabId: string;
    url: string;
    domain: string;
    pageTitle?: string;
  };
}

export type AnyRawEvent = BaseEvent | InputActivityEvent | AppForegroundEvent | BrowserTabEvent;
