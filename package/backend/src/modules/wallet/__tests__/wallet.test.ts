import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

describe('Wallet: top-up is server-confirmed, never client-trusted (PRD Section 6 hard rule)', () => {
  it('balance stays at 0 immediately after initiating a top-up — only updates on webhook confirmation', async () => {
    const { accessToken } = await loginAsNewUser(app);

    const before = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${accessToken}`);
    expect(before.body.real_money_balance).toBe(0);

    const topUp = await request(app)
      .post('/v1/wallet/add-money')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ amount: 500, payment_method_id: 'test-method' });
    expect(topUp.status).toBe(202);

    const afterInitiate = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${accessToken}`);
    expect(afterInitiate.body.real_money_balance).toBe(0);
  });

  it('balance updates to the exact amount after webhook confirmation', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const topUp = await request(app)
      .post('/v1/wallet/add-money')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ amount: 250, payment_method_id: 'test-method' });
    const gatewayRef = topUp.body.gateway_session.gateway_ref;

    await request(app)
      .post('/v1/wallet/dev/simulate-webhook')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ gateway_ref: gatewayRef, amount: 250 });

    const after = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${accessToken}`);
    expect(after.body.real_money_balance).toBe(250);
  });

  it('duplicate webhook delivery for the SAME gateway_ref cannot double-credit', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const topUp = await request(app)
      .post('/v1/wallet/add-money')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ amount: 100, payment_method_id: 'test-method' });
    const gatewayRef = topUp.body.gateway_session.gateway_ref;

    // Fire the "webhook" twice, sequentially — simulating a provider retry.
    await request(app)
      .post('/v1/wallet/dev/simulate-webhook')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ gateway_ref: gatewayRef, amount: 100 });
    await request(app)
      .post('/v1/wallet/dev/simulate-webhook')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ gateway_ref: gatewayRef, amount: 100 });

    const after = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${accessToken}`);
    expect(after.body.real_money_balance).toBe(100); // NOT 200

    const ledgerCount = await pool.query(
      `SELECT count(*) FROM wallet_transactions WHERE linked_gateway_ref = $1`,
      [gatewayRef]
    );
    expect(parseInt(ledgerCount.rows[0].count, 10)).toBe(1);
  });

  it('duplicate webhook delivery fired CONCURRENTLY (not sequentially) still cannot double-credit', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const topUp = await request(app)
      .post('/v1/wallet/add-money')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ amount: 75, payment_method_id: 'test-method' });
    const gatewayRef = topUp.body.gateway_session.gateway_ref;

    await Promise.all([
      request(app)
        .post('/v1/wallet/dev/simulate-webhook')
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ gateway_ref: gatewayRef, amount: 75 }),
      request(app)
        .post('/v1/wallet/dev/simulate-webhook')
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ gateway_ref: gatewayRef, amount: 75 }),
    ]);

    const after = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${accessToken}`);
    expect(after.body.real_money_balance).toBe(75);

    const ledgerCount = await pool.query(
      `SELECT count(*) FROM wallet_transactions WHERE linked_gateway_ref = $1`,
      [gatewayRef]
    );
    expect(parseInt(ledgerCount.rows[0].count, 10)).toBe(1);
  });

  it('rejects a top-up amount of zero or negative', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/wallet/add-money')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ amount: -50, payment_method_id: 'test-method' });
    expect(res.status).toBe(400);
  });

  it('transaction history reflects every ledger entry with a correct running balance_after', async () => {
    const { accessToken } = await loginAsNewUser(app);
    for (const amount of [100, 50]) {
      const topUp = await request(app)
        .post('/v1/wallet/add-money')
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ amount, payment_method_id: 'test-method' });
      await request(app)
        .post('/v1/wallet/dev/simulate-webhook')
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ gateway_ref: topUp.body.gateway_session.gateway_ref, amount });
    }

    const history = await request(app)
      .get('/v1/wallet/transactions')
      .set('Authorization', `Bearer ${accessToken}`);
    expect(history.body.length).toBe(2);
    // Most recent first.
    expect(parseFloat(history.body[0].balance_after)).toBe(150);
    expect(parseFloat(history.body[1].balance_after)).toBe(100);
  });
});

describe('Wallet: a client-facing confirmation cannot be used to credit someone ELSE\u2019s top-up (P0 payment-gateway work)', () => {
  it('a different customer cannot confirm your pending payment via the simulator route', async () => {
    const { accessToken: ownerToken } = await loginAsNewUser(app);
    const topUp = await request(app)
      .post('/v1/wallet/add-money')
      .set('Authorization', `Bearer ${ownerToken}`)
      .send({ amount: 300, payment_method_id: 'test-method' });

    const { accessToken: attackerToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/wallet/dev/simulate-webhook')
      .set('Authorization', `Bearer ${attackerToken}`)
      .send({ gateway_ref: topUp.body.gateway_session.gateway_ref, amount: 300 });
    expect(res.status).toBe(403);

    // The real owner's wallet must be completely unaffected by the attempt.
    const ownerBalance = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${ownerToken}`);
    expect(ownerBalance.body.real_money_balance).toBe(0);
  });

  it('the real owner can still confirm their own payment after a failed attempt by someone else', async () => {
    const { accessToken: ownerToken } = await loginAsNewUser(app);
    const topUp = await request(app)
      .post('/v1/wallet/add-money')
      .set('Authorization', `Bearer ${ownerToken}`)
      .send({ amount: 200, payment_method_id: 'test-method' });

    const { accessToken: attackerToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/wallet/dev/simulate-webhook')
      .set('Authorization', `Bearer ${attackerToken}`)
      .send({ gateway_ref: topUp.body.gateway_session.gateway_ref, amount: 200 });

    const confirm = await request(app)
      .post('/v1/wallet/dev/simulate-webhook')
      .set('Authorization', `Bearer ${ownerToken}`)
      .send({ gateway_ref: topUp.body.gateway_session.gateway_ref, amount: 200 });
    expect(confirm.status).toBe(200);

    const balance = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${ownerToken}`);
    expect(balance.body.real_money_balance).toBe(200);
  });
});
