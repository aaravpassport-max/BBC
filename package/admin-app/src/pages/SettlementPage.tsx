import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { AccessDenied } from '../components/AccessDenied';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import {
  listPayoutBatches,
  getPayoutBatchDetail,
  generatePayoutBatch,
  approvePayoutBatch,
  holdPayoutLine,
  releasePayoutLine,
  getLedgerIntegrity,
  type PayoutBatch,
  type PayoutBatchDetail,
} from '../api/finance';
import { ApiError, getErrorMessage } from '../api/client';

function money(n: string | number): string {
  return `₹${parseFloat(String(n)).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
}

const HOLD_REASONS = ['DISPUTE_PENDING', 'FRAUD_REVIEW', 'BANK_DETAILS_INVALID', 'OTHER'] as const;

export function SettlementPage() {
  const [batches, setBatches] = useState<PayoutBatch[] | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<PayoutBatchDetail | null>(null);
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

  const loadDetail = useCallback(async (batchId: string) => {
    try {
      const d = await getPayoutBatchDetail(batchId);
      setDetail(d);
      setSelectedId(batchId);
      setError('');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load batch detail.'));
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
      const result = await generatePayoutBatch(start.toISOString().slice(0, 10), end.toISOString().slice(0, 10));
      await refresh();
      await loadDetail(result.batchId);
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

  const heldCount = detail?.lines.filter((l) => l.status === 'held').length ?? 0;
  const canApprove =
    detail && (detail.status === 'proposed' || detail.status === 'reviewing') && heldCount === 0 && integrity === 0;

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
          Ledger integrity:{' '}
          {integrity === 0 ? '✓ All wallet caches match ledger sums' : `⚠ ${integrity} mismatch(es) — approval blocked`}
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: selectedId ? '1fr 1.2fr' : '1fr', gap: 16 }}>
        <div>
          {batches === null && !error && <SkeletonRowList count={3} />}

          {batches && batches.length === 0 && (
            <p style={{ color: 'var(--text-muted)' }}>No payout batches yet. Generate one to settle driver earnings.</p>
          )}

          {batches && batches.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {batches.map((batch) => (
                <button
                  key={batch.id}
                  type="button"
                  onClick={() => void loadDetail(batch.id)}
                  style={{
                    border: `1px solid ${selectedId === batch.id ? 'var(--accent)' : 'var(--border)'}`,
                    borderRadius: 12,
                    padding: '14px 16px',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    background: selectedId === batch.id ? 'var(--accent-soft)' : 'var(--surface)',
                    cursor: 'pointer',
                    textAlign: 'left',
                    color: 'inherit',
                  }}
                >
                  <div>
                    <div style={{ fontWeight: 600 }}>
                      {new Date(batch.period_start).toLocaleDateString()} –{' '}
                      {new Date(batch.period_end).toLocaleDateString()}
                    </div>
                    <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>
                      {batch.status} · {money(batch.total_amount)}
                    </div>
                  </div>
                  {batch.status === 'completed' && (
                    <span style={{ fontSize: 12, color: 'var(--success)' }}>Paid out</span>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>

        {detail && (
          <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <div>
                <div style={{ fontWeight: 700 }}>Batch detail</div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                  {detail.lines.length} driver(s) · {heldCount} held
                </div>
              </div>
              {canApprove && (
                <Button
                  style={{ width: 'auto' }}
                  onClick={() => {
                    void approvePayoutBatch(detail.id)
                      .then((r) => {
                        if (r.failed > 0) {
                          setError(`${r.submitted} payouts submitted, ${r.failed} failed.`);
                        }
                        return Promise.all([refresh(), loadDetail(detail.id)]);
                      })
                      .catch((err) => setError(getErrorMessage(err, 'Could not approve batch.')));
                  }}
                >
                  Approve & submit
                </Button>
              )}
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: 480, overflowY: 'auto' }}>
              {detail.lines.map((line) => (
                <div
                  key={line.id}
                  style={{
                    border: '1px solid var(--border)',
                    borderRadius: 10,
                    padding: '10px 12px',
                    fontSize: 13,
                  }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <div>
                      <strong>{line.name || line.phone}</strong>
                      <div style={{ color: 'var(--text-muted)', fontSize: 12 }}>
                        {line.status}
                        {line.kyc_status && ` · KYC ${line.kyc_status}`}
                      </div>
                    </div>
                    <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 600 }}>{money(line.net_payout)}</div>
                  </div>
                  {line.hold_note && (
                    <div style={{ fontSize: 12, color: 'var(--warning, #b26a00)', marginTop: 4 }}>{line.hold_note}</div>
                  )}
                  {line.failure_reason && (
                    <div style={{ fontSize: 12, color: 'var(--danger)', marginTop: 4 }}>{line.failure_reason}</div>
                  )}
                  {(detail.status === 'proposed' || detail.status === 'reviewing') && (
                    <div style={{ marginTop: 8, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      {line.status === 'eligible' && (
                        <Button
                          variant="ghost"
                          style={{ width: 'auto', padding: '4px 10px', fontSize: 11 }}
                          onClick={() => {
                            const reason = HOLD_REASONS[0];
                            void holdPayoutLine(detail.id, line.id, reason)
                              .then(() => loadDetail(detail.id))
                              .catch((err) => setError(getErrorMessage(err, 'Could not hold line.')));
                          }}
                        >
                          Hold
                        </Button>
                      )}
                      {line.status === 'held' && (
                        <Button
                          variant="ghost"
                          style={{ width: 'auto', padding: '4px 10px', fontSize: 11 }}
                          onClick={() => {
                            void releasePayoutLine(detail.id, line.id)
                              .then(() => loadDetail(detail.id))
                              .catch((err) => setError(getErrorMessage(err, 'Could not release line.')));
                          }}
                        >
                          Release
                        </Button>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </Layout>
  );
}
