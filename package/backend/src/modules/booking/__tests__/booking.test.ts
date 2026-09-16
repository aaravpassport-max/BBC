import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsFundedUser, randomPhone } from '../../../test-utils/helpers';
import {
  assertReferenceSeedPresent,
  createCoupon,
  createCorporateAccount,
  addCorporateEmployee,
  createOnlineEligibleDriver,
  samplePickupDrop,
} from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

async function getQuote(token: string, couponCode?: string) {
  const res = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${token}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck', coupon_code: couponCode });
  if (res.status !== 200) throw new Error(`Quote failed: ${JSON.stringify(res.body)}`);
  return res.body.quotes[0];
}

describe('Booking: idempotency (PRD 2.2.6 hard requirement)', () => {
  it('the same Idempotency-Key returns the SAME booking on a duplicate request, never creates two', async () => {
    const { accessToken, userId } = await loginAsFundedUser(app);
    const quote = await getQuote(accessToken);
    const idempotencyKey = `idem-${crypto.randomUUID()}`;

    const first = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', idempotencyKey)
      .send({ quote_id: quote.quote_id, payment_method: 'wallet' });

    const second = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', idempotencyKey)
      .send({ quote_id: quote.quote_id, payment_method: 'wallet' });

    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect(second.body.id).toBe(first.body.id);

    const dbCount = await pool.query('SELECT count(*) FROM bookings WHERE customer_id = $1', [userId]);
    expect(parseInt(dbCount.rows[0].count, 10)).toBe(1);
  });

  it('a quote can only be used for ONE booking — reuse with a different idempotency key is rejected', async () => {
    const { accessToken } = await loginAsFundedUser(app);
    const quote = await getQuote(accessToken);

    const first = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `idem-a-${crypto.randomUUID()}`)
      .send({ quote_id: quote.quote_id, payment_method: 'wallet' });
    expect(first.status).toBe(201);

    const second = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `idem-b-${crypto.randomUUID()}`)
      .send({ quote_id: quote.quote_id, payment_method: 'wallet' });
    expect(second.status).toBe(409);
    expect(second.body.error.code).toBe('QUOTE_ALREADY_USED');
  });

  it('rejects booking creation without an Idempotency-Key header', async () => {
    const { accessToken } = await loginAsFundedUser(app);
    const quote = await getQuote(accessToken);

    const res = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ quote_id: quote.quote_id, payment_method: 'wallet' });
    expect(res.status).toBe(400);
  });
});

describe('Booking: cancellation (PRD 2A.1)', () => {
  it('cancelling twice returns ALREADY_CANCELLED on the second attempt, not a duplicate cancellation', async () => {
    const { accessToken } = await loginAsFundedUser(app);
    const quote = await getQuote(accessToken);
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `idem-${crypto.randomUUID()}`)
      .send({ quote_id: quote.quote_id, payment_method: 'wallet' });

    const firstCancel = await request(app)
      .post(`/v1/bookings/${booking.body.id}/cancel`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ reason_code: 'BOOKED_BY_MISTAKE' });
    expect(firstCancel.status).toBe(200);

    const secondCancel = await request(app)
      .post(`/v1/bookings/${booking.body.id}/cancel`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ reason_code: 'BOOKED_BY_MISTAKE' });
    expect(secondCancel.status).toBe(409);
    expect(secondCancel.body.error.code).toBe('ALREADY_CANCELLED');
  });
});

describe('Booking: coupon redemption race (PRD 15A.1 hard concurrency requirement)', () => {
  it('two customers racing for the LAST slot of a global_limit=1 coupon: exactly one succeeds, coupon caps out, loser has zero bookings', async () => {
    const couponCode = `RACE${Date.now()}`;
    const couponId = await createCoupon({ code: couponCode, discountValue: 10, globalLimit: 1 });

    const cust1 = await loginAsFundedUser(app);
    const cust2 = await loginAsFundedUser(app);

    const [q1, q2] = await Promise.all([getQuote(cust1.accessToken, couponCode), getQuote(cust2.accessToken, couponCode)]);
    expect(q1.fare_breakdown.coupon_discount).toBe(10);
    expect(q2.fare_breakdown.coupon_discount).toBe(10);

    const [res1, res2] = await Promise.all([
      request(app)
        .post('/v1/bookings')
        .set('Authorization', `Bearer ${cust1.accessToken}`)
        .set('Idempotency-Key', `race1-${crypto.randomUUID()}`)
        .send({ quote_id: q1.quote_id, payment_method: 'wallet' }),
      request(app)
        .post('/v1/bookings')
        .set('Authorization', `Bearer ${cust2.accessToken}`)
        .set('Idempotency-Key', `race2-${crypto.randomUUID()}`)
        .send({ quote_id: q2.quote_id, payment_method: 'wallet' }),
    ]);

    const results = [res1, res2];
    const succeeded = results.filter((r) => r.status === 201);
    const failed = results.filter((r) => r.status === 409 || r.status === 400);

    expect(succeeded.length).toBe(1);
    expect(failed.length).toBe(1);
    expect(failed[0].body.error.details.coupon_code).toMatch(/usage limit/);

    const redemptionCount = await pool.query('SELECT count(*) FROM coupon_redemptions WHERE coupon_id = $1', [couponId]);
    expect(parseInt(redemptionCount.rows[0].count, 10)).toBe(1);

    const couponRow = await pool.query('SELECT status FROM coupons WHERE id = $1', [couponId]);
    expect(couponRow.rows[0].status).toBe('usage_cap_reached');

    // The loser must have ZERO bookings — a clean rollback, not a booking left
    // without its discount.
    const loserId = succeeded[0] === res1 ? cust2.userId : cust1.userId;
    const loserBookings = await pool.query('SELECT count(*) FROM bookings WHERE customer_id = $1', [loserId]);
    expect(parseInt(loserBookings.rows[0].count, 10)).toBe(0);
  });
});

describe('Booking: corporate credit-limit race (PRD 14A.1 hard concurrency requirement)', () => {
  it('two employees racing for the last available budget on a tight limit: exactly one succeeds, limit correctly reserved', async () => {
    // A tight credit_limit that allows exactly one booking on this route,
    // never two — derived from a real quote rather than a hardcoded fare
    // figure. A hand-computed magic number here previously went stale
    // (silently, since nothing re-derives it) when the exact distance/fare
    // formula changed elsewhere in the codebase; deriving it from an
    // actual quote call means this test can never drift from reality.
    const probeEmployee = await loginAsFundedUser(app);
    const probeQuote = await getQuote(probeEmployee.accessToken);
    const singleFare = probeQuote.fare_breakdown.final_fare;
    const tightLimit = singleFare * 1.5; // room for exactly one, never two

    const accountId = await createCorporateAccount({ name: `Race Corp ${Date.now()}`, creditLimit: tightLimit });

    const emp1 = await loginAsFundedUser(app);
    const emp2 = await loginAsFundedUser(app);
    await addCorporateEmployee({ accountId, userId: emp1.userId, email: `${emp1.phone}@test.com` });
    await addCorporateEmployee({ accountId, userId: emp2.userId, email: `${emp2.phone}@test.com` });

    const [q1, q2] = await Promise.all([getQuote(emp1.accessToken), getQuote(emp2.accessToken)]);

    const [res1, res2] = await Promise.all([
      request(app)
        .post('/v1/bookings')
        .set('Authorization', `Bearer ${emp1.accessToken}`)
        .set('Idempotency-Key', `corp1-${crypto.randomUUID()}`)
        .send({ quote_id: q1.quote_id, payment_method: 'corporate_bill' }),
      request(app)
        .post('/v1/bookings')
        .set('Authorization', `Bearer ${emp2.accessToken}`)
        .set('Idempotency-Key', `corp2-${crypto.randomUUID()}`)
        .send({ quote_id: q2.quote_id, payment_method: 'corporate_bill' }),
    ]);

    const results = [res1, res2];
    const succeeded = results.filter((r) => r.status === 201);
    const failed = results.filter((r) => r.status === 402);

    expect(succeeded.length).toBe(1);
    expect(failed.length).toBe(1);
    expect(failed[0].body.error.code).toBe('CREDIT_LIMIT_EXCEEDED');

    const account = await pool.query(
      'SELECT credit_limit, committed_spend, reserved_spend FROM corporate_accounts WHERE id = $1',
      [accountId]
    );
    const acc = account.rows[0];
    // Reserved spend equals whichever quote actually won the race — q1 and
    // q2 are independently-generated quotes for the same route and should
    // match each other (and singleFare) to within normal rounding, but
    // asserting against the SPECIFIC winning quote's own fare is the
    // correct, non-brittle check rather than a second hardcoded figure.
    const winningFare = succeeded[0] === res1 ? q1.fare_breakdown.final_fare : q2.fare_breakdown.final_fare;
    expect(parseFloat(acc.reserved_spend)).toBeCloseTo(winningFare, 1);
    expect(parseFloat(acc.committed_spend) + parseFloat(acc.reserved_spend)).toBeLessThanOrEqual(
      parseFloat(acc.credit_limit)
    );
  });

  it('cancelling a reserved corporate booking releases the reservation immediately', async () => {
    const accountId = await createCorporateAccount({ name: `Release Corp ${Date.now()}`, creditLimit: 200 });
    const emp = await loginAsFundedUser(app);
    await addCorporateEmployee({ accountId, userId: emp.userId, email: `${emp.phone}@test.com` });

    const quote = await getQuote(emp.accessToken);
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${emp.accessToken}`)
      .set('Idempotency-Key', `release-${crypto.randomUUID()}`)
      .send({ quote_id: quote.quote_id, payment_method: 'corporate_bill' });
    expect(booking.status).toBe(201);

    let account = await pool.query('SELECT reserved_spend FROM corporate_accounts WHERE id = $1', [accountId]);
    expect(parseFloat(account.rows[0].reserved_spend)).toBeGreaterThan(0);

    await request(app)
      .post(`/v1/bookings/${booking.body.id}/cancel`)
      .set('Authorization', `Bearer ${emp.accessToken}`)
      .send({ reason_code: 'BOOKED_BY_MISTAKE' });

    account = await pool.query('SELECT reserved_spend FROM corporate_accounts WHERE id = $1', [accountId]);
    expect(parseFloat(account.rows[0].reserved_spend)).toBe(0);
  });
});

describe('Dispatch: no-double-offer guarantee under concurrency (PRD Section 4 hard requirement)', () => {
  it('two bookings dispatched concurrently against a SINGLE online driver: at most one active offer exists for that driver', async () => {
    const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });

    const cust1 = await loginAsFundedUser(app);
    const cust2 = await loginAsFundedUser(app);
    const [q1, q2] = await Promise.all([getQuote(cust1.accessToken), getQuote(cust2.accessToken)]);

    const b1 = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${cust1.accessToken}`)
      .set('Idempotency-Key', `disp1-${crypto.randomUUID()}`)
      .send({ quote_id: q1.quote_id, payment_method: 'wallet' });
    const b2 = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${cust2.accessToken}`)
      .set('Idempotency-Key', `disp2-${crypto.randomUUID()}`)
      .send({ quote_id: q2.quote_id, payment_method: 'wallet' });

    await Promise.all([
      request(app).post(`/v1/driver/dev/trigger-dispatch/${b1.body.id}`).set('Authorization', `Bearer ${cust1.accessToken}`),
      request(app).post(`/v1/driver/dev/trigger-dispatch/${b2.body.id}`).set('Authorization', `Bearer ${cust2.accessToken}`),
    ]);

    const activeOffers = await pool.query(
      `SELECT count(*) FROM dispatch_offers WHERE driver_id = $1 AND status = 'offered'`,
      [driverId]
    );
    expect(parseInt(activeOffers.rows[0].count, 10)).toBeLessThanOrEqual(1);
  });
});
