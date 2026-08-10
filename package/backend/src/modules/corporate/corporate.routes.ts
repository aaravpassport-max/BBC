import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import {
  getCorporateAccountSummary,
  getMyAccounts,
  acceptInvite,
  getAccountBookings,
  inviteEmployee,
  removeEmployee,
  updateEmployeeCap,
  listEmployees,
  generateInvoice,
  listInvoices,
  getInvoiceDetail,
  markInvoicePaid,
  getSpendAnalytics,
  getSpendByEmployee,
  getSuggestedInvoicePeriod,
  getInvoiceAutomationStatus,
} from './corporate.service';

export const corporateRouter = Router();

// PRD 14B.1 — a logged-in user's own corporate memberships, so the portal
// has something to show before it knows any account ID. Registered BEFORE
// the /:accountId route below so Express doesn't greedily match the
// literal path segment "my-accounts" as an :accountId param.
corporateRouter.get(
  '/my-accounts',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accounts = await getMyAccounts(req.user!.userId);
    res.status(200).json(accounts);
  })
);

const acceptInviteSchema = z.object({ email: z.string().email() });

corporateRouter.post(
  '/invites/accept',
  requireAuth,
  validateBody(acceptInviteSchema),
  asyncHandler(async (req, res) => {
    const result = await acceptInvite({ userId: req.user!.userId, email: req.body.email });
    res.status(200).json(result);
  })
);

// PRD 14A.1 Company Dashboard — "available credit" is always computed live
// from committed-plus-reserved spend, never a cached/stale figure.
// Requires active membership on this specific account (see the security
// note in corporate.service.ts — this was previously ungated).
corporateRouter.get(
  '/:accountId',
  requireAuth,
  asyncHandler(async (req, res) => {
    const summary = await getCorporateAccountSummary(req.params.accountId as string, req.user!.userId);
    res.status(200).json(summary);
  })
);

// PRD 14A.1 Company Dashboard: "active bookings across the org".
corporateRouter.get(
  '/:accountId/bookings',
  requireAuth,
  asyncHandler(async (req, res) => {
    const bookings = await getAccountBookings(req.params.accountId as string, req.user!.userId);
    res.status(200).json(bookings);
  })
);

const inviteSchema = z.object({
  email: z.string().email(),
  role: z.enum(['employee', 'account_admin']).default('employee'),
  per_user_monthly_cap: z.number().positive().optional(),
});

// PRD 14B.1
corporateRouter.get(
  '/:accountId/employees',
  requireAuth,
  asyncHandler(async (req, res) => {
    const employees = await listEmployees(req.params.accountId as string, req.user!.userId);
    res.status(200).json(employees);
  })
);

corporateRouter.post(
  '/:accountId/employees',
  requireAuth,
  validateBody(inviteSchema),
  asyncHandler(async (req, res) => {
    const { email, role, per_user_monthly_cap } = req.body;
    const result = await inviteEmployee({
      accountId: req.params.accountId as string,
      requestedBy: req.user!.userId,
      email,
      role,
      perUserMonthlyCap: per_user_monthly_cap,
    });
    res.status(201).json(result);
  })
);

corporateRouter.delete(
  '/:accountId/employees/:employeeId',
  requireAuth,
  asyncHandler(async (req, res) => {
    await removeEmployee({
      accountId: req.params.accountId as string,
      requestedBy: req.user!.userId,
      employeeId: req.params.employeeId as string,
    });
    res.status(200).json({ removed: true });
  })
);

const updateCapSchema = z.object({ per_user_monthly_cap: z.number().positive().nullable() });

// PRD 14B.1: "Row actions: edit cap" — a lowered cap only applies to
// FUTURE bookings (see the guarantee documented in updateEmployeeCap).
corporateRouter.patch(
  '/:accountId/employees/:employeeId/cap',
  requireAuth,
  validateBody(updateCapSchema),
  asyncHandler(async (req, res) => {
    await updateEmployeeCap({
      accountId: req.params.accountId as string,
      requestedBy: req.user!.userId,
      employeeId: req.params.employeeId as string,
      newCap: req.body.per_user_monthly_cap,
    });
    res.status(200).json({ updated: true });
  })
);

// ---------- Enterprise invoicing (P2 gap-analysis item) ----------

const generateInvoiceSchema = z.object({
  period_start: z.string().datetime(),
  period_end: z.string().datetime(),
});

corporateRouter.post(
  '/:accountId/invoices/generate',
  requireAuth,
  validateBody(generateInvoiceSchema),
  asyncHandler(async (req, res) => {
    const invoice = await generateInvoice({
      accountId: req.params.accountId as string,
      requestingUserId: req.user!.userId,
      periodStart: req.body.period_start,
      periodEnd: req.body.period_end,
    });
    res.status(201).json(invoice);
  })
);

corporateRouter.get(
  '/:accountId/invoices',
  requireAuth,
  asyncHandler(async (req, res) => {
    const invoices = await listInvoices(req.params.accountId as string, req.user!.userId);
    res.status(200).json(invoices);
  })
);

corporateRouter.get(
  '/:accountId/invoices/suggested-period',
  requireAuth,
  asyncHandler(async (req, res) => {
    const suggested = await getSuggestedInvoicePeriod(req.params.accountId as string, req.user!.userId);
    res.status(200).json(suggested);
  })
);

corporateRouter.get(
  '/:accountId/invoices/automation-status',
  requireAuth,
  asyncHandler(async (req, res) => {
    const status = await getInvoiceAutomationStatus(req.params.accountId as string, req.user!.userId);
    res.status(200).json(status);
  })
);

corporateRouter.get(
  '/:accountId/invoices/:invoiceId',
  requireAuth,
  asyncHandler(async (req, res) => {
    const detail = await getInvoiceDetail(
      req.params.accountId as string,
      req.params.invoiceId as string,
      req.user!.userId
    );
    res.status(200).json(detail);
  })
);

corporateRouter.post(
  '/:accountId/invoices/:invoiceId/mark-paid',
  requireAuth,
  asyncHandler(async (req, res) => {
    await markInvoicePaid({
      accountId: req.params.accountId as string,
      invoiceId: req.params.invoiceId as string,
      requestingUserId: req.user!.userId,
    });
    res.status(200).json({ paid: true });
  })
);

corporateRouter.get(
  '/:accountId/spend-analytics',
  requireAuth,
  asyncHandler(async (req, res) => {
    const months = parseInt((req.query.months as string) || '6', 10);
    const analytics = await getSpendAnalytics(req.params.accountId as string, req.user!.userId, months);
    res.status(200).json(analytics);
  })
);

corporateRouter.get(
  '/:accountId/spend-by-employee',
  requireAuth,
  asyncHandler(async (req, res) => {
    const periodStart = typeof req.query.period_start === 'string' ? req.query.period_start : undefined;
    const periodEnd = typeof req.query.period_end === 'string' ? req.query.period_end : undefined;
    const analytics = await getSpendByEmployee(
      req.params.accountId as string,
      req.user!.userId,
      periodStart,
      periodEnd
    );
    res.status(200).json(analytics);
  })
);
