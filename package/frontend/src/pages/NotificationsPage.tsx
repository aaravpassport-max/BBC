import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import { getNotificationInbox, getErrorMessage, type InboxNotification } from '../api';
import { notificationBody } from '../lib/notificationCopy';

const CATEGORY_LABELS: Record<string, string> = {
  trip_updates: 'Trip update',
  promotions: 'Promotion',
  account_activity: 'Account',
  product_news: 'News',
  otp: 'OTP',
  sos: 'Safety',
};

const TRIP_TEMPLATES = new Set([
  'driver_assigned',
  'driver_on_the_way',
  'driver_arrived',
  'pickup_verified',
  'trip_completed',
  'trip_cancelled',
  'trip_cancelled_by_customer',
  'booking_confirmed',
  'new_trip_message',
]);

function notificationRoute(n: InboxNotification): string | null {
  if (n.category === 'sos') return '/safety';
  if (n.category === 'promotions' || n.template_id.includes('promo')) return '/home';
  if (n.template_id.includes('wallet') || n.template_id.includes('referral')) {
    return n.template_id.includes('referral') ? '/referral' : '/wallet';
  }
  if (n.template_id.includes('subscription')) return '/subscription';
  if (n.category === 'trip_updates' || TRIP_TEMPLATES.has(n.template_id)) return '/history';
  if (n.category === 'account_activity') return '/profile';
  return null;
}

export function NotificationsPage() {
  const navigate = useNavigate();
  const [items, setItems] = useState<InboxNotification[] | null>(null);
  const [readIds, setReadIds] = useState<Set<string>>(() => {
    try {
      const raw = localStorage.getItem('portmystuff_read_notifications');
      return new Set(raw ? (JSON.parse(raw) as string[]) : []);
    } catch {
      return new Set();
    }
  });
  const [error, setError] = useState('');

  useEffect(() => {
    getNotificationInbox()
      .then(setItems)
      .catch((err) => setError(getErrorMessage(err, 'Could not load notifications.')));
  }, []);

  function persistReadIds(ids: Set<string>) {
    localStorage.setItem('portmystuff_read_notifications', JSON.stringify([...ids]));
  }

  function markRead(id: string) {
    setReadIds((prev) => {
      const next = new Set(prev);
      next.add(id);
      persistReadIds(next);
      return next;
    });
  }

  function markAllRead() {
    if (!items) return;
    const next = new Set(readIds);
    items.forEach((n) => next.add(n.id));
    setReadIds(next);
    persistReadIds(next);
  }

  function openNotification(n: InboxNotification) {
    markRead(n.id);
    const route = notificationRoute(n);
    if (route) navigate(route);
  }

  const unreadCount = items?.filter((n) => !readIds.has(n.id)).length ?? 0;

  return (
    <Screen eyebrow="Inbox" title="Notifications" onBack={() => navigate('/profile')}>
      {items && items.length > 0 && unreadCount > 0 && (
        <Button variant="ghost" style={{ width: 'auto', padding: '8px 16px' }} onClick={markAllRead}>
          Mark all read ({unreadCount})
        </Button>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {items === null && !error && <SkeletonRowList count={4} />}

      {items && items.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No notifications yet.</p>
      )}

      {items && items.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {items.map((n) => {
            const unread = !readIds.has(n.id);
            return (
              <button
                key={n.id}
                type="button"
                onClick={() => openNotification(n)}
                style={{
                  border: `1px solid ${unread ? 'var(--accent)' : 'var(--border)'}`,
                  borderRadius: 12,
                  background: unread ? 'var(--accent-soft)' : 'var(--surface)',
                  padding: '14px 16px',
                  textAlign: 'left',
                  cursor: 'pointer',
                  color: 'inherit',
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                  <div style={{ fontSize: 14, fontWeight: unread ? 700 : 600 }}>
                    {CATEGORY_LABELS[n.category] || n.category}
                  </div>
                  {unread && <span style={{ fontSize: 10, color: 'var(--accent-strong)', fontWeight: 700 }}>NEW</span>}
                </div>
                <div style={{ fontSize: 13, marginTop: 6, lineHeight: 1.4 }}>
                  {notificationBody(n.template_id, n.category)}
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 6 }}>
                  {new Date(n.created_at).toLocaleString()} · {n.channel}
                </div>
              </button>
            );
          })}
        </div>
      )}

      <button
        type="button"
        onClick={() => navigate('/settings')}
        style={{ marginTop: 12, background: 'none', border: 'none', color: 'var(--accent)', fontWeight: 600, cursor: 'pointer' }}
      >
        Notification settings →
      </button>
    </Screen>
  );
}
