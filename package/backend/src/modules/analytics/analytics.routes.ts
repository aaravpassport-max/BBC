import { Router } from 'express';
import { asyncHandler } from '../../middleware/errorHandler';
import { requireAuth, requirePermission } from '../../middleware/auth';
import { getRevenueDashboard, getBookingFunnel, getCancellationBreakdown, getDriverUtilization } from './analytics.service';

export const analyticsRouter = Router();

function parseDateRange(req: { query: Record<string, unknown> }) {
  return {
    from: typeof req.query.from === 'string' ? req.query.from : undefined,
    to: typeof req.query.to === 'string' ? req.query.to : undefined,
  };
}

// PRD Section 20 / A.10 — all analytics reads require the broad read-only
// analytics.view permission (Section 22), never open to every authenticated
// user, since this exposes aggregate business data.
analyticsRouter.get(
  '/revenue',
  requireAuth,
  requirePermission('analytics', 'view'),
  asyncHandler(async (req, res) => {
    const dashboard = await getRevenueDashboard(parseDateRange(req));
    res.status(200).json(dashboard);
  })
);

analyticsRouter.get(
  '/funnel/booking',
  requireAuth,
  requirePermission('analytics', 'view'),
  asyncHandler(async (req, res) => {
    const funnel = await getBookingFunnel(parseDateRange(req));
    res.status(200).json(funnel);
  })
);

analyticsRouter.get(
  '/cancellations',
  requireAuth,
  requirePermission('analytics', 'view'),
  asyncHandler(async (req, res) => {
    const breakdown = await getCancellationBreakdown(parseDateRange(req));
    res.status(200).json(breakdown);
  })
);

analyticsRouter.get(
  '/driver-utilization',
  requireAuth,
  requirePermission('analytics', 'view'),
  asyncHandler(async (req, res) => {
    const utilization = await getDriverUtilization(parseDateRange(req));
    res.status(200).json(utilization);
  })
);
