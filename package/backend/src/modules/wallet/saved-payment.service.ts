import { randomUUID as uuidv4 } from 'crypto';
import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import * as razorpay from './razorpay.provider';

export async function listSavedPaymentMethods(userId: string) {
  const result = await pool.query(
    `SELECT id, provider, method_type, display_label, is_default, created_at
     FROM saved_payment_methods WHERE user_id = $1 ORDER BY is_default DESC, created_at DESC`,
    [userId]
  );
  return result.rows;
}

export async function getSavedPaymentMethod(userId: string, methodId: string) {
  const result = await pool.query(
    `SELECT id, method_type, display_label, token_ref, provider
     FROM saved_payment_methods WHERE id = $1 AND user_id = $2`,
    [methodId, userId]
  );
  if (result.rowCount === 0) return null;
  return result.rows[0] as {
    id: string;
    method_type: 'card' | 'upi';
    display_label: string;
    token_ref: string;
    provider: string;
  };
}

export async function getOrCreateRazorpayCustomerId(userId: string): Promise<string> {
  const user = await pool.query(`SELECT razorpay_customer_id, name, phone FROM users WHERE id = $1`, [userId]);
  if (user.rowCount === 0) throw Errors.notFound('User');
  if (user.rows[0].razorpay_customer_id) {
    return user.rows[0].razorpay_customer_id as string;
  }

  if (!razorpay.isConfigured()) {
    const simId = `sim_cust_${userId.slice(0, 8)}`;
    await pool.query(`UPDATE users SET razorpay_customer_id = $1 WHERE id = $2`, [simId, userId]);
    return simId;
  }

  const customer = await razorpay.createCustomer({
    name: user.rows[0].name || 'Customer',
    contact: user.rows[0].phone,
  });
  await pool.query(`UPDATE users SET razorpay_customer_id = $1 WHERE id = $2`, [customer.id, userId]);
  return customer.id;
}

export async function initiateSaveCardSession(userId: string): Promise<Record<string, unknown>> {
  const customerId = await getOrCreateRazorpayCustomerId(userId);

  if (razorpay.isConfigured()) {
    const order = await razorpay.createTokenizationOrder({
      amountRupees: 1,
      customerId,
      receipt: `save_${uuidv4().slice(0, 8)}`,
    });
    return {
      order_id: order.id,
      amount: order.amount,
      currency: order.currency,
      key_id: process.env.RAZORPAY_KEY_ID,
      customer_id: customerId,
      simulated: false,
    };
  }

  return {
    gateway_ref: `sim_save_${uuidv4()}`,
    amount: 100,
    currency: 'INR',
    customer_id: customerId,
    simulated: true,
  };
}

export async function completeSaveCardFromPayment(params: {
  userId: string;
  paymentId: string;
  methodType: 'card' | 'upi';
  displayLabel?: string;
  setDefault?: boolean;
}): Promise<{ id: string }> {
  const { userId, paymentId, methodType, displayLabel, setDefault } = params;

  let tokenRef: string;
  let label = displayLabel?.trim() || '';

  if (razorpay.isConfigured()) {
    const payment = await razorpay.fetchPayment(paymentId);
    if (!payment.token_id) {
      throw Errors.validation({ payment: 'No token was returned for this payment.' });
    }
    tokenRef = payment.token_id;
    if (!label) {
      if (payment.method === 'upi' && payment.vpa) label = payment.vpa;
      else if (payment.card?.last4) label = `${payment.card.network ?? 'Card'} •••• ${payment.card.last4}`;
      else label = `${methodType.toUpperCase()} saved`;
    }
  } else {
    tokenRef = `sim_tok_${Date.now()}`;
    if (!label) label = `${methodType.toUpperCase()} (simulated)`;
  }

  return savePaymentMethod({
    userId,
    methodType,
    displayLabel: label,
    tokenRef,
    setDefault,
  });
}

export async function savePaymentMethod(params: {
  userId: string;
  methodType: 'card' | 'upi';
  displayLabel: string;
  tokenRef: string;
  setDefault?: boolean;
}): Promise<{ id: string }> {
  const { userId, methodType, displayLabel, tokenRef, setDefault } = params;

  return withTransaction(async (client) => {
    if (setDefault) {
      await client.query(`UPDATE saved_payment_methods SET is_default = false WHERE user_id = $1`, [userId]);
    }

    const existingDefault = await client.query(
      `SELECT count(*) FROM saved_payment_methods WHERE user_id = $1 AND is_default = true`,
      [userId]
    );
    const makeDefault = setDefault || parseInt(existingDefault.rows[0].count, 10) === 0;

    const result = await client.query(
      `INSERT INTO saved_payment_methods (user_id, method_type, display_label, token_ref, is_default)
       VALUES ($1, $2, $3, $4, $5) RETURNING id`,
      [userId, methodType, displayLabel, tokenRef, makeDefault]
    );
    return { id: result.rows[0].id };
  });
}

export async function deletePaymentMethod(userId: string, methodId: string): Promise<void> {
  const result = await pool.query(
    `DELETE FROM saved_payment_methods WHERE id = $1 AND user_id = $2 RETURNING id`,
    [methodId, userId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Payment method');
  }
}

export async function setDefaultPaymentMethod(userId: string, methodId: string): Promise<void> {
  await withTransaction(async (client) => {
    const owned = await client.query(`SELECT id FROM saved_payment_methods WHERE id = $1 AND user_id = $2`, [
      methodId,
      userId,
    ]);
    if (owned.rowCount === 0) {
      throw Errors.notFound('Payment method');
    }
    await client.query(`UPDATE saved_payment_methods SET is_default = false WHERE user_id = $1`, [userId]);
    await client.query(`UPDATE saved_payment_methods SET is_default = true WHERE id = $1`, [methodId]);
  });
}
