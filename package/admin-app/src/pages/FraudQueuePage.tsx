import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { getFraudQueue, resolveFraudFlag, ApiError, getErrorMessage, type FraudFlag } from '../api';
import { SkeletonRowList } from '../components/Skeleton';

const SEVERITY_COLOR: Record<string, string> = {
  low: 'var(--text-muted)',
  medium: 'var(--accent-strong)',
  high: 'var(--danger)',
};

export function FraudQueuePage() {
  const [flags, setFlags] = useState<FraudFlag[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [actingOn, setActingOn] = useState<string | null>(null);
  const [note, setNote] = useState('');

  const refresh = useCallback(async () => {
    try {
      setFlags(await getFraudQueue());
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load the fraud queue.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleResolve(id: string, action: 'clear' | 'escalate' | 'hold' | 'suspend') {
    if (!note.trim()) {
      setError('A resolution note is required.');
      return;
    }
    setError('');
    try {
      await resolveFraudFlag(id, action, note.trim());
      setActingOn(null);
      setNote('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not resolve this flag.'));
    }
  }

  if (forbidden) {
    return (
      <Layout title="Fraud Queue">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout title="Fraud Queue">
      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {flags === null ? (
        <SkeletonRowList count={3} />
      ) : flags.length === 0 ? (
        <p style={{ color: 'var(--text-muted)' }}>Queue is empty. Nothing needs review.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {flags.map((f) => (
            <div key={f.id} style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 18 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 10 }}>
                <div>
                  <div style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 14 }}>
                    {f.subject_type} · {f.subject_id.slice(0, 8)}
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
                    {f.signal_types.join(', ')}
                  </div>
                </div>
                <span style={{ fontSize: 12, fontWeight: 700, color: SEVERITY_COLOR[f.severity] || 'var(--text-muted)' }}>
                  {f.severity.toUpperCase()}
                </span>
              </div>

              <pre
                style={{
                  fontFamily: 'var(--font-mono)',
                  fontSize: 12,
                  background: 'var(--bg)',
                  border: '1px solid var(--border)',
                  borderRadius: 8,
                  padding: 10,
                  overflowX: 'auto',
                  marginBottom: 12,
                }}
              >
                {JSON.stringify(f.evidence, null, 2)}
              </pre>

              {actingOn === f.id ? (
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <div style={{ flex: 1 }}>
                    <Input placeholder="Resolution note (required)" value={note} onChange={(e) => setNote(e.target.value)} />
                  </div>
                  <Button style={{ width: 'auto', padding: '0 14px' }} onClick={() => handleResolve(f.id, 'clear')}>
                    Clear
                  </Button>
                  <Button variant="ghost" style={{ width: 'auto', padding: '0 14px' }} onClick={() => handleResolve(f.id, 'escalate')}>
                    Escalate
                  </Button>
                  <Button variant="ghost" style={{ width: 'auto', padding: '0 14px' }} onClick={() => handleResolve(f.id, 'hold')}>
                    Hold
                  </Button>
                  <Button variant="danger" style={{ width: 'auto', padding: '0 14px' }} onClick={() => handleResolve(f.id, 'suspend')}>
                    Suspend
                  </Button>
                </div>
              ) : (
                <Button style={{ width: 'auto', padding: '4px 16px', minHeight: 32, fontSize: 13 }} onClick={() => setActingOn(f.id)}>
                  Review
                </Button>
              )}
            </div>
          ))}
        </div>
      )}
    </Layout>
  );
}
