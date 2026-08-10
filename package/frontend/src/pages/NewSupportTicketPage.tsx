import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { listBookings, createSupportTicket, getErrorMessage, ApiError, type Booking } from '../api';
import { SUPPORT_CATEGORIES } from '../constants/brand';

const PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const;

export function NewSupportTicketPage() {
  const navigate = useNavigate();
  const [category, setCategory] = useState(SUPPORT_CATEGORIES[0]);
  const [description, setDescription] = useState('');
  const [priority, setPriority] = useState<(typeof PRIORITIES)[number]>('normal');
  const [linkedBookingId, setLinkedBookingId] = useState('');
  const [recentTrips, setRecentTrips] = useState<Booking[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    listBookings({ page_size: 5 })
      .then((r) => setRecentTrips(r.items))
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
        <select value={category} onChange={(e) => setCategory(e.target.value)} style={{ border: '1px solid var(--border)', borderRadius: 10, padding: '10px 12px', fontSize: 14, background: 'var(--surface)' }}>
          {SUPPORT_CATEGORIES.map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </select>
      </label>

      <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
        Priority
        <select value={priority} onChange={(e) => setPriority(e.target.value as (typeof PRIORITIES)[number])} style={{ border: '1px solid var(--border)', borderRadius: 10, padding: '10px 12px', fontSize: 14, background: 'var(--surface)' }}>
          {PRIORITIES.map((p) => (
            <option key={p} value={p}>{p}</option>
          ))}
        </select>
      </label>

      {recentTrips.length > 0 && (
        <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
          Related trip (optional)
          <select value={linkedBookingId} onChange={(e) => setLinkedBookingId(e.target.value)} style={{ border: '1px solid var(--border)', borderRadius: 10, padding: '10px 12px', fontSize: 14, background: 'var(--surface)' }}>
            <option value="">None</option>
            {recentTrips.map((b) => (
              <option key={b.id} value={b.id}>
                #{b.id.slice(0, 8).toUpperCase()} · {b.status} · ₹{b.fare_breakdown.final_fare.toFixed(0)}
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
          style={{ minHeight: 120, border: '1px solid var(--border)', borderRadius: 10, padding: '10px 12px', fontSize: 14, resize: 'vertical' }}
        />
      </label>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      <Button onClick={() => void handleSubmit()} loading={loading}>Submit ticket</Button>
    </Screen>
  );
}
