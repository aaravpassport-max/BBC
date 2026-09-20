import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { createCorporateAccount, addCorporateEmployee, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

describe('SECURITY FIX: corporate account summary and employee list require active membership', () => {
  it('a user with no relationship to a corporate account cannot view its financial summary', async () => {
    const accountId = await createCorporateAccount({ name: `Sec Corp ${Date.now()}`, creditLimit: 1000 });
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get(`/v1/corporate/${accountId}`).set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('a user with no relationship to a corporate account cannot view its employee roster', async () => {
    const accountId = await createCorporateAccount({ name: `Sec Corp2 ${Date.now()}`, creditLimit: 1000 });
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .get(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('an active (non-admin) employee CAN view the summary and roster — membership, not admin role, is what is required', async () => {
    const accountId = await createCorporateAccount({ name: `Sec Corp3 ${Date.now()}`, creditLimit: 1000 });
    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });

    const summary = await request(app).get(`/v1/corporate/${accountId}`).set('Authorization', `Bearer ${employee.accessToken}`);
    expect(summary.status).toBe(200);
    expect(summary.body.id).toBe(accountId);

    const roster = await request(app)
      .get(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${employee.accessToken}`);
    expect(roster.status).toBe(200);
  });

  it('a REMOVED employee can no longer view the summary — status must be active, not just present', async () => {
    const accountId = await createCorporateAccount({ name: `Sec Corp4 ${Date.now()}`, creditLimit: 1000 });
    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );
    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });
    const employeeRow = await pool.query('SELECT id FROM corporate_employees WHERE user_id = $1', [employee.userId]);
    await request(app)
      .delete(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);

    const res = await request(app).get(`/v1/corporate/${accountId}`).set('Authorization', `Bearer ${employee.accessToken}`);
    expect(res.status).toBe(403);
  });
});

describe('Corporate: discovering my own accounts (a genuine gap — every other endpoint required already knowing the account ID)', () => {
  it('returns an empty list for a user with no corporate memberships', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/v1/corporate/my-accounts').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body).toEqual([]);
  });

  it('lists every account a user actively belongs to, with their role, and excludes accounts they were removed from', async () => {
    const account1 = await createCorporateAccount({ name: `My Corp A ${Date.now()}`, creditLimit: 500 });
    const account2 = await createCorporateAccount({ name: `My Corp B ${Date.now()}`, creditLimit: 500 });
    const account3 = await createCorporateAccount({ name: `My Corp C ${Date.now()}`, creditLimit: 500 });
    const user = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId: account1, userId: user.userId, email: `${user.phone}@test.com` });
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [account2, user.userId, `${user.phone}@test2.com`]
    );
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'employee', 'removed')`,
      [account3, user.userId, `${user.phone}@test3.com`]
    );

    const res = await request(app).get('/v1/corporate/my-accounts').set('Authorization', `Bearer ${user.accessToken}`);
    expect(res.status).toBe(200);
    const accountIds = res.body.map((a: { account_id: string }) => a.account_id);
    expect(accountIds).toContain(account1);
    expect(accountIds).toContain(account2);
    expect(accountIds).not.toContain(account3);

    const account2Entry = res.body.find((a: { account_id: string }) => a.account_id === account2);
    expect(account2Entry.role).toBe('account_admin');
  });
});

describe('Corporate: active bookings across the org (PRD 14A.1 Company Dashboard)', () => {
  it('a user with no relationship to the account cannot view its bookings', async () => {
    const accountId = await createCorporateAccount({ name: `Bookings Sec Corp ${Date.now()}`, creditLimit: 1000 });
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get(`/v1/corporate/${accountId}/bookings`).set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('a real booking made by an employee shows up, correctly attributed to them, for any active team member to see', async () => {
    const accountId = await createCorporateAccount({ name: `Bookings Corp ${Date.now()}`, creditLimit: 5000 });
    const bookerEmployee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: bookerEmployee.userId, email: `${bookerEmployee.phone}@test.com` });

    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${bookerEmployee.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${bookerEmployee.accessToken}`)
      .set('Idempotency-Key', `corp-booking-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'corporate_bill' });
    expect(booking.status).toBe(201);

    // A DIFFERENT active teammate (not the booker) should also be able to
    // see it — this is an org-wide view, not just "my own bookings".
    const otherEmployee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: otherEmployee.userId, email: `${otherEmployee.phone}@test.com` });

    const res = await request(app)
      .get(`/v1/corporate/${accountId}/bookings`)
      .set('Authorization', `Bearer ${otherEmployee.accessToken}`);
    expect(res.status).toBe(200);
    const found = res.body.find((b: { id: string }) => b.id === booking.body.id);
    expect(found).toBeDefined();
    expect(found.employee_phone).toBe(bookerEmployee.phone);
    expect(found.fare_breakdown).toBeDefined();
  });

  it('does NOT include a booking from an unrelated customer with no corporate affiliation', async () => {
    const accountId = await createCorporateAccount({ name: `Bookings Isolation Corp ${Date.now()}`, creditLimit: 5000 });
    const admin = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: admin.userId, email: `${admin.phone}@test.com` });

    const unrelatedCustomer = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${unrelatedCustomer.accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
    const unrelatedBooking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${unrelatedCustomer.accessToken}`)
      .set('Idempotency-Key', `unrelated-${crypto.randomUUID()}`)
      .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });

    const res = await request(app)
      .get(`/v1/corporate/${accountId}/bookings`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.body.some((b: { id: string }) => b.id === unrelatedBooking.body.id)).toBe(false);
  });
});

describe('Corporate: accepting an invite (a genuine gap — inviteEmployee left user_id NULL with no way to ever link it)', () => {
  it('rejects accepting an invite for an email with no pending invite', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/corporate/invites/accept')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ email: 'nobody-invited-this@test.com' });
    expect(res.status).toBe(400);
  });

  it('a real invite, created via the real invite endpoint, can actually be accepted end-to-end', async () => {
    const accountId = await createCorporateAccount({ name: `Accept Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );

    const inviteEmail = `newhire-${Date.now()}@test.com`;
    await request(app)
      .post(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ email: inviteEmail, role: 'employee' });

    const beforeRow = await pool.query('SELECT user_id, status FROM corporate_employees WHERE email = $1', [inviteEmail]);
    expect(beforeRow.rows[0].user_id).toBeNull();
    expect(beforeRow.rows[0].status).toBe('invited');

    const newHire = await loginAsNewUser(app);
    const accept = await request(app)
      .post('/v1/corporate/invites/accept')
      .set('Authorization', `Bearer ${newHire.accessToken}`)
      .send({ email: inviteEmail });
    expect(accept.status).toBe(200);
    expect(accept.body.accountId).toBe(accountId);
    expect(accept.body.role).toBe('employee');

    const afterRow = await pool.query('SELECT user_id, status FROM corporate_employees WHERE email = $1', [inviteEmail]);
    expect(afterRow.rows[0].user_id).toBe(newHire.userId);
    expect(afterRow.rows[0].status).toBe('active');

    const myAccounts = await request(app).get('/v1/corporate/my-accounts').set('Authorization', `Bearer ${newHire.accessToken}`);
    expect(myAccounts.body.some((a: { account_id: string }) => a.account_id === accountId)).toBe(true);
  });

  it('cannot accept an already-accepted invite a second time', async () => {
    const accountId = await createCorporateAccount({ name: `DoubleAccept Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );
    const inviteEmail = `dup-${Date.now()}@test.com`;
    await request(app)
      .post(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ email: inviteEmail, role: 'employee' });

    const firstAccepter = await loginAsNewUser(app);
    await request(app)
      .post('/v1/corporate/invites/accept')
      .set('Authorization', `Bearer ${firstAccepter.accessToken}`)
      .send({ email: inviteEmail });

    const secondAccepter = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/corporate/invites/accept')
      .set('Authorization', `Bearer ${secondAccepter.accessToken}`)
      .send({ email: inviteEmail });
    expect(res.status).toBe(400);
  });
});
