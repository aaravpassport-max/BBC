import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { createOnlineEligibleDriver, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function createSearchingBooking() {
  await pool.query(`UPDATE driver_profiles SET online_status = false`);
  const customer = await loginAsNewUser(app);
  const quote = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const booking = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .set('Idempotency-Key', `loc-test-${crypto.randomUUID()}`)
    .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
  return { bookingId: booking.body.id, customerToken: customer.accessToken, customerId: customer.userId };
}

describe('Live tracking: driver location during an active trip (PRD Section 8)', () => {
  it('returns null (not an error) when no driver is assigned yet', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const res = await request(app)
      .get(`/v1/bookings/${bookingId}/driver-location`)
      .set('Authorization', `Bearer ${customerToken}`);
    expect(res.status).toBe(200);
    expect(res.body).toBeNull();
  });

  it('a different customer cannot see this location — 404, not just empty', async () => {
    const { bookingId } = await createSearchingBooking();
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .get(`/v1/bookings/${bookingId}/driver-location`)
      .set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(404);
  });

  it('returns the real, current driver position once a driver is assigned and has pinged', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await pool.query(`UPDATE bookings SET status = 'driver_assigned', driver_id = $1 WHERE id = $2`, [
      driverId,
      bookingId,
    ]);
    await pool.query(`UPDATE driver_profiles SET current_lat = $1, current_lng = $2 WHERE user_id = $3`, [
      12.9611,
      77.6387,
      driverId,
    ]);

    const res = await request(app)
      .get(`/v1/bookings/${bookingId}/driver-location`)
      .set('Authorization', `Bearer ${customerToken}`);
    expect(res.status).toBe(200);
    expect(res.body.lat).toBeCloseTo(12.9611, 4);
    expect(res.body.lng).toBeCloseTo(77.6387, 4);
  });

  it('stops exposing location once the trip is completed', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await pool.query(`UPDATE driver_profiles SET current_lat = 12.9, current_lng = 77.6 WHERE user_id = $1`, [
      driverId,
    ]);
    await pool.query(`UPDATE bookings SET status = 'completed', driver_id = $1 WHERE id = $2`, [
      driverId,
      bookingId,
    ]);

    const res = await request(app)
      .get(`/v1/bookings/${bookingId}/driver-location`)
      .set('Authorization', `Bearer ${customerToken}`);
    expect(res.status).toBe(200);
    expect(res.body).toBeNull();
  });
});
