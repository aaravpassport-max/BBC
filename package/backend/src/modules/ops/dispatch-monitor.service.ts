import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { checkDriverEligibility } from '../driver/driver.service';

/** Full dispatch-attempt timeline for a booking (PRD A.3) — every
 * DriverOffered/Accepted/Declined/Expired event, in order, for an admin
 * investigating why a specific booking took the dispatch path it did. */
export async function getDispatchLog(bookingId: string) {
  const booking = await pool.query(`SELECT id, status, driver_id FROM bookings WHERE id = $1`, [bookingId]);
  if (booking.rowCount === 0) {
    throw Errors.notFound('Booking');
  }

  const offers = await pool.query(
    `SELECT id, driver_id, status, offered_at, responded_at, expires_at
     FROM dispatch_offers WHERE booking_id = $1 ORDER BY offered_at ASC`,
    [bookingId]
  );

  return {
    booking: booking.rows[0],
    offers: offers.rows,
  };
}

/**
 * Force-assigns a specific driver to a booking, bypassing the normal
 * scoring/matching algorithm (PRD A.3) — reserved for genuine exceptions
 * (e.g. a VIP corporate escalation). Critically, this overrides only the
 * *scoring* step: hard eligibility gates (KYC approval, training, no
 * suspension, no expired documents) apply identically to a force-assign as
 * to normal dispatch — there is no override path around them, matching the
 * PRD's explicit acceptance criterion that this can never assign a
 * KYC-non-approved or document-expired driver.
 */
export async function forceAssignDriver(params: {
  bookingId: string;
  driverId: string;
  actorId: string;
}): Promise<void> {
  const { bookingId, driverId, actorId } = params;

  const eligibility = await checkDriverEligibility(driverId);
  if (!eligibility.eligible) {
    throw Errors.validation({
      driver: `This driver is not eligible and cannot be force-assigned: ${eligibility.reason}`,
    });
  }

  return withTransaction(async (client) => {
    const booking = await client.query(`SELECT status, driver_id FROM bookings WHERE id = $1 FOR UPDATE`, [
      bookingId,
    ]);
    if (booking.rowCount === 0) {
      throw Errors.notFound('Booking');
    }
    if (booking.rows[0].status !== 'searching' && booking.rows[0].status !== 'no_drivers_found') {
      throw Errors.validation({
        booking: `Cannot force-assign a booking in status '${booking.rows[0].status}' — it already has a driver or has concluded.`,
      });
    }

    // Revoke any still-pending offer to a different driver — a force-assign
    // is a deliberate override, so no other driver should still be able to
    // accept the same booking after this.
    await client.query(
      `UPDATE dispatch_offers SET status = 'revoked' WHERE booking_id = $1 AND status = 'offered'`,
      [bookingId]
    );

    await client.query(
      `UPDATE bookings SET status = 'driver_assigned', driver_id = $1, updated_at = now() WHERE id = $2`,
      [driverId, bookingId]
    );

    await client.query(
      `INSERT INTO dispatch_offers (booking_id, driver_id, status, responded_at, expires_at)
       VALUES ($1, $2, 'accepted', now(), now())`,
      [bookingId, driverId]
    );

    await client.query(
      `INSERT INTO audit_log (actor_id, actor_type, action, resource_type, resource_id, after_state)
       VALUES ($1, 'user', 'booking.force_assign', 'booking', $2, $3)`,
      [actorId, bookingId, JSON.stringify({ driver_id: driverId })]
    );
  });
}
