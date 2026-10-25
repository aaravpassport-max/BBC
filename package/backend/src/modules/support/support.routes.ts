import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import { pool } from '../../db/pool';
import {
  createTicket,
  getTicket,
  listMyTickets,
  addMessage,
  closeTicket,
  escalateTicket,
  listQueue,
} from './support.service';

const createSchema = z.object({
  category: z.string(),
  linked_booking_id: z.string().uuid().optional(),
  description: z.string().min(10).max(2000),
  priority: z.enum(['low', 'normal', 'high', 'urgent']).optional(),
});

const messageSchema = z.object({ body: z.string().min(1).max(2000) });

const closeSchema = z.object({
  resolution_category: z.string(),
  resolution_note: z.string().min(1),
});

async function isAgent(userId: string): Promise<boolean> {
  const perm = await pool.query(
    `SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id
     JOIN permissions p ON p.id = rp.permission_id
     WHERE ur.user_id = $1 AND p.resource = 'support' AND p.action = 'ticket_manage' LIMIT 1`,
    [userId]
  );
  return (perm.rowCount || 0) > 0;
}

export const supportRouter = Router();

// PRD 11B.1
supportRouter.post(
  '/tickets',
  requireAuth,
  validateBody(createSchema),
  asyncHandler(async (req, res) => {
    const idempotencyKeyHeader = req.headers['idempotency-key'];
    const idempotencyKey = Array.isArray(idempotencyKeyHeader) ? idempotencyKeyHeader[0] : idempotencyKeyHeader;
    if (!idempotencyKey) {
      throw Errors.validation({ 'Idempotency-Key': 'This header is required.' });
    }
    const { category, linked_booking_id, description, priority } = req.body;
    const result = await createTicket({
      userId: req.user!.userId,
      category,
      linkedBookingId: linked_booking_id,
      description,
      idempotencyKey,
      priority,
    });
    res.status(201).json(result);
  })
);

supportRouter.get(
  '/tickets',
  requireAuth,
  asyncHandler(async (req, res) => {
    const tickets = await listMyTickets(req.user!.userId);
    res.status(200).json(tickets);
  })
);

// PRD 11A.1 — agents (support.ticket_manage) see any ticket; customers only their own.
supportRouter.get(
  '/tickets/:id',
  requireAuth,
  asyncHandler(async (req, res) => {
    const agent = await isAgent(req.user!.userId);
    const ticket = await getTicket(req.params.id as string, req.user!.userId, agent);
    res.status(200).json(ticket);
  })
);

supportRouter.post(
  '/tickets/:id/messages',
  requireAuth,
  validateBody(messageSchema),
  asyncHandler(async (req, res) => {
    const agent = await isAgent(req.user!.userId);
    const result = await addMessage({
      ticketId: req.params.id as string,
      senderId: req.user!.userId,
      senderRole: agent ? 'agent' : 'customer',
      body: req.body.body,
    });
    res.status(201).json(result);
  })
);

// Agent-only actions (PRD Section 22 RBAC).
supportRouter.post(
  '/tickets/:id/close',
  requireAuth,
  requirePermission('support', 'ticket_manage'),
  validateBody(closeSchema),
  asyncHandler(async (req, res) => {
    const { resolution_category, resolution_note } = req.body;
    await closeTicket({
      ticketId: req.params.id as string,
      agentId: req.user!.userId,
      resolutionCategory: resolution_category,
      resolutionNote: resolution_note,
    });
    res.status(200).json({ closed: true });
  })
);

supportRouter.post(
  '/tickets/:id/escalate',
  requireAuth,
  requirePermission('support', 'ticket_manage'),
  asyncHandler(async (req, res) => {
    await escalateTicket(req.params.id as string, req.user!.userId);
    res.status(200).json({ escalated: true });
  })
);

supportRouter.get(
  '/queue',
  requireAuth,
  requirePermission('support', 'ticket_manage'),
  asyncHandler(async (req, res) => {
    const status = req.query.status as string | undefined;
    const priority = req.query.priority as string | undefined;
    const queue = await listQueue({ status, priority });
    res.status(200).json(queue);
  })
);
