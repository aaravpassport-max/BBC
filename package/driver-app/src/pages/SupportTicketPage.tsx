import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getSupportTicket, addSupportMessage, getErrorMessage, type SupportTicketDetail } from '../api';

export function SupportTicketPage() {
  const { ticketId } = useParams<{ ticketId: string }>();
  const navigate = useNavigate();
  const [ticket, setTicket] = useState<SupportTicketDetail | null>(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);

  async function refresh() {
    if (!ticketId) return;
    try {
      const t = await getSupportTicket(ticketId);
      setTicket(t);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load ticket.'));
    }
  }

  useEffect(() => {
    void refresh();
  }, [ticketId]);

  async function handleSend() {
    if (!ticketId || !message.trim()) return;
    setSending(true);
    setError('');
    try {
      const result = await addSupportMessage(ticketId, message.trim());
      setMessage('');
      if (result.newTicketCreated) {
        navigate(`/support/${result.ticketId}`, { replace: true });
      } else {
        await refresh();
      }
    } catch (err) {
      setError(getErrorMessage(err, 'Could not send message.'));
    } finally {
      setSending(false);
    }
  }

  if (!ticket) {
    return (
      <Screen eyebrow="Support" title="Loading…" onBack={() => navigate('/support')}>
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
      </Screen>
    );
  }

  return (
    <Screen eyebrow="Support" title={ticket.category} onBack={() => navigate('/support')}>
      <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 12 }}>
        Status: {ticket.status} · {new Date(ticket.created_at).toLocaleString()}
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 16 }}>
        {ticket.messages.map((m) => (
          <div
            key={m.id}
            style={{
              alignSelf: m.sender_role === 'agent' ? 'flex-start' : 'flex-end',
              maxWidth: '85%',
              background: m.sender_role === 'agent' ? 'var(--surface)' : 'var(--accent-soft)',
              border: '1px solid var(--border)',
              borderRadius: 12,
              padding: '10px 12px',
            }}
          >
            <div style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 4 }}>
              {m.sender_role === 'agent' ? 'Support' : 'You'} · {new Date(m.created_at).toLocaleString()}
            </div>
            <div style={{ fontSize: 14 }}>{m.body}</div>
          </div>
        ))}
      </div>

      {ticket.status !== 'closed' && (
        <>
          <Input
            placeholder="Type a reply…"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
          />
          <Button onClick={() => void handleSend()} loading={sending} disabled={!message.trim()}>
            Send
          </Button>
        </>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
    </Screen>
  );
}
