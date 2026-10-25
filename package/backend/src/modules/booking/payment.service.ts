import { randomUUID as uuidv4 } from 'crypto';
import { PoolClient } from 'pg';
import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import * as razorpay from '../wallet/razorpay.provider';
import { runDispatchCycle } from '../driver/dispatch.service';

export async function initiateTripPayment(
  client: PoolClient,
  params: { customerId: string; bookingId: string; amount: number; method: string }
): Promise<Record<string, unknown>> {
  const { customerId, bookingId, amount, method } = params;

  let gatewayRef: string;
  let gatewaySession: Record<string, unknown>;

  if (razorpay.isConfigured()) {
    const order = await razorpay.createOrder({
      amountRupees: amount,
      receipt: `trip_${bookingId.slice(0, 8)}`,
      notes: { booking_id: bookingId, customer_id: customerId },
    });
    gatewayRef = order.id;
    gatewaySession = {
      order_id: order.id,
      amount: order.amount,
      currency: order.currency,
      key_id: process.env.RAZORPAY_KEY_ID,
      simulated: false,
    };
  } else {
    gatewayRef = `sim_trip_${uuidv4()}`;
    gatewaySession = { gateway_ref: gatewayRef, simulated: true, amount: Math.round(amount * 100), currency: 'INR' };
  }

  await client.query(
    `INSERT INTO payments (gateway_ref, status, amount, method, customer_id, booking_id)
     VALUES ($1, 'pending', $2, $3, $4, $5)`,
    [gatewayRef, amount, method, customerId, bookingId]
  );

  return gatewaySession;
}

export async function confirmTripPayment(gatewayRef: string): Promise<{ bookingId: string | null }> {
  return withTransaction(async (client) => {
    const payment = await client.query(
      `SELECT id, status, booking_id, customer_id FROM payments WHERE gateway_ref = $1 FOR UPDATE`,
      [gatewayRef]
    );
    if (payment.rowCount === 0) throw Errors.notFound('Payment');
    const row = payment.rows[0];
    if (row.status === 'succeeded') return { bookingId: row.booking_id };

    if (!row.booking_id) {
      throw Errors.validation({ payment: 'Not a trip payment.' });
    }

    await client.query(
      `UPDATE payments SET status = 'succeeded', webhook_received_at = now() WHERE id = $1`,
      [row.id]
    );

    return { bookingId: row.booking_id as string };
  });
}

export async function confirmTripPaymentAsCustomer(
  customerId: string,
  gatewayRef: string
): Promise<{ bookingId: string | null }> {
  const payment = await pool.query(`SELECT customer_id, booking_id FROM payments WHERE gateway_ref = $1`, [gatewayRef]);
  if (payment.rowCount === 0) throw Errors.notFound('Payment');
  if (payment.rows[0].customer_id !== customerId) {
    throw Errors.forbidden('This payment does not belong to you.');
  }
  const result = await confirmTripPayment(gatewayRef);
  if (result.bookingId) {
    const booking = await pool.query(`SELECT status FROM bookings WHERE id = $1`, [result.bookingId]);
    if (booking.rows[0]?.status === 'searching') {
      void runDispatchCycle(result.bookingId).catch(() => undefined);
    }
  }
  return result;
}

export async function hasPendingTripPayment(bookingId: string): Promise<boolean> {
  const result = await pool.query(
    `SELECT 1 FROM payments WHERE booking_id = $1 AND status = 'pending' LIMIT 1`,
    [bookingId]
  );
  return (result.rowCount ?? 0) > 0;
}
