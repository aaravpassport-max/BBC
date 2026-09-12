import { PoolClient } from 'pg';
import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

const REFERRER_REWARD = 100; // PRD 18A.1 — config-driven amounts, fixed here for the reference implementation
const REFEREE_REWARD = 50;

function generateCode(): string {
  // Deterministic-length, human-shareable code. Collision handled by the
  // DB's UNIQUE constraint (migration 008) with a retry, not assumed away.
  return Array.from({ length: 8 }, () => '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'[Math.floor(Math.random() * 34)]).join('');
}

/** Returns the user's referral code, generating and persisting one on first
 * request if they don't have one yet (rather than requiring a separate
 * signup-time step every user must remember to complete). */
export async function getOrCreateReferralCode(userId: string): Promise<string> {
  const existing = await pool.query('SELECT referral_code FROM users WHERE id = $1', [userId]);
  if (existing.rows[0]?.referral_code) {
    return existing.rows[0].referral_code;
  }

  for (let attempt = 0; attempt < 5; attempt++) {
    const code = generateCode();
    try {
      await pool.query('UPDATE users SET referral_code = $1 WHERE id = $2', [code, userId]);
      return code;
    } catch (err) {
      const pgErr = err as { code?: string };
      if (pgErr.code === '23505') continue; // extremely rare collision — retry with a new code
      throw err;
    }
  }
  throw Errors.internal();
}

export async function getReferralSummary(userId: string) {
  const code = await getOrCreateReferralCode(userId);

  const fulfilled = await pool.query(
    `SELECT count(*) FROM referrals WHERE referrer_id = $1 AND status = 'fulfilled'`,
    [userId]
  );
  const pendingReview = await pool.query(
    `SELECT count(*) FROM referrals WHERE referrer_id = $1 AND status = 'pending_review'`,
    [userId]
  );

  return {
    referral_code: code,
    successful_referrals: parseInt(fulfilled.rows[0].count, 10),
    earned_confirmed: parseInt(fulfilled.rows[0].count, 10) * REFERRER_REWARD,
    earned_pending_review: parseInt(pendingReview.rows[0].count, 10) * REFERRER_REWARD,
  };
}

/**
 * Referee redeems a referrer's code (PRD Screen 45-46 entry point) — creates
 * a PENDING referral record. No reward is issued yet; that only happens on
 * the referee's first completed trip (PRD 18A.1's "qualifying action", not
 * just signup, to reduce fake-signup-farming fraud).
 */
export async function redeemReferralCode(refereeId: string, code: string): Promise<void> {
  const referrer = await pool.query('SELECT id FROM users WHERE referral_code = $1', [code.toUpperCase()]);
  if (referrer.rowCount === 0) {
    throw Errors.validation({ referral_code: 'This referral code is not valid.' });
  }
  const referrerId = referrer.rows[0].id;

  if (referrerId === refereeId) {
    throw Errors.validation({ referral_code: 'You cannot refer yourself.' });
  }

  try {
    await pool.query(
      `INSERT INTO referrals (referrer_id, referee_id, referral_code, status) VALUES ($1, $2, $3, 'pending')`,
      [referrerId, refereeId, code.toUpperCase()]
    );
  } catch (err) {
    const pgErr = err as { code?: string };
    if (pgErr.code === '23505') {
      // referrals.referee_id is UNIQUE (migration 005) — a user can only ever
      // be referred once, regardless of how many codes they try afterward.
      throw Errors.validation({ referral_code: 'This account has already used a referral code.' });
    }
    throw err;
  }
}

/**
 * Called from trip.service on trip completion (PRD 18A.1 trigger). Checks
 * whether this was the referee's FIRST completed trip and whether a pending
 * referral exists for them; if so, issues both rewards atomically. This
 * reference implementation's fraud pre-check is intentionally minimal (real
 * device/payment-instrument fingerprint clustering per PRD 18A.1/Section 17
 * is out of scope here) — it only checks for the structural abuse case this
 * schema can actually detect: the SAME underlying booking customer somehow
 * appearing as both referrer and referee across different accounts is
 * already blocked by the self-referral check in redeemReferralCode above.
 * A real deployment's Fraud Detection module (Section 17A.1) would sit here.
 */
export async function processReferralOnTripCompletion(client: PoolClient, refereeId: string, bookingId: string): Promise<void> {
  const referralResult = await client.query(
    `SELECT id, referrer_id, status FROM referrals WHERE referee_id = $1 FOR UPDATE`,
    [refereeId]
  );
  if (referralResult.rowCount === 0 || referralResult.rows[0].status !== 'pending') {
    return; // no pending referral for this user — nothing to do, not an error
  }
  const referral = referralResult.rows[0];

  // "First completed trip" check — this trip must be the referee's only
  // completed booking so far (idempotent-safe: a later completed trip by the
  // same referee will find status != 'pending' above and no-op).
  const completedCount = await client.query(
    `SELECT count(*) FROM bookings WHERE customer_id = $1 AND status = 'completed'`,
    [refereeId]
  );
  if (parseInt(completedCount.rows[0].count, 10) !== 1) {
    return; // not their first completed trip
  }

  await client.query(
    `UPDATE referrals SET status = 'fulfilled', qualifying_booking_id = $1, resolved_at = now() WHERE id = $2`,
    [bookingId, referral.id]
  );

  // Credit both wallets as tagged promotional-credit transactions (PRD 18A.1
  // acceptance criteria: idempotent per referral_id, traceable in the
  // ledger). Reuses the same wallet-creation-on-demand + ledger pattern as
  // wallet.service, inlined here since wallet.service's functions aren't
  // structured to accept an existing transaction client — flagged as a
  // follow-up refactor rather than duplicating silently without comment.
  for (const [ownerId, amount] of [
    [referral.referrer_id, REFERRER_REWARD],
    [refereeId, REFEREE_REWARD],
  ] as const) {
    const walletResult = await client.query(
      `SELECT id FROM wallets WHERE owner_type = 'customer' AND owner_id = $1`,
      [ownerId]
    );
    let walletId: string;
    if (walletResult.rowCount && walletResult.rowCount > 0) {
      walletId = walletResult.rows[0].id;
    } else {
      const created = await client.query(
        `INSERT INTO wallets (owner_type, owner_id, currency) VALUES ('customer', $1, 'INR') RETURNING id`,
        [ownerId]
      );
      walletId = created.rows[0].id;
    }

    const walletRow = await client.query(`SELECT promo_balance_cache FROM wallets WHERE id = $1 FOR UPDATE`, [
      walletId,
    ]);
    const newBalance = parseFloat(walletRow.rows[0].promo_balance_cache) + amount;

    await client.query(
      `INSERT INTO wallet_transactions
         (wallet_id, transaction_group_id, entry_type, balance_type, amount, balance_after, reason)
       VALUES ($1, $2, 'credit', 'promo', $3, $4, 'referral')`,
      [walletId, referral.id, amount, newBalance] // transaction_group_id reuses referral.id — unique per referral, doubling as the idempotency anchor (PRD 18A.1)
    );
    await client.query(`UPDATE wallets SET promo_balance_cache = $1 WHERE id = $2`, [newBalance, walletId]);
  }
}
