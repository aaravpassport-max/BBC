import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import { createBooking, cancelBooking, getBooking, getBookingDriverLocation, listBookings, previewCancellation } from './booking.service';
import { getRebookPrefill } from './rebook.service';
import { recordAddressUsage } from '../user/favourites.service';
import { confirmTripPaymentAsCustomer } from './payment.service';
import { submitTip, getTipForBooking, getPresetTipAmounts } from './tip.service';
import { listCustomerInvoices, generateTripInvoicePdf } from './invoice.service';
import { runDispatchCycle } from '../driver/dispatch.service';
import { sendTripMessage, getTripMessages } from './chat.service';
import { submitRating } from './ratings.service';
import { verifyPaymentSignature } from '../wallet/razorpay.provider';
import * as commsProvider from '../comms/comms.provider';
import { pool } from '../../db/pool';
import { isDevRoutesEnabled } from '../../config/env';

const locationPointSchema = z.object({
  label: z.string().optional(),
  lat: z.number(),
  lng: z.number(),
  addressLine: z.string().optional(),
  unitDetail: z.string().optional(),
  contactName: z.string().optional(),
  contactPhone: z.string().optional(),
  saveAs: z.string().optional(),
});

const rebookSnapshotSchema = z.object({
  serviceId: z.string().optional(),
  vehicleGroup: z.string().optional(),
  goodsCategory: z.string().optional(),
  weightBand: z.string().optional(),
  helperNeeded: z.boolean().optional(),
  pickup: locationPointSchema,
  drops: z.array(locationPointSchema).min(1),
});

const createBookingSchema = z.object({
  quote_id: z.string().uuid(),
  payment_method: z.enum(['wallet', 'card', 'upi', 'corporate_bill']),
  scheduled_for: z.string().datetime().optional(),
  corporate_account_id: z.string().uuid().optional(),
  saved_payment_method_id: z.string().uuid().optional(),
  rebook_snapshot: rebookSnapshotSchema.optional(),
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

const verifyTripPaymentSchema = z.object({
  razorpay_order_id: z.string().min(1),
  razorpay_payment_id: z.string().min(1),
  razorpay_signature: z.string().min(1),
});

const tipSchema = z.object({ amount: z.number().positive() });

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

    const { quote_id, payment_method, scheduled_for, corporate_account_id, saved_payment_method_id, rebook_snapshot } = req.body;
    const booking = await createBooking({
      customerId: req.user!.userId,
      quoteId: quote_id,
      paymentMethod: payment_method,
      idempotencyKey,
      scheduledFor: scheduled_for,
      corporateAccountId: corporate_account_id,
      savedPaymentMethodId: saved_payment_method_id,
      rebookSnapshot: rebook_snapshot,
    });
    if (rebook_snapshot) {
      const snap = rebook_snapshot;
      void recordAddressUsage(req.user!.userId, {
        label: snap.pickup.label || 'Pickup',
        address_line: snap.pickup.addressLine || snap.pickup.label || 'Pickup',
        lat: snap.pickup.lat,
        lng: snap.pickup.lng,
        landmark: snap.pickup.unitDetail ?? null,
        contact_name: snap.pickup.contactName ?? null,
        contact_phone: snap.pickup.contactPhone ?? null,
      }).catch(() => undefined);
      for (const drop of snap.drops) {
        void recordAddressUsage(req.user!.userId, {
          label: drop.label || 'Drop',
          address_line: drop.addressLine || drop.label || 'Drop',
          lat: drop.lat,
          lng: drop.lng,
          landmark: drop.unitDetail ?? null,
          contact_name: drop.contactName ?? null,
          contact_phone: drop.contactPhone ?? null,
        }).catch(() => undefined);
      }
    }
    if (!booking.payment_required && booking.status === 'searching') {
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
  '/invoices',
  requireAuth,
  asyncHandler(async (req, res) => {
    const items = await listCustomerInvoices(req.user!.userId);
    res.status(200).json({ items });
  })
);

bookingRouter.get(
  '/tip-presets',
  requireAuth,
  asyncHandler(async (_req, res) => {
    res.status(200).json({ amounts: getPresetTipAmounts() });
  })
);

bookingRouter.get(
  '/:id/rebook',
  requireAuth,
  asyncHandler(async (req, res) => {
    const prefill = await getRebookPrefill(req.params.id as string, req.user!.userId);
    res.status(200).json(prefill);
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

bookingRouter.post(
  '/:id/verify-payment',
  requireAuth,
  validateBody(verifyTripPaymentSchema),
  asyncHandler(async (req, res) => {
    const { razorpay_order_id, razorpay_payment_id, razorpay_signature } = req.body;
    const valid = verifyPaymentSignature({
      orderId: razorpay_order_id,
      paymentId: razorpay_payment_id,
      signature: razorpay_signature,
    });
    if (!valid) {
      throw Errors.validation({ signature: 'Payment signature verification failed.' });
    }
    const result = await confirmTripPaymentAsCustomer(req.user!.userId, razorpay_order_id);
    res.status(200).json({ confirmed: true, booking_id: result.bookingId });
  })
);

if (isDevRoutesEnabled()) {
  bookingRouter.post(
    '/:id/dev/confirm-payment',
    requireAuth,
    asyncHandler(async (req, res) => {
      const { gateway_ref } = req.body as { gateway_ref?: string };
      if (!gateway_ref) throw Errors.validation({ gateway_ref: 'Required.' });
      const result = await confirmTripPaymentAsCustomer(req.user!.userId, gateway_ref);
      res.status(200).json({ confirmed: true, booking_id: result.bookingId });
    })
  );
}

bookingRouter.get(
  '/:id/invoice.pdf',
  requireAuth,
  asyncHandler(async (req, res) => {
    const pdf = await generateTripInvoicePdf(req.params.id as string, req.user!.userId);
    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Content-Disposition', `attachment; filename="invoice-${req.params.id}.pdf"`);
    res.status(200).send(pdf);
  })
);

bookingRouter.get(
  '/:id/call-driver',
  requireAuth,
  asyncHandler(async (req, res) => {
    const bookingId = req.params.id as string;
    const customerId = req.user!.userId;
    const booking = await getBooking(bookingId, customerId);
    if (!booking.driver?.phone_masked) {
      throw Errors.validation({ driver: 'No driver assigned to call yet.' });
    }

    const phones = await pool.query(
      `SELECT cu.phone AS customer_phone, du.phone AS driver_phone, b.driver_id
       FROM bookings b
       JOIN users cu ON cu.id = b.customer_id
       LEFT JOIN users du ON du.id = b.driver_id
       WHERE b.id = $1 AND b.customer_id = $2`,
      [bookingId, customerId]
    );
    if (phones.rowCount === 0 || !phones.rows[0].driver_phone) {
      throw Errors.validation({ driver: 'No driver assigned to call yet.' });
    }

    const call = await commsProvider.initiateMaskedCall({
      fromPhone: phones.rows[0].customer_phone,
      toPhone: phones.rows[0].driver_phone,
    });

    await pool.query(
      `INSERT INTO call_logs (booking_id, caller_id, callee_id, provider_ref, masked_number, status)
       VALUES ($1, $2, $3, $4, $5, 'initiated')`,
      [bookingId, customerId, phones.rows[0].driver_id, call.providerRef ?? null, call.displayNumber]
    );

    res.status(200).json({
      call_uri: call.callUri,
      display_number: call.displayNumber,
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

bookingRouter.get(
  '/:id/final-fare',
  requireAuth,
  asyncHandler(async (req, res) => {
    const booking = await getBooking(req.params.id as string, req.user!.userId);
    if (booking.status !== 'completed') {
      throw Errors.validation({ booking: 'Final fare is available only for completed trips.' });
    }
    const tip = await getTipForBooking(req.params.id as string, req.user!.userId);
    res.status(200).json({
      ...booking.fare_breakdown,
      tip_amount: tip ? parseFloat(String(tip.amount)) : 0,
    });
  })
);

bookingRouter.post(
  '/:id/tip',
  requireAuth,
  validateBody(tipSchema),
  asyncHandler(async (req, res) => {
    const result = await submitTip({
      bookingId: req.params.id as string,
      customerId: req.user!.userId,
      amount: req.body.amount,
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
