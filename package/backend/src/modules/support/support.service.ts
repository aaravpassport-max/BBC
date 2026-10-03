import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

const REOPEN_WINDOW_DAYS = 7; // PRD 11A.1 config

const SLA_HOURS_BY_PRIORITY: Record<string, number> = {
  urgent: 1,
  high: 4,
  normal: 24,
  low: 48,
};

export async function createTicket(params: {
  userId: string;
  category: string;
  linkedBookingId?: string;
  description: string;
  idempotencyKey: string;
  priority?: string;
}): Promise<{ id: string; status: string }> {
  const { userId, category, linkedBookingId, description, idempotencyKey } = params;
  const priority = params.priority && SLA_HOURS_BY_PRIORITY[params.priority] ? params.priority : 'normal';

  return withTransaction(async (client) => {
    const existing = await client.query(
      `SELECT id, status FROM support_tickets WHERE user_id = $1 AND idempotency_key = $2`,
      [userId, idempotencyKey]
    );
    if (existing.rowCount && existing.rowCount > 0) {
      return { id: existing.rows[0].id, status: existing.rows[0].status };
    }

    if (linkedBookingId) {
      const booking = await client.query(`SELECT id FROM bookings WHERE id = $1 AND customer_id = $2`, [
        linkedBookingId,
        userId,
      ]);
      if (booking.rowCount === 0) {
        throw Errors.validation({ linked_booking_id: 'This booking does not belong to you.' });
      }
    }

    const slaHours = SLA_HOURS_BY_PRIORITY[priority];
    const result = await client.query(
      `INSERT INTO support_tickets (user_id, category, linked_booking_id, priority, sla_due_at, idempotency_key)
       VALUES ($1, $2, $3, $4, now() + interval '${slaHours} hours', $5)
       RETURNING id, status`,
      [userId, category, linkedBookingId || null, priority, idempotencyKey]
    );
    const ticketId = result.rows[0].id;

    await client.query(
      `INSERT INTO support_ticket_messages (ticket_id, sender_id, sender_role, body)
       VALUES ($1, $2, 'customer', $3)`,
      [ticketId, userId, description]
    );

    return result.rows[0];
  });
}

export async function getTicket(ticketId: string, userId: string, isAgent: boolean) {
  const ticket = await pool.query(
    isAgent ? `SELECT * FROM support_tickets WHERE id = $1` : `SELECT * FROM support_tickets WHERE id = $1 AND user_id = $2`,
    isAgent ? [ticketId] : [ticketId, userId]
  );
  if (ticket.rowCount === 0) {
    throw Errors.notFound('Ticket');
  }
  const messages = await pool.query(
    `SELECT id, sender_id, sender_role, body, created_at FROM support_ticket_messages WHERE ticket_id = $1 ORDER BY created_at`,
    [ticketId]
  );
  return { ...ticket.rows[0], messages: messages.rows };
}

export async function listMyTickets(userId: string) {
  const result = await pool.query(
    `SELECT id, category, status, priority, created_at, closed_at FROM support_tickets WHERE user_id = $1 ORDER BY created_at DESC`,
    [userId]
  );
  return result.rows;
}

/**
 * Adding a message to a CLOSED ticket auto-reopens it if within the reopen
 * window; past the window, a new ticket is created instead, linked back to
 * the original for context (PRD 11A.1 rule — never reopens indefinitely).
 */
export async function addMessage(params: {
  ticketId: string;
  senderId: string;
  senderRole: 'customer' | 'agent';
  body: string;
}): Promise<{ ticketId: string; reopened: boolean; newTicketCreated: boolean }> {
  const { ticketId, senderId, senderRole, body } = params;

  return withTransaction(async (client) => {
    const ticketResult = await client.query(`SELECT * FROM support_tickets WHERE id = $1 FOR UPDATE`, [ticketId]);
    if (ticketResult.rowCount === 0) {
      throw Errors.notFound('Ticket');
    }
    const ticket = ticketResult.rows[0];

    if (senderRole === 'customer' && ticket.user_id !== senderId) {
      throw Errors.forbidden('This is not your ticket.');
    }

    if (ticket.status !== 'closed') {
      await client.query(
        `INSERT INTO support_ticket_messages (ticket_id, sender_id, sender_role, body) VALUES ($1, $2, $3, $4)`,
        [ticketId, senderId, senderRole, body]
      );
      return { ticketId, reopened: false, newTicketCreated: false };
    }

    // Ticket is closed — only a customer message can trigger reopen/new-ticket
    // logic (an agent wouldn't be messaging a closed ticket in the normal flow).
    const daysSinceClosed = (Date.now() - new Date(ticket.closed_at).getTime()) / (1000 * 60 * 60 * 24);

    if (daysSinceClosed <= REOPEN_WINDOW_DAYS) {
      await client.query(
        `UPDATE support_tickets SET status = 'open', closed_at = NULL WHERE id = $1`,
        [ticketId]
      );
      await client.query(
        `INSERT INTO support_ticket_messages (ticket_id, sender_id, sender_role, body) VALUES ($1, $2, $3, $4)`,
        [ticketId, senderId, senderRole, body]
      );
      return { ticketId, reopened: true, newTicketCreated: false };
    }

    // Past the window — new linked ticket (PRD 11A.1), inherits category/priority.
    const slaHours = SLA_HOURS_BY_PRIORITY[ticket.priority] || SLA_HOURS_BY_PRIORITY.normal;
    const newTicket = await client.query(
      `INSERT INTO support_tickets (user_id, category, linked_booking_id, priority, sla_due_at, reopen_of_ticket_id)
       VALUES ($1, $2, $3, $4, now() + interval '${slaHours} hours', $5)
       RETURNING id`,
      [ticket.user_id, ticket.category, ticket.linked_booking_id, ticket.priority, ticketId]
    );
    await client.query(
      `INSERT INTO support_ticket_messages (ticket_id, sender_id, sender_role, body) VALUES ($1, $2, $3, $4)`,
      [newTicket.rows[0].id, senderId, senderRole, body]
    );
    return { ticketId: newTicket.rows[0].id, reopened: false, newTicketCreated: true };
  });
}

/**
 * Closing a ticket mandatorily requires a resolution category + note (PRD
 * 11A.1) — there is no code path that closes a ticket without both.
 */
export async function closeTicket(params: {
  ticketId: string;
  agentId: string;
  resolutionCategory: string;
  resolutionNote: string;
}): Promise<void> {
  const { ticketId, agentId, resolutionCategory, resolutionNote } = params;

  const result = await pool.query(
    `UPDATE support_tickets
     SET status = 'closed', closed_at = now(), assigned_agent_id = COALESCE(assigned_agent_id, $1),
         resolution_category = $2, resolution_note = $3
     WHERE id = $4 AND status != 'closed'
     RETURNING id`,
    [agentId, resolutionCategory, resolutionNote, ticketId]
  );
  if (result.rowCount === 0) {
    throw Errors.validation({ ticket: 'Ticket not found or already closed.' });
  }
}

export async function escalateTicket(ticketId: string, agentId: string): Promise<void> {
  const result = await pool.query(
    `UPDATE support_tickets SET status = 'escalated', assigned_agent_id = COALESCE(assigned_agent_id, $1)
     WHERE id = $2 AND status != 'closed' RETURNING id`,
    [agentId, ticketId]
  );
  if (result.rowCount === 0) {
    throw Errors.validation({ ticket: 'Ticket not found or already closed.' });
  }
}

export async function listQueue(filters: { status?: string; priority?: string }) {
  const conditions: string[] = [`status != 'closed'`];
  const args: unknown[] = [];
  if (filters.status) {
    args.push(filters.status);
    conditions.push(`status = $${args.length}`);
  }
  if (filters.priority) {
    args.push(filters.priority);
    conditions.push(`priority = $${args.length}`);
  }
  const result = await pool.query(
    `SELECT id, user_id, category, status, priority, sla_due_at, sla_breached, created_at
     FROM support_tickets WHERE ${conditions.join(' AND ')} ORDER BY sla_due_at ASC`,
    args
  );
  return result.rows;
}
