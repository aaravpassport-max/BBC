import { PoolClient } from 'pg';
import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { redeemCoupon } from '../pricing/coupon.service';
import { reserveCorporateSpend, releaseReservationInTransaction, checkPerUserMonthlyCap } from '../corporate/corporate.service';
import { sendNotification, deriveEventId } from '../notifications/notifications.service';

export async function createBooking(params: {
  customerId: string;
  quoteId: string;
  // paymentMethod is accepted from the API today and will drive real payment
  // authorization once the Payments module is wired in (PRD Section 6) — not
  // yet consumed by this reference implementation, EXCEPT for 'corporate_bill'
  // which triggers the credit-reservation flow (PRD 14A.1) below.
  paymentMethod: string;
  idempotencyKey: string;
  // P1 gap-analysis item — scheduled (future-dated) bookings. The schema
  // (status='scheduled', bookings.scheduled_at) existed since migration 003
  // but no code path ever set either one; every booking was hardcoded to
  // instant dispatch. See scheduled-dispatch.service.ts for the background
  // job that actually triggers dispatch once a scheduled booking's time
  // arrives.
  scheduledFor?: string;
}): Promise<{ id: string; status: string }> {
  const { customerId, quoteId, paymentMethod, idempotencyKey, scheduledFor } = params;

  return withTransaction(async (client: PoolClient) => {
    // Idempotency check first: same key from this customer returns the existing
    // booking rather than creating a second one, even under a network-retry race
    // (PRD 2.2.6 hard requirement, backed by the DB unique constraint in
    // migration 003 on (customer_id, idempotency_key)).
    const existing = await client.query(
      `SELECT id, status FROM bookings WHERE customer_id = $1 AND idempotency_key = $2`,
      [customerId, idempotencyKey]
    );
    if (existing.rowCount && existing.rowCount > 0) {
      return { id: existing.rows[0].id, status: existing.rows[0].status };
    }

    // Lock and validate the quote.
    const quoteResult = await client.query(
      `SELECT * FROM quotes WHERE id = $1 AND customer_id = $2 FOR UPDATE`,
      [quoteId, customerId]
    );
    if (quoteResult.rowCount === 0) {
      throw Errors.notFound('Quote');
    }
    const quote = quoteResult.rows[0];

    if (quote.consumed_at) {
      throw Errors.quoteAlreadyUsed();
    }
    if (new Date(quote.expires_at) < new Date()) {
      throw Errors.quoteExpired();
    }

    await client.query(`UPDATE quotes SET consumed_at = now() WHERE id = $1`, [quoteId]);

    const pickupGeoResult = await client.query(
      `SELECT ST_X(pickup_geo::geometry) AS lng, ST_Y(pickup_geo::geometry) AS lat FROM quotes WHERE id = $1`,
      [quoteId]
    );
    const { lat, lng } = pickupGeoResult.rows[0];

    const status = scheduledFor ? 'scheduled' : 'searching'; // PRD 2.2.6 — instant booking enters SEARCHING immediately; a scheduled one waits for its own dispatch trigger instead

    let scheduledAt: Date | null = null;
    if (scheduledFor) {
      scheduledAt = new Date(scheduledFor);
      if (isNaN(scheduledAt.getTime())) {
        throw Errors.validation({ scheduled_for: 'Not a valid date/time.' });
      }
      const MIN_LEAD_MINUTES = 30; // enough real lead time for dispatch to actually work with — see scheduled-dispatch.service.ts's own window
      const MAX_ADVANCE_DAYS = 7;
      const now = new Date();
      if (scheduledAt.getTime() < now.getTime() + MIN_LEAD_MINUTES * 60 * 1000) {
        throw Errors.validation({ scheduled_for: `Scheduled bookings need at least ${MIN_LEAD_MINUTES} minutes' notice.` });
      }
      if (scheduledAt.getTime() > now.getTime() + MAX_ADVANCE_DAYS * 24 * 60 * 60 * 1000) {
        throw Errors.validation({ scheduled_for: `Cannot schedule more than ${MAX_ADVANCE_DAYS} days in advance.` });
      }
    }

    // 4-digit pickup OTP (PRD 2.2.7) — plaintext by design; see migration 007's
    // note on why this differs from the hashed login OTP (auth.service).
    const pickupOtp = Math.floor(1000 + Math.random() * 9000).toString();

    const bookingResult = await client.query(
      `INSERT INTO bookings (customer_id, idempotency_key, status, vehicle_category_id,
                              pickup_geo, pickup_address_snapshot, quote_id, fare_breakdown,
                              coupon_id, corporate_account_id, pickup_otp, scheduled_at)
       VALUES ($1, $2, $3, $4, ST_SetSRID(ST_MakePoint($5, $6), 4326), $7, $8, $9, $10, $11, $12, $13)
       RETURNING id, status`,
      [
        customerId,
        idempotencyKey,
        status,
        quote.vehicle_category_id,
        lng,
        lat,
        JSON.stringify({ lat, lng }),
        quoteId,
        quote.fare_breakdown,
        null, // coupon_id starts null — see the lock-ordering note below for why
        null, // corporate_account_id: same reasoning, set after its own reservation succeeds
        pickupOtp,
        scheduledAt,
      ]
    );

    const bookingId = bookingResult.rows[0].id;

    // Materialize the quote's drops into booking_stops (PRD Section 24 schema
    // existed since migration 003, but no code path ever populated it until
    // now — the trip-progression tables were dead until this feature). Each
    // stop gets its own 4-digit OTP; sequence order matches the order the
    // customer specified at quote time (PRD 3B.1's sequence-integrity rule
    // enforced at completion time, not creation time).
    const dropsGeo = quote.drops_geo as Array<{ lat: number; lng: number; landmark_instructions?: string }>;
    for (let i = 0; i < dropsGeo.length; i++) {
      const drop = dropsGeo[i];
      const stopOtp = Math.floor(1000 + Math.random() * 9000).toString();
      await client.query(
        `INSERT INTO booking_stops (booking_id, sequence, geo, address_snapshot, instructions, otp_code)
         VALUES ($1, $2, ST_SetSRID(ST_MakePoint($3, $4), 4326), $5, $6, $7)`,
        [
          bookingId,
          i + 1,
          drop.lng,
          drop.lat,
          JSON.stringify({ lat: drop.lat, lng: drop.lng }),
          drop.landmark_instructions || null,
          stopOtp,
        ]
      );
    }

    // LOCK ORDERING (see the identical, more thoroughly documented fix on the
    // corporate_account_id path below): redeemCoupon's SELECT ... FOR UPDATE
    // takes an EXCLUSIVE lock on the coupons row. Setting bookings.coupon_id
    // as an FK reference implicitly takes a FOR KEY SHARE lock on that same
    // row. If the FK-setting UPDATE ran before redeemCoupon's exclusive lock
    // (as an earlier version of this code did by baking coupon_id directly
    // into the initial INSERT above), two concurrent requests for the last
    // slot of the same coupon could each acquire the shared FK lock first and
    // then both block upgrading to exclusive — the exact deadlock (Postgres
    // 40P01) that was reproduced and root-caused on the corporate-credit path
    // under stress testing. Applying the identical ordering fix here
    // preemptively, even though this specific path didn't reproduce the
    // deadlock in 20 stress-test runs — the lock pattern is structurally
    // identical, so absence of a reproduction is a narrower timing window,
    // not proof of safety.
    if (quote.coupon_id) {
      await redeemCoupon(client, {
        couponId: quote.coupon_id,
        customerId,
        bookingId,
        discountAmount: (quote.fare_breakdown as { coupon_discount?: number }).coupon_discount || 0,
      });
      await client.query(`UPDATE bookings SET coupon_id = $1 WHERE id = $2`, [quote.coupon_id, bookingId]);
    }

    // PRD 14A.1: "bill to company" resolves the employee's active corporate
    // account and atomically reserves the quoted fare against its live credit
    // limit — a failure here (limit exceeded) rolls back the whole booking,
    // exactly like the coupon race above, rather than leaving a confirmed
    // booking with no valid payment authorization behind it.
    if (paymentMethod === 'corporate_bill') {
      const employeeResult = await client.query(
        `SELECT corporate_account_id FROM corporate_employees
         WHERE user_id = $1 AND status = 'active' LIMIT 1`,
        [customerId]
      );
      if (employeeResult.rowCount === 0) {
        throw Errors.forbidden('Your account is not linked to an active corporate account.');
      }
      const corporateAccountId = employeeResult.rows[0].corporate_account_id;
      const fareAmount = (quote.fare_breakdown as { final_fare: number }).final_fare;

      // PRD 14B.1: the per-user monthly cap, if the employee has one, is
      // enforced BEFORE the account-wide credit reservation — a cap
      // violation should surface as "you've hit your personal limit," not
      // get conflated with the account running out of credit entirely.
      await checkPerUserMonthlyCap(client, {
        corporateAccountId,
        employeeUserId: customerId,
        additionalAmount: fareAmount,
      });

      // LOCK ORDERING FIX (found via a flaky-test investigation — see git
      // history / PR notes): reserveCorporateSpend's SELECT ... FOR UPDATE
      // takes an EXCLUSIVE lock on the corporate_accounts row. The UPDATE
      // below sets bookings.corporate_account_id, a foreign key reference,
      // which Postgres implicitly protects with a FOR KEY SHARE lock on the
      // REFERENCED corporate_accounts row. If that FK-referencing UPDATE ran
      // BEFORE reserveCorporateSpend (as it originally did), two concurrent
      // transactions could each acquire the shared FK lock first and then
      // both block trying to upgrade to the exclusive lock the other is
      // holding — a textbook deadlock (Postgres error 40P01), reproduced
      // under real concurrency by the "two employees racing" test at roughly
      // a 1-in-4 rate. Acquiring the exclusive lock FIRST (reserve before
      // the FK-setting UPDATE) means only one transaction can ever be in the
      // critical section at a time — no cycle, no deadlock possible.
      await reserveCorporateSpend(client, {
        corporateAccountId,
        bookingId,
        amount: fareAmount,
      });

      await client.query(`UPDATE bookings SET corporate_account_id = $1 WHERE id = $2`, [
        corporateAccountId,
        bookingId,
      ]);
    }

    // In a full implementation, this is where a `BookingCreated` event (PRD
    // Section 22) is published, which the Dispatch Engine consumes to begin
    // the offer sequence (PRD Section 4) — modeled as a direct call here for
    // this reference implementation rather than a real message bus.

    return bookingResult.rows[0];
  });
}

export async function cancelBooking(params: {
  bookingId: string;
  customerId: string;
  reasonCode: string;
  note?: string;
}): Promise<{ feeCharged: boolean; feeAmount: number }> {
  const { bookingId, customerId, reasonCode, note } = params;

  return withTransaction(async (client: PoolClient) => {
    const bookingResult = await client.query(
      `SELECT * FROM bookings WHERE id = $1 AND customer_id = $2 FOR UPDATE`,
      [bookingId, customerId]
    );
    if (bookingResult.rowCount === 0) {
      throw Errors.notFound('Booking');
    }
    const booking = bookingResult.rows[0];

    // Race resolution (PRD 2A.1): whichever cancellation reaches the server first
    // wins. The row-level lock above (FOR UPDATE) serializes concurrent attempts,
    // so this check is safe from a TOCTOU race.
    if (booking.status === 'cancelled') {
      throw Errors.alreadyCancelled(booking.cancelled_by || 'system');
    }
    if (booking.status === 'completed') {
      throw Errors.validation({ booking: 'This trip has already been completed and cannot be cancelled.' });
    }

    // Fee computation from live trip stage (PRD 2A.1) — simplified tiers for
    // this reference implementation; production would consult a config table.
    let feeAmount = 0;
    if (booking.status === 'in_progress') {
      feeAmount = 50; // e.g. driver already en route / arrived
    } else if (booking.status === 'driver_assigned') {
      feeAmount = 20;
    }
    // status === 'searching' -> no driver committed yet -> no fee.

    await client.query(
      `UPDATE bookings
       SET status = 'cancelled', cancelled_by = 'customer',
           cancellation_reason_code = $1, cancellation_fee = $2, updated_at = now()
       WHERE id = $3`,
      [reasonCode, feeAmount, bookingId]
    );

    if (note) {
      await client.query(
        `INSERT INTO audit_log (actor_id, actor_type, action, resource_type, resource_id, after_state)
         VALUES ($1, 'user', 'booking.cancel', 'booking', $2, $3)`,
        [customerId, bookingId, JSON.stringify({ reason_code: reasonCode, note })]
      );
    }

    // PRD 14A.1: a reserved-but-not-yet-completed trip's cancellation releases
    // its reservation back to available limit immediately. Runs inside THIS
    // SAME transaction/client (never a separate connection) so the release
    // commits atomically with the cancellation itself — if anything later in
    // this transaction fails, the release rolls back too.
    if (booking.corporate_account_id) {
      await releaseReservationInTransaction(client, bookingId);
    }

    // Fee charge failure must never block the cancellation itself (PRD 2A.1
    // rule) — in a full implementation, the actual wallet debit for feeAmount
    // happens here via the wallet service, wrapped so its failure only marks
    // an outstanding balance rather than rolling back this transaction.

    return { feeCharged: feeAmount > 0, feeAmount, driverId: booking.driver_id as string | null };
  }).then((result) => {
    if (result.driverId) {
      // Fired only AFTER the transaction has genuinely committed — never
      // speculatively, the same rule already established for corporate
      // finalization, fleet reassignment, and every other post-commit side
      // effect in this codebase. A driver already assigned to this trip
      // needs to know it's off immediately, not on their next poll.
      void sendNotification({
        eventId: deriveEventId(`${bookingId}:cancelled`),
        userId: result.driverId,
        category: 'trip_updates',
        channel: 'push',
        templateId: 'trip_cancelled_by_customer',
      }).catch((err) => console.error('Failed to notify driver of cancellation:', err));
    }
    return { feeCharged: result.feeCharged, feeAmount: result.feeAmount };
  });
}

export async function getBooking(bookingId: string, customerId: string) {
  const result = await pool.query(
    `SELECT id, status, vehicle_category_id, fare_breakdown, driver_id, created_at, pickup_otp,
            ST_X(pickup_geo::geometry) AS pickup_lng, ST_Y(pickup_geo::geometry) AS pickup_lat
     FROM bookings WHERE id = $1 AND customer_id = $2`,
    [bookingId, customerId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Booking');
  }

  const stopsResult = await pool.query(
    `SELECT id, sequence, status, otp_code, instructions FROM booking_stops WHERE booking_id = $1 ORDER BY sequence`,
    [bookingId]
  );

  return { ...result.rows[0], stops: stopsResult.rows };
}

/**
 * The driver's current position during an active trip (PRD Section 8 live
 * tracking — previously API-only in the sense that driver.service.ts wrote
 * a driver's location on every ping, but nothing ever let the CUSTOMER on
 * that trip read it back). Scoped tightly: only the customer who owns this
 * specific booking can see this driver's location, and only while the
 * booking has a driver assigned and is still in a trackable state — a
 * driver's live position is not exposed once a trip is no longer theirs to
 * see (completed/cancelled).
 */
export async function getBookingDriverLocation(bookingId: string, customerId: string) {
  const booking = await pool.query(`SELECT driver_id, status FROM bookings WHERE id = $1 AND customer_id = $2`, [
    bookingId,
    customerId,
  ]);
  if (booking.rowCount === 0) {
    throw Errors.notFound('Booking');
  }
  const { driver_id: driverId, status } = booking.rows[0];
  if (!driverId || !['driver_assigned', 'in_progress'].includes(status)) {
    return null;
  }

  const driver = await pool.query(
    `SELECT current_lat, current_lng, last_ping_at FROM driver_profiles WHERE user_id = $1`,
    [driverId]
  );
  if (driver.rowCount === 0 || driver.rows[0].current_lat === null) {
    return null;
  }
  return {
    lat: driver.rows[0].current_lat,
    lng: driver.rows[0].current_lng,
    last_ping_at: driver.rows[0].last_ping_at,
  };
}

export async function listBookings(params: {
  customerId: string;
  status?: string;
  page: number;
  pageSize: number;
}) {
  const { customerId, status, page, pageSize } = params;
  const offset = (page - 1) * pageSize;

  const whereClause = status ? 'AND status = $2' : '';
  const args = status ? [customerId, status, pageSize, offset] : [customerId, pageSize, offset];
  const limitOffsetIndex = status ? 3 : 2;

  const result = await pool.query(
    `SELECT id, status, fare_breakdown, driver_id, created_at
     FROM bookings
     WHERE customer_id = $1 ${whereClause}
     ORDER BY created_at DESC
     LIMIT $${limitOffsetIndex} OFFSET $${limitOffsetIndex + 1}`,
    args
  );
  return result.rows;
}
