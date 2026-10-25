import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { AccessDenied } from '../components/AccessDenied';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import {
  listPayoutBatches,
  generatePayoutBatch,
  approvePayoutBatch,
  getLedgerIntegrity,
  type PayoutBatch,
} from '../api/finance';
import { ApiError, getErrorMessage } from '../api/client';

function money(n: string | number): string {
  return `₹${parseFloat(String(n)).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
}

export function SettlementPage() {
  const [batches, setBatches] = useState<PayoutBatch[] | null>(null);
  const [integrity, setIntegrity] = useState<number | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    try {
      const [list, check] = await Promise.all([listPayoutBatches(), getLedgerIntegrity()]);
      setBatches(list);
      setIntegrity(check.mismatches);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load settlement data.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleGenerate() {
    setBusy(true);
    setError('');
    try {
      const end = new Date();
      const start = new Date(end);
      start.setDate(start.getDate() - 7);
      await generatePayoutBatch(start.toISOString().slice(0, 10), end.toISOString().slice(0, 10));
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not generate payout batch.'));
    } finally {
      setBusy(false);
    }
  }

  if (forbidden) {
    return (
      <Layout title="Finance & Settlement">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout
      title="Finance & Settlement"
      actions={
        <Button onClick={() => void handleGenerate()} loading={busy}>
          Generate weekly batch
        </Button>
      }
    >
      {error && <p style={{ color: 'var(--danger)', fontSize: 14 }}>{error}</p>}

      {integrity !== null && (
        <div
          style={{
            padding: '12px 16px',
            borderRadius: 10,
            marginBottom: 20,
            background: integrity === 0 ? 'var(--success-soft, #e8f5e9)' : 'var(--danger-soft, #fdecea)',
            fontSize: 14,
          }}
        >
          Ledger integrity: {integrity === 0 ? '✓ All wallet caches match ledger sums' : `⚠ ${integrity} mismatch(es) — investigate immediately`}
        </div>
      )}

      {batches === null && !error && <SkeletonRowList count={3} />}

      {batches && batches.length === 0 && (
        <p style={{ color: 'var(--text-muted)' }}>No payout batches yet. Generate one to settle driver earnings.</p>
      )}

      {batches && batches.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {batches.map((batch) => (
            <div
              key={batch.id}
              style={{
                border: '1px solid var(--border)',
                borderRadius: 12,
                padding: '14px 16px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
              }}
            >
              <div>
                <div style={{ fontWeight: 600 }}>
                  {new Date(batch.period_start).toLocaleDateString()} – {new Date(batch.period_end).toLocaleDateString()}
                </div>
                <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>
                  {batch.status} · {money(batch.total_amount)}
                </div>
              </div>
              {batch.status === 'proposed' && (
                <Button
                  variant="ghost"
                  style={{ width: 'auto' }}
                  onClick={() => {
                    void approvePayoutBatch(batch.id)
                      .then((r) => {
                        if (r.failed > 0) {
                          setError(`${r.submitted} payouts submitted, ${r.failed} failed — check batch lines.`);
                        }
                        return refresh();
                      })
                      .catch((err) => setError(getErrorMessage(err, 'Could not approve batch.')));
                  }}
                >
                  Approve & submit payouts
                </Button>
              )}
              {batch.status === 'completed' && (
                <span style={{ fontSize: 12, color: 'var(--success)' }}>Paid out</span>
              )}
            </div>
          ))}
        </div>
      )}
    </Layout>
  );
}
