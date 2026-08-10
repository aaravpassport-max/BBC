import express, { Request } from 'express';
import cors from 'cors';
import helmet from 'helmet';
import rateLimit from 'express-rate-limit';
import dotenv from 'dotenv';
import { errorHandler } from './middleware/errorHandler';
import { authRouter } from './modules/auth/auth.routes';
import { pricingRouter } from './modules/pricing/pricing.routes';
import { bookingRouter } from './modules/booking/booking.routes';
import { walletRouter } from './modules/wallet/wallet.routes';
import { driverRouter } from './modules/driver/driver.routes';
import { kycRouter } from './modules/driver/kyc.routes';
import { corporateRouter } from './modules/corporate/corporate.routes';
import { referralRouter } from './modules/booking/referral.routes';
import { notificationsRouter } from './modules/notifications/notifications.routes';
import { subscriptionRouter } from './modules/booking/subscription.routes';
import { supportRouter } from './modules/support/support.routes';
import { adminRouter } from './modules/admin/admin.routes';
import { fleetRouter } from './modules/fleet/fleet.routes';
import { penaltyRouter } from './modules/driver/penalty.routes';
import { analyticsRouter } from './modules/analytics/analytics.routes';
import { rbacRouter } from './modules/admin/rbac.routes';
import { cmsRouter } from './modules/marketing/cms.routes';
import { opsRouter } from './modules/ops/ops.routes';
import { trainingRouter } from './modules/driver/training.routes';
import { userRouter } from './modules/user/user.routes';
import { geoRouter } from './modules/geo/geo.routes';
import { loyaltyRouter } from './modules/loyalty/loyalty.routes';
import { financeRouter } from './modules/finance/finance.routes';

dotenv.config();

export function createApp() {
  const app = express();

  app.use(helmet());
  const corsOrigins = process.env.CORS_ORIGIN?.split(',').map((s) => s.trim()).filter(Boolean) ?? [];
  app.use(cors(corsOrigins.length > 0 ? { origin: corsOrigins } : undefined));

  // Razorpay webhook HMAC must be computed over the raw request body.
  app.use(
    '/v1/wallet/webhook',
    express.raw({ type: 'application/json' }),
    (req: Request & { rawBody?: string; body: Buffer }, _res, next) => {
      const raw = req.body?.length ? req.body.toString('utf8') : '';
      req.rawBody = raw;
      try {
        (req as Request & { body: unknown }).body = raw ? JSON.parse(raw) : {};
      } catch {
        (req as Request & { body: unknown }).body = {};
      }
      next();
    }
  );
  app.use(express.json());

  // PRD Section 27: rate limiting on every endpoint, especially Auth.
  // Skipped when NODE_ENV=test: this limiter is keyed by source IP to
  // protect against real-world abuse over a real network. Supertest's
  // in-process requests all present as the same source, so an integration
  // suite legitimately exercising many endpoints in quick succession (this
  // suite runs 140+ tests, each making several HTTP calls) trips it purely
  // as a test-harness artifact — found the hard way when trip.test.ts
  // started failing with 429s only when run alongside other test files,
  // never in isolation. The OTP-specific rate limiting in auth.service
  // (5/hour per number, 20/day per device) is unaffected by this and still
  // fully enforced/tested — this only disables the generic endpoint-wide
  // IP limiter, which has no equivalent business-logic test coverage to
  // lose.
  if (process.env.NODE_ENV !== 'test') {
    const generalLimiter = rateLimit({ windowMs: 60 * 1000, max: 100 });
    app.use(generalLimiter);
  }

  app.get('/health', (_req, res) => {
    res.status(200).json({ status: 'ok' });
  });

  app.use('/v1/auth', authRouter);
  app.use('/v1', userRouter);
  app.use('/v1/geo', geoRouter);
  app.use('/v1/loyalty', loyaltyRouter);
  app.use('/admin/v1/finance', financeRouter);
  app.use('/v1/pricing', pricingRouter);
  app.use('/v1/bookings', bookingRouter);
  app.use('/v1/wallet', walletRouter);
  app.use('/v1/driver', driverRouter);
  app.use('/v1/driver/kyc', kycRouter);
  app.use('/v1/corporate', corporateRouter);
  app.use('/v1/referral', referralRouter);
  app.use('/v1/notifications', notificationsRouter);
  app.use('/v1/subscriptions', subscriptionRouter);
  app.use('/v1/support', supportRouter);
  app.use('/admin/v1', adminRouter);
  app.use('/v1/fleet', fleetRouter);
  app.use('/v1/driver', penaltyRouter);
  app.use('/analytics/v1', analyticsRouter);
  app.use('/admin/v1/rbac', rbacRouter);
  app.use('/v1/cms/banners', cmsRouter);
  app.use('/ops/v1', opsRouter);
  app.use('/v1/driver/training', trainingRouter);

  // Must be registered last.
  app.use(errorHandler);

  return app;
}
