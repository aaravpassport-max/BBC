import { useState, useEffect, useCallback, useRef } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { getSosQueue, acknowledgeSos, resolveSos, escalateSos, ApiError, getErrorMessage, type SosEvent } from '../api';
import { SkeletonRowList } from '../components/Skeleton';
import { useOpsRealtime } from '../hooks/useOpsRealtime';

const POLL_INTERVAL_MS = 4000;

export function SosQueuePage() {
  const [events, setEvents] = useState<SosEvent[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [actingOn, setActingOn] = useState<string | null>(null);
  const [outcomeTag, setOutcomeTag] = useState('resolved_safe');
  const [note, setNote] = useState('');
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const refresh = useCallback(async () => {
    try {
      setEvents(await getSosQueue());
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load the SOS queue.'));
    }
  }, []);

  useOpsRealtime((msg) => {
    if (msg.event === 'sos.triggered' || msg.event === 'sos.escalated' || msg.event === 'sos.location') {
      void refresh();
    }
  });

  useEffect(() => {
    void refresh();
    pollRef.current = setInterval(() => void refresh(), POLL_INTERVAL_MS);
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [refresh]);

  async function handleAcknowledge(id: string) {
    setError('');
    try {
      await acknowledgeSos(id);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not acknowledge this alert.'));
    }
  }

  async function handleEscalate(id: string) {
    setError('');
    try {
      await escalateSos(id);
      await refresh();
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) {
        setError("You don't have permission to escalate to the safety team lead.");
      } else {
        setError(getErrorMessage(err, 'Could not escalate this alert.'));
      }
    }
  }

  async function handleResolve(id: string) {
    if (!note.trim()) {
      setError('A resolution note is required to close an SOS alert.');
      return;
    }
    setError('');
    try {
      await resolveSos(id, outcomeTag, note.trim());
      setActingOn(null);
      setNote('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not resolve this alert.'));
    }
  }

  if (forbidden) {
    return (
      <Layout title="SOS Queue">
        <AccessDenied />
      </Layout>
    );
  }

  const activeCount = events?.filter((e) => e.status === 'triggered').length ?? 0;

  return (
    <Layout title="SOS Queue">
      {activeCount > 0 && (
        <div
          style={{
            border: '1px solid var(--danger)',
            background: 'rgba(255, 107, 107, 0.08)',
            borderRadius: 12,
            padding: '12px 16px',
            marginBottom: 20,
            display: 'flex',
            alignItems: 'center',
            gap: 10,
          }}
        >
          <span
            style={{
              width: 10,
              height: 10,
              borderRadius: '50%',
              background: 'var(--danger)',
            }}
          />
          <span style={{ fontWeight: 700, color: 'var(--danger)' }}>
            {activeCount} unacknowledged SOS alert{activeCount > 1 ? 's' : ''}
          </span>
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {events === null ? (
        <SkeletonRowList count={2} />
      ) : events.length === 0 ? (
        <p style={{ color: 'var(--text-muted)' }}>No active SOS alerts.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {events.map((e) => (
            <div
              key={e.id}
              style={{
                border: `1px solid ${e.status === 'triggered' ? 'var(--danger)' : 'var(--border)'}`,
                borderRadius: 12,
                background: 'var(--surface)',
                padding: 18,
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
                <div>
                  <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 15 }}>
                    Trip #{e.booking_id.slice(0, 8).toUpperCase()}
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
                    Triggered by {e.triggered_by_role} · {new Date(e.created_at).toLocaleTimeString()}
                  </div>
                  {e.escalated_at && (
                    <div style={{ fontSize: 12, color: 'var(--danger)', marginTop: 4, fontWeight: 600 }}>
                      {e.auto_escalated ? '⚠ Auto-escalated (no ack within threshold)' : '⚠ Escalated to safety team lead'}
                    </div>
                  )}
                </div>
                <span
                  style={{
                    fontSize: 12,
                    fontWeight: 700,
                    color: e.status === 'triggered' ? 'var(--danger)' : 'var(--accent-strong)',
                    textTransform: 'uppercase',
                  }}
                >
                  {e.status}
                </span>
              </div>

              {e.trigger_lat && e.trigger_lng && (
                <div style={{ fontSize: 12, fontFamily: 'var(--font-mono)', color: 'var(--text-muted)', marginBottom: 8 }}>
                  Trigger: {e.trigger_lat.toFixed(5)}, {e.trigger_lng.toFixed(5)}
                </div>
              )}
              {e.driver_lat != null && e.driver_lng != null && (
                <div style={{ fontSize: 12, fontFamily: 'var(--font-mono)', color: 'var(--danger)', marginBottom: 12, fontWeight: 600 }}>
                  Live driver: {e.driver_lat.toFixed(5)}, {e.driver_lng.toFixed(5)}
                  {e.driver_last_ping && (
                    <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>
                      {' '}
                      · ping {new Date(e.driver_last_ping).toLocaleTimeString()}
                    </span>
                  )}
                </div>
              )}

              <div style={{ display: 'flex', gap: 8, marginBottom: e.status === 'acknowledged' ? 10 : 0 }}>
                {e.status === 'triggered' && (
                  <Button variant="danger" style={{ width: 'auto', padding: '4px 16px', minHeight: 32, fontSize: 13 }} onClick={() => handleAcknowledge(e.id)}>
                    Acknowledge
                  </Button>
                )}
                {!e.escalated_at && (
                  <Button
                    variant="ghost"
                    style={{ width: 'auto', padding: '4px 16px', minHeight: 32, fontSize: 13 }}
                    onClick={() => handleEscalate(e.id)}
                  >
                    Escalate to Safety Team Lead
                  </Button>
                )}
              </div>

              {e.status === 'acknowledged' &&
                (actingOn === e.id ? (
                  <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                    <select
                      value={outcomeTag}
                      onChange={(ev) => setOutcomeTag(ev.target.value)}
                      style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', padding: '8px 10px' }}
                    >
                      <option value="resolved_safe">Resolved — safe</option>
                      <option value="false_alarm">False alarm</option>
                      <option value="escalated_to_authorities">Escalated to authorities</option>
                      <option value="other">Other</option>
                    </select>
                    <div style={{ flex: 1 }}>
                      <Input placeholder="Resolution note (required, min 20 characters)" value={note} onChange={(ev) => setNote(ev.target.value)} />
                    </div>
                    <Button style={{ width: 'auto', padding: '0 16px' }} onClick={() => handleResolve(e.id)}>
                      Resolve
                    </Button>
                  </div>
                ) : (
                  <Button style={{ width: 'auto', padding: '4px 16px', minHeight: 32, fontSize: 13 }} onClick={() => setActingOn(e.id)}>
                    Resolve
                  </Button>
                ))}
            </div>
          ))}
        </div>
      )}
    </Layout>
  );
}
