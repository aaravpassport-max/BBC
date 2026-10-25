import { randomUUID as uuidv4 } from 'crypto';
import { PoolClient } from 'pg';
import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import * as razorpay from './razorpay.provider';

async function getOrCreateWallet(
  client: PoolClient,
  ownerType: string,
  ownerId: string,
  currency = 'INR'
): Promise<string> {
  const existing = await client.query(
    `SELECT id FROM wallets WHERE owner_type = $1 AND owner_id = $2 AND currency = $3`,
    [ownerType, ownerId, currency]
  );
  if (existing.rowCount && existing.rowCount > 0) {
    return existing.rows[0].id;
  }
  const created = await client.query(
    `INSERT INTO wallets (owner_type, owner_id, currency) VALUES ($1, $2, $3) RETURNING id`,
    [ownerType, ownerId, currency]
  );
  return created.rows[0].id;
}

/**
 * Records one economic event as a matched debit+credit pair sharing a
 * transaction_group_id (PRD Section 6 hard rule: never a single mutable
 * balance field). Updates the wallet's cached balance atomically in the
 * same transaction so the cache can never drift from the ledger sum it
 * was derived from.
 */
async function recordLedgerEntry(
  client: PoolClient,
  params: {
    debitWalletId: string | null; // null for external-source credits like a gateway top-up
    creditWalletId: string | null;
    amount: number;
    balanceType: 'real' | 'promo';
    reason: string;
    linkedBookingId?: string;
    linkedGatewayRef?: string;
  }
): Promise<string> {
  const groupId = uuidv4();
  const { debitWalletId, creditWalletId, amount, balanceType, reason, linkedBookingId, linkedGatewayRef } =
    params;
  const balanceField = balanceType === 'real' ? 'real_balance_cache' : 'promo_balance_cache';

  // DEADLOCK PREVENTION: when both a debit and a credit wallet are involved
  // (e.g. a future trip-charge: debit customer, credit platform revenue; or a
  // payout: debit platform, credit driver), two concurrent calls to this
  // function touching the SAME PAIR of wallets in opposite roles could lock
  // them in opposite order — transaction A locks wallet1-then-wallet2 while
  // transaction B locks wallet2-then-wallet1 — producing the identical
  // deadlock shape (Postgres 40P01) root-caused on the corporate-credit and
  // coupon-redemption paths in booking.service. Fixed there by controlling
  // WHEN a lock is acquired; fixed here by controlling the ORDER two locks
  // in the same call are acquired — always sorted by wallet_id, independent
  // of which one is the debit side and which is the credit side, so any two
  // concurrent calls touching the same two wallets always request their locks
  // in the same relative order and simply queue behind each other instead of
  // deadlocking. This isn't exercised by any flow built yet (only
  // single-sided top-up calls this today) but is fixed pre-emptively before
  // a trip-charge or payout flow is built on top of it.
  const walletIdsToLock = [debitWalletId, creditWalletId].filter((id): id is string => id !== null).sort();

  const balances = new Map<string, { real: number; promo: number; ownerType: string }>();
  for (const walletId of walletIdsToLock) {
    const row = await client.query(
      `SELECT real_balance_cache, promo_balance_cache, owner_type FROM wallets WHERE id = $1 FOR UPDATE`,
      [walletId]
    );
    balances.set(walletId, {
      real: parseFloat(row.rows[0].real_balance_cache),
      promo: parseFloat(row.rows[0].promo_balance_cache),
      ownerType: row.rows[0].owner_type,
    });
  }

  if (debitWalletId) {
    const current = balances.get(debitWalletId)!;
    const currentBalance = balanceType === 'real' ? current.real : current.promo;
    const newBalance = currentBalance - amount;

    if (current.ownerType === 'customer' && balanceType === 'real' && newBalance < 0) {
      throw Errors.insufficientBalance();
    }

    await client.query(
      `INSERT INTO wallet_transactions
         (wallet_id, transaction_group_id, entry_type, balance_type, amount, balance_after, reason, linked_booking_id, linked_gateway_ref)
       VALUES ($1, $2, 'debit', $3, $4, $5, $6, $7, $8)`,
      [debitWalletId, groupId, balanceType, amount, newBalance, reason, linkedBookingId, linkedGatewayRef]
    );
    await client.query(`UPDATE wallets SET ${balanceField} = $1 WHERE id = $2`, [newBalance, debitWalletId]);
  }

  if (creditWalletId) {
    const current = balances.get(creditWalletId)!;
    const currentBalance = balanceType === 'real' ? current.real : current.promo;
    const newBalance = currentBalance + amount;

    await client.query(
      `INSERT INTO wallet_transactions
         (wallet_id, transaction_group_id, entry_type, balance_type, amount, balance_after, reason, linked_booking_id, linked_gateway_ref)
       VALUES ($1, $2, 'credit', $3, $4, $5, $6, $7, $8)`,
      [creditWalletId, groupId, balanceType, amount, newBalance, reason, linkedBookingId, linkedGatewayRef]
    );
    await client.query(`UPDATE wallets SET ${balanceField} = $1 WHERE id = $2`, [newBalance, creditWalletId]);
  }

  return groupId;
}

export async function getWalletBalance(ownerType: string, ownerId: string) {
  const result = await pool.query(
    `SELECT real_balance_cache, promo_balance_cache FROM wallets WHERE owner_type = $1 AND owner_id = $2`,
    [ownerType, ownerId]
  );
  if (result.rowCount === 0) {
    return { real_money_balance: 0, promotional_credit_balance: 0, held_balance: 0 };
  }
  return {
    real_money_balance: parseFloat(result.rows[0].real_balance_cache),
    promotional_credit_balance: parseFloat(result.rows[0].promo_balance_cache),
    held_balance: 0, // computed from fraud_flags in a full implementation
  };
}

/**
 * Top-up initiation: creates a PENDING payment record. The wallet balance is
 * NEVER incremented here — only on confirmation below, per PRD Section 6's
 * hard rule that balance changes are server-confirmed, not client-reported.
 *
 * Uses the REAL Razorpay gateway the moment RAZORPAY_KEY_ID/SECRET are
 * configured (see razorpay.provider.ts) — falls back to the pre-existing
 * simulated flow otherwise, so local dev/test without real credentials
 * behaves exactly as it always has.
 */
export async function initiateTopUp(params: {
  customerId: string;
  amount: number;
  paymentMethodId: string;
}): Promise<{ transactionId: string; gatewaySession: Record<string, unknown> }> {
  const { customerId, amount } = params;

  let gatewayRef: string;
  let gatewaySession: Record<string, unknown>;

  if (razorpay.isConfigured()) {
    const order = await razorpay.createOrder({
      amountRupees: amount,
      receipt: `topup_${customerId}_${Date.now()}`,
    });
    gatewayRef = order.id;
    // Everything the frontend's Razorpay Checkout widget needs to actually
    // open — a real order ID, real amount/currency Razorpay itself
    // confirmed, and the publishable key ID (never the secret) it uses to
    // initialize the checkout script.
    gatewaySession = {
      order_id: order.id,
      amount: order.amount,
      currency: order.currency,
      key_id: process.env.RAZORPAY_KEY_ID,
      simulated: false,
    };
  } else {
    gatewayRef = `sim_${uuidv4()}`; // stands in for a real gateway's transaction reference
    gatewaySession = { gateway_ref: gatewayRef, simulated: true };
  }

  const result = await pool.query(
    `INSERT INTO payments (gateway_ref, status, amount, method, customer_id) VALUES ($1, 'pending', $2, 'wallet_topup', $3)
     RETURNING id`,
    [gatewayRef, amount, customerId]
  );

  return {
    transactionId: result.rows[0].id,
    gatewaySession,
  };
}

/**
 * Confirms a top-up succeeded and credits the wallet. This is the ONLY
 * function that increments a customer wallet's real balance from a
 * top-up — called from both the real Razorpay webhook and (when Razorpay
 * isn't configured) the dev-only simulator route, so there is exactly one
 * code path a balance can ever be credited through, not two that could
 * drift apart.
 *
 * Deliberately looks up customer_id and amount from OUR OWN payment
 * record (created at initiateTopUp, before any money moved) rather than
 * trusting either as an external parameter — a genuine webhook carries no
 * client auth context, so "amount" and "which customer" must come from
 * what we ourselves recorded, never from anything the caller claims.
 * Idempotent on gateway_ref (PRD Section 6: duplicate webhook delivery
 * cannot double-credit), enforced by the unique index on
 * wallet_transactions.linked_gateway_ref in migration 004.
 */
/**
 * Credits a driver's wallet for a completed trip — the "trip-charge or
 * payout flow" the deadlock-prevention fix in recordLedgerEntry above
 * explicitly anticipated but that nothing had actually built yet. Found
 * while building fleet-earnings reporting: a fleet owner's "today's
 * earnings" view would have silently always shown ₹0, not because of a
 * bug in the reporting query, but because trip completion never credited
 * any driver anything at all, for any trip, ever.
 *
 * Payout = final fare minus the platform's own fee — a standard
 * aggregator commission model. Idempotent on (booking_id, reason): a
 * retried or duplicate call for the same trip can never double-pay a
 * driver, checked before any credit is recorded.
 */
export async function creditDriverTripEarnings(params: {
  driverId: string;
  bookingId: string;
  fareBreakdown: { final_fare: number; platform_fee: number };
}): Promise<void> {
  const { driverId, bookingId, fareBreakdown } = params;
  const payout = fareBreakdown.final_fare - fareBreakdown.platform_fee;
  if (payout <= 0) return; // a fully-discounted or edge-case zero/negative fare pays out nothing, not a negative credit

  await withTransaction(async (client) => {
    const existing = await client.query(
      `SELECT 1 FROM wallet_transactions WHERE linked_booking_id = $1 AND reason = 'trip_earning'`,
      [bookingId]
    );
    if (existing.rowCount && existing.rowCount > 0) return; // already paid out — idempotent no-op

    const walletId = await getOrCreateWallet(client, 'driver', driverId);
    await recordLedgerEntry(client, {
      debitWalletId: null, // the customer's own payment already left their wallet at trip-charge time (or their gateway payment) — this credit represents the platform's own payout obligation, not a second debit from the customer
      creditWalletId: walletId,
      amount: payout,
      balanceType: 'real',
      reason: 'trip_earning',
      linkedBookingId: bookingId,
    });
  });
}

export async function confirmTopUp(gatewayRef: string): Promise<void> {
  await withTransaction(async (client) => {
    const payment = await client.query(
      `SELECT id, status, amount, customer_id FROM payments WHERE gateway_ref = $1 FOR UPDATE`,
      [gatewayRef]
    );
    if (payment.rowCount === 0) {
      throw Errors.notFound('Payment');
    }
    if (payment.rows[0].status === 'succeeded') {
      return; // already processed — idempotent no-op, not an error
    }
    if (!payment.rows[0].customer_id) {
      // Should be structurally impossible (initiateTopUp always sets it) —
      // guarded explicitly rather than crediting an unknown wallet.
      throw Errors.validation({ payment: 'This payment has no associated customer and cannot be confirmed.' });
    }

    await client.query(
      `UPDATE payments SET status = 'succeeded', webhook_received_at = now() WHERE id = $1`,
      [payment.rows[0].id]
    );

    const walletId = await getOrCreateWallet(client, 'customer', payment.rows[0].customer_id);
    await recordLedgerEntry(client, {
      debitWalletId: null, // funds originate externally from the gateway
      creditWalletId: walletId,
      amount: parseFloat(payment.rows[0].amount),
      balanceType: 'real',
      reason: 'topup',
      linkedGatewayRef: gatewayRef,
    });
  });
}

/**
 * Same confirmation as confirmTopUp, but for the two CLIENT-invoked paths
 * (the post-checkout signature verification, and the dev-only simulator) —
 * both are authenticated requests, unlike a real webhook, so they must
 * additionally prove the calling customer actually owns this payment
 * before confirming it. Without this, any authenticated customer who
 * learned or guessed another customer's gateway_ref could credit money
 * into their own flow by confirming someone else's pending payment.
 */
export async function confirmTopUpAsCustomer(customerId: string, gatewayRef: string): Promise<void> {
  const payment = await pool.query(`SELECT customer_id FROM payments WHERE gateway_ref = $1`, [gatewayRef]);
  if (payment.rowCount === 0) {
    throw Errors.notFound('Payment');
  }
  if (payment.rows[0].customer_id !== customerId) {
    throw Errors.forbidden('This payment does not belong to you.');
  }
  await confirmTopUp(gatewayRef);
}

/**
 * Debits a customer's wallet for a trip fare. Uses promotional balance first,
 * then real money. Idempotent per booking.
 */
export async function debitCustomerForBooking(
  client: PoolClient,
  params: { customerId: string; bookingId: string; amount: number }
): Promise<void> {
  const { customerId, bookingId, amount } = params;
  if (amount <= 0) return;

  const existing = await client.query(
    `SELECT 1 FROM wallet_transactions WHERE linked_booking_id = $1 AND reason = 'trip_charge'`,
    [bookingId]
  );
  if (existing.rowCount && existing.rowCount > 0) return;

  const walletId = await getOrCreateWallet(client, 'customer', customerId);
  const wallet = await client.query(
    `SELECT real_balance_cache, promo_balance_cache FROM wallets WHERE id = $1 FOR UPDATE`,
    [walletId]
  );
  const promo = parseFloat(wallet.rows[0].promo_balance_cache);
  const real = parseFloat(wallet.rows[0].real_balance_cache);
  let remaining = amount;

  const promoDebit = Math.min(promo, remaining);
  if (promoDebit > 0) {
    await recordLedgerEntry(client, {
      debitWalletId: walletId,
      creditWalletId: null,
      amount: promoDebit,
      balanceType: 'promo',
      reason: 'trip_charge',
      linkedBookingId: bookingId,
    });
    remaining -= promoDebit;
  }

  if (remaining > 0) {
    if (real < remaining) {
      throw Errors.insufficientBalance();
    }
    await recordLedgerEntry(client, {
      debitWalletId: walletId,
      creditWalletId: null,
      amount: remaining,
      balanceType: 'real',
      reason: 'trip_charge',
      linkedBookingId: bookingId,
    });
  }
}

/** Debits cancellation fee from customer wallet. Returns false if insufficient funds. */
export async function debitCustomerCancellationFee(
  client: PoolClient,
  params: { customerId: string; bookingId: string; amount: number }
): Promise<boolean> {
  const { customerId, bookingId, amount } = params;
  if (amount <= 0) return false;

  const existing = await client.query(
    `SELECT 1 FROM wallet_transactions WHERE linked_booking_id = $1 AND reason = 'cancellation_fee'`,
    [bookingId]
  );
  if (existing.rowCount && existing.rowCount > 0) return true;

  try {
    const walletId = await getOrCreateWallet(client, 'customer', customerId);
    await recordLedgerEntry(client, {
      debitWalletId: walletId,
      creditWalletId: null,
      amount,
      balanceType: 'real',
      reason: 'cancellation_fee',
      linkedBookingId: bookingId,
    });
    return true;
  } catch {
    return false;
  }
}

/** Charges customer wallet for subscription purchase. */
export async function debitCustomerForSubscription(
  client: PoolClient,
  params: { customerId: string; amount: number }
): Promise<void> {
  const { customerId, amount } = params;
  const walletId = await getOrCreateWallet(client, 'customer', customerId);
  await recordLedgerEntry(client, {
    debitWalletId: walletId,
    creditWalletId: null,
    amount,
    balanceType: 'real',
    reason: 'subscription',
  });
}

/** Debits driver wallet when a payout batch line is submitted to the bank. */
export async function debitDriverForPayout(
  client: PoolClient,
  params: { driverId: string; amount: number }
): Promise<void> {
  const { driverId, amount } = params;
  const walletId = await getOrCreateWallet(client, 'driver', driverId);
  await recordLedgerEntry(client, {
    debitWalletId: walletId,
    creditWalletId: null,
    amount,
    balanceType: 'real',
    reason: 'payout',
  });
}

/** Transfers a tip from customer to driver — distinct ledger reason from trip fare. */
export async function transferTip(
  client: PoolClient,
  params: { customerId: string; driverId: string; bookingId: string; amount: number }
): Promise<void> {
  const { customerId, driverId, bookingId, amount } = params;
  const customerWalletId = await getOrCreateWallet(client, 'customer', customerId);
  const driverWalletId = await getOrCreateWallet(client, 'driver', driverId);
  await recordLedgerEntry(client, {
    debitWalletId: customerWalletId,
    creditWalletId: driverWalletId,
    amount,
    balanceType: 'real',
    reason: 'tip',
    linkedBookingId: bookingId,
  });
}

export async function getTransactionHistory(ownerType: string, ownerId: string, type?: string) {
  const walletResult = await pool.query(
    `SELECT id FROM wallets WHERE owner_type = $1 AND owner_id = $2`,
    [ownerType, ownerId]
  );
  if (walletResult.rowCount === 0) return [];

  const walletId = walletResult.rows[0].id;
  const typeClause = type ? 'AND reason = $2' : '';
  const args = type ? [walletId, type] : [walletId];

  const result = await pool.query(
    `SELECT id, entry_type, balance_type, amount, balance_after, reason, linked_booking_id, created_at
     FROM wallet_transactions
     WHERE wallet_id = $1 ${typeClause}
     ORDER BY created_at DESC`,
    args
  );
  return result.rows;
}
