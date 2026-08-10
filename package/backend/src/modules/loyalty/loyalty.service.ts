import { pool } from '../../db/pool';

const TIER_THRESHOLDS = { bronze: 0, silver: 500, gold: 2000, platinum: 5000 };

function tierForLifetime(lifetime: number): string {
  if (lifetime >= TIER_THRESHOLDS.platinum) return 'platinum';
  if (lifetime >= TIER_THRESHOLDS.gold) return 'gold';
  if (lifetime >= TIER_THRESHOLDS.silver) return 'silver';
  return 'bronze';
}

export async function getLoyaltySummary(userId: string) {
  const result = await pool.query(
    `SELECT balance, lifetime_earned, tier FROM loyalty_points WHERE user_id = $1`,
    [userId]
  );
  if (result.rowCount === 0) {
    return { balance: 0, lifetime_earned: 0, tier: 'bronze', next_tier_at: TIER_THRESHOLDS.silver };
  }
  const row = result.rows[0];
  const tier = row.tier as string;
  const nextTier =
    tier === 'bronze'
      ? TIER_THRESHOLDS.silver
      : tier === 'silver'
        ? TIER_THRESHOLDS.gold
        : tier === 'gold'
          ? TIER_THRESHOLDS.platinum
          : null;
  return {
    balance: row.balance,
    lifetime_earned: row.lifetime_earned,
    tier,
    next_tier_at: nextTier,
  };
}

export async function getLoyaltyHistory(userId: string) {
  const result = await pool.query(
    `SELECT id, points, reason, linked_booking_id, created_at
     FROM loyalty_transactions WHERE user_id = $1 ORDER BY created_at DESC LIMIT 50`,
    [userId]
  );
  return result.rows;
}

/** 10 points = ₹1 discount, capped at 50% of fare. */
export async function computeLoyaltyDiscount(
  userId: string,
  pointsRequested: number,
  fareBeforeDiscount: number
): Promise<{ discount: number; pointsUsed: number }> {
  if (pointsRequested <= 0) return { discount: 0, pointsUsed: 0 };

  const summary = await getLoyaltySummary(userId);
  const maxDiscount = fareBeforeDiscount * 0.5;
  const maxPointsByFare = Math.floor(maxDiscount * 10);
  const pointsUsed = Math.min(pointsRequested, summary.balance, maxPointsByFare);
  const discount = Math.round((pointsUsed / 10) * 100) / 100;
  return { discount, pointsUsed };
}

export async function redeemPointsForBooking(params: {
  userId: string;
  bookingId: string;
  pointsUsed: number;
  discountAmount: number;
}): Promise<void> {
  const { userId, bookingId, pointsUsed, discountAmount } = params;
  if (pointsUsed <= 0) return;

  await pool.query(
    `UPDATE loyalty_points SET balance = balance - $1, updated_at = now() WHERE user_id = $2 AND balance >= $1`,
    [pointsUsed, userId]
  );
  await pool.query(
    `INSERT INTO loyalty_transactions (user_id, points, reason, linked_booking_id)
     VALUES ($1, $2, 'redeem', $3)`,
    [userId, -pointsUsed, bookingId]
  );
  await pool.query(
    `INSERT INTO loyalty_redemptions (user_id, booking_id, points_used, discount_amount)
     VALUES ($1, $2, $3, $4)`,
    [userId, bookingId, pointsUsed, discountAmount]
  );
}

/** Accrue points on trip completion — 1 point per ₹10 spent, minimum 10. */
export async function accrueTripPoints(userId: string, bookingId: string, finalFare: number): Promise<void> {
  const points = Math.max(10, Math.floor(finalFare / 10));

  const inserted = await pool.query(
    `INSERT INTO loyalty_transactions (user_id, points, reason, linked_booking_id)
     VALUES ($1, $2, 'trip_complete', $3)
     ON CONFLICT (linked_booking_id) WHERE reason = 'trip_complete' DO NOTHING
     RETURNING id`,
    [userId, points, bookingId]
  );
  if (inserted.rowCount === 0) return;

  await pool.query(
    `INSERT INTO loyalty_points (user_id, balance, lifetime_earned, tier)
     VALUES ($1, $2, $2, 'bronze')
     ON CONFLICT (user_id) DO UPDATE SET
       balance = loyalty_points.balance + $2,
       lifetime_earned = loyalty_points.lifetime_earned + $2,
       updated_at = now()`,
    [userId, points]
  );

  const updated = await pool.query(`SELECT lifetime_earned FROM loyalty_points WHERE user_id = $1`, [userId]);
  if (updated.rowCount) {
    await pool.query(`UPDATE loyalty_points SET tier = $1 WHERE user_id = $2`, [
      tierForLifetime(updated.rows[0].lifetime_earned as number),
      userId,
    ]);
  }
}
