/**
 * Public entry point for @attendance-tracker/core. package.json's "main"
 * has pointed at dist/index.js since Stage 1, but this file — the thing
 * that actually makes that true — didn't exist until Stage 2's cleanup
 * pass noticed the gap. Nothing here is new logic; it's a barrel
 * re-export of what's already proven by the test suite, so an external
 * consumer (a scheduled recompute job, eventually) has one place to
 * import from instead of reaching into src/ paths directly.
 */

export * from "./domain/events.js";
export * from "./domain/state.js";
export * from "./domain/session.js";
export * from "./domain/usage.js";

export { reconcileSession, DEFAULT_RECONCILER_CONFIG, type ReconcilerConfig } from "./engine/activity-reconciler.js";
export {
  detectSyntheticInputRuns,
  DEFAULT_ANOMALY_CONFIG,
  type AnomalyDetectionConfig,
  type SyntheticInputFlag,
} from "./engine/anomaly-detection.js";
export { hashPassword, verifyPassword, generateToken, hashToken } from "./engine/auth.js";
export {
  createAdminUser,
  loginAdmin,
  setEmployeePassword,
  loginEmployee,
  enrollDevice,
  revokeDeviceToken,
  loginDevice,
  resolveSession,
  revokeSession,
  submitCorrectionRequest,
  getCorrectionRequest,
  listCorrectionRequests,
  reviewCorrectionRequest,
  AuthError,
  type Role,
  type AuthSession,
  type CorrectionRequestInput,
  type CorrectionRequestRow,
} from "./db/auth-repository.js";
export { reconcileAppForeground, reconcileBrowserActivity, type UsageAnomaly } from "./engine/usage-reconciler.js";
export { parseTarget, resolveClassification, summarizeUsage, type UsageSummary } from "./engine/classification.js";
export {
  buildDailyReport,
  buildMonthlyReport,
  dailyReportToCsv,
  monthlyConsolidatedCsv,
  monthlyDailyBreakdownCsv,
  type DailyReport,
  type DailyReportStatus,
  type DailySessionDetail,
  type MonthlyReport,
  type MonthlyReportTotals,
  type MonthlyReportAverages,
} from "./engine/reporting.js";

export {
  openDatabase,
  upsertEmployee,
  upsertDevice,
  recordNoticeShown,
  recordNoticeAcknowledged,
  hasAcknowledgedNotice,
  TransparencyGateError,
  ingestRawEvent,
  ingestRawEvents,
  getEventsForSession,
  ensureAttendanceSession,
  closeAttendanceSession,
  recomputeSession,
  getStateIntervals,
  getBreakIntervals,
  recomputeUsage,
  getAppForegroundIntervals,
  getBrowserUrlIntervals,
  addClassificationRule,
  endClassificationRule,
  getAllClassificationRules,
  getAllEmployees,
  getSessionsForEmployeeOnDate,
  getSessionById,
  getRetentionDays,
  setRetentionDays,
  assertPruneAllowed,
  getDailySessionDetails,
  getDailyReport,
  getMonthlyReport,
  queryAttendanceHistory,
  type IngestResult,
  type RecomputeResult,
  type RecomputeUsageResult,
  type EmployeeRow,
  type SessionRow,
  type HistoryFilters,
  type HistoryRow,
} from "./db/repository.js";
