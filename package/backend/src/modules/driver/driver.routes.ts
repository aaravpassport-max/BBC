import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';

const uuidParamSchema = z.string().uuid();

/**
 * Validates a route param is a real UUID before it ever reaches a query —
 * without this, a malformed/missing param (e.g. a client bug producing
 * literal "undefined" in a URL) hits Postgres directly and crashes with a
 * raw, unhelpful 500 instead of a clean, actionable 400. Found via a real
 * end-to-end test hitting exactly this case (P1 gap-analysis navigation
 * work — the failure was in test setup, not the accept flow itself, but
 * the crash-instead-of-400 behavior it exposed was real).
 */
function requireUuidParam(value: string, paramName: string): string {
  const result = uuidParamSchema.safeParse(value);
  if (!result.success) {
    throw Errors.validation({ [paramName]: `Not a valid ${paramName}.` });
  }
  return result.data;
}
import {
  setDriverOnlineStatus,
  updateDriverLocation,
  acceptJobOffer,
  declineJobOffer,
  getMyPendingOffer,
  getMyActiveJob,
  registerVehicle,
  listDriverJobHistory,
  getDriverPartnerProfile,
  updateDriverPartnerProfile,
} from './driver.service';
import { runDispatchCycle } from './dispatch.service';
import { verifyPickupOtp, completeStop } from './trip.service';

const statusSchema = z.object({
  online: z.boolean(),
  offline_reason: z.string().optional(),
});

const locationSchema = z.object({
  lat: z.number(),
  lng: z.number(),
});

export const driverRouter = Router();

// PRD Home screen / A.2 — blocked server-side if ineligible, regardless of client UI state.
driverRouter.post(
  '/status',
  requireAuth,
  validateBody(statusSchema),
  asyncHandler(async (req, res) => {
    const { online, offline_reason } = req.body;
    await setDriverOnlineStatus(req.user!.userId, online, offline_reason);
    res.status(200).json({ online });
  })
);

driverRouter.post(
  '/location',
  requireAuth,
  validateBody(locationSchema),
  asyncHandler(async (req, res) => {
    const { lat, lng } = req.body;
    await updateDriverLocation(req.user!.userId, lat, lng);
    res.status(200).json({ acknowledged: true });
  })
);

// PRD 3.3
driverRouter.post(
  '/jobs/:offerId/accept',
  requireAuth,
  asyncHandler(async (req, res) => {
    const offerId = requireUuidParam(req.params.offerId as string, 'offerId');
    const result = await acceptJobOffer(offerId, req.user!.userId);
    res.status(200).json(result);
  })
);

driverRouter.post(
  '/jobs/:offerId/decline',
  requireAuth,
  asyncHandler(async (req, res) => {
    const offerId = requireUuidParam(req.params.offerId as string, 'offerId');
    const { bookingId } = await declineJobOffer(offerId, req.user!.userId);
    res.status(200).json({ declined: true });
    // PRD Section 4: on decline, immediately re-enter dispatch for the next
    // candidate. Fired after responding so the driver's own confirmation isn't
    // held up by the next offer's creation; failures here are logged, not
    // surfaced back to the declining driver (it's not their concern).
    runDispatchCycle(bookingId).catch((err) => {
      console.error(`Re-dispatch after decline failed for booking ${bookingId}:`, err);
    });
  })
);

// PRD 3.3 — polled by the Driver App since this reference backend has no
// push/websocket infrastructure. Returns null, not 404, when nothing is
// pending — an empty result is the normal common-case response.
driverRouter.get(
  '/jobs/history',
  requireAuth,
  asyncHandler(async (req, res) => {
    const page = parseInt((req.query.page as string) || '1', 10);
    const pageSize = Math.min(parseInt((req.query.page_size as string) || '20', 10), 100);
    const items = await listDriverJobHistory(req.user!.userId, page, pageSize);
    res.status(200).json({ items, page });
  })
);

const profileUpdateSchema = z.object({
  name: z.string().min(1).max(100).optional(),
  email: z.string().email().nullable().optional(),
});

driverRouter.get(
  '/profile',
  requireAuth,
  asyncHandler(async (req, res) => {
    res.status(200).json(await getDriverPartnerProfile(req.user!.userId));
  })
);

driverRouter.put(
  '/profile',
  requireAuth,
  validateBody(profileUpdateSchema),
  asyncHandler(async (req, res) => {
    res.status(200).json(await updateDriverPartnerProfile(req.user!.userId, req.body));
  })
);

driverRouter.get(
  '/jobs/pending-offer',
  requireAuth,
  asyncHandler(async (req, res) => {
    const offer = await getMyPendingOffer(req.user!.userId);
    res.status(200).json(offer);
  })
);

driverRouter.get(
  '/jobs/active',
  requireAuth,
  asyncHandler(async (req, res) => {
    const job = await getMyActiveJob(req.user!.userId);
    res.status(200).json(job);
  })
);

const registerVehicleSchema = z.object({
  category: z.string(),
  plate_number: z.string().min(1).max(20),
});

driverRouter.get(
  '/vehicles',
  requireAuth,
  asyncHandler(async (req, res) => {
    const profile = await getDriverPartnerProfile(req.user!.userId);
    res.status(200).json(profile.vehicle);
  })
);

// PRD 3.2 step 4 / Section A.2 Vehicle Management
driverRouter.post(
  '/vehicles',
  requireAuth,
  validateBody(registerVehicleSchema),
  asyncHandler(async (req, res) => {
    const { category, plate_number } = req.body;
    const result = await registerVehicle({ driverId: req.user!.userId, category, plateNumber: plate_number });
    res.status(201).json(result);
  })
);

// PRD 2.2.7 — driver confirms arrival + verifies the pickup OTP the customer reads aloud.
driverRouter.post(
  '/jobs/:bookingId/verify-pickup',
  requireAuth,
  asyncHandler(async (req, res) => {
    const { otp } = req.body;
    const result = await verifyPickupOtp({
      bookingId: req.params.bookingId as string,
      driverId: req.user!.userId,
      otp,
    });
    res.status(200).json(result);
  })
);

// PRD 2.2.7/3B.1 — completes one drop stop; completing the last one completes the trip.
driverRouter.post(
  '/jobs/:bookingId/stops/:stopId/complete',
  requireAuth,
  asyncHandler(async (req, res) => {
    const { otp } = req.body;
    const result = await completeStop({
      bookingId: req.params.bookingId as string,
      stopId: req.params.stopId as string,
      driverId: req.user!.userId,
      otp,
    });
    res.status(200).json(result);
  })
);

// Dev-only manual trigger, standing in for the real event-bus consumer that
// would call runDispatchCycle automatically on BookingCreated (PRD Section 22).
driverRouter.post(
  '/dev/trigger-dispatch/:bookingId',
  requireAuth,
  asyncHandler(async (req, res) => {
    const result = await runDispatchCycle(req.params.bookingId as string);
    res.status(200).json(result);
  })
);
