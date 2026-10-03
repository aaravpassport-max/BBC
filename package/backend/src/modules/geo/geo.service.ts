import { pool } from '../../db/pool';

export async function checkServiceability(lat: number, lng: number) {
  const zoneResult = await pool.query(
    `SELECT z.id AS zone_id, z.name AS zone_name, c.id AS city_id, c.name AS city_name
     FROM zones z
     JOIN cities c ON c.id = z.city_id
     WHERE z.zone_type = 'service_area'
       AND ST_Covers(z.boundary::geometry, ST_SetSRID(ST_MakePoint($1, $2), 4326))
     LIMIT 1`,
    [lng, lat]
  );

  if (zoneResult.rowCount === 0) {
    return { serviceable: false as const };
  }

  const row = zoneResult.rows[0];
  return {
    serviceable: true as const,
    zone_id: row.zone_id,
    zone_name: row.zone_name,
    city_id: row.city_id,
    city_name: row.city_name,
  };
}
