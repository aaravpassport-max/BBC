import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { SkeletonTableRows } from '../components/Skeleton';
import {
  getSupportQueue,
  getSupportTicket,
  replyToTicket,
  closeTicket,
  escalateTicket,
  ApiError, getErrorMessage,
  type SupportTicket,
  type SupportMessage,
} from '../api';

const PRIORITY_COLOR: Record<string, string> = {
  urgent: 'var(--danger)',
  high: 'var(--accent-strong)',
  normal: 'var(--text-muted)',
  low: 'var(--text-muted)',
};

export function SupportPage() {
  const [tickets, setTickets] = useState<SupportTicket[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<(SupportTicket & { messages: SupportMessage[] }) | null>(null);
  const [reply, setReply] = useState('');
  const [resolutionCategory, setResolutionCategory] = useState('resolved');
  const [resolutionNote, setResolutionNote] = useState('');
  const [busy, setBusy] = useState(false);

  const refreshQueue = useCallback(async () => {
    try {
      setTickets(await getSupportQueue({}));
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load the support queue.'));
    }
  }, []);

  useEffect(() => {
    void refreshQueue();
  }, [refreshQueue]);

  const openTicket = useCallback(async (id: string) => {
    setSelectedId(id);
    setError('');
    try {
      setDetail(await getSupportTicket(id));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load this ticket.'));
    }
  }, []);

  async function handleReply() {
    if (!selectedId || !reply.trim()) return;
    setBusy(true);
    setError('');
    try {
      await replyToTicket(selectedId, reply.trim());
      setReply('');
      setDetail(await getSupportTicket(selectedId));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not send this reply.'));
    } finally {
      setBusy(false);
    }
  }

  async function handleClose() {
    if (!selectedId) return;
    if (!resolutionNote.trim()) {
      setError('A resolution note is required to close a ticket.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await closeTicket(selectedId, resolutionCategory, resolutionNote.trim());
      setResolutionNote('');
      setSelectedId(null);
      setDetail(null);
      await refreshQueue();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not close this ticket.'));
    } finally {
      setBusy(false);
    }
  }

  async function handleEscalate() {
    if (!selectedId) return;
    setBusy(true);
    setError('');
    try {
      await escalateTicket(selectedId);
      await refreshQueue();
      setDetail(await getSupportTicket(selectedId));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not escalate this ticket.'));
    } finally {
      setBusy(false);
    }
  }

  if (forbidden) {
    return (
      <Layout title="Support">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout title="Support Queue">
      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', gap: 24 }}>
        <div>
          {tickets && tickets.length === 0 ? (
            <p style={{ color: 'var(--text-muted)' }}>Queue is empty.</p>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Priority</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>SLA due</th>
                </tr>
              </thead>
              <tbody>
                {tickets === null ? (
                  <SkeletonTableRows columns={4} rows={4} />
                ) : (
                  tickets.map((t) => (
                  <tr
                    key={t.id}
                    onClick={() => openTicket(t.id)}
                    style={{ cursor: 'pointer', background: selectedId === t.id ? 'var(--surface-raised)' : undefined }}
                  >
                    <td style={{ color: PRIORITY_COLOR[t.priority] || 'var(--text-muted)', fontWeight: 600, fontSize: 13 }}>
                      {t.priority.toUpperCase()}
                    </td>
                    <td>{t.category}</td>
                    <td style={{ fontSize: 13 }}>{t.status}</td>
                    <td style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>
                      {new Date(t.sla_due_at).toLocaleString()}
                    </td>
                  </tr>
                  ))
                )}
              </tbody>
            </table>
          )}
        </div>

        <div>
          {!detail ? (
            <p style={{ color: 'var(--text-muted)' }}>Select a ticket to view the thread.</p>
          ) : (
            <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 18 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 14 }}>
                <div style={{ fontFamily: 'var(--font-display)', fontWeight: 600 }}>
                  {detail.category} · {detail.status}
                </div>
                {detail.status !== 'closed' && (
                  <Button variant="ghost" style={{ width: 'auto', padding: '4px 12px', minHeight: 30, fontSize: 12 }} onClick={handleEscalate}>
                    Escalate
                  </Button>
                )}
              </div>

              <div style={{ display: 'flex', flexDirection: 'column', gap: 10, maxHeight: 320, overflowY: 'auto', marginBottom: 16 }}>
                {detail.messages.map((m) => (
                  <div
                    key={m.id}
                    style={{
                      alignSelf: m.sender_role === 'agent' ? 'flex-end' : 'flex-start',
                      background: m.sender_role === 'agent' ? 'var(--accent)' : 'var(--surface-raised)',
                      color: m.sender_role === 'agent' ? 'var(--accent-ink)' : 'var(--text)',
                      borderRadius: 10,
                      padding: '8px 12px',
                      maxWidth: '80%',
                      fontSize: 14,
                    }}
                  >
                    {m.body}
                  </div>
                ))}
              </div>

              {detail.status !== 'closed' && (
                <>
                  <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                    <div style={{ flex: 1 }}>
                      <Input placeholder="Reply to customer" value={reply} onChange={(e) => setReply(e.target.value)} />
                    </div>
                    <Button loading={busy} style={{ width: 'auto', padding: '0 16px' }} onClick={handleReply}>
                      Send
                    </Button>
                  </div>

                  <div style={{ borderTop: '1px dashed var(--border)', paddingTop: 14, display: 'flex', gap: 8, alignItems: 'center' }}>
                    <select
                      value={resolutionCategory}
                      onChange={(e) => setResolutionCategory(e.target.value)}
                      style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', padding: '8px 10px' }}
                    >
                      <option value="resolved">Resolved</option>
                      <option value="not_actionable">Not actionable</option>
                      <option value="duplicate">Duplicate</option>
                    </select>
                    <div style={{ flex: 1 }}>
                      <Input placeholder="Resolution note (required)" value={resolutionNote} onChange={(e) => setResolutionNote(e.target.value)} />
                    </div>
                    <Button variant="danger" loading={busy} style={{ width: 'auto', padding: '0 16px' }} onClick={handleClose}>
                      Close ticket
                    </Button>
                  </div>
                </>
              )}
            </div>
          )}
        </div>
      </div>
    </Layout>
  );
}
