import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

describe('Subscriptions: purchase and lifecycle (PRD 19A.1)', () => {
  it('purchases a subscription and it appears as active', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });
    expect(purchase.status).toBe(201);

    const me = await request(app).get('/v1/subscriptions/me').set('Authorization', `Bearer ${accessToken}`);
    expect(me.body.status).toBe('active');
    expect(me.body.plan_id).toBe('platform_plus');
  });

  it('rejects purchasing a second subscription while one is already active', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });
    const second = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });
    expect(second.status).toBe(400);
  });

  it('rejects an unknown plan_id', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'not_a_real_plan' });
    expect(res.status).toBe(400);
  });

  it('proactive cancellation is clean — no grace period, immediately cancelled', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });

    const cancel = await request(app)
      .post(`/v1/subscriptions/${purchase.body.id}/cancel`)
      .set('Authorization', `Bearer ${accessToken}`);
    expect(cancel.status).toBe(200);

    const me = await request(app).get('/v1/subscriptions/me').set('Authorization', `Bearer ${accessToken}`);
    expect(me.body.status).toBe('cancelled');
  });
});

describe('Subscriptions: renewal and dunning (PRD 19A.1 grace-period flow)', () => {
  it('a failed renewal moves active -> grace_period, benefits still apply', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });

    const renewal = await request(app)
      .post(`/v1/subscriptions/dev/attempt-renewal/${purchase.body.id}`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ simulate_success: false });
    expect(renewal.body.status).toBe('grace_period');

    const me = await request(app).get('/v1/subscriptions/me').set('Authorization', `Bearer ${accessToken}`);
    expect(me.body.status).toBe('grace_period');
    expect(me.body.grace_period_ends_at).toBeTruthy();
  });

  it('a successful renewal restores active status and resets retry_count', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });
    await request(app)
      .post(`/v1/subscriptions/dev/attempt-renewal/${purchase.body.id}`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ simulate_success: false });

    const renewal = await request(app)
      .post(`/v1/subscriptions/dev/attempt-renewal/${purchase.body.id}`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ simulate_success: true });
    expect(renewal.body.status).toBe('active');

    const sub = await pool.query('SELECT retry_count, grace_period_ends_at FROM subscriptions WHERE id = $1', [
      purchase.body.id,
    ]);
    expect(sub.rows[0].retry_count).toBe(0);
    expect(sub.rows[0].grace_period_ends_at).toBeNull();
  });

  it('exceeding max retries while still in grace lapses the subscription', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });

    let lastResult;
    for (let i = 0; i < 5; i++) {
      lastResult = await request(app)
        .post(`/v1/subscriptions/dev/attempt-renewal/${purchase.body.id}`)
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ simulate_success: false });
    }
    expect(lastResult!.body.status).toBe('lapsed');

    const me = await request(app).get('/v1/subscriptions/me').set('Authorization', `Bearer ${accessToken}`);
    expect(me.body.status).toBe('lapsed');
  });

  it('reactivation within the window restores active status', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });
    for (let i = 0; i < 5; i++) {
      await request(app)
        .post(`/v1/subscriptions/dev/attempt-renewal/${purchase.body.id}`)
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ simulate_success: false });
    }

    const reactivate = await request(app)
      .post(`/v1/subscriptions/${purchase.body.id}/reactivate`)
      .set('Authorization', `Bearer ${accessToken}`);
    expect(reactivate.status).toBe(200);

    const me = await request(app).get('/v1/subscriptions/me').set('Authorization', `Bearer ${accessToken}`);
    expect(me.body.status).toBe('active');
  });

  it('rejects reactivating a subscription that is not lapsed', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });

    const res = await request(app)
      .post(`/v1/subscriptions/${purchase.body.id}/reactivate`)
      .set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(404);
  });
});

describe('Subscriptions: benefit application in pricing (PRD 19A.1 acceptance criteria)', () => {
  it('a non-subscriber pays the full platform fee', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    expect(quote.body.quotes[0].fare_breakdown.subscription_benefit).toBe(0);
    expect(quote.body.quotes[0].fare_breakdown.platform_fee).toBeGreaterThan(0);
  });

  it('an active subscriber with a platform-fee-waiving plan gets the fee itemized off, final_fare reduced accordingly', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });

    const withoutSub = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });

    const fb = withoutSub.body.quotes[0].fare_breakdown;
    expect(fb.subscription_benefit).toBeGreaterThan(0);
    expect(fb.subscription_benefit).toBe(fb.platform_fee);
  });

  it('benefit still applies during grace_period (PRD 19A.1: benefits continue through grace, only cut off on lapse)', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });
    await request(app)
      .post(`/v1/subscriptions/dev/attempt-renewal/${purchase.body.id}`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ simulate_success: false }); // now in grace_period

    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    expect(quote.body.quotes[0].fare_breakdown.subscription_benefit).toBeGreaterThan(0);
  });

  it('benefit stops applying once lapsed', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const purchase = await request(app)
      .post('/v1/subscriptions/purchase')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ plan_id: 'platform_plus' });
    for (let i = 0; i < 5; i++) {
      await request(app)
        .post(`/v1/subscriptions/dev/attempt-renewal/${purchase.body.id}`)
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ simulate_success: false });
    }

    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    expect(quote.body.quotes[0].fare_breakdown.subscription_benefit).toBe(0);
  });
});
