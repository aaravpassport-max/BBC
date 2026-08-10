import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { SkeletonRowList } from '../components/Skeleton';
import { getNotificationInbox, getErrorMessage, type InboxNotification } from '../api';

const CATEGORY_LABELS: Record<string, string> = {
  trip_updates: 'Trip update',
  promotions: 'Promotion',
  account_activity: 'Account',
  product_news: 'News',
  otp: 'OTP',
  sos: 'Safety',
};

export function NotificationsPage() {
  const navigate = useNavigate();
  const [items, setItems] = useState<InboxNotification[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    getNotificationInbox()
      .then(setItems)
      .catch((err) => setError(getErrorMessage(err, 'Could not load notifications.')));
  }, []);

  return (
    <Screen eyebrow="Inbox" title="Notifications" onBack={() => navigate('/profile')}>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {items === null && !error && <SkeletonRowList count={4} />}

      {items && items.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No notifications yet.</p>
      )}

      {items && items.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {items.map((n) => (
            <div
              key={n.id}
              style={{
                border: '1px solid var(--border)',
                borderRadius: 12,
                background: 'var(--surface)',
                padding: '14px 16px',
              }}
            >
              <div style={{ fontSize: 14, fontWeight: 600 }}>
                {CATEGORY_LABELS[n.category] || n.category}
              </div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                {new Date(n.created_at).toLocaleString()} · {n.channel}
              </div>
            </div>
          ))}
        </div>
      )}

      <button
        type="button"
        onClick={() => navigate('/settings')}
        style={{
          marginTop: 12,
          background: 'none',
          border: 'none',
          color: 'var(--accent)',
          fontWeight: 600,
          cursor: 'pointer',
        }}
      >
        Notification settings →
      </button>
    </Screen>
  );
}
