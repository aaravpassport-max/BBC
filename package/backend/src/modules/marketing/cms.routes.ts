import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import { createBanner, publishBanner, getActiveBannersForUser, listBanners } from './cms.service';

const createSchema = z.object({
  headline: z.string().max(60),
  image_url: z.string(),
  cta_text: z.string().optional(),
  cta_deep_link: z.string(),
  linked_coupon_id: z.string().uuid().optional(),
  target_segment: z.string().optional(),
  priority: z.number().int().optional(),
  start_at: z.string(),
  end_at: z.string(),
});

export const cmsRouter = Router();

cmsRouter.get(
  '/active',
  requireAuth,
  asyncHandler(async (req, res) => {
    const segment = (req.query.segment as string) || null;
    res.status(200).json(await getActiveBannersForUser(segment));
  })
);

cmsRouter.get(
  '/',
  requireAuth,
  requirePermission('marketing', 'cms_manage'),
  asyncHandler(async (_req, res) => {
    res.status(200).json(await listBanners());
  })
);

cmsRouter.post(
  '/',
  requireAuth,
  requirePermission('marketing', 'cms_manage'),
  validateBody(createSchema),
  asyncHandler(async (req, res) => {
    const b = req.body;
    const result = await createBanner({
      headline: b.headline,
      imageUrl: b.image_url,
      ctaText: b.cta_text,
      ctaDeepLink: b.cta_deep_link,
      linkedCouponId: b.linked_coupon_id,
      targetSegment: b.target_segment,
      priority: b.priority,
      startAt: b.start_at,
      endAt: b.end_at,
      createdBy: req.user!.userId,
    });
    res.status(201).json(result);
  })
);

cmsRouter.post(
  '/:id/publish',
  requireAuth,
  requirePermission('marketing', 'cms_manage'),
  asyncHandler(async (req, res) => {
    await publishBanner(req.params.id as string);
    res.status(200).json({ published: true });
  })
);
