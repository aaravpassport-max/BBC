import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import {
  listRateCards,
  createRateCard,
  publishRateCard,
  listDrivers,
  suspendDriver,
  reinstateDriver,
  listFraudQueue,
  resolveFraudFlag,
  updateRateCardSurgeTiers,
  listSurgeZones,
  getOfflineReasonAnalytics,
} from './admin.service';

const createRateCardSchema = z.object({
  city_id: z.string().uuid(),
  vehicle_category_id: z.string().uuid(),
  base_fare: z.number().positive(),
  per_km_rate: z.number().positive(),
  per_min_rate: z.number().min(0).optional(),
  minimum_fare: z.number().positive(),
  platform_fee: z.number().min(0).optional(),
  tax_rate_pct: z.number().min(0).optional(),
});

const publishSchema = z.object({ expected_version: z.number().int() });

const suspendSchema = z.object({
  reason_code: z.enum(['FRAUD_SUSPECTED', 'DOCUMENT_EXPIRED', 'SAFETY_COMPLAINT', 'LOW_RATING', 'OTHER']),
  note: z.string().optional(),
});

const resolveFraudSchema = z.object({
  action: z.enum(['clear', 'escalate', 'hold', 'suspend']),
  note: z.string().min(1),
});

const surgeTiersSchema = z.object({
  surge_tiers: z.array(z.number().min(1)).min(1),
  surge_cap: z.number().min(1),
});

export const adminRouter = Router();

// PRD 9A.1 — all Admin actions require RBAC permissions (Section 22).
adminRouter.get(
  '/pricing/rate-cards',
  requireAuth,
  requirePermission('pricing', 'edit'),
  asyncHandler(async (req, res) => {
    const cityId = typeof req.query.city_id === 'string' ? req.query.city_id : undefined;
    const vehicleCategoryId = typeof req.query.vehicle_category_id === 'string' ? req.query.vehicle_category_id : undefined;
    const cards = await listRateCards({ cityId, vehicleCategoryId });
    res.status(200).json(cards);
  })
);

adminRouter.post(
  '/pricing/rate-cards',
  requireAuth,
  requirePermission('pricing', 'edit'),
  validateBody(createRateCardSchema),
  asyncHandler(async (req, res) => {
    const { city_id, vehicle_category_id, ...coefficients } = req.body;
    const result = await createRateCard({
      cityId: city_id,
      vehicleCategoryId: vehicle_category_id,
      coefficients,
      createdBy: req.user!.userId,
    });
    res.status(201).json(result);
  })
);

adminRouter.post(
  '/pricing/rate-cards/:id/publish',
  requireAuth,
  requirePermission('pricing', 'edit'),
  validateBody(publishSchema),
  asyncHandler(async (req, res) => {
    const result = await publishRateCard({
      rateCardId: req.params.id as string,
      expectedVersion: req.body.expected_version,
    });
    res.status(200).json(result);
  })
);

adminRouter.patch(
  '/pricing/rate-cards/:id/surge',
  requireAuth,
  requirePermission('pricing', 'edit'),
  validateBody(surgeTiersSchema),
  asyncHandler(async (req, res) => {
    await updateRateCardSurgeTiers({
      rateCardId: req.params.id as string,
      surgeTiers: req.body.surge_tiers,
      surgeCap: req.body.surge_cap,
    });
    res.status(200).json({ updated: true });
  })
);

adminRouter.get(
  '/geo/surge-zones',
  requireAuth,
  requirePermission('pricing', 'edit'),
  asyncHandler(async (req, res) => {
    const cityId = typeof req.query.city_id === 'string' ? req.query.city_id : undefined;
    const zones = await listSurgeZones(cityId);
    res.status(200).json(zones);
  })
);

adminRouter.get(
  '/drivers/offline-analytics',
  requireAuth,
  requirePermission('driver', 'suspend'),
  asyncHandler(async (req, res) => {
    const days = typeof req.query.days === 'string' ? parseInt(req.query.days, 10) : 7;
    const analytics = await getOfflineReasonAnalytics(Number.isFinite(days) ? days : 7);
    res.status(200).json(analytics);
  })
);

// PRD 9A.2
adminRouter.get(
  '/drivers',
  requireAuth,
  requirePermission('driver', 'suspend'),
  asyncHandler(async (req, res) => {
    const search = typeof req.query.search === 'string' ? req.query.search : undefined;
    const drivers = await listDrivers({ search });
    res.status(200).json(drivers);
  })
);

adminRouter.post(
  '/drivers/:id/suspend',
  requireAuth,
  requirePermission('driver', 'suspend'),
  validateBody(suspendSchema),
  asyncHandler(async (req, res) => {
    const { reason_code, note } = req.body;
    await suspendDriver({ driverId: req.params.id as string, reasonCode: reason_code, note, actorId: req.user!.userId });
    res.status(200).json({ suspended: true });
  })
);

adminRouter.post(
  '/drivers/:id/reinstate',
  requireAuth,
  requirePermission('driver', 'suspend'),
  asyncHandler(async (req, res) => {
    await reinstateDriver({ driverId: req.params.id as string, actorId: req.user!.userId });
    res.status(200).json({ reinstated: true });
  })
);

// PRD 17A.1
adminRouter.get(
  '/fraud/queue',
  requireAuth,
  requirePermission('fraud', 'review'),
  asyncHandler(async (req, res) => {
    const status = req.query.status as string | undefined;
    const queue = await listFraudQueue(status);
    res.status(200).json(queue);
  })
);

adminRouter.post(
  '/fraud/queue/:id/resolve',
  requireAuth,
  requirePermission('fraud', 'review'),
  validateBody(resolveFraudSchema),
  asyncHandler(async (req, res) => {
    const { action, note } = req.body;
    await resolveFraudFlag({ flagId: req.params.id as string, action, note, actorId: req.user!.userId });
    res.status(200).json({ resolved: true });
  })
);
