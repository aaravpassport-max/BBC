import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import { generateQuotes, listVehicleCategoriesForLocation } from './pricing.service';

const geoPointSchema = z.object({ lat: z.number(), lng: z.number() });

const quoteRequestSchema = z.object({
  pickup: geoPointSchema,
  drops: z.array(geoPointSchema).min(1).max(5),
  vehicle_category: z.string().optional(),
  scheduled_at: z.string().datetime().nullable().optional(),
  item_details: z
    .object({
      goods_category: z.string().optional(),
      weight_band: z.string().optional(),
      helper_needed: z.boolean().optional(),
    })
    .optional(),
  coupon_code: z.string().nullable().optional(),
  loyalty_points_to_redeem: z.number().int().min(0).optional(),
});

export const pricingRouter = Router();

pricingRouter.get(
  '/vehicle-categories',
  requireAuth,
  asyncHandler(async (req, res) => {
    const lat = parseFloat(req.query.lat as string);
    const lng = parseFloat(req.query.lng as string);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      throw Errors.validation({ lat: 'lat and lng query params are required.' });
    }
    const categories = await listVehicleCategoriesForLocation({ lat, lng });
    res.status(200).json(categories);
  })
);

// PRD 2.2.5
pricingRouter.post(
  '/quote',
  requireAuth,
  validateBody(quoteRequestSchema),
  asyncHandler(async (req, res) => {
    const { pickup, drops, vehicle_category, coupon_code, loyalty_points_to_redeem } = req.body;
    const quotes = await generateQuotes({
      customerId: req.user!.userId,
      pickup,
      drops,
      vehicleCategory: vehicle_category,
      couponCode: coupon_code || undefined,
      loyaltyPointsToRedeem: loyalty_points_to_redeem,
    });
    res.status(200).json({ quotes });
  })
);
