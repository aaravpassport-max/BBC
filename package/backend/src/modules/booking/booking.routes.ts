import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import { createBooking, cancelBooking, getBooking, getBookingDriverLocation, listBookings, previewCancellation } from './booking.service';
import { runDispatchCycle } from '../driver/dispatch.service';
import { sendTripMessage, getTripMessages } from './chat.service';
import { submitRating } from './ratings.service';

const createBookingSchema = z.object({
  quote_id: z.string().uuid(),
  payment_method: z.enum(['wallet', 'card', 'upi', 'corporate_bill']),
  scheduled_for: z.string().datetime().optional(),
});

const cancelBookingSchema = z.object({
  reason_code: z.enum([
    'BOOKED_BY_MISTAKE',
    'PRICE_TOO_HIGH',
    'DRIVER_TOO_LONG',
    'FOUND_ALTERNATIVE',
    'OTHER',
  ]),
  note: z.string().max(300).optional(),
});

const rateBookingSchema = z.object({
  stars: z.number().int().min(1).max(5),
  tags: z.array(z.string()).max(3).optional(),
  comment: z.string().max(500).optional(),
});

export const bookingRouter = Router();

// PRD 2.2.6 — Idempotency-Key header is mandatory, not optional.
bookingRouter.post(
  '/',
  requireAuth,
  validateBody(createBookingSchema),
  asyncHandler(async (req, res) => {
    const idempotencyKeyHeader = req.headers['idempotency-key'];
    const idempotencyKey = Array.isArray(idempotencyKeyHeader)
      ? idempotencyKeyHeader[0]
      : idempotencyKeyHeader;
    if (!idempotencyKey) {
      throw Errors.validation({ 'Idempotency-Key': 'This header is required.' });
    }

    const { quote_id, payment_method, scheduled_for } = req.body;
    const booking = await createBooking({
      customerId: req.user!.userId,
      quoteId: quote_id,
      paymentMethod: payment_method,
      idempotencyKey,
      scheduledFor: scheduled_for,
    });
    if (booking.status === 'searching') {
      void runDispatchCycle(booking.id).catch(() => undefined);
    }
    res.status(201).json(booking);
  })
);

bookingRouter.get(
  '/',
  requireAuth,
  asyncHandler(async (req, res) => {
    const statusRaw = req.query.status;
    const status = typeof statusRaw === 'string' ? statusRaw : undefined;
    const page = parseInt((req.query.page as string) || '1', 10);
    const pageSize = Math.min(parseInt((req.query.page_size as string) || '20', 10), 100);

    const items = await listBookings({ customerId: req.user!.userId, status, page, pageSize });
    res.status(200).json({ items, page });
  })
);

bookingRouter.get(
  '/:id/cancel-preview',
  requireAuth,
  asyncHandler(async (req, res) => {
    const preview = await previewCancellation(req.params.id as string, req.user!.userId);
    res.status(200).json(preview);
  })
);

bookingRouter.get(
  '/:id/call-driver',
  requireAuth,
  asyncHandler(async (req, res) => {
    const booking = await getBooking(req.params.id as string, req.user!.userId);
    if (!booking.driver?.phone_masked) {
      throw Errors.validation({ driver: 'No driver assigned to call yet.' });
    }
    res.status(200).json({
      call_uri: `tel:+91000000${String(booking.driver.id).slice(0, 4)}`,
      display_number: booking.driver.phone_masked,
    });
  })
);

bookingRouter.get(
  '/:id',
  requireAuth,
  asyncHandler(async (req, res) => {
    const booking = await getBooking(req.params.id as string, req.user!.userId);
    res.status(200).json(booking);
  })
);

// PRD Section 8 live tracking — polled by the customer app's map while a
// trip is active.
bookingRouter.get(
  '/:id/driver-location',
  requireAuth,
  asyncHandler(async (req, res) => {
    const location = await getBookingDriverLocation(req.params.id as string, req.user!.userId);
    res.status(200).json(location);
  })
);

// PRD gap-analysis P0 — in-app chat between a booking's customer and
// driver. Both directions handled by the same two routes; the service
// layer determines the caller's role from the booking record itself.
const sendMessageSchema = z.object({ body: z.string().min(1).max(1000) });

bookingRouter.get(
  '/:id/messages',
  requireAuth,
  asyncHandler(async (req, res) => {
    const messages = await getTripMessages(req.params.id as string, req.user!.userId);
    res.status(200).json(messages);
  })
);

bookingRouter.post(
  '/:id/messages',
  requireAuth,
  validateBody(sendMessageSchema),
  asyncHandler(async (req, res) => {
    const message = await sendTripMessage({
      bookingId: req.params.id as string,
      senderId: req.user!.userId,
      body: req.body.body,
    });
    res.status(201).json(message);
  })
);

bookingRouter.post(
  '/:id/rate',
  requireAuth,
  validateBody(rateBookingSchema),
  asyncHandler(async (req, res) => {
    const { stars, tags, comment } = req.body;
    const result = await submitRating({
      bookingId: req.params.id as string,
      raterId: req.user!.userId,
      stars,
      tags,
      comment,
    });
    res.status(201).json(result);
  })
);
bookingRouter.post(
  '/:id/cancel',
  requireAuth,
  validateBody(cancelBookingSchema),
  asyncHandler(async (req, res) => {
    const { reason_code, note } = req.body;
    const result = await cancelBooking({
      bookingId: req.params.id as string,
      customerId: req.user!.userId,
      reasonCode: reason_code,
      note,
    });
    res.status(200).json({ fee_charged: result.feeCharged, fee_amount: result.feeAmount });
  })
);
