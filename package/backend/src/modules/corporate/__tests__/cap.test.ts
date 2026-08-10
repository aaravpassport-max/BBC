import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { createCorporateAccount, addCorporateEmployee, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function makeAdmin(accountId: string) {
  const admin = await loginAsNewUser(app);
  await pool.query(
    `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
    [accountId, admin.userId, `${admin.phone}@test.com`]
  );
  return admin;
}

describe('Per-user monthly cap: previously a completely unenforced column (PRD 14B.1)', () => {
  it('an employee with NO cap set can book freely up to the account credit limit', async () => {
    const accountId = await createCorporateAccount({ name: `NoCap Corp ${Date.now()}`, creditLimit: 5000 });
    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });

    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .set('Idempotency-Key', `nocap-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'corporate_bill' });
    expect(booking.status).toBe(201);
  });

  it('a booking that would exceed the per-user cap is rejected, and no reservation is made', async () => {
    const accountId = await createCorporateAccount({ name: `CapExceed Corp ${Date.now()}`, creditLimit: 5000 });
    const employee = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status, per_user_monthly_cap) VALUES ($1, $2, $3, 'employee', 'active', 1)`,
      [accountId, employee.userId, `${employee.phone}@test.com`]
    );

    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const fare = quote.body.quotes[0].fare_breakdown.final_fare;
    expect(fare).toBeGreaterThan(1);

    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .set('Idempotency-Key', `capexceed-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'corporate_bill' });
    expect(booking.status).toBe(400);
    expect(booking.body.error.details.cap).toMatch(/monthly booking cap/);

    const accountRow = await pool.query('SELECT reserved_spend FROM corporate_accounts WHERE id = $1', [accountId]);
    expect(parseFloat(accountRow.rows[0].reserved_spend)).toBe(0);
  });

  it('a booking within the cap succeeds, and counts toward the running total for the next one', async () => {
    const accountId = await createCorporateAccount({ name: `CapRunning Corp ${Date.now()}`, creditLimit: 5000 });
    const employee = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status, per_user_monthly_cap) VALUES ($1, $2, $3, 'employee', 'active', 500)`,
      [accountId, employee.userId, `${employee.phone}@test.com`]
    );

    const quote1 = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const first = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .set('Idempotency-Key', `caprun1-${crypto.randomUUID()}`)
      .send({ quote_id: quote1.body.quotes[0].quote_id, payment_method: 'corporate_bill' });
    expect(first.status).toBe(201);

    const admin = await makeAdmin(accountId);
    const employeeRow = await pool.query('SELECT id FROM corporate_employees WHERE user_id = $1', [employee.userId]);
    const firstFare = quote1.body.quotes[0].fare_breakdown.final_fare;
    await request(app)
      .patch(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}/cap`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ per_user_monthly_cap: firstFare - 1 });

    const quote2 = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const second = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .set('Idempotency-Key', `caprun2-${crypto.randomUUID()}`)
      .send({ quote_id: quote2.body.quotes[0].quote_id, payment_method: 'corporate_bill' });
    expect(second.status).toBe(400);
  });

  it('PRD explicit guarantee: lowering the cap NEVER retroactively invalidates the already-reserved first booking', async () => {
    const accountId = await createCorporateAccount({ name: `CapRetro Corp ${Date.now()}`, creditLimit: 5000 });
    const employee = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status, per_user_monthly_cap) VALUES ($1, $2, $3, 'employee', 'active', 500)`,
      [accountId, employee.userId, `${employee.phone}@test.com`]
    );

    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .set('Idempotency-Key', `capretro-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'corporate_bill' });
    expect(booking.status).toBe(201);

    const admin = await makeAdmin(accountId);
    const employeeRow = await pool.query('SELECT id FROM corporate_employees WHERE user_id = $1', [employee.userId]);
    await request(app)
      .patch(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}/cap`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ per_user_monthly_cap: 0.01 });

    const bookingCheck = await pool.query('SELECT status, corporate_account_id FROM bookings WHERE id = $1', [
      booking.body.id,
    ]);
    expect(bookingCheck.rows[0].corporate_account_id).toBe(accountId);
    expect(bookingCheck.rows[0].status).not.toBe('cancelled');

    const accountRow = await pool.query('SELECT reserved_spend FROM corporate_accounts WHERE id = $1', [accountId]);
    expect(parseFloat(accountRow.rows[0].reserved_spend)).toBeGreaterThan(0);
  });
});

describe('Per-user monthly cap: editing (PRD 14B.1 "Row actions: edit cap")', () => {
  it('a non-admin cannot edit a cap', async () => {
    const accountId = await createCorporateAccount({ name: `CapEditAuth Corp ${Date.now()}`, creditLimit: 5000 });
    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });
    const employeeRow = await pool.query('SELECT id FROM corporate_employees WHERE user_id = $1', [employee.userId]);

    const res = await request(app)
      .patch(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}/cap`)
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ per_user_monthly_cap: 100 });
    expect(res.status).toBe(403);
  });

  it('rejects setting a cap above the account credit limit, both at invite time and via edit', async () => {
    const accountId = await createCorporateAccount({ name: `CapOverLimit Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await makeAdmin(accountId);

    const inviteAttempt = await request(app)
      .post(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ email: `overcap-${Date.now()}@test.com`, role: 'employee', per_user_monthly_cap: 1000 });
    expect(inviteAttempt.status).toBe(400);

    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });
    const employeeRow = await pool.query('SELECT id FROM corporate_employees WHERE user_id = $1', [employee.userId]);
    const editAttempt = await request(app)
      .patch(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}/cap`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ per_user_monthly_cap: 1000 });
    expect(editAttempt.status).toBe(400);
  });

  it('an admin can clear a cap back to unlimited by setting it to null', async () => {
    const accountId = await createCorporateAccount({ name: `CapClear Corp ${Date.now()}`, creditLimit: 5000 });
    const admin = await makeAdmin(accountId);
    const employee = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status, per_user_monthly_cap) VALUES ($1, $2, $3, 'employee', 'active', 100)`,
      [accountId, employee.userId, `${employee.phone}@test.com`]
    );
    const employeeRow = await pool.query('SELECT id FROM corporate_employees WHERE user_id = $1', [employee.userId]);

    const res = await request(app)
      .patch(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}/cap`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ per_user_monthly_cap: null });
    expect(res.status).toBe(200);

    const row = await pool.query('SELECT per_user_monthly_cap FROM corporate_employees WHERE id = $1', [
      employeeRow.rows[0].id,
    ]);
    expect(row.rows[0].per_user_monthly_cap).toBeNull();
  });
});
