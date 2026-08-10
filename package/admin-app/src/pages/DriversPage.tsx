import { useState, useEffect, useCallback, Fragment } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { api, suspendDriver, reinstateDriver, ApiError, getErrorMessage } from '../api';
import { SkeletonTableRows } from '../components/Skeleton';

interface DriverRow {
  id: string;
  phone: string;
  kyc_status: string;
  training_status: string;
  online_status: boolean;
  suspended_at: string | null;
  suspension_reason: string | null;
  rating_avg: string | null;
}

const REASON_CODES = ['FRAUD_SUSPECTED', 'DOCUMENT_EXPIRED', 'SAFETY_COMPLAINT', 'LOW_RATING', 'OTHER'];

export function DriversPage() {
  const [drivers, setDrivers] = useState<DriverRow[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [actingOn, setActingOn] = useState<string | null>(null);
  const [reasonCode, setReasonCode] = useState(REASON_CODES[0]);
  const [note, setNote] = useState('');

  const refresh = useCallback(async (searchTerm?: string) => {
    try {
      const result = await api.get<DriverRow[]>(`/admin/v1/drivers${searchTerm ? `?search=${searchTerm}` : ''}`);
      setDrivers(result);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load drivers.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleSuspend(driverId: string) {
    if (reasonCode === 'OTHER' && !note.trim()) {
      setError('Add a note when the reason is OTHER.');
      return;
    }
    setError('');
    try {
      await suspendDriver(driverId, reasonCode, note.trim() || undefined);
      setActingOn(null);
      setNote('');
      await refresh(search || undefined);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not suspend this driver.'));
    }
  }

  async function handleReinstate(driverId: string) {
    setError('');
    try {
      await reinstateDriver(driverId);
      await refresh(search || undefined);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not reinstate this driver.'));
    }
  }

  if (forbidden) {
    return (
      <Layout title="Drivers">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout title="Drivers">
      <div style={{ display: 'flex', gap: 10, marginBottom: 20, maxWidth: 320 }}>
        <Input
          placeholder="Search by phone"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && refresh(search || undefined)}
        />
        <Button style={{ width: 'auto', padding: '0 18px' }} onClick={() => refresh(search || undefined)}>
          Search
        </Button>
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {drivers && drivers.length === 0 ? (
        <p style={{ color: 'var(--text-muted)' }}>No drivers found.</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Phone</th>
              <th>KYC</th>
              <th>Rating</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {drivers === null ? (
              <SkeletonTableRows columns={5} rows={4} />
            ) : (
              drivers.map((d) => (
              <Fragment key={d.id}>
                <tr>
                  <td style={{ fontFamily: 'var(--font-mono)' }}>+91 {d.phone}</td>
                  <td>{d.kyc_status}</td>
                  <td style={{ fontFamily: 'var(--font-mono)' }}>{d.rating_avg ? parseFloat(d.rating_avg).toFixed(1) : '—'}</td>
                  <td>
                    {d.suspended_at ? (
                      <span style={{ color: 'var(--danger)', fontSize: 13, fontWeight: 600 }}>
                        Suspended ({d.suspension_reason})
                      </span>
                    ) : (
                      <span style={{ color: d.online_status ? 'var(--success)' : 'var(--text-muted)', fontSize: 13 }}>
                        {d.online_status ? 'Online' : 'Offline'}
                      </span>
                    )}
                  </td>
                  <td>
                    {d.suspended_at ? (
                      <Button
                        style={{ width: 'auto', padding: '4px 14px', minHeight: 32, fontSize: 13 }}
                        onClick={() => handleReinstate(d.id)}
                      >
                        Reinstate
                      </Button>
                    ) : (
                      <Button
                        variant="danger"
                        style={{ width: 'auto', padding: '4px 14px', minHeight: 32, fontSize: 13 }}
                        onClick={() => setActingOn(actingOn === d.id ? null : d.id)}
                      >
                        Suspend
                      </Button>
                    )}
                  </td>
                </tr>
                {actingOn === d.id && (
                  <tr>
                    <td colSpan={5} style={{ background: 'var(--surface)' }}>
                      <div style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '8px 0' }}>
                        <select
                          value={reasonCode}
                          onChange={(e) => setReasonCode(e.target.value)}
                          style={{
                            background: 'var(--bg)',
                            border: '1px solid var(--border)',
                            borderRadius: 8,
                            color: 'var(--text)',
                            padding: '8px 10px',
                          }}
                        >
                          {REASON_CODES.map((r) => (
                            <option key={r} value={r}>
                              {r}
                            </option>
                          ))}
                        </select>
                        <div style={{ flex: 1 }}>
                          <Input placeholder="Note (required for OTHER)" value={note} onChange={(e) => setNote(e.target.value)} />
                        </div>
                        <Button
                          variant="danger"
                          style={{ width: 'auto', padding: '0 16px' }}
                          onClick={() => handleSuspend(d.id)}
                        >
                          Confirm suspend
                        </Button>
                      </div>
                    </td>
                  </tr>
                )}
              </Fragment>
              ))
            )}
          </tbody>
        </table>
      )}
    </Layout>
  );
}
