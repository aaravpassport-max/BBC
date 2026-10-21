/**
 * Stage 2b: classifying application/browser usage as productive, neutral,
 * unproductive, excluded, or (the default) unclassified, and summing
 * durations per classification, per app, and per domain.
 *
 * Pure functions again, same discipline as the reconcilers: given
 * intervals and rules, compute a summary — no DB access, no clock reads
 * beyond the explicit asOfUtc parameter callers must supply.
 */

import type { WorkClassification, ClassificationRule } from "../domain/session.js";
import type { AppForegroundInterval, BrowserUrlInterval, WorkClassificationTarget } from "../domain/usage.js";

export function parseTarget(target: string): WorkClassificationTarget | null {
  if (target.startsWith("app:")) return { kind: "app", executableName: target.slice(4) };
  if (target.startsWith("domain:")) return { kind: "domain", domain: target.slice(7) };
  return null; // malformed rule row — callers should skip it, not crash on it
}

function targetMatches(ruleTarget: WorkClassificationTarget, candidate: WorkClassificationTarget): boolean {
  if (ruleTarget.kind !== candidate.kind) return false;
  if (ruleTarget.kind === "app" && candidate.kind === "app") {
    return ruleTarget.executableName.toLowerCase() === candidate.executableName.toLowerCase();
  }
  if (ruleTarget.kind === "domain" && candidate.kind === "domain") {
    // A rule for "google.com" also covers "mail.google.com", "docs.google.com",
    // etc. — the common, useful case for an admin who wants to classify a
    // whole property without enumerating every subdomain. An exact-domain
    // rule ("mail.google.com") still matches only that domain, since it's
    // not a suffix of a shorter rule target the other way around.
    const ruleDomain = ruleTarget.domain.toLowerCase();
    const candidateDomain = candidate.domain.toLowerCase();
    return candidateDomain === ruleDomain || candidateDomain.endsWith("." + ruleDomain);
  }
  return false;
}

function toMs(iso: string): number {
  const ms = Date.parse(iso);
  if (Number.isNaN(ms)) throw new Error(`Invalid ISO timestamp: ${iso}`);
  return ms;
}

/**
 * Resolves which classification applies to one target at one instant.
 * Among rules whose target matches AND whose effective window contains
 * atUtc, the rule with the LATEST effectiveFromUtc wins — i.e. a more
 * recently created override beats an older standing rule, which matches
 * how an admin actually expects "I just reclassified this app" to behave.
 * Falls back to "unclassified" when nothing matches, never throws for an
 * unmatched target — unclassified is a normal, expected outcome for most
 * usage, not an error.
 */
export function resolveClassification(
  rules: ClassificationRule[],
  candidate: WorkClassificationTarget,
  atUtc: string,
): WorkClassification {
  const atMs = toMs(atUtc);
  let best: ClassificationRule | null = null;
  let bestFromMs = -Infinity;

  for (const rule of rules) {
    const parsed = parseTarget(rule.target);
    if (!parsed || !targetMatches(parsed, candidate)) continue;
    const fromMs = toMs(rule.effectiveFromUtc);
    if (fromMs > atMs) continue; // not yet effective at this instant
    if (rule.effectiveToUtc !== null && toMs(rule.effectiveToUtc) <= atMs) continue; // already expired
    if (fromMs > bestFromMs) {
      best = rule;
      bestFromMs = fromMs;
    }
  }

  return best?.classification ?? "unclassified";
}

export interface UsageSummary {
  totalMs: number;
  byClassification: Record<WorkClassification, number>;
  byExecutable: Array<{ executableName: string; ms: number; classification: WorkClassification }>;
  byDomain: Array<{ domain: string; ms: number; classification: WorkClassification }>;
}

const EMPTY_BY_CLASSIFICATION: Record<WorkClassification, number> = {
  productive: 0,
  neutral: 0,
  unproductive: 0,
  unclassified: 0,
  excluded: 0,
};

/**
 * Sums app-foreground and browser-URL interval durations into a
 * per-classification / per-app / per-domain breakdown. An interval with a
 * null endUtc (still ongoing) is treated as running until `asOfUtc` —
 * callers pass "now" for a live dashboard, or a day's end boundary for a
 * historical report; this function never reads the clock itself.
 *
 * App and browser time are summed into the SAME byClassification totals
 * (an employee's productive time is productive whether it happened in an
 * app or a browser tab) but kept in separate byExecutable/byDomain
 * breakdowns, since "which app" and "which site" are different questions
 * an admin might ask.
 */
export function summarizeUsage(
  appIntervals: AppForegroundInterval[],
  browserIntervals: BrowserUrlInterval[],
  rules: ClassificationRule[],
  asOfUtc: string,
): UsageSummary {
  const asOfMs = toMs(asOfUtc);
  const byClassification: Record<WorkClassification, number> = { ...EMPTY_BY_CLASSIFICATION };
  const execTotals = new Map<string, { ms: number; classification: WorkClassification }>();
  const domainTotals = new Map<string, { ms: number; classification: WorkClassification }>();
  let totalMs = 0;

  function durationMs(startUtc: string, endUtc: string | null): number {
    const startMs = toMs(startUtc);
    const endMs = endUtc !== null ? toMs(endUtc) : asOfMs;
    return Math.max(0, endMs - startMs);
  }

  for (const interval of appIntervals) {
    const ms = durationMs(interval.startUtc, interval.endUtc);
    if (ms === 0) continue;
    const classification = resolveClassification(rules, { kind: "app", executableName: interval.executableName }, interval.startUtc);
    totalMs += ms;
    byClassification[classification] += ms;
    const existing = execTotals.get(interval.executableName);
    execTotals.set(interval.executableName, { ms: (existing?.ms ?? 0) + ms, classification });
  }

  for (const interval of browserIntervals) {
    const ms = durationMs(interval.startUtc, interval.endUtc);
    if (ms === 0) continue;
    const classification = resolveClassification(rules, { kind: "domain", domain: interval.domain }, interval.startUtc);
    totalMs += ms;
    byClassification[classification] += ms;
    const existing = domainTotals.get(interval.domain);
    domainTotals.set(interval.domain, { ms: (existing?.ms ?? 0) + ms, classification });
  }

  return {
    totalMs,
    byClassification,
    byExecutable: [...execTotals.entries()]
      .map(([executableName, v]) => ({ executableName, ms: v.ms, classification: v.classification }))
      .sort((a, b) => b.ms - a.ms),
    byDomain: [...domainTotals.entries()]
      .map(([domain, v]) => ({ domain, ms: v.ms, classification: v.classification }))
      .sort((a, b) => b.ms - a.ms),
  };
}
