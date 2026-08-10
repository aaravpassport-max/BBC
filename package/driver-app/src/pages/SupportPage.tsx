import { useState, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import { listSupportTickets, getErrorMessage, type SupportTicketSummary } from '../api';

const STATUS_FILTERS = ['all', 'open', 'in_progress', 'resolved', 'closed'] as const;
type StatusFilter = (typeof STATUS_FILTERS)[number];

const PRIORITY_COLORS: Record<string, string> = {
  low: 'var(--text-muted)',
  normal: 'var(--accent-strong)',
  high: '#e67e22',
  urgent: 'var(--danger)',
};

export function SupportPage() {
  const navigate = useNavigate();
  const [tickets, setTickets] = useState<SupportTicketSummary[] | null>(null);
  const [error, setError] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');

  useEffect(() => {
    listSupportTickets()
      .then(setTickets)
      .catch((err) => setError(getErrorMessage(err, 'Could not load support tickets.')));
  }, []);

  const filtered = useMemo(() => {
    if (!tickets) return [];
    if (statusFilter === 'all') return tickets;
    return tickets.filter((t) => t.status === statusFilter);
  }, [tickets, statusFilter]);

  return (
    <Screen eyebrow="Support" title="Your tickets" onBack={() => navigate('/help')}>
      <Button onClick={() => navigate('/support/new')}>New ticket</Button>

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
        {STATUS_FILTERS.map((f) => (
          <button
            key={f}
            type="button"
            onClick={() => setStatusFilter(f)}
            style={{
              padding: '6px 12px',
              borderRadius: 20,
              border: `1px solid ${statusFilter === f ? 'var(--accent)' : 'var(--border)'}`,
              background: statusFilter === f ? 'var(--accent-soft)' : 'var(--surface)',
              fontSize: 12,
              fontWeight: 600,
              cursor: 'pointer',
              textTransform: 'capitalize',
            }}
          >
            {f === 'all' ? 'All' : f.replace(/_/g, ' ')}
          </button>
        ))}
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {tickets === null && !error && <SkeletonRowList count={2} />}

      {tickets && filtered.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          {tickets.length === 0 ? 'No support tickets yet.' : 'No tickets match this filter.'}
        </p>
      )}

      {filtered.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {filtered.map((t) => (
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
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                <div style={{ fontSize: 14, fontWeight: 600 }}>{t.category}</div>
                <span
                  style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    color: PRIORITY_COLORS[t.priority] || 'var(--text-muted)',
                  }}
                >
                  {t.priority}
                </span>
              </div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                {new Date(t.created_at).toLocaleString()} · {t.status.replace(/_/g, ' ')}
              </div>
            </button>
          ))}
        </div>
      )}
    </Screen>
  );
}
