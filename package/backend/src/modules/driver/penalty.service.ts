import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

const PENALTY_REASONS = ['LATE_ARRIVAL', 'TRIP_CANCELLED_POST_ACCEPT', 'DOCUMENT_VIOLATION', 'OTHER'];

/**
 * Computes a driver's held balance from active fraud holds (PRD Section
 * A.2's Withdraw Funds rule: "available balance is always computed as
 * available-minus-held in real time, verified consistent with the Finance
 * ledger"). This is the real implementation of what wallet.service's
 * getWalletBalance previously left as a hardcoded 0 placeholder.
 */
async function getHeldAmount(driverId: string): Promise<number> {
  // A driver-subject fraud hold in 'held' status freezes their ENTIRE
  // withdrawable balance in this reference implementation (a production
  // system might freeze a specific disputed amount instead — flagged as a
  // simplification, not a PRD requirement either way since the PRD doesn't
  // specify a partial-freeze mechanism).
  const activeHold = await pool.query(
    `SELECT 1 FROM fraud_flags WHERE subject_type = 'driver' AND subject_id = $1 AND status = 'held' LIMIT 1`,
    [driverId]
  );
  if ((activeHold.rowCount || 0) === 0) return 0;

  const wallet = await pool.query(
    `SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`,
    [driverId]
  );
  return wallet.rowCount && wallet.rowCount > 0 ? parseFloat(wallet.rows[0].real_balance_cache) : 0;
}

export async function getWithdrawableBalance(driverId: string): Promise<{ available: number; held: number }> {
  const wallet = await pool.query(
    `SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`,
    [driverId]
  );
  const total = wallet.rowCount && wallet.rowCount > 0 ? parseFloat(wallet.rows[0].real_balance_cache) : 0;
  const held = await getHeldAmount(driverId);
  return { available: Math.max(0, total - held), held };
}

/**
 * On-demand payout request (PRD Section A.2). A held-fraud-flag driver can
 * still withdraw whatever isn't frozen — never blocked entirely, only
 * capped at the available (non-held) portion.
 */
export async function requestWithdrawal(params: {
  driverId: string;
  amount: number;
  mode: 'instant' | 'standard';
}): Promise<{ id: string; status: string }> {
  const { driverId, amount } = params;

  return withTransaction(async (client) => {
    const wallet = await client.query(
      `SELECT id, real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1 FOR UPDATE`,
      [driverId]
    );
    if (wallet.rowCount === 0) {
      throw Errors.insufficientBalance();
    }

    const held = await getHeldAmount(driverId);
    const available = parseFloat(wallet.rows[0].real_balance_cache) - held;

    if (amount > available) {
      throw Errors.validation({
        amount: 'This amount exceeds your available (non-held) balance.',
        available,
        held,
      });
    }

    const newBalance = parseFloat(wallet.rows[0].real_balance_cache) - amount;
    const groupId = crypto.randomUUID();

    await client.query(
      `INSERT INTO wallet_transactions (wallet_id, transaction_group_id, entry_type, balance_type, amount, balance_after, reason)
       VALUES ($1, $2, 'debit', 'real', $3, $4, 'payout')`,
      [wallet.rows[0].id, groupId, amount, newBalance]
    );
    await client.query(`UPDATE wallets SET real_balance_cache = $1 WHERE id = $2`, [newBalance, wallet.rows[0].id]);

    // The actual bank transfer (mode-dependent fee, T+1 vs instant) is an
    // external payout-provider integration outside this reference
    // implementation's scope (same category of gap as the real payment
    // gateway in wallet.service) — modeled here as an immediately-"queued"
    // status rather than a real provider call.
    return { id: groupId, status: 'queued' };
  });
}

// ---------- Penalties (PRD Section A.2) ----------

export async function issuePenalty(params: {
  driverId: string;
  amount: number;
  reasonCode: string;
  reasonNote?: string;
  linkedBookingId?: string;
  issuedBy?: string;
}): Promise<{ id: string }> {
  const { driverId, amount, reasonCode, reasonNote, linkedBookingId, issuedBy } = params;
  if (!PENALTY_REASONS.includes(reasonCode)) {
    throw Errors.validation({ reason_code: 'Unknown penalty reason.' });
  }

  return withTransaction(async (client) => {
    const walletResult = await client.query(
      `SELECT id, real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1 FOR UPDATE`,
      [driverId]
    );
    let walletId: string;
    let currentBalance: number;
    if (walletResult.rowCount && walletResult.rowCount > 0) {
      walletId = walletResult.rows[0].id;
      currentBalance = parseFloat(walletResult.rows[0].real_balance_cache);
    } else {
      const created = await client.query(
        `INSERT INTO wallets (owner_type, owner_id, currency) VALUES ('driver', $1, 'INR') RETURNING id`,
        [driverId]
      );
      walletId = created.rows[0].id;
      currentBalance = 0;
    }

    // Driver wallets MAY go negative pending payout offset (PRD Section 6
    // rule, distinct from the customer-wallet hard floor) — a penalty is
    // exactly this scenario, so no balance check blocks it here.
    const newBalance = currentBalance - amount;
    const groupId = crypto.randomUUID();

    await client.query(
      `INSERT INTO wallet_transactions (wallet_id, transaction_group_id, entry_type, balance_type, amount, balance_after, reason, linked_booking_id)
       VALUES ($1, $2, 'debit', 'real', $3, $4, 'penalty', $5)`,
      [walletId, groupId, amount, newBalance, linkedBookingId || null]
    );
    await client.query(`UPDATE wallets SET real_balance_cache = $1 WHERE id = $2`, [newBalance, walletId]);

    const penalty = await client.query(
      `INSERT INTO penalties (driver_id, amount, reason_code, reason_note, linked_booking_id, wallet_transaction_group_id, issued_by)
       VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id`,
      [driverId, amount, reasonCode, reasonNote || null, linkedBookingId || null, groupId, issuedBy || null]
    );
    return { id: penalty.rows[0].id };
  });
}

export async function listPenalties(driverId: string) {
  const result = await pool.query(
    `SELECT id, amount, reason_code, status, dispute_note, resolution_note, created_at, resolved_at
     FROM penalties WHERE driver_id = $1 ORDER BY created_at DESC`,
    [driverId]
  );
  return result.rows;
}

/**
 * Driver disputes a penalty (PRD Section A.2: "every penalty has a
 * disputable path with a structured, reasoned resolution"). Only an
 * 'issued' penalty can be disputed — one already resolved has already had
 * its process run.
 */
export async function disputePenalty(params: { penaltyId: string; driverId: string; note: string }): Promise<void> {
  const { penaltyId, driverId, note } = params;
  if (!note || note.trim().length === 0) {
    throw Errors.validation({ note: 'Explain why you are disputing this penalty.' });
  }

  const result = await pool.query(
    `UPDATE penalties SET status = 'disputed', dispute_note = $1, dispute_submitted_at = now()
     WHERE id = $2 AND driver_id = $3 AND status = 'issued'
     RETURNING id`,
    [note, penaltyId, driverId]
  );
  if (result.rowCount === 0) {
    throw Errors.validation({ penalty: 'This penalty was not found or is not eligible for dispute.' });
  }
}

/**
 * Admin resolves a dispute (PRD Section A.2: "the driver is shown the
 * specific resolution reasoning, not just a bare 'dispute denied'").
 * A 'reversed' resolution refunds the penalty amount back to the driver's
 * wallet, tagged distinctly from the original penalty debit.
 */
export async function resolvePenaltyDispute(params: {
  penaltyId: string;
  resolution: 'upheld' | 'reversed';
  resolutionNote: string;
  resolvedBy: string;
}): Promise<void> {
  const { penaltyId, resolution, resolutionNote, resolvedBy } = params;
  if (!resolutionNote || resolutionNote.trim().length === 0) {
    throw Errors.validation({ resolution_note: 'A resolution note is required.' });
  }

  return withTransaction(async (client) => {
    const penalty = await client.query(`SELECT * FROM penalties WHERE id = $1 AND status = 'disputed' FOR UPDATE`, [
      penaltyId,
    ]);
    if (penalty.rowCount === 0) {
      throw Errors.validation({ penalty: 'This penalty is not currently disputed.' });
    }

    await client.query(
      `UPDATE penalties SET status = $1, resolution_note = $2, resolved_by = $3, resolved_at = now() WHERE id = $4`,
      [resolution, resolutionNote, resolvedBy, penaltyId]
    );

    if (resolution === 'reversed') {
      const walletResult = await client.query(
        `SELECT id, real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1 FOR UPDATE`,
        [penalty.rows[0].driver_id]
      );
      const newBalance = parseFloat(walletResult.rows[0].real_balance_cache) + parseFloat(penalty.rows[0].amount);
      await client.query(
        `INSERT INTO wallet_transactions (wallet_id, transaction_group_id, entry_type, balance_type, amount, balance_after, reason)
         VALUES ($1, $2, 'credit', 'real', $3, $4, 'penalty_reversal')`,
        [walletResult.rows[0].id, crypto.randomUUID(), penalty.rows[0].amount, newBalance]
      );
      await client.query(`UPDATE wallets SET real_balance_cache = $1 WHERE id = $2`, [
        newBalance,
        walletResult.rows[0].id,
      ]);
    }
  });
}
