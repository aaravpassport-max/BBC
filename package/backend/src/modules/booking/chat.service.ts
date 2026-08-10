import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { sendNotification, deriveEventId } from '../notifications/notifications.service';

async function getParticipantRole(bookingId: string, userId: string): Promise<'customer' | 'driver'> {
  const result = await pool.query(`SELECT customer_id, driver_id FROM bookings WHERE id = $1`, [bookingId]);
  if (result.rowCount === 0) {
    throw Errors.notFound('Booking');
  }
  const { customer_id: customerId, driver_id: driverId } = result.rows[0];
  if (customerId === userId) return 'customer';
  if (driverId === userId) return 'driver';
  throw Errors.forbidden('You are not a participant on this booking.');
}

/**
 * Sends a trip chat message (P0 gap analysis item — "in-app chat between
 * customer and driver"). Scoped tightly to this exact booking's two real
 * participants — verified by looking at the booking row itself, not by
 * trusting a role the client claims to have.
 */
export async function sendTripMessage(params: {
  bookingId: string;
  senderId: string;
  body: string;
}): Promise<{ id: string; senderRole: string; createdAt: string }> {
  const { bookingId, senderId, body } = params;

  const bookingRow = await pool.query(`SELECT customer_id, driver_id FROM bookings WHERE id = $1`, [bookingId]);
  if (bookingRow.rowCount === 0) {
    throw Errors.notFound('Booking');
  }
  const { customer_id: customerId, driver_id: driverId } = bookingRow.rows[0];
  let senderRole: 'customer' | 'driver';
  let recipientId: string | null;
  if (customerId === senderId) {
    senderRole = 'customer';
    recipientId = driverId;
  } else if (driverId === senderId) {
    senderRole = 'driver';
    recipientId = customerId;
  } else {
    throw Errors.forbidden('You are not a participant on this booking.');
  }

  const trimmed = body.trim();
  if (trimmed.length === 0) {
    throw Errors.validation({ body: 'Message cannot be empty.' });
  }
  if (trimmed.length > 1000) {
    throw Errors.validation({ body: 'Message is too long (max 1000 characters).' });
  }

  const result = await pool.query(
    `INSERT INTO trip_messages (booking_id, sender_id, sender_role, body)
     VALUES ($1, $2, $3, $4) RETURNING id, sender_role, created_at`,
    [bookingId, senderId, senderRole, trimmed]
  );
  const row = result.rows[0];

  if (recipientId) {
    // Each message gets its own event_id (not one shared per booking) so
    // every message genuinely notifies — idempotency here means "don't
    // double-notify for the SAME message," not "only ever notify once per
    // trip," which would silently swallow every message after the first.
    void sendNotification({
      eventId: deriveEventId(`${row.id}:new_message`),
      userId: recipientId,
      category: 'trip_updates',
      channel: 'push',
      templateId: 'new_trip_message',
    }).catch((err) => console.error('Failed to notify recipient of new message:', err));
  }

  return { id: row.id, senderRole: row.sender_role, createdAt: row.created_at };
}

/**
 * Returns the full thread for a booking, oldest first — polled by both
 * apps while a trip is active. Access requires being one of the two
 * participants (same check as sending), so a customer can never read a
 * different customer's conversation with their driver.
 */
export async function getTripMessages(bookingId: string, userId: string) {
  await getParticipantRole(bookingId, userId); // throws if not a participant — the actual access check
  const result = await pool.query(
    `SELECT id, sender_id, sender_role, body, created_at FROM trip_messages
     WHERE booking_id = $1 ORDER BY created_at ASC`,
    [bookingId]
  );
  return result.rows;
}
