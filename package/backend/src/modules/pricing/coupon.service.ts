import { PoolClient } from 'pg';
import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { FareBreakdown } from './pricing.service';

interface Coupon {
  id: string;
  discount_type: 'flat' | 'percent';
  discount_value: string;
  max_discount_cap: string | null;
  min_order_value: string;
  per_user_limit: number | null;
  global_limit: number | null;
  status: string;
  valid_from: string;
  valid_to: string;
}

/**
 * Validates a coupon against a customer + order value WITHOUT redeeming it —
 * used at quote time (PRD 2.2.5/15A.1) so the discount can be shown in the
 * fare breakdown before the customer commits. Redemption (the atomic,
 * concurrency-safe consumption of a usage slot) only happens at booking
 * confirmation via redeemCoupon below — never at quote time, since a quote
 * can be abandoned and must not burn a usage slot.
 */
export async function validateCoupon(
  code: string,
  customerId: string,
  orderValue: number
): Promise<{ coupon: Coupon; discountAmount: number }> {
  const result = await pool.query(`SELECT * FROM coupons WHERE code = $1`, [code.toUpperCase()]);
  if (result.rowCount === 0) {
    throw Errors.validation({ coupon_code: 'This coupon code is not valid.' });
  }
  const coupon: Coupon = result.rows[0];

  if (coupon.status !== 'active') {
    throw Errors.validation({ coupon_code: 'This coupon is no longer active.' });
  }
  const now = new Date();
  if (now < new Date(coupon.valid_from) || now > new Date(coupon.valid_to)) {
    throw Errors.validation({ coupon_code: 'This coupon has expired.' });
  }
  if (orderValue < parseFloat(coupon.min_order_value)) {
    throw Errors.validation({
      coupon_code: `This coupon requires a minimum order value of ${coupon.min_order_value}.`,
    });
  }

  if (coupon.per_user_limit !== null) {
    const usedCount = await pool.query(
      `SELECT count(*) FROM coupon_redemptions WHERE coupon_id = $1 AND user_id = $2`,
      [coupon.id, customerId]
    );
    if (parseInt(usedCount.rows[0].count, 10) >= coupon.per_user_limit) {
      throw Errors.validation({ coupon_code: "You've already used this coupon the maximum number of times." });
    }
  }

  if (coupon.global_limit !== null) {
    const globalCount = await pool.query(`SELECT count(*) FROM coupon_redemptions WHERE coupon_id = $1`, [
      coupon.id,
    ]);
    if (parseInt(globalCount.rows[0].count, 10) >= coupon.global_limit) {
      throw Errors.validation({ coupon_code: 'This coupon has reached its usage limit.' });
    }
  }

  let discountAmount: number;
  if (coupon.discount_type === 'flat') {
    discountAmount = parseFloat(coupon.discount_value);
  } else {
    discountAmount = orderValue * (parseFloat(coupon.discount_value) / 100);
    if (coupon.max_discount_cap) {
      discountAmount = Math.min(discountAmount, parseFloat(coupon.max_discount_cap));
    }
  }

  return { coupon, discountAmount: Math.round(discountAmount * 100) / 100 };
}

/**
 * Atomically redeems a coupon at booking-confirmation time (PRD 15A.1 hard
 * requirement: "two customers redeem the last remaining slot simultaneously
 * -> exactly one succeeds"). Uses SELECT ... FOR UPDATE on the coupon row to
 * serialize concurrent redemption attempts against the SAME coupon, and the
 * DB-level unique index on (coupon_id, user_id) as the structural backstop
 * for the per-user case. Must be called from within the same transaction as
 * the booking creation it's paired with (PRD 2.2.6), so a booking failure
 * rolls back the redemption too.
 */
export async function redeemCoupon(
  client: PoolClient,
  params: { couponId: string; customerId: string; bookingId: string; discountAmount: number }
): Promise<void> {
  const { couponId, customerId, bookingId, discountAmount } = params;

  const coupon = await client.query(`SELECT * FROM coupons WHERE id = $1 FOR UPDATE`, [couponId]);
  if (coupon.rowCount === 0) {
    throw Errors.notFound('Coupon');
  }
  const row = coupon.rows[0];

  if (row.global_limit !== null && row.global_redeemed_count >= row.global_limit) {
    // NOTE: this status flip is redundant in practice — the transaction that
    // actually pushed global_redeemed_count to the limit already sets
    // status='usage_cap_reached' via the conditional UPDATE below on its own
    // success path. We still throw here (correctly rolling back only THIS
    // caller's own booking, per PRD 15A.1), so this particular UPDATE would
    // be discarded by that rollback — which is fine precisely because it's
    // redundant, not because throw-after-mutation is safe in general (it
    // is NOT, per the auth.service/driver.service bugs found and fixed in
    // this same audit pass). Left as a throw (not restructured into the
    // return-outcome pattern) deliberately, since restructuring here would
    // add complexity for a write that provably never needs to survive.
    throw Errors.validation({ coupon_code: 'This coupon just reached its usage limit.' });
  }

  try {
    await client.query(
      `INSERT INTO coupon_redemptions (coupon_id, user_id, booking_id, discount_amount)
       VALUES ($1, $2, $3, $4)`,
      [couponId, customerId, bookingId, discountAmount]
    );
  } catch (err) {
    const pgErr = err as { code?: string };
    if (pgErr.code === '23505') {
      throw Errors.validation({ coupon_code: 'You have already used this coupon.' });
    }
    throw err;
  }

  const newCount = row.global_redeemed_count + 1;
  await client.query(
    `UPDATE coupons SET global_redeemed_count = $1,
            status = CASE WHEN global_limit IS NOT NULL AND $1 >= global_limit THEN 'usage_cap_reached' ELSE status END
     WHERE id = $2`,
    [newCount, couponId]
  );
}

export function applyCouponToFare(fareBreakdown: FareBreakdown, discountAmount: number): FareBreakdown {
  const cappedDiscount = Math.min(discountAmount, fareBreakdown.final_fare);
  return {
    ...fareBreakdown,
    coupon_discount: cappedDiscount,
    final_fare: Math.round((fareBreakdown.final_fare - cappedDiscount) * 100) / 100,
  };
}
