import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { listAdminPenalties, issuePenalty, resolvePenaltyDispute, ApiError, getErrorMessage } from '../api';
import { SkeletonTableRows } from '../components/Skeleton';

interface AdminPenalty {
  id: string;
  driver_id: string;
  amount: string;
  reason_code: string;
  status: string;
  dispute_note: string | null;
  created_at: string;
  phone: string;
}

const REASON_CODES = ['LATE_ARRIVAL', 'TRIP_CANCELLED_POST_ACCEPT', 'DOCUMENT_VIOLATION', 'OTHER'];

export function PenaltiesPage() {
  const [penalties, setPenalties] = useState<AdminPenalty[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [showIssue, setShowIssue] = useState(false);
  const [driverId, setDriverId] = useState('');
  const [amount, setAmount] = useState('50');
  const [reasonCode, setReasonCode] = useState(REASON_CODES[0]);
  const [note, setNote] = useState('');
  const [issuing, setIssuing] = useState(false);
  const [resolving, setResolving] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    try {
      setPenalties(await listAdminPenalties());
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load penalties.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleIssue() {
    if (!driverId.trim()) return;
    setIssuing(true);
    setError('');
    try {
      await issuePenalty(driverId.trim(), parseFloat(amount), reasonCode, note.trim() || undefined);
      setShowIssue(false);
      setDriverId('');
      setNote('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not issue penalty.'));
    } finally {
      setIssuing(false);
    }
  }

  async function handleResolve(penaltyId: string, resolution: 'upheld' | 'reversed') {
    setResolving(penaltyId);
    setError('');
    try {
      await resolvePenaltyDispute(penaltyId, resolution, `Admin ${resolution} via console.`);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not resolve dispute.'));
    } finally {
      setResolving(null);
    }
  }

  if (forbidden) {
    return (
      <Layout title="Penalties">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout
      title="Penalties"
      actions={
        <Button style={{ width: 'auto', padding: '0 18px' }} onClick={() => setShowIssue((v) => !v)}>
          {showIssue ? 'Cancel' : 'Issue penalty'}
        </Button>
      }
    >
      {showIssue && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 16, marginBottom: 20, maxWidth: 480 }}>
          <Input label="Driver ID (UUID)" value={driverId} onChange={(e) => setDriverId(e.target.value)} />
          <Input label="Amount (₹)" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} />
          <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13, marginBottom: 10 }}>
            Reason
            <select value={reasonCode} onChange={(e) => setReasonCode(e.target.value)} style={{ padding: '8px 10px', borderRadius: 8 }}>
              {REASON_CODES.map((c) => (
                <option key={c} value={c}>
                  {c.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
          </label>
          <Input label="Note (optional)" value={note} onChange={(e) => setNote(e.target.value)} />
          <Button loading={issuing} onClick={() => void handleIssue()}>
            Issue
          </Button>
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}
      {penalties === null && !error && <SkeletonTableRows columns={6} rows={4} />}
      {penalties && penalties.length === 0 && <p style={{ color: 'var(--text-muted)' }}>No active penalties.</p>}
      {penalties && penalties.length > 0 && (
        <table>
          <thead>
            <tr>
              <th>Driver</th>
              <th>Amount</th>
              <th>Reason</th>
              <th>Status</th>
              <th>Dispute</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {penalties.map((p) => (
              <tr key={p.id}>
                <td>
                  <div style={{ fontSize: 12 }}>{p.driver_id.slice(0, 8)}…</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>+91 {p.phone}</div>
                </td>
                <td>₹{parseFloat(p.amount).toFixed(0)}</td>
                <td>{p.reason_code.replace(/_/g, ' ')}</td>
                <td>{p.status}</td>
                <td style={{ fontSize: 12, maxWidth: 180 }}>{p.dispute_note || '—'}</td>
                <td>
                  {p.status === 'disputed' && (
                    <div style={{ display: 'flex', gap: 6 }}>
                      <Button
                        style={{ width: 'auto', padding: '4px 10px', minHeight: 28, fontSize: 12 }}
                        loading={resolving === p.id}
                        onClick={() => void handleResolve(p.id, 'upheld')}
                      >
                        Uphold
                      </Button>
                      <Button
                        variant="ghost"
                        style={{ width: 'auto', padding: '4px 10px', minHeight: 28, fontSize: 12 }}
                        loading={resolving === p.id}
                        onClick={() => void handleResolve(p.id, 'reversed')}
                      >
                        Reverse
                      </Button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Layout>
  );
}
