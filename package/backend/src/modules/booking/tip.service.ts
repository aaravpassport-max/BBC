import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { transferTip } from '../wallet/wallet.service';

const TIP_WINDOW_HOURS = 24;
const PRESET_TIP_AMOUNTS = [20, 50, 100];

export function getPresetTipAmounts(): number[] {
  return PRESET_TIP_AMOUNTS;
}

export async function getTipForBooking(bookingId: string, customerId: string) {
  const result = await pool.query(
    `SELECT amount, created_at FROM tips WHERE booking_id = $1 AND customer_id = $2`,
    [bookingId, customerId]
  );
  return result.rows[0] || null;
}

export async function submitTip(params: {
  bookingId: string;
  customerId: string;
  amount: number;
}): Promise<{ tipped: boolean; amount: number }> {
  const { bookingId, customerId, amount } = params;

  if (amount <= 0 || amount > 5000) {
    throw Errors.validation({ amount: 'Tip must be between ₹1 and ₹5000.' });
  }

  return withTransaction(async (client) => {
    const booking = await client.query(
      `SELECT customer_id, driver_id, status, updated_at FROM bookings WHERE id = $1 FOR UPDATE`,
      [bookingId]
    );
    if (booking.rowCount === 0) throw Errors.notFound('Booking');
    const row = booking.rows[0];

    if (row.customer_id !== customerId) throw Errors.forbidden('This booking does not belong to you.');
    if (row.status !== 'completed') throw Errors.validation({ booking: 'Tips are only allowed on completed trips.' });
    if (!row.driver_id) throw Errors.validation({ driver: 'No driver to tip.' });

    const hoursSince = (Date.now() - new Date(row.updated_at).getTime()) / (1000 * 60 * 60);
    if (hoursSince > TIP_WINDOW_HOURS) {
      throw Errors.validation({ tip: 'The tipping window for this trip has closed.' });
    }

    const existing = await client.query(`SELECT 1 FROM tips WHERE booking_id = $1`, [bookingId]);
    if (existing.rowCount && existing.rowCount > 0) {
      throw Errors.validation({ tip: 'You have already tipped on this trip.' });
    }

    await transferTip(client, {
      customerId,
      driverId: row.driver_id,
      bookingId,
      amount,
    });

    await client.query(
      `INSERT INTO tips (booking_id, customer_id, driver_id, amount) VALUES ($1, $2, $3, $4)`,
      [bookingId, customerId, row.driver_id, amount]
    );

    return { tipped: true, amount };
  });
}
