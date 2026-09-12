import { withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { finalizeCorporateReservation } from '../corporate/corporate.service';
import { processReferralOnTripCompletion } from '../booking/referral.service';
import { applyScheduledReassignment } from '../fleet/fleet.service';
import { sendNotification, deriveEventId } from '../notifications/notifications.service';
import { creditDriverTripEarnings } from '../wallet/wallet.service';

const MAX_OTP_ATTEMPTS = 3; // PRD 2.2.7 "OTP mismatch 3x -> escalation" edge case

/**
 * Driver confirms arrival + verifies the customer-read pickup OTP (PRD
 * 2.2.7). Transitions booking searching/driver_assigned -> in_progress.
 * Sequence integrity: this can only succeed once (checked via status, not
 * just OTP correctness) — a booking already in_progress or later cannot be
 * re-verified, closing the same "out-of-order/duplicate action" class of bug
 * that 3B.1's stop-completion enforces for drops.
 */
export async function verifyPickupOtp(params: {
  bookingId: string;
  driverId: string;
  otp: string;
}): Promise<{ status: string }> {
  const { bookingId, driverId, otp } = params;

  type Outcome = { kind: 'success' } | { kind: 'not_found' } | { kind: 'wrong_state'; status: string } | { kind: 'mismatch'; attemptsRemaining: number } | { kind: 'locked' };

  const outcome = await withTransaction<Outcome>(async (client) => {
    const bookingResult = await client.query(
      `SELECT status, driver_id, pickup_otp, pickup_otp_attempts FROM bookings WHERE id = $1 FOR UPDATE`,
      [bookingId]
    );
    if (bookingResult.rowCount === 0 || bookingResult.rows[0].driver_id !== driverId) {
      return { kind: 'not_found' };
    }
    const booking = bookingResult.rows[0];

    if (booking.status !== 'driver_assigned') {
      return { kind: 'wrong_state', status: booking.status };
    }

    if (booking.pickup_otp_attempts >= MAX_OTP_ATTEMPTS) {
      return { kind: 'locked' };
    }

    if (booking.pickup_otp !== otp) {
      const newAttempts = booking.pickup_otp_attempts + 1;
      await client.query(`UPDATE bookings SET pickup_otp_attempts = $1 WHERE id = $2`, [newAttempts, bookingId]);
      // Return (not throw) so this attempt-counter increment survives even
      // though we're about to report failure — the exact pattern audited and
      // fixed across auth.service/driver.service earlier; getting this right
      // here from the start rather than repeating the bug a third time.
      return { kind: 'mismatch', attemptsRemaining: MAX_OTP_ATTEMPTS - newAttempts };
    }

    await client.query(`UPDATE bookings SET status = 'in_progress', started_at = now(), updated_at = now() WHERE id = $1`, [
      bookingId,
    ]);
    return { kind: 'success' };
  });

  switch (outcome.kind) {
    case 'success':
      return { status: 'in_progress' };
    case 'not_found':
      throw Errors.notFound('Booking');
    case 'wrong_state':
      throw Errors.validation({ booking: `Cannot verify pickup OTP while booking is ${outcome.status}.` });
    case 'mismatch':
      throw Errors.validation({ otp: 'Incorrect code.', attempts_remaining: outcome.attemptsRemaining });
    case 'locked':
      // PRD 2.2.7: 3x mismatch escalates to support chat automatically in
      // both apps — this reference implementation surfaces the escalation
      // signal to the client; wiring it to actually auto-open a Support
      // ticket (Section 11B.1) is a follow-up once that flow exists.
      throw Errors.validation({ otp: 'Too many incorrect attempts. This trip has been flagged for support.' });
  }
}

/**
 * Driver completes a drop stop via OTP (PRD 2.2.7/3B.1). Sequence integrity
 * is enforced server-side: a stop can only complete if every earlier-
 * sequence stop on the same booking is already completed — checked here,
 * not just hidden by the client UI, matching 3B.1's original hard
 * requirement. Completing the LAST stop transitions the booking to
 * completed and finalizes any corporate reservation (PRD 14A.1 step 5) —
 * this is the only place in the codebase that call was missing until now.
 */
export async function completeStop(params: {
  bookingId: string;
  stopId: string;
  driverId: string;
  otp: string;
}): Promise<{ bookingStatus: string; tripCompleted: boolean }> {
  const { bookingId, stopId, driverId, otp } = params;

  type Outcome =
    | { kind: 'success'; tripCompleted: boolean; finalFare?: number; platformFee?: number; customerId?: string }
    | { kind: 'not_found' }
    | { kind: 'wrong_trip_state'; status: string }
    | { kind: 'out_of_sequence' }
    | { kind: 'mismatch'; attemptsRemaining: number }
    | { kind: 'locked' };

  const outcome = await withTransaction<Outcome>(async (client) => {
    const bookingResult = await client.query(
      `SELECT id, status, driver_id, customer_id, fare_breakdown FROM bookings WHERE id = $1 FOR UPDATE`,
      [bookingId]
    );
    if (bookingResult.rowCount === 0 || bookingResult.rows[0].driver_id !== driverId) {
      return { kind: 'not_found' };
    }
    const booking = bookingResult.rows[0];
    if (booking.status !== 'in_progress') {
      return { kind: 'wrong_trip_state', status: booking.status };
    }

    const stopResult = await client.query(
      `SELECT id, sequence, status, otp_code, otp_attempts FROM booking_stops WHERE id = $1 AND booking_id = $2 FOR UPDATE`,
      [stopId, bookingId]
    );
    if (stopResult.rowCount === 0) {
      return { kind: 'not_found' };
    }
    const stop = stopResult.rows[0];

    // Sequence integrity (PRD 3B.1 hard requirement): no earlier-sequence
    // stop may still be pending.
    const earlierPending = await client.query(
      `SELECT count(*) FROM booking_stops WHERE booking_id = $1 AND sequence < $2 AND status != 'completed'`,
      [bookingId, stop.sequence]
    );
    if (parseInt(earlierPending.rows[0].count, 10) > 0) {
      return { kind: 'out_of_sequence' };
    }

    if (stop.status === 'completed') {
      return { kind: 'wrong_trip_state', status: 'stop_already_completed' };
    }

    if (stop.otp_attempts >= MAX_OTP_ATTEMPTS) {
      return { kind: 'locked' };
    }

    if (stop.otp_code !== otp) {
      const newAttempts = stop.otp_attempts + 1;
      await client.query(`UPDATE booking_stops SET otp_attempts = $1 WHERE id = $2`, [newAttempts, stopId]);
      return { kind: 'mismatch', attemptsRemaining: MAX_OTP_ATTEMPTS - newAttempts };
    }

    await client.query(
      `UPDATE booking_stops SET status = 'completed', completed_at = now() WHERE id = $1`,
      [stopId]
    );

    const remainingResult = await client.query(
      `SELECT count(*) FROM booking_stops WHERE booking_id = $1 AND status != 'completed'`,
      [bookingId]
    );
    const tripCompleted = parseInt(remainingResult.rows[0].count, 10) === 0;

    if (tripCompleted) {
      await client.query(`UPDATE bookings SET status = 'completed', updated_at = now() WHERE id = $1`, [
        bookingId,
      ]);
      // Referral fulfillment (PRD 18A.1) runs inside THIS SAME transaction —
      // it takes a client, not a standalone withTransaction, so it commits
      // atomically with the trip completion itself rather than as a separate
      // connection (the exact deadlock-prone pattern audited and fixed
      // elsewhere in this codebase; done correctly here from the start).
      await processReferralOnTripCompletion(client, booking.customer_id, bookingId);
    }

    const finalFare = (booking.fare_breakdown as { final_fare: number } | null)?.final_fare;
    const platformFee = (booking.fare_breakdown as { platform_fee: number } | null)?.platform_fee;
    return { kind: 'success', tripCompleted, finalFare, platformFee, customerId: booking.customer_id };
  });

  switch (outcome.kind) {
    case 'success':
      // Finalizing the corporate reservation is deliberately OUTSIDE the
      // transaction above (it opens its own, per corporate.service's
      // standalone-vs-nested distinction) and only attempted after the trip-
      // completion transaction has actually committed — never speculatively
      // finalized against a completion that might still roll back.
      if (outcome.tripCompleted && outcome.finalFare !== undefined) {
        await finalizeCorporateReservation(bookingId, outcome.finalFare);
      }
      // Same "outside, after commit" rule as corporate finalization above —
      // a fleet vehicle's scheduled reassignment (PRD 13A.1) only applies
      // once this trip has genuinely finished, never speculatively.
      if (outcome.tripCompleted) {
        await applyScheduledReassignment(driverId);
      }
      // Same "outside, after commit" rule — the driver's real payout for
      // this trip, previously missing entirely (see creditDriverTripEarnings's
      // own note on how this was found). Unlike the notification below,
      // this is NOT wrapped in a swallowed .catch() — a failed payout is a
      // real financial error that must surface, not a best-effort nicety.
      if (outcome.tripCompleted && outcome.finalFare !== undefined && outcome.platformFee !== undefined) {
        await creditDriverTripEarnings({
          driverId,
          bookingId,
          fareBreakdown: { final_fare: outcome.finalFare, platform_fee: outcome.platformFee },
        });
      }
      if (outcome.tripCompleted && outcome.customerId) {
        void sendNotification({
          eventId: deriveEventId(`${bookingId}:completed`),
          userId: outcome.customerId,
          category: 'trip_updates',
          channel: 'push',
          templateId: 'trip_completed',
        }).catch((err) => console.error('Failed to notify customer of trip completion:', err));
      }
      return { bookingStatus: outcome.tripCompleted ? 'completed' : 'in_progress', tripCompleted: outcome.tripCompleted };
    case 'not_found':
      throw Errors.notFound('Booking or stop');
    case 'wrong_trip_state':
      throw Errors.validation({ booking: `Cannot complete this stop (${outcome.status}).` });
    case 'out_of_sequence':
      throw Errors.validation({ stop: 'An earlier stop on this trip has not been completed yet.' });
    case 'mismatch':
      throw Errors.validation({ otp: 'Incorrect code.', attempts_remaining: outcome.attemptsRemaining });
    case 'locked':
      throw Errors.validation({ otp: 'Too many incorrect attempts. This trip has been flagged for support.' });
  }
}
