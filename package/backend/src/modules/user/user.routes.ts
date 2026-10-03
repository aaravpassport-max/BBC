import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { getProfile, updateProfile } from './profile.service';
import { listAddresses, createAddress, updateAddress, deleteAddress } from './addresses.service';
import {
  listRecentAddresses,
  listFavouriteAddresses,
  setAddressFavourite,
  listBookingTemplates,
  createBookingTemplate,
  deleteBookingTemplate,
} from './favourites.service';

const updateProfileSchema = z.object({
  name: z.string().min(1).max(100).optional(),
  email: z.string().email().nullable().optional(),
  locale: z.string().max(10).optional(),
  gstin: z.string().max(15).nullable().optional(),
  billing_address: z.string().max(500).nullable().optional(),
  business_name: z.string().max(120).nullable().optional(),
});

const addressSchema = z.object({
  label: z.string().min(1).max(50),
  address_line: z.string().min(3).max(255),
  lat: z.number(),
  lng: z.number(),
  landmark: z.string().max(100).optional(),
  contact_name: z.string().max(100).optional(),
  contact_phone: z.string().max(20).optional(),
  is_default: z.boolean().optional(),
  is_favourite: z.boolean().optional(),
});

const templateSchema = z.object({
  name: z.string().min(1).max(120),
  snapshot: z.record(z.string(), z.unknown()),
});

export const userRouter = Router();

userRouter.get(
  '/profile',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await getProfile(req.user!.userId));
  })
);

userRouter.put(
  '/profile',
  requireAuth,
  validateBody(updateProfileSchema),
  asyncHandler(async (req, res) => {
    res.status(200).json(await updateProfile(req.user!.userId, req.body));
  })
);

userRouter.get(
  '/addresses',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await listAddresses(req.user!.userId));
  })
);

userRouter.get(
  '/addresses/recent',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await listRecentAddresses(req.user!.userId));
  })
);

userRouter.get(
  '/addresses/favourites',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await listFavouriteAddresses(req.user!.userId));
  })
);

userRouter.post(
  '/addresses',
  requireAuth,
  validateBody(addressSchema),
  asyncHandler(async (req, res) => {
    res.status(201).json(await createAddress(req.user!.userId, req.body));
  })
);

userRouter.post(
  '/addresses/:id/favourite',
  requireAuth,
  validateBody(z.object({ is_favourite: z.boolean() })),
  asyncHandler(async (req, res) => {
    res.status(200).json(
      await setAddressFavourite(req.user!.userId, req.params.id as string, req.body.is_favourite)
    );
  })
);

userRouter.put(
  '/addresses/:id',
  requireAuth,
  validateBody(addressSchema.partial()),
  asyncHandler(async (req, res) => {
    res.status(200).json(await updateAddress(req.user!.userId, req.params.id as string, req.body));
  })
);

userRouter.delete(
  '/addresses/:id',
  requireAuth,
  asyncHandler(async (req, res) => {
    await deleteAddress(req.user!.userId, req.params.id as string);
    res.status(200).json({ deleted: true });
  })
);

userRouter.get(
  '/booking-templates',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await listBookingTemplates(req.user!.userId));
  })
);

userRouter.post(
  '/booking-templates',
  requireAuth,
  validateBody(templateSchema),
  asyncHandler(async (req, res) => {
    res.status(201).json(await createBookingTemplate(req.user!.userId, req.body.name, req.body.snapshot));
  })
);

userRouter.delete(
  '/booking-templates/:id',
  requireAuth,
  asyncHandler(async (req, res) => {
    await deleteBookingTemplate(req.user!.userId, req.params.id as string);
    res.status(200).json({ deleted: true });
  })
);
