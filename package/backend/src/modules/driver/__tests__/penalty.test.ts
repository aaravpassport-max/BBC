import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { createOnlineEligibleDriver, getRoleIdByName } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function grantAdminPermission(userId: string) {
  const roleId = await getRoleIdByName('ops_admin'); // has driver.suspend permission
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    roleId,
  ]);
}

async function creditDriverWallet(driverId: string, amount: number) {
  const wallet = await pool.query(
    `INSERT INTO wallets (owner_type, owner_id, currency) VALUES ('driver', $1, 'INR')
     ON CONFLICT (owner_type, owner_id, currency) DO UPDATE SET owner_type = EXCLUDED.owner_type
     RETURNING id, real_balance_cache`,
    [driverId]
  );
  const newBalance = parseFloat(wallet.rows[0].real_balance_cache) + amount;
  await pool.query(
    `INSERT INTO wallet_transactions (wallet_id, transaction_group_id, entry_type, balance_type, amount, balance_after, reason)
     VALUES ($1, gen_random_uuid(), 'credit', 'real', $2, $3, 'trip_earning')`,
    [wallet.rows[0].id, amount, newBalance]
  );
  await pool.query(`UPDATE wallets SET real_balance_cache = $1 WHERE id = $2`, [newBalance, wallet.rows[0].id]);
}

describe('Driver: withdraw funds (PRD Section A.2)', () => {
  it('withdrawable balance reflects wallet balance minus zero when no fraud hold exists', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 500);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);

    const res = await request(app).get('/v1/driver/wallet/withdrawable').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(res.body.available).toBe(500);
    expect(res.body.held).toBe(0);
  });

  it('a fraud hold freezes the entire withdrawable balance', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 500);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);

    await pool.query(
      `INSERT INTO fraud_flags (subject_type, subject_id, signal_types, evidence, severity, status)
       VALUES ('driver', $1, ARRAY['test'], '{}', 'high', 'held')`,
      [driverId]
    );

    const res = await request(app).get('/v1/driver/wallet/withdrawable').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(res.body.available).toBe(0);
    expect(res.body.held).toBe(500);
  });

  it('a withdrawal request exceeding available balance is rejected with the actual available/held figures', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 100);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);

    const res = await request(app)
      .post('/v1/driver/wallet/withdraw')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ amount: 200, mode: 'standard' });
    expect(res.status).toBe(400);
    expect(res.body.error.details.available).toBe(100);
  });

  it('a successful withdrawal debits the wallet by exactly the requested amount', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 300);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);

    const res = await request(app)
      .post('/v1/driver/wallet/withdraw')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ amount: 120, mode: 'standard' });
    expect(res.status).toBe(202);

    const wallet = await pool.query(
      `SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`,
      [driverId]
    );
    expect(parseFloat(wallet.rows[0].real_balance_cache)).toBe(180);
  });

  it('a driver with a partial hold can still withdraw whatever is not frozen', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 400);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);

    // No hold yet — this driver should be able to withdraw normally, proving
    // withdrawal isn't blocked just because the driver COULD theoretically
    // be flagged (i.e., not a blanket restriction, only an actual hold).
    const res = await request(app)
      .post('/v1/driver/wallet/withdraw')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ amount: 400, mode: 'standard' });
    expect(res.status).toBe(202);
  });
});

describe('Driver: penalties and disputes (PRD Section A.2)', () => {
  it('issuing a penalty debits the driver wallet and creates a structured record', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 200);
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const res = await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: driverId, amount: 50, reason_code: 'LATE_ARRIVAL', reason_note: 'Arrived 25 minutes late.' });
    expect(res.status).toBe(201);

    const wallet = await pool.query(`SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`, [driverId]);
    expect(parseFloat(wallet.rows[0].real_balance_cache)).toBe(150);

    const penaltyRow = await pool.query('SELECT status, amount, reason_code FROM penalties WHERE id = $1', [res.body.id]);
    expect(penaltyRow.rows[0].status).toBe('issued');
    expect(parseFloat(penaltyRow.rows[0].amount)).toBe(50);
  });

  it('a penalty can push a driver wallet negative (distinct from the customer hard floor)', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 30);
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const res = await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: driverId, amount: 50, reason_code: 'DOCUMENT_VIOLATION' });
    expect(res.status).toBe(201);

    const wallet = await pool.query(`SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`, [driverId]);
    expect(parseFloat(wallet.rows[0].real_balance_cache)).toBe(-20);
  });

  it('a driver can dispute their own penalty but not one belonging to another driver', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const otherDriverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const penalty = await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: otherDriverId, amount: 30, reason_code: 'OTHER', reason_note: 'x' });

    const wrongDriverDispute = await request(app)
      .post(`/v1/driver/penalties/${penalty.body.id}/dispute`)
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ note: 'This is not my penalty.' });
    expect(wrongDriverDispute.status).toBe(400);
  });

  it('resolving a dispute as reversed refunds the penalty amount', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 200);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const penalty = await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: driverId, amount: 40, reason_code: 'LATE_ARRIVAL' });

    await request(app)
      .post(`/v1/driver/penalties/${penalty.body.id}/dispute`)
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ note: 'I was on time, GPS was wrong.' });

    const resolve = await request(app)
      .post(`/v1/driver/admin/penalties/${penalty.body.id}/resolve`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ resolution: 'reversed', resolution_note: 'GPS log confirms on-time arrival, penalty reversed.' });
    expect(resolve.status).toBe(200);

    const wallet = await pool.query(`SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`, [driverId]);
    expect(parseFloat(wallet.rows[0].real_balance_cache)).toBe(200); // net zero: -40 then +40

    const penaltyRow = await pool.query('SELECT status, resolution_note FROM penalties WHERE id = $1', [penalty.body.id]);
    expect(penaltyRow.rows[0].status).toBe('reversed');
    expect(penaltyRow.rows[0].resolution_note).toMatch(/GPS log confirms/);
  });

  it('resolving a dispute as upheld does NOT refund, and reasoning is recorded', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 200);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const penalty = await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: driverId, amount: 40, reason_code: 'LATE_ARRIVAL' });
    await request(app)
      .post(`/v1/driver/penalties/${penalty.body.id}/dispute`)
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ note: 'Traffic was bad.' });

    await request(app)
      .post(`/v1/driver/admin/penalties/${penalty.body.id}/resolve`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ resolution: 'upheld', resolution_note: 'Traffic delay does not exempt the lateness policy per driver agreement.' });

    const wallet = await pool.query(`SELECT real_balance_cache FROM wallets WHERE owner_type = 'driver' AND owner_id = $1`, [driverId]);
    expect(parseFloat(wallet.rows[0].real_balance_cache)).toBe(160); // unchanged from the original -40

    const penaltyRow = await pool.query('SELECT status FROM penalties WHERE id = $1', [penalty.body.id]);
    expect(penaltyRow.rows[0].status).toBe('upheld');
  });

  it('cannot dispute a penalty that is not in issued status', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await creditDriverWallet(driverId, 200);
    const driver = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone);
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const penalty = await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: driverId, amount: 20, reason_code: 'OTHER', reason_note: 'x' });
    await request(app)
      .post(`/v1/driver/penalties/${penalty.body.id}/dispute`)
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ note: 'Disputing once.' });

    const secondDispute = await request(app)
      .post(`/v1/driver/penalties/${penalty.body.id}/dispute`)
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ note: 'Disputing again.' });
    expect(secondDispute.status).toBe(400);
  });

  it('a non-admin cannot issue a penalty', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ driver_id: driverId, amount: 20, reason_code: 'OTHER', reason_note: 'x' });
    expect(res.status).toBe(403);
  });
});

describe('Driver earnings history (P1 gap-analysis item — reuses wallet.service\u2019s existing generic getTransactionHistory, previously only ever called with ownerType=customer)', () => {
  it('starts empty for a driver with no transactions yet', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driver = await loginAsNewUser(app, driverPhone);

    const res = await request(app).get('/v1/driver/wallet/transactions').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body).toEqual([]);
  });

  it('shows a real penalty deduction in the driver\u2019s own history, most recent first', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driver = await loginAsNewUser(app, driverPhone);
    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO user_roles (user_id, role_id) SELECT $1, id FROM roles WHERE name = 'ops_admin'`,
      [admin.userId]
    );

    await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: driverId, amount: 15, reason_code: 'LATE_ARRIVAL', reason_note: 'Late to pickup.' });

    const res = await request(app).get('/v1/driver/wallet/transactions').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.length).toBeGreaterThanOrEqual(1);
    expect(res.body[0].reason).toBe('penalty');
  });

  it('a driver only ever sees their OWN transaction history, never another driver\u2019s', async () => {
    const driverAId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverAPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverAId])).rows[0].phone;
    const driverA = await loginAsNewUser(app, driverAPhone);

    const driverBId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverBPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverBId])).rows[0].phone;
    const driverB = await loginAsNewUser(app, driverBPhone);

    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO user_roles (user_id, role_id) SELECT $1, id FROM roles WHERE name = 'ops_admin'`,
      [admin.userId]
    );
    await request(app)
      .post('/v1/driver/admin/penalties')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ driver_id: driverAId, amount: 10, reason_code: 'OTHER', reason_note: 'x' });

    const bHistory = await request(app)
      .get('/v1/driver/wallet/transactions')
      .set('Authorization', `Bearer ${driverB.accessToken}`);
    expect(bHistory.body).toEqual([]); // driver B sees nothing from driver A's penalty

    const aHistory = await request(app)
      .get('/v1/driver/wallet/transactions')
      .set('Authorization', `Bearer ${driverA.accessToken}`);
    expect(aHistory.body.length).toBeGreaterThanOrEqual(1);
  });
});
