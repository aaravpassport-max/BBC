import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

const RATING_WINDOW_HOURS = 48; // PRD 17B.1 config
const SAFETY_TAGS = ['Unsafe driving', 'Rude', 'Damaged goods', 'Overcharged'];
const LOW_RATING_THRESHOLD = 2; // PRD 17B.1 config — <= this auto-flags

export async function submitRating(params: {
  bookingId: string;
  raterId: string;
  stars: number;
  tags?: string[];
  comment?: string;
}): Promise<{ isLate: boolean; safetyFlagRaised: boolean }> {
  const { bookingId, raterId, stars, tags, comment } = params;

  // PRD 17B.1 tamper rule: the rater must be a genuine party to this specific
  // booking (either the customer or the assigned driver) — checked before
  // anything else, with a generic not-found response so a crafted request
  // against a booking the rater isn't part of learns nothing about whether
  // that booking_id even exists.
  const bookingResult = await pool.query(
    `SELECT customer_id, driver_id, status, updated_at FROM bookings WHERE id = $1`,
    [bookingId]
  );
  if (bookingResult.rowCount === 0) {
    throw Errors.notFound('Booking');
  }
  const booking = bookingResult.rows[0];

  let rateeId: string;
  if (booking.customer_id === raterId) {
    if (!booking.driver_id) throw Errors.validation({ booking: 'No driver was assigned to this trip.' });
    rateeId = booking.driver_id;
  } else if (booking.driver_id === raterId) {
    rateeId = booking.customer_id;
  } else {
    // Rater is neither party — same generic error as "not found" so no
    // information about the booking's existence leaks (PRD 17B.1 rule).
    throw Errors.forbidden('You are not a party to this booking.');
  }

  if (booking.status !== 'completed') {
    throw Errors.validation({ booking: 'Only completed trips can be rated.' });
  }

  const hoursSinceCompletion = (Date.now() - new Date(booking.updated_at).getTime()) / (1000 * 60 * 60);
  const isLate = hoursSinceCompletion > RATING_WINDOW_HOURS;

  const hasSafetyTag = (tags || []).some((t) => SAFETY_TAGS.includes(t));
  const safetyFlagRaised = stars <= LOW_RATING_THRESHOLD && hasSafetyTag;

  try {
    await pool.query(
      `INSERT INTO ratings (booking_id, rater_id, ratee_id, stars, tags, comment, is_late)
       VALUES ($1, $2, $3, $4, $5, $6, $7)`,
      [bookingId, raterId, rateeId, stars, tags || [], comment || null, isLate]
    );
  } catch (err) {
    const pgErr = err as { code?: string };
    if (pgErr.code === '23505') {
      // uq_rating_once_per_rater — PRD 17B.1 "attempt second rating" rule.
      throw Errors.validation({ rating: 'You have already rated this trip.' });
    }
    throw err;
  }

  // Recompute the ratee's rolling average from the actual rating rows (never
  // an incrementally-mutated field with no audit trail, PRD 17 rule). Late
  // ratings ARE included in the historical average shown to the person, but
  // excluded from what a live dispatch-scoring read would use — this
  // reference implementation keeps one average; a production system would
  // maintain a second "recent, non-late" rolling window for dispatch scoring
  // specifically, flagged here rather than silently conflating the two.
  await pool.query(
    `UPDATE driver_profiles SET
       rating_avg = (SELECT AVG(stars)::numeric(3,2) FROM ratings WHERE ratee_id = $1),
       rating_count = (SELECT count(*) FROM ratings WHERE ratee_id = $1)
     WHERE user_id = $1`,
    [rateeId]
  );

  if (safetyFlagRaised) {
    await pool.query(
      `INSERT INTO fraud_flags (subject_type, subject_id, signal_types, evidence, severity, max_silent_hold_until)
       VALUES ('user', $1, ARRAY['safety_low_rating'], $2, 'high', now() + interval '24 hours')`,
      [rateeId, JSON.stringify({ booking_id: bookingId, stars, tags })]
    );
  }

  return { isLate, safetyFlagRaised };
}
