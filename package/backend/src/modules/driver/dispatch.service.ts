import { randomUUID as uuidv4 } from 'crypto';
import { pool, withTransaction } from '../../db/pool';
import { sendNotification, deriveEventId } from '../notifications/notifications.service';

const OFFER_TIMEOUT_SECONDS = 15; // PRD 3.3 config default
const SEARCH_RADII_KM = [2, 4, 6]; // PRD Section 4 — expanding rings on no match

interface EligibleDriver {
  driver_id: string;
  distance_km: number;
}

/**
 * Finds eligible drivers for a booking within a radius, ordered by ETA-to-
 * pickup (approximated here by straight-line distance — a production system
 * would use a real routing engine for ETA, PRD Section 4 step 2). Eligibility
 * mirrors driver.service.checkDriverEligibility's rules inline via SQL for
 * bulk candidate scanning performance, but must be kept in sync with that
 * function's logic — see the note at the bottom of this file.
 */
async function findEligibleDrivers(
  pickupLat: number,
  pickupLng: number,
  vehicleCategoryId: string,
  radiusKm: number,
  excludeDriverIds: string[]
): Promise<EligibleDriver[]> {
  const excludeClause = excludeDriverIds.length > 0 ? 'AND dp.user_id != ALL($5)' : '';
  const args: unknown[] = [pickupLng, pickupLat, radiusKm * 1000, vehicleCategoryId];
  if (excludeDriverIds.length > 0) args.push(excludeDriverIds);

  const result = await pool.query(
    `SELECT
       dp.user_id AS driver_id,
       ST_Distance(
         ST_SetSRID(ST_MakePoint(dp.current_lng, dp.current_lat), 4326)::geography,
         ST_SetSRID(ST_MakePoint($1, $2), 4326)::geography
       ) / 1000.0 AS distance_km
     FROM driver_profiles dp
     JOIN driver_vehicle_assignment dva ON dva.driver_id = dp.user_id AND dva.is_active = true
     JOIN vehicles v ON v.id = dva.vehicle_id AND v.category = (
       SELECT name FROM vehicle_categories WHERE id = $4
     )
     WHERE dp.online_status = true
       AND dp.kyc_status = 'approved'
       AND dp.training_status = 'passed'
       AND dp.suspended_at IS NULL
       AND dp.current_lat IS NOT NULL
       AND dp.last_ping_at > now() - interval '2 minutes'
       -- No open dispatch offer already outstanding for this driver (structural
       -- guarantee is the DB unique index; this filter just avoids wasting a
       -- scoring pass on a driver who is provably unofferable right now).
       AND NOT EXISTS (
         SELECT 1 FROM dispatch_offers doff
         WHERE doff.driver_id = dp.user_id AND doff.status = 'offered'
       )
       AND ST_DWithin(
         ST_SetSRID(ST_MakePoint(dp.current_lng, dp.current_lat), 4326)::geography,
         ST_SetSRID(ST_MakePoint($1, $2), 4326)::geography,
         $3
       )
       ${excludeClause}
     ORDER BY distance_km ASC
     LIMIT 20`,
    args
  );

  return result.rows;
}

/**
 * Creates a single dispatch offer for the top-scored candidate driver.
 * The DB unique index `uq_dispatch_offers_one_active_per_driver` (migration
 * 003) is the actual structural guarantee behind PRD Section 4's "a driver
 * can never receive two simultaneous offers" — this insert will fail with a
 * unique-violation if that invariant is ever at risk, rather than silently
 * allowing it.
 */
async function createOffer(bookingId: string, driverId: string): Promise<string> {
  return withTransaction(async (client) => {
    const offerId = uuidv4();
    const expiresAt = new Date(Date.now() + OFFER_TIMEOUT_SECONDS * 1000);
    await client.query(
      `INSERT INTO dispatch_offers (id, booking_id, driver_id, status, expires_at)
       VALUES ($1, $2, $3, 'offered', $4)`,
      [offerId, bookingId, driverId, expiresAt]
    );
    return offerId;
  });
}

export interface DispatchResult {
  status: 'offer_sent' | 'no_drivers_found';
  offerId?: string;
  driverId?: string;
}

/**
 * Runs one dispatch attempt for a booking: try the best candidate; if the
 * booking has already exhausted attempts at every radius tier with no
 * acceptance, mark NO_DRIVERS_FOUND (PRD Section 4 step 4). This function
 * is meant to be invoked by an event consumer on BookingCreated and again on
 * every DriverDeclined/expired/revoked event — modeled here as a directly
 * callable function for this reference implementation rather than a real
 * message-bus consumer.
 */
export async function runDispatchCycle(bookingId: string): Promise<DispatchResult> {
  const bookingResult = await pool.query(
    `SELECT b.id, b.vehicle_category_id, b.status,
            ST_X(b.pickup_geo::geometry) AS lng, ST_Y(b.pickup_geo::geometry) AS lat
     FROM bookings b WHERE b.id = $1`,
    [bookingId]
  );
  if (bookingResult.rowCount === 0) {
    return { status: 'no_drivers_found' };
  }
  const booking = bookingResult.rows[0];

  if (booking.status !== 'searching') {
    // Already assigned, cancelled, or otherwise no longer eligible for dispatch.
    return { status: 'no_drivers_found' };
  }

  // Drivers already offered-and-declined or offered-and-expired for THIS
  // booking are excluded from re-offering within the same cycle (PRD Section 4
  // "never re-offered within a cooldown" rule, simplified here to "never
  // twice in the same booking's dispatch attempt sequence").
  const alreadyTriedResult = await pool.query(
    `SELECT DISTINCT driver_id FROM dispatch_offers WHERE booking_id = $1`,
    [bookingId]
  );
  const excludeDriverIds = alreadyTriedResult.rows.map((r) => r.driver_id);

  for (const radiusKm of SEARCH_RADII_KM) {
    const candidates = await findEligibleDrivers(
      booking.lat,
      booking.lng,
      booking.vehicle_category_id,
      radiusKm,
      excludeDriverIds
    );

    if (candidates.length > 0) {
      const best = candidates[0];
      try {
        const offerId = await createOffer(bookingId, best.driver_id);
        // PRD Section 4 / 16A.2: a new job offer is exactly the kind of
        // time-sensitive event push notifications exist for — the driver
        // has a short (15s) window to respond, so this fires immediately
        // rather than waiting for the driver's own poll to notice it.
        // Failure to notify must never block dispatch itself — this event
        // firing is a side effect of a successful offer, not a precondition.
        void sendNotification({
          eventId: deriveEventId(`${offerId}:new_offer`),
          userId: best.driver_id,
          category: 'trip_updates',
          channel: 'push',
          templateId: 'new_job_offer',
        }).catch((err) => console.error('Failed to notify driver of new offer:', err));
        return { status: 'offer_sent', offerId, driverId: best.driver_id };
      } catch (err) {
        // Unique-violation on the one-active-offer-per-driver index means this
        // driver received a concurrent offer from another booking's dispatch
        // cycle between our SELECT and our INSERT — a genuine, expected race
        // under concurrency. Fall through and let the caller retry the cycle
        // rather than crash; PRD Section 4 acceptance criteria requires this
        // to be handled, not just theoretically prevented.
        const pgErr = err as { code?: string };
        if (pgErr.code === '23505') {
          continue;
        }
        throw err;
      }
    }
  }

  await pool.query(`UPDATE bookings SET status = 'no_drivers_found', updated_at = now() WHERE id = $1`, [
    bookingId,
  ]);
  return { status: 'no_drivers_found' };
}

/**
 * Sweeps expired offers and re-triggers dispatch for their bookings (PRD
 * Section 4 — on decline/timeout, offer to next candidate). Intended to run
 * as a scheduled background job (PRD Section 25/26), invoked here as a plain
 * exported function so it can be called from a cron entrypoint or tested
 * directly.
 */
/**
 * P1 gap-analysis item — scheduled (future-dated) bookings. A scheduled
 * booking sits in status='scheduled' doing nothing until its own time
 * approaches; this is what actually transitions it into real dispatch,
 * the same way sweepExpiredOffers above turns an expired offer into the
 * next dispatch attempt. Fires DISPATCH_LEAD_MINUTES before the
 * customer's requested time — early enough that a driver has a real
 * chance to be found and en route by the actual scheduled moment, not
 * dispatched exactly AT that moment with zero lead time.
 */
const DISPATCH_LEAD_MINUTES = 15;

export async function sweepScheduledBookings(): Promise<number> {
  const due = await pool.query(
    `UPDATE bookings SET status = 'searching', updated_at = now()
     WHERE status = 'scheduled' AND scheduled_at <= now() + interval '${DISPATCH_LEAD_MINUTES} minutes'
     RETURNING id`
  );

  for (const row of due.rows) {
    await runDispatchCycle(row.id);
  }
  return due.rowCount || 0;
}

export async function sweepExpiredOffers(): Promise<number> {
  const expired = await pool.query(
    `UPDATE dispatch_offers SET status = 'expired'
     WHERE status = 'offered' AND expires_at < now()
     RETURNING booking_id`
  );

  for (const row of expired.rows) {
    await runDispatchCycle(row.booking_id);
  }
  return expired.rowCount || 0;
}

// NOTE on eligibility-logic duplication: findEligibleDrivers above re-implements
// the KYC/training/suspension checks from driver.service.checkDriverEligibility
// as inline SQL for bulk-scan performance (checking one driver at a time via
// that function for every candidate in a city would be far too slow for
// dispatch). If PRD Section 3.2/9A.2's eligibility rules change, BOTH this
// query and checkDriverEligibility must be updated together — flagged here
// deliberately rather than silently risking drift between the two.
