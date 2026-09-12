import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { createOnlineEligibleDriver, samplePickupDrop } from '../../../test-utils/seed';
import { deriveEventId } from '../../notifications/notifications.service';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function bookAndAssignDriver() {
  await pool.query(`UPDATE driver_profiles SET online_status = false`);
  const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
  const customer = await loginAsNewUser(app);
  const quote = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const bookingRes = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .set('Idempotency-Key', `chat-${crypto.randomUUID()}`)
    .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
  await pool.query(`UPDATE bookings SET status = 'driver_assigned', driver_id = $1 WHERE id = $2`, [
    driverId,
    bookingRes.body.id,
  ]);
  const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
  const driverLogin = await loginAsNewUser(app, driverPhone);
  return { bookingId: bookingRes.body.id, customer, driverId, driverToken: driverLogin.accessToken };
}

describe('Trip chat: sending (P0 gap analysis item — direct in-app contact between customer and driver)', () => {
  it('the customer can send a message, and it is attributed to them correctly', async () => {
    const { bookingId, customer } = await bookAndAssignDriver();
    const res = await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: "I'm at the loading dock, gate 3." });
    expect(res.status).toBe(201);
    expect(res.body.senderRole).toBe('customer');
  });

  it('the driver can send a message on the same booking', async () => {
    const { bookingId, driverToken } = await bookAndAssignDriver();
    const res = await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${driverToken}`)
      .send({ body: 'On my way, 5 minutes out.' });
    expect(res.status).toBe(201);
    expect(res.body.senderRole).toBe('driver');
  });

  it('a third party — not the customer or the assigned driver — cannot send a message', async () => {
    const { bookingId } = await bookAndAssignDriver();
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ body: 'Trying to eavesdrop.' });
    expect(res.status).toBe(403);
  });

  it('rejects an empty message', async () => {
    const { bookingId, customer } = await bookAndAssignDriver();
    const res = await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: '   ' });
    expect(res.status).toBe(400);
  });

  it('rejects a message over the length limit', async () => {
    const { bookingId, customer } = await bookAndAssignDriver();
    const res = await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: 'x'.repeat(1001) });
    expect(res.status).toBe(400);
  });

  it('sending a message notifies the OTHER participant, not the sender', async () => {
    const { bookingId, customer, driverId } = await bookAndAssignDriver();
    const send = await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: 'Please call when you arrive.' });
    await new Promise((r) => setTimeout(r, 150));

    const driverNotified = await pool.query(
      `SELECT * FROM notification_log WHERE event_id = $1 AND user_id = $2`,
      [deriveEventId(`${send.body.id}:new_message`), driverId]
    );
    expect(driverNotified.rowCount).toBe(1);

    const customerNotSelfNotified = await pool.query(
      `SELECT * FROM notification_log WHERE event_id = $1 AND user_id = $2`,
      [deriveEventId(`${send.body.id}:new_message`), customer.userId]
    );
    expect(customerNotSelfNotified.rowCount).toBe(0);
  });
});

describe('Trip chat: reading the thread', () => {
  it('both participants see the full thread, in order, from both of them', async () => {
    const { bookingId, customer, driverToken } = await bookAndAssignDriver();
    await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: 'First message.' });
    await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${driverToken}`)
      .send({ body: 'Second message.' });

    const asCustomer = await request(app)
      .get(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`);
    const asDriver = await request(app)
      .get(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${driverToken}`);

    expect(asCustomer.body.length).toBe(2);
    expect(asDriver.body.length).toBe(2);
    expect(asCustomer.body[0].body).toBe('First message.');
    expect(asCustomer.body[1].body).toBe('Second message.');
    expect(asCustomer.body[0].sender_role).toBe('customer');
    expect(asCustomer.body[1].sender_role).toBe('driver');
  });

  it('a third party cannot read the thread — 403, not an empty list', async () => {
    const { bookingId, customer } = await bookAndAssignDriver();
    await request(app)
      .post(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: 'Private conversation.' });

    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get(`/v1/bookings/${bookingId}/messages`).set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('a customer on a DIFFERENT booking cannot read this one\u2019s thread', async () => {
    const { bookingId } = await bookAndAssignDriver();
    const otherBooking = await bookAndAssignDriver();
    const res = await request(app)
      .get(`/v1/bookings/${bookingId}/messages`)
      .set('Authorization', `Bearer ${otherBooking.customer.accessToken}`);
    expect(res.status).toBe(403);
  });
});
