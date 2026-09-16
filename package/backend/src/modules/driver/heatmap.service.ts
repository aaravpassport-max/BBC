import { pool } from '../../db/pool';

export interface DemandHeatCell {
  lat: number;
  lng: number;
  demand_score: number;
  label: string;
  booking_count: number;
  surge_zone: boolean;
}

/**
 * Aggregates recent booking demand into a coarse grid for the driver heatmap.
 * Combines active searching bookings with surge_zone centroids for context.
 */
export async function getDemandHeatmap(params: {
  lat?: number;
  lng?: number;
  radiusKm?: number;
}): Promise<{ cells: DemandHeatCell[]; updated_at: string }> {
  const radiusKm = params.radiusKm ?? 15;

  // Grid bookings by ~0.02° (~2 km) cells from pickup points in the last 3 hours.
  const demandResult = await pool.query(
    `SELECT
       ROUND(ST_Y(pickup_geo::geometry)::numeric, 2) AS lat,
       ROUND(ST_X(pickup_geo::geometry)::numeric, 2) AS lng,
       count(*)::int AS booking_count
     FROM bookings
     WHERE status IN ('searching', 'scheduled', 'driver_assigned')
       AND created_at > now() - interval '3 hours'
       AND ($1::float IS NULL OR ST_DWithin(
         pickup_geo,
         ST_SetSRID(ST_MakePoint($2, $1), 4326)::geography,
         $3 * 1000
       ))
     GROUP BY 1, 2
     HAVING count(*) >= 1
     ORDER BY booking_count DESC
     LIMIT 40`,
    [params.lat ?? null, params.lng ?? null, radiusKm]
  );

  const surgeResult = await pool.query(
    `SELECT
       ST_Y(ST_Centroid(boundary::geometry)) AS lat,
       ST_X(ST_Centroid(boundary::geometry)) AS lng,
       name
     FROM zones
     WHERE zone_type = 'surge_zone'
     LIMIT 20`
  );

  const maxCount = Math.max(1, ...demandResult.rows.map((r) => r.booking_count as number));

  const cells: DemandHeatCell[] = demandResult.rows.map((row) => {
    const count = row.booking_count as number;
    const score = Math.min(1, count / maxCount);
    return {
      lat: parseFloat(row.lat),
      lng: parseFloat(row.lng),
      demand_score: Math.round(score * 100) / 100,
      label: count >= 3 ? 'High demand' : count >= 2 ? 'Moderate demand' : 'Demand',
      booking_count: count,
      surge_zone: false,
    };
  });

  for (const zone of surgeResult.rows) {
    const lat = parseFloat(zone.lat);
    const lng = parseFloat(zone.lng);
    const existing = cells.find((c) => Math.abs(c.lat - lat) < 0.03 && Math.abs(c.lng - lng) < 0.03);
    if (existing) {
      existing.surge_zone = true;
      existing.label = `${existing.label} · Surge zone`;
    } else {
      cells.push({
        lat,
        lng,
        demand_score: 0.35,
        label: `${zone.name} (surge zone)`,
        booking_count: 0,
        surge_zone: true,
      });
    }
  }

  return { cells, updated_at: new Date().toISOString() };
}
