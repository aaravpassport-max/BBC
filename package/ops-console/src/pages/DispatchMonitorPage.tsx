import { useState } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { getDispatchLog, forceAssignDriver, ApiError, getErrorMessage, type DispatchLog } from '../api';

const OFFER_STATUS_COLOR: Record<string, string> = {
  offered: 'var(--accent-strong)',
  accepted: 'var(--success)',
  declined: 'var(--danger)',
  expired: 'var(--text-muted)',
  revoked: 'var(--text-muted)',
};

export function DispatchMonitorPage() {
  const [bookingId, setBookingId] = useState('');
  const [log, setLog] = useState<DispatchLog | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [forceDriverId, setForceDriverId] = useState('');
  const [assigning, setAssigning] = useState(false);

  async function handleSearch() {
    if (!bookingId.trim()) return;
    setError('');
    setLoading(true);
    try {
      setLog(await getDispatchLog(bookingId.trim()));
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else if (err instanceof ApiError && err.status === 404) setError('No booking found with that ID.');
      else setError(getErrorMessage(err, 'Could not load the dispatch log.'));
      setLog(null);
    } finally {
      setLoading(false);
    }
  }

  async function handleForceAssign() {
    if (!log || !forceDriverId.trim()) return;
    setError('');
    setAssigning(true);
    try {
      await forceAssignDriver(log.booking.id, forceDriverId.trim());
      setForceDriverId('');
      setLog(await getDispatchLog(log.booking.id));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not force-assign this driver.'));
    } finally {
      setAssigning(false);
    }
  }

  if (forbidden) {
    return (
      <Layout title="Dispatch Monitor">
        <AccessDenied />
      </Layout>
    );
  }

  const canForceAssign = log && (log.booking.status === 'searching' || log.booking.status === 'no_drivers_found');

  return (
    <Layout title="Dispatch Monitor">
      <div style={{ display: 'flex', gap: 8, marginBottom: 24, maxWidth: 500 }}>
        <Input
          placeholder="Booking ID"
          value={bookingId}
          onChange={(e) => setBookingId(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
        />
        <Button loading={loading} style={{ width: 'auto', padding: '0 18px' }} onClick={handleSearch}>
          Look up
        </Button>
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {log && (
        <>
          <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 18, marginBottom: 24 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
              <span style={{ fontFamily: 'var(--font-display)', fontWeight: 700 }}>
                Booking #{log.booking.id.slice(0, 8).toUpperCase()}
              </span>
              <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>{log.booking.status}</span>
            </div>
            {log.booking.driver_id && (
              <div style={{ fontSize: 12, fontFamily: 'var(--font-mono)', color: 'var(--text-muted)' }}>
                Driver: {log.booking.driver_id.slice(0, 8)}
              </div>
            )}
          </div>

          <h2 style={{ fontSize: 15, marginBottom: 12 }}>Dispatch timeline</h2>
          {log.offers.length === 0 ? (
            <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 24 }}>No dispatch offers yet.</p>
          ) : (
            <table style={{ marginBottom: 28 }}>
              <thead>
                <tr>
                  <th>Driver</th>
                  <th>Status</th>
                  <th>Offered</th>
                  <th>Responded</th>
                </tr>
              </thead>
              <tbody>
                {log.offers.map((o) => (
                  <tr key={o.id}>
                    <td style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>{o.driver_id.slice(0, 8)}</td>
                    <td style={{ color: OFFER_STATUS_COLOR[o.status] || 'var(--text-muted)', fontWeight: 600, fontSize: 13 }}>
                      {o.status}
                    </td>
                    <td style={{ fontSize: 12, color: 'var(--text-muted)' }}>{new Date(o.offered_at).toLocaleTimeString()}</td>
                    <td style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                      {o.responded_at ? new Date(o.responded_at).toLocaleTimeString() : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {canForceAssign && (
            <div style={{ border: '1px dashed var(--border)', borderRadius: 12, padding: 18 }}>
              <h2 style={{ fontSize: 14, marginBottom: 4 }}>Force-assign a driver</h2>
              <p style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 12 }}>
                Bypasses the normal scoring algorithm. Hard eligibility gates (KYC, training, suspension, document
                expiry) still apply and cannot be overridden.
              </p>
              <div style={{ display: 'flex', gap: 8 }}>
                <div style={{ flex: 1 }}>
                  <Input placeholder="Driver ID" value={forceDriverId} onChange={(e) => setForceDriverId(e.target.value)} />
                </div>
                <Button variant="danger" loading={assigning} style={{ width: 'auto', padding: '0 18px' }} onClick={handleForceAssign}>
                  Force-assign
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </Layout>
  );
}
