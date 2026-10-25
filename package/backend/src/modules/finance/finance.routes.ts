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
  holdPayoutLine,
  releasePayoutLine,
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

const holdLineSchema = z.object({
  reason: z.enum(['DISPUTE_PENDING', 'FRAUD_REVIEW', 'BANK_DETAILS_INVALID', 'OTHER']),
  note: z.string().max(500).optional(),
});

financeRouter.post(
  '/payout-batches/:batchId/lines/:lineId/hold',
  requireAuth,
  requirePermission('finance', 'approve'),
  validateBody(holdLineSchema),
  asyncHandler(async (req, res) => {
    await holdPayoutLine({
      batchId: req.params.batchId as string,
      lineId: req.params.lineId as string,
      reason: req.body.reason,
      note: req.body.note,
    });
    res.status(200).json({ held: true });
  })
);

financeRouter.post(
  '/payout-batches/:batchId/lines/:lineId/release',
  requireAuth,
  requirePermission('finance', 'approve'),
  asyncHandler(async (req, res) => {
    await releasePayoutLine(req.params.batchId as string, req.params.lineId as string);
    res.status(200).json({ released: true });
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
