import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { createSupportTicket, listJobHistory, getErrorMessage, ApiError } from '../api';
import { SUPPORT_CATEGORIES } from '../constants/brand';
import { formatAddress } from '../lib/address';

const PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const;

export function NewSupportTicketPage() {
  const navigate = useNavigate();
  const [category, setCategory] = useState(SUPPORT_CATEGORIES[0]);
  const [priority, setPriority] = useState<(typeof PRIORITIES)[number]>('normal');
  const [description, setDescription] = useState('');
  const [linkedBookingId, setLinkedBookingId] = useState('');
  const [recentTrips, setRecentTrips] = useState<
    { id: string; created_at: string; pickup_address?: { formatted?: string; line1?: string }; first_drop_address?: { formatted?: string; line1?: string } }[]
  >([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    listJobHistory(1, 10)
      .then((res) => setRecentTrips(res.items))
      .catch(() => undefined);
  }, []);

  async function handleSubmit() {
    if (description.trim().length < 10) {
      setError('Please describe your issue in at least 10 characters.');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const ticket = await createSupportTicket({
        category,
        description: description.trim(),
        priority,
        linked_booking_id: linkedBookingId || undefined,
      });
      navigate(`/support/${ticket.id}`, { replace: true });
    } catch (err) {
      if (err instanceof ApiError) setError(err.message);
      else setError(getErrorMessage(err, 'Could not create ticket.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Support" title="Raise a ticket" onBack={() => navigate('/support')}>
      <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
        Category
        <select
          value={category}
          onChange={(e) => setCategory(e.target.value)}
          style={{
            border: '1px solid var(--border)',
            borderRadius: 10,
            padding: '10px 12px',
            fontSize: 14,
            background: 'var(--surface)',
          }}
        >
          {SUPPORT_CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
      </label>

      <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
        Priority
        <select
          value={priority}
          onChange={(e) => setPriority(e.target.value as (typeof PRIORITIES)[number])}
          style={{
            border: '1px solid var(--border)',
            borderRadius: 10,
            padding: '10px 12px',
            fontSize: 14,
            background: 'var(--surface)',
          }}
        >
          {PRIORITIES.map((p) => (
            <option key={p} value={p}>
              {p.charAt(0).toUpperCase() + p.slice(1)}
            </option>
          ))}
        </select>
      </label>

      {recentTrips.length > 0 && (
        <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
          Link to a recent trip (optional)
          <select
            value={linkedBookingId}
            onChange={(e) => setLinkedBookingId(e.target.value)}
            style={{
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: '10px 12px',
              fontSize: 14,
              background: 'var(--surface)',
            }}
          >
            <option value="">None</option>
            {recentTrips.map((t) => (
              <option key={t.id} value={t.id}>
                #{t.id.slice(0, 8).toUpperCase()} — {formatAddress(t.pickup_address, 'Pickup')} →{' '}
                {formatAddress(t.first_drop_address, 'Drop')} ({new Date(t.created_at).toLocaleDateString()})
              </option>
            ))}
          </select>
        </label>
      )}

      <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
        Describe your issue
        <textarea
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="Tell us what happened…"
          maxLength={2000}
          style={{
            minHeight: 120,
            border: '1px solid var(--border)',
            borderRadius: 10,
            padding: '10px 12px',
            fontSize: 14,
            resize: 'vertical',
          }}
        />
      </label>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      <Button onClick={() => void handleSubmit()} loading={loading}>
        Submit ticket
      </Button>
    </Screen>
  );
}
