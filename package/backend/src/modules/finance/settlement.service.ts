import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { submitDriverPayout } from './payout.provider';
import { debitDriverForPayout } from '../wallet/wallet.service';

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
    `SELECT pbl.id, pbl.driver_id, pbl.gross_earnings, pbl.net_payout, pbl.status,
            pbl.hold_reason, pbl.hold_note, pbl.failure_reason, pbl.provider_txn_ref,
            u.phone, u.name, dp.kyc_status
     FROM payout_batch_lines pbl
     JOIN users u ON u.id = pbl.driver_id
     LEFT JOIN driver_profiles dp ON dp.user_id = pbl.driver_id
     WHERE pbl.batch_id = $1
     ORDER BY pbl.status, u.phone`,
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
      const kyc = await client.query(`SELECT kyc_status FROM driver_profiles WHERE user_id = $1`, [d.driver_id]);
      const kycStatus = kyc.rows[0]?.kyc_status;
      const held = kycStatus !== 'approved';
      await client.query(
        `INSERT INTO payout_batch_lines (batch_id, driver_id, gross_earnings, deductions, net_payout, status, hold_reason, hold_note)
         VALUES ($1, $2, $3, 0, $3, $4, $5, $6)`,
        [
          batchId,
          d.driver_id,
          amount,
          held ? 'held' : 'eligible',
          held ? 'FRAUD_REVIEW' : null,
          held ? 'KYC not approved — auto-held for review' : null,
        ]
      );
      if (held) total -= amount;
    }

    await client.query(`UPDATE payout_batches SET total_amount = $1 WHERE id = $2`, [total, batchId]);

    return { batchId, driverCount: drivers.rowCount ?? 0, totalAmount: total };
  });
}

export async function holdPayoutLine(params: {
  batchId: string;
  lineId: string;
  reason: string;
  note?: string;
}): Promise<void> {
  const { batchId, lineId, reason, note } = params;
  const batch = await pool.query(`SELECT status FROM payout_batches WHERE id = $1`, [batchId]);
  if (batch.rowCount === 0) throw Errors.notFound('Payout batch');
  if (!['proposed', 'reviewing'].includes(batch.rows[0].status)) {
    throw Errors.validation({ batch: 'Cannot hold lines on a batch that is already submitting.' });
  }

  const line = await pool.query(
    `UPDATE payout_batch_lines SET status = 'held', hold_reason = $1, hold_note = $2
     WHERE id = $3 AND batch_id = $4 AND status IN ('eligible', 'held')
     RETURNING net_payout`,
    [reason, note ?? null, lineId, batchId]
  );
  if (line.rowCount === 0) throw Errors.notFound('Payout line');

  const eligibleTotal = await pool.query(
    `SELECT COALESCE(SUM(net_payout), 0) AS total FROM payout_batch_lines WHERE batch_id = $1 AND status = 'eligible'`,
    [batchId]
  );
  await pool.query(`UPDATE payout_batches SET total_amount = $1, status = 'reviewing' WHERE id = $2`, [
    eligibleTotal.rows[0].total,
    batchId,
  ]);
}

export async function releasePayoutLine(batchId: string, lineId: string): Promise<void> {
  const batch = await pool.query(`SELECT status FROM payout_batches WHERE id = $1`, [batchId]);
  if (batch.rowCount === 0) throw Errors.notFound('Payout batch');
  if (!['proposed', 'reviewing'].includes(batch.rows[0].status)) {
    throw Errors.validation({ batch: 'Cannot release lines on a batch that is already submitting.' });
  }

  const line = await pool.query(
    `UPDATE payout_batch_lines SET status = 'eligible', hold_reason = NULL, hold_note = NULL
     WHERE id = $1 AND batch_id = $2 AND status = 'held'
     RETURNING id`,
    [lineId, batchId]
  );
  if (line.rowCount === 0) throw Errors.notFound('Payout line');

  const eligibleTotal = await pool.query(
    `SELECT COALESCE(SUM(net_payout), 0) AS total FROM payout_batch_lines WHERE batch_id = $1 AND status = 'eligible'`,
    [batchId]
  );
  await pool.query(`UPDATE payout_batches SET total_amount = $1 WHERE id = $2`, [
    eligibleTotal.rows[0].total,
    batchId,
  ]);
}

export async function approvePayoutBatch(batchId: string, approverId: string): Promise<{ submitted: number; failed: number }> {
  const integrity = await runLedgerIntegrityCheck();
  if (integrity.mismatches > 0) {
    throw Errors.validation({
      ledger: `${integrity.mismatches} wallet cache mismatch(es) — resolve before approving payouts.`,
    });
  }

  const batch = await pool.query(`SELECT status, version FROM payout_batches WHERE id = $1`, [batchId]);
  if (batch.rowCount === 0) throw Errors.notFound('Payout batch');
  if (batch.rows[0].status !== 'proposed' && batch.rows[0].status !== 'reviewing') {
    throw Errors.validation({ batch: 'Only proposed or reviewing batches can be approved.' });
  }

  const held = await pool.query(
    `SELECT count(*) FROM payout_batch_lines WHERE batch_id = $1 AND status = 'held'`,
    [batchId]
  );
  if (parseInt(held.rows[0].count, 10) > 0) {
    throw Errors.validation({ batch: 'Release or exclude all held lines before approving.' });
  }

  await pool.query(
    `UPDATE payout_batches SET status = 'submitting', approved_by = $1, approved_at = now(), version = version + 1
     WHERE id = $2 AND version = $3`,
    [approverId, batchId, batch.rows[0].version]
  );

  const lines = await pool.query(
    `SELECT pbl.id, pbl.driver_id, pbl.net_payout, u.name, u.phone,
            kd.manual_entry AS bank_json
     FROM payout_batch_lines pbl
     JOIN users u ON u.id = pbl.driver_id
     LEFT JOIN LATERAL (
       SELECT manual_entry FROM kyc_documents
       WHERE subject_type = 'driver' AND subject_id = pbl.driver_id AND doc_type = 'bank_details' AND status = 'approved'
       ORDER BY version DESC LIMIT 1
     ) kd ON true
     WHERE pbl.batch_id = $1 AND pbl.status = 'eligible'`,
    [batchId]
  );

  let submitted = 0;
  let failed = 0;

  for (const line of lines.rows) {
    const bank = (line.bank_json || {}) as { account?: string; ifsc?: string; holder?: string };
    const accountNumber = bank.account || `sim${String(line.driver_id).slice(0, 8)}`;
    const ifsc = bank.ifsc || 'HDFC0000001';
    const name = bank.holder || line.name || `Driver ${line.phone}`;

    try {
      const payout = await submitDriverPayout({
        driverId: line.driver_id,
        amountRupees: parseFloat(line.net_payout),
        accountNumber,
        ifsc,
        name,
        reference: `batch_${batchId}_line_${line.id}`,
      });

      await withTransaction(async (client) => {
        await debitDriverForPayout(client, {
          driverId: line.driver_id,
          amount: parseFloat(line.net_payout),
        });
        await client.query(
          `UPDATE payout_batch_lines SET status = 'submitted', provider_txn_ref = $1 WHERE id = $2`,
          [payout.providerRef, line.id]
        );
      });
      submitted++;
    } catch (err) {
      failed++;
      const reason = err instanceof Error ? err.message : 'Payout submission failed';
      await pool.query(
        `UPDATE payout_batch_lines SET status = 'failed', failure_reason = $1, retry_count = retry_count + 1 WHERE id = $2`,
        [reason.slice(0, 500), line.id]
      );
    }
  }

  const finalStatus = failed === 0 ? 'completed' : failed === lines.rowCount ? 'partially_failed' : 'completed';
  await pool.query(`UPDATE payout_batches SET status = $1 WHERE id = $2`, [finalStatus, batchId]);

  return { submitted, failed };
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
