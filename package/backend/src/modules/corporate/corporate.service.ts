import { PoolClient } from 'pg';
import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

/**
 * Atomically reserves a booking's quoted fare against a corporate account's
 * live credit limit (PRD 14A.1 — the hard requirement that two employees
 * booking simultaneously near the limit boundary must not both succeed if
 * only one fits). Uses SELECT ... FOR UPDATE on the account row, the same
 * pattern as coupon.service's row-lock race protection, and the DB CHECK
 * constraint on corporate_accounts (committed_spend + reserved_spend <=
 * credit_limit, migration 006) as the structural backstop.
 *
 * Must be called from within the SAME transaction as the booking insert it
 * reserves for (PRD 2.2.6/14A.1), so a booking failure releases the
 * reservation automatically via rollback.
 */
export async function reserveCorporateSpend(
  client: PoolClient,
  params: { corporateAccountId: string; bookingId: string; amount: number }
): Promise<void> {
  const { corporateAccountId, bookingId, amount } = params;

  const accountResult = await client.query(
    `SELECT credit_limit, committed_spend, reserved_spend, status
     FROM corporate_accounts WHERE id = $1 FOR UPDATE`,
    [corporateAccountId]
  );
  if (accountResult.rowCount === 0) {
    throw Errors.notFound('Corporate account');
  }
  const account = accountResult.rows[0];

  if (account.status !== 'active') {
    throw Errors.forbidden('This corporate account is currently suspended.');
  }

  const committed = parseFloat(account.committed_spend);
  const reserved = parseFloat(account.reserved_spend);
  const limit = parseFloat(account.credit_limit);

  if (committed + reserved + amount > limit) {
    throw Errors.creditLimitExceeded();
  }

  await client.query(`UPDATE corporate_accounts SET reserved_spend = reserved_spend + $1 WHERE id = $2`, [
    amount,
    corporateAccountId,
  ]);

  await client.query(
    `INSERT INTO corporate_reservations (corporate_account_id, booking_id, reserved_amount, status)
     VALUES ($1, $2, $3, 'reserved')`,
    [corporateAccountId, bookingId, amount]
  );
}

/**
 * Converts a reservation into a finalized charge at trip completion (PRD
 * 14A.1 step 5) — the final fare may differ slightly from the reserved quote
 * amount (waiting time, tolls realized during the actual trip), so this
 * adjusts committed_spend to the ACTUAL amount and releases any difference
 * back to available limit, rather than permanently locking up the original
 * estimate.
 */
export async function finalizeCorporateReservation(bookingId: string, finalAmount: number): Promise<void> {
  await withTransaction(async (client) => {
    const reservationResult = await client.query(
      `SELECT id, corporate_account_id, reserved_amount, status
       FROM corporate_reservations WHERE booking_id = $1 FOR UPDATE`,
      [bookingId]
    );
    if (reservationResult.rowCount === 0) {
      return; // not a corporate booking — nothing to finalize
    }
    const reservation = reservationResult.rows[0];
    if (reservation.status !== 'reserved') {
      return; // already finalized or released — idempotent no-op
    }

    await client.query(
      `UPDATE corporate_reservations SET status = 'finalized', final_amount = $1, resolved_at = now() WHERE id = $2`,
      [finalAmount, reservation.id]
    );

    // Move from reserved -> committed at the ACTUAL amount, releasing the
    // difference between what was reserved and what was actually charged
    // (PRD 14A.1: "releasing any difference between reserved and actual fare
    // back to available limit").
    await client.query(
      `UPDATE corporate_accounts
       SET reserved_spend = reserved_spend - $1, committed_spend = committed_spend + $2
       WHERE id = $3`,
      [reservation.reserved_amount, finalAmount, reservation.corporate_account_id]
    );
  });
}

/** Core release logic, usable either standalone (own transaction) or nested
 * inside a caller's existing transaction (see releaseReservationInTransaction
 * below) — never opens a second, independent transaction against a different
 * connection while a caller's transaction for the same booking is still open,
 * which would risk committing the release before/independently of whatever
 * the caller does next. */
async function releaseReservationCore(client: PoolClient, bookingId: string): Promise<void> {
  const reservationResult = await client.query(
    `SELECT id, corporate_account_id, reserved_amount, status
     FROM corporate_reservations WHERE booking_id = $1 FOR UPDATE`,
    [bookingId]
  );
  if (reservationResult.rowCount === 0 || reservationResult.rows[0].status !== 'reserved') {
    return;
  }
  const reservation = reservationResult.rows[0];

  await client.query(`UPDATE corporate_reservations SET status = 'released', resolved_at = now() WHERE id = $1`, [
    reservation.id,
  ]);
  await client.query(`UPDATE corporate_accounts SET reserved_spend = reserved_spend - $1 WHERE id = $2`, [
    reservation.reserved_amount,
    reservation.corporate_account_id,
  ]);
}

/** Standalone entry point — opens its own transaction. Use this when the
 * caller is NOT already inside a transaction for this booking. */
export async function releaseCorporateReservation(bookingId: string): Promise<void> {
  await withTransaction((client) => releaseReservationCore(client, bookingId));
}

/** Nested entry point — use this from within an existing transaction (e.g.
 * booking.service's cancelBooking) so the release commits atomically with
 * whatever else that transaction does, never on a separate connection. */
export async function releaseReservationInTransaction(client: PoolClient, bookingId: string): Promise<void> {
  await releaseReservationCore(client, bookingId);
}

async function requireActiveEmployee(accountId: string, userId: string): Promise<void> {
  const employee = await pool.query(
    `SELECT 1 FROM corporate_employees WHERE corporate_account_id = $1 AND user_id = $2 AND status = 'active'`,
    [accountId, userId]
  );
  if (employee.rowCount === 0) {
    throw Errors.forbidden('You are not a member of this corporate account.');
  }
}

/**
 * SECURITY FIX: this previously had no authorization check at all — any
 * authenticated user who knew or guessed a corporate account's UUID could
 * view its credit limit, committed spend, and reserved spend. Found while
 * building the self-service Corporate Portal, since the portal's own
 * "which account am I even looking at" flow made the missing check
 * obvious. Now requires active membership on that specific account.
 */
export async function getCorporateAccountSummary(accountId: string, requestingUserId: string) {
  await requireActiveEmployee(accountId, requestingUserId);

  const result = await pool.query(
    `SELECT id, name, credit_limit, committed_spend, reserved_spend,
            (credit_limit - committed_spend - reserved_spend) AS available_credit, status
     FROM corporate_accounts WHERE id = $1`,
    [accountId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Corporate account');
  }
  return result.rows[0];
}

/**
 * Lists every corporate account the given user actively belongs to (GAP
 * FOUND WHILE BUILDING THE PORTAL: every other endpoint required already
 * knowing the account ID — there was no way for a logged-in employee to
 * discover which account(s) they belong to in the first place).
 */
export async function getMyAccounts(userId: string) {
  const result = await pool.query(
    `SELECT ce.corporate_account_id AS account_id, ce.role, ca.name, ca.status
     FROM corporate_employees ce JOIN corporate_accounts ca ON ca.id = ce.corporate_account_id
     WHERE ce.user_id = $1 AND ce.status = 'active'
     ORDER BY ca.name`,
    [userId]
  );
  return result.rows;
}

/**
 * Accepts a pending invite (ANOTHER GAP FOUND WHILE BUILDING THE PORTAL:
 * inviteEmployee creates a row with user_id = NULL until accepted, but no
 * endpoint ever set it — an invited employee could never actually become
 * an active member). Matched by email, since this platform's auth is
 * phone-based and the invite itself was sent to an email address; the
 * accepting user confirms the email they were invited with rather than
 * this requiring a users.email column to already be populated.
 */
export async function acceptInvite(params: { userId: string; email: string }): Promise<{ accountId: string; role: string }> {
  const { userId, email } = params;

  const invite = await pool.query(
    `SELECT id, corporate_account_id, role FROM corporate_employees
     WHERE email = $1 AND status = 'invited' AND user_id IS NULL
     ORDER BY invited_at DESC LIMIT 1`,
    [email.toLowerCase()]
  );
  if (invite.rowCount === 0) {
    throw Errors.validation({ email: 'No pending invite found for this email address.' });
  }

  await pool.query(`UPDATE corporate_employees SET user_id = $1, status = 'active' WHERE id = $2`, [
    userId,
    invite.rows[0].id,
  ]);

  return { accountId: invite.rows[0].corporate_account_id, role: invite.rows[0].role };
}

/**
 * "Active bookings across the org" (PRD 14A.1 Company Dashboard). Joins to
 * the actual employee's phone (not stored redundantly on the booking row)
 * so an admin can see WHO on their team is on WHICH trip, not just an
 * anonymous booking ID.
 */
export async function getAccountBookings(accountId: string, requestingUserId: string) {
  await requireActiveEmployee(accountId, requestingUserId);

  const result = await pool.query(
    `SELECT b.id, b.status, b.fare_breakdown, b.created_at, u.phone AS employee_phone
     FROM bookings b JOIN users u ON u.id = b.customer_id
     WHERE b.corporate_account_id = $1
     ORDER BY b.created_at DESC
     LIMIT 50`,
    [accountId]
  );
  return result.rows;
}

// ---------- Employee Management (PRD 14B.1) ----------

async function requireAccountAdmin(client: PoolClient, accountId: string, userId: string): Promise<void> {
  const admin = await client.query(
    `SELECT 1 FROM corporate_employees WHERE corporate_account_id = $1 AND user_id = $2 AND role = 'account_admin' AND status = 'active'`,
    [accountId, userId]
  );
  if (admin.rowCount === 0) {
    throw Errors.forbidden('Only an account admin can perform this action.');
  }
}

/**
 * Enforces an employee's per-user monthly cap, if one is set (PRD 14B.1
 * table + acceptance criteria — this column existed since the very first
 * corporate migration but nothing anywhere ever checked it; it was pure
 * decoration). Must be called from within the SAME transaction as
 * reserveCorporateSpend, for the same reason that function documents: a
 * failure here rolls back the whole booking rather than leaving one
 * confirmed with no valid authorization.
 *
 * "This month" is the calendar month containing `now()` in the database's
 * timezone — bookings from prior months never count against the current
 * cap, matching the PRD's "per-user MONTHLY cap" naming.
 */
export async function checkPerUserMonthlyCap(
  client: PoolClient,
  params: { corporateAccountId: string; employeeUserId: string; additionalAmount: number }
): Promise<void> {
  const { corporateAccountId, employeeUserId, additionalAmount } = params;

  const employee = await client.query(
    `SELECT per_user_monthly_cap FROM corporate_employees
     WHERE corporate_account_id = $1 AND user_id = $2 AND status = 'active'`,
    [corporateAccountId, employeeUserId]
  );
  const cap = employee.rows[0]?.per_user_monthly_cap;
  if (cap === null || cap === undefined) return; // no cap set — unlimited within the account's own credit limit

  // Every booking this employee has made against THIS account so far this
  // calendar month counts toward the cap, EXCEPT ones that never actually
  // reserved spend (cancelled before a driver was found, or never found a
  // driver at all) — those never touched reserveCorporateSpend and have
  // nothing to count.
  const spendThisMonth = await client.query(
    `SELECT COALESCE(SUM((fare_breakdown->>'final_fare')::numeric), 0) AS total
     FROM bookings
     WHERE customer_id = $1 AND corporate_account_id = $2
       AND status NOT IN ('cancelled', 'no_drivers_found')
       AND created_at >= date_trunc('month', now())`,
    [employeeUserId, corporateAccountId]
  );
  const alreadySpent = parseFloat(spendThisMonth.rows[0].total);

  if (alreadySpent + additionalAmount > parseFloat(cap)) {
    throw Errors.validation({
      cap: `This booking would exceed your monthly booking cap of ${cap}. Spent so far this month: ${alreadySpent.toFixed(2)}.`,
    });
  }
}

/**
 * Updates an employee's per-user monthly cap after invite (PRD 14B.1: "Row
 * actions: edit cap" — there was no code path for this at all before).
 * PRD 14B.1 explicit acceptance criterion: a LOWERED cap only applies to
 * FUTURE bookings — it never retroactively invalidates spend already
 * reserved this period. This function only ever changes the stored cap
 * value; it never touches any existing booking or reservation, so that
 * guarantee holds structurally, not just by convention.
 */
export async function updateEmployeeCap(params: {
  accountId: string;
  requestedBy: string;
  employeeId: string;
  newCap: number | null;
}): Promise<void> {
  const { accountId, requestedBy, employeeId, newCap } = params;

  return withTransaction(async (client) => {
    await requireAccountAdmin(client, accountId, requestedBy);

    if (newCap !== null) {
      const account = await client.query(`SELECT credit_limit FROM corporate_accounts WHERE id = $1`, [accountId]);
      if (account.rowCount === 0) {
        throw Errors.notFound('Corporate account');
      }
      if (newCap > parseFloat(account.rows[0].credit_limit)) {
        throw Errors.validation({ cap: "Per-user cap cannot exceed the account's overall credit limit." });
      }
    }

    const result = await client.query(
      `UPDATE corporate_employees SET per_user_monthly_cap = $1
       WHERE id = $2 AND corporate_account_id = $3 AND status = 'active' RETURNING id`,
      [newCap, employeeId, accountId]
    );
    if (result.rowCount === 0) {
      throw Errors.notFound('Employee');
    }
  });
}

export async function inviteEmployee(params: {
  accountId: string;
  requestedBy: string;
  email: string;
  role: 'employee' | 'account_admin';
  perUserMonthlyCap?: number;
}): Promise<{ id: string }> {
  const { accountId, requestedBy, email, role, perUserMonthlyCap } = params;

  return withTransaction(async (client) => {
    await requireAccountAdmin(client, accountId, requestedBy);

    // PRD 14B.1: "Per-user cap cannot exceed the account credit limit" —
    // this validation already existed for updateEmployeeCap above; applying
    // it here too so the same rule can't be bypassed by setting an
    // over-limit cap at invite time instead of via a later edit.
    if (perUserMonthlyCap !== undefined) {
      const account = await client.query(`SELECT credit_limit FROM corporate_accounts WHERE id = $1`, [accountId]);
      if (account.rowCount === 0) {
        throw Errors.notFound('Corporate account');
      }
      if (perUserMonthlyCap > parseFloat(account.rows[0].credit_limit)) {
        throw Errors.validation({ per_user_monthly_cap: "Per-user cap cannot exceed the account's overall credit limit." });
      }
    }

    try {
      const result = await client.query(
        `INSERT INTO corporate_employees (corporate_account_id, email, role, per_user_monthly_cap, status)
         VALUES ($1, $2, $3, $4, 'invited') RETURNING id`,
        [accountId, email.toLowerCase(), role, perUserMonthlyCap || null]
      );
      return { id: result.rows[0].id };
    } catch (err) {
      const pgErr = err as { code?: string };
      if (pgErr.code === '23505') {
        throw Errors.validation({ email: 'This email is already part of your team.' });
      }
      throw err;
    }
  });
}

/**
 * Removes an employee (PRD 14B.1). Guard-rails, both enforced server-side:
 * (1) the last active account_admin cannot be removed — a zero-admin
 * account has no one left able to manage it; (2) any recurring booking the
 * removed employee owned auto-transfers to a remaining admin rather than
 * being silently orphaned.
 */
export async function removeEmployee(params: {
  accountId: string;
  requestedBy: string;
  employeeId: string;
}): Promise<void> {
  const { accountId, requestedBy, employeeId } = params;

  return withTransaction(async (client) => {
    await requireAccountAdmin(client, accountId, requestedBy);

    const target = await client.query(
      `SELECT id, role, user_id FROM corporate_employees WHERE id = $1 AND corporate_account_id = $2 AND status = 'active' FOR UPDATE`,
      [employeeId, accountId]
    );
    if (target.rowCount === 0) {
      throw Errors.notFound('Employee');
    }

    if (target.rows[0].role === 'account_admin') {
      const adminCount = await client.query(
        `SELECT count(*) FROM corporate_employees WHERE corporate_account_id = $1 AND role = 'account_admin' AND status = 'active'`,
        [accountId]
      );
      if (parseInt(adminCount.rows[0].count, 10) <= 1) {
        throw Errors.validation({
          employee: 'Cannot remove the last remaining account admin — promote another employee first.',
        });
      }
    }

    await client.query(`UPDATE corporate_employees SET status = 'removed', removed_at = now() WHERE id = $1`, [
      employeeId,
    ]);

    // Auto-transfer any recurring bookings this employee owned to a
    // remaining active admin (PRD 14A.1/14B.1 rule — never orphaned).
    if (target.rows[0].user_id) {
      const remainingAdmin = await client.query(
        `SELECT id FROM corporate_employees WHERE corporate_account_id = $1 AND role = 'account_admin' AND status = 'active' LIMIT 1`,
        [accountId]
      );
      if (remainingAdmin.rowCount && remainingAdmin.rowCount > 0) {
        await client.query(
          `UPDATE recurring_bookings SET owner_employee_id = $1 WHERE owner_employee_id = $2`,
          [remainingAdmin.rows[0].id, employeeId]
        );
      }
      // In-progress trips this employee's account was billing to are
      // entirely unaffected — removal only prevents FUTURE bookings (PRD
      // 14B.1 rule); nothing here touches the bookings table.
    }
  });
}

export async function listEmployees(accountId: string, requestingUserId: string) {
  await requireActiveEmployee(accountId, requestingUserId);

  const result = await pool.query(
    `SELECT id, email, role, status, per_user_monthly_cap, invited_at, removed_at
     FROM corporate_employees WHERE corporate_account_id = $1 ORDER BY invited_at`,
    [accountId]
  );
  return result.rows;
}

// ---------- Enterprise invoicing (P2 gap-analysis item) ----------

/**
 * Generates a real invoice for a billing period — admin-only, since this
 * is a financial document, not a report anyone can pull. Line items are
 * NOT stored on the invoice; they're computed here from `bookings`
 * directly for this exact period and re-derived identically every time
 * the invoice is viewed later (getInvoiceDetail below), so there's never
 * a second copy of fare data that could drift from the real booking
 * records. The unique constraint on (account, period_start, period_end)
 * means the same period can never be double-invoiced, even under a
 * network-retried request.
 */
export async function generateInvoice(params: {
  accountId: string;
  requestingUserId: string;
  periodStart: string;
  periodEnd: string;
}): Promise<{ id: string; invoiceNumber: string; totalAmount: number; bookingCount: number }> {
  const { accountId, requestingUserId, periodStart, periodEnd } = params;

  return withTransaction(async (client) => {
    await requireAccountAdmin(client, accountId, requestingUserId);

    if (new Date(periodStart) >= new Date(periodEnd)) {
      throw Errors.validation({ period: 'period_start must be before period_end.' });
    }

    const bookings = await client.query(
      `SELECT fare_breakdown FROM bookings
       WHERE corporate_account_id = $1 AND status = 'completed'
         AND created_at >= $2 AND created_at < $3`,
      [accountId, periodStart, periodEnd]
    );
    const totalAmount = bookings.rows.reduce(
      (sum, row) => sum + (row.fare_breakdown as { final_fare: number }).final_fare,
      0
    );

    // A real, sequential-looking invoice number — not the raw UUID, which
    // is correct for a database key but meaningless on an actual invoice
    // a company's finance team would file.
    const sequence = await client.query(
      `SELECT COUNT(*) AS n FROM corporate_invoices WHERE corporate_account_id = $1`,
      [accountId]
    );
    const invoiceNumber = `INV-${accountId.slice(0, 8).toUpperCase()}-${String(parseInt(sequence.rows[0].n, 10) + 1).padStart(4, '0')}`;

    const result = await client.query(
      `INSERT INTO corporate_invoices (corporate_account_id, invoice_number, period_start, period_end, total_amount, booking_count)
       VALUES ($1, $2, $3, $4, $5, $6)
       RETURNING id, invoice_number, total_amount, booking_count`,
      [accountId, invoiceNumber, periodStart, periodEnd, totalAmount, bookings.rowCount || 0]
    );
    return {
      id: result.rows[0].id,
      invoiceNumber: result.rows[0].invoice_number,
      totalAmount: parseFloat(result.rows[0].total_amount),
      bookingCount: result.rows[0].booking_count,
    };
  });
}

export async function listInvoices(accountId: string, requestingUserId: string) {
  await requireActiveEmployee(accountId, requestingUserId);
  const result = await pool.query(
    `SELECT id, invoice_number, period_start, period_end, total_amount, booking_count, status, generated_at
     FROM corporate_invoices WHERE corporate_account_id = $1 ORDER BY period_start DESC`,
    [accountId]
  );
  return result.rows;
}

/**
 * A single invoice's full detail, including real line items — re-derived
 * from `bookings` for this invoice's own stored period, the same query
 * generateInvoice itself used, so the line items shown always match what
 * was actually billed, not a snapshot that could go stale.
 */
export async function getInvoiceDetail(accountId: string, invoiceId: string, requestingUserId: string) {
  await requireActiveEmployee(accountId, requestingUserId);

  const invoice = await pool.query(
    `SELECT id, invoice_number, period_start, period_end, total_amount, booking_count, status, generated_at
     FROM corporate_invoices WHERE id = $1 AND corporate_account_id = $2`,
    [invoiceId, accountId]
  );
  if (invoice.rowCount === 0) {
    throw Errors.notFound('Invoice');
  }

  const lineItems = await pool.query(
    `SELECT b.id, b.fare_breakdown, b.created_at, u.phone AS employee_phone
     FROM bookings b JOIN users u ON u.id = b.customer_id
     WHERE b.corporate_account_id = $1 AND b.status = 'completed'
       AND b.created_at >= $2 AND b.created_at < $3
     ORDER BY b.created_at`,
    [accountId, invoice.rows[0].period_start, invoice.rows[0].period_end]
  );

  return { ...invoice.rows[0], lineItems: lineItems.rows };
}
