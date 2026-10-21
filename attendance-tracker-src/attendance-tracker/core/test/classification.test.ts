import { test } from "node:test";
import assert from "node:assert/strict";
import { parseTarget, resolveClassification, summarizeUsage } from "../src/engine/classification.js";
import type { ClassificationRule } from "../src/domain/session.js";
import type { AppForegroundInterval, BrowserUrlInterval } from "../src/domain/usage.js";
import { t } from "./helpers.js";

function rule(partial: Partial<ClassificationRule> & Pick<ClassificationRule, "target" | "classification">): ClassificationRule {
  return {
    ruleId: partial.ruleId ?? `rule-${Math.random()}`,
    target: partial.target,
    classification: partial.classification,
    effectiveFromUtc: partial.effectiveFromUtc ?? t("00:00"),
    effectiveToUtc: partial.effectiveToUtc ?? null,
    createdByAdminId: partial.createdByAdminId ?? "admin-1",
  };
}

test("parseTarget: parses app: and domain: prefixes, returns null for anything else", () => {
  assert.deepEqual(parseTarget("app:chrome.exe"), { kind: "app", executableName: "chrome.exe" });
  assert.deepEqual(parseTarget("domain:github.com"), { kind: "domain", domain: "github.com" });
  assert.equal(parseTarget("garbage"), null);
});

test("resolveClassification: an app rule matches case-insensitively", () => {
  const rules = [rule({ target: "app:Chrome.EXE", classification: "unproductive" })];
  assert.equal(resolveClassification(rules, { kind: "app", executableName: "chrome.exe" }, t("09:00")), "unproductive");
});

test("resolveClassification: a domain rule also matches its subdomains, not just the exact domain", () => {
  const rules = [rule({ target: "domain:google.com", classification: "neutral" })];
  assert.equal(resolveClassification(rules, { kind: "domain", domain: "mail.google.com" }, t("09:00")), "neutral");
  assert.equal(resolveClassification(rules, { kind: "domain", domain: "google.com" }, t("09:00")), "neutral");
  assert.equal(resolveClassification(rules, { kind: "domain", domain: "notgoogle.com" }, t("09:00")), "unclassified");
});

test("resolveClassification: an exact-subdomain rule does not leak to the parent domain or siblings", () => {
  const rules = [rule({ target: "domain:mail.google.com", classification: "productive" })];
  assert.equal(resolveClassification(rules, { kind: "domain", domain: "mail.google.com" }, t("09:00")), "productive");
  assert.equal(resolveClassification(rules, { kind: "domain", domain: "google.com" }, t("09:00")), "unclassified");
  assert.equal(resolveClassification(rules, { kind: "domain", domain: "docs.google.com" }, t("09:00")), "unclassified");
});

test("resolveClassification: no matching rule falls back to unclassified, not an error", () => {
  assert.equal(resolveClassification([], { kind: "app", executableName: "notepad.exe" }, t("09:00")), "unclassified");
});

test("resolveClassification: respects effectiveFromUtc / effectiveToUtc windows", () => {
  const rules = [rule({ target: "app:slack.exe", classification: "productive", effectiveFromUtc: t("10:00"), effectiveToUtc: t("12:00") })];
  assert.equal(resolveClassification(rules, { kind: "app", executableName: "slack.exe" }, t("09:00")), "unclassified", "before the rule takes effect");
  assert.equal(resolveClassification(rules, { kind: "app", executableName: "slack.exe" }, t("11:00")), "productive", "inside the window");
  assert.equal(resolveClassification(rules, { kind: "app", executableName: "slack.exe" }, t("12:00")), "unclassified", "effectiveToUtc is exclusive — expired exactly at the boundary");
  assert.equal(resolveClassification(rules, { kind: "app", executableName: "slack.exe" }, t("13:00")), "unclassified", "after the rule expired");
});

test("resolveClassification: when two rules both match, the more recently created (later effectiveFromUtc) one wins", () => {
  const rules = [
    rule({ ruleId: "old", target: "app:chrome.exe", classification: "unproductive", effectiveFromUtc: t("00:00") }),
    rule({ ruleId: "new", target: "app:chrome.exe", classification: "productive", effectiveFromUtc: t("08:00") }),
  ];
  assert.equal(resolveClassification(rules, { kind: "app", executableName: "chrome.exe" }, t("09:00")), "productive");
  // Before the newer rule's own effective window started, the older one still applies.
  assert.equal(resolveClassification(rules, { kind: "app", executableName: "chrome.exe" }, t("01:00")), "unproductive");
});

test("summarizeUsage: sums app and browser durations into totals, byClassification, byExecutable, byDomain", () => {
  const rules = [
    rule({ target: "app:code.exe", classification: "productive" }),
    rule({ target: "domain:github.com", classification: "productive" }),
    rule({ target: "domain:youtube.com", classification: "unproductive" }),
  ];
  const appIntervals: AppForegroundInterval[] = [
    { employeeId: "e1", deviceId: "d1", sessionId: "s1", executableName: "code.exe", windowTitle: null, startUtc: t("09:00"), endUtc: t("09:30"), causedByEventIds: [] },
    { employeeId: "e1", deviceId: "d1", sessionId: "s1", executableName: "solitaire.exe", windowTitle: null, startUtc: t("09:30"), endUtc: t("09:40"), causedByEventIds: [] },
  ];
  const browserIntervals: BrowserUrlInterval[] = [
    { employeeId: "e1", deviceId: "d1", sessionId: "s1", browserName: "chrome", tabId: "t1", url: "https://github.com/x", domain: "github.com", pageTitle: null, startUtc: t("09:40"), endUtc: t("09:55"), causedByEventIds: [] },
    { employeeId: "e1", deviceId: "d1", sessionId: "s1", browserName: "chrome", tabId: "t2", url: "https://youtube.com/watch", domain: "youtube.com", pageTitle: null, startUtc: t("09:55"), endUtc: null, causedByEventIds: [] },
  ];

  const summary = summarizeUsage(appIntervals, browserIntervals, rules, t("10:00"));

  assert.equal(summary.totalMs, 60 * 60000); // 30+10+15+5 minutes = 60 minutes total
  assert.equal(summary.byClassification.productive, 45 * 60000); // code.exe 30 + github.com 15
  assert.equal(summary.byClassification.unproductive, 5 * 60000); // youtube.com, open-ended, treated as running until asOfUtc
  assert.equal(summary.byClassification.unclassified, 10 * 60000); // solitaire.exe

  assert.equal(summary.byExecutable.length, 2);
  assert.equal(summary.byExecutable[0]!.executableName, "code.exe"); // sorted descending by ms
  assert.equal(summary.byExecutable[0]!.ms, 30 * 60000);
  assert.equal(summary.byExecutable[0]!.classification, "productive");

  assert.equal(summary.byDomain.length, 2);
  assert.equal(summary.byDomain[0]!.domain, "github.com");
  assert.equal(summary.byDomain[0]!.ms, 15 * 60000);
});

test("summarizeUsage: zero-length intervals (start === end) contribute nothing and do not appear in the breakdowns", () => {
  const appIntervals: AppForegroundInterval[] = [
    { employeeId: "e1", deviceId: "d1", sessionId: "s1", executableName: "chrome.exe", windowTitle: null, startUtc: t("09:00"), endUtc: t("09:00"), causedByEventIds: [] },
  ];
  const summary = summarizeUsage(appIntervals, [], [], t("10:00"));
  assert.equal(summary.totalMs, 0);
  assert.equal(summary.byExecutable.length, 0);
});

test("summarizeUsage: an empty rule set classifies everything as unclassified", () => {
  const appIntervals: AppForegroundInterval[] = [
    { employeeId: "e1", deviceId: "d1", sessionId: "s1", executableName: "anything.exe", windowTitle: null, startUtc: t("09:00"), endUtc: t("09:10"), causedByEventIds: [] },
  ];
  const summary = summarizeUsage(appIntervals, [], [], t("10:00"));
  assert.equal(summary.byClassification.unclassified, 10 * 60000);
  assert.equal(summary.byClassification.productive, 0);
});
