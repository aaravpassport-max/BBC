import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import { listSupportTickets, getErrorMessage, type SupportTicketSummary } from '../api';

const STATUS_FILTERS = [
  { id: 'all', label: 'All' },
  { id: 'open', label: 'Open' },
  { id: 'closed', label: 'Closed' },
] as const;

const OPEN_STATUSES = new Set(['open', 'pending', 'in_progress', 'reopened']);

const PRIORITY_COLORS: Record<string, { bg: string; color: string }> = {
  low: { bg: 'var(--bg)', color: 'var(--text-muted)' },
  normal: { bg: 'var(--accent-soft)', color: 'var(--accent-strong)' },
  high: { bg: '#fff7e6', color: '#d48806' },
  urgent: { bg: '#fff1f0', color: 'var(--danger)' },
};

export function SupportPage() {
  const navigate = useNavigate();
  const [tickets, setTickets] = useState<SupportTicketSummary[] | null>(null);
  const [error, setError] = useState('');
  const [statusFilter, setStatusFilter] = useState<(typeof STATUS_FILTERS)[number]['id']>('all');

  useEffect(() => {
    listSupportTickets()
      .then(setTickets)
      .catch((err) => setError(getErrorMessage(err, 'Could not load support tickets.')));
  }, []);

  const filtered =
    tickets?.filter((t) => {
      if (statusFilter === 'all') return true;
      if (statusFilter === 'open') return OPEN_STATUSES.has(t.status);
      return t.status === 'closed' || t.status === 'resolved';
    }) ?? null;

  return (
    <Screen eyebrow="Support" title="Your tickets" onBack={() => navigate('/help')}>
      <Button onClick={() => navigate('/support/new')}>New ticket</Button>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {STATUS_FILTERS.map((f) => (
          <button
            key={f.id}
            type="button"
            onClick={() => setStatusFilter(f.id)}
            style={{
              border: `1px solid ${statusFilter === f.id ? 'var(--accent)' : 'var(--border)'}`,
              background: statusFilter === f.id ? 'var(--accent-soft)' : 'var(--surface)',
              color: statusFilter === f.id ? 'var(--accent-strong)' : 'var(--text-muted)',
              borderRadius: 20,
              padding: '6px 14px',
              fontSize: 13,
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            {f.label}
          </button>
        ))}
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {filtered === null && !error && <SkeletonRowList count={2} />}

      {filtered && filtered.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No support tickets in this category.</p>
      )}

      {filtered && filtered.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {filtered.map((t) => {
            const priorityStyle = PRIORITY_COLORS[t.priority] ?? PRIORITY_COLORS.normal;
            return (
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
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                  <div style={{ fontSize: 14, fontWeight: 600 }}>{t.category}</div>
                  <span
                    style={{
                      fontSize: 10,
                      fontWeight: 700,
                      padding: '3px 8px',
                      borderRadius: 20,
                      background: priorityStyle.bg,
                      color: priorityStyle.color,
                      textTransform: 'uppercase',
                    }}
                  >
                    {t.priority}
                  </span>
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                  {new Date(t.created_at).toLocaleString()} · {t.status}
                </div>
              </button>
            );
          })}
        </div>
      )}
    </Screen>
  );
}
