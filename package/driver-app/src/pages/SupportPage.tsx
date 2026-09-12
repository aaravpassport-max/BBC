import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import { listSupportTickets, getErrorMessage, type SupportTicketSummary } from '../api';

export function SupportPage() {
  const navigate = useNavigate();
  const [tickets, setTickets] = useState<SupportTicketSummary[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    listSupportTickets()
      .then(setTickets)
      .catch((err) => setError(getErrorMessage(err, 'Could not load support tickets.')));
  }, []);

  return (
    <Screen eyebrow="Support" title="Your tickets" onBack={() => navigate('/help')}>
      <Button onClick={() => navigate('/support/new')}>New ticket</Button>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {tickets === null && !error && <SkeletonRowList count={2} />}

      {tickets && tickets.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No support tickets yet.</p>
      )}

      {tickets && tickets.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {tickets.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => navigate(`/support/${t.id}`)}
              style={{
                textAlign: 'left',
                border: '1px solid var(--border)',
                borderRadius: 12,
                background: 'var(--surface)',
                padding: '14px 16px',
                cursor: 'pointer',
                color: 'var(--text)',
              }}
            >
              <div style={{ fontSize: 14, fontWeight: 600 }}>{t.category}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                {new Date(t.created_at).toLocaleString()} · {t.status}
              </div>
            </button>
          ))}
        </div>
      )}
    </Screen>
  );
}
