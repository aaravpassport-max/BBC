import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { sendNotification, deriveEventId } from '../notifications/notifications.service';
import { broadcastBookingEvent, broadcastUserEvent } from '../realtime/realtime.hub';

/**
 * Computes job-eligibility live from the driver's current state — never a
 * cached flag, since KYC status, training, suspension, and document expiry
 * can all change independently (PRD Section 3.2, 9A.2, 3A.1). This is the
 * single function every eligibility check in the codebase should call
 * through, so eligibility logic never drifts between call sites.
 */
export async function checkDriverEligibility(
  driverId: string
): Promise<{ eligible: boolean; reason?: string }> {
  const profileResult = await pool.query(
    `SELECT kyc_status, training_status, suspended_at, suspension_reason
     FROM driver_profiles WHERE user_id = $1`,
    [driverId]
  );
  if (profileResult.rowCount === 0) {
    return { eligible: false, reason: 'DRIVER_PROFILE_NOT_FOUND' };
  }
  const profile = profileResult.rows[0];

  if (profile.kyc_status !== 'approved') {
    return { eligible: false, reason: 'KYC_NOT_APPROVED' };
  }
  if (profile.training_status !== 'passed') {
    return { eligible: false, reason: 'TRAINING_NOT_COMPLETE' };
  }
  if (profile.suspended_at) {
    return { eligible: false, reason: `SUSPENDED_${profile.suspension_reason || 'OTHER'}` };
  }

  // Any approved document past its expiry date blocks eligibility (PRD 3A.1) —
  // checked live against kyc_documents, not a cached "is valid" flag.
  const expiredDocs = await pool.query(
    `SELECT doc_type FROM kyc_documents
     WHERE subject_type = 'driver' AND subject_id = $1
       AND status = 'approved' AND expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE`,
    [driverId]
  );
  if (expiredDocs.rowCount && expiredDocs.rowCount > 0) {
    return { eligible: false, reason: `DOCUMENT_EXPIRED_${expiredDocs.rows[0].doc_type.toUpperCase()}` };
  }

  return { eligible: true };
}

export async function setDriverOnlineStatus(
  driverId: string,
  online: boolean,
  offlineReason?: string
): Promise<void> {
  if (online) {
    const eligibility = await checkDriverEligibility(driverId);
    if (!eligibility.eligible) {
      throw Errors.driverIneligible(
        `Cannot go online: ${eligibility.reason}. Please resolve this before accepting jobs.`
      );
    }
  }

  await pool.query(
    `UPDATE driver_profiles SET online_status = $1, updated_at = now() WHERE user_id = $2`,
    [online, driverId]
  );

  void offlineReason; // captured for analytics (PRD Section A.2) — not persisted in this reference schema yet
}

export async function updateDriverLocation(
  driverId: string,
  lat: number,
  lng: number
): Promise<void> {
  await pool.query(
    `UPDATE driver_profiles SET current_lat = $1, current_lng = $2, last_ping_at = now() WHERE user_id = $3`,
    [lat, lng, driverId]
  );

  const active = await pool.query(
    `SELECT id, customer_id FROM bookings WHERE driver_id = $1 AND status IN ('driver_assigned', 'in_progress') LIMIT 1`,
    [driverId]
  );
  if (active.rowCount && active.rowCount > 0) {
    const bookingId = active.rows[0].id as string;
    broadcastBookingEvent(bookingId, { event: 'driver.location', lat, lng, at: new Date().toISOString() });
    broadcastUserEvent(active.rows[0].customer_id as string, {
      event: 'driver.location',
      booking_id: bookingId,
      lat,
      lng,
    });
  }
}

/**
 * Driver accepts an offered job (PRD 3.3). Uses row locking on the offer to
 * make the accept atomic against a concurrent expiry-sweep or revocation —
 * an offer can only be accepted while it is still in 'offered' status.
 */
export async function acceptJobOffer(
  offerId: string,
  driverId: string
): Promise<{ bookingId: string }> {
  type AcceptOutcome =
    | { kind: 'accepted'; bookingId: string; customerId: string }
    | { kind: 'not_found' }
    | { kind: 'unavailable'; status: string }
    | { kind: 'expired' };

  const outcome = await withTransaction<AcceptOutcome>(async (client) => {
    const offerResult = await client.query(
      `SELECT * FROM dispatch_offers WHERE id = $1 AND driver_id = $2 FOR UPDATE`,
      [offerId, driverId]
    );
    if (offerResult.rowCount === 0) {
      return { kind: 'not_found' };
    }
    const offer = offerResult.rows[0];

    if (offer.status !== 'offered') {
      // Covers both "already expired" and "revoked because the customer
      // cancelled mid-offer" (PRD 3.3 edge case) with the same clear signal.
      return { kind: 'unavailable', status: offer.status };
    }
    if (new Date(offer.expires_at) < new Date()) {
      // This mutation must commit even though we're about to report failure —
      // returning (not throwing) here is what makes that possible; see the
      // note on verifyOtp's identical fix for why throwing here would have
      // silently rolled this UPDATE back (a real bug caught by the auth test
      // suite and audited into every other withTransaction call site).
      await client.query(`UPDATE dispatch_offers SET status = 'expired' WHERE id = $1`, [offerId]);
      return { kind: 'expired' };
    }

    await client.query(
      `UPDATE dispatch_offers SET status = 'accepted', responded_at = now() WHERE id = $1`,
      [offerId]
    );
    await client.query(
      `UPDATE bookings SET status = 'driver_assigned', driver_id = $1, updated_at = now() WHERE id = $2`,
      [driverId, offer.booking_id]
    );
    const bookingRow = await client.query(`SELECT customer_id FROM bookings WHERE id = $1`, [offer.booking_id]);

    return { kind: 'accepted', bookingId: offer.booking_id, customerId: bookingRow.rows[0].customer_id };
  });

  switch (outcome.kind) {
    case 'accepted':
      // Outside the transaction, after commit — the same "never speculatively
      // notify against something that might still roll back" rule already
      // established for corporate finalization and fleet reassignment
      // elsewhere in this codebase.
      void sendNotification({
        eventId: deriveEventId(`${outcome.bookingId}:driver_assigned`),
        userId: outcome.customerId,
        category: 'trip_updates',
        channel: 'push',
        templateId: 'driver_on_the_way',
      }).catch((err) => console.error('Failed to notify customer of driver assignment:', err));
      broadcastBookingEvent(outcome.bookingId, { event: 'booking.status', status: 'driver_assigned' });
      broadcastUserEvent(outcome.customerId, {
        event: 'booking.status',
        booking_id: outcome.bookingId,
        status: 'driver_assigned',
      });
      return { bookingId: outcome.bookingId };
    case 'not_found':
      throw Errors.notFound('Job offer');
    case 'unavailable':
      throw Errors.validation({ offer: `This offer is no longer available (${outcome.status}).` });
    case 'expired':
      throw Errors.validation({ offer: 'This offer has expired.' });
  }
}

export async function declineJobOffer(offerId: string, driverId: string): Promise<{ bookingId: string }> {
  return withTransaction(async (client) => {
    const result = await client.query(
      `UPDATE dispatch_offers SET status = 'declined', responded_at = now()
       WHERE id = $1 AND driver_id = $2 AND status = 'offered'
       RETURNING booking_id`,
      [offerId, driverId]
    );
    if (result.rowCount === 0) {
      throw Errors.notFound('Job offer');
    }
    return { bookingId: result.rows[0].booking_id };
  });
}

/**
 * Lets the Driver App poll for a currently-outstanding offer (PRD 3.3) —
 * this reference backend has no push/websocket infrastructure, so the
 * frontend polls this endpoint rather than receiving a real-time push.
 * Returns null (not an error) when there is nothing pending — an empty
 * result is a normal, common state for this endpoint, not a failure.
 */
export async function getMyPendingOffer(driverId: string) {
  const result = await pool.query(
    `SELECT o.id AS offer_id, o.booking_id, o.expires_at,
            b.pickup_geo, b.fare_breakdown,
            ST_X(b.pickup_geo::geometry) AS pickup_lng, ST_Y(b.pickup_geo::geometry) AS pickup_lat
     FROM dispatch_offers o
     JOIN bookings b ON b.id = o.booking_id
     WHERE o.driver_id = $1 AND o.status = 'offered' AND o.expires_at > now()
     ORDER BY o.offered_at DESC LIMIT 1`,
    [driverId]
  );
  return result.rows[0] || null;
}

/** The driver's current active job, if any (PRD Home screen / Section 3B.1) — used
 * by the Driver App to resume the correct screen (offer / navigate / verify) on load.
 *
 * SECURITY: deliberately does NOT return pickup_otp or any stop's otp_code.
 * These codes exist so the CUSTOMER can prove the driver is genuinely
 * present by reading them aloud (PRD 2.2.7) — if the driver's own app could
 * just fetch the code from the database, the entire verification is
 * defeated (a driver could mark every pickup/drop complete without ever
 * meeting the customer). An earlier version of this function returned the
 * OTP columns directly and a test asserted that leak as "expected"
 * behavior — both are fixed here; the driver submits what the customer
 * tells them via POST .../verify-pickup and .../stops/:id/complete, and
 * the server validates it server-side without ever exposing the answer. */
export async function getMyActiveJob(driverId: string) {
  const result = await pool.query(
    `SELECT id, status,
            ST_X(pickup_geo::geometry) AS pickup_lng, ST_Y(pickup_geo::geometry) AS pickup_lat
     FROM bookings
     WHERE driver_id = $1 AND status IN ('driver_assigned', 'in_progress')
     ORDER BY created_at DESC LIMIT 1`,
    [driverId]
  );
  if (result.rowCount === 0) return null;

  // P1 gap-analysis item (turn-by-turn navigation): drop coordinates were
  // always stored (booking_stops.geo) but never queried here — the driver
  // app had pickup coordinates only, meaning navigation could only ever
  // work for the first leg of a trip. Real gap, not a simplification.
  const stops = await pool.query(
    `SELECT id, sequence, status, instructions,
            ST_X(geo::geometry) AS drop_lng, ST_Y(geo::geometry) AS drop_lat
     FROM booking_stops WHERE booking_id = $1 ORDER BY sequence`,
    [result.rows[0].id]
  );
  return { ...result.rows[0], stops: stops.rows };
}

/**
 * Registers a vehicle for an independent owner-driver (PRD 3.2 step 4 /
 * Section A.2 Vehicle Management). GENUINE GAP FOUND DURING FRONTEND
 * VERIFICATION: no code path anywhere created the `vehicles` +
 * `driver_vehicle_assignment` rows that dispatch.service's eligibility
 * query requires (it JOINs through driver_vehicle_assignment to match
 * vehicle category) — meaning a real driver going through the full KYC
 * flow via the actual API could complete every document, get approved,
 * go online successfully, and STILL never receive a single job offer,
 * with no error anywhere telling them why. Only found because the Driver
 * App's end-to-end check drove the real API instead of relying on the
 * backend test suite's own SQL-inserted fixture data, which had silently
 * been masking this gap in every test written so far.
 */
export async function registerVehicle(params: {
  driverId: string;
  category: string;
  plateNumber: string;
}): Promise<{ id: string }> {
  const { driverId, category, plateNumber } = params;

  const categoryExists = await pool.query(`SELECT 1 FROM vehicle_categories WHERE name = $1 AND status = 'active'`, [
    category,
  ]);
  if (categoryExists.rowCount === 0) {
    throw Errors.validation({ category: 'Unknown or inactive vehicle category.' });
  }

  return withTransaction(async (client) => {
    let vehicleId: string;
    try {
      const vehicle = await client.query(
        `INSERT INTO vehicles (owner_type, owner_id, category, plate_number, status)
         VALUES ('driver', $1, $2, $3, 'active') RETURNING id`,
        [driverId, category, plateNumber]
      );
      vehicleId = vehicle.rows[0].id;
    } catch (err) {
      const pgErr = err as { code?: string };
      if (pgErr.code === '23505') {
        throw Errors.validation({ plate_number: 'This plate number is already registered.' });
      }
      throw err;
    }

    // An owner-driver has at most one active vehicle assignment at a time
    // in this reference implementation — registering a new one deactivates
    // any prior assignment (mirrors the fleet reassignment pattern in
    // fleet.service, minus the in-progress-trip protection, since an
    // independent driver registering their own new vehicle while they
    // themselves are mid-trip on the old one is a self-contradictory
    // scenario the UI shouldn't allow to begin with).
    await client.query(
      `UPDATE driver_vehicle_assignment SET is_active = false, effective_to = now()
       WHERE driver_id = $1 AND is_active = true`,
      [driverId]
    );
    await client.query(
      `INSERT INTO driver_vehicle_assignment (driver_id, vehicle_id, is_active) VALUES ($1, $2, true)`,
      [driverId, vehicleId]
    );

    return { id: vehicleId };
  });
}

export async function listDriverJobHistory(driverId: string, page = 1, pageSize = 20) {
  const offset = (page - 1) * pageSize;
  const result = await pool.query(
    `SELECT id, status, fare_breakdown, created_at, updated_at
     FROM bookings
     WHERE driver_id = $1 AND status IN ('completed', 'cancelled')
     ORDER BY created_at DESC
     LIMIT $2 OFFSET $3`,
    [driverId, pageSize, offset]
  );
  return result.rows;
}

export async function updateDriverPartnerProfile(
  driverId: string,
  data: { name?: string; email?: string | null }
) {
  if (data.name !== undefined) {
    await pool.query(`UPDATE users SET name = $1, updated_at = now() WHERE id = $2`, [data.name, driverId]);
  }
  if (data.email !== undefined) {
    await pool.query(`UPDATE users SET email = $1, updated_at = now() WHERE id = $2`, [data.email, driverId]);
  }
  return getDriverPartnerProfile(driverId);
}

export async function getDriverPartnerProfile(driverId: string) {
  const result = await pool.query(
    `SELECT u.id, u.name, u.phone, u.email,
            dp.kyc_status, dp.training_status, dp.rating_avg, dp.rating_count, dp.online_status,
            v.plate_number, v.category AS vehicle_category, v.make, v.model
     FROM users u
     JOIN driver_profiles dp ON dp.user_id = u.id
     LEFT JOIN driver_vehicle_assignment dva ON dva.driver_id = u.id AND dva.is_active = true
     LEFT JOIN vehicles v ON v.id = dva.vehicle_id
     WHERE u.id = $1`,
    [driverId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Driver profile');
  }
  const row = result.rows[0];
  return {
    id: row.id,
    name: row.name,
    phone: row.phone,
    email: row.email,
    kyc_status: row.kyc_status,
    training_status: row.training_status,
    rating_avg: row.rating_avg ? parseFloat(row.rating_avg) : null,
    rating_count: row.rating_count,
    online_status: row.online_status,
    vehicle: row.plate_number
      ? { plate: row.plate_number, category: row.vehicle_category, make: row.make, model: row.model }
      : null,
  };
}
