import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { getReferralSummary, redeemReferralCode } from './referral.service';

const redeemSchema = z.object({
  referral_code: z.string().min(4).max(12),
});

export const referralRouter = Router();

// PRD Screens 45-46
referralRouter.get(
  '/summary',
  requireAuth,
  asyncHandler(async (req, res) => {
    const summary = await getReferralSummary(req.user!.userId);
    res.status(200).json(summary);
  })
);

referralRouter.post(
  '/redeem',
  requireAuth,
  validateBody(redeemSchema),
  asyncHandler(async (req, res) => {
    await redeemReferralCode(req.user!.userId, req.body.referral_code);
    res.status(200).json({ redeemed: true });
  })
);
