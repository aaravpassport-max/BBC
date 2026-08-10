import { pool, withTransaction } from '../../db/pool';

export async function listPayoutBatches() {
  const result = await pool.query(
    `SELECT id, period_start, period_end, status, total_amount, approved_at, created_at
     FROM payout_batches ORDER BY created_at DESC LIMIT 50`
  );
  return result.rows;
}

export async function getPayoutBatchDetail(batchId: string) {
  const batch = await pool.query(`SELECT * FROM payout_batches WHERE id = $1`, [batchId]);
  if (batch.rowCount === 0) return null;
  const lines = await pool.query(
    `SELECT pbl.id, pbl.driver_id, pbl.gross_earnings, pbl.net_payout, pbl.status, u.phone, dp.kyc_status
     FROM payout_batch_lines pbl
     JOIN users u ON u.id = pbl.driver_id
     LEFT JOIN driver_profiles dp ON dp.user_id = pbl.driver_id
     WHERE pbl.batch_id = $1`,
    [batchId]
  );
  return { ...batch.rows[0], lines: lines.rows };
}

/**
 * Generates a proposed payout batch for drivers with positive wallet balances.
 * PRD 12A.1 — weekly settlement cycle.
 */
export async function generatePayoutBatch(params: {
  periodStart: string;
  periodEnd: string;
}): Promise<{ batchId: string; driverCount: number; totalAmount: number }> {
  const { periodStart, periodEnd } = params;

  return withTransaction(async (client) => {
    const drivers = await client.query(
      `SELECT w.owner_id AS driver_id, w.real_balance_cache AS balance
       FROM wallets w
       WHERE w.owner_type = 'driver' AND w.real_balance_cache > 0`
    );

    if (drivers.rowCount === 0) {
      const empty = await client.query(
        `INSERT INTO payout_batches (period_start, period_end, status, total_amount)
         VALUES ($1::date, $2::date, 'proposed', 0) RETURNING id`,
        [periodStart, periodEnd]
      );
      return { batchId: empty.rows[0].id, driverCount: 0, totalAmount: 0 };
    }

    let total = 0;
    for (const d of drivers.rows) {
      total += parseFloat(d.balance);
    }

    const batch = await client.query(
      `INSERT INTO payout_batches (period_start, period_end, status, total_amount)
       VALUES ($1::date, $2::date, 'proposed', $3) RETURNING id`,
      [periodStart, periodEnd, total]
    );
    const batchId = batch.rows[0].id;

    for (const d of drivers.rows) {
      const amount = parseFloat(d.balance);
      await client.query(
        `INSERT INTO payout_batch_lines (batch_id, driver_id, gross_earnings, deductions, net_payout, status)
         VALUES ($1, $2, $3, 0, $3, 'eligible')`,
        [batchId, d.driver_id, amount]
      );
    }

    return { batchId, driverCount: drivers.rowCount ?? 0, totalAmount: total };
  });
}

export async function approvePayoutBatch(batchId: string, approverId: string): Promise<void> {
  await pool.query(
    `UPDATE payout_batches SET status = 'approved', approved_by = $1, approved_at = now()
     WHERE id = $2 AND status = 'proposed'`,
    [approverId, batchId]
  );
}

export async function runLedgerIntegrityCheck(): Promise<{ mismatches: number }> {
  const result = await pool.query(
    `SELECT w.id FROM wallets w
     WHERE w.real_balance_cache != COALESCE(
       (SELECT SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END)
        FROM wallet_transactions WHERE wallet_id = w.id AND balance_type = 'real'), 0
     )`
  );
  return { mismatches: result.rowCount ?? 0 };
}
