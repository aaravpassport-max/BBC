import { pool } from '../../db/pool';

export interface LiveDriverPin {
  driver_id: string;
  phone: string;
  name: string | null;
  lat: number;
  lng: number;
  online_status: boolean;
  on_trip: boolean;
  last_ping_at: string | null;
  active_booking_id: string | null;
}

export async function listLiveDrivers(): Promise<LiveDriverPin[]> {
  const result = await pool.query(
    `SELECT dp.user_id AS driver_id, u.phone, u.name, dp.current_lat AS lat, dp.current_lng AS lng,
            dp.online_status, dp.last_ping_at,
            EXISTS (
              SELECT 1 FROM bookings b
              WHERE b.driver_id = dp.user_id AND b.status IN ('driver_assigned', 'in_progress')
            ) AS on_trip,
            (
              SELECT b.id FROM bookings b
              WHERE b.driver_id = dp.user_id AND b.status IN ('driver_assigned', 'in_progress')
              LIMIT 1
            ) AS active_booking_id
     FROM driver_profiles dp
     JOIN users u ON u.id = dp.user_id
     WHERE dp.current_lat IS NOT NULL AND dp.current_lng IS NOT NULL
       AND dp.last_ping_at > now() - interval '30 minutes'
     ORDER BY dp.last_ping_at DESC NULLS LAST
     LIMIT 500`
  );

  return result.rows.map((row) => ({
    driver_id: row.driver_id,
    phone: row.phone,
    name: row.name,
    lat: parseFloat(row.lat),
    lng: parseFloat(row.lng),
    online_status: row.online_status,
    on_trip: row.on_trip,
    last_ping_at: row.last_ping_at,
    active_booking_id: row.active_booking_id,
  }));
}
