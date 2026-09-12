import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import { verifyPaymentSignature } from '../wallet/razorpay.provider';
import { PLANS, purchaseSubscription, getMySubscription, cancelSubscription, attemptRenewal, reactivateSubscription, confirmSubscriptionPayment } from './subscription.service';
import { isDevRoutesEnabled } from '../../config/env';

export function listSubscriptionPlans() {
  return Object.entries(PLANS).map(([id, plan]) => ({
    id,
    name: id === 'platform_plus' ? 'PORTMYSTUFF Plus' : id,
    monthly_fee: plan.monthlyFee,
    benefits: [
      plan.waivesPlatformFee ? 'Zero platform fee on every trip' : null,
      'Priority support',
      'Exclusive offers',
    ].filter(Boolean) as string[],
  }));
}

const purchaseSchema = z.object({ plan_id: z.string() });

const verifySubscriptionPaymentSchema = z.object({
  razorpay_order_id: z.string().min(1),
  razorpay_payment_id: z.string().min(1),
  razorpay_signature: z.string().min(1),
  plan_id: z.string(),
});

export const subscriptionRouter = Router();

subscriptionRouter.get(
  '/plans',
  asyncHandler(async (_req, res) => {
    res.status(200).json(listSubscriptionPlans());
  })
);

// PRD Screen 59
subscriptionRouter.post(
  '/purchase',
  requireAuth,
  validateBody(purchaseSchema),
  asyncHandler(async (req, res) => {
    const result = await purchaseSubscription(req.user!.userId, req.body.plan_id);
    res.status(result.payment_required ? 200 : 201).json(result);
  })
);

subscriptionRouter.post(
  '/verify-payment',
  requireAuth,
  validateBody(verifySubscriptionPaymentSchema),
  asyncHandler(async (req, res) => {
    const { razorpay_order_id, razorpay_payment_id, razorpay_signature, plan_id } = req.body;
    const valid = verifyPaymentSignature({
      orderId: razorpay_order_id,
      paymentId: razorpay_payment_id,
      signature: razorpay_signature,
    });
    if (!valid) {
      throw Errors.validation({ signature: 'Payment signature verification failed.' });
    }
    const result = await confirmSubscriptionPayment(req.user!.userId, razorpay_order_id, plan_id);
    res.status(200).json({ confirmed: true, subscription_id: result.id });
  })
);

if (isDevRoutesEnabled()) {
  subscriptionRouter.post(
    '/dev/confirm-payment',
    requireAuth,
    asyncHandler(async (req, res) => {
      const { gateway_ref, plan_id } = req.body as { gateway_ref?: string; plan_id?: string };
      if (!gateway_ref || !plan_id) throw Errors.validation({ gateway_ref: 'Required.', plan_id: 'Required.' });
      const result = await confirmSubscriptionPayment(req.user!.userId, gateway_ref, plan_id);
      res.status(200).json({ confirmed: true, subscription_id: result.id });
    })
  );
}

subscriptionRouter.get(
  '/me',
  requireAuth,
  asyncHandler(async (req, res) => {
    const sub = await getMySubscription(req.user!.userId);
    res.status(200).json(sub);
  })
);

subscriptionRouter.post(
  '/:id/cancel',
  requireAuth,
  asyncHandler(async (req, res) => {
    await cancelSubscription(req.user!.userId, req.params.id as string);
    res.status(200).json({ cancelled: true });
  })
);

subscriptionRouter.post(
  '/:id/reactivate',
  requireAuth,
  asyncHandler(async (req, res) => {
    await reactivateSubscription(req.user!.userId, req.params.id as string);
    res.status(200).json({ reactivated: true });
  })
);

// Dev-only manual trigger — not registered in production.
if (isDevRoutesEnabled()) {
  subscriptionRouter.post(
    '/dev/attempt-renewal/:id',
    requireAuth,
    asyncHandler(async (req, res) => {
      const { simulate_success } = req.body;
      const result = await attemptRenewal(req.params.id as string, !!simulate_success);
      res.status(200).json(result);
    })
  );
}
