import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, createOnlineEligibleDriver, getRoleIdByName, ADMIN_SEED_CITY_ID, ADMIN_SEED_CATEGORY_ID } from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

async function grantAdminPermission(userId: string) {
  const roleId = await getRoleIdByName('ops_admin');
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    roleId,
  ]);
}

describe('Admin: RBAC enforcement (PRD Section 22 — API-layer, not UI-layer)', () => {
  it('a plain user cannot suspend a driver', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/admin/v1/drivers/00000000-0000-0000-0000-000000000000/suspend')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ reason_code: 'FRAUD_SUSPECTED' });
    expect(res.status).toBe(403);
  });

  it('a plain user cannot create a rate card', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/admin/v1/pricing/rate-cards')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({
        city_id: ADMIN_SEED_CITY_ID,
        vehicle_category_id: ADMIN_SEED_CATEGORY_ID,
        base_fare: 50,
        per_km_rate: 10,
        minimum_fare: 70,
      });
    expect(res.status).toBe(403);
  });
});

describe('Admin: rate card version-conflict handling (PRD 9A.1)', () => {
  it('rejects minimum_fare below base_fare', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await grantAdminPermission(userId);
    const res = await request(app)
      .post('/admin/v1/pricing/rate-cards')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ city_id: ADMIN_SEED_CITY_ID, vehicle_category_id: ADMIN_SEED_CATEGORY_ID, base_fare: 100, per_km_rate: 10, minimum_fare: 50 });
    expect(res.status).toBe(400);
  });

  it('publishing with the correct expected_version succeeds and increments version', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await grantAdminPermission(userId);
    const draft = await request(app)
      .post('/admin/v1/pricing/rate-cards')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ city_id: ADMIN_SEED_CITY_ID, vehicle_category_id: ADMIN_SEED_CATEGORY_ID, base_fare: 55, per_km_rate: 11, minimum_fare: 75 });

    const publish = await request(app)
      .post(`/admin/v1/pricing/rate-cards/${draft.body.id}/publish`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ expected_version: 1 });
    expect(publish.status).toBe(200);
    expect(publish.body.version).toBe(2);
  });

  it('publishing with a STALE expected_version is rejected with the current version, never silently overwritten', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await grantAdminPermission(userId);
    const draft = await request(app)
      .post('/admin/v1/pricing/rate-cards')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ city_id: ADMIN_SEED_CITY_ID, vehicle_category_id: ADMIN_SEED_CATEGORY_ID, base_fare: 60, per_km_rate: 12, minimum_fare: 80 });

    // First publish succeeds, moving version 1 -> 2.
    await request(app)
      .post(`/admin/v1/pricing/rate-cards/${draft.body.id}/publish`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ expected_version: 1 });

    // Second attempt still thinks it's at version 1 (stale read).
    const stalePublish = await request(app)
      .post(`/admin/v1/pricing/rate-cards/${draft.body.id}/publish`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ expected_version: 1 });
    expect(stalePublish.status).toBe(400);
    expect(stalePublish.body.error.details.current_version).toBe(2);
  });

  it('publishing a new card supersedes the previously-published one for the same city+category', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await grantAdminPermission(userId);

    const first = await request(app)
      .post('/admin/v1/pricing/rate-cards')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ city_id: ADMIN_SEED_CITY_ID, vehicle_category_id: ADMIN_SEED_CATEGORY_ID, base_fare: 65, per_km_rate: 13, minimum_fare: 85 });
    await request(app)
      .post(`/admin/v1/pricing/rate-cards/${first.body.id}/publish`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ expected_version: 1 });

    const second = await request(app)
      .post('/admin/v1/pricing/rate-cards')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ city_id: ADMIN_SEED_CITY_ID, vehicle_category_id: ADMIN_SEED_CATEGORY_ID, base_fare: 70, per_km_rate: 14, minimum_fare: 90 });
    await request(app)
      .post(`/admin/v1/pricing/rate-cards/${second.body.id}/publish`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ expected_version: 1 });

    const firstRow = await pool.query('SELECT status FROM rate_cards WHERE id = $1', [first.body.id]);
    const secondRow = await pool.query('SELECT status FROM rate_cards WHERE id = $1', [second.body.id]);
    expect(firstRow.rows[0].status).toBe('superseded');
    expect(secondRow.rows[0].status).toBe('published');
    // No cross-test cleanup needed: ADMIN_SEED_CATEGORY_ID
    // ('admin_test_category') is a dedicated category no pricing/booking
    // test ever queries by name, so superseding rate cards here can't
    // disturb any other test file's fare assumptions.
  });
});

describe('Admin: driver suspend/reinstate (PRD 9A.2), integrated with the eligibility gate', () => {
  it('suspending a driver immediately blocks them from going online (cross-module integration)', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await pool.query(`UPDATE driver_profiles SET training_status = 'passed', online_status = false WHERE user_id = $1`, [driverId]);
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driver = await loginAsNewUser(app, driverPhone);

    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const suspend = await request(app)
      .post(`/admin/v1/drivers/${driverId}/suspend`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ reason_code: 'SAFETY_COMPLAINT' });
    expect(suspend.status).toBe(200);

    const onlineAttempt = await request(app)
      .post('/v1/driver/status')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ online: true });
    expect(onlineAttempt.status).toBe(403);
    expect(onlineAttempt.body.error.details).toBeDefined();

    const auditRow = await pool.query(
      `SELECT actor_id, action FROM audit_log WHERE resource_id = $1 AND action = 'driver.suspend' ORDER BY created_at DESC LIMIT 1`,
      [driverId]
    );
    expect(auditRow.rows[0].actor_id).toBe(admin.userId);
  });

  it('reinstating a suspended, still-KYC-approved driver restores their ability to go online', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await pool.query(`UPDATE driver_profiles SET training_status = 'passed', online_status = false WHERE user_id = $1`, [driverId]);
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driver = await loginAsNewUser(app, driverPhone);

    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    await request(app)
      .post(`/admin/v1/drivers/${driverId}/suspend`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ reason_code: 'OTHER', note: 'Manual review needed.' });

    const reinstate = await request(app)
      .post(`/admin/v1/drivers/${driverId}/reinstate`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(reinstate.status).toBe(200);

    const onlineAttempt = await request(app)
      .post('/v1/driver/status')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ online: true });
    expect(onlineAttempt.status).toBe(200);
  });

  it('reinstating a driver whose KYC is no longer approved is blocked', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    await request(app)
      .post(`/admin/v1/drivers/${driverId}/suspend`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ reason_code: 'DOCUMENT_EXPIRED' });

    // Simulate the driver's KYC having lapsed while suspended.
    await pool.query(`UPDATE driver_profiles SET kyc_status = 'rejected' WHERE user_id = $1`, [driverId]);

    const reinstate = await request(app)
      .post(`/admin/v1/drivers/${driverId}/reinstate`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(reinstate.status).toBe(400);
  });

  it('requires a note when suspension reason is OTHER', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const res = await request(app)
      .post(`/admin/v1/drivers/${driverId}/suspend`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ reason_code: 'OTHER' });
    expect(res.status).toBe(400);
  });
});

describe('Admin: fraud queue (PRD 17A.1)', () => {
  it('resolving a flag requires a non-empty note', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);
    const flag = await pool.query(
      `INSERT INTO fraud_flags (subject_type, subject_id, signal_types, evidence, severity)
       VALUES ('user', $1, ARRAY['test_signal'], '{}', 'low') RETURNING id`,
      [admin.userId]
    );

    const res = await request(app)
      .post(`/admin/v1/fraud/queue/${flag.rows[0].id}/resolve`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ action: 'clear', note: '' });
    expect(res.status).toBe(400);
  });

  it('clearing a flag updates its status and records who resolved it', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);
    const flag = await pool.query(
      `INSERT INTO fraud_flags (subject_type, subject_id, signal_types, evidence, severity)
       VALUES ('user', $1, ARRAY['test_signal'], '{}', 'low') RETURNING id`,
      [admin.userId]
    );

    const res = await request(app)
      .post(`/admin/v1/fraud/queue/${flag.rows[0].id}/resolve`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ action: 'clear', note: 'False positive, verified with user.' });
    expect(res.status).toBe(200);

    const row = await pool.query('SELECT status, resolved_by FROM fraud_flags WHERE id = $1', [flag.rows[0].id]);
    expect(row.rows[0].status).toBe('cleared');
    expect(row.rows[0].resolved_by).toBe(admin.userId);
  });

  it('a Suspend resolution on a driver-subject flag actually suspends the driver (integration)', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    await pool.query(`UPDATE driver_profiles SET training_status = 'passed', online_status = false WHERE user_id = $1`, [driverId]);
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driver = await loginAsNewUser(app, driverPhone);

    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);
    const flag = await pool.query(
      `INSERT INTO fraud_flags (subject_type, subject_id, signal_types, evidence, severity)
       VALUES ('driver', $1, ARRAY['gps_spoofing'], '{}', 'high') RETURNING id`,
      [driverId]
    );

    await request(app)
      .post(`/admin/v1/fraud/queue/${flag.rows[0].id}/resolve`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ action: 'suspend', note: 'Confirmed GPS spoofing pattern across 3 trips.' });

    const onlineAttempt = await request(app)
      .post('/v1/driver/status')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ online: true });
    expect(onlineAttempt.status).toBe(403);
  });
});
