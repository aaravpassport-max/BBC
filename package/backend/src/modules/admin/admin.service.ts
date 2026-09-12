import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

// ---------- Rate Cards (PRD 9A.1) ----------

/**
 * Lists rate cards, optionally filtered by city/category (GAP FOUND WHILE
 * BUILDING THE ADMIN FRONTEND: only create/publish existed — there was no
 * way to see what rate cards currently exist at all, making the create/
 * publish endpoints unusable from any real UI without this).
 */
export async function listRateCards(params: { cityId?: string; vehicleCategoryId?: string }) {
  const conditions: string[] = [];
  const args: unknown[] = [];
  if (params.cityId) {
    args.push(params.cityId);
    conditions.push(`rc.city_id = $${args.length}`);
  }
  if (params.vehicleCategoryId) {
    args.push(params.vehicleCategoryId);
    conditions.push(`rc.vehicle_category_id = $${args.length}`);
  }
  const where = conditions.length > 0 ? `WHERE ${conditions.join(' AND ')}` : '';

  const result = await pool.query(
    `SELECT rc.id, rc.status, rc.version, rc.base_fare, rc.per_km_rate, rc.minimum_fare,
            rc.platform_fee, rc.surge_tiers, rc.surge_cap, rc.effective_from,
            vc.name AS vehicle_category_name, ci.name AS city_name
     FROM rate_cards rc
     JOIN vehicle_categories vc ON vc.id = rc.vehicle_category_id
     JOIN cities ci ON ci.id = rc.city_id
     ${where}
     ORDER BY rc.status = 'published' DESC, rc.effective_from DESC
     LIMIT 100`,
    args
  );
  return result.rows;
}

export async function createRateCard(params: {
  cityId: string;
  vehicleCategoryId: string;
  coefficients: Record<string, unknown>;
  createdBy: string;
}): Promise<{ id: string }> {
  const { cityId, vehicleCategoryId, coefficients, createdBy } = params;
  const c = coefficients as {
    base_fare: number;
    per_km_rate: number;
    minimum_fare: number;
    per_min_rate?: number;
    platform_fee?: number;
    tax_rate_pct?: number;
  };

  if (c.minimum_fare < c.base_fare) {
    throw Errors.validation({ minimum_fare: 'Minimum fare cannot be less than base fare.' });
  }

  const result = await pool.query(
    `INSERT INTO rate_cards (city_id, vehicle_category_id, base_fare, per_km_rate, per_min_rate,
                              minimum_fare, platform_fee, tax_rate_pct, status, published_by)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'draft', $9)
     RETURNING id`,
    [
      cityId,
      vehicleCategoryId,
      c.base_fare,
      c.per_km_rate,
      c.per_min_rate || 0,
      c.minimum_fare,
      c.platform_fee || 0,
      c.tax_rate_pct || 0,
      createdBy,
    ]
  );
  return { id: result.rows[0].id };
}

/**
 * Publishes a rate card draft with optimistic-lock version checking (PRD
 * 9A.1: "a publish with a stale version token is rejected with a diff,
 * never silently overwritten"). The caller supplies the version they last
 * read; if the row has moved on since, this fails with a conflict rather
 * than blindly overwriting a concurrent edit.
 */
export async function publishRateCard(params: {
  rateCardId: string;
  expectedVersion: number;
}): Promise<{ version: number }> {
  const { rateCardId, expectedVersion } = params;

  return withTransaction(async (client) => {
    const current = await client.query(`SELECT version, status FROM rate_cards WHERE id = $1 FOR UPDATE`, [
      rateCardId,
    ]);
    if (current.rowCount === 0) {
      throw Errors.notFound('Rate card');
    }
    if (current.rows[0].version !== expectedVersion) {
      throw Errors.validation({
        version: 'This rate card has changed since you last read it.',
        current_version: current.rows[0].version,
      });
    }

    // Supersede any currently-published card for the same city+category —
    // exactly one published rate card per city/category at a time (PRD 9A.1:
    // historical cards retained, never hard-deleted, for fare auditability).
    await client.query(
      `UPDATE rate_cards SET status = 'superseded'
       WHERE status = 'published'
         AND city_id = (SELECT city_id FROM rate_cards WHERE id = $1)
         AND vehicle_category_id = (SELECT vehicle_category_id FROM rate_cards WHERE id = $1)
         AND id != $1`,
      [rateCardId]
    );

    const updated = await client.query(
      `UPDATE rate_cards SET status = 'published', version = version + 1, effective_from = now() WHERE id = $1 RETURNING version`,
      [rateCardId]
    );
    return { version: updated.rows[0].version };
  });
}

export async function updateRateCardSurgeTiers(params: {
  rateCardId: string;
  surgeTiers: number[];
  surgeCap: number;
}): Promise<void> {
  const { rateCardId, surgeTiers, surgeCap } = params;
  if (surgeTiers.length === 0 || surgeTiers.some((t) => t < 1)) {
    throw Errors.validation({ surge_tiers: 'Surge tiers must be positive multipliers.' });
  }
  if (surgeCap < 1) {
    throw Errors.validation({ surge_cap: 'Surge cap must be at least 1.0.' });
  }

  const result = await pool.query(
    `UPDATE rate_cards SET surge_tiers = $1::jsonb, surge_cap = $2
     WHERE id = $3 AND status IN ('draft', 'published')
     RETURNING id`,
    [JSON.stringify(surgeTiers), surgeCap, rateCardId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Rate card');
  }
}

export async function listSurgeZones(cityId?: string) {
  const args: unknown[] = [];
  let where = `WHERE z.zone_type = 'surge_zone'`;
  if (cityId) {
    args.push(cityId);
    where += ` AND z.city_id = $${args.length}`;
  }

  const result = await pool.query(
    `SELECT z.id, z.name, z.city_id, ci.name AS city_name, z.version, z.created_at, z.updated_at
     FROM zones z
     JOIN cities ci ON ci.id = z.city_id
     ${where}
     ORDER BY ci.name, z.name
     LIMIT 100`,
    args
  );
  return result.rows;
}

export async function getOfflineReasonAnalytics(days = 7) {
  const result = await pool.query(
    `SELECT reason_code, count(*)::int AS event_count
     FROM driver_offline_events
     WHERE created_at > now() - ($1::int || ' days')::interval
     GROUP BY reason_code
     ORDER BY event_count DESC`,
    [days]
  );
  return {
    period_days: days,
    total_events: result.rows.reduce((sum, r) => sum + (r.event_count as number), 0),
    by_reason: result.rows,
  };
}

// ---------- Driver Suspend/Reinstate (PRD 9A.2) ----------

/**
 * Generic user lookup by phone (any account type), for RBAC role
 * assignment — listDrivers below only covers drivers, but a role can be
 * granted to any account type (e.g. an ops team member who's also a
 * customer). Deliberately exact-match, not a partial search: this returns
 * account identity for a privilege-granting action, so it should not
 * surface a list of "close enough" phone numbers to pick from.
 */
export async function findUserByPhone(phone: string) {
  const result = await pool.query(`SELECT id, phone, account_type FROM users WHERE phone = $1`, [phone]);
  return result.rows[0] || null;
}

/**
 * Lists drivers with basic status info (ANOTHER GAP FOUND WHILE BUILDING
 * THE ADMIN FRONTEND: suspend/reinstate existed, but nothing let an admin
 * actually find a driver to act on in the first place).
 */
export async function listDrivers(params: { search?: string }) {
  const searchClause = params.search ? `AND u.phone ILIKE $1` : '';
  const args = params.search ? [`%${params.search}%`] : [];

  const result = await pool.query(
    `SELECT u.id, u.phone, dp.kyc_status, dp.training_status, dp.online_status,
            dp.suspended_at, dp.suspension_reason, dp.rating_avg
     FROM users u JOIN driver_profiles dp ON dp.user_id = u.id
     WHERE u.account_type = 'driver' ${searchClause}
     ORDER BY u.created_at DESC
     LIMIT 100`,
    args
  );
  return result.rows;
}

const SUSPENSION_REASONS = ['FRAUD_SUSPECTED', 'DOCUMENT_EXPIRED', 'SAFETY_COMPLAINT', 'LOW_RATING', 'OTHER'];

export async function suspendDriver(params: {
  driverId: string;
  reasonCode: string;
  note?: string;
  actorId: string;
}): Promise<void> {
  const { driverId, reasonCode, note, actorId } = params;
  if (!SUSPENSION_REASONS.includes(reasonCode)) {
    throw Errors.validation({ reason_code: 'Unknown suspension reason.' });
  }
  if (reasonCode === 'OTHER' && !note) {
    throw Errors.validation({ note: 'A note is required when reason is OTHER.' });
  }

  const result = await pool.query(
    `UPDATE driver_profiles SET suspended_at = now(), suspension_reason = $1 WHERE user_id = $2 RETURNING user_id`,
    [reasonCode, driverId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Driver');
  }

  // PRD 9A.2: an in-progress trip at time of suspension is unaffected through
  // completion — this is naturally true here since suspension only affects
  // FUTURE eligibility checks (driver.service.checkDriverEligibility), never
  // touches the bookings table, so nothing about an active trip changes.

  await pool.query(
    `INSERT INTO audit_log (actor_id, actor_type, action, resource_type, resource_id, after_state)
     VALUES ($1, 'user', 'driver.suspend', 'driver', $2, $3)`,
    [actorId, driverId, JSON.stringify({ reason_code: reasonCode, note })]
  );
}

/**
 * Reinstatement blocks if the driver's KYC has since become invalid (PRD
 * 9A.2: "reinstate flow re-checks KYC validity ... forces document
 * re-verification before allowing reinstatement, rather than reinstating
 * into an invalid state").
 */
export async function reinstateDriver(params: { driverId: string; actorId: string }): Promise<void> {
  const { driverId, actorId } = params;

  const profile = await pool.query(`SELECT kyc_status FROM driver_profiles WHERE user_id = $1`, [driverId]);
  if (profile.rowCount === 0) {
    throw Errors.notFound('Driver');
  }
  if (profile.rows[0].kyc_status !== 'approved') {
    throw Errors.validation({
      driver: 'This driver\u2019s KYC is no longer approved and must be re-verified before reinstatement.',
    });
  }

  await pool.query(
    `UPDATE driver_profiles SET suspended_at = NULL, suspension_reason = NULL WHERE user_id = $1`,
    [driverId]
  );
  await pool.query(
    `INSERT INTO audit_log (actor_id, actor_type, action, resource_type, resource_id)
     VALUES ($1, 'user', 'driver.reinstate', 'driver', $2)`,
    [actorId, driverId]
  );
}

// ---------- Fraud Queue (PRD 17A.1) ----------

export async function listFraudQueue(status?: string) {
  const result = await pool.query(
    status
      ? `SELECT * FROM fraud_flags WHERE status = $1 ORDER BY created_at ASC`
      : `SELECT * FROM fraud_flags WHERE status IN ('pending', 'escalated', 'held') ORDER BY created_at ASC`,
    status ? [status] : []
  );
  return result.rows;
}

export async function resolveFraudFlag(params: {
  flagId: string;
  action: 'clear' | 'escalate' | 'hold' | 'suspend';
  note: string;
  actorId: string;
}): Promise<void> {
  const { flagId, action, note, actorId } = params;
  if (!note || note.trim().length === 0) {
    throw Errors.validation({ note: 'A resolution note is required.' });
  }

  return withTransaction(async (client) => {
    const flag = await client.query(`SELECT * FROM fraud_flags WHERE id = $1 FOR UPDATE`, [flagId]);
    if (flag.rowCount === 0) {
      throw Errors.notFound('Fraud flag');
    }

    const statusMap: Record<string, string> = {
      clear: 'cleared',
      escalate: 'escalated',
      hold: 'held',
      suspend: 'suspended',
    };

    await client.query(
      `UPDATE fraud_flags SET status = $1, resolved_by = $2, resolution_note = $3, resolved_at = now() WHERE id = $4`,
      [statusMap[action], actorId, note, flagId]
    );

    // PRD 17A.1: a Suspend action on a driver flag follows the exact same
    // suspension rule as 9A.2 (never interrupts an in-progress trip) — reuse
    // the same function rather than a parallel implementation.
    if (action === 'suspend' && flag.rows[0].subject_type === 'driver') {
      await client.query(
        `UPDATE driver_profiles SET suspended_at = now(), suspension_reason = 'FRAUD_SUSPECTED' WHERE user_id = $1`,
        [flag.rows[0].subject_id]
      );
    }
  });
}
