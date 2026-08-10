import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, createOnlineEligibleDriver, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

/** Books, dispatches, accepts, and fully completes a trip via the real API —
 * ratings can only be submitted against a completed booking, so every test
 * here needs a genuinely completed trip, not a DB shortcut. */
async function setUpCompletedTrip() {
  await pool.query(`UPDATE driver_profiles SET online_status = false`); // test isolation, see trip.test.ts's note
  const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
  const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
  const driver = await loginAsNewUser(app, driverPhone);
  const customer = await loginAsNewUser(app);

  const quoteRes = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const quoteId = quoteRes.body.quotes[0].quote_id;

  const bookingRes = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .set('Idempotency-Key', `rating-${crypto.randomUUID()}`)
    .send({ quote_id: quoteId, payment_method: 'wallet' });
  const bookingId = bookingRes.body.id;

  const dispatchRes = await request(app)
    .post(`/v1/driver/dev/trigger-dispatch/${bookingId}`)
    .set('Authorization', `Bearer ${customer.accessToken}`);
  await request(app)
    .post(`/v1/driver/jobs/${dispatchRes.body.offerId}/accept`)
    .set('Authorization', `Bearer ${driver.accessToken}`);

  const detail = await request(app)
    .get(`/v1/bookings/${bookingId}`)
    .set('Authorization', `Bearer ${customer.accessToken}`);

  await request(app)
    .post(`/v1/driver/jobs/${bookingId}/verify-pickup`)
    .set('Authorization', `Bearer ${driver.accessToken}`)
    .send({ otp: detail.body.pickup_otp });

  const stop = detail.body.stops[0];
  await request(app)
    .post(`/v1/driver/jobs/${bookingId}/stops/${stop.id}/complete`)
    .set('Authorization', `Bearer ${driver.accessToken}`)
    .send({ otp: stop.otp_code });

  return { bookingId, customerToken: customer.accessToken, driverToken: driver.accessToken, driverId, customerId: customer.userId };
}

describe('Ratings: bidirectional submission and tamper protection (PRD 17B.1)', () => {
  it('customer can rate the driver on a completed trip, and the driver rating_avg recomputes from actual rows', async () => {
    const trip = await setUpCompletedTrip();
    const res = await request(app)
      .post(`/v1/bookings/${trip.bookingId}/rate`)
      .set('Authorization', `Bearer ${trip.customerToken}`)
      .send({ stars: 5, tags: ['On time'], comment: 'Great' });
    expect(res.status).toBe(201);

    const profile = await pool.query('SELECT rating_avg, rating_count FROM driver_profiles WHERE user_id = $1', [
      trip.driverId,
    ]);
    expect(parseFloat(profile.rows[0].rating_avg)).toBe(5);
    expect(profile.rows[0].rating_count).toBe(1);
  });

  it('driver can rate the customer back on the same trip', async () => {
    const trip = await setUpCompletedTrip();
    const res = await request(app)
      .post(`/v1/bookings/${trip.bookingId}/rate`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ stars: 4, tags: ['Friendly'] });
    expect(res.status).toBe(201);

    const rating = await pool.query('SELECT rater_id, ratee_id, stars FROM ratings WHERE booking_id = $1', [
      trip.bookingId,
    ]);
    expect(rating.rows[0].rater_id).toBe(trip.driverId);
    expect(rating.rows[0].ratee_id).toBe(trip.customerId);
  });

  it('rejects a second rating from the same rater on the same trip', async () => {
    const trip = await setUpCompletedTrip();
    await request(app)
      .post(`/v1/bookings/${trip.bookingId}/rate`)
      .set('Authorization', `Bearer ${trip.customerToken}`)
      .send({ stars: 5 });
    const second = await request(app)
      .post(`/v1/bookings/${trip.bookingId}/rate`)
      .set('Authorization', `Bearer ${trip.customerToken}`)
      .send({ stars: 3 });
    expect(second.status).toBe(400);
    expect(second.body.error.details.rating).toMatch(/already rated/);
  });

  it('rejects a rating from someone who is not a party to the booking (tamper protection)', async () => {
    const trip = await setUpCompletedTrip();
    const outsider = await loginAsNewUser(app);
    const res = await request(app)
      .post(`/v1/bookings/${trip.bookingId}/rate`)
      .set('Authorization', `Bearer ${outsider.accessToken}`)
      .send({ stars: 1 });
    expect(res.status).toBe(403);
  });

  it('rejects a rating on a booking that is not yet completed', async () => {
    await pool.query(`UPDATE driver_profiles SET online_status = false`);
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    await loginAsNewUser(app, driverPhone);
    const customer = await loginAsNewUser(app);

    const quoteRes = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const bookingRes = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `notcomplete-${crypto.randomUUID()}`)
      .send({ quote_id: quoteRes.body.quotes[0].quote_id, payment_method: 'wallet' });

    const res = await request(app)
      .post(`/v1/bookings/${bookingRes.body.id}/rate`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ stars: 5 });
    expect(res.status).toBe(400);
  });

  it('a low rating with a safety tag raises a fraud flag', async () => {
    const trip = await setUpCompletedTrip();
    const res = await request(app)
      .post(`/v1/bookings/${trip.bookingId}/rate`)
      .set('Authorization', `Bearer ${trip.customerToken}`)
      .send({ stars: 1, tags: ['Unsafe driving'] });
    expect(res.status).toBe(201);
    expect(res.body.safetyFlagRaised).toBe(true);

    const flag = await pool.query(
      `SELECT signal_types, severity FROM fraud_flags WHERE subject_id = $1 ORDER BY created_at DESC LIMIT 1`,
      [trip.driverId]
    );
    expect(flag.rows[0].signal_types).toContain('safety_low_rating');
    expect(flag.rows[0].severity).toBe('high');
  });

  it('a high rating never raises a fraud flag even with an unrelated tag', async () => {
    const trip = await setUpCompletedTrip();
    const res = await request(app)
      .post(`/v1/bookings/${trip.bookingId}/rate`)
      .set('Authorization', `Bearer ${trip.customerToken}`)
      .send({ stars: 5, tags: ['On time'] });
    expect(res.body.safetyFlagRaised).toBe(false);
  });
});
