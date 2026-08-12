import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, createFleetDriverAndVehicle, createOnlineEligibleDriver, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

describe('Fleet: vehicle reassignment (PRD 13A.1)', () => {
  it('reassigns immediately when the vehicle has no active trip', async () => {
    const owner = await loginAsNewUser(app);
    const driver1 = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    const driver2 = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });

    const res = await request(app)
      .post(`/v1/fleet/vehicles/${driver1.vehicleId}/reassign`)
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .send({ new_driver_id: driver2.driverId });
    expect(res.status).toBe(200);
    expect(res.body.effective).toBe('immediate');

    const assignment = await pool.query(
      'SELECT driver_id FROM driver_vehicle_assignment WHERE vehicle_id = $1 AND is_active = true',
      [driver1.vehicleId]
    );
    expect(assignment.rows[0].driver_id).toBe(driver2.driverId);
  });

  it('rejects reassigning to a driver outside the owners fleet (server-side scoping, not just UI)', async () => {
    const owner = await loginAsNewUser(app);
    const otherOwner = await loginAsNewUser(app);
    const myDriver = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    const outsideDriver = await createFleetDriverAndVehicle({ fleetOwnerId: otherOwner.userId, phone: randomPhone() });

    const res = await request(app)
      .post(`/v1/fleet/vehicles/${myDriver.vehicleId}/reassign`)
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .send({ new_driver_id: outsideDriver.driverId });
    expect(res.status).toBe(403);
  });

  it('rejects reassigning a vehicle that does not belong to the requesting owner', async () => {
    const owner = await loginAsNewUser(app);
    const otherOwner = await loginAsNewUser(app);
    const otherVehicle = await createFleetDriverAndVehicle({ fleetOwnerId: otherOwner.userId, phone: randomPhone() });
    const myDriver = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });

    const res = await request(app)
      .post(`/v1/fleet/vehicles/${otherVehicle.vehicleId}/reassign`)
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .send({ new_driver_id: myDriver.driverId });
    expect(res.status).toBe(404);
  });

  it('downgrades to on_next_completion when the vehicle has an active trip, never interrupting it', async () => {
    const owner = await loginAsNewUser(app);
    const driver1 = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    const driver2 = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    const driver1Login = await loginAsNewUser(app, (await pool.query('SELECT phone FROM users WHERE id = $1', [driver1.driverId])).rows[0].phone);

    // Take every other driver offline so dispatch deterministically picks driver1.
    await pool.query(`UPDATE driver_profiles SET online_status = false WHERE user_id != $1`, [driver1.driverId]);

    const customer = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `fleet-active-trip-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
    const dispatch = await request(app)
      .post(`/v1/driver/dev/trigger-dispatch/${booking.body.id}`)
      .set('Authorization', `Bearer ${customer.accessToken}`);
    await request(app)
      .post(`/v1/driver/jobs/${dispatch.body.offerId}/accept`)
      .set('Authorization', `Bearer ${driver1Login.accessToken}`);

    // driver1 now has an active (driver_assigned) trip — attempt reassignment.
    const reassign = await request(app)
      .post(`/v1/fleet/vehicles/${driver1.vehicleId}/reassign`)
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .send({ new_driver_id: driver2.driverId });
    expect(reassign.status).toBe(200);
    expect(reassign.body.effective).toBe('on_next_completion');

    // The vehicle is STILL assigned to driver1 — reassignment did not happen yet.
    const assignment = await pool.query(
      'SELECT driver_id, scheduled_reassignment_to FROM driver_vehicle_assignment WHERE vehicle_id = $1 AND is_active = true',
      [driver1.vehicleId]
    );
    expect(assignment.rows[0].driver_id).toBe(driver1.driverId);
    expect(assignment.rows[0].scheduled_reassignment_to).toBe(driver2.driverId);

    // Complete the trip — reassignment should now apply automatically.
    const detail = await request(app)
      .get(`/v1/bookings/${booking.body.id}`)
      .set('Authorization', `Bearer ${customer.accessToken}`);
    await request(app)
      .post(`/v1/driver/jobs/${booking.body.id}/verify-pickup`)
      .set('Authorization', `Bearer ${driver1Login.accessToken}`)
      .send({ otp: detail.body.pickup_otp });
    const stop = detail.body.stops[0];
    await request(app)
      .post(`/v1/driver/jobs/${booking.body.id}/stops/${stop.id}/complete`)
      .set('Authorization', `Bearer ${driver1Login.accessToken}`)
      .send({ otp: stop.otp_code });

    const afterCompletion = await pool.query(
      'SELECT driver_id FROM driver_vehicle_assignment WHERE vehicle_id = $1 AND is_active = true',
      [driver1.vehicleId]
    );
    expect(afterCompletion.rows[0].driver_id).toBe(driver2.driverId);
  });
});

describe('Fleet: driver roster and status (P1 gap-analysis item — the "My Fleet" view)', () => {
  it('lists every driver in the fleet with a real, derived status', async () => {
    const owner = await loginAsNewUser(app);
    const driver1 = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    const driver2 = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });

    const res = await request(app).get('/v1/fleet/drivers').set('Authorization', `Bearer ${owner.accessToken}`);
    expect(res.status).toBe(200);
    const ids = res.body.map((d: { driver_id: string }) => d.driver_id).sort();
    expect(ids).toEqual([driver1.driverId, driver2.driverId].sort());
    // createFleetDriverAndVehicle sets online_status=true and no active booking.
    for (const row of res.body) {
      expect(row.status).toBe('online');
    }
  });

  it('a driver mid-trip shows status on_trip, not just online', async () => {
    const owner = await loginAsNewUser(app);
    const fleetDriver = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    const customer = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `fleet-status-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
    await pool.query(`UPDATE bookings SET status = 'driver_assigned', driver_id = $1 WHERE id = $2`, [
      fleetDriver.driverId,
      booking.body.id,
    ]);

    const res = await request(app).get('/v1/fleet/drivers').set('Authorization', `Bearer ${owner.accessToken}`);
    const row = res.body.find((d: { driver_id: string }) => d.driver_id === fleetDriver.driverId);
    expect(row.status).toBe('on_trip');
  });

  it('never shows another fleet owner\u2019s drivers', async () => {
    const ownerA = await loginAsNewUser(app);
    const ownerB = await loginAsNewUser(app);
    await createFleetDriverAndVehicle({ fleetOwnerId: ownerA.userId, phone: randomPhone() });

    const res = await request(app).get('/v1/fleet/drivers').set('Authorization', `Bearer ${ownerB.accessToken}`);
    expect(res.body).toEqual([]);
  });
});

describe('Fleet: adding and removing drivers (P1 gap-analysis item — previously only a raw-SQL test helper could do this)', () => {
  it('links an existing, independent driver to a fleet by phone number', async () => {
    const owner = await loginAsNewUser(app);
    const driverPhone = randomPhone();
    const driverId = await createOnlineEligibleDriver({ phone: driverPhone });

    const res = await request(app)
      .post('/v1/fleet/drivers')
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .send({ driver_phone: driverPhone });
    expect(res.status).toBe(201);
    expect(res.body.driverId).toBe(driverId);

    const row = await pool.query('SELECT fleet_owner_id FROM driver_profiles WHERE user_id = $1', [driverId]);
    expect(row.rows[0].fleet_owner_id).toBe(owner.userId);
  });

  it('adding the same driver twice to the SAME fleet is idempotent, not an error', async () => {
    const owner = await loginAsNewUser(app);
    const driverPhone = randomPhone();
    await createOnlineEligibleDriver({ phone: driverPhone });

    await request(app).post('/v1/fleet/drivers').set('Authorization', `Bearer ${owner.accessToken}`).send({ driver_phone: driverPhone });
    const second = await request(app)
      .post('/v1/fleet/drivers')
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .send({ driver_phone: driverPhone });
    expect(second.status).toBe(201);
  });

  it('cannot poach a driver already in a DIFFERENT fleet', async () => {
    const ownerA = await loginAsNewUser(app);
    const ownerB = await loginAsNewUser(app);
    const driverPhone = randomPhone();
    const driverId = await createOnlineEligibleDriver({ phone: driverPhone });
    await pool.query(`UPDATE driver_profiles SET fleet_owner_id = $1 WHERE user_id = $2`, [ownerA.userId, driverId]);

    const res = await request(app)
      .post('/v1/fleet/drivers')
      .set('Authorization', `Bearer ${ownerB.accessToken}`)
      .send({ driver_phone: driverPhone });
    expect(res.status).toBe(400);

    const row = await pool.query('SELECT fleet_owner_id FROM driver_profiles WHERE user_id = $1', [driverId]);
    expect(row.rows[0].fleet_owner_id).toBe(ownerA.userId); // unchanged
  });

  it('rejects a phone number that has no driver account at all', async () => {
    const owner = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/fleet/drivers')
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .send({ driver_phone: randomPhone() });
    expect(res.status).toBe(404);
  });

  it('removes a driver from the fleet — their own account is untouched', async () => {
    const owner = await loginAsNewUser(app);
    const fleetDriver = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });

    const res = await request(app)
      .delete(`/v1/fleet/drivers/${fleetDriver.driverId}`)
      .set('Authorization', `Bearer ${owner.accessToken}`);
    expect(res.status).toBe(200);

    const row = await pool.query('SELECT fleet_owner_id, kyc_status FROM driver_profiles WHERE user_id = $1', [
      fleetDriver.driverId,
    ]);
    expect(row.rows[0].fleet_owner_id).toBeNull();
    expect(row.rows[0].kyc_status).toBe('approved'); // their own driver profile is otherwise untouched
  });

  it('cannot remove a driver who does not belong to your fleet', async () => {
    const ownerA = await loginAsNewUser(app);
    const ownerB = await loginAsNewUser(app);
    const fleetDriver = await createFleetDriverAndVehicle({ fleetOwnerId: ownerA.userId, phone: randomPhone() });

    const res = await request(app)
      .delete(`/v1/fleet/drivers/${fleetDriver.driverId}`)
      .set('Authorization', `Bearer ${ownerB.accessToken}`);
    expect(res.status).toBe(404);

    const row = await pool.query('SELECT fleet_owner_id FROM driver_profiles WHERE user_id = $1', [fleetDriver.driverId]);
    expect(row.rows[0].fleet_owner_id).toBe(ownerA.userId); // unchanged
  });
});

describe('Fleet: per-driver detail (ownership-scoped)', () => {
  it('shows a fleet driver\u2019s own wallet balance and transactions', async () => {
    const owner = await loginAsNewUser(app);
    const fleetDriver = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });

    const res = await request(app)
      .get(`/v1/fleet/drivers/${fleetDriver.driverId}`)
      .set('Authorization', `Bearer ${owner.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.balance).toBe(0);
    expect(res.body.transactions).toEqual([]);
  });

  it('a fleet owner can never see a driver detail outside their own fleet — 403, not empty data', async () => {
    const ownerA = await loginAsNewUser(app);
    const ownerB = await loginAsNewUser(app);
    const fleetDriver = await createFleetDriverAndVehicle({ fleetOwnerId: ownerA.userId, phone: randomPhone() });

    const res = await request(app)
      .get(`/v1/fleet/drivers/${fleetDriver.driverId}`)
      .set('Authorization', `Bearer ${ownerB.accessToken}`);
    expect(res.status).toBe(403);
  });
});

describe('Fleet: earnings summary, and the driver-payout gap this surfaced', () => {
  it('starts at zero with the right driver count for a fresh fleet', async () => {
    const owner = await loginAsNewUser(app);
    await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });

    const res = await request(app).get('/v1/fleet/earnings').set('Authorization', `Bearer ${owner.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.totalToday).toBe(0);
    expect(res.body.driverCount).toBe(2);
  });

  it('reflects a REAL completed trip\u2019s payout, not a stale/simulated number', async () => {
    const owner = await loginAsNewUser(app);
    const fleetDriver = await createFleetDriverAndVehicle({ fleetOwnerId: owner.userId, phone: randomPhone() });
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [fleetDriver.driverId])).rows[0].phone;
    const driverLogin = await loginAsNewUser(app, driverPhone);

    const customer = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const fareBreakdown = quote.body.quotes[0].fare_breakdown;
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `fleet-earn-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
    await pool.query(`UPDATE bookings SET status = 'driver_assigned', driver_id = $1 WHERE id = $2`, [
      fleetDriver.driverId,
      booking.body.id,
    ]);

    const detail = await request(app)
      .get(`/v1/bookings/${booking.body.id}`)
      .set('Authorization', `Bearer ${customer.accessToken}`);
    await request(app)
      .post(`/v1/driver/jobs/${booking.body.id}/verify-pickup`)
      .set('Authorization', `Bearer ${driverLogin.accessToken}`)
      .send({ otp: detail.body.pickup_otp });
    for (const stop of detail.body.stops) {
      await request(app)
        .post(`/v1/driver/jobs/${booking.body.id}/stops/${stop.id}/complete`)
        .set('Authorization', `Bearer ${driverLogin.accessToken}`)
        .send({ otp: stop.otp_code });
    }

    const res = await request(app).get('/v1/fleet/earnings').set('Authorization', `Bearer ${owner.accessToken}`);
    const expectedPayout = fareBreakdown.final_fare - fareBreakdown.platform_fee;
    expect(res.body.totalToday).toBeCloseTo(expectedPayout, 2);
  });
});
