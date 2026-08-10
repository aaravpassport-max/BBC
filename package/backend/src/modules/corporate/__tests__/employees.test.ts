import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { createCorporateAccount, addCorporateEmployee } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

describe('Corporate: employee invite/remove (PRD 14B.1)', () => {
  it('an account admin can invite an employee', async () => {
    const accountId = await createCorporateAccount({ name: `Invite Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );

    const res = await request(app)
      .post(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ email: 'new.employee@test.com', role: 'employee' });
    expect(res.status).toBe(201);

    const employees = await request(app)
      .get(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(employees.body.some((e: { email: string }) => e.email === 'new.employee@test.com')).toBe(true);
  });

  it('a non-admin employee cannot invite others', async () => {
    const accountId = await createCorporateAccount({ name: `NonAdmin Corp ${Date.now()}`, creditLimit: 500 });
    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });

    const res = await request(app)
      .post(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${employee.accessToken}`)
      .send({ email: 'someone@test.com' });
    expect(res.status).toBe(403);
  });

  it('rejects inviting a duplicate email on the same account', async () => {
    const accountId = await createCorporateAccount({ name: `Dup Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );

    await request(app)
      .post(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ email: 'dup@test.com' });
    const second = await request(app)
      .post(`/v1/corporate/${accountId}/employees`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ email: 'dup@test.com' });
    expect(second.status).toBe(400);
  });

  it('an admin can remove a regular employee', async () => {
    const accountId = await createCorporateAccount({ name: `Remove Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await loginAsNewUser(app);
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );
    const employee = await loginAsNewUser(app);
    await addCorporateEmployee({ accountId, userId: employee.userId, email: `${employee.phone}@test.com` });
    const employeeRow = await pool.query('SELECT id FROM corporate_employees WHERE user_id = $1', [employee.userId]);

    const res = await request(app)
      .delete(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);

    const row = await pool.query('SELECT status FROM corporate_employees WHERE id = $1', [employeeRow.rows[0].id]);
    expect(row.rows[0].status).toBe('removed');
  });

  it('cannot remove the LAST remaining account admin (guard-rail)', async () => {
    const accountId = await createCorporateAccount({ name: `LastAdmin Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await loginAsNewUser(app);
    const adminRow = await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active') RETURNING id`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );

    const res = await request(app)
      .delete(`/v1/corporate/${accountId}/employees/${adminRow.rows[0].id}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(400);
    expect(res.body.error.details.employee).toMatch(/last remaining account admin/);

    const row = await pool.query('SELECT status FROM corporate_employees WHERE id = $1', [adminRow.rows[0].id]);
    expect(row.rows[0].status).toBe('active'); // unchanged
  });

  it('CAN remove an admin when a second admin exists', async () => {
    const accountId = await createCorporateAccount({ name: `TwoAdmins Corp ${Date.now()}`, creditLimit: 500 });
    const admin1 = await loginAsNewUser(app);
    const admin2 = await loginAsNewUser(app);
    const admin1Row = await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active') RETURNING id`,
      [accountId, admin1.userId, `${admin1.phone}@test.com`]
    );
    await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active')`,
      [accountId, admin2.userId, `${admin2.phone}@test.com`]
    );

    const res = await request(app)
      .delete(`/v1/corporate/${accountId}/employees/${admin1Row.rows[0].id}`)
      .set('Authorization', `Bearer ${admin2.accessToken}`);
    expect(res.status).toBe(200);
  });

  it('removing an employee who owns a recurring booking transfers ownership to a remaining admin', async () => {
    const accountId = await createCorporateAccount({ name: `Recur Corp ${Date.now()}`, creditLimit: 500 });
    const admin = await loginAsNewUser(app);
    const adminRow = await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'account_admin', 'active') RETURNING id`,
      [accountId, admin.userId, `${admin.phone}@test.com`]
    );
    const employee = await loginAsNewUser(app);
    const employeeRow = await pool.query(
      `INSERT INTO corporate_employees (corporate_account_id, user_id, email, role, status) VALUES ($1, $2, $3, 'employee', 'active') RETURNING id`,
      [accountId, employee.userId, `${employee.phone}@test.com`]
    );
    const recurringRow = await pool.query(
      `INSERT INTO recurring_bookings (corporate_account_id, owner_employee_id, recurrence_pattern) VALUES ($1, $2, '{}') RETURNING id`,
      [accountId, employeeRow.rows[0].id]
    );

    await request(app)
      .delete(`/v1/corporate/${accountId}/employees/${employeeRow.rows[0].id}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);

    const recurring = await pool.query('SELECT owner_employee_id FROM recurring_bookings WHERE id = $1', [
      recurringRow.rows[0].id,
    ]);
    expect(recurring.rows[0].owner_employee_id).toBe(adminRow.rows[0].id);
  });
});
