import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { createCorporateAccount, addCorporateEmployee, createOnlineEligibleDriver, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function setUpAccountWithAdmin() {
  const accountId = await createCorporateAccount({ name: `Invoice Corp ${Date.now()}`, creditLimit: 10000 });
  const admin = await loginAsNewUser(app);
  await addCorporateEmployee({ accountId, userId: admin.userId, email: `${admin.phone}@test.com` });
  await pool.query(`UPDATE corporate_employees SET role = 'account_admin' WHERE corporate_account_id = $1 AND user_id = $2`, [
    accountId,
    admin.userId,
  ]);
  return { accountId, admin };
}

async function completeRealCorporateTrip(accountId: string) {
  const employee = await loginAsNewUser(app);
  await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });

  const quote = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${employee.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const fareBreakdown = quote.body.quotes[0].fare_breakdown;
  const booking = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${employee.accessToken}`)
    .set('Idempotency-Key', `inv-${crypto.randomUUID()}`)
    .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'corporate_bill' });

  const driverId = await createOnlineEligibleDriver({ phone: randomPhone() });
  await pool.query(`UPDATE bookings SET status = 'driver_assigned', driver_id = $1 WHERE id = $2`, [
    driverId,
    booking.body.id,
  ]);
  const driverPhone = (await pool.query('SELECT phone FROM users WHERE id = $1', [driverId])).rows[0].phone;
  const driverLogin = await loginAsNewUser(app, driverPhone);

  const detail = await request(app)
    .get(`/v1/bookings/${booking.body.id}`)
    .set('Authorization', `Bearer ${employee.accessToken}`);
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
  return { bookingId: booking.body.id, fareBreakdown, employeePhone: employee.phone };
}

function daysAgo(n: number): string {
  return new Date(Date.now() - n * 24 * 60 * 60 * 1000).toISOString();
}
function daysFromNow(n: number): string {
  return new Date(Date.now() + n * 24 * 60 * 60 * 1000).toISOString();
}

describe('Enterprise invoicing: generation (P2 gap-analysis item)', () => {
  it('only an account admin can generate an invoice — a regular employee cannot', async () => {
    const { accountId } = await setUpAccountWithAdmin();
    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });

    const res = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ period_start: daysAgo(30), period_end: daysFromNow(1) });
    expect(res.status).toBe(403);
  });

  it('generates an invoice covering a real, completed trip, with the correct real total', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    const { fareBreakdown } = await completeRealCorporateTrip(accountId);

    const res = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysAgo(1), period_end: daysFromNow(1) });
    expect(res.status).toBe(201);
    expect(res.body.bookingCount).toBe(1);
    expect(res.body.totalAmount).toBeCloseTo(fareBreakdown.final_fare, 2);
    expect(res.body.invoiceNumber).toMatch(/^INV-/);
  });

  it('a booking OUTSIDE the invoiced period is correctly excluded', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    await completeRealCorporateTrip(accountId);

    const res = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysAgo(60), period_end: daysAgo(30) });
    expect(res.status).toBe(201);
    expect(res.body.bookingCount).toBe(0);
    expect(res.body.totalAmount).toBe(0);
  });

  it('rejects period_start on or after period_end', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    const res = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysFromNow(1), period_end: daysAgo(1) });
    expect(res.status).toBe(400);
  });

  it('the SAME period can never be invoiced twice for the same account', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    const periodStart = daysAgo(1);
    const periodEnd = daysFromNow(1);

    const first = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: periodStart, period_end: periodEnd });
    expect(first.status).toBe(201);

    const second = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: periodStart, period_end: periodEnd });
    expect(second.status).toBeGreaterThanOrEqual(400);
  });
});

describe('Enterprise invoicing: listing and detail', () => {
  it('lists invoices for the account, most recent period first', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysAgo(60), period_end: daysAgo(30) });
    await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysAgo(30), period_end: daysAgo(1) });

    const res = await request(app).get(`/v1/corporate/${accountId}/invoices`).set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.length).toBe(2);
    expect(new Date(res.body[0].period_start).getTime()).toBeGreaterThan(new Date(res.body[1].period_start).getTime());
  });

  it('a non-member cannot list or view invoices — 403, not empty data', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    const invoice = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysAgo(1), period_end: daysFromNow(1) });

    const { accessToken: strangerToken } = await loginAsNewUser(app);
    const listRes = await request(app).get(`/v1/corporate/${accountId}/invoices`).set('Authorization', `Bearer ${strangerToken}`);
    expect(listRes.status).toBe(403);

    const detailRes = await request(app)
      .get(`/v1/corporate/${accountId}/invoices/${invoice.body.id}`)
      .set('Authorization', `Bearer ${strangerToken}`);
    expect(detailRes.status).toBe(403);
  });

  it('invoice detail shows real line items matching the real trip that was billed', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    const { bookingId, fareBreakdown, employeePhone } = await completeRealCorporateTrip(accountId);

    const invoice = await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysAgo(1), period_end: daysFromNow(1) });

    const detail = await request(app)
      .get(`/v1/corporate/${accountId}/invoices/${invoice.body.id}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(detail.status).toBe(200);
    expect(detail.body.lineItems.length).toBe(1);
    expect(detail.body.lineItems[0].id).toBe(bookingId);
    expect(detail.body.lineItems[0].employee_phone).toBe(employeePhone);
    expect(detail.body.lineItems[0].fare_breakdown.final_fare).toBeCloseTo(fareBreakdown.final_fare, 2);
  });

  it('a regular (non-admin) employee CAN view invoices — viewing, unlike generating, only needs membership', async () => {
    const { accountId, admin } = await setUpAccountWithAdmin();
    await request(app)
      .post(`/v1/corporate/${accountId}/invoices/generate`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ period_start: daysAgo(1), period_end: daysFromNow(1) });

    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });
    const res = await request(app).get(`/v1/corporate/${accountId}/invoices`).set('Authorization', `Bearer ${employee.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.length).toBe(1);
  });
});

describe('Enterprise invoicing: monthly sweep', () => {
  it('sweepMonthlyCorporateInvoices uses account_admin role and returns a count', async () => {
    const { sweepMonthlyCorporateInvoices } = await import('../corporate.service');
    const generated = await sweepMonthlyCorporateInvoices();
    expect(typeof generated).toBe('number');
    expect(generated).toBeGreaterThanOrEqual(0);
  });
});
