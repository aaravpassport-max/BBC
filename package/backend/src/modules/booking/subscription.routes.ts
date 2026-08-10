import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import {
  purchaseSubscription,
  getMySubscription,
  cancelSubscription,
  attemptRenewal,
  reactivateSubscription,
} from './subscription.service';

const purchaseSchema = z.object({ plan_id: z.string() });

export const subscriptionRouter = Router();

// PRD Screen 59
subscriptionRouter.post(
  '/purchase',
  requireAuth,
  validateBody(purchaseSchema),
  asyncHandler(async (req, res) => {
    const result = await purchaseSubscription(req.user!.userId, req.body.plan_id);
    res.status(201).json(result);
  })
);

subscriptionRouter.get(
  '/me',
  requireAuth,
  asyncHandler(async (req, res) => {
    const sub = await getMySubscription(req.user!.userId);
    res.status(200).json(sub);
  })
);

subscriptionRouter.post(
  '/:id/cancel',
  requireAuth,
  asyncHandler(async (req, res) => {
    await cancelSubscription(req.user!.userId, req.params.id as string);
    res.status(200).json({ cancelled: true });
  })
);

subscriptionRouter.post(
  '/:id/reactivate',
  requireAuth,
  asyncHandler(async (req, res) => {
    await reactivateSubscription(req.user!.userId, req.params.id as string);
    res.status(200).json({ reactivated: true });
  })
);

// Dev-only manual trigger, standing in for the real scheduled renewal job (PRD 19A.1).
subscriptionRouter.post(
  '/dev/attempt-renewal/:id',
  requireAuth,
  asyncHandler(async (req, res) => {
    const { simulate_success } = req.body;
    const result = await attemptRenewal(req.params.id as string, !!simulate_success);
    res.status(200).json(result);
  })
);
