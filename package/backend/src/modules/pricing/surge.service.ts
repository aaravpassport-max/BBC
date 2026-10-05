import { pool } from '../../db/pool';

/**
 * Computes a discrete surge multiplier from live demand/supply near pickup
 * (PRD Section 5 / 20B.1). Uses rate card tiers — never a continuous slider.
 */
export async function computeSurgeMultiplier(params: {
  pickupLat: number;
  pickupLng: number;
  surgeTiers: number[];
  surgeCap: number;
}): Promise<number> {
  const { pickupLat, pickupLng, surgeCap } = params;
  const tiers = [...params.surgeTiers].sort((a, b) => a - b);
  if (tiers.length === 0) return 1.0;

  const demandResult = await pool.query(
    `SELECT count(*)::int AS c FROM bookings
     WHERE status IN ('searching', 'scheduled', 'driver_assigned')
       AND created_at > now() - interval '2 hours'
       AND ST_DWithin(
         pickup_geo,
         ST_SetSRID(ST_MakePoint($1, $2), 4326)::geography,
         3000
       )`,
    [pickupLng, pickupLat]
  );

  const supplyResult = await pool.query(
    `SELECT count(*)::int AS c FROM driver_profiles
     WHERE online_status = true
       AND kyc_status = 'approved'
       AND training_status = 'passed'
       AND suspended_at IS NULL
       AND current_lat IS NOT NULL
       AND last_ping_at > now() - interval '5 minutes'
       AND ST_DWithin(
         ST_SetSRID(ST_MakePoint(current_lng, current_lat), 4326)::geography,
         ST_SetSRID(ST_MakePoint($1, $2), 4326)::geography,
         5000
       )`,
    [pickupLng, pickupLat]
  );

  const demand = demandResult.rows[0].c as number;
  const supply = Math.max(1, supplyResult.rows[0].c as number);
  const ratio = demand / supply;

  let tierIndex = 0;
  if (ratio >= 2.5) tierIndex = Math.min(3, tiers.length - 1);
  else if (ratio >= 1.5) tierIndex = Math.min(2, tiers.length - 1);
  else if (ratio >= 0.75) tierIndex = Math.min(1, tiers.length - 1);

  const multiplier = tiers[tierIndex] ?? 1.0;
  return Math.min(multiplier, surgeCap);
}
