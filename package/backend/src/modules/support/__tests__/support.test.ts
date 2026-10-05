import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { getRoleIdByName } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function grantAgentPermission(userId: string) {
  const roleId = await getRoleIdByName('support_agent');
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    roleId,
  ]);
}

describe('Support: ticket creation and idempotency', () => {
  it('creates a ticket with the initial description as the first message', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'payment', description: 'My wallet top-up did not reflect in my balance.' });
    expect(res.status).toBe(201);

    const detail = await request(app)
      .get(`/v1/support/tickets/${res.body.id}`)
      .set('Authorization', `Bearer ${accessToken}`);
    expect(detail.body.messages.length).toBe(1);
    expect(detail.body.messages[0].sender_role).toBe('customer');
  });

  it('duplicate Idempotency-Key returns the same ticket, never creates two', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    const key = crypto.randomUUID();
    const first = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', key)
      .send({ category: 'trip_issue', description: 'The driver took a very long detour.' });
    const second = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', key)
      .send({ category: 'trip_issue', description: 'The driver took a very long detour.' });

    expect(second.body.id).toBe(first.body.id);
    const count = await pool.query('SELECT count(*) FROM support_tickets WHERE user_id = $1', [userId]);
    expect(parseInt(count.rows[0].count, 10)).toBe(1);
  });

  it('rejects linking a booking that does not belong to the requester', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({
        category: 'trip_issue',
        description: 'Something about a trip that is not mine.',
        linked_booking_id: '00000000-0000-0000-0000-000000000000',
      });
    expect(res.status).toBe(400);
  });

  it('a customer cannot view another customers ticket', async () => {
    const owner = await loginAsNewUser(app);
    const outsider = await loginAsNewUser(app);
    const ticket = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${owner.accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'account', description: 'Question about my account settings please.' });

    const res = await request(app)
      .get(`/v1/support/tickets/${ticket.body.id}`)
      .set('Authorization', `Bearer ${outsider.accessToken}`);
    expect(res.status).toBe(404);
  });
});

describe('Support: RBAC-gated agent actions (PRD Section 22, 11A.1)', () => {
  it('a plain customer cannot close a ticket', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const ticket = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'other', description: 'General question about the platform please.' });

    const res = await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/close`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ resolution_category: 'resolved', resolution_note: 'Fixed it myself.' });
    expect(res.status).toBe(403);
  });

  it('an agent can close a ticket only with BOTH resolution_category and resolution_note', async () => {
    const customer = await loginAsNewUser(app);
    const agent = await loginAsNewUser(app);
    await grantAgentPermission(agent.userId);

    const ticket = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'payment', description: 'Refund never arrived in my account please help.' });

    const missingNote = await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/close`)
      .set('Authorization', `Bearer ${agent.accessToken}`)
      .send({ resolution_category: 'resolved' });
    expect(missingNote.status).toBe(400);

    const close = await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/close`)
      .set('Authorization', `Bearer ${agent.accessToken}`)
      .send({ resolution_category: 'resolved', resolution_note: 'Refund reissued manually, confirmed with customer.' });
    expect(close.status).toBe(200);

    const row = await pool.query('SELECT status, resolution_category, resolution_note FROM support_tickets WHERE id = $1', [
      ticket.body.id,
    ]);
    expect(row.rows[0].status).toBe('closed');
    expect(row.rows[0].resolution_note).toMatch(/Refund reissued/);
  });

  it('an agent sees tickets they do not own; a customer only sees their own', async () => {
    const customer = await loginAsNewUser(app);
    const agent = await loginAsNewUser(app);
    await grantAgentPermission(agent.userId);

    const ticket = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'other', description: 'Testing agent visibility into this ticket.' });

    const asAgent = await request(app)
      .get(`/v1/support/tickets/${ticket.body.id}`)
      .set('Authorization', `Bearer ${agent.accessToken}`);
    expect(asAgent.status).toBe(200);
  });
});

describe('Support: reopen window logic (PRD 11A.1)', () => {
  it('messaging a recently-closed ticket reopens it', async () => {
    const customer = await loginAsNewUser(app);
    const agent = await loginAsNewUser(app);
    await grantAgentPermission(agent.userId);

    const ticket = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'other', description: 'A question I will reopen after closing shortly.' });
    await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/close`)
      .set('Authorization', `Bearer ${agent.accessToken}`)
      .send({ resolution_category: 'resolved', resolution_note: 'Answered.' });

    const reply = await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: 'Actually I still have a follow-up question.' });
    expect(reply.body.reopened).toBe(true);
    expect(reply.body.newTicketCreated).toBe(false);
    expect(reply.body.ticketId).toBe(ticket.body.id);

    const row = await pool.query('SELECT status FROM support_tickets WHERE id = $1', [ticket.body.id]);
    expect(row.rows[0].status).toBe('open');
  });

  it('messaging a ticket closed PAST the reopen window creates a new linked ticket instead', async () => {
    const customer = await loginAsNewUser(app);
    const agent = await loginAsNewUser(app);
    await grantAgentPermission(agent.userId);

    const ticket = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'other', description: 'A question I will close and simulate an old closure for.' });
    await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/close`)
      .set('Authorization', `Bearer ${agent.accessToken}`)
      .send({ resolution_category: 'resolved', resolution_note: 'Answered.' });

    // Simulate the closure having happened well outside the reopen window.
    await pool.query(`UPDATE support_tickets SET closed_at = now() - interval '30 days' WHERE id = $1`, [
      ticket.body.id,
    ]);

    const reply = await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/messages`)
      .set('Authorization', `Bearer ${customer.accessToken}`)
      .send({ body: 'Following up on this old ticket much later.' });
    expect(reply.body.reopened).toBe(false);
    expect(reply.body.newTicketCreated).toBe(true);
    expect(reply.body.ticketId).not.toBe(ticket.body.id);

    const newTicket = await pool.query('SELECT reopen_of_ticket_id, status FROM support_tickets WHERE id = $1', [
      reply.body.ticketId,
    ]);
    expect(newTicket.rows[0].reopen_of_ticket_id).toBe(ticket.body.id);
    expect(newTicket.rows[0].status).toBe('open');

    // The ORIGINAL ticket remains closed — this is a new ticket, not a reopen.
    const originalRow = await pool.query('SELECT status FROM support_tickets WHERE id = $1', [ticket.body.id]);
    expect(originalRow.rows[0].status).toBe('closed');
  });

  it('a message on an OPEN ticket just adds to the thread, no reopen/new-ticket logic triggered', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const ticket = await request(app)
      .post('/v1/support/tickets')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', crypto.randomUUID())
      .send({ category: 'other', description: 'An open ticket I will add a follow-up message to.' });

    const reply = await request(app)
      .post(`/v1/support/tickets/${ticket.body.id}/messages`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ body: 'Adding more detail to my open ticket.' });
    expect(reply.body.reopened).toBe(false);
    expect(reply.body.newTicketCreated).toBe(false);

    const detail = await request(app).get(`/v1/support/tickets/${ticket.body.id}`).set('Authorization', `Bearer ${accessToken}`);
    expect(detail.body.messages.length).toBe(2);
  });
});
