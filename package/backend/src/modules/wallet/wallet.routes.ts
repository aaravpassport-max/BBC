import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import { getWalletBalance, initiateTopUp, getTransactionHistory, confirmTopUp, confirmTopUpAsCustomer } from './wallet.service';
import {
  listSavedPaymentMethods,
  savePaymentMethod,
  deletePaymentMethod,
  setDefaultPaymentMethod,
  initiateSaveCardSession,
  completeSaveCardFromPayment,
} from './saved-payment.service';
import { verifyPaymentSignature, verifyWebhookSignature } from './razorpay.provider';
import { confirmTripPayment } from '../booking/payment.service';
import { runDispatchCycle } from '../driver/dispatch.service';
import { pool } from '../../db/pool';
import { isDevRoutesEnabled } from '../../config/env';

const addMoneySchema = z.object({
  amount: z.number().positive(),
  payment_method_id: z.string().min(1),
});

export const walletRouter = Router();

walletRouter.get(
  '/',
  requireAuth,
  asyncHandler(async (req, res) => {
    const balance = await getWalletBalance('customer', req.user!.userId);
    res.status(200).json(balance);
  })
);

// PRD 2A.2 — creates a PENDING transaction; balance only updates on
// confirmation below. Uses the real Razorpay gateway once configured (see
// wallet.service.ts's initiateTopUp), a simulated one otherwise.
walletRouter.post(
  '/add-money',
  requireAuth,
  validateBody(addMoneySchema),
  asyncHandler(async (req, res) => {
    const { amount, payment_method_id } = req.body;
    const result = await initiateTopUp({
      customerId: req.user!.userId,
      amount,
      paymentMethodId: payment_method_id,
    });
    res.status(202).json({ transaction_id: result.transactionId, gateway_session: result.gatewaySession });
  })
);

walletRouter.get(
  '/transactions',
  requireAuth,
  asyncHandler(async (req, res) => {
    const type = req.query.type as string | undefined;
    const transactions = await getTransactionHistory('customer', req.user!.userId, type);
    res.status(200).json(transactions);
  })
);

const verifyPaymentSchema = z.object({
  razorpay_order_id: z.string().min(1),
  razorpay_payment_id: z.string().min(1),
  razorpay_signature: z.string().min(1),
});

// Real Razorpay Checkout's standard client-side confirmation flow: after
// the checkout widget reports success, the frontend calls this with the
// three values Razorpay itself returned, and the signature is verified
// server-side before the wallet is ever touched (PRD Section 6 — never
// client-reported). This gives immediate UI feedback; the webhook below
// remains the authoritative confirmation in case this call never arrives
// (app closed mid-flow, network drop, etc.).
walletRouter.post(
  '/verify-payment',
  requireAuth,
  validateBody(verifyPaymentSchema),
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
    await confirmTopUpAsCustomer(req.user!.userId, razorpay_order_id);
    res.status(200).json({ confirmed: true });
  })
);

// The REAL production confirmation path — a genuine server-to-server call
// from Razorpay's own infrastructure, never behind requireAuth (Razorpay
// isn't an app user and carries no session), authenticated instead by its
// HMAC signature over the raw request body (see razorpay.provider.ts).
walletRouter.post(
  '/webhook',
  asyncHandler(async (req, res) => {
    const signature = req.headers['x-razorpay-signature'] as string | undefined;
    const rawBody = (req as unknown as { rawBody?: string }).rawBody ?? JSON.stringify(req.body);
    if (!signature || !verifyWebhookSignature(rawBody, signature)) {
      throw Errors.forbidden('Invalid webhook signature.');
    }
    const event = req.body;
    if (event.event === 'payment.captured') {
      const orderId = event.payload?.payment?.entity?.order_id;
      if (orderId) {
        const payment = await pool.query(`SELECT method, booking_id FROM payments WHERE gateway_ref = $1`, [orderId]);
        if (payment.rowCount && payment.rows[0].booking_id) {
          const result = await confirmTripPayment(orderId);
          if (result.bookingId) {
            const booking = await pool.query(`SELECT status FROM bookings WHERE id = $1`, [result.bookingId]);
            if (booking.rows[0]?.status === 'searching') {
              void runDispatchCycle(result.bookingId).catch(() => undefined);
            }
          }
        } else {
          await confirmTopUp(orderId);
        }
      }
    }
    // Razorpay expects a 200 for any event it doesn't need retried,
    // including ones this handler doesn't act on — an unhandled event type
    // is not an error.
    res.status(200).json({ received: true });
  })
);

// Dev-only helper route — not registered in production (see config/env.ts).
if (isDevRoutesEnabled()) {
  walletRouter.post(
    '/dev/simulate-webhook',
    requireAuth,
    asyncHandler(async (req, res) => {
      const { gateway_ref } = req.body;
      await confirmTopUpAsCustomer(req.user!.userId, gateway_ref);
      res.status(200).json({ confirmed: true });
    })
  );
}

const savePaymentMethodSchema = z.object({
  method_type: z.enum(['card', 'upi']),
  display_label: z.string().min(1).max(80),
  token_ref: z.string().min(1).max(200),
  set_default: z.boolean().optional(),
});

walletRouter.get(
  '/payment-methods',
  requireAuth,
  asyncHandler(async (req, res) => {
    const methods = await listSavedPaymentMethods(req.user!.userId);
    res.status(200).json(methods);
  })
);

walletRouter.post(
  '/payment-methods',
  requireAuth,
  validateBody(savePaymentMethodSchema),
  asyncHandler(async (req, res) => {
    const { method_type, display_label, token_ref, set_default } = req.body;
    const result = await savePaymentMethod({
      userId: req.user!.userId,
      methodType: method_type,
      displayLabel: display_label,
      tokenRef: token_ref,
      setDefault: set_default,
    });
    res.status(201).json(result);
  })
);

walletRouter.delete(
  '/payment-methods/:id',
  requireAuth,
  asyncHandler(async (req, res) => {
    await deletePaymentMethod(req.user!.userId, req.params.id as string);
    res.status(200).json({ deleted: true });
  })
);

walletRouter.post(
  '/payment-methods/:id/default',
  requireAuth,
  asyncHandler(async (req, res) => {
    await setDefaultPaymentMethod(req.user!.userId, req.params.id as string);
    res.status(200).json({ updated: true });
  })
);

walletRouter.post(
  '/payment-methods/initiate-save',
  requireAuth,
  asyncHandler(async (req, res) => {
    const session = await initiateSaveCardSession(req.user!.userId);
    res.status(200).json({ gateway_session: session });
  })
);

const completeSaveSchema = z.object({
  razorpay_payment_id: z.string().min(1),
  method_type: z.enum(['card', 'upi']),
  display_label: z.string().max(80).optional(),
  set_default: z.boolean().optional(),
});

walletRouter.post(
  '/payment-methods/complete-save',
  requireAuth,
  validateBody(completeSaveSchema),
  asyncHandler(async (req, res) => {
    const { razorpay_payment_id, method_type, display_label, set_default } = req.body;
    const result = await completeSaveCardFromPayment({
      userId: req.user!.userId,
      paymentId: razorpay_payment_id,
      methodType: method_type,
      displayLabel: display_label,
      setDefault: set_default,
    });
    res.status(201).json(result);
  })
);
