import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import { registerAsDriver, submitKycStep, getKycStatus, reviewKycDocument, listPendingKycDocuments } from './kyc.service';

const submitStepSchema = z.object({
  fields: z.record(z.string(), z.unknown()).optional(),
  document_url: z.string().optional(),
  expiry_date: z.string().optional(),
});

const reviewSchema = z.object({
  decision: z.enum(['approved', 'rejected']),
  rejection_reason: z.enum(['DOC_BLURRY', 'DOC_EXPIRED', 'NAME_MISMATCH', 'FACE_MISMATCH', 'OTHER']).optional(),
  rejection_note: z.string().optional(),
});

export const kycRouter = Router();

// PRD 3.1 — converts a customer-type account into a driver application.
kycRouter.post(
  '/register',
  requireAuth,
  asyncHandler(async (req, res) => {
    await registerAsDriver(req.user!.userId);
    res.status(200).json({ registered: true });
  })
);

// PRD 3.2
kycRouter.post(
  '/:step',
  requireAuth,
  validateBody(submitStepSchema),
  asyncHandler(async (req, res) => {
    const { fields, document_url, expiry_date } = req.body;
    await submitKycStep({
      driverId: req.user!.userId,
      step: req.params.step as string,
      fields,
      documentUrl: document_url,
      expiryDate: expiry_date,
    });
    res.status(202).json({ submitted: true });
  })
);

kycRouter.get(
  '/status',
  requireAuth,
  asyncHandler(async (req, res) => {
    const status = await getKycStatus(req.user!.userId);
    res.status(200).json(status);
  })
);

kycRouter.get(
  '/admin/pending',
  requireAuth,
  requirePermission('driver', 'kyc_review'),
  asyncHandler(async (_req, res) => {
    const docs = await listPendingKycDocuments();
    res.status(200).json(docs);
  })
);

// Admin-only reviewer action (PRD Section 7) — gated by RBAC, not by driver self-auth.
kycRouter.post(
  '/documents/:documentId/review',
  requireAuth,
  requirePermission('driver', 'kyc_review'),
  validateBody(reviewSchema),
  asyncHandler(async (req, res) => {
    const { decision, rejection_reason, rejection_note } = req.body;
    await reviewKycDocument({
      documentId: req.params.documentId as string,
      reviewerId: req.user!.userId,
      decision,
      rejectionReason: rejection_reason,
      rejectionNote: rejection_note,
    });
    res.status(200).json({ reviewed: true });
  })
);
