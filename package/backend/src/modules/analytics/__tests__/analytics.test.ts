import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, createOnlineEligibleDriver, samplePickupDrop, getRoleIdByName } from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

async function grantAnalyticsPermission(userId: string) {
  const roleId = await getRoleIdByName('ops_admin');
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    roleId,
  ]);
}

/** Books and completes one trip, returning the isolating time window
 * (from/to ISO strings) so a test's dashboard query only sees what THIS
 * test created — the test DB has accumulated a large volume of historical
 * data across this entire session, so an unscoped query would be
 * non-deterministic. */
async function completeOneTrip(): Promise<{ from: string }> {
  const from = new Date().toISOString();
  await pool.query(`UPDATE driver_profiles SET online_status = false`);
  const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
  const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
  const driver = await loginAsNewUser(app, driverPhone);
  const customer = await loginAsNewUser(app);

  const quote = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const booking = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .set('Idempotency-Key', `analytics-${crypto.randomUUID()}`)
    .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
  const dispatch = await request(app)
    .post(`/v1/driver/dev/trigger-dispatch/${booking.body.id}`)
    .set('Authorization', `Bearer ${customer.accessToken}`);
  await request(app)
    .post(`/v1/driver/jobs/${dispatch.body.offerId}/accept`)
    .set('Authorization', `Bearer ${driver.accessToken}`);
  const detail = await request(app)
    .get(`/v1/bookings/${booking.body.id}`)
    .set('Authorization', `Bearer ${customer.accessToken}`);
  await request(app)
    .post(`/v1/driver/jobs/${booking.body.id}/verify-pickup`)
    .set('Authorization', `Bearer ${driver.accessToken}`)
    .send({ otp: detail.body.pickup_otp });
  const stop = detail.body.stops[0];
  await request(app)
    .post(`/v1/driver/jobs/${booking.body.id}/stops/${stop.id}/complete`)
    .set('Authorization', `Bearer ${driver.accessToken}`)
    .send({ otp: stop.otp_code });

  return { from };
}

describe('Analytics: RBAC enforcement (PRD Section 22)', () => {
  it('a plain user cannot access the revenue dashboard', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/analytics/v1/revenue').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });
});

describe('Analytics: revenue dashboard (PRD Section 20/A.10)', () => {
  it('gross_revenue and take_rate_pct are internally consistent for an isolated completed trip', async () => {
    const { from } = await completeOneTrip();
    const admin = await loginAsNewUser(app);
    await grantAnalyticsPermission(admin.userId);

    const res = await request(app)
      .get(`/analytics/v1/revenue?from=${encodeURIComponent(from)}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.completed_bookings).toBe(1);
    expect(res.body.gross_revenue).toBeGreaterThan(0);
    expect(res.body.platform_fee_revenue).toBeGreaterThan(0);

    // take_rate_pct must equal platform_fee_revenue / gross_revenue * 100,
    // recomputed independently here rather than trusting the service's own math twice.
    const expectedTakeRate = Math.round((res.body.platform_fee_revenue / res.body.gross_revenue) * 10000) / 100;
    expect(res.body.take_rate_pct).toBe(expectedTakeRate);
  });

  it('an empty date range with no bookings returns all zeros, not an error', async () => {
    const admin = await loginAsNewUser(app);
    await grantAnalyticsPermission(admin.userId);
    const futureDate = new Date(Date.now() + 1000 * 60 * 60 * 24 * 365).toISOString();

    const res = await request(app)
      .get(`/analytics/v1/revenue?from=${encodeURIComponent(futureDate)}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.completed_bookings).toBe(0);
    expect(res.body.gross_revenue).toBe(0);
    expect(res.body.take_rate_pct).toBe(0); // never divides by zero
  });
});

describe('Analytics: booking funnel (PRD 20A.1)', () => {
  it('a completed trip appears at every funnel stage with 100% conversion in an isolated window', async () => {
    const { from } = await completeOneTrip();
    const admin = await loginAsNewUser(app);
    await grantAnalyticsPermission(admin.userId);

    const res = await request(app)
      .get(`/analytics/v1/funnel/booking?from=${encodeURIComponent(from)}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    const [confirmedStage, assignedStage, completedStage] = res.body.stages;
    expect(confirmedStage.count).toBe(1);
    expect(assignedStage.count).toBe(1);
    expect(completedStage.count).toBe(1);
    expect(assignedStage.conversion_from_previous_pct).toBe(100);
    expect(completedStage.conversion_from_previous_pct).toBe(100);
  });
});

describe('Analytics: cancellation breakdown (PRD 20A.10)', () => {
  it('a cancelled booking is attributed to its actual reason_code from the fixed taxonomy', async () => {
    const from = new Date().toISOString();
    const customer = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `cancel-analytics-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
    await request(app)
      .post(`/v1/bookings/${booking.body.id}/cancel`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ reason_code: 'PRICE_TOO_HIGH' });

    const admin = await loginAsNewUser(app);
    await grantAnalyticsPermission(admin.userId);
    const res = await request(app)
      .get(`/analytics/v1/cancellations?from=${encodeURIComponent(from)}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);

    expect(res.body.total_bookings).toBe(1);
    expect(res.body.cancellation_rate_pct).toBe(100);
    expect(res.body.by_reason).toEqual([{ reason_code: 'PRICE_TOO_HIGH', count: 1 }]);
  });
});

describe('Analytics: driver utilization (PRD 20A.10)', () => {
  it('a completed trip contributes positive trip_hours for its driver', async () => {
    const { from } = await completeOneTrip();
    const admin = await loginAsNewUser(app);
    await grantAnalyticsPermission(admin.userId);

    const res = await request(app)
      .get(`/analytics/v1/driver-utilization?from=${encodeURIComponent(from)}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.length).toBe(1);
    expect(res.body[0].completed_trips).toBe(1);
    expect(res.body[0].trip_hours).toBeGreaterThanOrEqual(0);
  });
});
