import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, samplePickupDrop, getRoleIdByName } from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

async function grantKycReviewer(userId: string) {
  const roleId = await getRoleIdByName('kyc_reviewer');
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    roleId,
  ]);
}

/** Onboards a driver ENTIRELY through the real API — registration, all four
 * KYC documents, reviewer approval, vehicle registration — with zero SQL
 * fixture shortcuts. This is deliberately the slow way: every other test in
 * this codebase uses createOnlineEligibleDriver's direct SQL insert for
 * speed, which is exactly what silently masked the missing-vehicle-
 * registration-endpoint gap this test guards against. */
async function onboardDriverThroughRealApi(driverToken: string, driverUserId: string) {
  await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${driverToken}`);
  for (const step of ['identity_document', 'driving_license', 'vehicle_documents', 'bank_details']) {
    await request(app)
      .post(`/v1/driver/kyc/${step}`)
      .set('Authorization', `Bearer ${driverToken}`)
      .send({ document_url: `https://example.com/${step}.jpg` });
  }

  const reviewer = await loginAsNewUser(app);
  await grantKycReviewer(reviewer.userId);
  const docs = await pool.query(`SELECT id FROM kyc_documents WHERE subject_id = $1`, [driverUserId]);
  for (const doc of docs.rows) {
    await request(app)
      .post(`/v1/driver/kyc/documents/${doc.id}/review`)
      .set('Authorization', `Bearer ${reviewer.accessToken}`)
      .send({ decision: 'approved' });
  }
  await request(app).get('/v1/driver/kyc/status').set('Authorization', `Bearer ${driverToken}`);

  await pool.query(`UPDATE driver_profiles SET training_status = 'passed' WHERE user_id = $1`, [driverUserId]);
}

describe('Driver: vehicle registration (PRD 3.2 step 4 / Section A.2)', () => {
  it('rejects an unknown vehicle category', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/driver/vehicles')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'not_a_real_category', plate_number: 'KA01ZZ0001' });
    expect(res.status).toBe(400);
  });

  it('registers a vehicle and creates an active assignment', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/driver/vehicles')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'mini_truck', plate_number: `KA01AB${Date.now() % 10000}` });
    expect(res.status).toBe(201);

    const assignment = await pool.query(
      `SELECT is_active FROM driver_vehicle_assignment WHERE driver_id = $1 AND vehicle_id = $2`,
      [userId, res.body.id]
    );
    expect(assignment.rows[0].is_active).toBe(true);
  });

  it('rejects a duplicate plate number', async () => {
    const driver1 = await loginAsNewUser(app);
    const driver2 = await loginAsNewUser(app);
    const plate = `KA01DUP${Date.now() % 1000}`;

    const first = await request(app)
      .post('/v1/driver/vehicles')
      .set('Authorization', `Bearer ${driver1.accessToken}`)
      .send({ category: 'mini_truck', plate_number: plate });
    expect(first.status).toBe(201);

    const second = await request(app)
      .post('/v1/driver/vehicles')
      .set('Authorization', `Bearer ${driver2.accessToken}`)
      .send({ category: 'mini_truck', plate_number: plate });
    expect(second.status).toBe(400);
  });

  it('registering a new vehicle deactivates the previous active assignment', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    const first = await request(app)
      .post('/v1/driver/vehicles')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'mini_truck', plate_number: `KA01OLD${Date.now() % 1000}` });
    const second = await request(app)
      .post('/v1/driver/vehicles')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'mini_truck', plate_number: `KA01NEW${Date.now() % 1000}` });

    const oldAssignment = await pool.query(
      `SELECT is_active FROM driver_vehicle_assignment WHERE driver_id = $1 AND vehicle_id = $2`,
      [userId, first.body.id]
    );
    const newAssignment = await pool.query(
      `SELECT is_active FROM driver_vehicle_assignment WHERE driver_id = $1 AND vehicle_id = $2`,
      [userId, second.body.id]
    );
    expect(oldAssignment.rows[0].is_active).toBe(false);
    expect(newAssignment.rows[0].is_active).toBe(true);
  });
});

describe('REGRESSION: a driver onboarded entirely through the real API must actually be dispatchable', () => {
  it('an API-only onboarded driver (KYC + vehicle registration, no SQL fixture shortcuts) receives a real dispatch offer', async () => {
    await pool.query(`UPDATE driver_profiles SET online_status = false`); // isolation, see other files' note

    const driver = await loginAsNewUser(app);
    await onboardDriverThroughRealApi(driver.accessToken, driver.userId);

    const vehicleRes = await request(app)
      .post('/v1/driver/vehicles')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ category: 'mini_truck', plate_number: `KA01RG${Date.now() % 10000}` });
    expect(vehicleRes.status).toBe(201);

    const onlineRes = await request(app)
      .post('/v1/driver/status')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ online: true });
    expect(onlineRes.status).toBe(200);

    await request(app)
      .post('/v1/driver/location')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ lat: 12.951, lng: 77.601 });

    const customer = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `regression-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });

    const dispatch = await request(app)
      .post(`/v1/driver/dev/trigger-dispatch/${booking.body.id}`)
      .set('Authorization', `Bearer ${customer.accessToken}`);

    expect(dispatch.body.status).toBe('offer_sent');
    expect(dispatch.body.driverId).toBe(driver.userId);
  });
});
