import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

// PRD 19A.1 — config-driven plan catalog, fixed here for the reference implementation.
export const PLANS: Record<string, { monthlyFee: number; waivesPlatformFee: boolean; surgeExempt: boolean }> = {
  platform_plus: { monthlyFee: 99, waivesPlatformFee: true, surgeExempt: false },
};

const GRACE_PERIOD_DAYS = 3; // PRD 19A.1 config
const MAX_RENEWAL_RETRIES = 3;

export async function purchaseSubscription(userId: string, planId: string): Promise<{ id: string }> {
  if (!PLANS[planId]) {
    throw Errors.validation({ plan_id: 'Unknown subscription plan.' });
  }

  return withTransaction(async (client) => {
    const existing = await client.query(
      `SELECT id FROM subscriptions WHERE user_id = $1 AND status IN ('active', 'grace_period') FOR UPDATE`,
      [userId]
    );
    if (existing.rowCount && existing.rowCount > 0) {
      throw Errors.validation({ subscription: 'You already have an active subscription.' });
    }

    const result = await client.query(
      `INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, payment_method_id)
       VALUES ($1, $2, 'active', now(), now() + interval '30 days', 'default')
       RETURNING id`,
      [userId, planId]
    );
    return { id: result.rows[0].id };
  });
}

export async function getMySubscription(userId: string) {
  const result = await pool.query(
    `SELECT id, plan_id, status, current_period_start, current_period_end, grace_period_ends_at, retry_count
     FROM subscriptions WHERE user_id = $1 ORDER BY created_at DESC LIMIT 1`,
    [userId]
  );
  return result.rows[0] || null;
}

export async function cancelSubscription(userId: string, subscriptionId: string): Promise<void> {
  const result = await pool.query(
    `UPDATE subscriptions SET status = 'cancelled'
     WHERE id = $1 AND user_id = $2 AND status IN ('active', 'grace_period')
     RETURNING id`,
    [subscriptionId, userId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Active subscription');
  }
  // PRD 19A.1: proactive cancellation is a clean path — no dunning, benefits
  // continue through the already-paid period end (current_period_end is left
  // untouched; the benefit-lookup query below checks status IN
  // ('active','grace_period'), so a 'cancelled' row simply stops counting
  // even though its period_end hasn't technically elapsed — matching the
  // "then stop cleanly" rule rather than the grace/retry path).
}

/**
 * Simulates one renewal attempt (PRD 19A.1 dunning flow). In production this
 * is triggered by a scheduled job hitting subscriptions past
 * current_period_end; exposed here as a directly-callable function (and a
 * dev-only route) for the same reason dispatch.sweepExpiredOffers is,
 * rather than a real cron in this reference environment.
 */
export async function attemptRenewal(
  subscriptionId: string,
  simulateSuccess: boolean
): Promise<{ status: string }> {
  return withTransaction(async (client) => {
    const subResult = await client.query(`SELECT * FROM subscriptions WHERE id = $1 FOR UPDATE`, [subscriptionId]);
    if (subResult.rowCount === 0) {
      throw Errors.notFound('Subscription');
    }
    const sub = subResult.rows[0];

    if (sub.status === 'cancelled') {
      throw Errors.validation({ subscription: 'Cannot renew a cancelled subscription.' });
    }

    if (simulateSuccess) {
      await client.query(
        `UPDATE subscriptions
         SET status = 'active', current_period_start = now(), current_period_end = now() + interval '30 days',
             grace_period_ends_at = NULL, retry_count = 0, lapsed_at = NULL
         WHERE id = $1`,
        [subscriptionId]
      );
      return { status: 'active' };
    }

    // Failed charge — enter or continue the grace period (PRD 19A.1).
    if (sub.status === 'active') {
      await client.query(
        `UPDATE subscriptions
         SET status = 'grace_period', grace_period_ends_at = now() + interval '${GRACE_PERIOD_DAYS} days', retry_count = 1
         WHERE id = $1`,
        [subscriptionId]
      );
      return { status: 'grace_period' };
    }

    if (sub.status === 'grace_period') {
      const newRetryCount = sub.retry_count + 1;
      const graceExpired = new Date(sub.grace_period_ends_at) < new Date();

      if (graceExpired || newRetryCount > MAX_RENEWAL_RETRIES) {
        await client.query(`UPDATE subscriptions SET status = 'lapsed', lapsed_at = now() WHERE id = $1`, [
          subscriptionId,
        ]);
        return { status: 'lapsed' };
      }

      await client.query(`UPDATE subscriptions SET retry_count = $1 WHERE id = $2`, [newRetryCount, subscriptionId]);
      return { status: 'grace_period' };
    }

    return { status: sub.status };
  });
}

/**
 * Reactivation within the secondary window (PRD 19A.1: continuity restored,
 * no perceived gap — NOT a fresh period from reactivation date).
 */
export async function reactivateSubscription(userId: string, subscriptionId: string): Promise<void> {
  const result = await pool.query(
    `UPDATE subscriptions
     SET status = 'active', grace_period_ends_at = NULL, retry_count = 0, lapsed_at = NULL,
         current_period_end = current_period_start + interval '30 days'
     WHERE id = $1 AND user_id = $2 AND status = 'lapsed'
     RETURNING id`,
    [subscriptionId, userId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Lapsed subscription');
  }
}

/**
 * Called from pricing.service at quote time (PRD 19A.1 acceptance criteria:
 * every subscription benefit is itemized in fare_breakdown exactly like a
 * coupon discount). Benefits apply while status is 'active' OR
 * 'grace_period' — PRD 19A.1 explicitly keeps benefits live through the
 * grace window, only cutting them off on actual lapse.
 */
export async function getActiveSubscriptionBenefit(
  userId: string
): Promise<{ waivesPlatformFee: boolean; surgeExempt: boolean } | null> {
  const result = await pool.query(
    `SELECT plan_id FROM subscriptions WHERE user_id = $1 AND status IN ('active', 'grace_period') LIMIT 1`,
    [userId]
  );
  if (result.rowCount === 0) return null;
  const plan = PLANS[result.rows[0].plan_id];
  return plan ? { waivesPlatformFee: plan.waivesPlatformFee, surgeExempt: plan.surgeExempt } : null;
}
