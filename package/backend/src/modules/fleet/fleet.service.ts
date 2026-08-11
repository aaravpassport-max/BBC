import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { sendNotification, deriveEventId } from '../notifications/notifications.service';

/**
 * P1 gap-analysis item — the Fleet/Owner experience. The gap-analysis
 * report originally framed this as needing "a new entity in the data
 * model" — that turned out to be wrong on closer inspection: the schema
 * (driver_profiles.fleet_owner_id, vehicles.owner_type='fleet') and even
 * vehicle-reassignment logic already existed from an earlier pass. What
 * was genuinely missing: any way to see a fleet's drivers, add a driver
 * to a fleet through a real endpoint (previously only a raw-SQL test
 * helper did this), see fleet-wide earnings, or see one driver's own
 * detail — every function below is a real gap, not a restatement of
 * something that already worked.
 */

/**
 * The fleet owner's "My Fleet" bird's-eye view — every driver in this
 * fleet with their real, current status. 'on_trip' vs 'online' is derived
 * from whether the driver has a live booking, not a separate stored flag
 * that could drift out of sync with reality.
 */
export async function getFleetDrivers(ownerId: string) {
  const result = await pool.query(
    `SELECT dp.user_id AS driver_id, u.phone, u.name, dp.online_status, dp.current_lat, dp.current_lng,
            EXISTS (
              SELECT 1 FROM bookings b WHERE b.driver_id = dp.user_id AND b.status IN ('driver_assigned', 'in_progress')
            ) AS on_trip
     FROM driver_profiles dp
     JOIN users u ON u.id = dp.user_id
     WHERE dp.fleet_owner_id = $1
     ORDER BY u.phone`,
    [ownerId]
  );
  return result.rows.map((row) => ({
    driver_id: row.driver_id,
    phone: row.phone,
    name: row.name,
    status: row.on_trip ? 'on_trip' : row.online_status ? 'online' : 'offline',
    current_lat: row.current_lat,
    current_lng: row.current_lng,
  }));
}

/**
 * Links an EXISTING driver account to this fleet owner, by phone number —
 * the driver must already have registered and gone through their own
 * onboarding (this is deliberately not a way to create a driver account
 * out of nothing; a fleet owner recruiting a driver who already drives
 * for the platform is the real-world shape of this action, matching how
 * Porter's own "Owner Assist" product is documented to work). A driver
 * already linked to a DIFFERENT fleet owner cannot be silently poached —
 * this must be explicit (the driver leaving their current fleet first),
 * not a side effect of another owner adding the same phone number.
 */
export async function addDriverToFleet(params: { ownerId: string; driverPhone: string }): Promise<{ driverId: string }> {
  const { ownerId, driverPhone } = params;

  return withTransaction(async (client) => {
    const driver = await client.query(
      `SELECT u.id, dp.fleet_owner_id FROM users u
       JOIN driver_profiles dp ON dp.user_id = u.id
       WHERE u.phone = $1 AND u.account_type = 'driver' FOR UPDATE`,
      [driverPhone]
    );
    if (driver.rowCount === 0) {
      throw Errors.notFound('Driver');
    }
    const { id: driverId, fleet_owner_id: currentFleetOwnerId } = driver.rows[0];

    if (currentFleetOwnerId === ownerId) {
      return { driverId }; // already in this fleet — idempotent, not an error
    }
    if (currentFleetOwnerId) {
      throw Errors.validation({ driver: 'This driver already belongs to another fleet.' });
    }

    await client.query(`UPDATE driver_profiles SET fleet_owner_id = $1 WHERE user_id = $2`, [ownerId, driverId]);
    void sendNotification({
      eventId: deriveEventId(`fleet:driver_added:${ownerId}:${driverId}`),
      userId: driverId,
      category: 'account_activity',
      channel: 'push',
      templateId: 'fleet_driver_added',
    }).catch(() => undefined);
    return { driverId };
  });
}

/**
 * Removes a driver from this fleet — ownership-checked, same as every
 * other fleet action. Deliberately does not touch the driver's own
 * account, KYC status, or wallet — leaving a fleet only means they're no
 * longer managed by this owner, not that anything about their own driver
 * profile changes.
 */
export async function removeDriverFromFleet(params: { ownerId: string; driverId: string }): Promise<void> {
  const { ownerId, driverId } = params;
  const result = await pool.query(
    `UPDATE driver_profiles SET fleet_owner_id = NULL WHERE user_id = $1 AND fleet_owner_id = $2`,
    [driverId, ownerId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Driver');
  }
  void sendNotification({
    eventId: deriveEventId(`fleet:driver_removed:${ownerId}:${driverId}`),
    userId: driverId,
    category: 'account_activity',
    channel: 'push',
    templateId: 'fleet_driver_removed',
  }).catch(() => undefined);
}

/**
 * A single fleet driver's detail — their own wallet balance and recent
 * transaction history, scoped so a fleet owner can only ever see this for
 * a driver genuinely in their own fleet (403, not empty data, for anyone
 * else's driver).
 */
export async function getFleetDriverDetail(ownerId: string, driverId: string) {
  const membership = await pool.query(`SELECT 1 FROM driver_profiles WHERE user_id = $1 AND fleet_owner_id = $2`, [
    driverId,
    ownerId,
  ]);
  if (membership.rowCount === 0) {
    throw Errors.forbidden('This driver does not belong to your fleet.');
  }

  const wallet = await pool.query(
    `SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`,
    [driverId]
  );
  const transactions = await pool.query(
    `SELECT wt.entry_type, wt.balance_type, wt.amount, wt.reason, wt.created_at
     FROM wallet_transactions wt
     JOIN wallets w ON w.id = wt.wallet_id
     WHERE w.owner_type = 'driver' AND w.owner_id = $1
     ORDER BY wt.created_at DESC LIMIT 20`,
    [driverId]
  );
  return {
    balance: wallet.rowCount ? parseFloat(wallet.rows[0].real_balance_cache) : 0,
    transactions: transactions.rows,
  };
}

/**
 * Fleet-wide earnings for today — summed live across every driver in the
 * fleet's own wallet ledgers, not a separately-maintained fleet balance
 * that could drift. A fleet owner doesn't hold money directly in this
 * model (drivers do, individually) — this is a real-time aggregate view,
 * matching how Porter Owner Assist's "today's earnings" is documented to
 * work for a fleet of independently-paid drivers.
 */
export async function getFleetEarningsSummary(ownerId: string) {
  const result = await pool.query(
    `SELECT COALESCE(SUM(wt.amount), 0) AS total_today, COUNT(DISTINCT dp.user_id) AS driver_count
     FROM driver_profiles dp
     LEFT JOIN wallets w ON w.owner_type = 'driver' AND w.owner_id = dp.user_id
     LEFT JOIN wallet_transactions wt ON wt.wallet_id = w.id
       AND wt.entry_type = 'credit' AND wt.reason = 'trip_earning' AND wt.created_at >= date_trunc('day', now())
     WHERE dp.fleet_owner_id = $1`,
    [ownerId]
  );
  return {
    totalToday: parseFloat(result.rows[0].total_today),
    driverCount: parseInt(result.rows[0].driver_count, 10),
  };
}

/**
 * Reassigns a vehicle from its current driver to a new one. If the vehicle
 * is currently on an in-progress trip, the reassignment is automatically
 * downgraded to "on next trip completion" rather than forcibly interrupting
 * it (PRD 13A.1 hard rule — identical principle to 9A.2's driver-suspension
 * rule: an active trip is never yanked mid-delivery by an admin action).
 */
export async function reassignVehicle(params: {
  vehicleId: string;
  newDriverId: string;
  ownerId: string; // the fleet owner making the request — must own both the vehicle and the target driver
}): Promise<{ effective: 'immediate' | 'on_next_completion' }> {
  const { vehicleId, newDriverId, ownerId } = params;

  return withTransaction(async (client) => {
    const vehicle = await client.query(
      `SELECT id, owner_id, owner_type FROM vehicles WHERE id = $1 FOR UPDATE`,
      [vehicleId]
    );
    if (vehicle.rowCount === 0 || vehicle.rows[0].owner_id !== ownerId) {
      throw Errors.notFound('Vehicle');
    }

    // Ownership scoping: a fleet owner can only reassign to a driver who
    // belongs to their OWN fleet (PRD 13A.1 RBAC note — enforced server-side
    // via the fleet_owner_id column, never just hidden in a UI dropdown).
    const belongsToFleet = await client.query(
      `SELECT 1 FROM driver_profiles WHERE user_id = $1 AND fleet_owner_id = $2`,
      [newDriverId, ownerId]
    );
    if (belongsToFleet.rowCount === 0) {
      throw Errors.forbidden('Target driver does not belong to your fleet.');
    }

    const currentAssignment = await client.query(
      `SELECT driver_id FROM driver_vehicle_assignment WHERE vehicle_id = $1 AND is_active = true FOR UPDATE`,
      [vehicleId]
    );

    // Is this vehicle currently on an in-progress trip? Checked via the
    // CURRENT driver's assigned bookings, not the vehicle directly (bookings
    // reference driver_id, not vehicle_id, per the schema — PRD 13A.1's
    // "vehicle has an active in-progress trip" is operationalized as "the
    // vehicle's current driver has an in-progress booking").
    let hasActiveTrip = false;
    if (currentAssignment.rowCount && currentAssignment.rowCount > 0) {
      const activeTrip = await client.query(
        `SELECT 1 FROM bookings WHERE driver_id = $1 AND status IN ('driver_assigned', 'in_progress') LIMIT 1`,
        [currentAssignment.rows[0].driver_id]
      );
      hasActiveTrip = (activeTrip.rowCount || 0) > 0;
    }

    if (hasActiveTrip) {
      await client.query(
        `UPDATE driver_vehicle_assignment SET scheduled_reassignment_to = $1
         WHERE vehicle_id = $2 AND is_active = true`,
        [newDriverId, vehicleId]
      );
      return { effective: 'on_next_completion' as const };
    }

    // No active trip — reassign immediately.
    if (currentAssignment.rowCount && currentAssignment.rowCount > 0) {
      await client.query(
        `UPDATE driver_vehicle_assignment SET is_active = false, effective_to = now()
         WHERE vehicle_id = $1 AND is_active = true`,
        [vehicleId]
      );
    }
    await client.query(
      `INSERT INTO driver_vehicle_assignment (driver_id, vehicle_id, is_active) VALUES ($1, $2, true)`,
      [newDriverId, vehicleId]
    );
    void sendNotification({
      eventId: deriveEventId(`fleet:vehicle_reassigned:${vehicleId}:${newDriverId}`),
      userId: newDriverId,
      category: 'trip_updates',
      channel: 'push',
      templateId: 'fleet_vehicle_assigned',
    }).catch(() => undefined);
    return { effective: 'immediate' as const };
  });
}

/**
 * Called from trip.service on trip completion (mirroring how
 * finalizeCorporateReservation and processReferralOnTripCompletion are
 * wired in) — applies any scheduled_reassignment_to that was waiting for
 * this exact trip to finish.
 */
export async function applyScheduledReassignment(driverId: string): Promise<void> {
  const pending = await pool.query(
    `SELECT vehicle_id, scheduled_reassignment_to FROM driver_vehicle_assignment
     WHERE driver_id = $1 AND is_active = true AND scheduled_reassignment_to IS NOT NULL`,
    [driverId]
  );
  if (pending.rowCount === 0) return;

  const { vehicle_id: vehicleId, scheduled_reassignment_to: newDriverId } = pending.rows[0];

  await withTransaction(async (client) => {
    await client.query(
      `UPDATE driver_vehicle_assignment SET is_active = false, effective_to = now()
       WHERE vehicle_id = $1 AND is_active = true`,
      [vehicleId]
    );
    await client.query(
      `INSERT INTO driver_vehicle_assignment (driver_id, vehicle_id, is_active) VALUES ($1, $2, true)`,
      [newDriverId, vehicleId]
    );
  });
}

export async function getFleetVehicles(ownerId: string) {
  const result = await pool.query(
    `SELECT v.id, v.category, v.plate_number, v.status,
            dva.driver_id, dva.scheduled_reassignment_to
     FROM vehicles v
     LEFT JOIN driver_vehicle_assignment dva ON dva.vehicle_id = v.id AND dva.is_active = true
     WHERE v.owner_id = $1 AND v.owner_type = 'fleet'
     ORDER BY v.plate_number`,
    [ownerId]
  );
  return result.rows;
}
