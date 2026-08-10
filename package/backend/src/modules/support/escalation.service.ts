import { pool } from '../../db/pool';
import { sendNotification, deriveEventId } from '../notifications/notifications.service';

/**
 * Auto-creates a high-priority support ticket when OTP verification is locked
 * (PRD 2.2.7 — 3× mismatch escalates to support automatically).
 */
export async function escalateOtpLockout(params: {
  bookingId: string;
  customerId: string;
  driverId: string;
  context: 'pickup' | 'drop';
}): Promise<string | null> {
  const { bookingId, customerId, driverId, context } = params;
  const idempotencyKey = `otp-lock:${bookingId}:${context}`;

  const existing = await pool.query(
    `SELECT id FROM support_tickets WHERE idempotency_key = $1 LIMIT 1`,
    [idempotencyKey]
  );
  if (existing.rowCount && existing.rowCount > 0) {
    return existing.rows[0].id as string;
  }

  const description =
    context === 'pickup'
      ? `Pickup OTP verification failed 3 times on booking ${bookingId.slice(0, 8)}. Auto-escalated for support review.`
      : `Drop OTP verification failed 3 times on booking ${bookingId.slice(0, 8)}. Auto-escalated for support review.`;

  const result = await pool.query(
    `INSERT INTO support_tickets (user_id, category, linked_booking_id, priority, sla_due_at, idempotency_key)
     VALUES ($1, 'trip_issue', $2, 'urgent', now() + interval '1 hour', $3)
     RETURNING id`,
    [customerId, bookingId, idempotencyKey]
  );
  const ticketId = result.rows[0].id as string;

  await pool.query(
    `INSERT INTO support_ticket_messages (ticket_id, sender_id, sender_role, body)
     VALUES ($1, $2, 'system', $3)`,
    [ticketId, customerId, description]
  );

  void sendNotification({
    eventId: deriveEventId(`${ticketId}:otp_escalation_customer`),
    userId: customerId,
    category: 'support',
    channel: 'push',
    templateId: 'support_escalation',
  }).catch(() => undefined);

  void sendNotification({
    eventId: deriveEventId(`${ticketId}:otp_escalation_driver`),
    userId: driverId,
    category: 'support',
    channel: 'push',
    templateId: 'support_escalation',
  }).catch(() => undefined);

  return ticketId;
}
