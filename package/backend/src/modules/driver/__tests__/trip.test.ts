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

/** Books, dispatches, and accepts a trip end-to-end via the real API (not
 * DB shortcuts), returning everything a test needs to drive it further. */
async function setUpAcceptedTrip() {
  // Test-DB hygiene: previous runs' drivers are left online at the same
  // seeded coordinates (never cleaned up), which made earlier runs of this
  // suite flaky — dispatch would correctly assign SOME eligible driver, but
  // not necessarily the one this specific test just created, since many
  // tied-distance candidates accumulate across runs. Taking every other
  // driver offline before dispatching keeps this test deterministic without
  // needing a full DB reset between test files.
  await pool.query(`UPDATE driver_profiles SET online_status = false`);

  const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
  const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
  const driverLogin = await loginAsNewUser(app, driverPhone);

  const customer = await loginAsNewUser(app);
  const quoteRes = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const quoteId = quoteRes.body.quotes[0].quote_id;

  const bookingRes = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .set('Idempotency-Key', `trip-${crypto.randomUUID()}`)
    .send({ quote_id: quoteId, payment_method: 'wallet' });
  const bookingId = bookingRes.body.id;

  const dispatchRes = await request(app)
    .post(`/v1/driver/dev/trigger-dispatch/${bookingId}`)
    .set('Authorization', `Bearer ${customer.accessToken}`);
  const offerId = dispatchRes.body.offerId;

  if (dispatchRes.body.driverId !== driverId) {
    throw new Error(
      `Test setup assumption broken: expected dispatch to assign our seeded driver ${driverId}, got ${dispatchRes.body.driverId}. ` +
        'This means the "take every other driver offline" hygiene step above did not fully isolate this test.'
    );
  }

  await request(app)
    .post(`/v1/driver/jobs/${offerId}/accept`)
    .set('Authorization', `Bearer ${driverLogin.accessToken}`);

  // Read the OTPs the way the real customer app would — via the booking detail endpoint.
  const detail = await request(app)
    .get(`/v1/bookings/${bookingId}`)
    .set('Authorization', `Bearer ${customer.accessToken}`);

  return {
    bookingId,
    customerToken: customer.accessToken,
    driverToken: driverLogin.accessToken,
    pickupOtp: detail.body.pickup_otp as string,
    stops: detail.body.stops as { id: string; otp_code: string; sequence: number }[],
  };
}

describe('Trip lifecycle: pickup verification (PRD 2.2.7)', () => {
  it('wrong pickup OTP is rejected and does not advance trip state', async () => {
    const trip = await setUpAcceptedTrip();
    const res = await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: '0000' === trip.pickupOtp ? '1111' : '0000' });
    expect(res.status).toBe(400);

    const status = await pool.query('SELECT status FROM bookings WHERE id = $1', [trip.bookingId]);
    expect(status.rows[0].status).toBe('driver_assigned');
  });

  it('correct pickup OTP transitions the booking to in_progress', async () => {
    const trip = await setUpAcceptedTrip();
    const res = await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: trip.pickupOtp });
    expect(res.status).toBe(200);
    expect(res.body.status).toBe('in_progress');
  });

  it('locks out after 3 wrong pickup OTP attempts (PRD 2.2.7 escalation rule)', async () => {
    const trip = await setUpAcceptedTrip();
    const wrongOtp = trip.pickupOtp === '0000' ? '1111' : '0000';

    let lastRes;
    for (let i = 0; i < 4; i++) {
      lastRes = await request(app)
        .post(`/v1/driver/jobs/${trip.bookingId}/verify-pickup`)
        .set('Authorization', `Bearer ${trip.driverToken}`)
        .send({ otp: wrongOtp });
    }
    expect(lastRes!.body.error.details.otp).toMatch(/flagged for support/);

    // Even the CORRECT code no longer works once locked.
    const finalAttempt = await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: trip.pickupOtp });
    expect(finalAttempt.status).toBe(400);
    expect(finalAttempt.body.error.details.otp).toMatch(/flagged for support/);
  });

  it('a driver who is not assigned to this booking cannot verify its pickup OTP', async () => {
    const trip = await setUpAcceptedTrip();
    const otherDriverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const otherDriverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [otherDriverId])).rows[0].phone;
    const otherDriver = await loginAsNewUser(app, otherDriverPhone);

    const res = await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${otherDriver.accessToken}`)
      .send({ otp: trip.pickupOtp });
    expect(res.status).toBe(404);
  });
});

describe('Trip lifecycle: stop completion and sequence integrity (PRD 3B.1)', () => {
  it('completing the only stop with the correct OTP completes the trip', async () => {
    const trip = await setUpAcceptedTrip();
    await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: trip.pickupOtp });

    const stop = trip.stops[0];
    const res = await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/stops/${stop.id}/complete`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: stop.otp_code });

    expect(res.status).toBe(200);
    expect(res.body.tripCompleted).toBe(true);
    expect(res.body.bookingStatus).toBe('completed');

    const dbStatus = await pool.query('SELECT status FROM bookings WHERE id = $1', [trip.bookingId]);
    expect(dbStatus.rows[0].status).toBe('completed');
  });

  it('cannot complete a stop before the trip has started (still driver_assigned, pickup not verified)', async () => {
    const trip = await setUpAcceptedTrip();
    const stop = trip.stops[0];
    const res = await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/stops/${stop.id}/complete`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: stop.otp_code });
    expect(res.status).toBe(400);

    const dbStatus = await pool.query('SELECT status FROM bookings WHERE id = $1', [trip.bookingId]);
    expect(dbStatus.rows[0].status).toBe('driver_assigned');
  });

  it('rejects an incorrect stop OTP without completing it', async () => {
    const trip = await setUpAcceptedTrip();
    await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: trip.pickupOtp });

    const stop = trip.stops[0];
    const wrongOtp = stop.otp_code === '0000' ? '1111' : '0000';
    const res = await request(app)
      .post(`/v1/driver/jobs/${trip.bookingId}/stops/${stop.id}/complete`)
      .set('Authorization', `Bearer ${trip.driverToken}`)
      .send({ otp: wrongOtp });
    expect(res.status).toBe(400);

    const stopStatus = await pool.query('SELECT status FROM booking_stops WHERE id = $1', [stop.id]);
    expect(stopStatus.rows[0].status).toBe('pending');
  });
});

describe('Driver: polling endpoints for offers and active jobs (PRD 3.3, no push infrastructure)', () => {
  it('returns null (not an error) when a driver has no pending offer', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driver = await loginAsNewUser(app, driverPhone);

    const res = await request(app).get('/v1/driver/jobs/pending-offer').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body).toBeNull();
  });

  it('returns the actual pending offer once dispatched', async () => {
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
      .set('Idempotency-Key', `poll-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
    const dispatch = await request(app)
      .post(`/v1/driver/dev/trigger-dispatch/${booking.body.id}`)
      .set('Authorization', `Bearer ${customer.accessToken}`);

    const res = await request(app).get('/v1/driver/jobs/pending-offer').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.offer_id).toBe(dispatch.body.offerId);
    expect(res.body.booking_id).toBe(booking.body.id);
  });

  it('returns null for active job before accepting, then the real job with stops after accepting', async () => {
    await pool.query(`UPDATE driver_profiles SET online_status = false`);
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driver = await loginAsNewUser(app, driverPhone);
    const customer = await loginAsNewUser(app);

    const before = await request(app).get('/v1/driver/jobs/active').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(before.body).toBeNull();

    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', `active-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
    const dispatch = await request(app)
      .post(`/v1/driver/dev/trigger-dispatch/${booking.body.id}`)
      .set('Authorization', `Bearer ${customer.accessToken}`);
    await request(app)
      .post(`/v1/driver/jobs/${dispatch.body.offerId}/accept`)
      .set('Authorization', `Bearer ${driver.accessToken}`);

    const after = await request(app).get('/v1/driver/jobs/active').set('Authorization', `Bearer ${driver.accessToken}`);
    expect(after.body.id).toBe(booking.body.id);
    expect(after.body.status).toBe('driver_assigned');
    expect(after.body.stops.length).toBe(1);

    // SECURITY: the pickup OTP and each stop's OTP must never be readable
    // by the driver's own app — only the customer sees these, to read
    // aloud and prove the driver is genuinely present (PRD 2.2.7). If the
    // driver could fetch the code directly, the whole verification is
    // defeated.
    expect(after.body.pickup_otp).toBeUndefined();
    expect(after.body.stops[0].otp_code).toBeUndefined();
  });
});

describe('Trip lifecycle: corporate reservation finalization wiring (PRD 14A.1 step 5)', () => {
  it('completing a corporate-billed trip finalizes its reservation (moves reserved -> committed)', async () => {
    const { createCorporateAccount, addCorporateEmployee } = await import('../../../test-utils/seed');
    await pool.query(`UPDATE driver_profiles SET online_status = false`); // see setUpAcceptedTrip's hygiene note
    const accountId = await createCorporateAccount({ name: `Finalize Corp ${Date.now()}`, creditLimit: 200 });

    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
    const driverLogin = await loginAsNewUser(app, driverPhone);

    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });

    const quoteRes = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const quoteId = quoteRes.body.quotes[0].quote_id;

    const bookingRes = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .set('Idempotency-Key', `corp-finalize-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'corporate_bill' });
    const bookingId = bookingRes.body.id;

    const dispatchRes = await request(app)
      .post(`/v1/driver/dev/trigger-dispatch/${bookingId}`)
      .set('Authorization', `Bearer ${employee.accessToken}`);
    await request(app)
      .post(`/v1/driver/jobs/${dispatchRes.body.offerId}/accept`)
      .set('Authorization', `Bearer ${driverLogin.accessToken}`);

    const detail = await request(app)
      .get(`/v1/bookings/${bookingId}`)
      .set('Authorization', `Bearer ${employee.accessToken}`);

    await request(app)
      .post(`/v1/driver/jobs/${bookingId}/verify-pickup`)
      .set('Authorization', `Bearer ${driverLogin.accessToken}`)
      .send({ otp: detail.body.pickup_otp });

    const stop = detail.body.stops[0];
    const before = await pool.query(
      'SELECT reserved_spend, committed_spend FROM corporate_accounts WHERE id = $1',
      [accountId]
    );
    expect(parseFloat(before.rows[0].reserved_spend)).toBeGreaterThan(0);
    expect(parseFloat(before.rows[0].committed_spend)).toBe(0);

    await request(app)
      .post(`/v1/driver/jobs/${bookingId}/stops/${stop.id}/complete`)
      .set('Authorization', `Bearer ${driverLogin.accessToken}`)
      .send({ otp: stop.otp_code });

    const after = await pool.query(
      'SELECT reserved_spend, committed_spend FROM corporate_accounts WHERE id = $1',
      [accountId]
    );
    expect(parseFloat(after.rows[0].reserved_spend)).toBe(0);
    expect(parseFloat(after.rows[0].committed_spend)).toBeGreaterThan(0);

    const reservation = await pool.query('SELECT status FROM corporate_reservations WHERE booking_id = $1', [
      bookingId,
    ]);
    expect(reservation.rows[0].status).toBe('finalized');
  });
});

describe('Active job: drop-stop coordinates (P1 gap-analysis item — turn-by-turn navigation)', () => {
  it('the active job response includes real, usable coordinates for both pickup AND every drop stop', async () => {
    const { bookingId, driverToken } = await setUpAcceptedTrip();
    const res = await request(app).get('/v1/driver/jobs/active').set('Authorization', `Bearer ${driverToken}`);
    expect(res.status).toBe(200);
    expect(res.body.id).toBe(bookingId);

    // Pickup coordinates already worked before this fix — verifying they
    // still do, alongside the new drop coordinates, not in isolation.
    expect(typeof res.body.pickup_lat).toBe('number');
    expect(typeof res.body.pickup_lng).toBe('number');

    expect(res.body.stops.length).toBeGreaterThan(0);
    for (const stop of res.body.stops) {
      expect(typeof stop.drop_lat).toBe('number');
      expect(typeof stop.drop_lng).toBe('number');
      // A real coordinate, not a zeroed/null placeholder silently passing
      // the typeof check.
      expect(stop.drop_lat).not.toBe(0);
      expect(stop.drop_lng).not.toBe(0);
    }
  });

  it('drop coordinates match what was actually booked, not a different or stale location', async () => {
    const { bookingId, driverToken } = await setUpAcceptedTrip();
    const res = await request(app).get('/v1/driver/jobs/active').set('Authorization', `Bearer ${driverToken}`);

    const dbStop = await pool.query(
      `SELECT ST_Y(geo::geometry) AS lat, ST_X(geo::geometry) AS lng FROM booking_stops WHERE booking_id = $1 ORDER BY sequence LIMIT 1`,
      [bookingId]
    );
    expect(res.body.stops[0].drop_lat).toBeCloseTo(dbStop.rows[0].lat, 5);
    expect(res.body.stops[0].drop_lng).toBeCloseTo(dbStop.rows[0].lng, 5);
  });
});

describe('Accept/decline: malformed offerId is rejected cleanly (found via a real end-to-end test that hit exactly this case)', () => {
  it('a malformed offerId on accept returns 400, not a raw 500 from an invalid Postgres UUID', async () => {
    const { driverToken } = await setUpAcceptedTrip();
    const res = await request(app)
      .post('/v1/driver/jobs/not-a-real-uuid/accept')
      .set('Authorization', `Bearer ${driverToken}`);
    expect(res.status).toBe(400);
  });

  it('the literal string "undefined" (the exact real-world case this was found from) is rejected the same way', async () => {
    const { driverToken } = await setUpAcceptedTrip();
    const res = await request(app)
      .post('/v1/driver/jobs/undefined/accept')
      .set('Authorization', `Bearer ${driverToken}`);
    expect(res.status).toBe(400);
  });

  it('a malformed offerId on decline is rejected the same way', async () => {
    const { driverToken } = await setUpAcceptedTrip();
    const res = await request(app)
      .post('/v1/driver/jobs/not-a-real-uuid/decline')
      .set('Authorization', `Bearer ${driverToken}`);
    expect(res.status).toBe(400);
  });

  it('a well-formed but non-existent offerId still returns a clean error, not a crash', async () => {
    const { driverToken } = await setUpAcceptedTrip();
    const res = await request(app)
      .post(`/v1/driver/jobs/${crypto.randomUUID()}/accept`)
      .set('Authorization', `Bearer ${driverToken}`);
    expect(res.status).toBeLessThan(500);
  });
});
