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

async function completeATripAsCustomer(customerToken: string) {
  await pool.query(`UPDATE driver_profiles SET online_status = false`);
  const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
  const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
  const driver = await loginAsNewUser(app, driverPhone);

  const quoteRes = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customerToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const bookingRes = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customerToken}`)
    .set('Idempotency-Key', `referral-trip-${crypto.randomUUID()}`)
    .send({ quote_id: quoteRes.body.quotes[0].quote_id, payment_method: 'wallet' });
  const bookingId = bookingRes.body.id;

  const dispatchRes = await request(app)
    .post(`/v1/driver/dev/trigger-dispatch/${bookingId}`)
    .set('Authorization', `Bearer ${customerToken}`);
  await request(app)
    .post(`/v1/driver/jobs/${dispatchRes.body.offerId}/accept`)
    .set('Authorization', `Bearer ${driver.accessToken}`);

  const detail = await request(app).get(`/v1/bookings/${bookingId}`).set('Authorization', `Bearer ${customerToken}`);
  await request(app)
    .post(`/v1/driver/jobs/${bookingId}/verify-pickup`)
    .set('Authorization', `Bearer ${driver.accessToken}`)
    .send({ otp: detail.body.pickup_otp });

  const stop = detail.body.stops[0];
  await request(app)
    .post(`/v1/driver/jobs/${bookingId}/stops/${stop.id}/complete`)
    .set('Authorization', `Bearer ${driver.accessToken}`)
    .send({ otp: stop.otp_code });

  return bookingId;
}

describe('Referral: code generation and redemption (PRD 18A.1)', () => {
  it('generates a stable referral code that stays the same on repeated requests', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const first = await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${accessToken}`);
    const second = await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${accessToken}`);
    expect(first.body.referral_code).toBe(second.body.referral_code);
    expect(first.body.referral_code).toHaveLength(8);
  });

  it('rejects redeeming an invalid code', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/referral/redeem')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ referral_code: 'NOTREAL1' });
    expect(res.status).toBe(400);
  });

  it('rejects self-referral', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const summary = await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${accessToken}`);
    const res = await request(app)
      .post('/v1/referral/redeem')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ referral_code: summary.body.referral_code });
    expect(res.status).toBe(400);
    expect(res.body.error.details.referral_code).toMatch(/cannot refer yourself/);
  });

  it('rejects a second redemption by the same referee (one referral per account)', async () => {
    const referrer1 = await loginAsNewUser(app);
    const referrer2 = await loginAsNewUser(app);
    const referee = await loginAsNewUser(app);
    const code1 = (await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${referrer1.accessToken}`)).body.referral_code;
    const code2 = (await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${referrer2.accessToken}`)).body.referral_code;

    const first = await request(app)
      .post('/v1/referral/redeem')
      .set('Authorization', `Bearer ${referee.accessToken}`)
      .send({ referral_code: code1 });
    expect(first.status).toBe(200);

    const second = await request(app)
      .post('/v1/referral/redeem')
      .set('Authorization', `Bearer ${referee.accessToken}`)
      .send({ referral_code: code2 });
    expect(second.status).toBe(400);
  });
});

describe('Referral: reward fulfillment on qualifying trip (PRD 18A.1 trigger)', () => {
  it('completing the referees FIRST trip credits both referrer and referee wallets', async () => {
    const referrer = await loginAsNewUser(app);
    const referee = await loginAsNewUser(app);
    const code = (await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${referrer.accessToken}`)).body.referral_code;
    await request(app)
      .post('/v1/referral/redeem')
      .set('Authorization', `Bearer ${referee.accessToken}`)
      .send({ referral_code: code });

    await completeATripAsCustomer(referee.accessToken);

    const referrerWallet = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${referrer.accessToken}`);
    const refereeWallet = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${referee.accessToken}`);
    expect(referrerWallet.body.promotional_credit_balance).toBe(100);
    expect(refereeWallet.body.promotional_credit_balance).toBe(50);

    const referralRow = await pool.query('SELECT status FROM referrals WHERE referee_id = $1', [referee.userId]);
    expect(referralRow.rows[0].status).toBe('fulfilled');

    const summary = await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${referrer.accessToken}`);
    expect(summary.body.successful_referrals).toBe(1);
    expect(summary.body.earned_confirmed).toBe(100);
  });

  it('a SECOND completed trip by the same referee does not credit a second reward (idempotent)', async () => {
    const referrer = await loginAsNewUser(app);
    const referee = await loginAsNewUser(app);
    const code = (await request(app).get('/v1/referral/summary').set('Authorization', `Bearer ${referrer.accessToken}`)).body.referral_code;
    await request(app)
      .post('/v1/referral/redeem')
      .set('Authorization', `Bearer ${referee.accessToken}`)
      .send({ referral_code: code });

    await completeATripAsCustomer(referee.accessToken);
    await completeATripAsCustomer(referee.accessToken); // second trip

    const referrerWallet = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${referrer.accessToken}`);
    expect(referrerWallet.body.promotional_credit_balance).toBe(100); // NOT 200

    const ledgerCount = await pool.query(
      `SELECT count(*) FROM wallet_transactions WHERE reason = 'referral' AND wallet_id = (
         SELECT id FROM wallets WHERE owner_type = 'customer' AND owner_id = $1
       )`,
      [referrer.userId]
    );
    expect(parseInt(ledgerCount.rows[0].count, 10)).toBe(1);
  });

  it('completing a trip with NO referral in play does nothing (no error, no phantom credit)', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await completeATripAsCustomer(accessToken);

    const wallet = await request(app).get('/v1/wallet').set('Authorization', `Bearer ${accessToken}`);
    expect(wallet.body.promotional_credit_balance).toBe(0);

    const referralRow = await pool.query('SELECT count(*) FROM referrals WHERE referee_id = $1', [userId]);
    expect(parseInt(referralRow.rows[0].count, 10)).toBe(0);
  });
});
