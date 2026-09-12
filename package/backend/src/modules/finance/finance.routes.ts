import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import {
  listPayoutBatches,
  getPayoutBatchDetail,
  generatePayoutBatch,
  approvePayoutBatch,
  runLedgerIntegrityCheck,
} from './settlement.service';

const generateBatchSchema = z.object({
  period_start: z.string(),
  period_end: z.string(),
});

export const financeRouter = Router();

financeRouter.get(
  '/payout-batches',
  requireAuth,
  requirePermission('finance', 'review'),
  asyncHandler(async (_req, res) => {
    res.status(200).json(await listPayoutBatches());
  })
);

financeRouter.get(
  '/payout-batches/:id',
  requireAuth,
  requirePermission('finance', 'review'),
  asyncHandler(async (req, res) => {
    const detail = await getPayoutBatchDetail(req.params.id as string);
    res.status(200).json(detail);
  })
);

financeRouter.post(
  '/payout-batches/generate',
  requireAuth,
  requirePermission('finance', 'approve'),
  validateBody(generateBatchSchema),
  asyncHandler(async (req, res) => {
    const result = await generatePayoutBatch({
      periodStart: req.body.period_start,
      periodEnd: req.body.period_end,
    });
    res.status(201).json(result);
  })
);

financeRouter.post(
  '/payout-batches/:id/approve',
  requireAuth,
  requirePermission('finance', 'approve'),
  asyncHandler(async (req, res) => {
    const result = await approvePayoutBatch(req.params.id as string, req.user!.userId);
    res.status(200).json({ approved: true, ...result });
  })
);

financeRouter.get(
  '/ledger-integrity',
  requireAuth,
  requirePermission('finance', 'review'),
  asyncHandler(async (_req, res) => {
    res.status(200).json(await runLedgerIntegrityCheck());
  })
);
