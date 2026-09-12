import { Router } from 'express';
import { asyncHandler } from '../../middleware/errorHandler';
import { requireAuth } from '../../middleware/auth';
import { getLoyaltySummary, getLoyaltyHistory } from './loyalty.service';

export const loyaltyRouter = Router();

loyaltyRouter.get(
  '/me',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await getLoyaltySummary(req.user!.userId));
  })
);

loyaltyRouter.get(
  '/history',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await getLoyaltyHistory(req.user!.userId));
  })
);
