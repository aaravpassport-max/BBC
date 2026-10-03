import { pool } from '../../db/pool';

/** Daily trip-count mission — computed from real completed trips today. */
const DAILY_TRIP_TARGET = 5;
const DAILY_TRIP_BONUS = 200;

export async function getActiveDriverIncentives(driverId: string) {
  const todayTrips = await pool.query(
    `SELECT count(*)::int AS completed
     FROM bookings
     WHERE driver_id = $1
       AND status = 'completed'
       AND created_at >= date_trunc('day', now() AT TIME ZONE 'Asia/Kolkata') AT TIME ZONE 'Asia/Kolkata'`,
    [driverId]
  );
  const completed = todayTrips.rows[0]?.completed ?? 0;
  const remaining = Math.max(0, DAILY_TRIP_TARGET - completed);

  return [
    {
      id: 'daily_trips_5',
      title: `Complete ${DAILY_TRIP_TARGET} trips today`,
      description: `Earn a ₹${DAILY_TRIP_BONUS} bonus when you finish ${DAILY_TRIP_TARGET} deliveries before midnight.`,
      bonus_amount: DAILY_TRIP_BONUS,
      target: DAILY_TRIP_TARGET,
      progress: completed,
      remaining,
      completed: completed >= DAILY_TRIP_TARGET,
      period: 'today',
    },
  ];
}
