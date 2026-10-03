import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { getFleetDriverDetail, removeDriverFromFleet, getErrorMessage, type FleetDriverDetail } from '../api';

function money(n: number | string): string {
  return `₹${parseFloat(String(n)).toFixed(2)}`;
}

export function DriverDetailPage() {
  const { driverId } = useParams<{ driverId: string }>();
  const navigate = useNavigate();
  const [detail, setDetail] = useState<FleetDriverDetail | null>(null);
  const [error, setError] = useState('');
  const [removing, setRemoving] = useState(false);

  const refresh = useCallback(async () => {
    if (!driverId) return;
    try {
      setDetail(await getFleetDriverDetail(driverId));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load this driver.'));
    }
  }, [driverId]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleRemove() {
    if (!driverId) return;
    setRemoving(true);
    setError('');
    try {
      await removeDriverFromFleet(driverId);
      navigate('/home');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not remove this driver.'));
    } finally {
      setRemoving(false);
    }
  }

  return (
    <Screen eyebrow="Driver" title="Driver detail">
      <Button variant="ghost" style={{ width: 'auto', padding: '4px 0', marginBottom: 4 }} onClick={() => navigate(-1)}>
        ← Back
      </Button>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      {detail && (
        <>
          <div
            style={{
              border: '1px solid var(--border)',
              borderRadius: 16,
              background: 'var(--surface)',
              padding: 24,
              textAlign: 'center',
            }}
          >
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>Wallet balance</div>
            <div style={{ fontFamily: 'var(--font-mono)', fontSize: 32, fontWeight: 700, color: 'var(--accent-strong)' }}>
              {money(detail.balance)}
            </div>
          </div>

          <h2 style={{ fontSize: 15, marginTop: 6 }}>Recent transactions</h2>
          {detail.transactions.length === 0 ? (
            <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No transactions yet.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {detail.transactions.map((t, i) => (
                <div
                  key={i}
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    border: '1px solid var(--border)',
                    borderRadius: 10,
                    padding: '10px 14px',
                  }}
                >
                  <div>
                    <div style={{ fontSize: 14, textTransform: 'capitalize' }}>{t.reason.replace(/_/g, ' ')}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{new Date(t.created_at).toLocaleString()}</div>
                  </div>
                  <div
                    style={{
                      fontFamily: 'var(--font-mono)',
                      fontSize: 14,
                      fontWeight: 600,
                      color: t.entry_type === 'credit' ? 'var(--success)' : 'var(--danger)',
                    }}
                  >
                    {t.entry_type === 'credit' ? '+' : '−'}
                    {money(t.amount)}
                  </div>
                </div>
              ))}
            </div>
          )}

          <Button variant="danger" loading={removing} onClick={handleRemove} style={{ marginTop: 12 }}>
            Remove from fleet
          </Button>
        </>
      )}
    </Screen>
  );
}
