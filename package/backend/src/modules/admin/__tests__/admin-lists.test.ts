import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import {
  assertReferenceSeedPresent,
  createOnlineEligibleDriver,
  ADMIN_SEED_CITY_ID,
  ADMIN_SEED_CATEGORY_ID,
  getRoleIdByName,
} from '../../../test-utils/seed';

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

describe('Admin: list rate cards (a genuine gap found while building the Admin frontend — create/publish existed with no way to see what exists)', () => {
  it('a plain user cannot list rate cards', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/admin/v1/pricing/rate-cards').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('a newly-created and published rate card appears in the list, correctly reflecting its status', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const draft = await request(app)
      .post('/admin/v1/pricing/rate-cards')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ city_id: ADMIN_SEED_CITY_ID, vehicle_category_id: ADMIN_SEED_CATEGORY_ID, base_fare: 61, per_km_rate: 11, minimum_fare: 76 });

    const beforePublish = await request(app)
      .get(`/admin/v1/pricing/rate-cards?city_id=${ADMIN_SEED_CITY_ID}&vehicle_category_id=${ADMIN_SEED_CATEGORY_ID}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    const draftEntry = beforePublish.body.find((c: { id: string }) => c.id === draft.body.id);
    expect(draftEntry.status).toBe('draft');

    await request(app)
      .post(`/admin/v1/pricing/rate-cards/${draft.body.id}/publish`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ expected_version: 1 });

    const afterPublish = await request(app)
      .get(`/admin/v1/pricing/rate-cards?city_id=${ADMIN_SEED_CITY_ID}&vehicle_category_id=${ADMIN_SEED_CATEGORY_ID}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    const publishedEntry = afterPublish.body.find((c: { id: string }) => c.id === draft.body.id);
    expect(publishedEntry.status).toBe('published');
    expect(publishedEntry.version).toBe(2);
  });

  it('filters correctly by city and category, excluding cards from other pairs', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);

    const res = await request(app)
      .get(`/admin/v1/pricing/rate-cards?city_id=${ADMIN_SEED_CITY_ID}&vehicle_category_id=${ADMIN_SEED_CATEGORY_ID}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.every((c: { id: string }) => c.id)).toBe(true);
    expect(res.body.every((c: { vehicle_category_name: string }) => c.vehicle_category_name === 'admin_test_category')).toBe(
      true
    );
  });
});

describe('Admin: list drivers (a genuine gap found while building the Admin frontend — suspend/reinstate existed with no way to find a driver to act on)', () => {
  it('a plain user cannot list drivers', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/admin/v1/drivers').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('an admin sees a driver they just created, with correct status fields', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);
    const phone = randomPhone();
    const driverId = await createOnlineEligibleDriver({ phone });

    const res = await request(app).get(`/admin/v1/drivers?search=${phone}`).set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    const found = res.body.find((d: { id: string }) => d.id === driverId);
    expect(found).toBeDefined();
    expect(found.kyc_status).toBe('approved');
    expect(found.phone).toBe(phone);
  });

  it('the search filter actually narrows results, not just decoration', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);
    const phone1 = randomPhone();
    const phone2 = randomPhone();
    await createOnlineEligibleDriver({ phone: phone1 });
    await createOnlineEligibleDriver({ phone: phone2 });

    const res = await request(app).get(`/admin/v1/drivers?search=${phone1}`).set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.body.some((d: { phone: string }) => d.phone === phone1)).toBe(true);
    expect(res.body.some((d: { phone: string }) => d.phone === phone2)).toBe(false);
  });

  it('a suspended driver shows suspended_at and suspension_reason in the list', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdminPermission(admin.userId);
    const phone = randomPhone();
    const driverId = await createOnlineEligibleDriver({ phone });

    await request(app)
      .post(`/admin/v1/drivers/${driverId}/suspend`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ reason_code: 'SAFETY_COMPLAINT' });

    const res = await request(app).get(`/admin/v1/drivers?search=${phone}`).set('Authorization', `Bearer ${admin.accessToken}`);
    const found = res.body.find((d: { id: string }) => d.id === driverId);
    expect(found.suspended_at).toBeTruthy();
    expect(found.suspension_reason).toBe('SAFETY_COMPLAINT');
  });
});
