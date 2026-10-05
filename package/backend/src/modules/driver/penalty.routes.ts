import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import {
  getWithdrawableBalance,
  requestWithdrawal,
  issuePenalty,
  listPenalties,
  disputePenalty,
  resolvePenaltyDispute,
  listAdminPenalties,
} from './penalty.service';
import { getTransactionHistory } from '../wallet/wallet.service';

const withdrawSchema = z.object({
  amount: z.number().positive(),
  mode: z.enum(['instant', 'standard']),
});

const issuePenaltySchema = z.object({
  driver_id: z.string().uuid(),
  amount: z.number().positive(),
  reason_code: z.enum(['LATE_ARRIVAL', 'TRIP_CANCELLED_POST_ACCEPT', 'DOCUMENT_VIOLATION', 'OTHER']),
  reason_note: z.string().optional(),
  linked_booking_id: z.string().uuid().optional(),
});

const disputeSchema = z.object({ note: z.string().min(1) });

const resolveDisputeSchema = z.object({
  resolution: z.enum(['upheld', 'reversed']),
  resolution_note: z.string().min(1),
});

export const penaltyRouter = Router();

// PRD Section A.2 — Withdraw Funds
penaltyRouter.get(
  '/wallet/withdrawable',
  requireAuth,
  asyncHandler(async (req, res) => {
    const balance = await getWithdrawableBalance(req.user!.userId);
    res.status(200).json(balance);
  })
);

penaltyRouter.post(
  '/wallet/withdraw',
  requireAuth,
  validateBody(withdrawSchema),
  asyncHandler(async (req, res) => {
    const { amount, mode } = req.body;
    const result = await requestWithdrawal({ driverId: req.user!.userId, amount, mode });
    res.status(202).json(result);
  })
);

// P1 gap-analysis item: a driver's earnings history. The underlying
// function (getTransactionHistory) already existed and was generic — it
// just had never been called with ownerType='driver' from anywhere, only
// 'customer' from wallet.routes.ts. No new backend logic needed, only
// exposing what already worked to the driver-facing surface.
penaltyRouter.get(
  '/wallet/transactions',
  requireAuth,
  asyncHandler(async (req, res) => {
    const type = req.query.type as string | undefined;
    const transactions = await getTransactionHistory('driver', req.user!.userId, type);
    res.status(200).json(transactions);
  })
);

// PRD Section A.2 — Penalties/Violations
penaltyRouter.get(
  '/penalties',
  requireAuth,
  asyncHandler(async (req, res) => {
    const penalties = await listPenalties(req.user!.userId);
    res.status(200).json(penalties);
  })
);

penaltyRouter.post(
  '/penalties/:id/dispute',
  requireAuth,
  validateBody(disputeSchema),
  asyncHandler(async (req, res) => {
    await disputePenalty({ penaltyId: req.params.id as string, driverId: req.user!.userId, note: req.body.note });
    res.status(200).json({ disputed: true });
  })
);

// Admin-only (PRD Section 22 RBAC) — issuing and resolving penalties.
penaltyRouter.get(
  '/admin/penalties',
  requireAuth,
  requirePermission('driver', 'suspend'),
  asyncHandler(async (req, res) => {
    const status = req.query.status as string | undefined;
    const penalties = await listAdminPenalties(status);
    res.status(200).json(penalties);
  })
);

penaltyRouter.post(
  '/admin/penalties',
  requireAuth,
  requirePermission('driver', 'suspend'), // same operational trust tier as suspension
  validateBody(issuePenaltySchema),
  asyncHandler(async (req, res) => {
    const { driver_id, amount, reason_code, reason_note, linked_booking_id } = req.body;
    const result = await issuePenalty({
      driverId: driver_id,
      amount,
      reasonCode: reason_code,
      reasonNote: reason_note,
      linkedBookingId: linked_booking_id,
      issuedBy: req.user!.userId,
    });
    res.status(201).json(result);
  })
);

penaltyRouter.post(
  '/admin/penalties/:id/resolve',
  requireAuth,
  requirePermission('driver', 'suspend'),
  validateBody(resolveDisputeSchema),
  asyncHandler(async (req, res) => {
    const { resolution, resolution_note } = req.body;
    await resolvePenaltyDispute({
      penaltyId: req.params.id as string,
      resolution,
      resolutionNote: resolution_note,
      resolvedBy: req.user!.userId,
    });
    res.status(200).json({ resolved: true });
  })
);
