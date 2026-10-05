import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, createOnlineEligibleDriver, samplePickupDrop } from '../../../test-utils/seed';
import { deriveEventId } from '../notifications.service';

const app = createApp();

// sendNotification is deliberately fire-and-forget (`void sendNotification(...)`)
// in every call site — the same "never block the user-facing response" rule
// established throughout this codebase. That means the HTTP response can
// return before the notification's own INSERT has committed, so a test
// checking notification_log immediately afterward has a genuine race
// against the product code, not a bug in it. A short wait here reflects
// that reality without weakening the fire-and-forget guarantee itself.
const waitForAsyncNotification = () => new Promise((r) => setTimeout(r, 150));

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

async function bookAndDispatch() {
  await pool.query(`UPDATE driver_profiles SET online_status = false`);
  const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
  const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
  const driverLogin = await loginAsNewUser(app, driverPhone);

  const customer = await loginAsNewUser(app);
  const quote = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const bookingRes = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .set('Idempotency-Key', `notif-${crypto.randomUUID()}`)
    .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
  const bookingId = bookingRes.body.id;

  const dispatchRes = await request(app)
    .post(`/v1/driver/dev/trigger-dispatch/${bookingId}`)
    .set('Authorization', `Bearer ${customer.accessToken}`);

  return { bookingId, offerId: dispatchRes.body.offerId, driverId, driverToken: driverLogin.accessToken, customer };
}

describe('Notifications: wired to real events (previously the whole system existed but was never called)', () => {
  it('a new dispatch offer notifies the driver', async () => {
    const { offerId, driverId } = await bookAndDispatch();
    await waitForAsyncNotification();
    const row = await pool.query(
      `SELECT * FROM notification_log WHERE event_id = $1 AND user_id = $2 AND template_id = 'new_job_offer'`,
      [deriveEventId(`${offerId}:new_offer`), driverId]
    );
    expect(row.rowCount).toBe(1);
  });

  it('the driver accepting a job notifies the customer', async () => {
    const { bookingId, offerId, driverToken, customer } = await bookAndDispatch();
    await request(app).post(`/v1/driver/jobs/${offerId}/accept`).set('Authorization', `Bearer ${driverToken}`);

    await waitForAsyncNotification();
    const row = await pool.query(
      `SELECT * FROM notification_log WHERE event_id = $1 AND user_id = $2 AND template_id = 'driver_on_the_way'`,
      [deriveEventId(`${bookingId}:driver_assigned`), customer.userId]
    );
    expect(row.rowCount).toBe(1);
  });

  it('completing the final stop notifies the customer the trip is done', async () => {
    const { bookingId, offerId, driverToken, customer } = await bookAndDispatch();
    await request(app).post(`/v1/driver/jobs/${offerId}/accept`).set('Authorization', `Bearer ${driverToken}`);

    const detail = await request(app).get(`/v1/bookings/${bookingId}`).set('Authorization', `Bearer ${customer.accessToken}`);
    await request(app)
      .post(`/v1/driver/jobs/${bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${driverToken}`)
      .send({ otp: detail.body.pickup_otp });
    for (const stop of detail.body.stops) {
      await request(app)
        .post(`/v1/driver/jobs/${bookingId}/stops/${stop.id}/complete`)
        .set('Authorization', `Bearer ${driverToken}`)
        .send({ otp: stop.otp_code });
    }

    await waitForAsyncNotification();
    const row = await pool.query(
      `SELECT * FROM notification_log WHERE event_id = $1 AND user_id = $2 AND template_id = 'trip_completed'`,
      [deriveEventId(`${bookingId}:completed`), customer.userId]
    );
    expect(row.rowCount).toBe(1);
  });

  it('the customer cancelling after a driver is assigned notifies that driver', async () => {
    const { bookingId, offerId, driverToken, driverId, customer } = await bookAndDispatch();
    await request(app).post(`/v1/driver/jobs/${offerId}/accept`).set('Authorization', `Bearer ${driverToken}`);

    await request(app)
      .post(`/v1/bookings/${bookingId}/cancel`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ reason_code: 'BOOKED_BY_MISTAKE' });

    await waitForAsyncNotification();
    const row = await pool.query(
      `SELECT * FROM notification_log WHERE event_id = $1 AND user_id = $2 AND template_id = 'trip_cancelled_by_customer'`,
      [deriveEventId(`${bookingId}:cancelled`), driverId]
    );
    expect(row.rowCount).toBe(1);
  });

  it('cancelling BEFORE a driver is assigned does not fire a driver-cancellation notification (there is no driver to notify)', async () => {
    const customer = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const bookingRes = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `notif-nodrv-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });

    const cancel = await request(app)
      .post(`/v1/bookings/${bookingRes.body.id}/cancel`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ reason_code: 'BOOKED_BY_MISTAKE' });
    expect(cancel.status).toBe(200);

    await waitForAsyncNotification();
    const row = await pool.query(
      `SELECT * FROM notification_log WHERE event_id = $1 AND template_id = 'trip_cancelled_by_customer'`,
      [deriveEventId(`${bookingRes.body.id}:cancelled`)]
    );
    expect(row.rowCount).toBe(0);
  });
});
