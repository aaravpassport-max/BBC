import { test } from "node:test";
import assert from "node:assert/strict";
import { reconcileAppForeground, reconcileBrowserActivity } from "../src/engine/usage-reconciler.js";
import { ev, t, minutesBetween } from "./helpers.js";

// ---------------------------------------------------------------------------
// Application foreground
// ---------------------------------------------------------------------------

test("app foreground: consecutive starts with no explicit ends (the realistic Windows pattern) still produce correct non-overlapping intervals", () => {
  const events = [
    ev("app.foreground_start", t("09:00"), { payload: { executableName: "outlook.exe", windowTitle: "Inbox" } }),
    ev("app.foreground_start", t("09:20"), { payload: { executableName: "chrome.exe", windowTitle: "Gmail" } }),
    ev("app.foreground_start", t("09:45"), { payload: { executableName: "code.exe", windowTitle: "main.ts" } }),
  ];
  const { intervals, anomalies } = reconcileAppForeground(events);
  assert.equal(anomalies.length, 0);
  assert.equal(intervals.length, 3);

  assert.equal(intervals[0]!.executableName, "outlook.exe");
  assert.equal(intervals[0]!.startUtc, t("09:00"));
  assert.equal(intervals[0]!.endUtc, t("09:20"));

  assert.equal(intervals[1]!.executableName, "chrome.exe");
  assert.equal(intervals[1]!.startUtc, t("09:20"));
  assert.equal(intervals[1]!.endUtc, t("09:45"));

  // Last app is still foreground — open-ended interval, like StateInterval.
  assert.equal(intervals[2]!.executableName, "code.exe");
  assert.equal(intervals[2]!.startUtc, t("09:45"));
  assert.equal(intervals[2]!.endUtc, null);
});

test("app foreground: explicit foreground_end closes the interval and leaves no foreground app open", () => {
  const events = [
    ev("app.foreground_start", t("09:00"), { payload: { executableName: "excel.exe" } }),
    ev("app.foreground_end", t("09:30"), { payload: { executableName: "excel.exe" } }),
    ev("app.foreground_start", t("09:35"), { payload: { executableName: "teams.exe" } }),
  ];
  const { intervals, anomalies } = reconcileAppForeground(events);
  assert.equal(anomalies.length, 0);
  assert.equal(intervals.length, 2);
  assert.equal(intervals[0]!.executableName, "excel.exe");
  assert.equal(intervals[0]!.endUtc, t("09:30"));
  // The gap 09:30-09:35 (no foreground app) correctly produces no interval
  // at all, rather than a fabricated one.
  assert.equal(intervals[1]!.executableName, "teams.exe");
  assert.equal(intervals[1]!.startUtc, t("09:35"));
});

test("app foreground: redundant start for the already-current app is attributed, not treated as a new interval", () => {
  const events = [
    ev("app.foreground_start", t("09:00"), { eventId: "a", payload: { executableName: "chrome.exe", windowTitle: "Gmail" } }),
    ev("app.foreground_start", t("09:10"), { eventId: "b", payload: { executableName: "chrome.exe", windowTitle: "Gmail" } }),
    ev("app.foreground_end", t("09:20"), { eventId: "c", payload: { executableName: "chrome.exe" } }),
  ];
  const { intervals, anomalies } = reconcileAppForeground(events);
  assert.equal(anomalies.length, 0);
  assert.equal(intervals.length, 1);
  assert.equal(intervals[0]!.startUtc, t("09:00"));
  assert.equal(intervals[0]!.endUtc, t("09:20"));
  assert.deepEqual(intervals[0]!.causedByEventIds, ["a", "b", "c"]);
});

test("app foreground: a stale foreground_end (wrong executable) is reported as an anomaly and does not corrupt the current interval", () => {
  const events = [
    ev("app.foreground_start", t("09:00"), { payload: { executableName: "chrome.exe" } }),
    ev("app.foreground_end", t("09:10"), { payload: { executableName: "excel.exe" } }), // never was foreground
    ev("app.foreground_end", t("09:30"), { payload: { executableName: "chrome.exe" } }),
  ];
  const { intervals, anomalies } = reconcileAppForeground(events);
  assert.equal(anomalies.length, 1);
  assert.equal(anomalies[0]!.type, "stale_foreground_end");
  assert.equal(intervals.length, 1);
  assert.equal(intervals[0]!.executableName, "chrome.exe");
  assert.equal(intervals[0]!.endUtc, t("09:30")); // closed by the correct, later end
});

test("app foreground: an out-of-order event is flagged as an anomaly and skipped rather than corrupting the timeline", () => {
  const events = [
    ev("app.foreground_start", t("09:30"), { payload: { executableName: "chrome.exe" } }),
    ev("app.foreground_start", t("09:00"), { payload: { executableName: "excel.exe" } }), // earlier timestamp, arrives second
  ];
  const { intervals, anomalies } = reconcileAppForeground(events);
  assert.equal(anomalies.length, 1);
  assert.equal(anomalies[0]!.type, "out_of_order_event");
  assert.equal(intervals.length, 1);
  assert.equal(intervals[0]!.executableName, "chrome.exe");
  assert.equal(intervals[0]!.endUtc, null);
});

test("app foreground: a zero-length transition (two events at the identical instant) is suppressed, not emitted as a phantom interval", () => {
  const events = [
    ev("app.foreground_start", t("09:00"), { eventId: "a", payload: { executableName: "chrome.exe" } }),
    ev("app.foreground_start", t("09:00"), { eventId: "b", payload: { executableName: "excel.exe" } }),
    ev("app.foreground_end", t("09:15"), { eventId: "c", payload: { executableName: "excel.exe" } }),
  ];
  const { intervals } = reconcileAppForeground(events);
  // Only the excel.exe interval should exist — chrome.exe never had positive duration.
  assert.equal(intervals.length, 1);
  assert.equal(intervals[0]!.executableName, "excel.exe");
  assert.equal(intervals[0]!.startUtc, t("09:00"));
  assert.equal(intervals[0]!.endUtc, t("09:15"));
});

test("app foreground: empty event list produces no intervals and no anomalies", () => {
  const { intervals, anomalies } = reconcileAppForeground([]);
  assert.equal(intervals.length, 0);
  assert.equal(anomalies.length, 0);
});

// ---------------------------------------------------------------------------
// Browser URL / active tab
// ---------------------------------------------------------------------------

test("browser: consecutive tab_active_start events with no explicit ends produce correct non-overlapping intervals", () => {
  const events = [
    ev("browser.tab_active_start", t("10:00"), { payload: { browserName: "chrome", tabId: "tab-1", url: "https://mail.google.com/", domain: "mail.google.com" } }),
    ev("browser.tab_active_start", t("10:12"), { payload: { browserName: "chrome", tabId: "tab-2", url: "https://github.com/org/repo", domain: "github.com" } }),
  ];
  const { intervals, anomalies } = reconcileBrowserActivity(events);
  assert.equal(anomalies.length, 0);
  assert.equal(intervals.length, 2);
  assert.equal(intervals[0]!.tabId, "tab-1");
  assert.equal(intervals[0]!.endUtc, t("10:12"));
  assert.equal(intervals[1]!.tabId, "tab-2");
  assert.equal(intervals[1]!.endUtc, null);
});

test("browser: navigation within the active tab splits into two intervals with the same tabId", () => {
  const events = [
    ev("browser.tab_active_start", t("10:00"), { payload: { browserName: "chrome", tabId: "tab-1", url: "https://github.com/", domain: "github.com" } }),
    ev("browser.navigation", t("10:05"), { payload: { browserName: "chrome", tabId: "tab-1", url: "https://github.com/org/repo/pulls", domain: "github.com" } }),
    ev("browser.tab_active_end", t("10:15"), { payload: { tabId: "tab-1" } }),
  ];
  const { intervals, anomalies } = reconcileBrowserActivity(events);
  assert.equal(anomalies.length, 0);
  assert.equal(intervals.length, 2);
  assert.equal(intervals[0]!.tabId, "tab-1");
  assert.equal(intervals[0]!.url, "https://github.com/");
  assert.equal(intervals[0]!.startUtc, t("10:00"));
  assert.equal(intervals[0]!.endUtc, t("10:05"));
  assert.equal(intervals[1]!.tabId, "tab-1");
  assert.equal(intervals[1]!.url, "https://github.com/org/repo/pulls");
  assert.equal(intervals[1]!.startUtc, t("10:05"));
  assert.equal(intervals[1]!.endUtc, t("10:15"));
  assert.equal(minutesBetween(intervals[0]!.startUtc, intervals[0]!.endUtc!), 5);
});

test("browser: navigation in a tab that is NOT currently active is ignored and does not affect the active interval", () => {
  const events = [
    ev("browser.tab_active_start", t("10:00"), { payload: { browserName: "chrome", tabId: "tab-1", url: "https://github.com/", domain: "github.com" } }),
    // tab-2 navigates in the background — should have zero effect here.
    ev("browser.navigation", t("10:05"), { payload: { browserName: "chrome", tabId: "tab-2", url: "https://example.com/other", domain: "example.com" } }),
    ev("browser.tab_active_end", t("10:15"), { payload: { tabId: "tab-1" } }),
  ];
  const { intervals, anomalies } = reconcileBrowserActivity(events);
  assert.equal(anomalies.length, 0);
  assert.equal(intervals.length, 1);
  assert.equal(intervals[0]!.tabId, "tab-1");
  assert.equal(intervals[0]!.url, "https://github.com/");
  assert.equal(intervals[0]!.startUtc, t("10:00"));
  assert.equal(intervals[0]!.endUtc, t("10:15"));
});

test("browser: a stale tab_active_end (wrong tabId) is reported as an anomaly and does not corrupt the active interval", () => {
  const events = [
    ev("browser.tab_active_start", t("10:00"), { payload: { browserName: "chrome", tabId: "tab-1", url: "https://github.com/", domain: "github.com" } }),
    ev("browser.tab_active_end", t("10:05"), { payload: { tabId: "tab-99" } }), // never was active
    ev("browser.tab_active_end", t("10:20"), { payload: { tabId: "tab-1" } }),
  ];
  const { intervals, anomalies } = reconcileBrowserActivity(events);
  assert.equal(anomalies.length, 1);
  assert.equal(anomalies[0]!.type, "stale_tab_end");
  assert.equal(intervals.length, 1);
  assert.equal(intervals[0]!.tabId, "tab-1");
  assert.equal(intervals[0]!.endUtc, t("10:20"));
});

test("browser: redundant tab_active_start for the already-active tab is attributed, not treated as a new interval", () => {
  const events = [
    ev("browser.tab_active_start", t("10:00"), { eventId: "a", payload: { browserName: "chrome", tabId: "tab-1", url: "https://github.com/", domain: "github.com" } }),
    ev("browser.tab_active_start", t("10:05"), { eventId: "b", payload: { browserName: "chrome", tabId: "tab-1", url: "https://github.com/", domain: "github.com" } }),
    ev("browser.tab_active_end", t("10:10"), { eventId: "c", payload: { tabId: "tab-1" } }),
  ];
  const { intervals, anomalies } = reconcileBrowserActivity(events);
  assert.equal(anomalies.length, 0);
  assert.equal(intervals.length, 1);
  assert.deepEqual(intervals[0]!.causedByEventIds, ["a", "b", "c"]);
  assert.equal(intervals[0]!.startUtc, t("10:00"));
  assert.equal(intervals[0]!.endUtc, t("10:10"));
});

test("browser: an out-of-order event is flagged as an anomaly and skipped rather than corrupting the timeline", () => {
  const events = [
    ev("browser.tab_active_start", t("10:30"), { payload: { browserName: "chrome", tabId: "tab-1", url: "https://github.com/", domain: "github.com" } }),
    ev("browser.tab_active_start", t("10:00"), { payload: { browserName: "chrome", tabId: "tab-2", url: "https://example.com/", domain: "example.com" } }),
  ];
  const { intervals, anomalies } = reconcileBrowserActivity(events);
  assert.equal(anomalies.length, 1);
  assert.equal(anomalies[0]!.type, "out_of_order_event");
  assert.equal(intervals.length, 1);
  assert.equal(intervals[0]!.tabId, "tab-1");
});

test("browser: empty event list produces no intervals and no anomalies", () => {
  const { intervals, anomalies } = reconcileBrowserActivity([]);
  assert.equal(intervals.length, 0);
  assert.equal(anomalies.length, 0);
});
